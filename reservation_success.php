<?php

session_start();

require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/icons.php";


// ======================================================
// CUSTOMER LOGIN REQUIRED
// ======================================================

if (
    !isset($_SESSION["user_id"])
    || ($_SESSION["role"] ?? "") !== "customer"
) {

    header("Location: login.php");

    exit;
}


// ======================================================
// GET RESERVATION ID
// ======================================================

$reservationId =
    (int) ($_GET["id"] ?? 0);


if ($reservationId <= 0) {

    header(
        "Location: customer/dashboard.php"
    );

    exit;
}


// ======================================================
// GET CUSTOMER RESERVATION
// ======================================================

$stmt = $pdo->prepare("
    SELECT
        reservations.*,
        rooms.room_name,
        rooms.description,
        rooms.capacity
    FROM reservations

    INNER JOIN rooms
        ON reservations.room_id = rooms.id

    WHERE reservations.id = ?
    AND reservations.user_id = ?

    LIMIT 1
");

$stmt->execute([
    $reservationId,
    $_SESSION["user_id"]
]);

$reservation = $stmt->fetch();


// ======================================================
// RESERVATION DOES NOT BELONG TO CUSTOMER
// ======================================================

if (!$reservation) {

    header(
        "Location: customer/dashboard.php"
    );

    exit;
}


// ======================================================
// STATUS
// ======================================================

$status =
    $reservation["status"]
    ?? "pending";

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
        Reservation Successful | ARVE'S House
    </title>

    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
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

            /* Motion tokens */
            --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            --ease-in-out: cubic-bezier(0.77, 0, 0.175, 1);
            --ease-drawer: cubic-bezier(0.32, 0.72, 0, 1);
        }

        html {
            -webkit-tap-highlight-color: transparent;
            -webkit-text-size-adjust: 100%;
            text-size-adjust: 100%;
        }

        body {
            min-height: 100vh;
            min-height: 100svh;
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
        }

        a {
            color: inherit;
        }

        .navbar {
            min-height: 78px;
            padding: 0 7%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            position: sticky;
            top: 0;
            z-index: 1000;
            background: rgba(255,250,244,.90);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 11px;
            color: var(--text);
            text-decoration: none;
            font-size: 21px;
            font-weight: 800;
            touch-action: manipulation;
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

        .nav-links {
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .nav-links a {
            padding: 9px 12px;
            border-radius: 9px;
            color: var(--text);
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            touch-action: manipulation;
            transition:
                transform 140ms var(--ease-out),
                background-color 180ms ease,
                color 180ms ease;
        }

        @media (hover: hover) and (pointer: fine) {
            .nav-links a:hover {
                background: rgba(122,79,54,.08);
                color: var(--brown);
            }
        }

        .nav-links a:not(.nav-primary):focus-visible {
            background: rgba(122,79,54,.08);
            color: var(--brown);
        }

        .nav-links a:active {
            transform: scale(0.97);
        }

        .nav-primary {
            background:
                linear-gradient(
                    135deg,
                    var(--brown),
                    var(--brown-dark)
                );
            color: white !important;
            -webkit-user-select: none;
            user-select: none;
        }

        .page {
            width: 90%;
            max-width: 980px;
            margin: 48px auto 70px;
        }

        .success-card {
            overflow: hidden;
            border: 1px solid rgba(255,255,255,.90);
            border-radius: 28px;
            background: rgba(255,255,255,.90);
            backdrop-filter: blur(14px);
            box-shadow: var(--shadow);
            animation: arves-success-rise 300ms var(--ease-out) both;
        }

        .success-header {
            position: relative;
            padding: 50px 28px 44px;
            text-align: center;
            color: white;

            background:
                radial-gradient(
                    circle at 15% 15%,
                    rgba(255,255,255,.12),
                    transparent 30%
                ),
                linear-gradient(
                    135deg,
                    #7a4f36,
                    #513421
                );
        }

        .success-header::after {
            content: "";
            position: absolute;
            width: 260px;
            height: 260px;
            border-radius: 50%;
            right: -100px;
            bottom: -140px;
            background: rgba(255,255,255,.06);
        }

        .success-icon {
            width: 82px;
            height: 82px;
            margin: 0 auto 18px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: #fff;
            color: #166534;
            font-size: 36px;
            font-weight: 900;
            box-shadow: 0 12px 30px rgba(0,0,0,.16);
            animation: arves-success-pop 300ms var(--ease-out) 120ms both;
        }

        /* One-time confirmation entrance (rare page, a little delight) */
        @keyframes arves-success-rise {
            from {
                opacity: 0;
                transform: translateY(8px);
            }

            to {
                opacity: 1;
                transform: none;
            }
        }

        @keyframes arves-success-pop {
            0% {
                opacity: 0;
                transform: scale(0.95);
            }

            60% {
                opacity: 1;
                transform: scale(1.04);
            }

            100% {
                opacity: 1;
                transform: none;
            }
        }

        .success-header span {
            display: inline-block;
            margin-bottom: 9px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: #f3dcc8;
        }

        .success-header h1 {
            font-family:
                Georgia,
                "Times New Roman",
                serif;
            font-size:
                clamp(
                    34px,
                    5vw,
                    48px
                );
            margin-bottom: 10px;
        }

        .success-header p {
            max-width: 610px;
            margin: auto;
            color: #eadfd8;
            font-size: 13px;
            line-height: 1.6;
        }

        .content {
            padding: 30px;
        }

        .notice {
            padding: 15px 16px;
            margin-bottom: 24px;
            border-radius: 12px;
            border: 1px solid #fde68a;
            background: #fef3c7;
            color: #92400e;
            font-size: 12px;
            line-height: 1.6;
        }

        .reservation-number {
            text-align: center;
            margin-bottom: 24px;
            color: var(--muted);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .8px;
        }

        .reservation-number strong {
            display: block;
            margin-top: 4px;
            font-size: 24px;
            color: var(--brown-dark);
            letter-spacing: 0;
        }

        .details {
            display: grid;
            grid-template-columns:
                repeat(2, 1fr);
            gap: 12px;
        }

        .detail-box {
            padding: 14px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: rgba(255,250,244,.60);
        }

        .detail-label {
            display: block;
            margin-bottom: 5px;
            color: var(--muted);
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .detail-value {
            color: var(--text);
            font-size: 13px;
            font-weight: 800;
        }

        .total-box {
            margin-top: 16px;
            padding: 18px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            background:
                linear-gradient(
                    135deg,
                    var(--brown),
                    var(--brown-dark)
                );
            color: white;
        }

        .total-box span {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .6px;
            opacity: .82;
        }

        .total-box strong {
            font-size: 26px;
        }

        .status-container {
            margin: 20px 0 6px;
            text-align: center;
        }

        .status {
            display: inline-flex;
            align-items: center;
            min-height: 30px;
            padding: 0 12px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .5px;
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

        .next-step {
            margin-top: 22px;
            padding: 16px;
            border-radius: 12px;
            background: rgba(122,79,54,.06);
            color: var(--muted);
            font-size: 11px;
            line-height: 1.6;
        }

        .actions {
            margin-top: 24px;
            display: flex;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            min-height: 46px;
            padding: 0 18px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 12px;
            font-weight: 800;
            touch-action: manipulation;
            -webkit-user-select: none;
            user-select: none;
            transition: transform 140ms var(--ease-out);
        }

        @media (hover: hover) and (pointer: fine) {
            .btn:hover {
                transform: translateY(-1px);
            }
        }

        .btn:focus-visible {
            transform: translateY(-1px);
        }

        .btn:active:not([aria-disabled="true"]) {
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
                0 9px 22px
                rgba(90,56,38,.18);
        }

        .btn-secondary {
            border: 1px solid var(--border);
            background: rgba(255,255,255,.72);
            color: var(--brown-dark);
        }

        .btn-payment {
            background:
                linear-gradient(
                    135deg,
                    #b98058,
                    var(--brown)
                );
            color: white;
        }

        footer {
            margin-top: 70px;
            padding: 36px 7% 22px;
            background: #2d1d16;
            color: #d8c9bf;
        }

        .footer-inner {
            max-width: 980px;
            margin: auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 25px;
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
            color: #bdaea5;
            font-size: 11px;
        }

        @media (max-width: 650px) {
            .navbar {
                padding: 0 5%;
            }

            .brand small {
                display: none;
            }

            .nav-links a:not(.nav-primary) {
                display: none;
            }

            .page {
                width: 92%;
                margin-top: 28px;
            }

            .content {
                padding: 22px 16px;
            }

            .details {
                grid-template-columns: 1fr;
            }

            .total-box {
                align-items: flex-start;
                flex-direction: column;
            }

            .footer-inner {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        /* Safe areas (viewport-fit=cover); after the 650px block so its padding shorthand can't reset them */
        .navbar {
            min-height: calc(78px + env(safe-area-inset-top, 0px));
            padding-top: env(safe-area-inset-top, 0px);
        }

        footer {
            padding-bottom: calc(22px + env(safe-area-inset-bottom, 0px));
        }

        @media (prefers-reduced-motion: reduce) {
            html {
                scroll-behavior: auto;
            }

            /* Drop movement: hover lift, press scale, entrance rise/pop */
            .btn,
            .nav-links a,
            .success-card,
            .success-icon {
                transform: none !important;
            }

            *,
            *::before,
            *::after {
                animation-duration: 1ms !important;
                animation-iteration-count: 1 !important;
            }

            /* Keep a gentle opacity-only fade for the one-time entrance */
            .success-card,
            .success-icon {
                animation-duration: 240ms !important;
            }
        }

    </style>

<?php require __DIR__ . "/includes/glass.php"; ?>
</head>

<body>


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


    <div class="nav-links">

        <a href="index.php">
            Home
        </a>

        <a href="rooms.php">
            Rooms
        </a>

        <a
            href="customer/dashboard.php"
            class="nav-primary"
        >
            My Reservations
        </a>

    </div>

</nav>


<main class="page">

    <section class="success-card">


        <div class="success-header">

            <div class="success-icon">
                ✓
            </div>

            <span>
                Booking Received
            </span>

            <h1>
                Reservation Submitted
            </h1>

            <p>
                Your reservation has been saved successfully.
                Review your booking details below and continue
                to payment when you're ready.
            </p>

        </div>


        <div class="content">


            <div class="notice">

                Your reservation is currently
                <strong><?= htmlspecialchars(ucfirst($status)) ?></strong>.

                <?php if ($status === "pending"): ?>

                    Complete your payment and wait for administrator
                    verification. Once the payment is verified,
                    your reservation will be confirmed automatically.

                <?php elseif ($status === "confirmed"): ?>

                    Your reservation is confirmed.

                <?php endif; ?>

            </div>


            <div class="reservation-number">

                Reservation Number

                <strong>
                    #<?= (int)
                        $reservation["id"]
                    ?>
                </strong>

            </div>


            <div class="details">


                <div class="detail-box">

                    <span class="detail-label">
                        Room
                    </span>

                    <div class="detail-value">
                        <?= htmlspecialchars(
                            $reservation["room_name"]
                        ) ?>
                    </div>

                </div>


                <div class="detail-box">

                    <span class="detail-label">
                        Guests
                    </span>

                    <div class="detail-value">

                        <?= (int)
                            $reservation["guests"]
                        ?>

                        guest<?= (int)
                            $reservation["guests"] !== 1
                            ? "s"
                            : ""
                        ?>

                    </div>

                </div>


                <div class="detail-box">

                    <span class="detail-label">
                        Check-in
                    </span>

                    <div class="detail-value">
                        <?= htmlspecialchars(
                            $reservation["check_in"]
                        ) ?>
                    </div>

                </div>


                <div class="detail-box">

                    <span class="detail-label">
                        Check-out
                    </span>

                    <div class="detail-value">
                        <?= htmlspecialchars(
                            $reservation["check_out"]
                        ) ?>
                    </div>

                </div>


                <div class="detail-box">

                    <span class="detail-label">
                        Number of Nights
                    </span>

                    <div class="detail-value">
                        <?= (int)
                            $reservation["total_nights"]
                        ?>
                    </div>

                </div>


                <div class="detail-box">

                    <span class="detail-label">
                        Price per Night
                    </span>

                    <div class="detail-value">

                        ₱<?= number_format(
                            (float)
                            $reservation["price_per_night"],
                            2
                        ) ?>

                    </div>

                </div>


            </div>


            <div class="total-box">

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


            <div class="status-container">

                <span
                    class="status <?= htmlspecialchars(
                        $status
                    ) ?>"
                >
                    <?= htmlspecialchars(
                        ucfirst($status)
                    ) ?>
                </span>

            </div>


            <?php if ($status === "pending"): ?>

                <div class="next-step">

                    <strong>
                        Next step:
                    </strong>

                    Continue to payment for this reservation.
                    Payment starts as pending verification.
                    After the administrator verifies the payment,
                    the reservation will automatically change
                    to confirmed.

                </div>

            <?php endif; ?>


            <div class="actions">


                <?php if ($status === "pending"): ?>

                    <a
                        href="payment.php?reservation_id=<?= (int)
                            $reservation["id"]
                        ?>"
                        class="btn btn-payment"
                    >
                        <?= icon("credit-card") ?> Pay Now
                    </a>

                <?php endif; ?>


                <a
                    href="customer/dashboard.php"
                    class="btn btn-primary"
                >
                    My Reservations
                </a>


                <a
                    href="rooms.php"
                    class="btn btn-secondary"
                >
                    Browse More Rooms
                </a>


            </div>


        </div>

    </section>

</main>


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
                Reservation Confirmation
            </p>

            <p>
                Comfort · Relax · Stay
            </p>

        </div>

    </div>

</footer>


<?php require __DIR__ . "/includes/inbox-widget.php"; ?>
<?php require __DIR__ . "/includes/terms-modal.php"; ?>

</body>

</html>