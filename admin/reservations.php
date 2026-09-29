<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/icons.php";
require_once __DIR__ . "/../includes/chat.php";

ensure_chat_schema($pdo);
$chatUnread = chat_unread_for_admins($pdo);


// ======================================================
// ADMIN CHECK
// ======================================================

if (
    !isset($_SESSION["user_id"]) ||
    ($_SESSION["role"] ?? "") !== "admin"
) {
    header("Location: ../login.php");
    exit;
}


// ======================================================
// HANDLE ACTIONS
// ======================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $reservation_id =
        (int) ($_POST["reservation_id"] ?? 0);

    $action =
        $_POST["action"] ?? "";

    if ($reservation_id > 0) {

        $stmt = $pdo->prepare("
            SELECT
                reservations.id,
                reservations.status,
                (
                    SELECT payments.status
                    FROM payments
                    WHERE payments.reservation_id = reservations.id
                    ORDER BY payments.id DESC
                    LIMIT 1
                ) AS payment_status
            FROM reservations
            WHERE reservations.id = ?
            LIMIT 1
        ");

        $stmt->execute([
            $reservation_id
        ]);

        $reservation =
            $stmt->fetch();

        if ($reservation) {

            $paymentStatus =
                $reservation["payment_status"]
                ?? null;

            if (
                $action === "decline"
                && $reservation["status"] === "pending"
                && $paymentStatus !== "verified"
            ) {

                $stmt = $pdo->prepare("
                    UPDATE reservations
                    SET status = 'declined'
                    WHERE id = ?
                    AND status = 'pending'
                ");

                $stmt->execute([
                    $reservation_id
                ]);

            } elseif (
                $action === "complete"
                && $reservation["status"] === "confirmed"
            ) {

                $stmt = $pdo->prepare("
                    UPDATE reservations
                    SET status = 'completed'
                    WHERE id = ?
                    AND status = 'confirmed'
                ");

                $stmt->execute([
                    $reservation_id
                ]);
            }
        }
    }

    header("Location: reservations.php");
    exit;
}


// ======================================================
// FILTER
// ======================================================

$statusFilter = $_GET["status"] ?? "all";

$allowedFilters = [
    "all",
    "pending",
    "confirmed",
    "declined",
    "cancelled",
    "completed"
];

if (!in_array($statusFilter, $allowedFilters, true)) {
    $statusFilter = "all";
}


// ======================================================
// COUNTS
// ======================================================

$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM reservations
");
$totalReservations = (int) $stmt->fetchColumn();


$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM reservations
    WHERE status = 'pending'
");
$totalPending = (int) $stmt->fetchColumn();


$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM reservations
    WHERE status = 'confirmed'
");
$totalConfirmed = (int) $stmt->fetchColumn();


$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM reservations
    WHERE status = 'completed'
");
$totalCompleted = (int) $stmt->fetchColumn();


// ======================================================
// GET RESERVATIONS
// ONE RESERVATION = ONE ROW
// LATEST PAYMENT STATUS ONLY
// ======================================================

$sql = "
    SELECT
        reservations.*,
        rooms.room_name,
        users.full_name AS customer_name,
        users.email AS customer_email,
        users.profile_image,

        (
            SELECT payments.status
            FROM payments
            WHERE payments.reservation_id = reservations.id
            AND payments.user_id = reservations.user_id
            ORDER BY payments.id DESC
            LIMIT 1
        ) AS payment_status,

        (
            SELECT payments.id
            FROM payments
            WHERE payments.reservation_id = reservations.id
            AND payments.user_id = reservations.user_id
            ORDER BY payments.id DESC
            LIMIT 1
        ) AS payment_id,

        (
            SELECT payments.payment_method
            FROM payments
            WHERE payments.reservation_id = reservations.id
            AND payments.user_id = reservations.user_id
            ORDER BY payments.id DESC
            LIMIT 1
        ) AS payment_method

    FROM reservations

    INNER JOIN rooms
        ON reservations.room_id = rooms.id

    INNER JOIN users
        ON reservations.user_id = users.id
";

$params = [];

if ($statusFilter !== "all") {

    $sql .= "
        WHERE reservations.status = ?
    ";

    $params[] = $statusFilter;
}

$sql .= "
    ORDER BY reservations.created_at DESC
";

$stmt = $pdo->prepare($sql);

$stmt->execute($params);

$reservations = $stmt->fetchAll();


// ======================================================
// ADMIN INFO
// ======================================================

$stmt = $pdo->prepare("
    SELECT *
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([
    $_SESSION["user_id"]
]);

$admin = $stmt->fetch();
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0, viewport-fit=cover"
>

<meta
    name="theme-color"
    content="#ffffff"
>

<title>
    Reservations | ARVE'S House
</title>

<style>

/* MOTION TOKENS */

:root {
    --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
    --ease-in-out: cubic-bezier(0.77, 0, 0.175, 1);
    --ease-drawer: cubic-bezier(0.32, 0.72, 0, 1);
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

html {
    -webkit-tap-highlight-color: transparent;
    -webkit-text-size-adjust: 100%;
    text-size-adjust: 100%;
}

body {
    font-family: Arial, sans-serif;
    background: #f3f5f9;
    color: #111827;
}


/* CONTROLS (no double-tap delay, no long-press text selection) */

button,
.filter-btn,
.logout-button {
    touch-action: manipulation;
    -webkit-user-select: none;
    user-select: none;
}

.menu-item {
    touch-action: manipulation;
}


/* SIDEBAR */

.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: 270px;
    height: 100vh;
    height: 100dvh;
    padding-top: env(safe-area-inset-top, 0px);
    padding-bottom: env(safe-area-inset-bottom, 0px);
    padding-left: env(safe-area-inset-left, 0px);
    background: linear-gradient(
        180deg,
        #111827,
        #172033
    );
    color: white;
    display: flex;
    flex-direction: column;
    z-index: 1000;
}

.sidebar-logo {
    height: 90px;
    display: flex;
    align-items: center;
    padding: 0 28px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
}

.logo-icon {
    width: 46px;
    height: 46px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f59e0b;
    color: #111827;
    font-size: 22px;
    margin-right: 12px;
}

.logo-text h2 {
    font-size: 18px;
}

.logo-text span {
    font-size: 11px;
    color: #9ca3af;
    text-transform: uppercase;
    letter-spacing: 1.5px;
}

.sidebar-menu {
    padding: 25px 18px;
    flex: 1;
}

.menu-title {
    color: #6b7280;
    font-size: 11px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    padding: 0 14px;
    margin: 15px 0 10px;
}

.menu-item {
    display: flex;
    align-items: center;
    gap: 13px;
    color: #cbd5e1;
    text-decoration: none;
    padding: 14px 16px;
    border-radius: 12px;
    margin-bottom: 7px;
    transition:
        background-color 180ms ease,
        color 180ms ease;
}

@media (hover: hover) and (pointer: fine) {

    .menu-item:hover {
        background: rgba(255,255,255,0.08);
        color: white;
    }
}

.menu-item:focus-visible,
.menu-item:active {
    background: rgba(255,255,255,0.08);
    color: white;
}

.menu-item.active {
    background: #f59e0b;
    color: #111827;
    font-weight: bold;
}

.menu-icon {
    width: 28px;
    text-align: center;
}

.sidebar-footer {
    padding: 20px;
    border-top: 1px solid rgba(255,255,255,0.08);
}

.logout-button {
    display: block;
    width: 100%;
    padding: 13px;
    border-radius: 10px;
    text-align: center;
    text-decoration: none;
    color: #fca5a5;
    border: 1px solid rgba(255,255,255,0.1);
    transition: transform 140ms var(--ease-out);
}

.logout-button:active {
    transform: scale(0.97);
}


/* MAIN */

.main {
    margin-left: 270px;
    min-height: 100vh;
    min-height: 100svh;
}


/* TOPBAR */

.topbar {
    height: 90px;
    height: calc(90px + env(safe-area-inset-top, 0px));
    background: white;
    border-bottom: 1px solid #e5e7eb;
    padding: 0 35px;
    padding-top: env(safe-area-inset-top, 0px);
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 900;
}

.topbar-left h1 {
    font-size: 22px;
    margin-bottom: 4px;
}

.topbar-left p {
    font-size: 13px;
    color: #6b7280;
}

.admin-profile {
    display: flex;
    align-items: center;
    gap: 10px;
}

.admin-avatar {
    width: 43px;
    height: 43px;
    border-radius: 50%;
    object-fit: cover;
    background: #111827;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
}

.admin-info strong {
    display: block;
    font-size: 13px;
}

.admin-info span {
    font-size: 11px;
    color: #9ca3af;
}


/* CONTENT */

.content {
    padding: 35px;
}


/* HEADER */

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 28px;
}

.page-header h2 {
    font-size: 24px;
    margin-bottom: 6px;
}

.page-header p {
    color: #6b7280;
    font-size: 13px;
}


/* STATS */

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 18px;
    margin-bottom: 28px;
}

.stat-card {
    background: white;
    border-radius: 18px;
    padding: 22px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 8px 22px rgba(0,0,0,0.04);
}

.stat-card span {
    color: #6b7280;
    font-size: 12px;
}

.stat-card h3 {
    font-size: 28px;
    margin-top: 8px;
}


/* FILTERS */

.filters {
    display: flex;
    flex-wrap: wrap;
    gap: 9px;
    margin-bottom: 20px;
}

.filter-btn {
    text-decoration: none;
    color: #374151;
    background: white;
    padding: 10px 15px;
    border-radius: 9px;
    border: 1px solid #e5e7eb;
    font-size: 13px;
    transition: transform 140ms var(--ease-out);
}

.filter-btn:active {
    transform: scale(0.97);
}

.filter-btn.active {
    background: #111827;
    color: white;
}


/* TABLE CARD */

.table-card {
    background: white;
    border-radius: 20px;
    border: 1px solid #e5e7eb;
    overflow: hidden;
    box-shadow: 0 8px 25px rgba(0,0,0,0.04);
}

.table-header {
    padding: 22px;
    border-bottom: 1px solid #e5e7eb;
}

.table-header h3 {
    font-size: 18px;
}

.table-wrapper {
    overflow-x: auto;
    overscroll-behavior-x: contain;
}

table {
    width: 100%;
    min-width: 1250px;
    border-collapse: collapse;
}

thead {
    background: #f9fafb;
}

th {
    padding: 14px 18px;
    text-align: left;
    font-size: 11px;
    color: #6b7280;
    text-transform: uppercase;
}

td {
    padding: 17px 18px;
    border-top: 1px solid #f0f1f3;
    font-size: 13px;
    vertical-align: middle;
}


/* CUSTOMER */

.customer-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.customer-image {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    object-fit: cover;
    background: #e5e7eb;
}

.customer-placeholder {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: center;
}

.customer-name {
    font-weight: bold;
    margin-bottom: 3px;
}

.customer-email {
    font-size: 11px;
    color: #9ca3af;
}


/* STATUS */

.status {
    display: inline-block;
    padding: 6px 11px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: bold;
}

.pending {
    background: #fef3c7;
    color: #92400e;
}

.confirmed {
    background: #d1fae5;
    color: #065f46;
}

.declined {
    background: #fee2e2;
    color: #991b1b;
}

.cancelled {
    background: #e5e7eb;
    color: #374151;
}

.completed {
    background: #dbeafe;
    color: #1e40af;
}


/* PAYMENT */

.payment {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 20px;
    background: #f3f4f6;
    font-size: 11px;
}

.payment.verified {
    background: #d1fae5;
    color: #065f46;
}

.payment.pending {
    background: #fef3c7;
    color: #92400e;
}

.payment.rejected {
    background: #fee2e2;
    color: #991b1b;
}


/* BUTTONS */

.actions {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
}

.btn {
    border: none;
    padding: 8px 11px;
    border-radius: 7px;
    font-size: 11px;
    cursor: pointer;
    color: white;
    transition: transform 140ms var(--ease-out);
}

.btn:active:not(:disabled) {
    transform: scale(0.97);
}

.btn-confirm {
    background: #16a34a;
}

.btn-decline {
    background: #dc2626;
}

.btn-complete {
    background: #2563eb;
}


/* EMPTY */

.empty {
    padding: 45px;
    text-align: center;
    color: #6b7280;
}


/* MOBILE */

.mobile-toggle {
    display: none;
    background: #111827;
    color: white;
    border: none;
    border-radius: 8px;
    padding: 9px 12px;
    font-size: 18px;
    transition: transform 140ms var(--ease-out);
}

.mobile-toggle:active {
    transform: scale(0.97);
}

@media (max-width: 1100px) {

    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 850px) {

    .sidebar {
        transform: translateX(-100%);
        transition: transform 280ms var(--ease-drawer);
    }

    .sidebar.show {
        transform: translateX(0);
    }

    .main {
        margin-left: 0;
    }

    .mobile-toggle {
        display: block;
    }

    .topbar {
        padding: 0 20px;
        padding-top: env(safe-area-inset-top, 0px);
        padding-left: max(20px, env(safe-area-inset-left, 0px));
        padding-right: max(20px, env(safe-area-inset-right, 0px));
    }

    .content {
        padding: 20px;
        padding-left: max(20px, env(safe-area-inset-left, 0px));
        padding-right: max(20px, env(safe-area-inset-right, 0px));
        padding-bottom: max(20px, env(safe-area-inset-bottom, 0px));
    }

    .admin-info {
        display: none;
    }
}

@media (max-width: 600px) {

    .stats-grid {
        grid-template-columns: 1fr;
    }

    .page-header {
        align-items: flex-start;
        flex-direction: column;
        gap: 10px;
    }
}


/* REDUCED MOTION (fewer and gentler, not zero) */

@media (prefers-reduced-motion: reduce) {

    html {
        scroll-behavior: auto;
    }

    .btn:active,
    .filter-btn:active,
    .logout-button:active,
    .mobile-toggle:active {
        transform: none !important;
    }

    *,
    *::before,
    *::after {
        animation-duration: 1ms !important;
        animation-iteration-count: 1 !important;
    }
}

@media (prefers-reduced-motion: reduce) and (max-width: 850px) {

    /* drawer fades in place instead of sliding */
    .sidebar {
        opacity: 0;
        transition:
            opacity 200ms ease,
            transform 0s linear 200ms;
    }

    .sidebar.show {
        opacity: 1;
        transition:
            opacity 200ms ease,
            transform 0s;
    }
}

</style>

<?php $glassTheme = "admin"; require __DIR__ . "/../includes/glass.php"; ?>
</head>

<body>


<!-- SIDEBAR -->

<aside
    class="sidebar"
    id="sidebar"
>

    <div class="sidebar-logo">

        <div class="logo-icon">
            <?= icon("home") ?>
        </div>

        <div class="logo-text">
            <h2>ARVE'S House</h2>
            <span>Administration</span>
        </div>

    </div>


    <div class="sidebar-menu">

        <div class="menu-title">
            Main
        </div>

        <a href="dashboard.php" class="menu-item">
            <span class="menu-icon"><?= icon("chart") ?></span>
            Dashboard
        </a>

        <a href="reservations.php" class="menu-item active">
            <span class="menu-icon"><?= icon("calendar") ?></span>
            Reservations
        </a>

        <a href="calendar.php" class="menu-item">
            <span class="menu-icon"><?= icon("calendar") ?></span>
            Calendar
        </a>

        <a href="rooms.php" class="menu-item">
            <span class="menu-icon"><?= icon("bed") ?></span>
            Rooms
        </a>

        <a href="payment.php" class="menu-item">
            <span class="menu-icon"><?= icon("credit-card") ?></span>
            Payments
        </a>


        <div class="menu-title">
            Management
        </div>

        <a href="customers.php" class="menu-item">
            <span class="menu-icon"><?= icon("users") ?></span>
            Customers
        </a>

        <a href="messages.php" class="menu-item">
            <span class="menu-icon"><?= icon("mail") ?></span>
            Messages
            <?php if ($chatUnread > 0): ?>
                <span style="margin-left:auto;min-width:20px;height:20px;padding:0 6px;border-radius:999px;background:#dc2626;color:#fff;font-size:11px;font-weight:800;line-height:20px;text-align:center"><?= $chatUnread > 9 ? "9+" : $chatUnread ?></span>
            <?php endif; ?>
        </a>


        <a
            href="../index.php"
            target="_blank"
            class="menu-item"
        >
            <span class="menu-icon"><?= icon("globe") ?></span>
            View Website
        </a>

    </div>


    <div class="sidebar-footer">

        <a
            href="../logout.php"
            class="logout-button"
        >
            <?= icon("log-out") ?> Logout
        </a>

    </div>

</aside>


<!-- MAIN -->

<main class="main">


<header class="topbar">

    <div
        style="
            display:flex;
            align-items:center;
            gap:15px;
        "
    >

        <button
            class="mobile-toggle"
            onclick="toggleSidebar()"
            aria-label="Toggle menu"
            aria-controls="sidebar"
        >
            <?= icon("menu") ?>
        </button>

        <div class="topbar-left">

            <h1>
                Reservations
            </h1>

            <p>
                Manage all customer bookings
            </p>

        </div>

    </div>


    <div class="admin-profile">

        <?php if (!empty($admin["profile_image"])): ?>

            <img
                src="../<?= htmlspecialchars(
                    $admin["profile_image"]
                ) ?>"
                class="admin-avatar"
                alt="Admin"
            >

        <?php else: ?>

            <div class="admin-avatar">
                A
            </div>

        <?php endif; ?>

        <div class="admin-info">

            <strong>
                <?= htmlspecialchars(
                    $admin["full_name"]
                    ?? "Administrator"
                ) ?>
            </strong>

            <span>
                Administrator
            </span>

        </div>

    </div>

</header>


<div class="content">


    <div class="page-header">

        <div>

            <h2>
                Reservation Management
            </h2>

            <p>
                Confirm, decline and complete reservations.
            </p>

        </div>

    </div>


    <!-- STATS -->

    <div class="stats-grid">

        <div class="stat-card">

            <span>
                Total Reservations
            </span>

            <h3>
                <?= $totalReservations ?>
            </h3>

        </div>


        <div class="stat-card">

            <span>
                Pending
            </span>

            <h3>
                <?= $totalPending ?>
            </h3>

        </div>


        <div class="stat-card">

            <span>
                Confirmed
            </span>

            <h3>
                <?= $totalConfirmed ?>
            </h3>

        </div>


        <div class="stat-card">

            <span>
                Completed
            </span>

            <h3>
                <?= $totalCompleted ?>
            </h3>

        </div>

    </div>


    <!-- FILTERS -->

    <div class="filters">

        <?php foreach ($allowedFilters as $filter): ?>

            <a
                href="?status=<?= urlencode($filter) ?>"
                class="filter-btn
                <?= $statusFilter === $filter
                    ? "active"
                    : ""
                ?>"
            >
                <?= ucfirst($filter) ?>
            </a>

        <?php endforeach; ?>

    </div>


    <!-- TABLE -->

    <div class="table-card">

        <div class="table-header">

            <h3>
                Reservations
            </h3>

        </div>


        <?php if (count($reservations) > 0): ?>

            <div class="table-wrapper">

                <table>

                    <thead>

                        <tr>

                            <th>ID</th>

                            <th>Customer</th>

                            <th>Room</th>

                            <th>Check-in</th>

                            <th>Check-out</th>

                            <th>Guests</th>

                            <th>Total</th>

                            <th>Reservation</th>

                            <th>Payment</th>

                            <th>Action</th>

                        </tr>

                    </thead>


                    <tbody>

                    <?php foreach ($reservations as $reservation): ?>

                        <?php
                        $status = $reservation["status"];
                        $paymentStatus =
                            $reservation["payment_status"]
                            ?? null;
                        ?>

                        <tr>

                            <td>
                                #<?= (int) $reservation["id"] ?>
                            </td>


                            <td>

                                <div class="customer-info">

                                    <?php if (
                                        !empty(
                                            $reservation["profile_image"]
                                        )
                                    ): ?>

                                        <img
                                            src="../<?= htmlspecialchars(
                                                $reservation[
                                                    "profile_image"
                                                ]
                                            ) ?>"
                                            class="customer-image"
                                            alt="Profile"
                                        >

                                    <?php else: ?>

                                        <div class="customer-placeholder">
                                            <?= icon("user") ?>
                                        </div>

                                    <?php endif; ?>


                                    <div>

                                        <div class="customer-name">

                                            <?= htmlspecialchars(
                                                $reservation[
                                                    "customer_name"
                                                ]
                                                ?? "Customer"
                                            ) ?>

                                        </div>

                                        <div class="customer-email">

                                            <?= htmlspecialchars(
                                                $reservation[
                                                    "customer_email"
                                                ]
                                                ?? ""
                                            ) ?>

                                        </div>

                                    </div>

                                </div>

                            </td>


                            <td>

                                <?= htmlspecialchars(
                                    $reservation["room_name"]
                                ) ?>

                            </td>


                            <td>

                                <?= htmlspecialchars(
                                    $reservation["check_in"]
                                ) ?>

                            </td>


                            <td>

                                <?= htmlspecialchars(
                                    $reservation["check_out"]
                                ) ?>

                            </td>


                            <td>

                                <?= (int) $reservation["guests"] ?>

                            </td>


                            <td>

                                <strong>

                                    ₱<?= number_format(
                                        (float)
                                        $reservation[
                                            "total_amount"
                                        ],
                                        2
                                    ) ?>

                                </strong>

                            </td>


                            <td>

                                <span
                                    class="status <?= htmlspecialchars(
                                        $status
                                    ) ?>"
                                >

                                    <?= ucfirst(
                                        htmlspecialchars(
                                            $status
                                        )
                                    ) ?>

                                </span>

                            </td>


                            <td>

                                <?php if ($paymentStatus): ?>

                                    <span
                                        class="payment <?= htmlspecialchars(
                                            $paymentStatus
                                        ) ?>"
                                    >

                                        <?= ucfirst(
                                            htmlspecialchars(
                                                $paymentStatus
                                            )
                                        ) ?>

                                    </span>

                                <?php else: ?>

                                    <span class="payment">
                                        Not Paid
                                    </span>

                                <?php endif; ?>

                            </td>


                            <td>

                                <div class="actions">


                                <?php if ($status === "pending"): ?>

                                    <?php if ($paymentStatus === "verified"): ?>

                                        <span class="payment verified">
                                            Auto Confirmed
                                        </span>

                                    <?php elseif ($paymentStatus === "pending"): ?>

                                        <span class="payment pending">
                                            Waiting for Payment Verification
                                        </span>

                                    <?php elseif ($paymentStatus === "rejected"): ?>

                                        <span class="payment rejected">
                                            Payment Rejected
                                        </span>

                                        <form
                                            method="POST"
                                            onsubmit="
                                                return confirm(
                                                    'Decline this reservation?'
                                                );
                                            "
                                        >

                                        <input
                                            type="hidden"
                                            name="reservation_id"
                                            value="<?= (int)
                                                $reservation["id"]
                                            ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="decline"
                                        >

                                            <button
                                                class="btn btn-decline"
                                                type="submit"
                                            >
                                                Decline
                                            </button>

                                        </form>

                                    <?php else: ?>

                                        <span class="payment">
                                            Waiting for Payment
                                        </span>

                                        <form
                                            method="POST"
                                            onsubmit="
                                                return confirm(
                                                    'Decline this reservation?'
                                                );
                                            "
                                        >

                                            <input
                                                type="hidden"
                                                name="reservation_id"
                                                value="<?= (int)
                                                    $reservation["id"]
                                                ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="decline"
                                            >

                                            <button
                                                type="submit"
                                                class="btn btn-decline"
                                            >
                                                Decline
                                            </button>

                                        </form>

                                    <?php endif; ?>


                                <?php elseif (
                                    $status === "confirmed"
                                ): ?>


                                    <form
                                        method="POST"
                                        onsubmit="
                                            return confirm(
                                                'Mark this reservation completed?'
                                            );
                                        "
                                    >

                                        <input
                                            type="hidden"
                                            name="reservation_id"
                                            value="<?= (int)
                                                $reservation["id"]
                                            ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="complete"
                                        >

                                        <button
                                            class="btn btn-complete"
                                            type="submit"
                                        >
                                            Complete
                                        </button>

                                    </form>


                                <?php else: ?>

                                    —

                                <?php endif; ?>


                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>


        <?php else: ?>

            <div class="empty">

                <h3>
                    No reservations found
                </h3>

                <p>
                    Reservations matching this filter will appear here.
                </p>

            </div>

        <?php endif; ?>

    </div>


</div>

</main>


<script>

function toggleSidebar() {

    document
        .getElementById("sidebar")
        .classList
        .toggle("show");

}

</script>

</body>
</html>