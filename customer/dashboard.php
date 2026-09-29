<?php

session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/icons.php";
require_once __DIR__ . "/../includes/chat.php";
require_once __DIR__ . "/../includes/paymongo.php";

ensure_chat_schema($pdo);
$inboxUnread = chat_unread_for_customer($pdo, (int) ($_SESSION["user_id"] ?? 0));


// ======================================================
// CUSTOMER LOGIN REQUIRED
// ======================================================

if (
    !isset($_SESSION["user_id"])
    || ($_SESSION["role"] ?? "") !== "customer"
) {
    header("Location: ../login.php");
    exit;
}

$user_id = (int) $_SESSION["user_id"];

// Online payments that were paid (or abandoned) since the customer left PayMongo
paymongo_settle_pending($pdo, $user_id);


// ======================================================
// GET CURRENT USER INFORMATION
// ======================================================

$stmtUser = $pdo->prepare("
    SELECT *
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmtUser->execute([$user_id]);

$currentUser = $stmtUser->fetch();

if (!$currentUser) {

    session_destroy();

    header("Location: ../login.php");

    exit;
}


// ======================================================
// HANDLE RESERVATION CANCELLATION
// ======================================================

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["cancel_reservation"])
) {

    $reservation_id =
        (int) ($_POST["reservation_id"] ?? 0);


    if ($reservation_id > 0) {

        $stmt = $pdo->prepare("
            UPDATE reservations

            SET status = 'cancelled'

            WHERE id = ?
            AND user_id = ?
            AND status = 'pending'

            AND NOT EXISTS (
                SELECT 1
                FROM payments

                WHERE payments.reservation_id =
                    reservations.id

                AND payments.status = 'verified'
            )
        ");

        $stmt->execute([
            $reservation_id,
            $user_id
        ]);
    }


    header("Location: dashboard.php");

    exit;
}


// ======================================================
// GET CUSTOMER RESERVATIONS
// ONLY GET LATEST PAYMENT FOR EACH RESERVATION
// ======================================================

$stmt = $pdo->prepare("
    SELECT
        reservations.*,
        rooms.room_name,

        (
            SELECT payments.status
            FROM payments

            WHERE payments.reservation_id =
                reservations.id

            AND payments.user_id =
                reservations.user_id

            ORDER BY payments.id DESC

            LIMIT 1
        ) AS payment_status,

        (
            SELECT payments.id
            FROM payments

            WHERE payments.reservation_id =
                reservations.id

            AND payments.user_id =
                reservations.user_id

            ORDER BY payments.id DESC

            LIMIT 1
        ) AS payment_id

    FROM reservations

    INNER JOIN rooms
        ON reservations.room_id =
            rooms.id

    WHERE reservations.user_id = ?

    ORDER BY reservations.created_at DESC
");

$stmt->execute([$user_id]);

$reservations = $stmt->fetchAll();

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
        content="#fffaf4"
    >

    <title>
        Customer Dashboard | ARVE'S House
    </title>

    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html {
            scroll-behavior: smooth;
            -webkit-tap-highlight-color: transparent;
            -webkit-text-size-adjust: 100%;
            text-size-adjust: 100%;
        }

        :root {
            --cream: #fffaf4;
            --cream-2: #f4e7d9;
            --brown: #7a4f36;
            --brown-dark: #513421;
            --gold: #d4a76a;
            --text: #241a15;
            --muted: #786d66;
            --white: rgba(255,255,255,.92);
            --border: rgba(122,79,54,.14);
            --shadow:
                0 18px 50px
                rgba(76,50,34,.10);

            /* motion tokens */
            --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            --ease-in-out: cubic-bezier(0.77, 0, 0.175, 1);
            --ease-drawer: cubic-bezier(0.32, 0.72, 0, 1);
        }

        body {
            font-family:
                Arial,
                Helvetica,
                sans-serif;

            color:
                var(--text);

            background:
                radial-gradient(
                    circle at 8% 10%,
                    rgba(212,167,106,.22),
                    transparent 28%
                ),
                radial-gradient(
                    circle at 92% 45%,
                    rgba(122,79,54,.11),
                    transparent 28%
                ),
                linear-gradient(
                    135deg,
                    #fffdf9 0%,
                    #f8f0e6 48%,
                    #eee0d1 100%
                );

            min-height: 100vh;
            min-height: 100svh;
        }

        a {
            color: inherit;
        }

        .navbar {
            min-height: 78px;
            padding: 0 7%;
            padding-top: env(safe-area-inset-top, 0px);
            padding-left: max(7%, env(safe-area-inset-left, 0px));
            padding-right: max(7%, env(safe-area-inset-right, 0px));
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 18px;
            position: sticky;
            top: 0;
            z-index: 1000;
            background:
                rgba(255,250,244,.90);
            backdrop-filter: blur(16px);
            border-bottom:
                1px solid
                var(--border);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 11px;
            text-decoration: none;
            color: var(--text);
            font-size: 21px;
            font-weight: 800;
        }

        .brand-mark {
            width: 43px;
            height: 43px;
            border-radius: 13px;
            display: grid;
            place-items: center;
            background:
                linear-gradient(
                    135deg,
                    var(--brown),
                    var(--brown-dark)
                );
            color: white;
            box-shadow:
                0 8px 20px
                rgba(90,56,38,.20);
        }

        .brand small {
            display: block;
            color: var(--muted);
            font-size: 9px;
            font-weight: normal;
            letter-spacing: 1.2px;
        }

        .nav-right {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .nav-right > a {
            text-decoration: none;
            padding: 9px 12px;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 700;
            touch-action: manipulation;
            transition:
                transform 140ms var(--ease-out),
                background-color 180ms ease,
                color 180ms ease;
        }

        @media (hover: hover) and (pointer: fine) {

            .nav-right > a:hover {
                background:
                    rgba(122,79,54,.08);
                color: var(--brown);
            }
        }

        .nav-right > a:not(.logout-link):focus-visible {
            background:
                rgba(122,79,54,.08);
            color: var(--brown);
        }

        .nav-right > a:active {
            transform: scale(0.97);
        }

        .profile-link {
            display: flex;
            align-items: center;
            gap: 9px;
            text-decoration: none;
            padding: 5px 8px !important;
        }

        .nav-profile-image,
        .nav-profile-placeholder {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border:
                2px solid
                rgba(122,79,54,.18);
        }

        .nav-profile-image {
            object-fit: cover;
        }

        .nav-profile-placeholder {
            display: grid;
            place-items: center;
            background:
                rgba(122,79,54,.10);
        }

        .profile-name {
            font-size: 12px;
            font-weight: 800;
        }

        .logout-link {
            background:
                linear-gradient(
                    135deg,
                    var(--brown),
                    var(--brown-dark)
                );
            color: white !important;
        }

        .container {
            width: 90%;
            /* viewport-fit=cover: keep cards clear of the notch in landscape (desktop: env()=0, unchanged) */
            width: min(90%, calc(100% - 2 * max(env(safe-area-inset-left, 0px), env(safe-area-inset-right, 0px))));
            max-width: 1180px;
            margin:
                42px auto 70px;
        }

        .welcome {
            display: grid;
            grid-template-columns:
                1fr auto;
            align-items: center;
            gap: 24px;
            padding: 28px;
            margin-bottom: 30px;
            border:
                1px solid
                rgba(255,255,255,.90);
            border-radius: 24px;
            background:
                rgba(255,255,255,.88);
            backdrop-filter: blur(14px);
            box-shadow: var(--shadow);
        }

        .welcome-badge {
            display: inline-block;
            margin-bottom: 9px;
            color: var(--brown);
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 3px;
        }

        .welcome h1 {
            font-family:
                Georgia,
                "Times New Roman",
                serif;
            font-size:
                clamp(
                    32px,
                    4vw,
                    44px
                );
            margin-bottom: 7px;
        }

        .welcome p {
            color: var(--muted);
            font-size: 13px;
            margin-bottom: 4px;
        }

        .welcome-side {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 0 16px;
            border: none;
            border-radius: 10px;
            text-decoration: none;
            cursor: pointer;
            font-size: 12px;
            font-weight: 800;
            touch-action: manipulation;
            -webkit-user-select: none;
            user-select: none;
            transition:
                transform 140ms var(--ease-out);
        }

        @media (hover: hover) and (pointer: fine) {

            .btn:hover {
                transform: translateY(-1px);
            }
        }

        .btn:active:not(:disabled):not([aria-disabled="true"]) {
            transform: translateY(0) scale(0.97);
        }

        .btn-primary {
            background:
                linear-gradient(
                    135deg,
                    var(--brown),
                    var(--brown-dark)
                );
            color: white;
            box-shadow:
                0 8px 20px
                rgba(90,56,38,.16);
        }

        .btn-secondary {
            background:
                rgba(255,255,255,.70);
            color: var(--brown-dark);
            border:
                1px solid
                var(--border);
        }

        .btn-danger {
            background: #dc2626;
            color: white;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: end;
            gap: 20px;
            margin-bottom: 18px;
        }

        .section-header span {
            color: var(--brown);
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 3px;
        }

        .section-title {
            margin-top: 5px;
            font-family:
                Georgia,
                "Times New Roman",
                serif;
            font-size: 30px;
        }

        .section-header p {
            color: var(--muted);
            font-size: 12px;
        }

        .reservation-card {
            margin-bottom: 20px;
            overflow: hidden;
            border:
                1px solid
                rgba(255,255,255,.90);
            border-radius: 20px;
            background:
                rgba(255,255,255,.90);
            backdrop-filter: blur(12px);
            box-shadow: var(--shadow);
        }

        .reservation-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            padding: 20px 22px;
            border-bottom:
                1px solid
                var(--border);
            background:
                linear-gradient(
                    135deg,
                    rgba(255,250,244,.95),
                    rgba(244,231,217,.65)
                );
        }

        .reservation-top h3 {
            font-family:
                Georgia,
                "Times New Roman",
                serif;
            font-size: 21px;
            margin-bottom: 3px;
        }

        .reservation-id {
            color: var(--muted);
            font-size: 11px;
        }

        .reservation-body {
            padding: 20px 22px 22px;
        }

        .details {
            display: grid;
            grid-template-columns:
                repeat(3, 1fr);
            gap: 12px;
        }

        .detail-box {
            padding: 13px;
            border:
                1px solid
                var(--border);
            border-radius: 11px;
            background:
                rgba(255,250,244,.58);
        }

        .detail-box span {
            display: block;
            margin-bottom: 5px;
            color: var(--muted);
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .detail-box strong,
        .detail-box div {
            color: var(--text);
            font-size: 13px;
        }

        .status-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 16px;
        }

        .status,
        .payment-pill {
            display: inline-flex;
            align-items: center;
            min-height: 28px;
            padding: 0 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 800;
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

        .payment-verified {
            background: #dcfce7;
            color: #166534;
        }

        .payment-pending {
            background: #fff7ed;
            color: #9a3412;
        }

        .payment-rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .payment-none {
            background: #f3f4f6;
            color: #6b7280;
        }

        .actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .empty {
            padding: 45px 25px;
            text-align: center;
            border:
                1px solid
                rgba(255,255,255,.90);
            border-radius: 20px;
            background:
                rgba(255,255,255,.88);
            box-shadow: var(--shadow);
        }

        .empty h3 {
            font-family:
                Georgia,
                "Times New Roman",
                serif;
            font-size: 23px;
            margin-bottom: 8px;
        }

        .empty p {
            color: var(--muted);
            font-size: 13px;
            margin-bottom: 18px;
        }

        footer {
            margin-top: 70px;
            padding: 35px 7% 22px;
            padding-bottom: calc(22px + env(safe-area-inset-bottom, 0px));
            padding-left: max(7%, env(safe-area-inset-left, 0px));
            padding-right: max(7%, env(safe-area-inset-right, 0px));
            background: #2d1d16;
            color: #d8c9bf;
        }

        .footer-inner {
            max-width: 1180px;
            margin: auto;
            display: flex;
            justify-content: space-between;
            gap: 30px;
            align-items: center;
        }

        footer h3 {
            font-family:
                Georgia,
                "Times New Roman",
                serif;
            color: white;
            margin-bottom: 3px;
        }

        footer p {
            font-size: 11px;
            color: #bdaea5;
        }

        @media (max-width: 850px) {

            .navbar {
                padding:
                    14px 5%;
                padding-top: calc(14px + env(safe-area-inset-top, 0px));
                padding-left: max(5%, env(safe-area-inset-left, 0px));
                padding-right: max(5%, env(safe-area-inset-right, 0px));
                align-items: flex-start;
            }

            .nav-right {
                justify-content: flex-end;
            }

            .profile-name {
                display: none;
            }

            .welcome {
                grid-template-columns: 1fr;
            }

            .welcome-side {
                justify-content: flex-start;
            }

            .details {
                grid-template-columns:
                    repeat(2, 1fr);
            }

            .section-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media (max-width: 620px) {

            .brand small {
                display: none;
            }

            .nav-right > a:not(.profile-link):not(.logout-link) {
                display: none;
            }

            .container {
                width: 92%;
                margin-top: 25px;
            }

            .details {
                grid-template-columns: 1fr;
            }

            .reservation-top {
                align-items: flex-start;
                flex-direction: column;
            }

            .footer-inner {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media (prefers-reduced-motion: reduce) {

            html {
                scroll-behavior: auto;
            }

            /* keep color fades; drop hover lift + press scale */
            .btn:hover,
            .btn:active,
            .nav-right > a:active {
                transform: none !important;
            }

            *,
            *::before,
            *::after {
                animation-duration: 1ms !important;
                animation-iteration-count: 1 !important;
            }
        }

    </style>

<style>
/* Phone polish: keep the brand on one line and the header actions side by side */
@media (max-width: 560px) {
    .brand { font-size: 17px; gap: 9px; }
    .brand-mark { width: 36px; height: 36px; border-radius: 11px; }
    .nav-right { flex-wrap: nowrap; }
}

/* Compact reservation boxes */

/* the payment pill is a span inside .detail-box, which made it a block label; keep it a pill */
.detail-box .payment-pill {
    display: inline-flex;
    align-items: center;
    margin-bottom: 0;
    letter-spacing: normal;
}

@media (min-width: 901px) {
    /* all six details on one row */
    .details { grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 10px; }
    .reservation-top { padding: 16px 20px; }
    .reservation-body { padding: 16px 20px 18px; }
    .detail-box { padding: 10px 12px; }
    .actions { margin-top: 14px; }
}

@media (max-width: 620px) {
    .reservation-card { margin-bottom: 14px; border-radius: 16px; }

    .reservation-top {
        flex-direction: row;
        align-items: center;
        gap: 10px;
        padding: 13px 15px;
    }

    .reservation-top h3 { font-size: 17px; margin-bottom: 1px; }
    .reservation-top .status { flex: none; }

    .reservation-body { padding: 12px 15px 15px; }

    .details { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 7px; }

    .detail-box { padding: 8px 10px; border-radius: 9px; }
    .detail-box span { margin-bottom: 2px; font-size: 9px; letter-spacing: .3px; }
    .detail-box strong, .detail-box div { font-size: 12.5px; }

    /* Total amount: two columns; Payment: full row with the pill on the right */
    .detail-box:nth-child(5) { grid-column: span 2; }

    .detail-box:nth-child(6) {
        grid-column: 1 / -1;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }

    .detail-box:nth-child(6) > span { margin-bottom: 0; }
    .detail-box:nth-child(6) > div { display: flex; }
    .detail-box .payment-pill { min-height: 24px; margin: 0; }

    .actions { gap: 8px; margin-top: 12px; }
    .actions > .btn, .actions > form { flex: 1; }
    .actions form .btn { width: 100%; }
    .actions .btn { min-height: 40px; padding: 0 10px; white-space: nowrap; }
}
</style>
<?php require __DIR__ . "/../includes/glass.php"; ?>
<style>
/* Messages: envelope button in the header (visible on phones too) */
.msg-link {
    position: relative;
    display: inline-grid !important;
    place-items: center;
    width: 42px;
    height: 42px;
    border-radius: 999px;
    border: 1px solid var(--border, rgba(122,79,54,.14));
    background: rgba(255, 250, 244, 0.7);
    color: var(--brown-dark, #513421);
    text-decoration: none;
}
.msg-link svg { display: block; }
.msg-count {
    position: absolute;
    top: -4px;
    right: -4px;
    min-width: 19px;
    height: 19px;
    padding: 0 5px;
    border: 2px solid #fff;
    border-radius: 999px;
    background: #dc2626;
    color: #fff;
    font: 800 10px/15px Arial, sans-serif;
    text-align: center;
}
@media (max-width: 420px) {
    .brand { font-size: 15px; white-space: nowrap; }
    .brand-mark { width: 34px; height: 34px; }
    .nav-right { gap: 6px; }
    .msg-link { width: 40px; height: 40px; }
}
.btn .msg-count, .mini-link .msg-count { position: static; display: inline-block; margin-left: 6px; border: 0; line-height: 19px; }
</style>
</head>

<body>


<nav class="navbar">

    <a
        href="../index.php"
        class="brand"
    >
        <span class="brand-mark">
            <?= icon("home") ?>
        </span>

        <span>
            ARVE'S House

            <small>
                YOUR HOME AWAY FROM HOME
            </small>
        </span>
    </a>


    <div class="nav-right">

        <a href="../index.php">
            Home
        </a>

        <a href="../rooms.php">
            Rooms
        </a>

        <a href="dashboard.php">
            My Reservations
        </a>
        <a href="messages.php" class="msg-link" aria-label="Messages"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg><?php if ($inboxUnread > 0): ?><span class="msg-count"><?= $inboxUnread > 9 ? "9+" : $inboxUnread ?></span><?php endif; ?></a>


        <a
            href="profile.php"
            class="profile-link"
        >

            <?php if (
                !empty(
                    $currentUser["profile_image"]
                )
            ): ?>

                <img
                    src="../<?= htmlspecialchars(
                        $currentUser["profile_image"]
                    ) ?>"
                    alt="Profile Picture"
                    class="nav-profile-image"
                >

            <?php else: ?>

                <span
                    class="nav-profile-placeholder"
                >
                    <?= icon("user") ?>
                </span>

            <?php endif; ?>


            <span class="profile-name">

                <?= htmlspecialchars(
                    $currentUser["name"]
                    ?? $currentUser["full_name"]
                    ?? "Profile"
                ) ?>

            </span>

        </a>


        <a
            href="../logout.php"
            class="logout-link"
        >
            Logout
        </a>

    </div>

</nav>


<div class="container">


    <section class="welcome">

        <div>

            <span class="welcome-badge">
                Customer Portal
            </span>

            <h1>
                Welcome back,
                <?= htmlspecialchars(
                    $currentUser["name"]
                    ?? $currentUser["full_name"]
                    ?? "Customer"
                ) ?>
            </h1>

            <p>
                Manage your reservations,
                payment status and upcoming stays
                from one place.
            </p>

        </div>


        <div class="welcome-side">

            <a
                href="../rooms.php"
                class="btn btn-primary"
            >
                ＋ Book Another Room
            </a>

            <a
                href="profile.php"
                class="btn btn-secondary"
            >
                My Profile
            </a>

            <a href="messages.php" class="btn btn-secondary">Messages <?php if ($inboxUnread > 0): ?><span class="msg-count"><?= $inboxUnread > 9 ? "9+" : $inboxUnread ?></span><?php endif; ?></a>

        </div>

    </section>


    <div class="section-header">

        <div>

            <span>
                Your Stays
            </span>

            <h2 class="section-title">
                My Reservations
            </h2>

        </div>

        <p>
            <?= count($reservations) ?>
            reservation<?= count($reservations) === 1 ? "" : "s" ?>
        </p>

    </div>


    <?php if (
        count($reservations) > 0
    ): ?>


        <?php foreach (
            $reservations
            as $reservation
        ): ?>


            <?php

            $status =
                $reservation["status"];

            $paymentStatus =
                $reservation["payment_status"]
                ?? null;


            $payableReservation =
                !in_array(
                    $status,
                    [
                        "cancelled",
                        "declined",
                        "completed"
                    ],
                    true
                );


            $canCancel =
                $status === "pending"
                &&
                $paymentStatus !== "verified";

            ?>


            <article class="reservation-card">


                <div class="reservation-top">

                    <div>

                        <h3>
                            <?= htmlspecialchars(
                                $reservation["room_name"]
                            ) ?>
                        </h3>

                        <div class="reservation-id">
                            Reservation
                            #<?= (int)
                                $reservation["id"]
                            ?>
                        </div>

                    </div>


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

                </div>


                <div class="reservation-body">


                    <div class="details">


                        <div class="detail-box">

                            <span>
                                Check-in
                            </span>

                            <strong>
                                <?= htmlspecialchars(
                                    $reservation["check_in"]
                                ) ?>
                            </strong>

                        </div>


                        <div class="detail-box">

                            <span>
                                Check-out
                            </span>

                            <strong>
                                <?= htmlspecialchars(
                                    $reservation["check_out"]
                                ) ?>
                            </strong>

                        </div>


                        <div class="detail-box">

                            <span>
                                Guests
                            </span>

                            <strong>
                                <?= (int)
                                    $reservation["guests"]
                                ?>
                            </strong>

                        </div>


                        <div class="detail-box">

                            <span>
                                Total Nights
                            </span>

                            <strong>
                                <?= (int)
                                    $reservation["total_nights"]
                                ?>
                            </strong>

                        </div>


                        <div class="detail-box">

                            <span>
                                Total Amount
                            </span>

                            <strong>
                                ₱<?= number_format(
                                    (float)
                                    $reservation["total_amount"],
                                    2
                                ) ?>
                            </strong>

                        </div>


                        <div class="detail-box">

                            <span>
                                Payment
                            </span>

                            <div>

                                <?php if (
                                    $paymentStatus === "verified"
                                ): ?>

                                    <span
                                        class="
                                            payment-pill
                                            payment-verified
                                        "
                                    >
                                        ✓ Verified
                                    </span>

                                <?php elseif (
                                    $paymentStatus === "pending"
                                ): ?>

                                    <span
                                        class="
                                            payment-pill
                                            payment-pending
                                        "
                                    >
                                        <?= icon("clock") ?> Waiting Verification
                                    </span>

                                <?php elseif (
                                    $paymentStatus === "rejected"
                                ): ?>

                                    <span
                                        class="
                                            payment-pill
                                            payment-rejected
                                        "
                                    >
                                        ✕ Rejected
                                    </span>

                                <?php else: ?>

                                    <span
                                        class="
                                            payment-pill
                                            payment-none
                                        "
                                    >
                                        Not yet paid
                                    </span>

                                <?php endif; ?>

                            </div>

                        </div>


                    </div>


                    <div class="actions">


                        <?php if (
                            $payableReservation
                            &&
                            (
                                !$paymentStatus
                                ||
                                in_array($paymentStatus, ["rejected", "cancelled"], true)
                            )
                        ): ?>


                            <a
                                href="../payment.php?reservation_id=<?= (int)
                                    $reservation["id"]
                                ?>"
                                class="btn btn-primary"
                            >

                                <?= $paymentStatus === "rejected"
                                    ? "Pay Again"
                                    : "Pay Now"
                                ?>

                            </a>

                        <?php endif; ?>


                        <?php if ($canCancel): ?>


                            <form
                                method="POST"
                                onsubmit="
                                    return confirm(
                                        'Are you sure you want to cancel this reservation?'
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


                                <button
                                    type="submit"
                                    name="cancel_reservation"
                                    class="btn btn-danger"
                                >
                                    Cancel Reservation
                                </button>

                            </form>


                        <?php endif; ?>


                    </div>


                </div>


            </article>


        <?php endforeach; ?>


    <?php else: ?>


        <div class="empty">

            <h3>
                No reservations yet
            </h3>

            <p>
                You haven't booked a room yet.
                Browse available rooms and start your first reservation.
            </p>

            <a
                href="../rooms.php"
                class="btn btn-primary"
            >
                View Available Rooms
            </a>

        </div>


    <?php endif; ?>


</div>


<footer>

    <div class="footer-inner">

        <div>

            <h3>
                ARVE'S House
            </h3>

            <p>
                Your Home Away From Home
            </p>

        </div>


        <div>

            <p>
                Customer Reservation Portal
            </p>

            <p>
                Comfort · Relax · Stay
            </p>

        </div>

    </div>

</footer>


<?php require __DIR__ . "/../includes/inbox-widget.php"; ?>
<?php require __DIR__ . "/../includes/terms-modal.php"; ?>

</body>

</html>