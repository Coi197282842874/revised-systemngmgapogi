<?php
/*
 * The search box in the admin top bar (assets/admin.js). Answers with JSON:
 *     { ok: true, groups: [ { title: "Customers", items: [ { title, sub, href, icon } ] } ] }
 * An admin only finds what their role may open. Results link to the list pages with ?q=...
 */

session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/admin.php";

const SEARCH_PER_GROUP = 5;

admin_boot_json($pdo);

$query = mb_substr(trim((string) ($_GET["q"] ?? "")), 0, 80);

if (mb_strlen($query) < 2) {
    echo json_encode(["ok" => true, "groups" => []]);
    exit;
}

$like = "%" . str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $query) . "%";

// "#12" or "12" also finds reservation and payment number 12
$number = preg_match('/^#?(\d{1,9})$/', $query, $match) ? (int) $match[1] : 0;

$groups = [];


// ---- pages of the admin panel ----

$pages = [];

// words people type that are not in a page's name
$alsoKnownAs = [
    "reservations" => "bookings booking reserve pending confirmed",
    "calendar" => "dates close block schedule availability",
    "rooms" => "room price capacity photos",
    "payments" => "payment gcash cash verify paymongo money",
    "customers" => "users guests accounts people",
    "messages" => "chat inbox message",
    "admins" => "roles permissions staff admin users access",
    "analytics" => "reports revenue sales occupancy charts statistics",
    "activity" => "logs history audit",
    "settings" => "homepage hero card site photos pictures slideshow",
    "integrations" => "google email smtp gmail paymongo api keys",
    "system" => "health backup database server php status",
    "help" => "support faq guide how",
];

foreach (admin_menu($pdo) as $items) {
    foreach ($items as $item) {
        $haystack = $item["label"] . " " . ($alsoKnownAs[$item["key"]] ?? "");

        if (mb_stripos($haystack, $query) !== false) {
            $pages[] = ["title" => $item["label"], "sub" => "", "href" => $item["href"], "icon" => icon($item["icon"])];
        }
    }
}

foreach ([
    ["Change password", "account.php", "key", "password account security"],
    ["View website", "../index.php", "external-link", "website site homepage public"],
    ["Log out", "../logout.php", "log-out", "logout sign out exit"],
] as [$label, $href, $iconName, $words]) {
    if (mb_stripos($label . " " . $words, $query) !== false) {
        $pages[] = ["title" => $label, "sub" => "", "href" => $href, "icon" => icon($iconName)];
    }
}

if ($pages) {
    $groups[] = ["title" => "Go to", "items" => array_slice($pages, 0, SEARCH_PER_GROUP)];
}


// ---- customers ----

if (admin_can("customers")) {
    $stmt = $pdo->prepare(
        "SELECT id, full_name, email, phone
         FROM users
         WHERE role = 'customer' AND (full_name LIKE ? OR email LIKE ? OR phone LIKE ?)
         ORDER BY full_name
         LIMIT " . SEARCH_PER_GROUP
    );
    $stmt->execute([$like, $like, $like]);

    $items = [];

    foreach ($stmt as $row) {
        $items[] = [
            "title" => $row["full_name"],
            "sub" => $row["email"],
            "href" => "customers.php?q=" . rawurlencode($row["email"]),
            "icon" => icon("user"),
        ];
    }

    if ($items) {
        $groups[] = ["title" => "Customers", "items" => $items];
    }
}


// ---- reservations ----

if (admin_can("reservations")) {
    $stmt = $pdo->prepare(
        "SELECT reservations.id, reservations.check_in, reservations.check_out, reservations.status,
                rooms.room_name, users.full_name
         FROM reservations
         INNER JOIN rooms ON rooms.id = reservations.room_id
         INNER JOIN users ON users.id = reservations.user_id
         WHERE reservations.id = ? OR users.full_name LIKE ? OR users.email LIKE ? OR rooms.room_name LIKE ?
         ORDER BY reservations.id = ? DESC, reservations.created_at DESC
         LIMIT " . SEARCH_PER_GROUP
    );
    $stmt->execute([$number, $like, $like, $like, $number]);

    $items = [];

    foreach ($stmt as $row) {
        $items[] = [
            "title" => "#" . $row["id"] . " · " . $row["full_name"],
            "sub" => $row["room_name"] . " · " . admin_stay($row["check_in"], $row["check_out"]) . " · " . ucfirst($row["status"]),
            "href" => "reservations.php?q=" . (int) $row["id"],
            "icon" => icon("calendar"),
        ];
    }

    if ($items) {
        $groups[] = ["title" => "Reservations", "items" => $items];
    }
}


// ---- payments ----

if (admin_can("payments")) {
    $stmt = $pdo->prepare(
        "SELECT payments.id, payments.amount, payments.payment_method, payments.status,
                payments.reference_number, payments.reservation_id, users.full_name
         FROM payments
         INNER JOIN users ON users.id = payments.user_id
         WHERE payments.id = ? OR payments.reservation_id = ? OR payments.reference_number LIKE ? OR users.full_name LIKE ?
         ORDER BY payments.id = ? DESC, payments.created_at DESC
         LIMIT " . SEARCH_PER_GROUP
    );
    $stmt->execute([$number, $number, $like, $like, $number]);

    $items = [];

    foreach ($stmt as $row) {
        $reference = trim((string) $row["reference_number"]);

        $items[] = [
            "title" => peso($row["amount"]) . " · " . $row["full_name"],
            "sub" => "Reservation #" . $row["reservation_id"] . " · " . ucfirst($row["status"])
                . ($reference !== "" ? " · Ref " . $reference : ""),
            "href" => "payment.php?q=" . rawurlencode($reference !== "" ? $reference : "#" . $row["reservation_id"]),
            "icon" => icon("credit-card"),
        ];
    }

    if ($items) {
        $groups[] = ["title" => "Payments", "items" => $items];
    }
}


// ---- rooms ----

if (admin_can("rooms")) {
    $stmt = $pdo->prepare(
        "SELECT id, room_name, price, capacity, status
         FROM rooms
         WHERE room_name LIKE ? OR description LIKE ?
         ORDER BY room_name
         LIMIT " . SEARCH_PER_GROUP
    );
    $stmt->execute([$like, $like]);

    $items = [];

    foreach ($stmt as $row) {
        $items[] = [
            "title" => $row["room_name"],
            "sub" => peso($row["price"]) . " a night · up to " . (int) $row["capacity"] . " guests · " . ucfirst($row["status"]),
            "href" => "rooms.php?q=" . rawurlencode($row["room_name"]),
            "icon" => icon("bed"),
        ];
    }

    if ($items) {
        $groups[] = ["title" => "Rooms", "items" => $items];
    }
}


// ---- admins ----

if (admin_can("admins")) {
    $stmt = $pdo->prepare(
        "SELECT id, full_name, email, admin_role
         FROM users
         WHERE role = 'admin' AND (full_name LIKE ? OR email LIKE ?)
         ORDER BY full_name
         LIMIT " . SEARCH_PER_GROUP
    );
    $stmt->execute([$like, $like]);

    $items = [];

    foreach ($stmt as $row) {
        $items[] = [
            "title" => $row["full_name"],
            "sub" => admin_role_label(admin_role_of($row)) . " · " . $row["email"],
            "href" => "admins.php",
            "icon" => icon("shield"),
        ];
    }

    if ($items) {
        $groups[] = ["title" => "Admins", "items" => $items];
    }
}

echo json_encode(["ok" => true, "groups" => $groups], JSON_UNESCAPED_UNICODE);
