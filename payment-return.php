<?php

// ======================================================
// BACK FROM PAYMONGO CHECKOUT
//
// PayMongo sends the customer here after paying (success) or backing out (cancelled=1).
// We ask PayMongo what really happened, never trusting the URL alone.
// ======================================================

session_start();

require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/paymongo.php";

if (!isset($_SESSION["user_id"]) || ($_SESSION["role"] ?? "") !== "customer") {
    header("Location: login.php");
    exit;
}

ensure_paymongo_schema($pdo);

$paymentId = (int) ($_GET["payment"] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ? AND user_id = ? LIMIT 1");
$stmt->execute([$paymentId, (int) $_SESSION["user_id"]]);
$payment = $stmt->fetch();

if (!$payment) {
    header("Location: customer/dashboard.php");
    exit;
}

$result = paymongo_settle($pdo, $payment, isset($_GET["cancelled"]));

$outcome = [
    "verified" => "paid",
    "cancelled" => "cancelled",
][$result] ?? "processing";

header("Location: payment.php?reservation_id=" . (int) $payment["reservation_id"] . "&online=" . $outcome);
exit;
