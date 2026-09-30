<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/customer-shell.php";

$me = customer_boot($pdo);
$userId = (int) $me["id"];

// online payments finished (or given up) on PayMongo since the guest last came by
paymongo_settle_pending($pdo, $userId);

const RESERVATIONS_PER_PAGE = 20;

// the tabs: what each one shows (SQL on r = reservations, lp = its latest payment)
$tabs = [
    "all" => ["All", "1 = 1"],
    "upcoming" => ["Upcoming", "r.status IN ('pending', 'confirmed') AND r.check_out > CURDATE()"],
    "to_pay" => ["To pay", "r.status = 'pending' AND (lp.status IS NULL OR lp.status IN ('rejected', 'cancelled'))"],
    "past" => ["Past", "(r.status = 'completed' OR (r.status = 'confirmed' AND r.check_out <= CURDATE()))"],
    "cancelled" => ["Cancelled", "r.status IN ('cancelled', 'declined', 'expired')"],
];

$show = (string) ($_GET["show"] ?? "all");
$show = isset($tabs[$show]) ? $show : "all";
$search = admin_query();

$here = function (array $change = []) use ($show, $search): string {
    $query = array_merge(["show" => $show, "q" => $search, "page" => (int) ($_GET["page"] ?? 1)], $change);

    return admin_url("reservations.php", $query);
};


// ======================================================
// CANCEL A RESERVATION (only while it is waiting, and nothing is paid)
// ======================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $reservationId = (int) ($_POST["reservation_id"] ?? 0);

    if (!admin_check_csrf()) {
        admin_flash("Your session expired. Please try again.", "error");
    } elseif (($_POST["action"] ?? "") === "cancel" && $reservationId > 0) {
        $stmt = $pdo->prepare("
            UPDATE reservations
            SET status = 'cancelled'
            WHERE id = ? AND user_id = ? AND status = 'pending'
            AND NOT EXISTS (
                SELECT 1 FROM payments
                WHERE payments.reservation_id = reservations.id AND payments.status = 'verified'
            )
        ");
        $stmt->execute([$reservationId, $userId]);

        if ($stmt->rowCount() > 0) {
            $cancelled = $pdo->prepare("
                SELECT rooms.room_name, reservations.check_in, reservations.check_out
                FROM reservations
                INNER JOIN rooms ON rooms.id = reservations.room_id
                WHERE reservations.id = ?
            ");
            $cancelled->execute([$reservationId]);
            $cancelled = $cancelled->fetch();

            // shows up in the admin's notifications and activity log
            log_activity(
                $pdo,
                "reservation.cancelled",
                ($me["full_name"] ?: "A customer") . " cancelled reservation #" . $reservationId
                    . ($cancelled
                        ? " (" . $cancelled["room_name"] . ", " . date("M j", strtotime($cancelled["check_in"]))
                            . " to " . date("M j", strtotime($cancelled["check_out"])) . ")"
                        : ""),
                [
                    "entity_type" => "reservation",
                    "entity_id" => $reservationId,
                    "link" => "reservations.php?q=" . $reservationId,
                    "notify" => true,
                ]
            );

            admin_flash("Reservation #" . $reservationId . " is cancelled. Its dates are open again.");
        } else {
            admin_flash("Reservation #" . $reservationId . " can no longer be cancelled. Message us if you need help.", "error");
        }
    }

    header("Location: " . $here());
    exit;
}


// ======================================================
// THE LIST
// ======================================================

$select = "
    SELECT r.*, rooms.room_name,
           lp.status AS payment_status, lp.payment_method, lp.amount AS payment_amount,
           lp.reference_number, lp.created_at AS payment_at
    FROM reservations r
    INNER JOIN rooms ON rooms.id = r.room_id
    " . CUSTOMER_LATEST_PAYMENT;

$where = "r.user_id = ?";
$values = [$userId];

if ($search !== "") {
    $number = (int) ltrim($search, "#");

    if (preg_match('/^#?\d{1,9}$/', $search)) {
        $where .= " AND r.id = ?";
        $values[] = $number;
    } else {
        $where .= " AND rooms.room_name LIKE ?";
        $values[] = admin_like($search);
    }
}

// how many in each tab (with the search)
$tabCounts = [];

foreach ($tabs as $key => [$label, $condition]) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations r INNER JOIN rooms ON rooms.id = r.room_id "
        . CUSTOMER_LATEST_PAYMENT . " WHERE {$where} AND {$condition}");
    $stmt->execute($values);
    $tabCounts[$key] = (int) $stmt->fetchColumn();
}

$paging = admin_paginate($tabCounts[$show], RESERVATIONS_PER_PAGE);

$stmt = $pdo->prepare($select . " WHERE {$where} AND {$tabs[$show][1]}
    ORDER BY (r.status IN ('pending', 'confirmed') AND r.check_out > CURDATE()) DESC,
             CASE WHEN r.status IN ('pending', 'confirmed') AND r.check_out > CURDATE() THEN r.check_in END ASC,
             r.created_at DESC, r.id DESC
    LIMIT " . RESERVATIONS_PER_PAGE . " OFFSET " . $paging["offset"]);
$stmt->execute($values);
$reservations = $stmt->fetchAll();

// ?open=12: that reservation's window opens, even when it is not in this list
$open = (int) ($_GET["open"] ?? 0);
$dialogs = $reservations;

if ($open > 0 && !in_array($open, array_map(fn ($r) => (int) $r["id"], $reservations), true)) {
    $stmt = $pdo->prepare($select . " WHERE r.user_id = ? AND r.id = ? LIMIT 1");
    $stmt->execute([$userId, $open]);

    if ($extra = $stmt->fetch()) {
        $dialogs[] = $extra;
    }
}

customer_shell_head([
    "title" => "My Reservations",
    "subtitle" => "Your bookings, their payments and dates",
    "active" => "reservations",
]);
?>
<style>
    .res-room {
        display: flex;
        align-items: center;
        gap: 12px;
        min-width: 0;
    }

    .res-thumb {
        flex: none;
        width: 44px;
        height: 44px;
        border-radius: 12px;
        object-fit: cover;
    }

    .res-room .tile-icon {
        width: 44px;
        height: 44px;
    }

    .res-room-text {
        min-width: 0;
    }

    .res-room-name {
        display: block;
        overflow: hidden;
        font-weight: 700;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    .detail-photo {
        width: 100%;
        aspect-ratio: 16 / 9;
        margin-bottom: 14px;
        border-radius: var(--radius);
        object-fit: cover;
    }

    .detail-pills {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-bottom: 14px;
    }

    .detail-list {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
        margin: 0;
    }

    .detail-list div {
        padding: 10px 12px;
        border: 1px solid var(--line);
        border-radius: var(--radius);
        background: var(--surface-2);
    }

    .detail-list dt {
        color: var(--text-3);
        font-size: 11.5px;
        font-weight: 600;
    }

    .detail-list dd {
        margin: 2px 0 0;
        font-weight: 700;
        font-variant-numeric: tabular-nums;
        overflow-wrap: anywhere;
    }

    .detail-list .is-wide {
        grid-column: 1 / -1;
    }

    @media (max-width: 767px) {
        .table-stack .res-room {
            width: 100%;
        }
    }
</style>
<?php customer_shell_body(); ?>

<div class="stack">

    <div>

        <!-- FILTERS -->

        <div class="page-bar">
            <nav class="tabs" aria-label="Show">
                <?php foreach ($tabs as $key => [$label]): ?>
                    <a
                        class="tab<?= $show === $key ? " is-active" : "" ?>"
                        href="<?= h($here(["show" => $key, "page" => 1])) ?>"
                        <?= $show === $key ? 'aria-current="page"' : "" ?>
                    >
                        <?= h($label) ?>
                        <span class="tab-count"><?= number_format($tabCounts[$key]) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <form class="filter-form" method="get" role="search">
                <?php if ($show !== "all"): ?>
                    <input type="hidden" name="show" value="<?= h($show) ?>">
                <?php endif; ?>
                <div class="filter-search">
                    <?= icon("search") ?>
                    <label class="sr-only" for="q">Search your reservations</label>
                    <input class="input" type="search" id="q" name="q" value="<?= h($search) ?>" placeholder="Room or #number" autocomplete="off" enterkeyhint="search">
                </div>
                <button class="btn" type="submit">Search</button>
            </form>
        </div>

        <?php if ($search !== ""): ?>
            <p class="filter-note">
                <?= number_format($tabCounts[$show]) ?>
                <?= $tabCounts[$show] === 1 ? "reservation matches" : "reservations match" ?>
                “<?= h($search) ?>”.
                <a href="<?= h($here(["q" => "", "page" => 1])) ?>">Clear the search</a>
            </p>
        <?php endif; ?>


        <!-- TABLE (phones: cards) -->

        <section class="card">

            <?php if ($reservations): ?>

                <div class="table-wrap">
                    <table class="table table-stack">
                        <thead>
                            <tr>
                                <th>Room</th>
                                <th>Stay</th>
                                <th class="right hide-narrow">Guests</th>
                                <th class="right">Amount</th>
                                <th>Payment</th>
                                <th>Status</th>
                                <th class="right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reservations as $reservation): ?>
                                <?php
                                $id = (int) $reservation["id"];
                                $nights = (int) $reservation["total_nights"];
                                $photo = customer_room_photo((int) $reservation["room_id"]);
                                ?>
                                <tr>
                                    <td class="cell-lead">
                                        <div class="res-room">
                                            <?php if ($photo !== ""): ?>
                                                <img class="res-thumb" src="<?= h($photo) ?>" alt="" loading="lazy" decoding="async">
                                            <?php else: ?>
                                                <span class="tile-icon tone-blue"><?= icon("bed") ?></span>
                                            <?php endif; ?>
                                            <span class="res-room-text">
                                                <span class="res-room-name"><?= h($reservation["room_name"]) ?></span>
                                                <span class="cell-sub">Reservation #<?= $id ?></span>
                                            </span>
                                        </div>
                                    </td>

                                    <td data-label="Stay" class="nowrap">
                                        <?= h(admin_stay($reservation["check_in"], $reservation["check_out"])) ?>
                                        <span class="cell-sub"><?= $nights ?> <?= $nights === 1 ? "night" : "nights" ?><?php $when = customer_countdown($reservation["check_in"], $reservation["check_out"]); ?><?= $when !== "" && in_array($reservation["status"], ["pending", "confirmed"], true) ? " · " . h($when) : "" ?></span>
                                    </td>

                                    <td data-label="Guests" class="right num hide-narrow"><?= (int) $reservation["guests"] ?></td>

                                    <td data-label="Amount" class="right num strong"><?= h(peso($reservation["total_amount"])) ?></td>

                                    <td data-label="Payment"><?= customer_payment_pill($reservation["payment_status"]) ?></td>

                                    <td data-label="Status"><?= status_pill($reservation["status"]) ?></td>

                                    <td class="cell-wide">
                                        <div class="table-actions">
                                            <button class="btn btn-sm btn-ghost" type="button" data-dialog-open="reservation-<?= $id ?>">Details</button>

                                            <?php if (ticket_available($reservation)): ?>
                                                <a class="btn btn-sm btn-primary" href="ticket.php?id=<?= $id ?>"><?= icon("ticket") ?> Ticket</a>
                                            <?php elseif (customer_can_pay($reservation)): ?>
                                                <a class="btn btn-sm btn-primary" href="pay.php?reservation_id=<?= $id ?>"><?= $reservation["payment_status"] === "rejected" ? "Pay again" : "Pay now" ?></a>
                                            <?php elseif ($reservation["payment_status"] === "pending" && $reservation["status"] === "pending"): ?>
                                                <span class="wait-note"><?= icon("clock", 14) ?> Being checked</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?= admin_pager($paging, fn ($page) => $here(["page" => $page]), "reservations") ?>

            <?php elseif ($search !== "" || $show !== "all"): ?>
                <div class="empty">
                    <div class="empty-icon"><?= icon("search", 22) ?></div>
                    <h3>Nothing here</h3>
                    <p>No reservation <?= $search !== "" ? "matches “" . h($search) . "”" : "is in " . h(strtolower($tabs[$show][0])) ?>.</p>
                    <a class="btn" href="reservations.php">Show all reservations</a>
                </div>
            <?php else: ?>
                <div class="empty">
                    <div class="empty-icon"><?= icon("calendar", 22) ?></div>
                    <h3>No reservations yet</h3>
                    <p>Browse the rooms and book your first stay. It will show up here.</p>
                    <a class="btn btn-primary" href="../rooms.php">View available rooms</a>
                </div>
            <?php endif; ?>

        </section>

    </div>

</div>


<!-- ======================================================
     THE WINDOW OF EACH RESERVATION
====================================================== -->

<?php foreach ($dialogs as $reservation): ?>
    <?php
    $id = (int) $reservation["id"];
    $photo = customer_room_photo((int) $reservation["room_id"]);
    $nights = (int) $reservation["total_nights"];
    ?>
    <dialog class="dialog dialog-wide" id="reservation-<?= $id ?>" aria-labelledby="reservation-<?= $id ?>-title" <?= $open === $id ? "data-dialog-auto" : "" ?>>
        <div class="dialog-head">
            <h2 id="reservation-<?= $id ?>-title" tabindex="-1" autofocus><?= h($reservation["room_name"]) ?> · #<?= $id ?></h2>
            <button class="btn btn-ghost btn-icon" type="button" data-dialog-close aria-label="Close"><?= icon("x") ?></button>
        </div>

        <div class="dialog-body">
            <?php if ($photo !== ""): ?>
                <img class="detail-photo" src="<?= h($photo) ?>" alt="<?= h($reservation["room_name"]) ?>" loading="lazy" decoding="async">
            <?php endif; ?>

            <div class="detail-pills">
                <?= status_pill($reservation["status"]) ?>
                <?= customer_payment_pill($reservation["payment_status"]) ?>
            </div>

            <dl class="detail-list">
                <div><dt>Check-in</dt><dd><?= h(admin_date($reservation["check_in"], "D, M j, Y")) ?></dd></div>
                <div><dt>Check-out</dt><dd><?= h(admin_date($reservation["check_out"], "D, M j, Y")) ?></dd></div>
                <div><dt>Nights</dt><dd><?= $nights ?></dd></div>
                <div><dt>Guests</dt><dd><?= (int) $reservation["guests"] ?></dd></div>
                <div><dt>Price per night</dt><dd><?= h(peso($reservation["price_per_night"], 2)) ?></dd></div>
                <div><dt>Total</dt><dd><?= h(peso($reservation["total_amount"], 2)) ?></dd></div>

                <?php if ($reservation["payment_status"]): ?>
                    <div><dt>Payment</dt><dd><?= h(payment_method_label((string) $reservation["payment_method"])) ?> · <?= h(peso($reservation["payment_amount"], 2)) ?></dd></div>
                    <div><dt>Reference</dt><dd><?= h($reservation["reference_number"] ?: "—") ?></dd></div>
                <?php endif; ?>

                <div class="is-wide"><dt>Booked on</dt><dd><?= h(admin_date($reservation["created_at"], "M j, Y · g:i A")) ?></dd></div>
            </dl>

            <?php if ($reservation["status"] === "pending" && $reservation["payment_status"] === "pending"): ?>
                <p class="hint">We are checking your payment. Your stay is confirmed as soon as it is accepted.</p>
            <?php elseif ($reservation["payment_status"] === "rejected"): ?>
                <p class="hint">Your last payment was not accepted. You can send it again, or message us if something is unclear.</p>
            <?php endif; ?>
        </div>

        <div class="dialog-actions">
            <?php if (customer_can_cancel($reservation)): ?>
                <form class="inline-form" method="post" action="<?= h($here(["open" => null])) ?>"
                    data-confirm="Reservation #<?= $id ?> (<?= h($reservation["room_name"]) ?>, <?= h(admin_stay($reservation["check_in"], $reservation["check_out"])) ?>) will be cancelled and its dates open up for other guests. This cannot be undone."
                    data-confirm-title="Cancel this reservation?"
                    data-confirm-ok="Cancel reservation"
                    data-confirm-tone="danger"
                >
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="reservation_id" value="<?= $id ?>">
                    <button class="btn btn-danger-soft" type="submit">Cancel reservation</button>
                </form>
            <?php endif; ?>

            <a class="btn" href="messages.php?about=<?= $id ?>"><?= icon("message") ?> Ask about it</a>

            <?php if (ticket_available($reservation)): ?>
                <a class="btn btn-primary" href="ticket.php?id=<?= $id ?>"><?= icon("ticket") ?> View ticket</a>
            <?php elseif (customer_can_pay($reservation)): ?>
                <a class="btn btn-primary" href="pay.php?reservation_id=<?= $id ?>"><?= icon("credit-card") ?> <?= $reservation["payment_status"] === "rejected" ? "Pay again" : "Pay now" ?></a>
            <?php else: ?>
                <button class="btn btn-primary" type="button" data-dialog-close>Done</button>
            <?php endif; ?>
        </div>
    </dialog>
<?php endforeach; ?>

<?php customer_shell_end(); ?>
