<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/icons.php";
require_once __DIR__ . "/../includes/chat.php";

ensure_chat_schema($pdo);
$chatUnread = chat_unread_for_admins($pdo);


// ======================================================
// ADMIN LOGIN CHECK
// ======================================================

if (
    !isset($_SESSION["user_id"]) ||
    ($_SESSION["role"] ?? "") !== "admin"
) {
    header("Location: ../login.php");
    exit;
}


// ======================================================
// DASHBOARD COUNTS
// ======================================================

// Total customers
$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM users
    WHERE role = 'customer'
");
$totalCustomers = (int) $stmt->fetchColumn();


// Total rooms
$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM rooms
");
$totalRooms = (int) $stmt->fetchColumn();


// Total reservations
$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM reservations
");
$totalReservations = (int) $stmt->fetchColumn();


// Pending reservations
$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM reservations
    WHERE status = 'pending'
");
$pendingReservations = (int) $stmt->fetchColumn();


// Confirmed reservations
$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM reservations
    WHERE status = 'confirmed'
");
$confirmedReservations = (int) $stmt->fetchColumn();


// Verified payments
$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM payments
    WHERE status = 'verified'
");
$verifiedPayments = (int) $stmt->fetchColumn();


// Total verified payment amount
$stmt = $pdo->query("
    SELECT COALESCE(SUM(amount), 0)
    FROM payments
    WHERE status = 'verified'
");
$totalRevenue = (float) $stmt->fetchColumn();


// ======================================================
// RECENT RESERVATIONS
// ======================================================

$stmt = $pdo->query("
    SELECT
        reservations.id,
        reservations.check_in,
        reservations.check_out,
        reservations.guests,
        reservations.total_amount,
        reservations.status,
        reservations.created_at,

        rooms.room_name,

        users.full_name AS customer_name,
        users.email AS customer_email,
        users.profile_image

    FROM reservations

    INNER JOIN rooms
        ON reservations.room_id = rooms.id

    INNER JOIN users
        ON reservations.user_id = users.id

    ORDER BY reservations.created_at DESC

    LIMIT 8
");

$recentReservations = $stmt->fetchAll();


// ======================================================
// ADMIN INFORMATION
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
        Admin Dashboard | ARVE'S House
    </title>


    <style>

        /* ==================================================
           MOTION TOKENS
        ================================================== */

        :root {

            --ease-out: cubic-bezier(0.23, 1, 0.32, 1);

            --ease-in-out: cubic-bezier(0.77, 0, 0.175, 1);

            --ease-drawer: cubic-bezier(0.32, 0.72, 0, 1);
        }


        /* ==================================================
           RESET
        ================================================== */

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;

            -webkit-tap-highlight-color: transparent;

            -webkit-text-size-adjust: 100%;
            text-size-adjust: 100%;
        }

        body {
            font-family:
                Inter,
                Arial,
                Helvetica,
                sans-serif;

            background: #f3f5f9;

            color: #111827;

            min-height: 100vh;
            min-height: 100svh;
        }


        /* Controls: no double-tap zoom delay, no long-press text selection */

        a,
        button {
            touch-action: manipulation;
        }

        button,
        .menu-item,
        .logout-button,
        .view-site,
        .quick-card {
            -webkit-user-select: none;
            user-select: none;
        }


        /* ==================================================
           SIDEBAR
        ================================================== */

        .sidebar {

            position: fixed;

            top: 0;
            left: 0;

            width: 270px;
            height: 100vh;
            height: 100dvh;

            /* keep logo/logout clear of the notch & home indicator */
            padding-top: env(safe-area-inset-top, 0px);
            padding-bottom: env(safe-area-inset-bottom, 0px);
            padding-left: env(safe-area-inset-left, 0px);

            background:
                linear-gradient(
                    180deg,
                    #111827,
                    #172033
                );

            color: white;

            display: flex;
            flex-direction: column;

            z-index: 1000;

            box-shadow:
                10px 0 30px
                rgba(0,0,0,0.08);
        }


        .sidebar-logo {

            height: 90px;

            display: flex;
            align-items: center;

            padding: 0 28px;

            border-bottom:
                1px solid
                rgba(255,255,255,0.08);
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

            margin-bottom: 2px;
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

            overflow-y: auto;

            overscroll-behavior: contain;
        }


        .menu-title {

            color: #6b7280;

            font-size: 11px;

            font-weight: bold;

            text-transform: uppercase;

            letter-spacing: 1.5px;

            padding: 0 14px;

            margin:
                15px 0
                10px;
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
                transform 140ms var(--ease-out),
                background-color 180ms ease,
                color 180ms ease;

            font-size: 14px;
        }


        .menu-item:active {

            transform:
                scale(0.98);
        }


        @media (hover: hover) and (pointer: fine) {

            .menu-item:hover {

                background:
                    rgba(255,255,255,0.08);

                color: white;

                transform:
                    translateX(3px);
            }


            .menu-item:hover:active {

                transform:
                    translateX(3px)
                    scale(0.98);
            }
        }


        .menu-item:focus-visible {

            background:
                rgba(255,255,255,0.08);

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

            font-size: 18px;
        }


        .sidebar-footer {

            padding: 20px;

            border-top:
                1px solid
                rgba(255,255,255,0.08);
        }


        .logout-button {

            width: 100%;

            padding: 13px;

            border-radius: 10px;

            border:
                1px solid
                rgba(255,255,255,0.1);

            color: #fca5a5;

            text-decoration: none;

            display: block;

            text-align: center;

            transition:
                transform 140ms var(--ease-out),
                background-color 180ms ease,
                color 180ms ease;
        }


        .logout-button:active {

            transform:
                scale(0.97);
        }


        @media (hover: hover) and (pointer: fine) {

            .logout-button:hover {

                background: #dc2626;

                color: white;
            }
        }


        .logout-button:focus-visible {

            background: #dc2626;

            color: white;
        }


        /* ==================================================
           MAIN AREA
        ================================================== */

        .main {

            margin-left: 270px;

            min-height: 100vh;
            min-height: 100svh;

            /* landscape notch (viewport-fit=cover) */
            padding-right: env(safe-area-inset-right, 0px);
        }


        /* ==================================================
           TOPBAR
        ================================================== */

        .topbar {

            height: 90px;
            height: calc(90px + env(safe-area-inset-top, 0px));

            padding: 0 35px;
            padding-top: env(safe-area-inset-top, 0px);

            background: white;

            display: flex;

            align-items: center;

            justify-content: space-between;

            border-bottom:
                1px solid #e5e7eb;

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


        .topbar-right {

            display: flex;

            align-items: center;

            gap: 18px;
        }


        .view-site {

            padding: 10px 16px;

            border-radius: 9px;

            background: #f3f4f6;

            color: #374151;

            text-decoration: none;

            font-size: 13px;

            font-weight: 600;

            transition:
                transform 140ms var(--ease-out),
                background-color 180ms ease;
        }


        .view-site:active {

            transform:
                scale(0.97);
        }


        @media (hover: hover) and (pointer: fine) {

            .view-site:hover {

                background: #e5e7eb;
            }
        }


        .view-site:focus-visible {

            background: #e5e7eb;
        }


        .admin-profile {

            display: flex;

            align-items: center;

            gap: 10px;

            padding-left: 18px;

            border-left:
                1px solid #e5e7eb;
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

            font-weight: bold;
        }


        .admin-info strong {

            display: block;

            font-size: 13px;
        }


        .admin-info span {

            font-size: 11px;

            color: #9ca3af;
        }


        /* ==================================================
           CONTENT
        ================================================== */

        .content {

            padding: 35px;
        }


        /* ==================================================
           WELCOME
        ================================================== */

        .welcome {

            position: relative;

            overflow: hidden;

            background:
                linear-gradient(
                    135deg,
                    #111827,
                    #1f2937
                );

            color: white;

            padding: 35px;

            border-radius: 22px;

            margin-bottom: 30px;
        }


        .welcome::after {

            content: "";

            position: absolute;

            width: 300px;
            height: 300px;

            border-radius: 50%;

            background:
                rgba(245,158,11,0.12);

            right: -100px;

            top: -120px;
        }


        .welcome h2 {

            font-size: 27px;

            margin-bottom: 9px;

            position: relative;

            z-index: 1;
        }


        .welcome p {

            color: #d1d5db;

            position: relative;

            z-index: 1;

            max-width: 650px;

            line-height: 1.6;
        }


        /* ==================================================
           STATS
        ================================================== */

        .stats-grid {

            display: grid;

            grid-template-columns:
                repeat(4, minmax(0, 1fr));

            gap: 20px;

            margin-bottom: 30px;
        }


        .stat-card {

            background: white;

            border-radius: 18px;

            padding: 23px;

            box-shadow:
                0 8px 25px
                rgba(17,24,39,0.05);

            border:
                1px solid #eef0f4;

            transition:
                transform 180ms var(--ease-out),
                box-shadow 180ms ease;
        }


        @media (hover: hover) and (pointer: fine) {

            .stat-card:hover {

                transform:
                    translateY(-4px);

                box-shadow:
                    0 15px 35px
                    rgba(17,24,39,0.09);
            }
        }


        .stat-top {

            display: flex;

            justify-content: space-between;

            align-items: center;

            margin-bottom: 17px;
        }


        .stat-icon {

            width: 48px;
            height: 48px;

            display: flex;

            align-items: center;

            justify-content: center;

            border-radius: 14px;

            font-size: 21px;

            background: #f3f4f6;
        }


        .stat-card h3 {

            font-size: 29px;

            margin-bottom: 5px;
        }


        .stat-card p {

            color: #6b7280;

            font-size: 13px;
        }


        .stat-note {

            font-size: 11px;

            color: #9ca3af;

            margin-top: 10px;
        }


        /* ==================================================
           QUICK ACTIONS
        ================================================== */

        .section-header {

            display: flex;

            justify-content: space-between;

            align-items: center;

            margin-bottom: 18px;
        }


        .section-header h2 {

            font-size: 19px;
        }


        .section-header a {

            color: #2563eb;

            text-decoration: none;

            font-size: 13px;

            font-weight: 600;
        }


        .quick-grid {

            display: grid;

            grid-template-columns:
                repeat(4, 1fr);

            gap: 18px;

            margin-bottom: 35px;
        }


        .quick-card {

            background: white;

            text-decoration: none;

            color: #111827;

            padding: 22px;

            border-radius: 17px;

            border:
                1px solid #e5e7eb;

            display: flex;

            align-items: center;

            gap: 15px;

            transition:
                transform 140ms var(--ease-out),
                border-color 180ms ease,
                box-shadow 180ms ease;
        }


        .quick-card:active {

            transform:
                scale(0.99);
        }


        @media (hover: hover) and (pointer: fine) {

            .quick-card:hover {

                transform:
                    translateY(-3px);

                border-color: #f59e0b;

                box-shadow:
                    0 12px 30px
                    rgba(0,0,0,0.06);
            }


            /* press settles the lifted card back down */
            .quick-card:hover:active {

                transform:
                    translateY(0)
                    scale(0.99);
            }
        }


        /* keyboard parity: same highlight, no lift */
        .quick-card:focus-visible {

            border-color: #f59e0b;

            box-shadow:
                0 12px 30px
                rgba(0,0,0,0.06);
        }


        .quick-icon {

            width: 50px;
            height: 50px;

            border-radius: 14px;

            background: #fff7ed;

            display: flex;

            justify-content: center;

            align-items: center;

            font-size: 22px;
        }


        .quick-card strong {

            display: block;

            margin-bottom: 5px;
        }


        .quick-card span {

            font-size: 12px;

            color: #6b7280;
        }


        /* ==================================================
           TABLE
        ================================================== */

        .table-card {

            background: white;

            border-radius: 20px;

            border:
                1px solid #e5e7eb;

            overflow: hidden;

            box-shadow:
                0 8px 25px
                rgba(17,24,39,0.04);
        }


        .table-header {

            padding: 22px 25px;

            display: flex;

            align-items: center;

            justify-content: space-between;

            border-bottom:
                1px solid #e5e7eb;
        }


        .table-header h2 {

            font-size: 18px;
        }


        .table-header a {

            text-decoration: none;

            color: #2563eb;

            font-size: 13px;

            font-weight: bold;
        }


        .table-wrapper {

            overflow-x: auto;

            overscroll-behavior-x: contain;
        }


        table {

            width: 100%;

            border-collapse: collapse;

            min-width: 950px;
        }


        thead {

            background: #f9fafb;
        }


        th {

            padding: 14px 20px;

            text-align: left;

            font-size: 11px;

            text-transform: uppercase;

            letter-spacing: 0.5px;

            color: #6b7280;
        }


        td {

            padding: 17px 20px;

            border-top:
                1px solid #f0f1f3;

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

            gap: 11px;
        }


        .customer-picture {

            width: 40px;
            height: 40px;

            border-radius: 50%;

            object-fit: cover;

            background: #e5e7eb;
        }


        .customer-placeholder {

            width: 40px;
            height: 40px;

            border-radius: 50%;

            background: #e5e7eb;

            display: flex;

            align-items: center;

            justify-content: center;
        }


        .customer-name {

            font-weight: 600;

            margin-bottom: 3px;
        }


        .customer-email {

            font-size: 11px;

            color: #9ca3af;
        }


        /* STATUS BADGES */

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


        .amount {

            font-weight: bold;
        }


        .empty {

            padding: 50px;

            text-align: center;

            color: #6b7280;
        }


        /* ==================================================
           MOBILE MENU BUTTON
        ================================================== */

        .mobile-toggle {

            display: none;

            border: none;

            background: #111827;

            color: white;

            border-radius: 8px;

            padding: 9px 12px;

            font-size: 18px;

            cursor: pointer;

            transition:
                transform 140ms var(--ease-out);
        }


        .mobile-toggle:active:not(:disabled) {

            transform:
                scale(0.97);
        }


        /* ==================================================
           RESPONSIVE
        ================================================== */

        @media (max-width: 1200px) {

            .stats-grid {

                grid-template-columns:
                    repeat(2, 1fr);
            }


            .quick-grid {

                grid-template-columns:
                    repeat(2, 1fr);
            }
        }


        @media (max-width: 850px) {

            .sidebar {

                transform:
                    translateX(-100%);

                transition:
                    transform 280ms var(--ease-drawer);
            }


            .sidebar.show {

                transform:
                    translateX(0);
            }


            .main {

                margin-left: 0;

                padding-left: env(safe-area-inset-left, 0px);
            }


            .mobile-toggle {

                display: block;
            }


            .topbar {

                padding: 0 20px;
                padding-top: env(safe-area-inset-top, 0px);
            }


            .content {

                padding: 20px;
            }


            .topbar-left p {

                display: none;
            }


            .view-site {

                display: none;
            }


            .admin-info {

                display: none;
            }
        }


        @media (max-width: 600px) {

            .stats-grid,
            .quick-grid {

                grid-template-columns: 1fr;
            }


            .welcome {

                padding: 25px;
            }


            .welcome h2 {

                font-size: 22px;
            }


            .content {

                padding: 15px;
            }


            .topbar {

                height: 75px;
                height: calc(75px + env(safe-area-inset-top, 0px));
            }
        }


        /* ==================================================
           REDUCED MOTION
        ================================================== */

        @media (prefers-reduced-motion: reduce) {

            html {
                scroll-behavior: auto;
            }


            /* no hover lifts/nudges or press scale */
            .menu-item,
            .logout-button,
            .view-site,
            .stat-card,
            .quick-card,
            .mobile-toggle {

                transform: none !important;
            }


            /* mobile drawer: fade in place instead of sliding */
            .sidebar {

                transition:
                    opacity 200ms ease;
            }


            *,
            *::before,
            *::after {
                animation-duration: 1ms !important;
                animation-iteration-count: 1 !important;
            }
        }


        @media (prefers-reduced-motion: reduce) and (max-width: 850px) {

            .sidebar:not(.show) {

                opacity: 0;
            }
        }

    </style>

<style>
/* Phone polish: two stat cards per row, smaller icons */
@media (max-width: 600px) {
    .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    .stat-card { padding: 15px; border-radius: 14px; }
    .stat-card:last-child:nth-child(odd) { grid-column: 1 / -1; }
    .stat-top { gap: 8px; margin-bottom: 10px; }
    .stat-icon { flex: none; width: 34px; height: 34px; border-radius: 10px; font-size: 16px; }
    .stat-card h3 { font-size: 22px; }
    .stat-card p { font-size: 12px; }
    .quick-card { padding: 14px 16px; gap: 12px; border-radius: 14px; }
    .quick-icon { flex: none; width: 40px; height: 40px; border-radius: 12px; font-size: 18px; }
}
</style>
<?php $glassTheme = "admin"; require __DIR__ . "/../includes/glass.php"; ?>
</head>


<body>


<!-- ======================================================
     SIDEBAR
====================================================== -->

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
            class="menu-item active"
        >

            <span class="menu-icon">
                <?= icon("chart") ?>
            </span>

            Dashboard

        </a>


        <a
            href="reservations.php"
            class="menu-item"
        >

            <span class="menu-icon">
                <?= icon("calendar") ?>
            </span>

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

            <span class="menu-icon">
                <?= icon("bed") ?>
            </span>

            Rooms

        </a>


        <a
            href="payment.php"
            class="menu-item"
        >

            <span class="menu-icon">
                <?= icon("credit-card") ?>
            </span>

            Payments

        </a>


        <div class="menu-title">
            Management
        </div>


        <a
            href="customers.php"
            class="menu-item"
        >

            <span class="menu-icon">
                <?= icon("users") ?>
            </span>

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
            href="site_settings.php"
            class="menu-item"
        >

            <span class="menu-icon">
                <?= icon("settings") ?>
            </span>

            Settings

        </a>


        <a
            href="account.php"
            class="menu-item"
        >

            <span class="menu-icon">
                <?= icon("lock") ?>
            </span>

            Change Password

        </a>


        <a
            href="admins.php"
            class="menu-item"
        >

            <span class="menu-icon">
                <?= icon("shield") ?>
            </span>

            Admins

        </a>


        <a
            href="../index.php"
            class="menu-item"
            target="_blank"
        >

            <span class="menu-icon">
                <?= icon("globe") ?>
            </span>

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



<!-- ======================================================
     MAIN
====================================================== -->

<main class="main">


    <!-- TOPBAR -->

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
                    Dashboard
                </h1>

                <p>
                    ARVE'S House Reservation Management System
                </p>

            </div>

        </div>


        <div class="topbar-right">


            <a
                href="../index.php"
                target="_blank"
                class="view-site"
            >
                View Website ↗
            </a>


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

        </div>

    </header>



    <!-- CONTENT -->

    <div class="content">


        <!-- WELCOME -->

        <section class="welcome">

            <h2>
                Welcome back,
                <?= htmlspecialchars(
                            $admin["full_name"]
                    ?? "Admin"
                ) ?>
            </h2>

            <p>
                Manage reservations, rooms, customer accounts
                and payments from one centralized dashboard.
            </p>

        </section>



        <!-- STATISTICS -->

        <section class="stats-grid">


            <div class="stat-card">

                <div class="stat-top">

                    <p>Customers</p>

                    <div class="stat-icon">
                        <?= icon("users") ?>
                    </div>

                </div>

                <h3>
                    <?= number_format(
                        $totalCustomers
                    ) ?>
                </h3>

                <p>
                    Registered customers
                </p>

            </div>



            <div class="stat-card">

                <div class="stat-top">

                    <p>Rooms</p>

                    <div class="stat-icon">
                        <?= icon("bed") ?>
                    </div>

                </div>

                <h3>
                    <?= number_format(
                        $totalRooms
                    ) ?>
                </h3>

                <p>
                    Total rooms
                </p>

            </div>



            <div class="stat-card">

                <div class="stat-top">

                    <p>Reservations</p>

                    <div class="stat-icon">
                        <?= icon("calendar") ?>
                    </div>

                </div>

                <h3>
                    <?= number_format(
                        $totalReservations
                    ) ?>
                </h3>

                <p>
                    Total reservations
                </p>

            </div>



            <div class="stat-card">

                <div class="stat-top">

                    <p>Pending</p>

                    <div class="stat-icon">
                        <?= icon("clock") ?>
                    </div>

                </div>

                <h3>
                    <?= number_format(
                        $pendingReservations
                    ) ?>
                </h3>

                <p>
                    Awaiting confirmation
                </p>

            </div>



            <div class="stat-card">

                <div class="stat-top">

                    <p>Confirmed</p>

                    <div class="stat-icon">
                        <?= icon("check-circle") ?>
                    </div>

                </div>

                <h3>
                    <?= number_format(
                        $confirmedReservations
                    ) ?>
                </h3>

                <p>
                    Confirmed bookings
                </p>

            </div>



            <div class="stat-card">

                <div class="stat-top">

                    <p>Verified Payments</p>

                    <div class="stat-icon">
                        <?= icon("credit-card") ?>
                    </div>

                </div>

                <h3>
                    <?= number_format(
                        $verifiedPayments
                    ) ?>
                </h3>

                <p>
                    Verified transactions
                </p>

            </div>



            <div class="stat-card">

                <div class="stat-top">

                    <p>Revenue</p>

                    <div class="stat-icon">
                        <?= icon("wallet") ?>
                    </div>

                </div>

                <h3>
                    ₱<?= number_format(
                        $totalRevenue,
                        2
                    ) ?>
                </h3>

                <p>
                    Verified payments
                </p>

            </div>


        </section>



        <!-- QUICK ACTIONS -->

        <div class="section-header">

            <h2>
                Quick Actions
            </h2>

        </div>


        <section class="quick-grid">


            <a
                href="reservations.php"
                class="quick-card"
            >

                <div class="quick-icon">
                    <?= icon("calendar") ?>
                </div>

                <div>

                    <strong>
                        Reservations
                    </strong>

                    <span>
                        Manage bookings
                    </span>

                </div>

            </a>



            <a
                href="rooms.php"
                class="quick-card"
            >

                <div class="quick-icon">
                    <?= icon("bed") ?>
                </div>

                <div>

                    <strong>
                        Manage Rooms
                    </strong>

                    <span>
                        Rooms & availability
                    </span>

                </div>

            </a>



            <a
                href="payment.php"
                class="quick-card"
            >

                <div class="quick-icon">
                    <?= icon("credit-card") ?>
                </div>

                <div>

                    <strong>
                        Payments
                    </strong>

                    <span>
                        Verify transactions
                    </span>

                </div>

            </a>



            <a
                href="customers.php"
                class="quick-card"
            >

                <div class="quick-icon">
                    <?= icon("users") ?>
                </div>

                <div>

                    <strong>
                        Customers
                    </strong>

                    <span>
                        Customer accounts
                    </span>

                </div>

            </a>


            <a
                href="site_settings.php"
                class="quick-card"
            >

                <div class="quick-icon">
                    <?= icon("settings") ?>
                </div>

                <div>

                    <strong>
                        Settings
                    </strong>

                    <span>
                        Edit homepage card
                    </span>

                </div>

            </a>


        </section>



        <!-- RECENT RESERVATIONS -->

        <section class="table-card">


            <div class="table-header">

                <h2>
                    Recent Reservations
                </h2>

                <a href="reservations.php">
                    View All →
                </a>

            </div>


            <?php if (
                count($recentReservations) > 0
            ): ?>


                <div class="table-wrapper">

                    <table>


                        <thead>

                            <tr>

                                <th>
                                    Customer
                                </th>

                                <th>
                                    Room
                                </th>

                                <th>
                                    Check-in
                                </th>

                                <th>
                                    Check-out
                                </th>

                                <th>
                                    Guests
                                </th>

                                <th>
                                    Amount
                                </th>

                                <th>
                                    Status
                                </th>

                            </tr>

                        </thead>


                        <tbody>


                            <?php foreach (
                                $recentReservations
                                as $reservation
                            ): ?>


                                <tr>


                                    <td>

                                        <div class="customer-info">


                                            <?php if (
                                                !empty(
                                                    $reservation[
                                                        "profile_image"
                                                    ]
                                                )
                                            ): ?>

                                                <img
                                                    src="../<?= htmlspecialchars(
                                                        $reservation[
                                                            "profile_image"
                                                        ]
                                                    ) ?>"
                                                    class="customer-picture"
                                                    alt="Profile"
                                                >

                                            <?php else: ?>

                                                <div
                                                    class="customer-placeholder"
                                                >
                                                    <?= icon("user") ?>
                                                </div>

                                            <?php endif; ?>


                                            <div>

                                                <div
                                                    class="customer-name"
                                                >

                                                    <?= htmlspecialchars(
                                                        $reservation[
                                                            "customer_name"
                                                        ]
                                                        ?? "Customer"
                                                    ) ?>

                                                </div>

                                                <div
                                                    class="customer-email"
                                                >

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
                                            $reservation[
                                                "room_name"
                                            ]
                                        ) ?>

                                    </td>


                                    <td>

                                        <?= htmlspecialchars(
                                            $reservation[
                                                "check_in"
                                            ]
                                        ) ?>

                                    </td>


                                    <td>

                                        <?= htmlspecialchars(
                                            $reservation[
                                                "check_out"
                                            ]
                                        ) ?>

                                    </td>


                                    <td>

                                        <?= (int)
                                            $reservation[
                                                "guests"
                                            ]
                                        ?>

                                    </td>


                                    <td class="amount">

                                        ₱<?= number_format(
                                            (float)
                                            $reservation[
                                                "total_amount"
                                            ],
                                            2
                                        ) ?>

                                    </td>


                                    <td>

                                        <span
                                            class="status <?= htmlspecialchars(
                                                $reservation[
                                                    "status"
                                                ]
                                            ) ?>"
                                        >

                                            <?= ucfirst(
                                                htmlspecialchars(
                                                    $reservation[
                                                        "status"
                                                    ]
                                                )
                                            ) ?>

                                        </span>

                                    </td>


                                </tr>


                            <?php endforeach; ?>


                        </tbody>

                    </table>

                </div>


            <?php else: ?>


                <div class="empty">

                    <h3>
                        No reservations yet
                    </h3>

                    <p>
                        New reservations will appear here.
                    </p>

                </div>


            <?php endif; ?>


        </section>


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