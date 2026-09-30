<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/customer-shell.php";
require_once __DIR__ . "/../includes/tickets.php";

// ======================================================
// THE GUEST'S TICKET FOR A CONFIRMED STAY
// Shown at the front desk; its QR code opens the booking on the admin's scanner.
// ======================================================

$me = customer_boot($pdo);
$userId = (int) $me["id"];

$reservationId = (int) ($_GET["id"] ?? 0);

$stmt = $pdo->prepare("
    SELECT r.*, rooms.room_name, lp.status AS payment_status, lp.amount AS paid_amount
    FROM reservations r
    INNER JOIN rooms ON rooms.id = r.room_id
    " . CUSTOMER_LATEST_PAYMENT . "
    WHERE r.id = ? AND r.user_id = ?
    LIMIT 1
");
$stmt->execute([$reservationId, $userId]);
$reservation = $stmt->fetch();

if (!$reservation) {
    header("Location: reservations.php");
    exit;
}

$hasTicket = ticket_available($reservation);
$code = $hasTicket ? ticket_code($pdo, $reservationId) : "";
$nights = (int) $reservation["total_nights"];
$guests = (int) $reservation["guests"];
$shareText = "My stay at ARVE'S House: " . $reservation["room_name"] . ", "
    . admin_stay($reservation["check_in"], $reservation["check_out"]) . ". Booking code " . $code;

customer_shell_head([
    "title" => "Your Ticket",
    "subtitle" => "Reservation #" . $reservationId . " · " . $reservation["room_name"],
    "active" => "reservations",
    "narrow" => true,
]);
?>
<?php customer_shell_body(); ?>

<?php if (!$hasTicket): ?>

    <section class="card">
        <div class="empty">
            <div class="empty-icon"><?= icon("ticket", 22) ?></div>
            <?php if (in_array($reservation["status"], ["cancelled", "declined", "expired"], true)): ?>
                <h3>No ticket for this reservation</h3>
                <p>Reservation #<?= $reservationId ?> is <?= h($reservation["status"]) ?>, so it has no ticket.</p>
                <a class="btn" href="reservations.php">My reservations</a>
            <?php else: ?>
                <h3>Your ticket is almost ready</h3>
                <p>It appears here as soon as your booking is confirmed<?= customer_can_pay($reservation) ? ", after your payment" : "" ?>.</p>
                <div class="row row-wrap" style="justify-content:center">
                    <?php if (customer_can_pay($reservation)): ?>
                        <a class="btn btn-primary" href="pay.php?reservation_id=<?= $reservationId ?>"><?= icon("credit-card") ?> Pay now</a>
                    <?php elseif ($reservation["payment_status"] === "pending"): ?>
                        <span class="wait-note"><?= icon("clock", 14) ?> We are checking your payment</span>
                    <?php endif; ?>
                    <a class="btn" href="reservations.php?open=<?= $reservationId ?>">View reservation</a>
                </div>
            <?php endif; ?>
        </div>
    </section>

<?php else: ?>

    <div class="ticket-page">

        <article class="ticket" aria-label="Ticket for reservation #<?= $reservationId ?>">

            <header class="ticket-top">
                <span class="brand-mark ticket-brand" aria-hidden="true"><?= icon("home") ?></span>
                <span class="ticket-check" aria-hidden="true"><?= icon("check-circle") ?></span>
                <h2>Your Ticket</h2>
                <p class="ticket-state"><?= $reservation["status"] === "completed" ? "Stay Completed" : "Booking Confirmed" ?></p>

                <p class="ticket-code">Booking code: <strong><?= h($code) ?></strong></p>

                <div class="ticket-qr" data-ticket-qr="<?= h(ticket_url($pdo, $reservationId)) ?>" data-ticket-code="<?= h($code) ?>" role="img" aria-label="QR code of booking <?= h($code) ?>">
                    <span class="ticket-qr-wait"><?= icon("qr-code") ?></span>
                </div>
                <p class="ticket-hint">Show this QR code at the front desk when you arrive.</p>
            </header>

            <div class="ticket-tear" aria-hidden="true"></div>

            <section class="ticket-stay">
                <div>
                    <span>Check-in</span>
                    <strong><?= h(admin_date($reservation["check_in"], "D, M j")) ?></strong>
                    <small><?= h(admin_date($reservation["check_in"], "Y")) ?></small>
                </div>
                <div class="ticket-mid">
                    <span><?= h($reservation["room_name"]) ?></span>
                    <em><?= $nights ?> <?= $nights === 1 ? "night" : "nights" ?></em>
                    <i class="ticket-arrow" aria-hidden="true"></i>
                </div>
                <div class="ticket-end">
                    <span>Check-out</span>
                    <strong><?= h(admin_date($reservation["check_out"], "D, M j")) ?></strong>
                    <small><?= h(admin_date($reservation["check_out"], "Y")) ?></small>
                </div>
            </section>

            <section class="ticket-people">
                <div>
                    <span>Guest</span>
                    <strong><?= h($me["full_name"]) ?></strong>
                </div>
                <div>
                    <span>Guests</span>
                    <strong><?= $guests ?></strong>
                </div>
                <div class="ticket-end">
                    <span><?= $reservation["payment_status"] === "verified" ? "Paid" : "Total" ?></span>
                    <strong><?= h(peso($reservation["payment_status"] === "verified" && $reservation["paid_amount"] !== null ? $reservation["paid_amount"] : $reservation["total_amount"])) ?></strong>
                </div>
            </section>

        </article>

        <div class="ticket-actions">
            <button class="btn btn-primary" type="button" data-ticket-print><?= icon("printer") ?> Save or print ticket</button>
            <button class="btn btn-icon" type="button" data-ticket-share data-copy="<?= h($shareText) ?>" aria-label="Share your booking"><?= icon("share") ?></button>
        </div>

        <p class="hint ticket-note">
            The front desk scans the QR code to find your booking. Keep this page or a printed copy with you.
        </p>

    </div>

    <script src="<?= h(admin_asset("vendor/qrcode.js")) ?>"></script>
    <script>
        (function () {
            // the QR code, drawn here from the address it holds (the code is also written above it)
            var holder = document.querySelector("[data-ticket-qr]");

            if (holder && typeof qrcode === "function") {
                var qr = qrcode(0, "M");
                qr.addData(holder.getAttribute("data-ticket-qr"));
                qr.make();
                holder.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 8, scalable: true });
            }

            // save or print: the browser's print window, where "Save as PDF" is one of the printers
            var print = document.querySelector("[data-ticket-print]");
            if (print) print.addEventListener("click", function () { window.print(); });

            // share where the phone can; elsewhere the button copies the text (data-copy in admin.js)
            var share = document.querySelector("[data-ticket-share]");

            if (share && navigator.share) {
                share.addEventListener("click", function (event) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    navigator.share({ title: "ARVE'S House booking", text: share.getAttribute("data-copy") }).catch(function () {});
                }, true);
            }
        })();
    </script>

<?php endif; ?>

<?php customer_shell_end(); ?>
