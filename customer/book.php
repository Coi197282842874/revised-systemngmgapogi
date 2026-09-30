<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/customer-shell.php";
require_once __DIR__ . "/../includes/availability.php";
require_once __DIR__ . "/../includes/hero-scenes.php";

// ======================================================
// BOOK A ROOM (the old reservation.php sends here)
// ======================================================

// an admin who tries to book is told that bookings need a customer account
if (isset($_SESSION["user_id"]) && ($_SESSION["role"] ?? "") === "admin") {
    header("Location: ../index.php?customer_required=1");
    exit;
}

$me = customer_boot($pdo);
$userId = (int) $me["id"];

ensure_availability_schema($pdo);

$roomId = (int) ($_GET["room_id"] ?? $_POST["room_id"] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ? LIMIT 1");
$stmt->execute([$roomId]);
$room = $stmt->fetch();

// only a room guests can book
if (!$room || $room["status"] !== "available") {
    header("Location: ../rooms.php");
    exit;
}

$today = date("Y-m-d");
$error = "";

$selectedCheckIn = (string) ($_POST["check_in"] ?? $_GET["check_in"] ?? "");
$selectedCheckOut = (string) ($_POST["check_out"] ?? $_GET["check_out"] ?? "");
$selectedGuests = (int) ($_POST["guests"] ?? 0);


// ======================================================
// SAVING THE RESERVATION
// ======================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $checkIn = trim($selectedCheckIn);
    $checkOut = trim($selectedCheckOut);
    $guests = $selectedGuests;
    $acceptedTerms = ($_POST["accept_terms"] ?? "") === "1";

    $checkInDate = DateTime::createFromFormat("!Y-m-d", $checkIn);
    $checkOutDate = DateTime::createFromFormat("!Y-m-d", $checkOut);

    if (!admin_check_csrf()) {
        $error = "Your session expired. Please try again.";
    } elseif ($checkIn === "" || $checkOut === "" || $guests < 1) {
        $error = "Please complete all reservation details.";
    } elseif (!$acceptedTerms) {
        $error = "Please agree to the Terms and Conditions to confirm your reservation.";
    } elseif (!$checkInDate || $checkInDate->format("Y-m-d") !== $checkIn || !$checkOutDate || $checkOutDate->format("Y-m-d") !== $checkOut) {
        $error = "Please enter valid dates.";
    } elseif ($checkIn < $today) {
        $error = "Check-in date cannot be in the past.";
    } elseif ($checkOutDate <= $checkInDate) {
        $error = "Check-out date must be after check-in date.";
    } elseif ($guests > (int) $room["capacity"]) {
        $error = "This room can accommodate a maximum of " . (int) $room["capacity"] . " guest(s).";
    } else {
        // the last word on availability: other reservations, and dates closed by the admin
        $unavailable = room_unavailable_reason($pdo, $roomId, $checkIn, $checkOut);

        if ($unavailable !== "") {
            $error = $unavailable;
        } else {
            $totalNights = $checkInDate->diff($checkOutDate)->days;
            $pricePerNight = (float) $room["price"];
            $totalAmount = $totalNights * $pricePerNight;

            try {
                $pdo->prepare("
                    INSERT INTO reservations
                        (user_id, room_id, check_in, check_out, guests, price_per_night, total_nights, total_amount, status, terms_accepted_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
                ")->execute([$userId, $roomId, $checkIn, $checkOut, $guests, $pricePerNight, $totalNights, $totalAmount, utc_now()]);

                $reservationId = (int) $pdo->lastInsertId();

                // shows up in the admin's notifications and activity log
                log_activity(
                    $pdo,
                    "reservation.created",
                    ($me["full_name"] ?: "A customer") . " booked " . $room["room_name"] . ", "
                        . date("M j", strtotime($checkIn)) . " to " . date("M j", strtotime($checkOut)),
                    [
                        "entity_type" => "reservation",
                        "entity_id" => $reservationId,
                        "link" => "reservations.php?q=" . $reservationId,
                        "notify" => true,
                    ]
                );

                header("Location: booked.php?id=" . $reservationId);
                exit;
            } catch (PDOException $exception) {
                error_log("ARVE'S House booking failed: " . $exception->getMessage());
                $error = "Unable to save the reservation. Please try again.";
            }
        }
    }
}

$photo = customer_room_photo($roomId);
$capacity = (int) $room["capacity"];

customer_shell_head([
    "title" => "Book a Room",
    "subtitle" => "Choose your dates and the number of guests",
    "active" => "book",
]);
?>
<?php customer_shell_body(); ?>

<div class="grid grid-side">

    <!-- THE ROOM -->

    <aside class="card stay-card">
        <div class="stay-photo">
            <?php if ($photo !== ""): ?>
                <img src="<?= h($photo) ?>" alt="<?= h($room["room_name"]) ?>" decoding="async">
            <?php else: ?>
                <?= room_scene("dusk", "book") ?>
            <?php endif; ?>
        </div>

        <div class="stay-body">
            <span><?= status_pill("available", "Available room") ?></span>
            <h2><?= h($room["room_name"]) ?></h2>
            <p><?= nl2br(h(trim((string) ($room["description"] ?? "")) ?: "Comfortable room available for your stay.")) ?></p>

            <ul class="stay-facts">
                <li><span>Up to</span><strong><?= $capacity ?> <?= $capacity === 1 ? "guest" : "guests" ?></strong></li>
                <li><span>Price</span><strong><?= h(peso($room["price"], 2)) ?> / night</strong></li>
            </ul>
        </div>
    </aside>


    <!-- THE BOOKING -->

    <section class="card card-pad">
        <h2 class="card-title">Reservation details</h2>
        <p class="card-sub" style="margin-bottom:16px">Your room is held as a pending reservation until your payment is done.</p>

        <?php if ($error !== ""): ?>
            <div class="alert alert-error alert-in" role="alert"><?= icon("alert") ?><p><?= h($error) ?></p></div>
        <?php endif; ?>

        <form method="post" action="book.php?room_id=<?= $roomId ?>" class="stack" style="gap:16px">
            <?= csrf_field() ?>
            <input type="hidden" name="room_id" value="<?= $roomId ?>">

            <div class="form-grid">
                <label class="field">
                    <span class="label">Check-in</span>
                    <input class="input" type="date" id="check_in" name="check_in" min="<?= h($today) ?>" value="<?= h($selectedCheckIn) ?>" required>
                </label>

                <label class="field">
                    <span class="label">Check-out</span>
                    <input class="input" type="date" id="check_out" name="check_out" min="<?= h($today) ?>" value="<?= h($selectedCheckOut) ?>" required>
                </label>

                <div class="field field-wide">
                    <?php $calendarRoomId = $roomId; require __DIR__ . "/../includes/availability-calendar.php"; ?>
                </div>

                <label class="field field-wide">
                    <span class="label">Number of guests</span>
                    <select class="select" id="guests" name="guests" required>
                        <option value="">Select guests</option>
                        <?php for ($count = 1; $count <= $capacity; $count++): ?>
                            <option value="<?= $count ?>" <?= $selectedGuests === $count ? "selected" : "" ?>><?= $count ?> <?= $count === 1 ? "guest" : "guests" ?></option>
                        <?php endfor; ?>
                    </select>
                </label>
            </div>

            <div class="price-card" aria-live="polite">
                <div class="price-row"><span>Price per night</span><strong><?= h(peso($room["price"], 2)) ?></strong></div>
                <div class="price-row"><span>Nights</span><strong id="nightCount">0</strong></div>
                <div class="price-total"><span>Estimated total</span><strong id="totalAmount">₱0.00</strong></div>
            </div>

            <div class="alert alert-info">
                <?= icon("info") ?>
                <p>Your reservation starts as <strong>Pending</strong>. It is confirmed as soon as your payment is accepted.</p>
            </div>

            <label class="check-row">
                <input type="checkbox" id="accept_terms" name="accept_terms" value="1" required>
                <span>I have read and agree to the <a href="#terms">Terms and Conditions</a>, including the payment, cancellation and house rules.</span>
            </label>

            <div class="form-actions" style="margin-top:0">
                <button class="btn btn-primary" type="submit"><?= icon("check") ?> Confirm reservation</button>
                <a class="btn btn-ghost" href="../rooms.php"><?= icon("arrow-left") ?> Back to rooms</a>
            </div>
        </form>
    </section>

</div>

<script>
    // the check-out can't be before the day after check-in; the total follows the dates
    (function () {
        var checkIn = document.getElementById("check_in");
        var checkOut = document.getElementById("check_out");
        var nights = document.getElementById("nightCount");
        var total = document.getElementById("totalAmount");
        var price = <?= json_encode((float) $room["price"]) ?>;

        function day(value) {
            return new Date(value + "T00:00:00");
        }

        function updateCheckoutMinimum() {
            if (!checkIn.value) return;

            var next = day(checkIn.value);
            next.setDate(next.getDate() + 1);

            var minimum = next.getFullYear() + "-" + String(next.getMonth() + 1).padStart(2, "0") + "-" + String(next.getDate()).padStart(2, "0");
            checkOut.min = minimum;

            if (checkOut.value && checkOut.value < minimum) {
                checkOut.value = "";
            }
        }

        function calculateTotal() {
            var count = checkIn.value && checkOut.value
                ? Math.round((day(checkOut.value) - day(checkIn.value)) / 86400000)
                : 0;

            if (count <= 0) {
                nights.textContent = "0";
                total.textContent = "₱0.00";
                return;
            }

            nights.textContent = count;
            total.textContent = "₱" + (count * price).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        checkIn.addEventListener("change", function () {
            updateCheckoutMinimum();
            calculateTotal();
        });

        checkOut.addEventListener("change", calculateTotal);

        updateCheckoutMinimum();
        calculateTotal();
    })();
</script>

<?php customer_shell_end(); ?>
