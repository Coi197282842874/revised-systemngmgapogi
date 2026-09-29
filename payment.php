<?php

session_start();

require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/icons.php";
require_once __DIR__ . "/includes/paymongo.php";
require_once __DIR__ . "/includes/activity.php";

ensure_paymongo_schema($pdo);


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

$user_id = (int) $_SESSION["user_id"];


// ======================================================
// GET RESERVATION ID
// ======================================================

$reservation_id =
    (int) ($_GET["reservation_id"] ?? 0);

if ($reservation_id <= 0) {
    header("Location: customer/dashboard.php");
    exit;
}


// ======================================================
// GET CUSTOMER RESERVATION
// ======================================================

$stmt = $pdo->prepare("
    SELECT
        reservations.*,
        rooms.room_name
    FROM reservations

    INNER JOIN rooms
        ON reservations.room_id = rooms.id

    WHERE reservations.id = ?
    AND reservations.user_id = ?

    LIMIT 1
");

$stmt->execute([
    $reservation_id,
    $user_id
]);

$reservation = $stmt->fetch();

if (!$reservation) {
    header("Location: customer/dashboard.php");
    exit;
}


// ======================================================
// PAYMENT VARIABLES
// ======================================================

$message = "";
$error = "";


// ======================================================
// CHECK RESERVATION STATUS
// ======================================================

$blockedReservationStatuses = [
    "cancelled",
    "declined",
    "completed"
];

$reservationCanBePaid = !in_array(
    $reservation["status"],
    $blockedReservationStatuses,
    true
);


// ======================================================
// GET LATEST PAYMENT
// ======================================================

$stmt = $pdo->prepare("
    SELECT *
    FROM payments
    WHERE reservation_id = ?
    AND user_id = ?
    ORDER BY id DESC
    LIMIT 1
");

$stmt->execute([
    $reservation_id,
    $user_id
]);

$existingPayment = $stmt->fetch();

// An online payment in progress? Ask PayMongo first (it may have been paid in another tab,
// or the checkout may have expired).
if (
    $existingPayment
    && $existingPayment["status"] === "pending"
    && $existingPayment["payment_method"] === "online"
) {
    paymongo_settle($pdo, $existingPayment);
    $stmt->execute([$reservation_id, $user_id]);
    $existingPayment = $stmt->fetch();
}


// ======================================================
// DETERMINE PAYMENT STATE
// ======================================================

$paymentStatus =
    $existingPayment["status"]
    ?? null;


// Customer may submit if:
// 1. Reservation is payable
// 2. No payment exists OR latest payment was rejected

$canSubmitPayment =
    $reservationCanBePaid
    &&
    (
        !$existingPayment
        || in_array($paymentStatus, ["rejected", "cancelled"], true)
    );


// ======================================================
// CANCEL AN ONLINE PAYMENT IN PROGRESS
// ======================================================

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["cancel_online"])
) {
    $outcome = "";

    if (
        $existingPayment
        && $existingPayment["status"] === "pending"
        && $existingPayment["payment_method"] === "online"
    ) {
        $outcome = paymongo_settle($pdo, $existingPayment, true);
    }

    header(
        "Location: payment.php?reservation_id=" . $reservation_id
        . "&online=" . ($outcome === "verified" ? "paid" : "cancelled")
    );
    exit;
}


// ======================================================
// HANDLE PAYMENT SUBMISSION
// ======================================================

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["submit_payment"])
) {

    // Re-check latest payment from database.
    // This prevents duplicate submissions from multiple tabs.

    $checkStmt = $pdo->prepare("
        SELECT *
        FROM payments
        WHERE reservation_id = ?
        AND user_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");

    $checkStmt->execute([
        $reservation_id,
        $user_id
    ]);

    $latestPayment =
        $checkStmt->fetch();


    // --------------------------------------------------
    // RE-CHECK RESERVATION STATUS
    // --------------------------------------------------

    $reservationCheck = $pdo->prepare("
        SELECT status, total_amount
        FROM reservations
        WHERE id = ?
        AND user_id = ?
        LIMIT 1
    ");

    $reservationCheck->execute([
        $reservation_id,
        $user_id
    ]);

    $currentReservation =
        $reservationCheck->fetch();


    if (!$currentReservation) {

        $error =
            "Reservation not found.";

    } elseif (
        in_array(
            $currentReservation["status"],
            $blockedReservationStatuses,
            true
        )
    ) {

        $error =
            "Payment is not allowed for this reservation.";

    } elseif (
        $latestPayment
        && $latestPayment["status"] === "verified"
    ) {

        $error =
            "This reservation has already been paid.";

    } elseif (
        $latestPayment
        && $latestPayment["status"] === "pending"
    ) {

        $error =
            "Your payment is already pending verification.";

    } else {


        // ==================================================
        // GET FORM DATA
        // ==================================================

        $payment_method =
            $_POST["payment_method"]
            ?? "";

        $reference_number =
            trim(
                $_POST["reference_number"]
                ?? ""
            );


        // Manual GCash (typed reference number) was replaced by Pay Online (PayMongo).
        $allowed_methods = [
            "cash",
            "pay_at_property"
        ];

        if (paymongo_enabled()) {
            $allowed_methods[] = "online";
        }


        // ==================================================
        // VALIDATE PAYMENT METHOD
        // ==================================================

        if (
            !in_array(
                $payment_method,
                $allowed_methods,
                true
            )
        ) {

            $error =
                "Please select a valid payment method.";

        } elseif ($payment_method === "online") {

            // ==================================================
            // ONLINE PAYMENT: PENDING UNTIL PAYMONGO SAYS PAID
            // ==================================================

            $pdo->prepare(
                "INSERT INTO payments (reservation_id, user_id, payment_method, amount, status)
                 VALUES (?, ?, 'online', ?, 'pending')"
            )->execute([$reservation_id, $user_id, $currentReservation["total_amount"]]);

            $onlinePaymentId = (int) $pdo->lastInsertId();

            $customerStmt = $pdo->prepare("SELECT full_name, email, phone FROM users WHERE id = ?");
            $customerStmt->execute([$user_id]);

            $checkout = paymongo_create_checkout(
                array_merge($reservation, ["total_amount" => $currentReservation["total_amount"]]),
                $customerStmt->fetch() ?: [],
                $onlinePaymentId
            );

            if ($checkout["ok"]) {
                $pdo->prepare("UPDATE payments SET checkout_session_id = ? WHERE id = ?")
                    ->execute([$checkout["session_id"], $onlinePaymentId]);

                header("Location: " . $checkout["checkout_url"]);
                exit;
            }

            $pdo->prepare("DELETE FROM payments WHERE id = ? AND status = 'pending'")
                ->execute([$onlinePaymentId]);

            $error = $checkout["error"];

        } else {


            // ==================================================
            // INSERT NEW PAYMENT ATTEMPT
            // ==================================================

            try {

                $stmt = $pdo->prepare("
                    INSERT INTO payments
                    (
                        reservation_id,
                        user_id,
                        payment_method,
                        amount,
                        reference_number,
                        status
                    )

                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        'pending'
                    )
                ");

                $stmt->execute([
                    $reservation_id,
                    $user_id,
                    $payment_method,
                    $currentReservation["total_amount"],
                    $reference_number !== ""
                        ? $reference_number
                        : null
                ]);

                // shows up in the admin's notifications and activity log
                log_activity(
                    $pdo,
                    "payment.submitted",
                    ($_SESSION["full_name"] ?? "A customer") . " sent a payment of ₱"
                        . number_format((float) $currentReservation["total_amount"], 2)
                        . " (" . payment_method_label($payment_method) . ") for reservation #" . $reservation_id,
                    [
                        "entity_type" => "payment",
                        "entity_id" => (int) $pdo->lastInsertId(),
                        "link" => "payment.php?status=pending",
                        "notify" => true,
                    ]
                );


                header(
                    "Location: payment.php?reservation_id="
                    . $reservation_id
                    . "&success=1"
                );

                exit;

            } catch (PDOException $exception) {

                $error =
                    "Unable to submit payment. Please try again.";

            }
        }
    }


    // Refresh payment after failed POST
    $stmt = $pdo->prepare("
        SELECT *
        FROM payments
        WHERE reservation_id = ?
        AND user_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->execute([
        $reservation_id,
        $user_id
    ]);

    $existingPayment =
        $stmt->fetch();

    $paymentStatus =
        $existingPayment["status"]
        ?? null;

    $canSubmitPayment =
        $reservationCanBePaid
        &&
        (
            !$existingPayment
            || in_array($paymentStatus, ["rejected", "cancelled"], true)
        );
}


// ======================================================
// SUCCESS MESSAGE
// ======================================================

$onlineOutcome = $_GET["online"] ?? "";

if ($onlineOutcome === "paid" && $paymentStatus === "verified") {
    $message = "Payment received! Thank you. Your reservation is now confirmed.";
} elseif ($onlineOutcome === "cancelled") {
    $error = "Online payment was cancelled. No money was taken. You can try again or choose another method.";
} elseif ($onlineOutcome === "processing") {
    $message = "We're still waiting for PayMongo to confirm your payment. Refresh this page in a minute.";
}

if (isset($_GET["success"])) {

    $message =
        "Payment submitted successfully. "
        . "Please wait for administrator verification.";
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#fffaf4">
    <title>Payment | ARVE'S House</title>

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
            --shadow: 0 18px 50px rgba(76,50,34,.10);
        }

        body {
            min-height: 100vh;
            font-family: Arial, Helvetica, sans-serif;
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

        img {
            max-width: 100%;
            height: auto;
        }

        button,
        a,
        input,
        select {
            touch-action: manipulation;
        }

        button,
        a {
            -webkit-tap-highlight-color: transparent;
        }

        body.menu-open {
            overflow: hidden;
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
        }

        .nav-links a:hover {
            background: rgba(122,79,54,.08);
            color: var(--brown);
        }

        .nav-primary {
            background:
                linear-gradient(
                    135deg,
                    var(--brown),
                    var(--brown-dark)
                );
            color: white !important;
        }

        .page {
            width: 90%;
            max-width: 1100px;
            margin: 45px auto 70px;
        }

        .page-heading {
            text-align: center;
            max-width: 700px;
            margin: 0 auto 28px;
        }

        .page-heading span {
            color: var(--brown);
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 3px;
        }

        .page-heading h1 {
            margin: 8px 0 10px;

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size:
                clamp(
                    36px,
                    5vw,
                    50px
                );
        }

        .page-heading p {
            color: var(--muted);
            font-size: 13px;
        }

        .payment-grid {
            display: grid;
            grid-template-columns: .88fr 1.12fr;
            gap: 26px;
            align-items: start;
        }

        .summary-card,
        .payment-card {
            border: 1px solid rgba(255,255,255,.90);
            border-radius: 24px;
            background: rgba(255,255,255,.90);
            backdrop-filter: blur(14px);
            box-shadow: var(--shadow);
        }

        .summary-card {
            padding: 26px;
            position: sticky;
            top: 105px;
        }

        .summary-label {
            display: inline-block;
            margin-bottom: 8px;

            color: var(--brown);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .summary-card h2 {
            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 27px;
            margin-bottom: 5px;
        }

        .reservation-number {
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 20px;
        }

        .summary-list {
            display: grid;
            gap: 10px;
        }

        .summary-row {
            padding: 13px 14px;

            border: 1px solid var(--border);
            border-radius: 11px;

            background: rgba(255,250,244,.60);
        }

        .summary-row span {
            display: block;
            margin-bottom: 4px;

            color: var(--muted);
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .6px;
        }

        .summary-row strong {
            font-size: 13px;
            color: var(--text);
        }

        .summary-total {
            margin-top: 16px;
            padding: 17px;

            border-radius: 14px;

            background:
                linear-gradient(
                    135deg,
                    #7a4f36,
                    #513421
                );

            color: white;
        }

        .summary-total span {
            display: block;
            font-size: 10px;
            opacity: .82;
            text-transform: uppercase;
            letter-spacing: .7px;
            margin-bottom: 3px;
        }

        .summary-total strong {
            font-size: 26px;
        }

        .payment-card {
            padding: 28px;
        }

        .payment-card h2 {
            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 28px;
            margin-bottom: 6px;
        }

        .card-subtitle {
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 22px;
        }

        .alert {
            margin-bottom: 18px;
            padding: 13px 15px;

            border-radius: 10px;
            font-size: 12px;
            line-height: 1.55;
        }

        .alert.error {
            border: 1px solid #fecaca;
            background: #fee2e2;
            color: #991b1b;
        }

        .alert.success {
            border: 1px solid #bbf7d0;
            background: #dcfce7;
            color: #166534;
        }

        .alert.pending {
            border: 1px solid #fde68a;
            background: #fef3c7;
            color: #92400e;
        }

        .status-panel {
            margin-top: 16px;
            display: grid;
            gap: 10px;
        }

        .status-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;

            padding: 13px 14px;

            border: 1px solid var(--border);
            border-radius: 10px;

            background: rgba(255,250,244,.56);

            font-size: 12px;
        }

        .status-row span {
            color: var(--muted);
        }

        .status-row strong {
            text-align: right;
        }

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            margin-bottom: 7px;

            color: var(--brown-dark);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .4px;
        }

        select,
        input {
            width: 100%;
            min-height: 49px;

            padding: 0 13px;

            border: 1px solid var(--border);
            border-radius: 10px;

            background: rgba(255,255,255,.94);
            color: var(--text);

            font-size: 13px;
            outline: none;
        }

        select:focus,
        input:focus {
            border-color: rgba(122,79,54,.55);

            box-shadow:
                0 0 0 3px
                rgba(122,79,54,.08);
        }

        .method-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 18px;
        }

        .method-option {
            position: relative;
        }

        .method-option input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .method-option label {
            height: 100%;
            min-height: 92px;
            margin: 0;

            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 7px;

            border: 1px solid var(--border);
            border-radius: 13px;

            background: rgba(255,250,244,.60);
            cursor: pointer;

            text-align: center;
            transition: .2s;
        }

        .method-option label:hover {
            border-color: rgba(122,79,54,.36);
            background: rgba(122,79,54,.05);
        }

        .method-option input:checked + label {
            border-color: var(--brown);
            background: rgba(122,79,54,.10);

            box-shadow:
                inset 0 0 0 1px
                var(--brown);
        }

        .method-icon {
            font-size: 25px;
        }

        .method-title {
            font-size: 11px;
            font-weight: 800;
            color: var(--text);
        }

        .method-help {
            font-size: 9px;
            color: var(--muted);
            font-weight: normal;
        }

        .reference-box {
            display: none;
            margin-bottom: 18px;
            padding: 16px;

            border: 1px solid var(--border);
            border-radius: 13px;

            background:
                linear-gradient(
                    135deg,
                    rgba(255,250,244,.88),
                    rgba(244,231,217,.60)
                );
        }

        .reference-box.show {
            display: block;
        }

        .gcash-note {
            margin-top: 8px;
            color: var(--muted);
            font-size: 10px;
            line-height: 1.5;
        }

        .actions {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 11px;
            margin-top: 20px;
        }

        .btn {
            min-height: 48px;
            padding: 0 18px;

            border: none;
            border-radius: 10px;

            display: flex;
            align-items: center;
            justify-content: center;

            text-decoration: none;
            cursor: pointer;

            font-size: 12px;
            font-weight: 800;

            transition: .2s;
        }

        .btn:hover {
            transform: translateY(-1px);
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

        .payment-note {
            margin-top: 15px;
            padding: 13px;

            border-radius: 10px;

            background: rgba(122,79,54,.06);
            color: var(--muted);

            font-size: 10px;
            line-height: 1.55;
        }

        footer {
            margin-top: 70px;

            padding:
                36px 7% 22px;

            background: #2d1d16;
            color: #d8c9bf;
        }

        .footer-inner {
            max-width: 1100px;
            margin: auto;

            display: flex;
            align-items: center;
            justify-content: space-between;

            gap: 25px;
        }

        footer h3 {
            color: white;
            font-family:
                Georgia,
                "Times New Roman",
                serif;
            margin-bottom: 3px;
        }

        footer p {
            color: #bdaea5;
            font-size: 11px;
        }

        /* ==================================================
           RESPONSIVE + MOBILE APP-LIKE UI
        ================================================== */

        .mobile-menu,
        .mobile-drawer,
        .drawer-backdrop,
        .mobile-bottom-nav {
            display: none;
        }

        @media (max-width: 980px) {

            .payment-grid {
                grid-template-columns: 1fr;
            }

            .summary-card {
                position: static;
            }
        }

        @media (max-width: 780px) {

            body {
                padding-bottom:
                    calc(78px + env(safe-area-inset-bottom));
            }

            .navbar {
                min-height: 72px;
                padding: 0 16px;
                z-index: 1200;
                background: rgba(255,250,244,.97);
                box-shadow:
                    0 8px 24px
                    rgba(76,50,34,.06);
            }

            .brand {
                min-width: 0;
                gap: 10px;
                font-size: 18px;
            }

            .brand-mark {
                width: 40px;
                height: 40px;
                flex: 0 0 40px;
                border-radius: 12px;
            }

            .brand small {
                font-size: 7px;
                letter-spacing: 1px;
                white-space: nowrap;
            }

            .nav-links {
                display: none;
            }

            .mobile-menu {
                display: grid;
                place-items: center;
                width: 44px;
                height: 44px;
                padding: 0;
                border: 0;
                border-radius: 13px;
                background: #f4e8dd;
                color: var(--brown-dark);
                font-size: 23px;
                cursor: pointer;
                position: relative;
                z-index: 1302;
            }

            .drawer-backdrop {
                display: block;
                position: fixed;
                inset: 0;
                z-index: 1290;
                background: rgba(35,24,18,.28);
                backdrop-filter: blur(2px);
                opacity: 0;
                visibility: hidden;
                transition: .25s ease;
            }

            .drawer-backdrop.show {
                opacity: 1;
                visibility: visible;
            }

            .mobile-drawer {
                display: flex;
                flex-direction: column;
                position: fixed;
                top: 84px;
                right: 12px;
                width: min(82vw, 320px);
                max-height: calc(100dvh - 104px);
                z-index: 1300;
                overflow: hidden;
                border:
                    1px solid
                    rgba(122,79,54,.13);
                border-radius: 22px;
                background:
                    rgba(255,252,248,.985);
                box-shadow:
                    0 24px 70px
                    rgba(48,31,21,.24);
                transform:
                    translateX(calc(100% + 26px));
                opacity: 0;
                pointer-events: none;
                transition:
                    transform .28s cubic-bezier(.2,.8,.2,1),
                    opacity .2s ease;
            }

            .mobile-drawer.show {
                transform: translateX(0);
                opacity: 1;
                pointer-events: auto;
            }

            .drawer-head {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 18px 18px 12px;
            }

            .drawer-title {
                font-family:
                    Georgia,
                    "Times New Roman",
                    serif;
                font-size: 21px;
                font-weight: 700;
                color: var(--brown-dark);
            }

            .drawer-close {
                width: 40px;
                height: 40px;
                border: 0;
                border-radius: 12px;
                background: #f5ece4;
                color: var(--brown-dark);
                font-size: 21px;
                cursor: pointer;
            }

            .drawer-menu {
                overflow-y: auto;
                padding: 6px 12px 14px;
            }

            .drawer-section {
                padding: 8px 6px 5px;
                color: #a18d7f;
                font-size: 9px;
                font-weight: 800;
                letter-spacing: 2px;
                text-transform: uppercase;
            }

            .drawer-link {
                min-height: 50px;
                margin: 3px 0;
                padding: 0 13px;
                border-radius: 13px;
                display: flex;
                align-items: center;
                gap: 12px;
                color: var(--brown-dark);
                text-decoration: none;
                font-size: 14px;
                font-weight: 700;
            }

            .drawer-link:hover,
            .drawer-link.active {
                background: #f4e7db;
                color: var(--brown);
            }

            .drawer-icon {
                width: 28px;
                height: 28px;
                flex: 0 0 28px;
                display: grid;
                place-items: center;
                font-size: 18px;
            }

            .drawer-divider {
                height: 1px;
                margin: 10px 7px;
                background: var(--border);
            }

            .drawer-action {
                min-height: 50px;
                margin: 9px 12px 16px;
                border-radius: 14px;
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
                text-decoration: none;
                font-size: 13px;
                font-weight: 800;
                box-shadow:
                    0 10px 22px
                    rgba(90,56,38,.18);
            }

            .page {
                width: auto;
                margin: 0;
                padding: 28px 14px 48px;
            }

            .page-heading {
                text-align: left;
                margin: 0 0 22px;
            }

            .page-heading span {
                font-size: 9px;
                letter-spacing: 2.7px;
            }

            .page-heading h1 {
                max-width: 360px;
                margin: 7px 0 9px;
                font-size:
                    clamp(
                        34px,
                        10vw,
                        46px
                    );
                line-height: 1.06;
            }

            .page-heading p {
                max-width: 440px;
                font-size: 13px;
                line-height: 1.7;
            }

            .payment-grid {
                gap: 18px;
            }

            /* Payment form/status comes first on phones */
            .payment-card {
                order: 1;
                padding: 18px;
                border-radius: 20px;
            }

            .summary-card {
                order: 2;
                padding: 18px;
                border-radius: 20px;
            }

            .payment-card h2 {
                font-size: 23px;
            }

            .card-subtitle {
                margin-bottom: 18px;
                font-size: 12px;
            }

            .method-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .method-option label {
                min-height: 78px;
                padding: 11px 14px;
                flex-direction: row;
                justify-content: flex-start;
                text-align: left;
            }

            .method-icon {
                width: 36px;
                flex: 0 0 36px;
                text-align: center;
                font-size: 24px;
            }

            .method-title {
                font-size: 12px;
            }

            .method-help {
                display: block;
                margin-top: 2px;
                font-size: 9px;
            }

            select,
            input {
                min-height: 52px;
                border-radius: 12px;
                font-size: 16px;
            }

            .reference-box {
                padding: 14px;
                border-radius: 13px;
            }

            .actions {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .btn {
                min-height: 52px;
                border-radius: 13px;
            }

            .status-row {
                align-items: flex-start;
                border-radius: 12px;
            }

            .summary-card h2 {
                font-size: 23px;
            }

            .summary-list {
                gap: 9px;
            }

            .summary-row {
                padding: 12px;
                border-radius: 12px;
            }

            .summary-total {
                padding: 15px;
            }

            .summary-total strong {
                font-size: 24px;
            }

            footer {
                margin-top: 30px;
                padding:
                    34px 18px
                    calc(90px + env(safe-area-inset-bottom));
            }

            .footer-inner {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
            }

            .mobile-bottom-nav {
                display: grid;
                grid-template-columns:
                    repeat(4,1fr);
                position: fixed;
                left: 10px;
                right: 10px;
                bottom:
                    calc(
                        8px +
                        env(safe-area-inset-bottom)
                    );
                z-index: 1180;
                min-height: 62px;
                padding: 7px;
                border:
                    1px solid
                    rgba(122,79,54,.12);
                border-radius: 20px;
                background:
                    rgba(255,252,248,.97);
                box-shadow:
                    0 16px 45px
                    rgba(55,36,24,.18);
                backdrop-filter: blur(18px);
            }

            .mobile-bottom-nav a {
                min-width: 0;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 2px;
                border-radius: 14px;
                color: #76675d;
                text-decoration: none;
                font-size: 9px;
                font-weight: 700;
            }

            .mobile-bottom-nav a.active {
                color: var(--brown);
                background: #f4e8dc;
            }

            .bottom-icon {
                font-size: 19px;
                line-height: 1;
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
                max-width: 175px;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .mobile-drawer {
                width: 84vw;
                right: 9px;
            }

            .page {
                padding-left: 12px;
                padding-right: 12px;
            }

            .page-heading h1 {
                font-size: 33px;
            }

            .payment-card,
            .summary-card {
                border-radius: 18px;
            }

            .status-row {
                flex-direction: column;
                gap: 5px;
            }

            .status-row strong {
                text-align: left;
            }
        }

        @media (max-width: 360px) {

            .brand small {
                display: none;
            }

            .page-heading h1 {
                font-size: 30px;
            }

            .mobile-drawer {
                width: 88vw;
            }
        }

        @media (hover: none) {

            .btn:hover {
                transform: none;
            }
        }

    </style>
<?php require __DIR__ . "/includes/glass.php"; ?>
</head>

<body>


<nav class="navbar">

    <a href="index.php" class="brand">

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
        onclick="openDrawer()"
        aria-label="Open menu"
        aria-expanded="false"
        aria-controls="mobileDrawer"
    >
        <?= icon("menu") ?>
    </button>

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

<div
    class="drawer-backdrop"
    id="drawerBackdrop"
    onclick="closeDrawer()"
></div>

<aside
    class="mobile-drawer"
    id="mobileDrawer"
    aria-hidden="true"
>
    <div class="drawer-head">
        <div class="drawer-title">
            Menu
        </div>

        <button
            type="button"
            class="drawer-close"
            onclick="closeDrawer()"
            aria-label="Close menu"
        >
            <?= icon("x") ?>
        </button>
    </div>

    <div class="drawer-menu">

        <div class="drawer-section">
            Payment
        </div>

        <a
            href="index.php"
            class="drawer-link"
        >
            <span class="drawer-icon"><?= icon("home") ?></span>
            <span>Home</span>
        </a>

        <a
            href="rooms.php"
            class="drawer-link"
        >
            <span class="drawer-icon"><?= icon("bed") ?></span>
            <span>Rooms</span>
        </a>

        <a
            href="#paymentSection"
            class="drawer-link active"
            onclick="closeDrawer()"
        >
            <span class="drawer-icon"><?= icon("credit-card") ?></span>
            <span>Payment</span>
        </a>

        <div class="drawer-divider"></div>

        <div class="drawer-section">
            Account
        </div>

        <a
            href="customer/dashboard.php"
            class="drawer-link"
        >
            <span class="drawer-icon"><?= icon("clipboard") ?></span>
            <span>My Reservations</span>
        </a>

        <a
            href="customer/profile.php"
            class="drawer-link"
        >
            <span class="drawer-icon"><?= icon("user") ?></span>
            <span>My Profile</span>
        </a>

        <a
            href="logout.php"
            class="drawer-link"
        >
            <span class="drawer-icon"><?= icon("log-out") ?></span>
            <span>Logout</span>
        </a>

        <a
            href="customer/dashboard.php"
            class="drawer-action"
        >
            View My Bookings
        </a>

    </div>
</aside>



<main class="page">


    <div class="page-heading">

        <span>
            Secure Checkout
        </span>

        <h1>
            Complete Your Payment
        </h1>

        <p>
            Review your reservation and choose your preferred
            payment method below.
        </p>

    </div>


    <div class="payment-grid">


        <aside class="summary-card">

            <span class="summary-label">
                Reservation Summary
            </span>

            <h2>
                <?= htmlspecialchars(
                    $reservation["room_name"]
                ) ?>
            </h2>

            <p class="reservation-number">
                Reservation
                #<?= (int)
                    $reservation["id"]
                ?>
            </p>


            <div class="summary-list">

                <div class="summary-row">

                    <span>
                        Check-in
                    </span>

                    <strong>
                        <?= htmlspecialchars(
                            $reservation["check_in"]
                        ) ?>
                    </strong>

                </div>


                <div class="summary-row">

                    <span>
                        Check-out
                    </span>

                    <strong>
                        <?= htmlspecialchars(
                            $reservation["check_out"]
                        ) ?>
                    </strong>

                </div>


                <div class="summary-row">

                    <span>
                        Guests
                    </span>

                    <strong>
                        <?= (int)
                            $reservation["guests"]
                        ?>
                    </strong>

                </div>


                <div class="summary-row">

                    <span>
                        Total Nights
                    </span>

                    <strong>
                        <?= (int)
                            $reservation["total_nights"]
                        ?>
                    </strong>

                </div>


            </div>


            <div class="summary-total">

                <span>
                    Amount Due
                </span>

                <strong>
                    ₱<?= number_format(
                        (float)
                        $reservation["total_amount"],
                        2
                    ) ?>
                </strong>

            </div>

        </aside>


        <section
            class="payment-card"
            id="paymentSection"
        >


            <h2>
                Payment Details
            </h2>

            <p class="card-subtitle">
                Your payment will be reviewed by the administrator.
            </p>


            <?php if ($message): ?>

                <div class="alert success">
                    <?= htmlspecialchars(
                        $message
                    ) ?>
                </div>

            <?php endif; ?>


            <?php if ($error): ?>

                <div class="alert error">
                    <?= htmlspecialchars(
                        $error
                    ) ?>
                </div>

            <?php endif; ?>


            <?php if (!$reservationCanBePaid): ?>

                <div class="alert error">

                    Payment is not available because this reservation is

                    <strong>
                        <?= htmlspecialchars(
                            ucfirst(
                                $reservation["status"]
                            )
                        ) ?>
                    </strong>.

                </div>


            <?php elseif (
                $existingPayment
                && $paymentStatus === "verified"
            ): ?>


                <div class="alert success">

                    <strong>
                        ✓ Payment Verified
                    </strong>

                    <br><br>

                    Your payment has been verified successfully.
                    Your reservation should now be confirmed automatically.

                </div>


                <div class="status-panel">

                    <div class="status-row">

                        <span>
                            Payment Method
                        </span>

                        <strong>
                            <?= htmlspecialchars(
                                ucwords(
                                    str_replace(
                                        "_",
                                        " ",
                                        $existingPayment[
                                            "payment_method"
                                        ]
                                    )
                                )
                            ) ?>
                        </strong>

                    </div>


                    <div class="status-row">

                        <span>
                            Amount
                        </span>

                        <strong>
                            ₱<?= number_format(
                                (float)
                                $existingPayment["amount"],
                                2
                            ) ?>
                        </strong>

                    </div>


                    <?php if (
                        !empty(
                            $existingPayment[
                                "reference_number"
                            ]
                        )
                    ): ?>

                        <div class="status-row">

                            <span>
                                Reference Number
                            </span>

                            <strong>
                                <?= htmlspecialchars(
                                    $existingPayment[
                                        "reference_number"
                                    ]
                                ) ?>
                            </strong>

                        </div>

                    <?php endif; ?>

                </div>


                <div class="actions">

                    <a
                        href="customer/dashboard.php"
                        class="btn btn-primary"
                    >
                        View My Reservations
                    </a>

                </div>


            <?php elseif (
                $existingPayment
                && $paymentStatus === "pending"
                && $existingPayment["payment_method"] === "online"
            ):
                $onlineSession = paymongo_session_status((string) $existingPayment["checkout_session_id"]);
            ?>

                <div class="alert pending">
                    <strong><?= icon("credit-card") ?> Online payment not finished yet</strong>
                    <br><br>
                    Your PayMongo checkout is still open. Finish paying there, or cancel it to choose another method.
                    If you already paid, refresh this page in a minute.
                </div>

                <div class="status-panel">
                    <div class="status-row">
                        <span>Payment Method</span>
                        <strong>Online (GCash, Maya, GrabPay or Card)</strong>
                    </div>
                    <div class="status-row">
                        <span>Amount</span>
                        <strong>₱<?= number_format((float) $existingPayment["amount"], 2) ?></strong>
                    </div>
                </div>

                <div class="actions">
                    <?php if ($onlineSession && $onlineSession["active"] && $onlineSession["checkout_url"] !== ""): ?>
                        <a href="<?= htmlspecialchars($onlineSession["checkout_url"]) ?>" class="btn btn-primary">Continue Payment</a>
                    <?php endif; ?>
                    <form method="POST" style="display:contents">
                        <button type="submit" name="cancel_online" class="btn btn-secondary">Cancel &amp; Choose Another Method</button>
                    </form>
                </div>

            <?php elseif (
                $existingPayment
                && $paymentStatus === "pending"
            ): ?>


                <div class="alert pending">

                    <strong>
                        <?= icon("clock") ?> Payment Pending Verification
                    </strong>

                    <br><br>

                    Your payment has been submitted and is waiting
                    for administrator verification.

                </div>


                <div class="status-panel">

                    <div class="status-row">

                        <span>
                            Payment Method
                        </span>

                        <strong>
                            <?= htmlspecialchars(
                                ucwords(
                                    str_replace(
                                        "_",
                                        " ",
                                        $existingPayment[
                                            "payment_method"
                                        ]
                                    )
                                )
                            ) ?>
                        </strong>

                    </div>


                    <div class="status-row">

                        <span>
                            Amount
                        </span>

                        <strong>
                            ₱<?= number_format(
                                (float)
                                $existingPayment["amount"],
                                2
                            ) ?>
                        </strong>

                    </div>


                    <?php if (
                        !empty(
                            $existingPayment[
                                "reference_number"
                            ]
                        )
                    ): ?>

                        <div class="status-row">

                            <span>
                                Reference Number
                            </span>

                            <strong>
                                <?= htmlspecialchars(
                                    $existingPayment[
                                        "reference_number"
                                    ]
                                ) ?>
                            </strong>

                        </div>

                    <?php endif; ?>

                </div>


                <div class="actions">

                    <a
                        href="customer/dashboard.php"
                        class="btn btn-secondary"
                    >
                        Back to My Reservations
                    </a>

                </div>


            <?php else: ?>


                <?php if (
                    $existingPayment
                    && $paymentStatus === "rejected"
                ): ?>

                    <div class="alert error">

                        <strong>
                            Payment Rejected
                        </strong>

                        <br><br>

                        Your previous payment was rejected.
                        Please check your payment information
                        and submit a new payment.

                    </div>

                <?php endif; ?>


                <form method="POST">


                    <label>
                        PAYMENT METHOD
                    </label>


                    <div class="method-grid">


                        <?php if (paymongo_enabled()): ?>
                    <div class="method-option">
                        <input type="radio" id="method_online" name="payment_method" value="online" required>
                        <label for="method_online">
                            <span class="method-icon"><?= icon("credit-card") ?></span>
                            <span class="method-title">Pay Online</span>
                            <span class="method-help">GCash, Maya, GrabPay or Card<?= paymongo_test_mode() ? " · TEST MODE" : "" ?></span>
                        </label>
                    </div>
                    <?php endif; ?>

                    <div class="method-option">

                            <input
                                type="radio"
                                id="method_cash"
                                name="payment_method"
                                value="cash"
                                required
                            >

                            <label for="method_cash">

                                <span class="method-icon">
                                    <?= icon("banknote") ?>
                                </span>

                                <span class="method-title">
                                    Cash
                                </span>

                                <span class="method-help">
                                    Cash payment option
                                </span>

                            </label>

                        </div>


                        <div class="method-option">

                            <input
                                type="radio"
                                id="method_property"
                                name="payment_method"
                                value="pay_at_property"
                                required
                            >

                            <label for="method_property">

                                <span class="method-icon">
                                    <?= icon("home") ?>
                                </span>

                                <span class="method-title">
                                    Pay at Property
                                </span>

                                <span class="method-help">
                                    Pay when you arrive
                                </span>

                            </label>

                        </div>


                    </div>


                    <div class="payment-note" id="paymentNote">

                        ℹ️ After submission, your payment status will be
                        <strong>Pending</strong>. Once the administrator
                        verifies it, the reservation will be confirmed
                        automatically.

                    </div>


                    <div class="actions">

                        <button
                            type="submit"
                            name="submit_payment" id="submitPayment"
                            class="btn btn-primary"
                        >

                            <?= $existingPayment
                                && $paymentStatus === "rejected"
                                ? "Submit New Payment"
                                : "Submit Payment"
                            ?>

                        </button>


                        <a
                            href="customer/dashboard.php"
                            class="btn btn-secondary"
                        >
                            Back
                        </a>

                    </div>


                </form>


            <?php endif; ?>


        </section>


    </div>


</main>



<nav
    class="mobile-bottom-nav"
    aria-label="Mobile navigation"
>
    <a href="index.php">
        <span class="bottom-icon"><?= icon("home") ?></span>
        <span>Home</span>
    </a>

    <a href="rooms.php">
        <span class="bottom-icon"><?= icon("bed") ?></span>
        <span>Rooms</span>
    </a>

    <a
        href="#paymentSection"
        class="active"
    >
        <span class="bottom-icon"><?= icon("credit-card") ?></span>
        <span>Payment</span>
    </a>

    <a href="customer/dashboard.php">
        <span class="bottom-icon"><?= icon("clipboard") ?></span>
        <span>Bookings</span>
    </a>
</nav>

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
                Customer Payment Portal
            </p>

            <p>
                Comfort · Relax · Stay
            </p>

        </div>

    </div>

</footer>


<script>

// ======================================================
// PARTIAL MOBILE DRAWER
// ======================================================

const mobileDrawer =
    document.getElementById(
        "mobileDrawer"
    );

const drawerBackdrop =
    document.getElementById(
        "drawerBackdrop"
    );

const mobileMenuButton =
    document.getElementById(
        "mobileMenuButton"
    );


function openDrawer() {

    mobileDrawer.classList.add(
        "show"
    );

    drawerBackdrop.classList.add(
        "show"
    );

    document.body.classList.add(
        "menu-open"
    );

    mobileDrawer.setAttribute(
        "aria-hidden",
        "false"
    );

    mobileMenuButton.setAttribute(
        "aria-expanded",
        "true"
    );
}


function closeDrawer() {

    mobileDrawer.classList.remove(
        "show"
    );

    drawerBackdrop.classList.remove(
        "show"
    );

    document.body.classList.remove(
        "menu-open"
    );

    mobileDrawer.setAttribute(
        "aria-hidden",
        "true"
    );

    mobileMenuButton.setAttribute(
        "aria-expanded",
        "false"
    );
}


document.addEventListener(
    "keydown",
    event => {

        if (event.key === "Escape") {
            closeDrawer();
        }
    }
);


window.addEventListener(
    "resize",
    () => {

        if (window.innerWidth > 780) {
            closeDrawer();
        }
    }
);


// ======================================================
// PAYMENT METHOD
// ======================================================

const methodInputs =
    document.querySelectorAll(
        'input[name="payment_method"]'
    );


// ======================================================
// PAY ONLINE: goes to PayMongo and confirms itself
// ======================================================

const submitPayment =
    document.getElementById(
        "submitPayment"
    );

const paymentNote =
    document.getElementById(
        "paymentNote"
    );

if (submitPayment && paymentNote) {

    const defaultButtonText =
        submitPayment.textContent.trim();

    const defaultNote =
        paymentNote.innerHTML;

    const updateForOnline = () => {

        const selected =
            document.querySelector(
                'input[name="payment_method"]:checked'
            );

        const online =
            selected
            && selected.value === "online";

        submitPayment.textContent = online
            ? "Continue to Secure Payment →"
            : defaultButtonText;

        paymentNote.innerHTML = online
            ? <?= json_encode(icon("lock") . " ") ?> + "You'll pay on PayMongo's secure page (GCash, Maya, GrabPay or card). "
                + "Once paid, your reservation is <strong>confirmed automatically</strong>."
            : defaultNote;
    };

    methodInputs.forEach(
        input => input.addEventListener("change", updateForOnline)
    );

    updateForOnline();
}

</script>


<?php $inboxFabLift = 72; require __DIR__ . "/includes/inbox-widget.php"; ?>
<?php $termsFabLift = 72; require __DIR__ . "/includes/terms-modal.php"; ?>

</body>
</html>