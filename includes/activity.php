<?php
/*
 * Activity log and admin notifications.
 *
 * One table, activity_log: every noteworthy event (a booking, a payment, an admin action, a
 * login, an automatic cancellation). Rows with notify = 1 are also the admin's notifications:
 * the bell in the admin top bar counts the ones with read_at IS NULL.
 *
 *     log_activity($pdo, "reservation.created", "Maria Santos booked family room, Oct 12 to Oct 14", [
 *         "entity_type" => "reservation", "entity_id" => 21,
 *         "link" => "reservations.php?status=pending",    // relative to /admin/
 *         "notify" => true,
 *     ]);
 *
 * log_activity() never throws and never blocks the page: logging must not break a booking.
 * Times are stored in UTC (like the messages); show them with activity_time_ago() or
 * activity_time() which convert to Philippine time.
 *
 * Event types in use (keep new ones in the same "thing.verb" style):
 *   reservation.created   reservation.cancelled (by the customer)   reservation.expired (unpaid, automatic)
 *   reservation.declined  reservation.completed  reservation.confirmed
 *   payment.submitted (cash / pay at property, needs verifying)     payment.verified   payment.rejected
 *   payment.online_paid   payment.online_cancelled
 *   customer.registered   customer.activated   customer.deactivated
 *   message.received   message.sent   review.created   review.hidden   review.shown
 *   admin.login   admin.created   admin.role_changed   admin.permissions_changed
 *   admin.activated   admin.deactivated   admin.password_changed
 *   room.created  room.updated  room.deleted   dates.closed   dates.opened
 *   settings.updated   backup.downloaded   ticket.scanned
 */

require_once __DIR__ . "/auth.php";

function ensure_activity_schema(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS activity_log (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            actor_id INT UNSIGNED NULL,
            actor_role VARCHAR(20) NOT NULL DEFAULT 'system',
            actor_name VARCHAR(150) NOT NULL DEFAULT '',
            type VARCHAR(50) NOT NULL,
            summary VARCHAR(255) NOT NULL,
            entity_type VARCHAR(30) NULL,
            entity_id INT UNSIGNED NULL,
            link VARCHAR(255) NULL,
            notify TINYINT(1) NOT NULL DEFAULT 0,
            read_at DATETIME NULL,
            ip VARCHAR(45) NULL,
            PRIMARY KEY (id),
            KEY activity_created_idx (created_at),
            KEY activity_notify_idx (notify, read_at),
            KEY activity_type_idx (type),
            KEY activity_entity_idx (entity_type, entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/*
 * Records an event. Options (all optional):
 *   actor_id, actor_role ("admin" | "customer" | "system" | "guest"), actor_name
 *       default: the logged-in user from the session, otherwise "system"
 *   entity_type, entity_id   what the event is about ("reservation", 21)
 *   link                     page to open from the admin, relative to /admin/
 *   notify                   true: also show it as an admin notification
 *   ip                       true: store the visitor's IP address (use for logins only)
 */
function log_activity(PDO $pdo, string $type, string $summary, array $options = []): void
{
    try {
        ensure_activity_schema($pdo);

        $sessionId = (int) ($_SESSION["user_id"] ?? 0);

        $actorId = array_key_exists("actor_id", $options) ? $options["actor_id"] : ($sessionId ?: null);
        $actorRole = $options["actor_role"] ?? ($sessionId ? (string) ($_SESSION["role"] ?? "customer") : "system");
        $actorName = $options["actor_name"] ?? ($sessionId ? (string) ($_SESSION["full_name"] ?? "") : "");

        $pdo->prepare(
            "INSERT INTO activity_log
                (created_at, actor_id, actor_role, actor_name, type, summary, entity_type, entity_id, link, notify, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            utc_now(),
            $actorId ? (int) $actorId : null,
            mb_substr($actorRole, 0, 20),
            mb_substr($actorName, 0, 150),
            mb_substr($type, 0, 50),
            mb_substr($summary, 0, 255),
            isset($options["entity_type"]) ? mb_substr((string) $options["entity_type"], 0, 30) : null,
            isset($options["entity_id"]) ? (int) $options["entity_id"] : null,
            isset($options["link"]) ? mb_substr((string) $options["link"], 0, 255) : null,
            !empty($options["notify"]) ? 1 : 0,
            !empty($options["ip"]) ? mb_substr((string) ($_SERVER["REMOTE_ADDR"] ?? ""), 0, 45) : null,
        ]);
    } catch (Throwable $e) {
        error_log("ARVE'S House activity log failed: " . $e->getMessage());
    }
}

/*
 * Latest events, newest first. Filters (all optional):
 *   type_prefix ("payment." or "reservation."), actor_role, not_actor_role, notify (bool), unread (bool),
 *   search (text in the summary or the actor's name), before_id (for "older" paging),
 *   since, until (UTC "Y-m-d H:i:s": from since, up to but not including until)
 */
function activity_recent(PDO $pdo, int $limit = 20, array $filters = []): array
{
    ensure_activity_schema($pdo);

    [$where, $values] = activity_where($filters);

    $stmt = $pdo->prepare(
        "SELECT * FROM activity_log"
        . ($where ? " WHERE " . implode(" AND ", $where) : "")
        . " ORDER BY id DESC LIMIT " . max(1, min(5000, $limit))
    );
    $stmt->execute($values);

    return $stmt->fetchAll();
}

// How many events match the same filters as activity_recent().
function activity_count(PDO $pdo, array $filters = []): int
{
    ensure_activity_schema($pdo);

    [$where, $values] = activity_where($filters);

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM activity_log" . ($where ? " WHERE " . implode(" AND ", $where) : "")
    );
    $stmt->execute($values);

    return (int) $stmt->fetchColumn();
}

// The filters of activity_recent() as SQL conditions and their values.
function activity_where(array $filters): array
{
    $where = [];
    $values = [];

    if (!empty($filters["since"])) {
        $where[] = "created_at >= ?";
        $values[] = (string) $filters["since"];
    }

    if (!empty($filters["until"])) {
        $where[] = "created_at < ?";
        $values[] = (string) $filters["until"];
    }

    if (!empty($filters["type_prefix"])) {
        $where[] = "type LIKE ?";
        $values[] = str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], (string) $filters["type_prefix"]) . "%";
    }

    if (!empty($filters["actor_role"])) {
        $where[] = "actor_role = ?";
        $values[] = (string) $filters["actor_role"];
    }

    if (!empty($filters["not_actor_role"])) {
        $where[] = "actor_role <> ?";
        $values[] = (string) $filters["not_actor_role"];
    }

    if (array_key_exists("notify", $filters)) {
        $where[] = "notify = ?";
        $values[] = $filters["notify"] ? 1 : 0;
    }

    if (!empty($filters["unread"])) {
        $where[] = "read_at IS NULL";
    }

    if (!empty($filters["search"])) {
        $like = "%" . str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], (string) $filters["search"]) . "%";
        $where[] = "(summary LIKE ? OR actor_name LIKE ?)";
        $values[] = $like;
        $values[] = $like;
    }

    if (!empty($filters["before_id"])) {
        $where[] = "id < ?";
        $values[] = (int) $filters["before_id"];
    }

    return [$where, $values];
}

function admin_notifications(PDO $pdo, int $limit = 8, bool $unreadOnly = false): array
{
    return activity_recent($pdo, $limit, ["notify" => true] + ($unreadOnly ? ["unread" => true] : []));
}

function admin_notifications_unread_count(PDO $pdo): int
{
    ensure_activity_schema($pdo);

    return (int) $pdo->query(
        "SELECT COUNT(*) FROM activity_log WHERE notify = 1 AND read_at IS NULL"
    )->fetchColumn();
}

// Marks one notification (by id) or all of them as read.
function admin_notifications_mark_read(PDO $pdo, ?int $id = null): void
{
    ensure_activity_schema($pdo);

    if ($id === null) {
        $pdo->prepare("UPDATE activity_log SET read_at = ? WHERE notify = 1 AND read_at IS NULL")
            ->execute([utc_now()]);
        return;
    }

    $pdo->prepare("UPDATE activity_log SET read_at = ? WHERE id = ? AND notify = 1 AND read_at IS NULL")
        ->execute([utc_now(), $id]);
}

// Icon name (includes/icons.php) for an event type.
function activity_icon(string $type): string
{
    $exact = [
        "reservation.created" => "calendar",
        "reservation.confirmed" => "check-circle",
        "reservation.completed" => "check-circle",
        "reservation.cancelled" => "x-circle",
        "reservation.declined" => "x-circle",
        "reservation.expired" => "clock",
        "payment.submitted" => "banknote",
        "payment.verified" => "check-circle",
        "payment.rejected" => "x-circle",
        "payment.online_paid" => "credit-card",
        "payment.online_cancelled" => "x-circle",
        "customer.registered" => "user-plus",
        "message.received" => "mail",
        "admin.login" => "log-in",
        "admin.created" => "user-plus",
        "admin.role_changed" => "shield",
        "admin.password_changed" => "key",
        "dates.closed" => "lock",
        "dates.opened" => "calendar",
        "settings.updated" => "settings",
        "backup.downloaded" => "download",
        "ticket.scanned" => "scan",
    ];

    if (isset($exact[$type])) {
        return $exact[$type];
    }

    $byPrefix = [
        "reservation." => "calendar",
        "payment." => "credit-card",
        "review." => "star",
        "room." => "bed",
        "admin." => "shield",
        "customer." => "user",
        "message." => "mail",
    ];

    foreach ($byPrefix as $prefix => $icon) {
        if (str_starts_with($type, $prefix)) {
            return $icon;
        }
    }

    return "activity";
}

// "Just now", "5 min ago", "3 hr ago", "Yesterday", then the date. $utc is "Y-m-d H:i:s" in UTC.
function activity_time_ago(string $utc): string
{
    $seconds = time() - utc_ts($utc);

    if ($seconds < 60) {
        return "Just now";
    }

    if ($seconds < 3600) {
        return floor($seconds / 60) . " min ago";
    }

    if ($seconds < 86400) {
        return floor($seconds / 3600) . " hr ago";
    }

    if ($seconds < 172800) {
        return "Yesterday";
    }

    if ($seconds < 604800) {
        return floor($seconds / 86400) . " days ago";
    }

    return activity_time($utc, "M j, Y");
}

// A UTC "Y-m-d H:i:s" shown in Philippine time.
function activity_time(string $utc, string $format = "M j, Y · g:i A"): string
{
    $date = new DateTime($utc, new DateTimeZone("UTC"));
    $date->setTimezone(new DateTimeZone("Asia/Manila"));

    return $date->format($format);
}

/*
 * Fills an empty log with what already happened (customers, bookings, payments), so the
 * dashboard has a history from the first day. Bookings and payments become notifications
 * that are already read. Runs once: as soon as the log has a row it does nothing.
 */
function activity_backfill(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    try {
        ensure_activity_schema($pdo);

        if ($pdo->query("SELECT 1 FROM activity_log LIMIT 1")->fetchColumn()) {
            return;
        }

        // two admins opening the panel at the same moment must not fill it twice
        if (!$pdo->query("SELECT GET_LOCK('arves_activity_backfill', 0)")->fetchColumn()) {
            return;
        }

        try {
            if ($pdo->query("SELECT 1 FROM activity_log LIMIT 1")->fetchColumn()) {
                return;
            }

            $events = [];

            $customers = $pdo->query(
                "SELECT id, full_name, created_at FROM users WHERE role = 'customer'"
            );

            foreach ($customers as $row) {
                $events[] = [
                    "at" => strtotime($row["created_at"]),
                    "actor_id" => (int) $row["id"],
                    "actor_name" => $row["full_name"],
                    "type" => "customer.registered",
                    "summary" => $row["full_name"] . " created an account",
                    "entity_type" => "customer",
                    "entity_id" => (int) $row["id"],
                    "link" => "customers.php",
                    "notify" => 0,
                ];
            }

            $reservations = $pdo->query(
                "SELECT reservations.id, reservations.user_id, reservations.check_in, reservations.check_out,
                        reservations.created_at, rooms.room_name, users.full_name
                 FROM reservations
                 INNER JOIN rooms ON rooms.id = reservations.room_id
                 INNER JOIN users ON users.id = reservations.user_id"
            );

            foreach ($reservations as $row) {
                $events[] = [
                    "at" => strtotime($row["created_at"]),
                    "actor_id" => (int) $row["user_id"],
                    "actor_name" => $row["full_name"],
                    "type" => "reservation.created",
                    "summary" => $row["full_name"] . " booked " . $row["room_name"] . ", "
                        . date("M j", strtotime($row["check_in"])) . " to " . date("M j", strtotime($row["check_out"])),
                    "entity_type" => "reservation",
                    "entity_id" => (int) $row["id"],
                    "link" => "reservations.php",
                    "notify" => 1,
                ];
            }

            $payments = $pdo->query(
                "SELECT payments.id, payments.user_id, payments.reservation_id, payments.amount,
                        payments.payment_method, payments.status, payments.created_at, users.full_name
                 FROM payments
                 INNER JOIN users ON users.id = payments.user_id
                 WHERE payments.status IN ('pending', 'verified', 'rejected')"
            );

            foreach ($payments as $row) {
                $online = str_starts_with((string) $row["payment_method"], "online");

                if ($online && $row["status"] !== "verified") {
                    continue;
                }

                $method = ucwords(str_replace(["online_", "_"], ["", " "], (string) $row["payment_method"]));

                $events[] = [
                    "at" => strtotime($row["created_at"]),
                    "actor_id" => (int) $row["user_id"],
                    "actor_name" => $row["full_name"],
                    "type" => $online ? "payment.online_paid" : "payment.submitted",
                    "summary" => $row["full_name"] . ($online ? " paid " : " sent a payment of ")
                        . "₱" . number_format((float) $row["amount"], 2) . " (" . $method . ") for reservation #"
                        . (int) $row["reservation_id"],
                    "entity_type" => "payment",
                    "entity_id" => (int) $row["id"],
                    "link" => "payment.php",
                    "notify" => 1,
                ];
            }

            usort($events, fn ($a, $b) => $a["at"] <=> $b["at"]);

            $insert = $pdo->prepare(
                "INSERT INTO activity_log
                    (created_at, actor_id, actor_role, actor_name, type, summary, entity_type, entity_id, link, notify, read_at)
                 VALUES (?, ?, 'customer', ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            foreach ($events as $event) {
                $at = gmdate("Y-m-d H:i:s", $event["at"] ?: time());

                $insert->execute([
                    $at,
                    $event["actor_id"],
                    mb_substr((string) $event["actor_name"], 0, 150),
                    $event["type"],
                    mb_substr($event["summary"], 0, 255),
                    $event["entity_type"],
                    $event["entity_id"],
                    $event["link"],
                    $event["notify"],
                    $event["notify"] ? $at : null,
                ]);
            }
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('arves_activity_backfill')");
        }
    } catch (Throwable $e) {
        error_log("ARVE'S House activity backfill failed: " . $e->getMessage());
    }
}

// Short name of an event type for tables and filters: "payment.verified" → "Payment verified".
function activity_label(string $type): string
{
    $labels = [
        "reservation.created" => "New reservation",
        "reservation.confirmed" => "Reservation confirmed",
        "reservation.completed" => "Stay completed",
        "reservation.cancelled" => "Reservation cancelled",
        "reservation.declined" => "Reservation declined",
        "reservation.expired" => "Reservation expired",
        "payment.submitted" => "Payment sent",
        "payment.verified" => "Payment verified",
        "payment.rejected" => "Payment rejected",
        "payment.online_paid" => "Online payment",
        "payment.online_cancelled" => "Online payment cancelled",
        "customer.registered" => "New customer",
        "customer.activated" => "Customer turned on",
        "customer.deactivated" => "Customer turned off",
        "message.received" => "New message",
        "message.sent" => "Reply sent",
        "admin.login" => "Admin login",
        "admin.created" => "Admin added",
        "admin.role_changed" => "Role changed",
        "admin.permissions_changed" => "Permissions changed",
        "admin.activated" => "Admin turned on",
        "admin.deactivated" => "Admin turned off",
        "admin.password_changed" => "Password changed",
        "room.created" => "Room added",
        "room.updated" => "Room edited",
        "room.deleted" => "Room deleted",
        "dates.closed" => "Dates closed",
        "dates.opened" => "Dates opened",
        "settings.updated" => "Settings changed",
        "backup.downloaded" => "Backup downloaded",
    ];

    return $labels[$type] ?? ucfirst(str_replace([".", "_"], " ", $type));
}

// The groups of event types, for filters: "payment." => "Payments"
const ACTIVITY_GROUPS = [
    "reservation." => "Reservations",
    "payment." => "Payments",
    "customer." => "Customers",
    "message." => "Messages",
    "room." => "Rooms",
    "dates." => "Calendar",
    "admin." => "Admins",
    "settings." => "Settings",
    "backup." => "Backups",
];
