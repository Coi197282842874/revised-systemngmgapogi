<?php

session_start();

require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/icons.php";
require_once __DIR__ . "/includes/availability.php";

ensure_availability_schema($pdo);


// ======================================================
// LOGIN STATUS
// ======================================================

$isLoggedIn = isset($_SESSION["user_id"]);

$isCustomer =
    $isLoggedIn &&
    ($_SESSION["role"] ?? "") === "customer";

$isAdmin =
    $isLoggedIn &&
    ($_SESSION["role"] ?? "") === "admin";


// ======================================================
// AVAILABILITY SEARCH
// ======================================================

$checkIn = trim($_GET["check_in"] ?? "");
$checkOut = trim($_GET["check_out"] ?? "");
$availabilityChecked = false;
$availabilityError = "";
$today = date("Y-m-d");

if ($checkIn !== "" || $checkOut !== "") {

    if ($checkIn === "" || $checkOut === "") {

        $availabilityError =
            "Please select both check-in and check-out dates.";

    } else {

        $checkInDate =
            DateTime::createFromFormat("Y-m-d", $checkIn);

        $checkOutDate =
            DateTime::createFromFormat("Y-m-d", $checkOut);

        $validCheckIn =
            $checkInDate &&
            $checkInDate->format("Y-m-d") === $checkIn;

        $validCheckOut =
            $checkOutDate &&
            $checkOutDate->format("Y-m-d") === $checkOut;

        if (!$validCheckIn || !$validCheckOut) {

            $availabilityError =
                "Please select valid reservation dates.";

        } elseif ($checkIn < $today) {

            $availabilityError =
                "Check-in date cannot be in the past.";

        } elseif ($checkOut <= $checkIn) {

            $availabilityError =
                "Check-out date must be after the check-in date.";

        } else {

            $availabilityChecked = true;
        }
    }
}


// ======================================================
// GET ROOMS
// ======================================================

if ($availabilityChecked) {

    $stmt = $pdo->prepare(
        "SELECT *
         FROM rooms
         WHERE status = 'available'
         AND " . room_free_sql() . "
         ORDER BY id DESC"
    );

    // reservations overlap, then dates closed by the admin
    $stmt->execute([
        $checkOut,
        $checkIn,
        $checkOut,
        $checkIn
    ]);

} else {

    $stmt = $pdo->query(
        "SELECT *
         FROM rooms
         WHERE status = 'available'
         ORDER BY id DESC"
    );
}

$rooms = $stmt->fetchAll();


// ======================================================
// ROOM IMAGE HELPER
// ======================================================

function roomImages(int $roomId): array
{
    /*
        Put your six images here:

        uploads/rooms/room_1_1.jpg
        uploads/rooms/room_1_2.jpg
        uploads/rooms/room_1_3.jpg
        uploads/rooms/room_1_4.jpg
        uploads/rooms/room_1_5.jpg
        uploads/rooms/room_1_6.jpg

        For room ID 2:
        room_2_1.jpg ... room_2_6.jpg
    */

    $images = [];

    for ($number = 1; $number <= 6; $number++) {

        $relativePath =
            "uploads/rooms/room_" .
            $roomId .
            "_" .
            $number .
            ".jpg";

        $absolutePath =
            __DIR__ . "/" . $relativePath;

        if (file_exists($absolutePath)) {

            $images[] = $relativePath;
        }
    }

    return $images;
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0, viewport-fit=cover"
    >
    <meta name="theme-color" content="#fffaf4">

    <title>Rooms | ARVE'S House</title>

    <style>

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
            -webkit-tap-highlight-color: transparent;
            -webkit-text-size-adjust: 100%;
            text-size-adjust: 100%;
        }

        :root {
            --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            --ease-in-out: cubic-bezier(0.77, 0, 0.175, 1);
            --ease-drawer: cubic-bezier(0.32, 0.72, 0, 1);
            --cream: #fffaf4;
            --cream-2: #f4e7d9;
            --brown: #7a4f36;
            --brown-dark: #513421;
            --brown-soft: #a47759;
            --gold: #d4a76a;
            --text: #241a15;
            --muted: #786d66;
            --white: rgba(255,255,255,.92);
            --border: rgba(122,79,54,.14);
            --shadow: 0 18px 50px rgba(76,50,34,.10);
        }

        body {

            margin: 0;

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            color: var(--text);

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

        img {
            max-width: 100%;
            height: auto;
        }

        button,
        a,
        input {
            touch-action: manipulation;
        }

        button,
        a {
            -webkit-tap-highlight-color: transparent;
        }

        button,
        .book-button,
        .nav-links .action-link {
            -webkit-user-select: none;
            user-select: none;
        }

        body.menu-open {
            overflow: hidden;
        }


        /* ==================================================
           NAVBAR
        ================================================== */

        .navbar {

            min-height: 78px;

            padding: 0 7%;

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 20px;

            position: sticky;

            top: 0;

            z-index: 1000;

            background:
                rgba(255,250,244,.90);

            backdrop-filter:
                blur(16px);

            border-bottom:
                1px solid var(--border);
        }

        .brand {

            display: flex;

            align-items: center;

            gap: 11px;

            color: var(--text);

            text-decoration: none;

            font-size: 21px;

            font-weight: 800;
        }

        .brand-mark {

            width: 43px;

            height: 43px;

            border-radius: 13px;

            display: flex;

            align-items: center;

            justify-content: center;

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

        .nav-links {

            display: flex;

            align-items: center;

            gap: 6px;
        }

        .nav-links a {

            padding: 9px 13px;

            border-radius: 9px;

            color: var(--text);

            text-decoration: none;

            font-size: 13px;

            font-weight: 600;
        }

        .nav-links a:hover {

            background:
                rgba(122,79,54,.08);

            color: var(--brown);
        }

        .nav-links .action-link {

            background:
                linear-gradient(
                    135deg,
                    var(--brown),
                    var(--brown-dark)
                );

            color: white;

            padding-left: 17px;

            padding-right: 17px;
        }

        .mobile-menu {

            display: none;

            border: none;

            background: transparent;

            font-size: 25px;

            cursor: pointer;
        }


        /* ==================================================
           HERO
        ================================================== */

        .hero {

            text-align: center;

            padding: 70px 20px 32px;
        }

        .hero-label {

            color: var(--brown);

            font-size: 10px;

            font-weight: 800;

            letter-spacing: 4px;

            text-transform: uppercase;
        }

        .hero h1 {

            margin:
                10px 0 8px;

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size:
                clamp(
                    38px,
                    5vw,
                    58px
                );

            line-height: 1.05;
        }

        .hero p {

            max-width: 650px;

            margin: auto;

            color: var(--muted);

            font-size: 14px;
        }


        /* ==================================================
           AVAILABILITY
        ================================================== */

        .availability-wrapper {

            width: 90%;

            max-width: 1050px;

            margin:
                0 auto 32px;
        }

        .availability-card {

            padding: 20px;

            border:
                1px solid
                rgba(255,255,255,.85);

            border-radius: 20px;

            background:
                rgba(255,255,255,.82);

            backdrop-filter:
                blur(14px);

            box-shadow:
                var(--shadow);
        }

        .availability-card h2 {

            margin:
                0 0 16px;

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 21px;
        }

        .availability-form {

            display: grid;

            grid-template-columns:
                1fr 1fr auto;

            gap: 14px;

            align-items: end;
        }

        .form-group label {

            display: block;

            margin-bottom: 7px;

            color: var(--brown-dark);

            font-size: 11px;

            font-weight: 800;

            letter-spacing: .5px;
        }

        .form-group input {

            width: 100%;

            min-height: 48px;

            padding:
                0 13px;

            border:
                1px solid
                var(--border);

            border-radius: 10px;

            background:
                rgba(255,255,255,.8);

            color: var(--text);

            font-size: 13px;

            outline: none;
        }

        .form-group input:focus {

            border-color:
                rgba(122,79,54,.5);

            box-shadow:
                0 0 0 3px
                rgba(122,79,54,.08);
        }

        .check-button {

            min-height: 48px;

            padding:
                0 22px;

            border: none;

            border-radius: 10px;

            background:
                linear-gradient(
                    135deg,
                    var(--brown),
                    var(--brown-dark)
                );

            color: white;

            font-size: 13px;

            font-weight: 800;

            cursor: pointer;
        }

        .availability-message {

            margin-top: 15px;

            padding:
                12px 14px;

            border-radius: 9px;

            font-size: 12px;
        }

        .availability-error {

            background: #fee2e2;

            color: #991b1b;
        }

        .availability-success {

            background: #ecfdf5;

            color: #065f46;
        }

        .clear-button {

            display: inline-block;

            margin-top: 12px;

            color: var(--brown);

            font-size: 12px;

            text-decoration: none;

            font-weight: 700;
        }


        /* ==================================================
           NOTICE
        ================================================== */

        .notice {

            width: 90%;

            max-width: 1180px;

            margin:
                0 auto 22px;

            padding:
                14px 17px;

            border:
                1px solid #fed7aa;

            border-radius: 11px;

            background:
                rgba(255,247,237,.92);

            color: #9a3412;

            font-size: 13px;
        }


        /* ==================================================
           ROOMS
        ================================================== */

        .rooms-container {

            max-width: 1180px;

            margin: auto;

            padding:
                18px 20px 70px;
        }

        .rooms-heading {

            display: flex;

            justify-content: space-between;

            align-items: end;

            gap: 20px;

            margin-bottom: 22px;
        }

        .rooms-heading span {

            color: var(--brown);

            font-size: 10px;

            font-weight: 800;

            letter-spacing: 3px;

            text-transform: uppercase;
        }

        .rooms-heading h2 {

            margin:
                5px 0 0;

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 33px;
        }

        .rooms-heading p {

            margin: 0;

            color: var(--muted);

            font-size: 12px;
        }

        .rooms-grid {

            display: grid;

            grid-template-columns:
                repeat(3, 1fr);

            gap: 24px;
        }

        .room-card {

            overflow: hidden;

            border:
                1px solid
                rgba(255,255,255,.90);

            border-radius: 20px;

            background:
                rgba(255,255,255,.92);

            box-shadow:
                var(--shadow);

            transition:
                transform .22s ease,
                box-shadow .22s ease;
        }

        .room-card:hover {

            transform:
                translateY(-5px);

            box-shadow:
                0 24px 60px
                rgba(76,50,34,.14);
        }


        /* ==================================================
           ROOM CAROUSEL
        ================================================== */

        .carousel {

            position: relative;

            height: 225px;

            overflow: hidden;

            touch-action: pan-y;
            user-select: none;
            -webkit-user-select: none;

            background:
                linear-gradient(
                    145deg,
                    #c8a17f,
                    #77533d
                );
        }

        .carousel-track {

            height: 100%;

            display: flex;

            transition:
                transform .45s ease;
        }

        .carousel-slide {

            min-width: 100%;

            height: 100%;
        }

        .carousel-slide img {

            width: 100%;

            height: 100%;

            object-fit: cover;

            display: block;

            pointer-events: none;
        }

        .carousel-fallback {

            min-width: 100%;

            height: 100%;

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 70px;

            color: white;

            background:
                linear-gradient(
                    145deg,
                    #c8a17f,
                    #77533d
                );
        }

        .carousel-button {

            position: absolute;

            top: 50%;

            transform:
                translateY(-50%);

            width: 38px;

            height: 38px;

            border: none;

            border-radius: 50%;

            display: flex;

            align-items: center;

            justify-content: center;

            background:
                rgba(31,22,17,.72);

            color: white;

            font-size: 21px;

            cursor: pointer;

            z-index: 3;

            transition: .2s;
        }

        .carousel-button:hover {

            background:
                rgba(31,22,17,.90);

            transform:
                translateY(-50%)
                scale(1.05);
        }

        .carousel-prev {
            left: 12px;
        }

        .carousel-next {
            right: 12px;
        }

        .carousel-count {

            position: absolute;

            top: 12px;

            right: 12px;

            z-index: 3;

            padding:
                5px 9px;

            border-radius: 20px;

            background:
                rgba(31,22,17,.72);

            color: white;

            font-size: 10px;

            font-weight: 800;
        }

        .carousel-dots {

            position: absolute;

            left: 50%;

            bottom: 12px;

            transform:
                translateX(-50%);

            display: flex;

            gap: 6px;

            z-index: 3;
        }

        .carousel-dot {

            width: 7px;

            height: 7px;

            border: none;

            border-radius: 50%;

            padding: 0;

            background:
                rgba(255,255,255,.60);

            cursor: pointer;

            transition: .2s;
        }

        .carousel-dot.active {

            width: 20px;

            border-radius: 10px;

            background: white;
        }


        /* ==================================================
           ROOM CONTENT
        ================================================== */

        .room-content {

            padding: 21px;
        }

        .available-badge {

            display: inline-block;

            margin-bottom: 10px;

            padding:
                5px 9px;

            border-radius: 20px;

            background: #dcfce7;

            color: #166534;

            font-size: 10px;

            font-weight: 800;
        }

        .room-header {

            display: flex;

            justify-content: space-between;

            align-items: flex-start;

            gap: 15px;

            margin-bottom: 10px;
        }

        .room-content h2 {

            margin: 0;

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 21px;

            color: var(--text);
        }

        .price {

            color: var(--brown);

            font-size: 18px;

            font-weight: 800;

            text-align: right;

            white-space: nowrap;
        }

        .price span {

            display: block;

            color: var(--muted);

            font-size: 10px;

            font-weight: normal;
        }

        .description {

            min-height: 58px;

            margin:
                0 0 15px;

            color: var(--muted);

            font-size: 12px;

            line-height: 1.6;
        }

        .details {

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 12px;

            margin-bottom: 16px;

            padding-top: 13px;

            border-top:
                1px solid
                var(--border);

            color: var(--muted);

            font-size: 12px;
        }

        .book-button {

            display: flex;

            align-items: center;

            justify-content: center;

            width: 100%;

            min-height: 45px;

            padding:
                0 14px;

            border: none;

            border-radius: 10px;

            background:
                linear-gradient(
                    135deg,
                    var(--brown),
                    var(--brown-dark)
                );

            color: white;

            text-decoration: none;

            font-size: 12px;

            font-weight: 800;

            cursor: pointer;

            transition: .2s;
        }

        .book-button:hover {

            transform:
                translateY(-1px);

            box-shadow:
                0 10px 24px
                rgba(90,56,38,.20);
        }

        .login-book {

            background:
                linear-gradient(
                    135deg,
                    #b98058,
                    var(--brown)
                );
        }

        .book-button:disabled {

            background: #d8d1ca;

            color: #7b736d;

            cursor: not-allowed;

            box-shadow: none;
        }


        /* ==================================================
           EMPTY
        ================================================== */

        .empty {

            padding: 55px 25px;

            border:
                1px solid
                rgba(255,255,255,.90);

            border-radius: 20px;

            background:
                rgba(255,255,255,.85);

            text-align: center;

            color: var(--muted);

            box-shadow:
                var(--shadow);
        }

        .empty h2 {

            margin-top: 0;

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            color: var(--text);
        }


        /* ==================================================
           FOOTER
        ================================================== */

        footer {

            padding:
                40px 7% 24px;

            background:
                #2d1d16;

            color: #d8c9bf;
        }

        .footer-inner {

            max-width: 1180px;

            margin: auto;

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 30px;
        }

        footer h3 {

            margin: 0;

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            color: white;

            font-size: 21px;
        }

        footer p {

            margin:
                4px 0 0;

            color: #bdaea5;

            font-size: 11px;
        }

        .footer-bottom {

            max-width: 1180px;

            margin:
                24px auto 0;

            padding-top: 18px;

            border-top:
                1px solid
                rgba(255,255,255,.10);

            color: #a9988e;

            font-size: 10px;
        }


        /* ==================================================
           RESPONSIVE
        ================================================== */

        @media (max-width: 1050px) {

            .navbar {
                padding-left: 5%;
                padding-right: 5%;
            }

            .rooms-grid {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 780px) {

            .navbar {
                min-height: 72px;
                padding: 0 18px;
            }

            .brand {
                min-width: 0;
                font-size: 18px;
            }

            .brand-mark {
                width: 40px;
                height: 40px;
                flex: 0 0 40px;
            }

            .brand small {
                font-size: 8px;
                letter-spacing: .8px;
                white-space: nowrap;
            }

            .mobile-menu {
                display: grid;
                place-items: center;
                width: 44px;
                height: 44px;
                border-radius: 10px;
                color: var(--brown-dark);
                position: relative;
                z-index: 1002;
            }

            .mobile-menu:hover,
            .mobile-menu:focus-visible {
                background: rgba(122,79,54,.08);
                outline: none;
            }

            .nav-links {
                display: none;
                position: fixed;
                top: 72px;
                left: 0;
                right: 0;
                max-height: calc(100dvh - 72px);
                overflow-y: auto;
                padding:
                    16px 18px
                    calc(22px + env(safe-area-inset-bottom));
                flex-direction: column;
                align-items: stretch;
                gap: 7px;
                background: rgba(255,250,244,.985);
                backdrop-filter: blur(18px);
                border-bottom: 1px solid var(--border);
                box-shadow: 0 16px 35px rgba(76,50,34,.10);
            }

            .nav-links.show {
                display: flex;
            }

            .nav-links a {
                min-height: 46px;
                display: flex;
                align-items: center;
                justify-content: center;
                text-align: center;
                padding: 11px 14px;
            }

            .nav-links .action-link {
                width: 100%;
            }

            .hero {
                padding: 48px 18px 26px;
            }

            .hero-label {
                font-size: 9px;
                letter-spacing: 2.5px;
            }

            .hero h1 {
                font-size: clamp(36px, 10vw, 50px);
            }

            .hero p {
                font-size: 13px;
                padding: 0 4px;
            }

            .availability-wrapper,
            .notice {
                width: auto;
                margin-left: 14px;
                margin-right: 14px;
            }

            .availability-card {
                padding: 16px;
                border-radius: 17px;
            }

            .availability-card h2 {
                font-size: 20px;
            }

            .availability-form {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .form-group input {
                min-height: 50px;
                font-size: 16px;
            }

            .check-button {
                width: 100%;
                min-height: 50px;
                padding: 12px 18px;
            }

            .notice {
                font-size: 12px;
                line-height: 1.6;
            }

            .rooms-container {
                padding: 16px 14px 58px;
            }

            .rooms-heading {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
                margin-bottom: 18px;
            }

            .rooms-heading h2 {
                font-size: 29px;
            }

            .rooms-grid {
                grid-template-columns: 1fr;
                gap: 18px;
            }

            .room-card:hover {
                transform: none;
            }

            .carousel {
                height: clamp(220px, 64vw, 310px);
            }

            .carousel-button {
                width: 42px;
                height: 42px;
                font-size: 24px;
            }

            .carousel-prev {
                left: 10px;
            }

            .carousel-next {
                right: 10px;
            }

            .carousel-dot {
                width: 8px;
                height: 8px;
            }

            .room-content {
                padding: 19px;
            }

            .room-header {
                gap: 10px;
            }

            .book-button {
                min-height: 50px;
                font-size: 13px;
            }

            footer {
                padding:
                    34px 18px
                    calc(22px + env(safe-area-inset-bottom));
            }

            .footer-inner {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
            }
        }

        @media (max-width: 430px) {

            .navbar {
                padding: 0 14px;
            }

            .brand {
                gap: 9px;
                font-size: 16px;
            }

            .brand-mark {
                width: 38px;
                height: 38px;
                flex-basis: 38px;
            }

            .brand small {
                font-size: 7px;
                max-width: 180px;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .hero {
                padding-left: 14px;
                padding-right: 14px;
            }

            .hero h1 {
                font-size: 34px;
            }

            .availability-wrapper,
            .notice {
                margin-left: 12px;
                margin-right: 12px;
            }

            .availability-card {
                padding: 14px;
            }

            .rooms-container {
                padding-left: 12px;
                padding-right: 12px;
            }

            .room-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .price {
                text-align: left;
            }

            .price span {
                display: inline;
                margin-left: 4px;
            }

            .description {
                min-height: 0;
            }

            .details {
                flex-direction: column;
                align-items: flex-start;
                gap: 7px;
            }

            .carousel-count {
                top: 10px;
                right: 10px;
            }
        }

        @media (max-width: 360px) {

            .brand small {
                display: none;
            }

            .hero h1 {
                font-size: 31px;
            }

            .rooms-heading h2 {
                font-size: 27px;
            }
        }

        @media (hover: none) {

            .room-card:hover,
            .book-button:hover {
                transform: none;
            }

            .carousel-button:hover {
                transform: translateY(-50%);
            }
        }

    </style>

<style>
/* Phone polish: smaller placeholder icon */
@media (max-width: 560px) {
    .carousel-fallback { font-size: 44px; }
}
</style>
<?php require __DIR__ . "/includes/glass.php"; ?>
</head>

<body>


<!-- ======================================================
     NAVBAR
====================================================== -->

<nav class="navbar">

    <a
        href="index.php"
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


    <button
        type="button"
        class="mobile-menu"
        id="mobileMenuButton"
        onclick="toggleMenu()"
        aria-label="Open menu"
        aria-expanded="false"
        aria-controls="navLinks"
    >
        <?= icon("menu") ?>
    </button>


    <div
        class="nav-links"
        id="navLinks"
    >

        <a href="index.php">
            Home
        </a>

        <a href="rooms.php">
            Rooms
        </a>


        <?php if ($isCustomer): ?>

            <a href="customer/dashboard.php">
                My Reservations
            </a>

            <a href="customer/profile.php">
                My Profile
            </a>

            <a
                href="logout.php"
                class="action-link"
            >
                Logout
            </a>


        <?php elseif ($isAdmin): ?>

            <a
                href="admin/dashboard.php"
                class="action-link"
            >
                Admin Dashboard
            </a>


        <?php else: ?>

            <a href="login.php">
                Login
            </a>

            <a
                href="register.php"
                class="action-link"
            >
                Register
            </a>

        <?php endif; ?>

    </div>

</nav>


<!-- ======================================================
     HERO
====================================================== -->

<section class="hero">

    <span class="hero-label">
        Comfort · Relax · Stay
    </span>

    <h1>
        Find Your Perfect Stay
    </h1>

    <p>
        Browse our available rooms and choose the dates
        that work best for your stay at ARVE'S House.
    </p>

</section>


<!-- ======================================================
     AVAILABILITY
====================================================== -->

<section class="availability-wrapper">

    <div class="availability-card">

        <h2>
            Check Room Availability
        </h2>

        <form
            method="GET"
            action="rooms.php"
            class="availability-form"
        >

            <div class="form-group">

                <label for="check_in">
                    CHECK-IN
                </label>

                <input
                    type="date"
                    id="check_in"
                    name="check_in"
                    min="<?= htmlspecialchars($today) ?>"
                    value="<?= htmlspecialchars($checkIn) ?>"
                    required
                >

            </div>


            <div class="form-group">

                <label for="check_out">
                    CHECK-OUT
                </label>

                <input
                    type="date"
                    id="check_out"
                    name="check_out"
                    min="<?= htmlspecialchars($today) ?>"
                    value="<?= htmlspecialchars($checkOut) ?>"
                    required
                >

            </div>


            <button
                type="submit"
                class="check-button"
            >
                <?= icon("search") ?> Check Availability
            </button>

        </form>


        <?php if ($availabilityError !== ""): ?>

            <div
                class="
                    availability-message
                    availability-error
                "
            >
                <?= htmlspecialchars(
                    $availabilityError
                ) ?>
            </div>

        <?php endif; ?>


        <?php if ($availabilityChecked): ?>

            <div
                class="
                    availability-message
                    availability-success
                "
            >

                <?= count($rooms) ?>
                room<?= count($rooms) === 1 ? "" : "s" ?>
                available from

                <strong>
                    <?= htmlspecialchars($checkIn) ?>
                </strong>

                to

                <strong>
                    <?= htmlspecialchars($checkOut) ?>
                </strong>.

            </div>

            <a
                href="rooms.php"
                class="clear-button"
            >
                Clear selected dates
            </a>

        <?php endif; ?>

    </div>

</section>


<!-- ======================================================
     ACCOUNT NOTICE
====================================================== -->

<?php if (!$isLoggedIn): ?>

    <div class="notice">

        <?= icon("log-in") ?> You can browse our available rooms,
        but you must log in to a customer account
        before making a reservation.

    </div>

<?php elseif ($isAdmin): ?>

    <div class="notice">

        <?= icon("alert") ?> You are viewing the public website using
        an administrator account. Admin accounts
        cannot create room reservations.

    </div>

<?php endif; ?>


<!-- ======================================================
     ROOMS
====================================================== -->

<main class="rooms-container">


    <div class="rooms-heading">

        <div>

            <span>
                Our Rooms
            </span>

            <h2>

                <?= $availabilityChecked
                    ? "Available Rooms"
                    : "All Available Rooms"
                ?>

            </h2>

        </div>


        <p>

            <?php if ($availabilityChecked): ?>

                Showing rooms available for your selected dates.

            <?php else: ?>

                Choose your dates above to check real-time availability.

            <?php endif; ?>

        </p>

    </div>


    <?php if (empty($rooms)): ?>

        <div class="empty">

            <h2>
                No rooms available
            </h2>

            <p>

                <?php if ($availabilityChecked): ?>

                    No rooms are available for your selected dates.
                    Try another check-in and check-out date.

                <?php else: ?>

                    Please check back later.

                <?php endif; ?>

            </p>

        </div>


    <?php else: ?>

        <div class="rooms-grid">


            <?php foreach ($rooms as $room): ?>


                <?php

                $roomId =
                    (int) $room["id"];

                $bookingUrl =
                    "reservation.php?room_id=" .
                    $roomId;

                if ($availabilityChecked) {

                    $bookingUrl .=
                        "&check_in=" .
                        urlencode($checkIn) .
                        "&check_out=" .
                        urlencode($checkOut);
                }

                $images =
                    roomImages($roomId);

                ?>


                <article class="room-card">


                    <!-- =========================================
                         6-IMAGE CAROUSEL
                    ========================================== -->

                    <div
                        class="carousel"
                        data-carousel
                    >


                        <?php if (count($images) > 0): ?>


                            <div class="carousel-track">


                                <?php foreach ($images as $image): ?>

                                    <div class="carousel-slide">

                                        <img
                                            src="<?= htmlspecialchars(
                                                $image
                                            ) ?>"
                                            alt="<?= htmlspecialchars(
                                                $room["room_name"]
                                            ) ?>"
                                        >

                                    </div>

                                <?php endforeach; ?>


                            </div>


                            <div class="carousel-count">

                                <span data-current>
                                    1
                                </span>

                                /

                                <?= count($images) ?>

                            </div>


                            <?php if (count($images) > 1): ?>

                                <button
                                    type="button"
                                    class="
                                        carousel-button
                                        carousel-prev
                                    "
                                    data-prev
                                    aria-label="Previous room image"
                                >
                                    ‹
                                </button>


                                <button
                                    type="button"
                                    class="
                                        carousel-button
                                        carousel-next
                                    "
                                    data-next
                                    aria-label="Next room image"
                                >
                                    ›
                                </button>


                                <div class="carousel-dots">

                                    <?php foreach ($images as $index => $image): ?>

                                        <button
                                            type="button"
                                            class="
                                                carousel-dot
                                                <?= $index === 0
                                                    ? "active"
                                                    : ""
                                                ?>
                                            "
                                            data-dot="<?= $index ?>"
                                            aria-label="Show image <?= $index + 1 ?>"
                                        ></button>

                                    <?php endforeach; ?>

                                </div>

                            <?php endif; ?>


                        <?php else: ?>


                            <div class="carousel-fallback">
                                <?= icon("home") ?>
                            </div>


                        <?php endif; ?>


                    </div>


                    <!-- =========================================
                         ROOM INFORMATION
                    ========================================== -->

                    <div class="room-content">


                        <?php if ($availabilityChecked): ?>

                            <span class="available-badge">
                                ✓ Available for Selected Dates
                            </span>

                        <?php endif; ?>


                        <div class="room-header">

                            <h2>
                                <?= htmlspecialchars(
                                    $room["room_name"]
                                ) ?>
                            </h2>


                            <div class="price">

                                ₱<?= number_format(
                                    (float) $room["price"],
                                    2
                                ) ?>

                                <span>
                                    / night
                                </span>

                            </div>

                        </div>


                        <p class="description">

                            <?= nl2br(
                                htmlspecialchars(
                                    $room["description"]
                                    ?? "Comfortable room available for your stay."
                                )
                            ) ?>

                        </p>


                        <div class="details">

                            <span>

                                <?= icon("users") ?>
                                <?= (int) $room["capacity"] ?>
                                guest<?= (int) $room["capacity"] !== 1
                                    ? "s"
                                    : ""
                                ?>

                            </span>

                            <span>
                                <?= icon("bed") ?> Comfortable Stay
                            </span>

                        </div>


                        <!-- CUSTOMER -->

                        <?php if ($isCustomer): ?>

                            <a
                                class="book-button"
                                href="<?= htmlspecialchars(
                                    $bookingUrl
                                ) ?>"
                            >

                                <?= icon("calendar") ?>
                                <?= $availabilityChecked
                                    ? "Book These Dates"
                                    : "Book Now"
                                ?>

                            </a>


                        <!-- VISITOR -->

                        <?php elseif (!$isLoggedIn): ?>

                            <a
                                class="
                                    book-button
                                    login-book
                                "
                                href="login.php?redirect=<?= urlencode(
                                    $bookingUrl
                                ) ?>"
                            >
                                <?= icon("log-in") ?> Login to Book
                            </a>


                        <!-- ADMIN -->

                        <?php else: ?>

                            <button
                                type="button"
                                class="book-button"
                                disabled
                                title="Customer account required"
                            >
                                Customer Account Required
                            </button>

                        <?php endif; ?>


                    </div>

                </article>


            <?php endforeach; ?>


        </div>

    <?php endif; ?>


</main>


<!-- ======================================================
     FOOTER
====================================================== -->

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
                Transient & Reservation System
            </p>

            <p>
                Comfort · Relax · Stay
            </p>

        </div>

    </div>


    <div class="footer-bottom">

        &copy;
        <?= date("Y") ?>
        ARVE'S House.
        All rights reserved.

    </div>

</footer>


<script>

// ======================================================
// MOBILE MENU
// ======================================================

const navLinks =
    document.getElementById("navLinks");

const mobileMenuButton =
    document.getElementById("mobileMenuButton");


function setMenu(open) {

    navLinks.classList.toggle(
        "show",
        open
    );

    document.body.classList.toggle(
        "menu-open",
        open
    );

    if (mobileMenuButton) {

        mobileMenuButton.setAttribute(
            "aria-expanded",
            open ? "true" : "false"
        );

        mobileMenuButton.setAttribute(
            "aria-label",
            open ? "Close menu" : "Open menu"
        );

        mobileMenuButton.innerHTML =
            open ? <?= json_encode(icon("x")) ?> : <?= json_encode(icon("menu")) ?>;
    }
}


function toggleMenu() {

    setMenu(
        !navLinks.classList.contains("show")
    );
}


navLinks
    .querySelectorAll("a")
    .forEach(link => {

        link.addEventListener(
            "click",
            () => setMenu(false)
        );
    });


document.addEventListener(
    "keydown",
    event => {

        if (event.key === "Escape") {
            setMenu(false);
        }
    }
);


window.addEventListener(
    "resize",
    () => {

        if (window.innerWidth > 780) {
            setMenu(false);
        }
    }
);


// ======================================================
// DATE PICKER
// ======================================================

const checkInInput =
    document.getElementById("check_in");

const checkOutInput =
    document.getElementById("check_out");


function formatLocalDate(date) {

    const year =
        date.getFullYear();

    const month =
        String(
            date.getMonth() + 1
        ).padStart(2, "0");

    const day =
        String(
            date.getDate()
        ).padStart(2, "0");

    return `${year}-${month}-${day}`;
}


function updateCheckoutMinimum() {

    if (!checkInInput.value) {
        return;
    }

    const selectedDate =
        new Date(
            checkInInput.value +
            "T00:00:00"
        );

    selectedDate.setDate(
        selectedDate.getDate() + 1
    );

    const minimumCheckout =
        formatLocalDate(
            selectedDate
        );

    checkOutInput.min =
        minimumCheckout;


    if (
        checkOutInput.value
        &&
        checkOutInput.value < minimumCheckout
    ) {

        checkOutInput.value = "";
    }
}


checkInInput.addEventListener(
    "change",
    updateCheckoutMinimum
);

updateCheckoutMinimum();


// ======================================================
// ROOM CAROUSELS
// Desktop: arrows / dots / autoplay
// Mobile: swipe left / right
// ======================================================

document
    .querySelectorAll("[data-carousel]")
    .forEach(carousel => {

        const track =
            carousel.querySelector(
                ".carousel-track"
            );

        if (!track) {
            return;
        }

        const slides =
            carousel.querySelectorAll(
                ".carousel-slide"
            );

        if (slides.length <= 1) {
            return;
        }

        const prevButton =
            carousel.querySelector(
                "[data-prev]"
            );

        const nextButton =
            carousel.querySelector(
                "[data-next]"
            );

        const dots =
            carousel.querySelectorAll(
                "[data-dot]"
            );

        const currentLabel =
            carousel.querySelector(
                "[data-current]"
            );

        let currentIndex = 0;
        let autoplayTimer = null;

        let touchStartX = 0;
        let touchEndX = 0;
        let isTouching = false;


        function showSlide(index) {

            if (index < 0) {

                currentIndex =
                    slides.length - 1;

            } else if (
                index >= slides.length
            ) {

                currentIndex = 0;

            } else {

                currentIndex = index;
            }


            track.style.transform =
                `translateX(-${currentIndex * 100}%)`;


            if (currentLabel) {

                currentLabel.textContent =
                    currentIndex + 1;
            }


            dots.forEach(
                (dot, dotIndex) => {

                    dot.classList.toggle(
                        "active",
                        dotIndex === currentIndex
                    );

                    dot.setAttribute(
                        "aria-current",
                        dotIndex === currentIndex
                            ? "true"
                            : "false"
                    );
                }
            );
        }


        function nextSlide() {

            showSlide(
                currentIndex + 1
            );
        }


        function previousSlide() {

            showSlide(
                currentIndex - 1
            );
        }


        function stopAutoplay() {

            if (autoplayTimer) {

                clearInterval(
                    autoplayTimer
                );

                autoplayTimer = null;
            }
        }


        function startAutoplay() {

            stopAutoplay();

            autoplayTimer =
                setInterval(
                    nextSlide,
                    4000
                );
        }


        function restartAutoplay() {

            startAutoplay();
        }


        function handleSwipe() {

            const distance =
                touchEndX - touchStartX;

            if (
                Math.abs(distance) < 45
            ) {
                return;
            }

            if (distance < 0) {

                nextSlide();

            } else {

                previousSlide();
            }

            restartAutoplay();
        }


        if (prevButton) {

            prevButton.addEventListener(
                "click",
                () => {

                    previousSlide();
                    restartAutoplay();
                }
            );
        }


        if (nextButton) {

            nextButton.addEventListener(
                "click",
                () => {

                    nextSlide();
                    restartAutoplay();
                }
            );
        }


        dots.forEach(dot => {

            dot.addEventListener(
                "click",
                () => {

                    showSlide(
                        Number(
                            dot.dataset.dot
                        )
                    );

                    restartAutoplay();
                }
            );
        });


        carousel.addEventListener(
            "touchstart",
            event => {

                if (
                    event.touches.length !== 1
                ) {
                    return;
                }

                isTouching = true;

                touchStartX =
                    event.touches[0].clientX;

                touchEndX =
                    touchStartX;

                stopAutoplay();

            },
            { passive: true }
        );


        carousel.addEventListener(
            "touchmove",
            event => {

                if (
                    !isTouching
                    ||
                    event.touches.length !== 1
                ) {
                    return;
                }

                touchEndX =
                    event.touches[0].clientX;

            },
            { passive: true }
        );


        carousel.addEventListener(
            "touchend",
            () => {

                if (!isTouching) {
                    return;
                }

                isTouching = false;

                handleSwipe();
            }
        );


        carousel.addEventListener(
            "mouseenter",
            stopAutoplay
        );


        carousel.addEventListener(
            "mouseleave",
            startAutoplay
        );


        carousel.addEventListener(
            "focusin",
            stopAutoplay
        );


        carousel.addEventListener(
            "focusout",
            startAutoplay
        );


        showSlide(0);
        startAutoplay();

    });

</script>


<?php require __DIR__ . "/includes/login-modal.php"; ?>

<?php require __DIR__ . "/includes/inbox-widget.php"; ?>
<?php require __DIR__ . "/includes/terms-modal.php"; ?>

</body>

</html>
