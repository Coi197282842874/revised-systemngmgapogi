<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/customer-shell.php";
require_once __DIR__ . "/../includes/hero-scenes.php";

$me = customer_boot($pdo);
$userId = (int) $me["id"];

// online payments finished (or given up) on PayMongo since the guest last came by
paymongo_settle_pending($pdo, $userId);

$counts = customer_counts($pdo, $userId);


// ======================================================
// THE GUEST'S RESERVATIONS, WITH THEIR LATEST PAYMENT
// ======================================================

$stmt = $pdo->prepare("
    SELECT r.*, rooms.room_name, lp.status AS payment_status, lp.payment_method
    FROM reservations r
    INNER JOIN rooms ON rooms.id = r.room_id
    " . CUSTOMER_LATEST_PAYMENT . "
    WHERE r.user_id = ?
    ORDER BY r.created_at DESC, r.id DESC
");
$stmt->execute([$userId]);
$reservations = $stmt->fetchAll();

// the next stay: booked or waiting, not over yet, the soonest first
$nextStay = null;

foreach ($reservations as $reservation) {
    if (
        in_array($reservation["status"], ["pending", "confirmed"], true)
        && $reservation["check_out"] > date("Y-m-d")
        && ($nextStay === null || $reservation["check_in"] < $nextStay["check_in"])
    ) {
        $nextStay = $reservation;
    }
}

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(total_nights), 0) FROM reservations
    WHERE user_id = ? AND status IN ('confirmed', 'completed')
");
$stmt->execute([$userId]);
$nights = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE user_id = ? AND status = 'verified'");
$stmt->execute([$userId]);
$paid = (float) $stmt->fetchColumn();

$history = customer_events($pdo, $userId, 8, false);

// waiting for the guest: the first one with something in it stands out
$todo = [
    [$counts["to_pay"], "Waiting for your payment", "To pay", "credit-card", "amber", "reservations.php?show=to_pay"],
    [$counts["checking"], "Payments being checked", "Checking", "clock", "cyan", "payments.php"],
    [$counts["unread_messages"], "New messages from us", "Messages", "message", "green", "messages.php#latest"],
    [$counts["unread_updates"], "New updates", "Updates", "bell", "violet", "notifications.php"],
];

$featured = null;

foreach ($todo as $index => $item) {
    if ($item[0] > 0) {
        $featured = $index;
        break;
    }
}

customer_shell_head([
    "title" => "Dashboard",
    "subtitle" => "Welcome back, " . customer_first_name($me),
    "active" => "dashboard",
]);
?>
<style>
    .dash-actions {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }

    .dash-actions p {
        margin: 0;
        color: var(--text-2);
    }

    .help-card {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .help-card p {
        margin: 0;
        color: var(--text-2);
    }

    @media (max-width: 767px) {
        .dash-actions {
            display: none;
        }
    }
</style>
<?php customer_shell_body(); ?>

<div class="stack">

    <div class="dash-actions">
        <p>Your bookings, payments and messages with ARVE'S House, in one place.</p>
        <a class="btn btn-primary" href="../rooms.php"><?= icon("plus") ?> Book a room</a>
    </div>


    <!-- NUMBERS -->

    <section class="stats" aria-label="Your stays">

        <a class="stat tone-blue" href="reservations.php?show=upcoming">
            <div class="stat-main">
                <div class="stat-label">Upcoming stays</div>
                <div class="stat-value"><?= number_format($counts["upcoming"]) ?></div>
                <div class="stat-note">Booked, still to come</div>
            </div>
            <div class="stat-icon"><?= icon("calendar") ?></div>
        </a>

        <a class="stat tone-violet" href="reservations.php">
            <div class="stat-main">
                <div class="stat-label">Reservations</div>
                <div class="stat-value"><?= number_format($counts["reservations"]) ?></div>
                <div class="stat-note">Since you joined</div>
            </div>
            <div class="stat-icon"><?= icon("clipboard") ?></div>
        </a>

        <a class="stat tone-cyan" href="reservations.php?show=past">
            <div class="stat-main">
                <div class="stat-label">Nights with us</div>
                <div class="stat-value"><?= number_format($nights) ?></div>
                <div class="stat-note">Confirmed and completed</div>
            </div>
            <div class="stat-icon"><?= icon("moon") ?></div>
        </a>

        <a class="stat tone-green" href="payments.php">
            <div class="stat-main">
                <div class="stat-label">Total paid</div>
                <div class="stat-value"><?= h(peso($paid)) ?></div>
                <div class="stat-note">Payments accepted</div>
            </div>
            <div class="stat-icon"><?= icon("wallet") ?></div>
        </a>

    </section>


    <!-- WAITING FOR THE GUEST (phones: quick actions) -->

    <h2 class="todo-title">Quick Actions</h2>

    <section class="todo" aria-label="Waiting for you">
        <?php foreach ($todo as $index => [$count, $label, $short, $iconName, $tone, $href]): ?>
            <a class="todo-item<?= $count === 0 ? " is-clear" : "" ?><?= $index === $featured ? " is-featured" : "" ?>" href="<?= h($href) ?>">
                <span class="tile-icon tone-<?= $count === 0 && $index !== $featured ? "gray" : $tone ?>"><?= icon($iconName) ?></span>
                <span class="todo-text">
                    <span class="todo-count"><?= number_format($count) ?></span>
                    <span class="todo-label"><span class="todo-long"><?= h($label) ?></span><span class="todo-short"><?= h($short) ?></span></span>
                </span>
                <?= icon("chevron-right", 16) ?>
            </a>
        <?php endforeach; ?>
    </section>


    <!-- THE NEXT STAY -->

    <?php if ($nextStay): ?>
        <?php
        $id = (int) $nextStay["id"];
        $photo = customer_room_photo((int) $nextStay["room_id"]);
        $when = customer_countdown($nextStay["check_in"], $nextStay["check_out"]);
        $nightsHere = (int) $nextStay["total_nights"];
        ?>
        <section class="card next-stay" aria-labelledby="next-stay-title">
            <div class="next-stay-photo">
                <?php if ($photo !== ""): ?>
                    <img src="<?= h($photo) ?>" alt="<?= h($nextStay["room_name"]) ?>" decoding="async">
                <?php else: ?>
                    <?= room_scene("dusk", "next") ?>
                <?php endif; ?>

                <?php if ($when !== ""): ?>
                    <span class="next-stay-when"><?= h($when) ?></span>
                <?php endif; ?>
            </div>

            <div class="next-stay-body">
                <span class="eyebrow">Your next stay · #<?= $id ?></span>
                <h2 id="next-stay-title"><?= h($nextStay["room_name"]) ?></h2>

                <div class="next-stay-pills">
                    <?= status_pill($nextStay["status"]) ?>
                    <?= customer_payment_pill($nextStay["payment_status"]) ?>
                </div>

                <div class="next-stay-facts">
                    <div><span>Check-in</span><strong><?= h(admin_date($nextStay["check_in"], "D, M j")) ?></strong></div>
                    <div><span>Check-out</span><strong><?= h(admin_date($nextStay["check_out"], "D, M j")) ?></strong></div>
                    <div><span>Guests</span><strong><?= (int) $nextStay["guests"] ?></strong></div>
                </div>

                <p class="soft" style="margin:0">
                    <?= $nightsHere ?> <?= $nightsHere === 1 ? "night" : "nights" ?> · <?= h(peso($nextStay["total_amount"], 2)) ?> in total
                </p>

                <div class="next-stay-actions">
                    <?php if (ticket_available($nextStay)): ?>
                        <a class="btn btn-primary" href="ticket.php?id=<?= $id ?>"><?= icon("ticket") ?> View ticket</a>
                    <?php elseif (customer_can_pay($nextStay)): ?>
                        <a class="btn btn-primary" href="pay.php?reservation_id=<?= $id ?>"><?= icon("credit-card") ?> <?= $nextStay["payment_status"] === "rejected" ? "Pay again" : "Pay now" ?></a>
                    <?php elseif ($nextStay["payment_status"] === "pending"): ?>
                        <span class="wait-note"><?= icon("clock", 14) ?> We are checking your payment</span>
                    <?php endif; ?>
                    <a class="btn" href="reservations.php?open=<?= $id ?>">View details</a>
                </div>
            </div>
        </section>
    <?php elseif (!$reservations): ?>
        <section class="card first-stay">
            <div>
                <span class="eyebrow">Welcome to ARVE'S House</span>
                <h2>Plan your first stay</h2>
                <p>Choose your dates, pick a room and book it in a few minutes. Your bookings and payments will show up here.</p>
            </div>
            <a class="btn btn-primary" href="../rooms.php"><?= icon("bed") ?> See the rooms</a>
        </section>
    <?php endif; ?>


    <!-- PHONES: THE LATEST BOOKINGS AS A LIST -->

    <?php if ($reservations): ?>
        <section class="recent show-sm" aria-label="My reservations">
            <div class="recent-head">
                <h2>My Reservations</h2>
                <a href="reservations.php">View All</a>
            </div>

            <ul class="recent-list">
                <?php foreach (array_slice($reservations, 0, 5) as $reservation): ?>
                    <?php $photo = customer_room_photo((int) $reservation["room_id"]); ?>
                    <li>
                        <a class="recent-item" href="reservations.php?open=<?= (int) $reservation["id"] ?>">
                            <?php if ($photo !== ""): ?>
                                <img class="recent-thumb" src="<?= h($photo) ?>" alt="" loading="lazy" decoding="async">
                            <?php else: ?>
                                <span class="tile-icon tone-blue"><?= icon("bed") ?></span>
                            <?php endif; ?>
                            <span class="recent-text">
                                <span class="recent-name"><?= h($reservation["room_name"]) ?></span>
                                <span class="recent-sub">
                                    <?= status_pill($reservation["status"]) ?>
                                    <span><?= h(admin_stay($reservation["check_in"], $reservation["check_out"])) ?></span>
                                </span>
                            </span>
                            <span class="recent-end">
                                <span class="recent-amount"><?= h(peso($reservation["total_amount"])) ?></span>
                                <span class="recent-note">#<?= (int) $reservation["id"] ?></span>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>


    <div class="grid grid-main">

        <!-- LEFT: the latest bookings -->

        <div class="col">
            <section class="card hide-sm">
                <div class="card-head">
                    <h2 class="card-title">My Reservations</h2>
                    <?php if ($reservations): ?>
                        <a class="card-link" href="reservations.php">View all</a>
                    <?php endif; ?>
                </div>

                <?php if ($reservations): ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Room</th>
                                    <th>Stay</th>
                                    <th class="right">Amount</th>
                                    <th>Payment</th>
                                    <th>Status</th>
                                    <th class="right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($reservations, 0, 5) as $reservation): ?>
                                    <?php $id = (int) $reservation["id"]; ?>
                                    <tr>
                                        <td class="cell-clip">
                                            <strong><?= h($reservation["room_name"]) ?></strong>
                                            <span class="cell-sub">#<?= $id ?></span>
                                        </td>
                                        <td class="nowrap">
                                            <?= h(admin_stay($reservation["check_in"], $reservation["check_out"])) ?>
                                            <span class="cell-sub"><?= (int) $reservation["total_nights"] ?> <?= (int) $reservation["total_nights"] === 1 ? "night" : "nights" ?></span>
                                        </td>
                                        <td class="right num strong"><?= h(peso($reservation["total_amount"])) ?></td>
                                        <td><?= customer_payment_pill($reservation["payment_status"]) ?></td>
                                        <td><?= status_pill($reservation["status"]) ?></td>
                                        <td class="right">
                                            <?php if (ticket_available($reservation)): ?>
                                                <a class="btn btn-sm btn-primary" href="ticket.php?id=<?= $id ?>"><?= icon("ticket") ?> Ticket</a>
                                            <?php elseif (customer_can_pay($reservation)): ?>
                                                <a class="btn btn-sm btn-primary" href="pay.php?reservation_id=<?= $id ?>"><?= $reservation["payment_status"] === "rejected" ? "Pay again" : "Pay now" ?></a>
                                            <?php else: ?>
                                                <a class="btn btn-sm btn-ghost" href="reservations.php?open=<?= $id ?>">Details</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="card-foot">
                        <a class="card-link" href="reservations.php">View all reservations <?= icon("chevron-right", 14) ?></a>
                    </div>
                <?php else: ?>
                    <div class="empty">
                        <div class="empty-icon"><?= icon("calendar", 22) ?></div>
                        <h3>No reservations yet</h3>
                        <p>Browse the rooms and book your first stay.</p>
                        <a class="btn btn-primary" href="../rooms.php">View available rooms</a>
                    </div>
                <?php endif; ?>
            </section>
        </div>


        <!-- RIGHT: what happened, and help -->

        <div class="col">
            <section class="card">
                <div class="card-head">
                    <h2 class="card-title">Recent Activity</h2>
                    <a class="card-link" href="notifications.php">Updates</a>
                </div>
                <?= customer_feed($history, $userId, ["new_since" => $me["notifications_seen_at"], "empty" => "Your bookings and payments will show up here"]) ?>
            </section>

            <section class="card card-pad help-card">
                <h2 class="card-title">Need help?</h2>
                <p>Questions about a room, your booking or a payment? Send us a message and we will reply here.</p>
                <div class="row row-wrap">
                    <a class="btn btn-primary" href="messages.php"><?= icon("message") ?> Message us</a>
                    <button class="btn btn-ghost" type="button" data-terms-open><?= icon("file-text") ?> House rules</button>
                </div>
            </section>
        </div>

    </div>

</div>

<?php customer_shell_end(); ?>
