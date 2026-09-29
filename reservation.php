<?php

session_start();

require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/icons.php";
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/availability.php";
require_once __DIR__ . "/includes/activity.php";

ensure_auth_schema($pdo);
ensure_availability_schema($pdo);


// =====================================================
// GET ROOM ID FIRST
// =====================================================

$roomId = (int) (
    $_GET["room_id"]
    ?? $_POST["room_id"]
    ?? 0
);


// =====================================================
// CHECK LOGIN
// =====================================================

if (!isset($_SESSION["user_id"])) {

    $returnUrl = "reservation.php";

    if ($roomId > 0) {
        $returnUrl .= "?room_id=" . $roomId;
    }

    header(
        "Location: login.php?redirect=" .
        urlencode($returnUrl)
    );

    exit;
}


// =====================================================
// ONLY CUSTOMER ACCOUNTS CAN BOOK
// =====================================================

if (
    ($_SESSION["role"] ?? "") !== "customer"
) {

    header(
        "Location: index.php?customer_required=1"
    );

    exit;
}


// =====================================================
// CHECK ROOM ID
// =====================================================

if ($roomId <= 0) {

    header("Location: rooms.php");

    exit;
}


// =====================================================
// GET ROOM
// =====================================================

$stmt = $pdo->prepare(
    "SELECT *
     FROM rooms
     WHERE id = ?
     LIMIT 1"
);

$stmt->execute([$roomId]);

$room = $stmt->fetch();


if (!$room) {

    header("Location: rooms.php");

    exit;
}


// =====================================================
// CHECK ROOM STATUS
// =====================================================

if ($room["status"] !== "available") {

    header("Location: rooms.php");

    exit;
}


// =====================================================
// FORM DEFAULTS
// =====================================================

$error = "";

$selectedCheckIn =
    $_GET["check_in"]
    ?? $_POST["check_in"]
    ?? "";

$selectedCheckOut =
    $_GET["check_out"]
    ?? $_POST["check_out"]
    ?? "";

$selectedGuests =
    (int) (
        $_POST["guests"]
        ?? 0
    );

$today =
    date("Y-m-d");


// =====================================================
// ROOM PREVIEW IMAGE
// =====================================================

$roomImage =
    "uploads/rooms/room_" .
    $roomId .
    "_1.jpg";

$roomImageAbsolute =
    __DIR__ . "/" . $roomImage;

$hasRoomImage =
    file_exists(
        $roomImageAbsolute
    );


// =====================================================
// HANDLE RESERVATION
// =====================================================

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["confirm_reservation"])
) {

    $checkIn =
        trim(
            $_POST["check_in"]
            ?? ""
        );

    $checkOut =
        trim(
            $_POST["check_out"]
            ?? ""
        );

    $guests =
        (int) (
            $_POST["guests"]
            ?? 0
        );


    $selectedCheckIn =
        $checkIn;

    $selectedCheckOut =
        $checkOut;

    $selectedGuests =
        $guests;

    $acceptedTerms =
        ($_POST["accept_terms"] ?? "") === "1";


    if (
        $checkIn === ""
        || $checkOut === ""
        || $guests < 1
    ) {

        $error =
            "Please complete all reservation details.";

    } elseif (
        !$acceptedTerms
    ) {

        $error =
            "Please agree to the Terms and Conditions to confirm your reservation.";

    } else {

        $checkInDate =
            DateTime::createFromFormat(
                "Y-m-d",
                $checkIn
            );

        $checkOutDate =
            DateTime::createFromFormat(
                "Y-m-d",
                $checkOut
            );


        $validCheckIn =
            $checkInDate
            && $checkInDate->format("Y-m-d")
                === $checkIn;

        $validCheckOut =
            $checkOutDate
            && $checkOutDate->format("Y-m-d")
                === $checkOut;


        if (
            !$validCheckIn
            || !$validCheckOut
        ) {

            $error =
                "Please enter valid dates.";

        } elseif (
            $checkIn < $today
        ) {

            $error =
                "Check-in date cannot be in the past.";

        } elseif (
            $checkOutDate <= $checkInDate
        ) {

            $error =
                "Check-out date must be after check-in date.";

        } elseif (
            $guests
            > (int) $room["capacity"]
        ) {

            $error =
                "This room can accommodate a maximum of " .
                (int) $room["capacity"] .
                " guest(s).";

        } else {

            // ==========================================
            // FINAL AVAILABILITY CHECK
            // (other reservations and dates closed by the admin)
            // ==========================================

            $unavailableReason =
                room_unavailable_reason(
                    $pdo,
                    $roomId,
                    $checkIn,
                    $checkOut
                );


            if ($unavailableReason !== "") {

                $error =
                    $unavailableReason;

            } else {

                // ======================================
                // CALCULATE TOTAL
                // ======================================

                $totalNights =
                    $checkInDate
                        ->diff(
                            $checkOutDate
                        )
                        ->days;

                $pricePerNight =
                    (float)
                    $room["price"];

                $totalAmount =
                    $totalNights
                    * $pricePerNight;


                // ======================================
                // SAVE RESERVATION
                // ======================================

                try {

                    $insertStmt =
                        $pdo->prepare(
                            "INSERT INTO reservations
                            (
                                user_id,
                                room_id,
                                check_in,
                                check_out,
                                guests,
                                price_per_night,
                                total_nights,
                                total_amount,
                                status,
                                terms_accepted_at
                            )
                            VALUES
                            (
                                ?,
                                ?,
                                ?,
                                ?,
                                ?,
                                ?,
                                ?,
                                ?,
                                'pending',
                                ?
                            )"
                        );

                    $insertStmt->execute([
                        $_SESSION["user_id"],
                        $roomId,
                        $checkIn,
                        $checkOut,
                        $guests,
                        $pricePerNight,
                        $totalNights,
                        $totalAmount,
                        utc_now()
                    ]);

                    $reservationId = (int) $pdo->lastInsertId();

                    // shows up in the admin's notifications and activity log
                    log_activity(
                        $pdo,
                        "reservation.created",
                        ($_SESSION["full_name"] ?? "A customer") . " booked " . $room["room_name"] . ", "
                            . date("M j", strtotime($checkIn)) . " to " . date("M j", strtotime($checkOut)),
                        [
                            "entity_type" => "reservation",
                            "entity_id" => $reservationId,
                            "link" => "reservations.php?q=" . $reservationId,
                            "notify" => true,
                        ]
                    );


                    header(
                        "Location: reservation_success.php?id=" .
                        $reservationId
                    );

                    exit;

                } catch (
                    PDOException $exception
                ) {

                    $error =
                        "Unable to save the reservation. Please try again.";
                }
            }
        }
    }
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

<meta
    name="theme-color"
    content="#fffaf4"
>

<title>
    Reserve <?= htmlspecialchars($room["room_name"]) ?> | ARVE'S House
</title>

<style>

* {
    box-sizing: border-box;
}

html {
    scroll-behavior: smooth;

    -webkit-tap-highlight-color: transparent;

    -webkit-text-size-adjust: 100%;
}

:root {
    --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
    --ease-in-out: cubic-bezier(0.77, 0, 0.175, 1);
    --ease-drawer: cubic-bezier(0.32, 0.72, 0, 1);

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
}

body {

    margin: 0;

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


/* ==================================================
   NAVBAR
================================================== */

.navbar {

    min-height: 78px;

    min-height: calc(78px + env(safe-area-inset-top, 0px));

    padding:
        0 7%;

    padding-top:
        env(safe-area-inset-top, 0px);

    display: flex;

    align-items: center;

    justify-content:
        space-between;

    gap: 20px;

    position: sticky;

    top: 0;

    z-index: 1000;

    background:
        rgba(255,250,244,.90);

    backdrop-filter:
        blur(16px);

    border-bottom:
        1px solid
        var(--border);
}

.brand {

    display: flex;

    align-items: center;

    gap: 11px;

    text-decoration: none;

    color:
        var(--text);

    font-size: 21px;

    font-weight: 800;

    touch-action: manipulation;
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

    color:
        var(--muted);

    font-size: 9px;

    font-weight: normal;

    letter-spacing: 1.2px;
}

.nav-actions {

    display: flex;

    align-items: center;

    gap: 8px;
}

.nav-link {

    padding:
        9px 13px;

    border-radius: 9px;

    text-decoration: none;

    color:
        var(--text);

    font-size: 13px;

    font-weight: 700;

    touch-action: manipulation;

    -webkit-user-select: none;

    user-select: none;

    transition:
        transform 140ms var(--ease-out),
        background-color 180ms ease,
        color 180ms ease;
}

@media (hover: hover) and (pointer: fine) {

    .nav-link:hover {

        background:
            rgba(122,79,54,.08);

        color:
            var(--brown);
    }
}

.nav-link:focus-visible {

    background:
        rgba(122,79,54,.08);

    color:
        var(--brown);
}

.nav-link:active {

    transform: scale(0.97);
}

.nav-primary {

    background:
        linear-gradient(
            135deg,
            var(--brown),
            var(--brown-dark)
        );

    color: white;

    /* gradient cannot interpolate: keep its hover swap instant
       so white text never fades over a light background */
    transition:
        transform 140ms var(--ease-out);
}


/* ==================================================
   PAGE
================================================== */

.page {

    max-width: 1180px;

    margin: auto;

    padding:
        55px 20px 75px;

    padding-left:
        max(20px, env(safe-area-inset-left, 0px));

    padding-right:
        max(20px, env(safe-area-inset-right, 0px));
}

.page-heading {

    text-align: center;

    max-width: 700px;

    margin:
        0 auto 32px;
}

.page-heading span {

    color:
        var(--brown);

    font-size: 10px;

    font-weight: 800;

    letter-spacing: 4px;

    text-transform:
        uppercase;
}

.page-heading h1 {

    margin:
        8px 0 10px;

    font-family:
        Georgia,
        "Times New Roman",
        serif;

    font-size:
        clamp(
            37px,
            5vw,
            54px
        );

    line-height: 1.08;
}

.page-heading p {

    margin: 0;

    color:
        var(--muted);

    font-size: 14px;
}


/* ==================================================
   BOOKING LAYOUT
================================================== */

.booking-grid {

    display: grid;

    grid-template-columns:
        .9fr 1.1fr;

    gap: 28px;

    align-items: start;
}


/* ==================================================
   ROOM SUMMARY
================================================== */

.room-summary {

    position: sticky;

    top: 105px;

    top: calc(105px + env(safe-area-inset-top, 0px));

    overflow: hidden;

    border:
        1px solid
        rgba(255,255,255,.90);

    border-radius: 24px;

    background:
        rgba(255,255,255,.88);

    box-shadow:
        var(--shadow);

    backdrop-filter:
        blur(14px);
}

.room-photo {

    height: 285px;

    display: flex;

    align-items: center;

    justify-content: center;

    overflow: hidden;

    background:
        linear-gradient(
            145deg,
            #c8a17f,
            #77533d
        );
}

.room-photo img {

    width: 100%;

    height: 100%;

    object-fit: cover;

    display: block;
}

.room-photo-fallback {

    color: white;

    font-size: 90px;
}

.room-summary-body {

    padding: 24px;
}

.room-badge {

    display: inline-block;

    margin-bottom: 11px;

    padding:
        5px 9px;

    border-radius: 20px;

    background: #dcfce7;

    color: #166534;

    font-size: 10px;

    font-weight: 800;
}

.room-summary h2 {

    margin:
        0 0 8px;

    font-family:
        Georgia,
        "Times New Roman",
        serif;

    font-size: 27px;
}

.room-summary-description {

    margin:
        0 0 18px;

    color:
        var(--muted);

    font-size: 12px;

    line-height: 1.65;
}

.room-facts {

    display: grid;

    grid-template-columns:
        1fr 1fr;

    gap: 10px;

    margin-top: 15px;
}

.fact {

    padding: 13px;

    border:
        1px solid
        var(--border);

    border-radius: 12px;

    background:
        rgba(255,250,244,.65);
}

.fact span {

    display: block;

    margin-bottom: 4px;

    color:
        var(--muted);

    font-size: 10px;

    text-transform:
        uppercase;

    letter-spacing: .6px;
}

.fact strong {

    font-size: 14px;

    color:
        var(--brown-dark);
}


/* ==================================================
   RESERVATION FORM
================================================== */

.form-card {

    padding: 28px;

    border:
        1px solid
        rgba(255,255,255,.90);

    border-radius: 24px;

    background:
        rgba(255,255,255,.90);

    backdrop-filter:
        blur(14px);

    box-shadow:
        var(--shadow);
}

.form-card h2 {

    margin:
        0 0 7px;

    font-family:
        Georgia,
        "Times New Roman",
        serif;

    font-size: 27px;
}

.form-subtitle {

    margin:
        0 0 23px;

    color:
        var(--muted);

    font-size: 12px;
}

.error {

    margin-bottom: 20px;

    padding:
        13px 15px;

    border:
        1px solid #fecaca;

    border-radius: 10px;

    background: #fee2e2;

    color: #991b1b;

    font-size: 12px;

    /* one-time entrance after a failed submit (rendered once per load) */
    animation:
        reserveAlertIn 240ms var(--ease-out) both;
}

@keyframes reserveAlertIn {

    from {

        opacity: 0;

        transform: translateY(-4px);
    }
}

.form-grid {

    display: grid;

    grid-template-columns:
        1fr 1fr;

    gap: 17px;
}

.form-group {

    margin-bottom: 17px;
}

.form-group.full {

    grid-column:
        1 / -1;
}

label {

    display: block;

    margin-bottom: 7px;

    color:
        var(--brown-dark);

    font-size: 11px;

    font-weight: 800;

    letter-spacing: .4px;
}

input,
select {

    width: 100%;

    min-height: 49px;

    padding:
        0 13px;

    border:
        1px solid
        var(--border);

    border-radius: 10px;

    background:
        rgba(255,255,255,.92);

    color:
        var(--text);

    font-size: 13px;

    outline: none;
}

input:focus,
select:focus {

    border-color:
        rgba(122,79,54,.55);

    box-shadow:
        0 0 0 3px
        rgba(122,79,54,.08);
}

/* iOS zooms into inputs under 16px; desktop keeps 13px */
@media (pointer: coarse) {

    input,
    select,
    textarea {

        font-size: 16px;
    }
}


/* ==================================================
   LIVE SUMMARY
================================================== */

.total-card {

    margin-top: 8px;

    padding: 18px;

    border:
        1px solid
        var(--border);

    border-radius: 14px;

    background:
        linear-gradient(
            135deg,
            rgba(255,250,244,.95),
            rgba(244,231,217,.88)
        );
}

.total-line {

    display: flex;

    justify-content:
        space-between;

    gap: 15px;

    margin-bottom: 8px;

    color:
        var(--muted);

    font-size: 12px;
}

.total-line strong {

    color:
        var(--text);
}

.total-main {

    margin-top: 12px;

    padding-top: 12px;

    border-top:
        1px solid
        var(--border);

    display: flex;

    justify-content:
        space-between;

    align-items: center;

    gap: 15px;
}

.total-main span {

    font-size: 12px;

    font-weight: 800;

    color:
        var(--brown-dark);
}

.total-main strong {

    color:
        var(--brown);

    font-size: 24px;
}


/* ==================================================
   BUTTONS
================================================== */

.terms-check {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-top: 18px;
}

.terms-check input {
    flex: none;
    width: 20px;
    height: 20px;
    margin: 1px 0 0;
    accent-color: var(--brown);
    cursor: pointer;
}

.terms-check label {
    display: inline;
    margin: 0;
    font-size: 13px;
    font-weight: 400;
    letter-spacing: normal;
    line-height: 1.5;
    color: var(--muted);
    cursor: pointer;
}

.terms-check a {
    color: var(--brown);
    font-weight: 700;
    text-decoration: underline;
    text-underline-offset: 2px;
}

.form-actions {

    display: grid;

    grid-template-columns:
        1fr auto;

    gap: 12px;

    margin-top: 22px;
}

.submit-button {

    min-height: 49px;

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

    touch-action: manipulation;

    -webkit-user-select: none;

    user-select: none;

    transition:
        transform 140ms var(--ease-out),
        box-shadow 180ms ease;
}

@media (hover: hover) and (pointer: fine) {

    .submit-button:hover {

        transform:
            translateY(-1px);

        box-shadow:
            0 10px 24px
            rgba(90,56,38,.20);
    }
}

.submit-button:focus-visible {

    box-shadow:
        0 10px 24px
        rgba(90,56,38,.20);
}

.submit-button:active:not(:disabled) {

    transform: scale(0.97);
}

.back-button {

    min-height: 49px;

    padding:
        0 18px;

    border:
        1px solid
        var(--border);

    border-radius: 10px;

    display: flex;

    align-items: center;

    justify-content: center;

    background:
        rgba(255,255,255,.65);

    color:
        var(--brown-dark);

    text-decoration: none;

    font-size: 12px;

    font-weight: 800;

    touch-action: manipulation;

    -webkit-user-select: none;

    user-select: none;

    transition:
        transform 140ms var(--ease-out);
}

.back-button:active {

    transform: scale(0.97);
}


/* ==================================================
   NOTE
================================================== */

.note {

    display: flex;

    gap: 10px;

    margin-top: 18px;

    padding:
        13px;

    border-radius: 10px;

    background:
        rgba(122,79,54,.06);

    color:
        var(--muted);

    font-size: 11px;

    line-height: 1.5;
}


/* ==================================================
   RESPONSIVE
================================================== */

@media (
    max-width: 900px
) {

    .booking-grid {

        grid-template-columns:
            1fr;
    }

    .room-summary {

        position: static;
    }

    .room-photo {

        height: 330px;
    }
}

@media (
    max-width: 650px
) {

    .navbar {

        padding:
            0 5%;

        padding-top:
            env(safe-area-inset-top, 0px);
    }

    .brand small {

        display: none;
    }

    .nav-actions .nav-link:not(.nav-primary) {

        display: none;
    }

    .page {

        padding:
            40px 15px 55px;

        padding-left:
            max(15px, env(safe-area-inset-left, 0px));

        padding-right:
            max(15px, env(safe-area-inset-right, 0px));
    }

    .form-grid {

        grid-template-columns:
            1fr;
    }

    .form-group.full {

        grid-column:
            auto;
    }

    .form-actions {

        grid-template-columns:
            1fr;
    }

    .room-facts {

        grid-template-columns:
            1fr;
    }

    .room-photo {

        height: 240px;
    }
}


/* ==================================================
   REDUCED MOTION
   Fewer/gentler, not zero: keep fades and colour,
   drop the hover lift, press scale and alert slide.
================================================== */

@media (prefers-reduced-motion: reduce) {

    html {
        scroll-behavior: auto;
    }

    .submit-button,
    .submit-button:hover,
    .submit-button:active,
    .back-button:active,
    .nav-link:active,
    .error {

        transform: none !important;
    }

    *,
    *::before,
    *::after {

        animation-duration: 1ms !important;

        animation-iteration-count: 1 !important;
    }

    /* keep the alert's opacity fade (its movement is removed above) */
    .error {

        animation-duration: 240ms !important;
    }
}

</style>

<style>
/* Phone polish: smaller placeholder icon */
@media (max-width: 560px) {
    .room-photo-fallback { font-size: 52px; }
}
</style>
<?php require __DIR__ . "/includes/glass.php"; ?>
</head>

<body>


<!-- =====================================================
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


    <div class="nav-actions">

        <a
            href="rooms.php"
            class="nav-link"
        >
            Rooms
        </a>

        <a
            href="customer/dashboard.php"
            class="
                nav-link
                nav-primary
            "
        >
            My Reservations
        </a>

    </div>

</nav>


<!-- =====================================================
     PAGE
====================================================== -->

<main class="page">


    <div class="page-heading">

        <span>
            Complete Your Booking
        </span>

        <h1>
            Reserve Your Stay
        </h1>

        <p>
            Choose your dates and number of guests.
            Your room will be held as a pending reservation
            until the payment process is completed.
        </p>

    </div>


    <div class="booking-grid">


        <!-- =================================================
             ROOM SUMMARY
        ================================================== -->

        <aside class="room-summary">


            <div class="room-photo">


                <?php if ($hasRoomImage): ?>

                    <img
                        src="<?= htmlspecialchars(
                            $roomImage
                        ) ?>"
                        alt="<?= htmlspecialchars(
                            $room["room_name"]
                        ) ?>"
                    >

                <?php else: ?>

                    <div class="room-photo-fallback">
                        <?= icon("bed") ?>
                    </div>

                <?php endif; ?>


            </div>


            <div class="room-summary-body">


                <span class="room-badge">
                    ✓ Available Room
                </span>


                <h2>
                    <?= htmlspecialchars(
                        $room["room_name"]
                    ) ?>
                </h2>


                <p class="room-summary-description">

                    <?= nl2br(
                        htmlspecialchars(
                            $room["description"]
                            ?? "Comfortable room available for your stay."
                        )
                    ) ?>

                </p>


                <div class="room-facts">


                    <div class="fact">

                        <span>
                            Capacity
                        </span>

                        <strong>

                            <?= icon("users") ?>
                            <?= (int)
                                $room["capacity"]
                            ?>

                            guest<?= (int)
                                $room["capacity"] !== 1
                                ? "s"
                                : ""
                            ?>

                        </strong>

                    </div>


                    <div class="fact">

                        <span>
                            Price
                        </span>

                        <strong>

                            ₱<?= number_format(
                                (float)
                                $room["price"],
                                2
                            ) ?>

                            / night

                        </strong>

                    </div>


                </div>


            </div>


        </aside>


        <!-- =================================================
             FORM
        ================================================== -->

        <section class="form-card">


            <h2>
                Reservation Details
            </h2>


            <p class="form-subtitle">
                Confirm your stay information before continuing.
            </p>


            <?php if ($error !== ""): ?>

                <div class="error">

                    <?= icon("alert") ?>
                    <?= htmlspecialchars(
                        $error
                    ) ?>

                </div>

            <?php endif; ?>


            <form method="POST">


                <input
                    type="hidden"
                    name="room_id"
                    value="<?= (int)
                        $roomId
                    ?>"
                >


                <div class="form-grid">


                    <div class="form-group">

                        <label for="check_in">
                            CHECK-IN
                        </label>

                        <input
                            type="date"
                            id="check_in"
                            name="check_in"
                            min="<?= htmlspecialchars(
                                $today
                            ) ?>"
                            value="<?= htmlspecialchars(
                                $selectedCheckIn
                            ) ?>"
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
                            min="<?= htmlspecialchars(
                                $today
                            ) ?>"
                            value="<?= htmlspecialchars(
                                $selectedCheckOut
                            ) ?>"
                            required
                        >

                    </div>


                    <?php $calendarRoomId = $roomId; require __DIR__ . "/includes/availability-calendar.php"; ?>


                    <div class="form-group full">

                        <label for="guests">
                            NUMBER OF GUESTS
                        </label>

                        <select
                            id="guests"
                            name="guests"
                            required
                        >

                            <option value="">
                                Select guests
                            </option>


                            <?php for (
                                $guestNumber = 1;
                                $guestNumber <=
                                    (int)
                                    $room["capacity"];
                                $guestNumber++
                            ): ?>

                                <option
                                    value="<?= $guestNumber ?>"
                                    <?= $selectedGuests ===
                                        $guestNumber
                                        ? "selected"
                                        : ""
                                    ?>
                                >

                                    <?= $guestNumber ?>

                                    <?= $guestNumber === 1
                                        ? "Guest"
                                        : "Guests"
                                    ?>

                                </option>

                            <?php endfor; ?>


                        </select>

                    </div>


                </div>


                <!-- =========================================
                     LIVE PRICE SUMMARY
                ========================================== -->

                <div class="total-card">


                    <div class="total-line">

                        <span>
                            Price per night
                        </span>

                        <strong>

                            ₱<?= number_format(
                                (float)
                                $room["price"],
                                2
                            ) ?>

                        </strong>

                    </div>


                    <div class="total-line">

                        <span>
                            Total nights
                        </span>

                        <strong id="nightCount">
                            0
                        </strong>

                    </div>


                    <div class="total-main">

                        <span>
                            Estimated Total
                        </span>

                        <strong id="totalAmount">
                            ₱0.00
                        </strong>

                    </div>


                </div>


                <div class="note">

                    <span>
                        ℹ️
                    </span>

                    <span>
                        Your reservation will initially be marked
                        <strong>Pending</strong>.
                        After payment is verified by the administrator,
                        the reservation will be confirmed automatically.
                    </span>

                </div>


                <div class="terms-check">

                    <input
                        type="checkbox"
                        id="accept_terms"
                        name="accept_terms"
                        value="1"
                        required
                    >

                    <label for="accept_terms">
                        I have read and agree to the
                        <a href="#terms">Terms and Conditions</a>,
                        including the payment, cancellation and house rules.
                    </label>

                </div>


                <div class="form-actions">


                    <button
                        type="submit"
                        name="confirm_reservation"
                        class="submit-button"
                    >
                        Confirm Reservation →
                    </button>


                    <a
                        href="rooms.php"
                        class="back-button"
                    >
                        ← Back to Rooms
                    </a>


                </div>


            </form>


        </section>


    </div>


</main>


<script>

// ======================================================
// ELEMENTS
// ======================================================

const checkInInput =
    document.getElementById(
        "check_in"
    );

const checkOutInput =
    document.getElementById(
        "check_out"
    );

const nightCount =
    document.getElementById(
        "nightCount"
    );

const totalAmount =
    document.getElementById(
        "totalAmount"
    );

const pricePerNight =
    <?= json_encode(
        (float)
        $room["price"]
    ) ?>;


// ======================================================
// CHECK-OUT MINIMUM
// ======================================================

function updateCheckoutMinimum() {

    if (
        !checkInInput.value
    ) {

        return;
    }


    const selectedDate =
        new Date(
            checkInInput.value +
            "T00:00:00"
        );


    selectedDate.setDate(
        selectedDate.getDate()
        + 1
    );


    const year =
        selectedDate
            .getFullYear();

    const month =
        String(
            selectedDate
                .getMonth()
            + 1
        ).padStart(
            2,
            "0"
        );

    const day =
        String(
            selectedDate
                .getDate()
        ).padStart(
            2,
            "0"
        );


    const minimumCheckout =
        `${year}-${month}-${day}`;


    checkOutInput.min =
        minimumCheckout;


    if (
        checkOutInput.value
        &&
        checkOutInput.value
            < minimumCheckout
    ) {

        checkOutInput.value =
            "";
    }
}


// ======================================================
// LIVE TOTAL
// ======================================================

function calculateTotal() {

    if (
        !checkInInput.value
        ||
        !checkOutInput.value
    ) {

        nightCount.textContent =
            "0";

        totalAmount.textContent =
            "₱0.00";

        return;
    }


    const checkIn =
        new Date(
            checkInInput.value +
            "T00:00:00"
        );

    const checkOut =
        new Date(
            checkOutInput.value +
            "T00:00:00"
        );


    const milliseconds =
        checkOut.getTime()
        - checkIn.getTime();


    const nights =
        Math.round(
            milliseconds
            /
            (
                1000
                * 60
                * 60
                * 24
            )
        );


    if (
        nights <= 0
    ) {

        nightCount.textContent =
            "0";

        totalAmount.textContent =
            "₱0.00";

        return;
    }


    const total =
        nights
        * pricePerNight;


    nightCount.textContent =
        nights;


    totalAmount.textContent =
        "₱" +
        total.toLocaleString(
            "en-PH",
            {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }
        );
}


// ======================================================
// EVENTS
// ======================================================

checkInInput.addEventListener(
    "change",
    () => {

        updateCheckoutMinimum();

        calculateTotal();
    }
);


checkOutInput.addEventListener(
    "change",
    calculateTotal
);


// ======================================================
// INITIAL LOAD
// ======================================================

updateCheckoutMinimum();

calculateTotal();

</script>


<?php require __DIR__ . "/includes/inbox-widget.php"; ?>
<?php require __DIR__ . "/includes/terms-modal.php"; ?>

</body>

</html>
