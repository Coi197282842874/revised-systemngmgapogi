<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/icons.php";
require_once __DIR__ . "/../includes/chat.php";
require_once __DIR__ . "/../includes/paymongo.php";

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

// Online (PayMongo) payments confirm themselves; bring any unfinished ones up to date
paymongo_settle_pending($pdo);


// ======================================================
// HANDLE PAYMENT ACTIONS
// ======================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $payment_id =
        (int) ($_POST["payment_id"] ?? 0);

    $action =
        $_POST["action"] ?? "";

    if ($payment_id > 0) {

        try {

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT
                    id,
                    reservation_id,
                    user_id,
                    status
                FROM payments
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->execute([
                $payment_id
            ]);

            $payment =
                $stmt->fetch();

            if (
                $payment
                && $payment["status"] === "pending"
            ) {

                if ($action === "verify") {

                    $stmt = $pdo->prepare("
                        UPDATE payments
                        SET status = 'verified'
                        WHERE id = ?
                        AND status = 'pending'
                    ");

                    $stmt->execute([
                        $payment_id
                    ]);

                    $stmt = $pdo->prepare("
                        UPDATE reservations
                        SET status = 'confirmed'
                        WHERE id = ?
                        AND status = 'pending'
                    ");

                    $stmt->execute([
                        $payment["reservation_id"]
                    ]);

                } elseif ($action === "reject") {

                    $stmt = $pdo->prepare("
                        UPDATE payments
                        SET status = 'rejected'
                        WHERE id = ?
                        AND status = 'pending'
                    ");

                    $stmt->execute([
                        $payment_id
                    ]);
                }
            }

            $pdo->commit();

        } catch (PDOException $exception) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    header("Location: payment.php");
    exit;
}


// ======================================================
// FILTER
// ======================================================

$statusFilter = $_GET["status"] ?? "all";

$allowedFilters = [
    "all",
    "pending",
    "verified",
    "rejected"
];

if (!in_array($statusFilter, $allowedFilters, true)) {
    $statusFilter = "all";
}


// ======================================================
// COUNTS
// ======================================================

$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM payments
");
$totalPayments = (int) $stmt->fetchColumn();


$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM payments
    WHERE status = 'pending'
");
$pendingPayments = (int) $stmt->fetchColumn();


$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM payments
    WHERE status = 'verified'
");
$verifiedPayments = (int) $stmt->fetchColumn();


$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM payments
    WHERE status = 'rejected'
");
$rejectedPayments = (int) $stmt->fetchColumn();


$stmt = $pdo->query("
    SELECT COALESCE(SUM(amount), 0)
    FROM payments
    WHERE status = 'verified'
");
$totalVerifiedAmount = (float) $stmt->fetchColumn();


// ======================================================
// GET PAYMENTS
// ======================================================

$sql = "
    SELECT
        payments.*,

        reservations.id AS reservation_number,
        reservations.check_in,
        reservations.check_out,

        rooms.room_name,

        users.full_name AS customer_name,
        users.email AS customer_email,
        users.profile_image

    FROM payments

    INNER JOIN reservations
        ON payments.reservation_id = reservations.id

    INNER JOIN rooms
        ON reservations.room_id = rooms.id

    INNER JOIN users
        ON payments.user_id = users.id
";

$params = [];

if ($statusFilter !== "all") {

    $sql .= "
        WHERE payments.status = ?
    ";

    $params[] = $statusFilter;
}

$sql .= "
    ORDER BY payments.created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$payments = $stmt->fetchAll();


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
    Payments | ARVE'S House
</title>

<style>

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


/* SIDEBAR */

.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: 270px;
    height: 100vh;
    height: 100dvh;
    background: linear-gradient(
        180deg,
        #111827,
        #172033
    );
    color: white;
    display: flex;
    flex-direction: column;
    z-index: 1000;
    overflow-y: auto;
    overscroll-behavior: contain;
    padding-top: env(safe-area-inset-top, 0px);
    padding-bottom: env(safe-area-inset-bottom, 0px);
    padding-left: env(safe-area-inset-left, 0px);
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
    touch-action: manipulation;
    transition: transform 140ms var(--ease-out);
}

@media (hover: hover) and (pointer: fine) {

    .menu-item:hover {
        background: rgba(255,255,255,0.08);
        color: white;
    }
}

.menu-item:focus-visible {
    background: rgba(255,255,255,0.08);
    color: white;
}

.menu-item:active {
    transform: scale(0.98);
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
    touch-action: manipulation;
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


/* PAGE HEADER */

.page-header {
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
    grid-template-columns: repeat(5, 1fr);
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
    margin-top: 8px;
    font-size: 27px;
}

.revenue-card {
    background: linear-gradient(
        135deg,
        #111827,
        #1f2937
    );
    color: white;
}

.revenue-card span {
    color: #d1d5db;
}


/* FILTERS */

.filters {
    display: flex;
    gap: 9px;
    flex-wrap: wrap;
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
    touch-action: manipulation;
    transition: transform 140ms var(--ease-out);
}

.filter-btn:active {
    transform: scale(0.97);
}

.filter-btn.active {
    background: #111827;
    color: white;
}


/* TABLE */

.table-card {
    background: white;
    border-radius: 20px;
    overflow: hidden;
    border: 1px solid #e5e7eb;
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
    min-width: 1350px;
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

@media (hover: hover) and (pointer: fine) {

    tbody tr:hover {
        background: #fafafa;
    }
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


/* PAYMENT METHOD */

.method {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 8px;
    background: #f3f4f6;
    font-size: 11px;
    font-weight: 600;
}


/* STATUS */

.status {
    display: inline-block;
    padding: 6px 11px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: bold;
}

.status.pending {
    background: #fef3c7;
    color: #92400e;
}

.status.verified {
    background: #d1fae5;
    color: #065f46;
}

.status.rejected {
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
    color: white;
    cursor: pointer;
    touch-action: manipulation;
    -webkit-user-select: none;
    user-select: none;
    transition: transform 140ms var(--ease-out);
}

.btn:active:not(:disabled) {
    transform: scale(0.97);
}

.btn-verify {
    background: #16a34a;
}

.btn-reject {
    background: #dc2626;
}


/* AMOUNT */

.amount {
    font-weight: bold;
    font-size: 14px;
}


/* REFERENCE */

.reference {
    font-size: 12px;
    color: #374151;
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
    touch-action: manipulation;
    -webkit-user-select: none;
    user-select: none;
    transition: transform 140ms var(--ease-out);
}

.mobile-toggle:active {
    transform: scale(0.97);
}

@media (max-width: 1250px) {

    .stats-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 900px) {

    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .sidebar {
        transform: translateX(-100%);
        transition: transform 300ms var(--ease-drawer);
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

    .content {
        padding: 15px;
        padding-left: max(15px, env(safe-area-inset-left, 0px));
        padding-right: max(15px, env(safe-area-inset-right, 0px));
        padding-bottom: max(15px, env(safe-area-inset-bottom, 0px));
    }
}


/* REDUCED MOTION */

@media (prefers-reduced-motion: reduce) {

    html {
        scroll-behavior: auto;
    }

    .menu-item:active,
    .logout-button:active,
    .filter-btn:active,
    .btn:active,
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

@media (prefers-reduced-motion: reduce) and (max-width: 900px) {

    .sidebar {
        opacity: 0;
        transition: opacity 200ms ease;
    }

    .sidebar.show {
        opacity: 1;
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

            <h2>
                ARVE'S House
            </h2>

            <span>
                Administration
            </span>

        </div>

    </div>


    <div class="sidebar-menu">

        <div class="menu-title">
            Main
        </div>

        <a
            href="dashboard.php"
            class="menu-item"
        >
            <span class="menu-icon"><?= icon("chart") ?></span>
            Dashboard
        </a>

        <a
            href="reservations.php"
            class="menu-item"
        >
            <span class="menu-icon"><?= icon("calendar") ?></span>
            Reservations
        </a>

        <a href="calendar.php" class="menu-item">
            <span class="menu-icon"><?= icon("calendar") ?></span>
            Calendar
        </a>

        <a
            href="rooms.php"
            class="menu-item"
        >
            <span class="menu-icon"><?= icon("bed") ?></span>
            Rooms
        </a>

        <a
            href="payment.php"
            class="menu-item active"
        >
            <span class="menu-icon"><?= icon("credit-card") ?></span>
            Payments
        </a>


        <div class="menu-title">
            Management
        </div>

        <a
            href="customers.php"
            class="menu-item"
        >
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
        >
            <?= icon("menu") ?>
        </button>

        <div class="topbar-left">

            <h1>
                Payments
            </h1>

            <p>
                Review and verify customer payments
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

        <h2>
            Payment Management
        </h2>

        <p>
            Verify, reject and monitor customer payment transactions.
        </p>

    </div>


    <!-- STATS -->

    <div class="stats-grid">


        <div class="stat-card">

            <span>
                Total Payments
            </span>

            <h3>
                <?= number_format($totalPayments) ?>
            </h3>

        </div>


        <div class="stat-card">

            <span>
                Pending
            </span>

            <h3>
                <?= number_format($pendingPayments) ?>
            </h3>

        </div>


        <div class="stat-card">

            <span>
                Verified
            </span>

            <h3>
                <?= number_format($verifiedPayments) ?>
            </h3>

        </div>


        <div class="stat-card">

            <span>
                Rejected
            </span>

            <h3>
                <?= number_format($rejectedPayments) ?>
            </h3>

        </div>


        <div class="stat-card revenue-card">

            <span>
                Verified Revenue
            </span>

            <h3>
                ₱<?= number_format(
                    $totalVerifiedAmount,
                    2
                ) ?>
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


    <!-- PAYMENT TABLE -->

    <div class="table-card">


        <div class="table-header">

            <h3>
                Payment Transactions
            </h3>

        </div>


        <?php if (count($payments) > 0): ?>


            <div class="table-wrapper">


                <table>


                    <thead>

                        <tr>

                            <th>ID</th>

                            <th>Customer</th>

                            <th>Reservation</th>

                            <th>Room</th>

                            <th>Method</th>

                            <th>Amount</th>

                            <th>Reference</th>

                            <th>Status</th>

                            <th>Date</th>

                            <th>Action</th>

                        </tr>

                    </thead>


                    <tbody>


                        <?php foreach ($payments as $payment): ?>


                            <tr>


                                <td>
                                    #<?= (int) $payment["id"] ?>
                                </td>


                                <td>

                                    <div class="customer-info">


                                        <?php if (
                                            !empty(
                                                $payment["profile_image"]
                                            )
                                        ): ?>

                                            <img
                                                src="../<?= htmlspecialchars(
                                                    $payment[
                                                        "profile_image"
                                                    ]
                                                ) ?>"
                                                class="customer-image"
                                                alt="Customer"
                                            >

                                        <?php else: ?>

                                            <div
                                                class="customer-placeholder"
                                            >
                                                <?= icon("user") ?>
                                            </div>

                                        <?php endif; ?>


                                        <div>

                                            <div class="customer-name">

                                                <?= htmlspecialchars(
                                                    $payment[
                                                        "customer_name"
                                                    ]
                                                    ?? "Customer"
                                                ) ?>

                                            </div>

                                            <div class="customer-email">

                                                <?= htmlspecialchars(
                                                    $payment[
                                                        "customer_email"
                                                    ]
                                                    ?? ""
                                                ) ?>

                                            </div>

                                        </div>

                                    </div>

                                </td>


                                <td>

                                    #<?= (int)
                                        $payment[
                                            "reservation_number"
                                        ]
                                    ?>

                                </td>


                                <td>

                                    <?= htmlspecialchars(
                                        $payment["room_name"]
                                    ) ?>

                                </td>


                                <td>

                                    <span class="method">

                                        <?= strtoupper(
                                            str_replace(
                                                "_",
                                                " ",
                                                htmlspecialchars(
                                                    $payment[
                                                        "payment_method"
                                                    ]
                                                )
                                            )
                                        ) ?>

                                    </span>

                                </td>


                                <td class="amount">

                                    ₱<?= number_format(
                                        (float)
                                        $payment["amount"],
                                        2
                                    ) ?>

                                </td>


                                <td class="reference">

                                    <?= !empty(
                                        $payment["reference_number"]
                                    )
                                        ? htmlspecialchars(
                                            $payment[
                                                "reference_number"
                                            ]
                                        )
                                        : "—"
                                    ?>

                                </td>


                                <td>

                                    <span
                                        class="status <?= htmlspecialchars(
                                            $payment["status"]
                                        ) ?>"
                                    >

                                        <?= ucfirst(
                                            htmlspecialchars(
                                                $payment["status"]
                                            )
                                        ) ?>

                                    </span>

                                </td>


                                <td>

                                    <?= htmlspecialchars(
                                        $payment["created_at"]
                                    ) ?>

                                </td>


                                <td>


                                    <?php if (
                                        $payment["status"]
                                        === "pending"
                                    ): ?>


                                        <div class="actions">


                                            <form method="POST">

                                                <input
                                                    type="hidden"
                                                    name="payment_id"
                                                    value="<?= (int)
                                                        $payment["id"]
                                                    ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="verify"
                                                >

                                                <button
                                                    type="submit"
                                                    class="btn btn-verify"
                                                >
                                                    Verify
                                                </button>

                                            </form>


                                            <form
                                                method="POST"
                                                onsubmit="
                                                    return confirm(
                                                        'Reject this payment?'
                                                    );
                                                "
                                            >

                                                <input
                                                    type="hidden"
                                                    name="payment_id"
                                                    value="<?= (int)
                                                        $payment["id"]
                                                    ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="reject"
                                                >

                                                <button
                                                    type="submit"
                                                    class="btn btn-reject"
                                                >
                                                    Reject
                                                </button>

                                            </form>


                                        </div>


                                    <?php else: ?>


                                        —


                                    <?php endif; ?>


                                </td>


                            </tr>


                        <?php endforeach; ?>


                    </tbody>


                </table>


            </div>


        <?php else: ?>


            <div class="empty">

                <h3>
                    No payments found
                </h3>

                <p>
                    Customer payments will appear here.
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