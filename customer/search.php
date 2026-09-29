<?php
/*
 * Search in the top bar (assets/admin.js asks: search.php?q=...), answered with JSON:
 * the guest's own reservations and payments, and the pages of the menu.
 */
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/customer-shell.php";

$me = customer_boot_json($pdo);
$userId = (int) $me["id"];
$query = admin_query();

if (mb_strlen($query) < 2) {
    echo json_encode(["ok" => true, "groups" => []]);
    exit;
}

$groups = [];

// reservations: "#12", "12", or part of the room's name
$number = preg_match('/^#?(\d{1,9})$/', $query, $m) ? (int) $m[1] : 0;

$stmt = $pdo->prepare("
    SELECT r.id, r.room_id, r.check_in, r.check_out, r.status, rooms.room_name
    FROM reservations r
    INNER JOIN rooms ON rooms.id = r.room_id
    WHERE r.user_id = ? AND (r.id = ? OR rooms.room_name LIKE ?)
    ORDER BY r.created_at DESC
    LIMIT 6
");
$stmt->execute([$userId, $number, admin_like($query)]);

$items = [];

foreach ($stmt as $reservation) {
    $items[] = [
        "title" => $reservation["room_name"] . " · #" . (int) $reservation["id"],
        "sub" => admin_stay($reservation["check_in"], $reservation["check_out"]) . " · " . ucfirst($reservation["status"]),
        "href" => "reservations.php?open=" . (int) $reservation["id"],
        "icon" => icon("calendar"),
    ];
}

$groups[] = ["title" => "Reservations", "items" => $items];

// payments: by reference number, or the reservation's number
$stmt = $pdo->prepare("
    SELECT p.id, p.reservation_id, p.amount, p.status, p.payment_method, p.reference_number
    FROM payments p
    WHERE p.user_id = ? AND (p.reservation_id = ? OR (p.reference_number <> '' AND p.reference_number LIKE ?))
    ORDER BY p.id DESC
    LIMIT 5
");
$stmt->execute([$userId, $number, admin_like($query)]);

$items = [];

foreach ($stmt as $payment) {
    $items[] = [
        "title" => peso($payment["amount"], 2) . " · " . payment_method_label((string) $payment["payment_method"]),
        "sub" => "Reservation #" . (int) $payment["reservation_id"] . " · " . ucfirst($payment["status"])
            . ($payment["reference_number"] ? " · Ref " . $payment["reference_number"] : ""),
        "href" => "reservations.php?open=" . (int) $payment["reservation_id"],
        "icon" => icon("credit-card"),
    ];
}

$groups[] = ["title" => "Payments", "items" => $items];

// the pages of the menu, and a few words people may type for them
$pages = [
    ["Dashboard", "dashboard.php", "home", "home overview start"],
    ["My Reservations", "reservations.php", "clipboard", "bookings booking stays cancel"],
    ["Payments", "payments.php", "credit-card", "pay paid receipt gcash"],
    ["Book a Room", "../rooms.php", "bed", "book rooms available new reserve"],
    ["Messages", "messages.php", "message", "chat help contact question ask"],
    ["Notifications", "notifications.php", "bell", "updates news alerts"],
    ["My Profile", "profile.php", "user", "account name phone photo picture password"],
];

$items = [];
$needle = mb_strtolower($query);

foreach ($pages as [$title, $href, $iconName, $words]) {
    if (str_contains(mb_strtolower($title . " " . $words), $needle)) {
        $items[] = ["title" => $title, "sub" => "", "href" => $href, "icon" => icon($iconName)];
    }
}

$groups[] = ["title" => "Pages", "items" => $items];

echo json_encode(["ok" => true, "groups" => $groups]);
