<?php
/*
 * The customer area (customer/*.php): who is logged in, the menu, what is waiting for the guest,
 * and the updates a guest sees about their own bookings and payments.
 *
 *     session_start();
 *     require_once __DIR__ . "/../config/database.php";
 *     require_once __DIR__ . "/../includes/customer-shell.php";   // loads this file
 *     $me = customer_boot($pdo);                                  // login check
 *
 * The small helpers (h, peso, status_pill, admin_stay, csrf_field, the one-time messages of
 * admin_flash) are the admin panel's: includes/admin.php holds them and is loaded here too.
 */

require_once __DIR__ . "/admin.php";
require_once __DIR__ . "/paymongo.php";

// Updates a guest is told about (the bell). A cancellation only when someone else did it.
const CUSTOMER_UPDATE_TYPES = [
    "reservation.confirmed", "reservation.declined", "reservation.completed", "reservation.expired",
    "payment.verified", "payment.rejected", "payment.online_paid", "payment.online_cancelled",
];


// ======================================================
// SCHEMA AND LOGIN
// ======================================================

function ensure_customer_schema(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    ensure_auth_schema($pdo);
    ensure_activity_schema($pdo);
    ensure_chat_schema($pdo);

    // when the guest last looked at their updates (UTC); newer updates are "new"
    $has = $pdo->query("SHOW COLUMNS FROM users LIKE 'notifications_seen_at'")->fetch();

    if (!$has) {
        $pdo->exec("ALTER TABLE users ADD COLUMN notifications_seen_at DATETIME NULL");
    }
}

// The page the guest was on, to come back to it after logging in ("customer/profile.php").
function customer_here(): string
{
    $page = basename((string) ($_SERVER["SCRIPT_NAME"] ?? "dashboard.php"));

    return "customer/" . (preg_match('/^[a-z_]+\.php$/', $page) ? $page : "dashboard.php");
}

// The guest's row, or null when nobody (or no active customer) is logged in.
function customer_load(PDO $pdo): ?array
{
    if (!isset($_SESSION["user_id"]) || ($_SESSION["role"] ?? "") !== "customer") {
        return null;
    }

    admin_philippine_time($pdo);
    ensure_customer_schema($pdo);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([(int) $_SESSION["user_id"]]);
    $user = $stmt->fetch();

    if (!$user || $user["role"] !== "customer" || ($user["status"] ?? "active") !== "active") {
        return null;
    }

    // a new account starts with nothing unread: only updates from now on count as new
    if ($user["notifications_seen_at"] === null) {
        $user["notifications_seen_at"] = utc_now();
        $pdo->prepare("UPDATE users SET notifications_seen_at = ? WHERE id = ?")
            ->execute([$user["notifications_seen_at"], $user["id"]]);
    }

    $hasPassword = $user["password"] ?? "";
    unset($user["password"]);
    $user["has_password"] = $hasPassword !== "";

    $GLOBALS["arvesCustomer"] = ["pdo" => $pdo, "user" => $user];

    return $user;
}

/*
 * Call at the top of every customer page, after session_start() and config/database.php.
 * Visitors who are not a logged-in, active customer go to the login page (and come back here).
 * Pages that show payments first call paymongo_settle_pending($pdo, $me["id"]): online payments
 * finished (or given up) on PayMongo since the guest last came by are settled then.
 */
function customer_boot(PDO $pdo): array
{
    $user = customer_load($pdo);

    if ($user === null) {
        if (isset($_SESSION["user_id"]) && ($_SESSION["role"] ?? "") === "customer") {
            // turned off or deleted while logged in: the session ends here
            $_SESSION = [];
            session_destroy();
        }

        header("Location: ../login.php?redirect=" . urlencode(customer_here()));
        exit;
    }

    return $user;
}

// For customer pages that answer with JSON: replies 401 instead of going to the login page.
function customer_boot_json(PDO $pdo): array
{
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store");

    $user = customer_load($pdo);

    if ($user === null) {
        http_response_code(401);
        echo json_encode(["ok" => false, "error" => "Please log in again."]);
        exit;
    }

    return $user;
}

function customer_current(): array
{
    return $GLOBALS["arvesCustomer"]["user"] ?? [];
}

function customer_first_name(array $user): string
{
    return explode(" ", trim((string) ($user["full_name"] ?? "")))[0] ?: "there";
}


// ======================================================
// WHAT IS WAITING FOR THE GUEST
// ======================================================

/*
 * The latest payment of each of the guest's reservations, in one query:
 *     SELECT r.*, lp.status AS payment_status ... FROM reservations r {CUSTOMER_LATEST_PAYMENT}
 */
const CUSTOMER_LATEST_PAYMENT = "
    LEFT JOIN payments lp ON lp.id = (
        SELECT p.id FROM payments p
        WHERE p.reservation_id = r.id AND p.user_id = r.user_id
        ORDER BY p.id DESC
        LIMIT 1
    )";

// A reservation the guest can still pay for: open, and nothing paid or waiting to be checked.
function customer_can_pay(array $reservation): bool
{
    return !in_array($reservation["status"], ["cancelled", "declined", "completed", "expired"], true)
        && in_array($reservation["payment_status"] ?? null, [null, "rejected", "cancelled"], true);
}

// A reservation the guest can still cancel: not confirmed yet, and no payment accepted.
function customer_can_cancel(array $reservation): bool
{
    return $reservation["status"] === "pending" && ($reservation["payment_status"] ?? null) !== "verified";
}

function customer_counts(PDO $pdo, int $userId): array
{
    static $cache = [];

    if (isset($cache[$userId])) {
        return $cache[$userId];
    }

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(r.status IN ('pending', 'confirmed') AND r.check_out > CURDATE()), 0) AS upcoming,
            COALESCE(SUM(r.status = 'pending' AND (lp.status IS NULL OR lp.status IN ('rejected', 'cancelled'))), 0) AS to_pay,
            COALESCE(SUM(CASE WHEN r.status = 'pending' AND (lp.status IS NULL OR lp.status IN ('rejected', 'cancelled'))
                THEN r.total_amount ELSE 0 END), 0) AS to_pay_amount,
            COALESCE(SUM(r.status = 'pending' AND lp.status = 'pending'), 0) AS checking,
            COUNT(r.id) AS reservations
        FROM reservations r
        " . CUSTOMER_LATEST_PAYMENT . "
        WHERE r.user_id = ?
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch() ?: [];

    return $cache[$userId] = [
        "upcoming" => (int) ($row["upcoming"] ?? 0),
        "to_pay" => (int) ($row["to_pay"] ?? 0),
        "to_pay_amount" => (float) ($row["to_pay_amount"] ?? 0),
        "checking" => (int) ($row["checking"] ?? 0),
        "reservations" => (int) ($row["reservations"] ?? 0),
        "unread_messages" => chat_unread_for_customer($pdo, $userId),
        "unread_updates" => customer_updates_unread($pdo, customer_current()),
    ];
}

/*
 * The side menu: sections of items. Each item: key, label, icon, href, badge (0 hides it).
 */
function customer_menu(PDO $pdo, array $user): array
{
    $counts = customer_counts($pdo, (int) $user["id"]);

    $sections = [
        "" => [
            ["dashboard", "Dashboard", "home", "dashboard.php", 0],
        ],
        "My Stays" => [
            ["reservations", "My Reservations", "clipboard", "reservations.php", $counts["to_pay"]],
            ["payments", "Payments", "credit-card", "payments.php", 0],
            ["book", "Book a Room", "bed", "../rooms.php", 0],
        ],
        "Account" => [
            ["messages", "Messages", "message", "messages.php", $counts["unread_messages"]],
            ["notifications", "Notifications", "bell", "notifications.php", $counts["unread_updates"]],
            ["profile", "My Profile", "user", "profile.php", 0],
        ],
    ];

    $menu = [];

    foreach ($sections as $title => $items) {
        foreach ($items as [$key, $label, $iconName, $href, $badge]) {
            $menu[$title][] = ["key" => $key, "label" => $label, "icon" => $iconName, "href" => $href, "badge" => (int) $badge];
        }
    }

    return $menu;
}


// ======================================================
// UPDATES ABOUT THE GUEST'S BOOKINGS
// ======================================================

/*
 * Events of the activity log about this guest's reservations and payments, newest first,
 * with the reservation they belong to (room, dates) and the payment's amount.
 *   $onlyUpdates true: only what the guest is told about (the bell); false: everything,
 *                also what the guest did themselves (the dashboard's history)
 *   $beforeId    for "show older"
 */
function customer_events(PDO $pdo, int $userId, int $limit = 10, bool $onlyUpdates = true, int $beforeId = 0): array
{
    [$where, $values] = customer_events_where($userId, $onlyUpdates);

    if ($beforeId > 0) {
        $where .= " AND a.id < ?";
        $values[] = $beforeId;
    }

    $stmt = $pdo->prepare("
        SELECT a.id, a.created_at, a.type, a.actor_id, a.actor_role,
               r.id AS reservation_id, r.check_in, r.check_out, r.status AS reservation_status,
               rooms.room_name, p.amount AS payment_amount, p.payment_method, p.status AS payment_now
        FROM activity_log a
        LEFT JOIN payments p ON a.entity_type = 'payment' AND p.id = a.entity_id
        INNER JOIN reservations r ON r.id = IF(a.entity_type = 'reservation', a.entity_id, p.reservation_id)
        LEFT JOIN rooms ON rooms.id = r.room_id
        WHERE {$where}
        ORDER BY a.id DESC
        LIMIT " . max(1, min(200, $limit)) . "
    ");
    $stmt->execute($values);

    return $stmt->fetchAll();
}

function customer_events_where(int $userId, bool $onlyUpdates): array
{
    $where = "r.user_id = ? AND a.entity_type IN ('reservation', 'payment')";
    $values = [$userId];

    if ($onlyUpdates) {
        $types = implode(", ", array_map(fn ($type) => "'" . $type . "'", CUSTOMER_UPDATE_TYPES));
        $where .= " AND (a.type IN ({$types}) OR (a.type = 'reservation.cancelled' AND (a.actor_id IS NULL OR a.actor_id <> ?)))";
        $values[] = $userId;
    } else {
        $where .= " AND (a.type LIKE 'reservation.%' OR a.type LIKE 'payment.%')";
    }

    return [$where, $values];
}

function customer_updates_unread(PDO $pdo, array $user): int
{
    if (!$user) {
        return 0;
    }

    [$where, $values] = customer_events_where((int) $user["id"], true);

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM activity_log a
        LEFT JOIN payments p ON a.entity_type = 'payment' AND p.id = a.entity_id
        INNER JOIN reservations r ON r.id = IF(a.entity_type = 'reservation', a.entity_id, p.reservation_id)
        WHERE {$where} AND a.created_at > ?
    ");
    $stmt->execute(array_merge($values, [(string) $user["notifications_seen_at"]]));

    return (int) $stmt->fetchColumn();
}

// Everything up to now counts as seen.
function customer_updates_mark_seen(PDO $pdo, array &$user): void
{
    $user["notifications_seen_at"] = utc_now();

    $pdo->prepare("UPDATE users SET notifications_seen_at = ? WHERE id = ?")
        ->execute([$user["notifications_seen_at"], $user["id"]]);

    if (isset($GLOBALS["arvesCustomer"]["user"])) {
        $GLOBALS["arvesCustomer"]["user"]["notifications_seen_at"] = $user["notifications_seen_at"];
    }
}

// What an event means for the guest, in their words.
function customer_event_text(array $event, int $userId): string
{
    $number = "#" . (int) $event["reservation_id"];
    $room = trim((string) ($event["room_name"] ?? "")) ?: "your room";
    $stay = admin_stay((string) $event["check_in"], (string) $event["check_out"]);
    $which = "reservation " . $number . " (" . $room . ", " . $stay . ")";
    $amount = $event["payment_amount"] !== null ? peso($event["payment_amount"], 2) : "";
    $byMe = (int) ($event["actor_id"] ?? 0) === $userId;

    switch ($event["type"]) {
        case "reservation.created":
            return "You booked " . $room . " for " . $stay . " (reservation " . $number . ").";
        case "reservation.cancelled":
            return $byMe ? "You cancelled " . $which . "." : ucfirst($which) . " was cancelled.";
        case "reservation.expired":
            return ucfirst($which) . " was cancelled because it was not paid in time.";
        case "reservation.declined":
            return "We could not accept " . $which . ". Its dates are open again.";
        case "reservation.confirmed":
            return ucfirst($which) . " is confirmed. See you soon!";
        case "reservation.completed":
            return "Thank you for staying with us! Reservation " . $number . " is completed.";
        case "payment.submitted":
            return "You sent a payment" . ($amount !== "" ? " of " . $amount : "") . " for reservation " . $number . "."
                . (($event["payment_now"] ?? "") === "pending" ? " We will check it soon." : "");
        case "payment.verified":
            return "Your payment" . ($amount !== "" ? " of " . $amount : "") . " for reservation " . $number . " was verified. Your stay is confirmed.";
        case "payment.rejected":
            return "Your payment for reservation " . $number . " was not accepted. Please send it again.";
        case "payment.online_paid":
            return "Your online payment" . ($amount !== "" ? " of " . $amount : "") . " for reservation " . $number . " went through. Your stay is confirmed.";
        case "payment.online_cancelled":
            return "The online payment for reservation " . $number . " was not finished. You can try again.";
    }

    return "Update on " . $which . ".";
}

/*
 * A list of events as icon, sentence and time (the same look as the admin's lists).
 *   new_since  UTC time: events after it are marked new
 *   empty      text for an empty list
 */
function customer_feed(array $events, int $userId, array $options = []): string
{
    $options += ["new_since" => null, "empty" => "Nothing here yet"];

    if (!$events) {
        return '<div class="empty empty-sm"><div class="empty-icon">' . icon("inbox", 20) . '</div>'
            . '<p>' . h($options["empty"]) . '</p></div>';
    }

    $html = '<ul class="feed">';

    foreach ($events as $event) {
        $isNew = $options["new_since"] !== null && $event["created_at"] > $options["new_since"];

        $html .= '<li><a class="feed-item' . ($isNew ? " is-unread" : "") . '" href="reservations.php?open=' . (int) $event["reservation_id"] . '">'
            . '<span class="feed-icon tone-' . activity_tone($event["type"]) . '">' . icon(activity_icon($event["type"])) . '</span>'
            . '<span class="feed-body"><span class="feed-text">' . h(customer_event_text($event, $userId)) . '</span></span>'
            . '<time class="feed-time" datetime="' . h(str_replace(" ", "T", $event["created_at"])) . 'Z" title="'
            . h(activity_time($event["created_at"])) . '">' . h(activity_time_ago($event["created_at"])) . '</time>'
            . '</a></li>';
    }

    return $html . '</ul>';
}


// ======================================================
// SMALL PIECES THE PAGES SHARE
// ======================================================

// The first photo of a room (Admin → Rooms → Photos), as seen from a customer page, or "".
function customer_room_photo(int $roomId): string
{
    $path = __DIR__ . "/../uploads/rooms/room_" . $roomId . "_1.jpg";

    return is_file($path) ? "../uploads/rooms/room_" . $roomId . "_1.jpg?v=" . filemtime($path) : "";
}

// "Today", "Tomorrow", "In 5 days", "Staying now", or "" for a stay in the past.
function customer_countdown(string $checkIn, string $checkOut): string
{
    $today = strtotime(date("Y-m-d"));
    $in = strtotime($checkIn);
    $out = strtotime($checkOut);

    if ($in === false || $out === false || $out <= $today) {
        return "";
    }

    if ($in <= $today) {
        return "Staying now";
    }

    $days = (int) round(($in - $today) / 86400);

    return $days === 1 ? "Tomorrow" : "In " . $days . " days";
}

// The payment of a reservation in a few words, for its pill.
function customer_payment_pill(?string $status): string
{
    switch ($status) {
        case "verified":
            return status_pill("verified", "Paid");
        case "pending":
            return status_pill("pending", "Being checked");
        case "rejected":
            return status_pill("rejected", "Not accepted");
        case "cancelled":
            return status_pill("cancelled", "Not finished");
    }

    return status_pill("not set up", "Not paid yet");
}
