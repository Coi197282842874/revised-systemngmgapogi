<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/customer-shell.php";
require_once __DIR__ . "/../includes/hero-scenes.php";

// ======================================================
// PAY FOR A RESERVATION (the old payment.php sends here)
//
// Pay Online goes to PayMongo and confirms itself when paid; Cash and Pay at Property wait
// for an admin to verify them.
// ======================================================

$me = customer_boot($pdo);
$userId = (int) $me["id"];

ensure_paymongo_schema($pdo);

$reservationId = (int) ($_GET["reservation_id"] ?? 0);

$stmt = $pdo->prepare("
    SELECT reservations.*, rooms.room_name
    FROM reservations
    INNER JOIN rooms ON reservations.room_id = rooms.id
    WHERE reservations.id = ? AND reservations.user_id = ?
    LIMIT 1
");
$stmt->execute([$reservationId, $userId]);
$reservation = $stmt->fetch();

if (!$reservation) {
    header("Location: reservations.php");
    exit;
}

$here = "pay.php?reservation_id=" . $reservationId;
$blockedStatuses = ["cancelled", "declined", "completed"];
$message = "";
$error = "";

// The latest payment of this reservation.
$latestPayment = function () use ($pdo, $reservationId, $userId) {
    $stmt = $pdo->prepare("SELECT * FROM payments WHERE reservation_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$reservationId, $userId]);

    return $stmt->fetch() ?: null;
};

$payment = $latestPayment();

// An online payment in progress? Ask PayMongo first (it may have been paid in another tab,
// or the checkout may have expired).
if ($payment && $payment["status"] === "pending" && $payment["payment_method"] === "online") {
    paymongo_settle($pdo, $payment);
    $payment = $latestPayment();
}


// ======================================================
// CANCEL AN ONLINE PAYMENT IN PROGRESS
// ======================================================

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["cancel_online"])) {
    $outcome = "";

    if (!admin_check_csrf()) {
        admin_flash("Your session expired. Please try again.", "error");
        header("Location: " . $here);
        exit;
    }

    if ($payment && $payment["status"] === "pending" && $payment["payment_method"] === "online") {
        $outcome = paymongo_settle($pdo, $payment, true);
    }

    header("Location: " . $here . "&online=" . ($outcome === "verified" ? "paid" : "cancelled"));
    exit;
}


// ======================================================
// SENDING A PAYMENT
// ======================================================

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["submit_payment"])) {

    // look again: another tab may have paid in the meantime
    $latest = $latestPayment();

    $check = $pdo->prepare("SELECT status, total_amount FROM reservations WHERE id = ? AND user_id = ? LIMIT 1");
    $check->execute([$reservationId, $userId]);
    $current = $check->fetch();

    $method = (string) ($_POST["payment_method"] ?? "");
    $methods = ["cash", "pay_at_property"];

    if (paymongo_enabled()) {
        $methods[] = "online";
    }

    if (!admin_check_csrf()) {
        $error = "Your session expired. Please try again.";
    } elseif (!$current) {
        $error = "Reservation not found.";
    } elseif (in_array($current["status"], $blockedStatuses, true)) {
        $error = "Payment is not allowed for this reservation.";
    } elseif ($latest && $latest["status"] === "verified") {
        $error = "This reservation has already been paid.";
    } elseif ($latest && $latest["status"] === "pending") {
        $error = "Your payment is already pending verification.";
    } elseif (!in_array($method, $methods, true)) {
        $error = "Please select a valid payment method.";
    } elseif ($method === "online") {

        // pending until PayMongo says it is paid
        $pdo->prepare(
            "INSERT INTO payments (reservation_id, user_id, payment_method, amount, status)
             VALUES (?, ?, 'online', ?, 'pending')"
        )->execute([$reservationId, $userId, $current["total_amount"]]);

        $onlinePaymentId = (int) $pdo->lastInsertId();

        $customer = $pdo->prepare("SELECT full_name, email, phone FROM users WHERE id = ?");
        $customer->execute([$userId]);

        $checkout = paymongo_create_checkout(
            array_merge($reservation, ["total_amount" => $current["total_amount"]]),
            $customer->fetch() ?: [],
            $onlinePaymentId
        );

        if ($checkout["ok"]) {
            $pdo->prepare("UPDATE payments SET checkout_session_id = ? WHERE id = ?")
                ->execute([$checkout["session_id"], $onlinePaymentId]);

            header("Location: " . $checkout["checkout_url"]);
            exit;
        }

        $pdo->prepare("DELETE FROM payments WHERE id = ? AND status = 'pending'")->execute([$onlinePaymentId]);
        $error = $checkout["error"];

    } else {

        try {
            $pdo->prepare("
                INSERT INTO payments (reservation_id, user_id, payment_method, amount, reference_number, status)
                VALUES (?, ?, ?, ?, NULL, 'pending')
            ")->execute([$reservationId, $userId, $method, $current["total_amount"]]);

            // shows up in the admin's notifications and activity log
            log_activity(
                $pdo,
                "payment.submitted",
                ($me["full_name"] ?: "A customer") . " sent a payment of ₱"
                    . number_format((float) $current["total_amount"], 2)
                    . " (" . payment_method_label($method) . ") for reservation #" . $reservationId,
                [
                    "entity_type" => "payment",
                    "entity_id" => (int) $pdo->lastInsertId(),
                    "link" => "payment.php?status=pending",
                    "notify" => true,
                ]
            );

            header("Location: " . $here . "&success=1");
            exit;
        } catch (PDOException $exception) {
            error_log("ARVE'S House payment failed: " . $exception->getMessage());
            $error = "Unable to submit payment. Please try again.";
        }
    }

    $payment = $latestPayment();
}

$status = $payment["status"] ?? null;
$canBePaid = !in_array($reservation["status"], $blockedStatuses, true);
$canSubmit = $canBePaid && (!$payment || in_array($status, ["rejected", "cancelled"], true));


// ======================================================
// WHAT HAPPENED, IN ONE LINE
// ======================================================

$online = (string) ($_GET["online"] ?? "");

if ($online === "paid" && $status === "verified") {
    $message = "Payment received! Thank you. Your reservation is now confirmed.";
} elseif ($online === "cancelled") {
    $error = $error ?: "Online payment was cancelled. No money was taken. You can try again or choose another method.";
} elseif ($online === "processing") {
    $message = "We're still waiting for PayMongo to confirm your payment. Refresh this page in a minute.";
}

if (isset($_GET["success"])) {
    $message = "Payment submitted. We will check it and confirm your reservation.";
}

$photo = customer_room_photo((int) $reservation["room_id"]);
$nights = (int) $reservation["total_nights"];

customer_shell_head([
    "title" => "Payment",
    "subtitle" => "Reservation #" . $reservationId . " · " . $reservation["room_name"],
    "active" => "payments",
]);
?>
<?php customer_shell_body(); ?>

<div class="grid grid-side">

    <!-- THE RESERVATION -->

    <aside class="card stay-card">
        <div class="stay-photo">
            <?php if ($photo !== ""): ?>
                <img src="<?= h($photo) ?>" alt="<?= h($reservation["room_name"]) ?>" decoding="async">
            <?php else: ?>
                <?= room_scene("dusk", "pay") ?>
            <?php endif; ?>
        </div>

        <div class="stay-body">
            <span class="eyebrow">Reservation #<?= $reservationId ?></span>
            <h2><?= h($reservation["room_name"]) ?></h2>

            <ul class="stay-facts">
                <li><span>Check-in</span><strong><?= h(admin_date($reservation["check_in"], "D, M j, Y")) ?></strong></li>
                <li><span>Check-out</span><strong><?= h(admin_date($reservation["check_out"], "D, M j, Y")) ?></strong></li>
                <li><span>Guests</span><strong><?= (int) $reservation["guests"] ?></strong></li>
                <li><span>Nights</span><strong><?= $nights ?></strong></li>
            </ul>

            <div class="stay-due">
                <span>Amount due</span>
                <strong><?= h(peso($reservation["total_amount"], 2)) ?></strong>
            </div>
        </div>
    </aside>


    <!-- THE PAYMENT -->

    <section class="card card-pad" id="payment">
        <h2 class="card-title">Payment details</h2>
        <p class="card-sub" style="margin-bottom:16px">Choose how you want to pay for this stay.</p>

        <?php if ($message !== ""): ?>
            <div class="alert alert-success alert-in" role="status"><?= icon("check-circle") ?><p><?= h($message) ?></p></div>
        <?php endif; ?>

        <?php if ($error !== ""): ?>
            <div class="alert alert-error alert-in" role="alert"><?= icon("alert") ?><p><?= h($error) ?></p></div>
        <?php endif; ?>

        <?php if (!$canBePaid): ?>

            <div class="alert alert-error"><?= icon("alert") ?><p>Payment is not available because this reservation is <strong><?= h(ucfirst($reservation["status"])) ?></strong>.</p></div>
            <div class="form-actions">
                <a class="btn" href="reservations.php?open=<?= $reservationId ?>">View reservation</a>
            </div>

        <?php elseif ($status === "verified"): ?>

            <div class="alert alert-success"><?= icon("check-circle") ?><p><strong>Payment verified.</strong> Your reservation is confirmed. See you soon!</p></div>

            <ul class="stay-facts">
                <li><span>Method</span><strong><?= h(payment_method_label((string) $payment["payment_method"])) ?></strong></li>
                <li><span>Amount</span><strong><?= h(peso($payment["amount"], 2)) ?></strong></li>
                <?php if (!empty($payment["reference_number"])): ?>
                    <li><span>Reference</span><strong><?= h($payment["reference_number"]) ?></strong></li>
                <?php endif; ?>
            </ul>

            <div class="form-actions">
                <a class="btn btn-primary" href="ticket.php?id=<?= $reservationId ?>"><?= icon("ticket") ?> View your ticket</a>
                <a class="btn" href="reservations.php?open=<?= $reservationId ?>">View my reservation</a>
            </div>

        <?php elseif ($status === "pending" && $payment["payment_method"] === "online"): ?>
            <?php $session = paymongo_session_status((string) $payment["checkout_session_id"]); ?>

            <div class="alert alert-warning"><?= icon("credit-card") ?><p><strong>Online payment not finished yet.</strong> Your PayMongo checkout is still open. Finish paying there, or cancel it to choose another method. Already paid? Refresh this page in a minute.</p></div>

            <ul class="stay-facts">
                <li><span>Method</span><strong>Online (GCash, Maya, GrabPay or Card)</strong></li>
                <li><span>Amount</span><strong><?= h(peso($payment["amount"], 2)) ?></strong></li>
            </ul>

            <div class="form-actions">
                <?php if ($session && $session["active"] && $session["checkout_url"] !== ""): ?>
                    <a class="btn btn-primary" href="<?= h($session["checkout_url"]) ?>"><?= icon("lock") ?> Continue payment</a>
                <?php endif; ?>
                <form class="inline-form" method="post" action="<?= h($here) ?>">
                    <?= csrf_field() ?>
                    <button class="btn" type="submit" name="cancel_online" value="1">Cancel &amp; choose another method</button>
                </form>
            </div>

        <?php elseif ($status === "pending"): ?>

            <div class="alert alert-warning"><?= icon("clock") ?><p><strong>Waiting for verification.</strong> Your payment was sent. We will check it and confirm your reservation.</p></div>

            <ul class="stay-facts">
                <li><span>Method</span><strong><?= h(payment_method_label((string) $payment["payment_method"])) ?></strong></li>
                <li><span>Amount</span><strong><?= h(peso($payment["amount"], 2)) ?></strong></li>
                <?php if (!empty($payment["reference_number"])): ?>
                    <li><span>Reference</span><strong><?= h($payment["reference_number"]) ?></strong></li>
                <?php endif; ?>
            </ul>

            <div class="form-actions">
                <a class="btn" href="reservations.php?open=<?= $reservationId ?>">Back to my reservation</a>
            </div>

        <?php else: ?>

            <?php if ($status === "rejected"): ?>
                <div class="alert alert-error"><?= icon("alert") ?><p><strong>Your last payment was not accepted.</strong> Please check it and send a new one, or <a href="messages.php?about=<?= $reservationId ?>">message us</a>.</p></div>
            <?php endif; ?>

            <form method="post" action="<?= h($here) ?>" class="stack" style="gap:16px">
                <?= csrf_field() ?>

                <fieldset class="method-set">
                    <legend class="label">Payment method</legend>

                    <div class="method-grid">
                        <?php if (paymongo_enabled()): ?>
                            <label class="method-option">
                                <input type="radio" name="payment_method" value="online" required>
                                <span class="method-tile">
                                    <span class="tile-icon tone-blue"><?= icon("credit-card") ?></span>
                                    <span class="method-text">
                                        <strong>Pay online</strong>
                                        <small>GCash, Maya, GrabPay or card<?= paymongo_test_mode() ? " · TEST MODE" : "" ?></small>
                                    </span>
                                    <span class="method-check"><?= icon("check-circle") ?></span>
                                </span>
                            </label>
                        <?php endif; ?>

                        <label class="method-option">
                            <input type="radio" name="payment_method" value="cash" required>
                            <span class="method-tile">
                                <span class="tile-icon tone-green"><?= icon("banknote") ?></span>
                                <span class="method-text">
                                    <strong>Cash</strong>
                                    <small>Pay in cash</small>
                                </span>
                                <span class="method-check"><?= icon("check-circle") ?></span>
                            </span>
                        </label>

                        <label class="method-option">
                            <input type="radio" name="payment_method" value="pay_at_property" required>
                            <span class="method-tile">
                                <span class="tile-icon tone-amber"><?= icon("home") ?></span>
                                <span class="method-text">
                                    <strong>Pay at the property</strong>
                                    <small>Pay when you arrive</small>
                                </span>
                                <span class="method-check"><?= icon("check-circle") ?></span>
                            </span>
                        </label>
                    </div>
                </fieldset>

                <div class="alert alert-info" id="payment-note">
                    <?= icon("info") ?>
                    <p>Your payment is <strong>Pending</strong> after you send it. We check it, and your reservation is confirmed as soon as it is accepted.</p>
                </div>

                <div class="form-actions" style="margin-top:0">
                    <button class="btn btn-primary" type="submit" name="submit_payment" value="1" id="submit-payment"><?= $status === "rejected" ? "Send new payment" : "Send payment" ?></button>
                    <a class="btn btn-ghost" href="reservations.php?open=<?= $reservationId ?>">Back</a>
                </div>
            </form>

        <?php endif; ?>
    </section>

</div>

<script>
    // Pay online: the button and the note say what happens next
    (function () {
        var submit = document.getElementById("submit-payment");
        var note = document.querySelector("#payment-note p");

        if (!submit || !note) return;

        var buttonText = submit.textContent;
        var noteHtml = note.innerHTML;

        function update() {
            var chosen = document.querySelector('input[name="payment_method"]:checked');
            var online = chosen && chosen.value === "online";

            submit.textContent = online ? "Continue to secure payment" : buttonText;
            note.innerHTML = online
                ? "You'll pay on PayMongo's secure page (GCash, Maya, GrabPay or card). Once paid, your reservation is <strong>confirmed automatically</strong>."
                : noteHtml;
        }

        document.querySelectorAll('input[name="payment_method"]').forEach(function (input) {
            input.addEventListener("change", update);
        });

        update();
    })();
</script>

<?php customer_shell_end(); ?>
