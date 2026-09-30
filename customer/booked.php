<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/customer-shell.php";
require_once __DIR__ . "/../includes/hero-scenes.php";

// ======================================================
// BOOKING RECEIVED (the old reservation_success.php sends here)
// ======================================================

$me = customer_boot($pdo);
$userId = (int) $me["id"];

$reservationId = (int) ($_GET["id"] ?? 0);

$stmt = $pdo->prepare("
    SELECT r.*, rooms.room_name, lp.status AS payment_status
    FROM reservations r
    INNER JOIN rooms ON rooms.id = r.room_id
    " . CUSTOMER_LATEST_PAYMENT . "
    WHERE r.id = ? AND r.user_id = ?
    LIMIT 1
");
$stmt->execute([$reservationId, $userId]);
$reservation = $stmt->fetch();

// not this guest's reservation
if (!$reservation) {
    header("Location: reservations.php");
    exit;
}

$status = $reservation["status"] ?: "pending";
$nights = (int) $reservation["total_nights"];
$guests = (int) $reservation["guests"];
$photo = customer_room_photo((int) $reservation["room_id"]);

customer_shell_head([
    "title" => "Booking received",
    "subtitle" => "Reservation #" . $reservationId . " · " . $reservation["room_name"],
    "active" => "reservations",
    "narrow" => true,
]);
?>
<?php customer_shell_body(); ?>

<div class="stack">

    <section class="card done-card">
        <div class="done-icon"><?= icon("check") ?></div>
        <span class="eyebrow">Booking received</span>
        <h2>Reservation submitted</h2>
        <p>
            <?php if ($status === "pending" && customer_can_pay($reservation)): ?>
                Your reservation is saved and waiting for payment. Pay now to confirm it; we confirm it as soon as your payment is accepted.
            <?php elseif ($status === "confirmed"): ?>
                Your reservation is confirmed. See you soon!
            <?php else: ?>
                Your reservation is <?= h($status) ?>.
            <?php endif; ?>
        </p>
        <span class="done-number">Reservation #<?= $reservationId ?></span>
    </section>

    <section class="card stay-card">
        <div class="stay-photo">
            <?php if ($photo !== ""): ?>
                <img src="<?= h($photo) ?>" alt="<?= h($reservation["room_name"]) ?>" decoding="async">
            <?php else: ?>
                <?= room_scene("dusk", "booked") ?>
            <?php endif; ?>
        </div>

        <div class="stay-body">
            <div class="row row-wrap">
                <?= status_pill($status) ?>
                <?= customer_payment_pill($reservation["payment_status"]) ?>
            </div>

            <h2><?= h($reservation["room_name"]) ?></h2>

            <ul class="stay-facts">
                <li><span>Check-in</span><strong><?= h(admin_date($reservation["check_in"], "D, M j, Y")) ?></strong></li>
                <li><span>Check-out</span><strong><?= h(admin_date($reservation["check_out"], "D, M j, Y")) ?></strong></li>
                <li><span>Nights</span><strong><?= $nights ?></strong></li>
                <li><span>Guests</span><strong><?= $guests ?> <?= $guests === 1 ? "guest" : "guests" ?></strong></li>
                <li><span>Price per night</span><strong><?= h(peso($reservation["price_per_night"], 2)) ?></strong></li>
            </ul>

            <div class="stay-due">
                <span>Total</span>
                <strong><?= h(peso($reservation["total_amount"], 2)) ?></strong>
            </div>

            <div class="form-actions">
                <?php if (ticket_available($reservation)): ?>
                    <a class="btn btn-primary" href="ticket.php?id=<?= $reservationId ?>"><?= icon("ticket") ?> View ticket</a>
                <?php elseif (customer_can_pay($reservation)): ?>
                    <a class="btn btn-primary" href="pay.php?reservation_id=<?= $reservationId ?>"><?= icon("credit-card") ?> Pay now</a>
                <?php endif; ?>
                <a class="btn" href="reservations.php?open=<?= $reservationId ?>">My reservations</a>
                <a class="btn btn-ghost" href="../rooms.php">Browse more rooms</a>
            </div>
        </div>
    </section>

</div>

<?php customer_shell_end(); ?>
