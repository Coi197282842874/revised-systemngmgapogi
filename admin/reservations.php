<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/admin-shell.php";
require_once __DIR__ . "/../includes/paymongo.php";

$admin = admin_boot($pdo, "reservations");

const RESERVATIONS_PER_PAGE = 25;


// ======================================================
// FILTERS: ?status=  ?q=  ?page=
// q: "12" or "#12" finds reservation 12; anything else is looked up in the guest's name,
// the guest's email and the room's name.
// ======================================================

$statuses = ["all", "pending", "confirmed", "completed", "declined", "cancelled"];

$statusFilter = $_GET["status"] ?? "all";

if (!in_array($statusFilter, $statuses, true)) {
    $statusFilter = "all";
}

$search = admin_query();

$here = fn (array $change = []) => admin_url(
    "reservations.php",
    $change + ["status" => $statusFilter, "q" => $search, "page" => (int) ($_GET["page"] ?? 1)]
);


// ======================================================
// DECLINE / COMPLETE
// ======================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $reservationId = (int) ($_POST["reservation_id"] ?? 0);
    $action = $_POST["action"] ?? "";

    if (!admin_check_csrf()) {
        admin_flash("Your session expired. Please try again.", "error");
    } elseif ($reservationId > 0) {

        $stmt = $pdo->prepare("
            SELECT
                reservations.id,
                reservations.status,
                reservations.check_in,
                reservations.check_out,
                rooms.room_name,
                users.full_name AS customer_name,
                (
                    SELECT payments.status
                    FROM payments
                    WHERE payments.reservation_id = reservations.id
                    ORDER BY payments.id DESC
                    LIMIT 1
                ) AS payment_status
            FROM reservations
            INNER JOIN rooms ON rooms.id = reservations.room_id
            INNER JOIN users ON users.id = reservations.user_id
            WHERE reservations.id = ?
            LIMIT 1
        ");

        $stmt->execute([$reservationId]);
        $reservation = $stmt->fetch();

        if (!$reservation) {
            admin_flash("That reservation no longer exists.", "error");
        } elseif (
            $action === "decline"
            && $reservation["status"] === "pending"
            && ($reservation["payment_status"] ?? null) !== "verified"
        ) {

            $stmt = $pdo->prepare("UPDATE reservations SET status = 'declined' WHERE id = ? AND status = 'pending'");
            $stmt->execute([$reservationId]);

            if ($stmt->rowCount() > 0) {
                log_activity(
                    $pdo,
                    "reservation.declined",
                    "Declined reservation #" . $reservationId . " of " . $reservation["customer_name"]
                        . " (" . $reservation["room_name"] . ", " . date("M j", strtotime($reservation["check_in"]))
                        . " to " . date("M j", strtotime($reservation["check_out"])) . ")",
                    ["entity_type" => "reservation", "entity_id" => $reservationId, "link" => "reservations.php?q=" . $reservationId]
                );

                admin_flash("Reservation #" . $reservationId . " was declined. Its dates are open again.");
            }

        } elseif ($action === "complete" && $reservation["status"] === "confirmed") {

            $stmt = $pdo->prepare("UPDATE reservations SET status = 'completed' WHERE id = ? AND status = 'confirmed'");
            $stmt->execute([$reservationId]);

            if ($stmt->rowCount() > 0) {
                log_activity(
                    $pdo,
                    "reservation.completed",
                    "Marked reservation #" . $reservationId . " of " . $reservation["customer_name"] . " as completed",
                    ["entity_type" => "reservation", "entity_id" => $reservationId, "link" => "reservations.php?q=" . $reservationId]
                );

                admin_flash("Reservation #" . $reservationId . " is marked as completed.");
            }

        } else {
            admin_flash("Reservation #" . $reservationId . " could not be changed: its status is already different.", "error");
        }
    }

    header("Location: " . $here());
    exit;
}


// ======================================================
// NUMBERS ON TOP (all reservations, whatever the filters)
// ======================================================

$totals = array_fill_keys($statuses, 0);

foreach ($pdo->query("SELECT status, COUNT(*) AS total FROM reservations GROUP BY status") as $row) {
    if (isset($totals[$row["status"]])) {
        $totals[$row["status"]] = (int) $row["total"];
    }

    $totals["all"] += (int) $row["total"];
}


// ======================================================
// THE LIST
// ======================================================

$from = "
    FROM reservations
    INNER JOIN rooms ON reservations.room_id = rooms.id
    INNER JOIN users ON reservations.user_id = users.id
";

$where = [];
$params = [];

if ($search !== "") {
    if (preg_match('/^#?(\d{1,9})$/', $search, $match)) {
        $where[] = "reservations.id = ?";
        $params[] = (int) $match[1];
    } else {
        $where[] = "(users.full_name LIKE ? OR users.email LIKE ? OR rooms.room_name LIKE ?)";
        array_push($params, admin_like($search), admin_like($search), admin_like($search));
    }
}

// the numbers on the tabs follow the search
$tabCounts = array_fill_keys($statuses, 0);

$stmt = $pdo->prepare(
    "SELECT reservations.status, COUNT(*) AS total" . $from
    . ($where ? " WHERE " . implode(" AND ", $where) : "")
    . " GROUP BY reservations.status"
);
$stmt->execute($params);

foreach ($stmt as $row) {
    if (isset($tabCounts[$row["status"]])) {
        $tabCounts[$row["status"]] = (int) $row["total"];
    }

    $tabCounts["all"] += (int) $row["total"];
}

if ($statusFilter !== "all") {
    $where[] = "reservations.status = ?";
    $params[] = $statusFilter;
}

$paging = admin_paginate($tabCounts[$statusFilter], RESERVATIONS_PER_PAGE);

// one reservation = one row, with its latest payment
$stmt = $pdo->prepare("
    SELECT
        reservations.*,
        rooms.room_name,
        users.full_name AS customer_name,
        users.email AS customer_email,
        users.phone AS customer_phone,
        users.profile_image,

        (
            SELECT payments.status
            FROM payments
            WHERE payments.reservation_id = reservations.id
            AND payments.user_id = reservations.user_id
            ORDER BY payments.id DESC
            LIMIT 1
        ) AS payment_status,

        (
            SELECT payments.id
            FROM payments
            WHERE payments.reservation_id = reservations.id
            AND payments.user_id = reservations.user_id
            ORDER BY payments.id DESC
            LIMIT 1
        ) AS payment_id,

        (
            SELECT payments.payment_method
            FROM payments
            WHERE payments.reservation_id = reservations.id
            AND payments.user_id = reservations.user_id
            ORDER BY payments.id DESC
            LIMIT 1
        ) AS payment_method
    " . $from
    . ($where ? " WHERE " . implode(" AND ", $where) : "")
    . " ORDER BY reservations.created_at DESC, reservations.id DESC
        LIMIT " . $paging["per_page"] . " OFFSET " . $paging["offset"]
);

$stmt->execute($params);
$reservations = $stmt->fetchAll();

admin_shell_head([
    "title" => "Reservations",
    "subtitle" => "Every booking, its payment and what to do next",
    "active" => "reservations",
]);
?>
<style>
    .res-number {
        color: var(--text-3);
        font-variant-numeric: tabular-nums;
    }

    .wait-note {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: var(--text-3);
        font-size: 12.5px;
        white-space: nowrap;
    }

    @media (max-width: 767px) {
        .wait-note {
            white-space: normal;
        }
    }
</style>
<?php admin_shell_body(); ?>

<div class="stack">

    <!-- NUMBERS -->

    <section class="stats stats-compact" aria-label="All reservations">

        <a class="stat tone-blue" href="reservations.php">
            <div class="stat-main">
                <div class="stat-label">All reservations</div>
                <div class="stat-value"><?= number_format($totals["all"]) ?></div>
                <div class="stat-note">Since the beginning</div>
            </div>
            <div class="stat-icon"><?= icon("clipboard") ?></div>
        </a>

        <a class="stat tone-amber" href="reservations.php?status=pending">
            <div class="stat-main">
                <div class="stat-label">Pending</div>
                <div class="stat-value"><?= number_format($totals["pending"]) ?></div>
                <div class="stat-note">Waiting for payment</div>
            </div>
            <div class="stat-icon"><?= icon("clock") ?></div>
        </a>

        <a class="stat tone-green" href="reservations.php?status=confirmed">
            <div class="stat-main">
                <div class="stat-label">Confirmed</div>
                <div class="stat-value"><?= number_format($totals["confirmed"]) ?></div>
                <div class="stat-note">Paid, stay still to come</div>
            </div>
            <div class="stat-icon"><?= icon("check-circle") ?></div>
        </a>

        <a class="stat tone-violet" href="reservations.php?status=completed">
            <div class="stat-main">
                <div class="stat-label">Completed</div>
                <div class="stat-value"><?= number_format($totals["completed"]) ?></div>
                <div class="stat-note">Guests who have stayed</div>
            </div>
            <div class="stat-icon"><?= icon("star") ?></div>
        </a>

    </section>


    <div>

        <!-- FILTERS -->

        <div class="page-bar">
            <nav class="tabs" aria-label="Status">
                <?php foreach ($statuses as $status): ?>
                    <a
                        class="tab<?= $statusFilter === $status ? " is-active" : "" ?>"
                        href="<?= h($here(["status" => $status, "page" => 1])) ?>"
                        <?= $statusFilter === $status ? 'aria-current="page"' : "" ?>
                    >
                        <?= ucfirst($status) ?>
                        <span class="tab-count"><?= number_format($tabCounts[$status]) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <form class="filter-form" method="get" role="search">
                <?php if ($statusFilter !== "all"): ?>
                    <input type="hidden" name="status" value="<?= h($statusFilter) ?>">
                <?php endif; ?>
                <div class="filter-search">
                    <?= icon("search") ?>
                    <label class="sr-only" for="q">Search reservations</label>
                    <input
                        class="input"
                        type="search"
                        id="q"
                        name="q"
                        value="<?= h($search) ?>"
                        placeholder="Guest, room or #number"
                        autocomplete="off"
                        enterkeyhint="search"
                    >
                </div>
                <button class="btn" type="submit">Search</button>
            </form>
        </div>

        <?php if ($search !== ""): ?>
            <p class="filter-note">
                <?= number_format($tabCounts[$statusFilter]) ?>
                <?= $tabCounts[$statusFilter] === 1 ? "reservation matches" : "reservations match" ?>
                “<?= h($search) ?>”.
                <a href="<?= h($here(["q" => "", "page" => 1])) ?>">Clear the search</a>
            </p>
        <?php endif; ?>


        <!-- TABLE -->

        <section class="card">

            <?php if ($reservations): ?>

                <div class="table-wrap">
                    <table class="table table-stack">
                        <thead>
                            <tr>
                                <th>Guest</th>
                                <th>Room</th>
                                <th>Stay</th>
                                <th class="right hide-narrow">Guests</th>
                                <th class="right">Amount</th>
                                <th>Payment</th>
                                <th>Status</th>
                                <th class="hide-narrow">Booked</th>
                                <th class="right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reservations as $reservation): ?>
                                <?php
                                $id = (int) $reservation["id"];
                                $status = $reservation["status"];
                                $paymentStatus = $reservation["payment_status"] ?? null;
                                $nights = (int) $reservation["total_nights"];
                                ?>
                                <tr>
                                    <td class="cell-lead">
                                        <div class="person">
                                            <?= admin_avatar(["full_name" => $reservation["customer_name"], "profile_image" => $reservation["profile_image"]], 34) ?>
                                            <div class="person-text">
                                                <span class="person-name"><?= h($reservation["customer_name"]) ?></span>
                                                <span class="person-sub"><span class="res-number">#<?= $id ?></span> · <?= h($reservation["customer_email"]) ?></span>
                                            </div>
                                        </div>
                                    </td>

                                    <td data-label="Room" class="cell-clip"><?= h($reservation["room_name"]) ?></td>

                                    <td data-label="Stay" class="nowrap">
                                        <?= h(admin_stay($reservation["check_in"], $reservation["check_out"])) ?>
                                        <span class="cell-sub"><?= $nights ?> <?= $nights === 1 ? "night" : "nights" ?></span>
                                    </td>

                                    <td data-label="Guests" class="right num hide-narrow"><?= (int) $reservation["guests"] ?></td>

                                    <td data-label="Amount" class="right num strong"><?= h(peso($reservation["total_amount"])) ?></td>

                                    <td data-label="Payment">
                                        <?php if ($paymentStatus): ?>
                                            <?= status_pill($paymentStatus) ?>
                                            <span class="cell-sub"><?= h(payment_method_label((string) $reservation["payment_method"])) ?></span>
                                        <?php else: ?>
                                            <?= status_pill("not set up", "Not paid") ?>
                                        <?php endif; ?>
                                    </td>

                                    <td data-label="Status"><?= status_pill($status) ?></td>

                                    <td data-label="Booked" class="nowrap soft hide-narrow"><?= h(admin_date($reservation["created_at"])) ?></td>

                                    <td class="cell-wide">
                                        <div class="table-actions">

                                            <button class="btn btn-sm btn-ghost" type="button" data-dialog-open="reservation-<?= $id ?>">Details</button>

                                            <?php if ($status === "pending" && $paymentStatus === "pending"): ?>

                                                <?php if (admin_can("payments")): ?>
                                                    <a class="btn btn-sm btn-soft" href="payment.php?q=%23<?= $id ?>">Check payment</a>
                                                <?php else: ?>
                                                    <span class="wait-note"><?= icon("clock", 14) ?> Payment being checked</span>
                                                <?php endif; ?>

                                            <?php elseif ($status === "pending" && $paymentStatus !== "verified"): ?>

                                                <form class="inline-form" method="post" action="<?= h($here()) ?>"
                                                    data-confirm="Reservation #<?= $id ?> of <?= h($reservation["customer_name"]) ?> will be declined and its dates open up for other guests. This cannot be undone."
                                                    data-confirm-title="Decline this reservation?"
                                                    data-confirm-ok="Decline"
                                                    data-confirm-tone="danger"
                                                >
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="reservation_id" value="<?= $id ?>">
                                                    <input type="hidden" name="action" value="decline">
                                                    <button class="btn btn-sm btn-danger-soft" type="submit">Decline</button>
                                                </form>

                                            <?php elseif ($status === "confirmed"): ?>

                                                <form class="inline-form" method="post" action="<?= h($here()) ?>"
                                                    data-confirm="Mark reservation #<?= $id ?> of <?= h($reservation["customer_name"]) ?> as completed? Do this after the guest has checked out."
                                                    data-confirm-title="Stay completed?"
                                                    data-confirm-ok="Mark as completed"
                                                >
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="reservation_id" value="<?= $id ?>">
                                                    <input type="hidden" name="action" value="complete">
                                                    <button class="btn btn-sm btn-primary" type="submit">Complete</button>
                                                </form>

                                            <?php endif; ?>

                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?= admin_pager($paging, fn (int $page) => $here(["page" => $page]), "reservations") ?>

            <?php else: ?>

                <div class="empty">
                    <div class="empty-icon"><?= icon("calendar", 22) ?></div>
                    <h3>No reservations found</h3>
                    <p>
                        <?= $search !== "" || $statusFilter !== "all"
                            ? "Nothing matches what you picked."
                            : "Bookings show up here as soon as a guest makes one." ?>
                    </p>
                    <?php if ($search !== "" || $statusFilter !== "all"): ?>
                        <a class="btn" href="reservations.php">Show all reservations</a>
                    <?php endif; ?>
                </div>

            <?php endif; ?>

        </section>

    </div>

</div>


<!-- DETAILS, ONE WINDOW PER ROW -->

<?php foreach ($reservations as $reservation): ?>
    <?php
    $id = (int) $reservation["id"];
    $nights = (int) $reservation["total_nights"];
    ?>
    <dialog class="dialog dialog-wide" id="reservation-<?= $id ?>" aria-labelledby="reservation-<?= $id ?>-title">
        <div class="dialog-head">
            <h2 id="reservation-<?= $id ?>-title" tabindex="-1" autofocus>Reservation #<?= $id ?></h2>
            <button class="btn btn-ghost btn-icon" type="button" data-dialog-close aria-label="Close"><?= icon("x") ?></button>
        </div>

        <div class="dialog-body">
            <div class="person" style="margin-bottom:14px">
                <?= admin_avatar(["full_name" => $reservation["customer_name"], "profile_image" => $reservation["profile_image"]], 44) ?>
                <div class="person-text">
                    <span class="person-name" style="max-width:none"><?= h($reservation["customer_name"]) ?></span>
                    <span class="person-sub" style="max-width:none">
                        <?= h($reservation["customer_email"]) ?><?= trim((string) $reservation["customer_phone"]) !== "" ? " · " . h($reservation["customer_phone"]) : "" ?>
                    </span>
                </div>
            </div>

            <dl class="kv">
                <dt>Status</dt>
                <dd><?= status_pill($reservation["status"]) ?></dd>

                <dt>Room</dt>
                <dd><?= h($reservation["room_name"]) ?></dd>

                <dt>Check-in</dt>
                <dd><?= h(admin_date($reservation["check_in"], "l, F j, Y")) ?></dd>

                <dt>Check-out</dt>
                <dd><?= h(admin_date($reservation["check_out"], "l, F j, Y")) ?></dd>

                <dt>Nights</dt>
                <dd><?= $nights ?></dd>

                <dt>Guests</dt>
                <dd><?= (int) $reservation["guests"] ?></dd>

                <dt>Price per night</dt>
                <dd><?= h(peso($reservation["price_per_night"], 2)) ?></dd>

                <dt>Total</dt>
                <dd class="strong"><?= h(peso($reservation["total_amount"], 2)) ?></dd>

                <dt>Payment</dt>
                <dd>
                    <?php if ($reservation["payment_status"]): ?>
                        <?= status_pill($reservation["payment_status"]) ?>
                        <?= h(payment_method_label((string) $reservation["payment_method"])) ?>
                    <?php else: ?>
                        Not paid yet
                    <?php endif; ?>
                </dd>

                <dt>Booked on</dt>
                <dd><?= h(admin_date($reservation["created_at"], "M j, Y · g:i A")) ?></dd>

                <?php if (!empty($reservation["terms_accepted_at"])): ?>
                    <dt>Terms accepted</dt>
                    <dd><?= h(activity_time($reservation["terms_accepted_at"])) ?></dd>
                <?php endif; ?>
            </dl>
        </div>

        <div class="dialog-actions">
            <?php if (admin_can("messages")): ?>
                <a class="btn" href="messages.php?customer=<?= (int) $reservation["user_id"] ?>"><?= icon("message") ?> Message guest</a>
            <?php endif; ?>
            <?php if ($reservation["payment_id"] && admin_can("payments")): ?>
                <a class="btn" href="payment.php?q=%23<?= $id ?>"><?= icon("credit-card") ?> Open payment</a>
            <?php endif; ?>
            <button class="btn btn-primary" type="button" data-dialog-close>Close</button>
        </div>
    </dialog>
<?php endforeach; ?>

<?php admin_shell_end(); ?>
