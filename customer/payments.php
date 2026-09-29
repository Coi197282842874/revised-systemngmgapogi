<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/customer-shell.php";

$me = customer_boot($pdo);
$userId = (int) $me["id"];

// online payments finished (or given up) on PayMongo since the guest last came by
paymongo_settle_pending($pdo, $userId);

const PAYMENTS_PER_PAGE = 20;


// ======================================================
// NUMBERS ON TOP
// ======================================================

$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN status = 'verified' THEN amount ELSE 0 END), 0) AS paid,
        COALESCE(SUM(status = 'verified'), 0) AS paid_count,
        COALESCE(SUM(status = 'pending'), 0) AS checking,
        COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS checking_amount,
        COALESCE(SUM(status = 'rejected'), 0) AS rejected,
        COUNT(*) AS total
    FROM payments
    WHERE user_id = ?
");
$stmt->execute([$userId]);
$totals = $stmt->fetch();

$counts = customer_counts($pdo, $userId);


// ======================================================
// THE LIST
// ======================================================

$paging = admin_paginate((int) $totals["total"], PAYMENTS_PER_PAGE);

$stmt = $pdo->prepare("
    SELECT p.*, r.check_in, r.check_out, r.status AS reservation_status, r.room_id, rooms.room_name,
           (p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.reservation_id = p.reservation_id AND p2.user_id = p.user_id)) AS is_latest
    FROM payments p
    INNER JOIN reservations r ON r.id = p.reservation_id
    INNER JOIN rooms ON rooms.id = r.room_id
    WHERE p.user_id = ?
    ORDER BY p.id DESC
    LIMIT " . PAYMENTS_PER_PAGE . " OFFSET " . $paging["offset"]
);
$stmt->execute([$userId]);
$payments = $stmt->fetchAll();

customer_shell_head([
    "title" => "Payments",
    "subtitle" => "What you paid, and what is being checked",
    "active" => "payments",
]);
?>
<?php customer_shell_body(); ?>

<div class="stack">

    <!-- NUMBERS -->

    <section class="stats" aria-label="Your payments">

        <div class="stat tone-green">
            <div class="stat-main">
                <div class="stat-label">Total paid</div>
                <div class="stat-value"><?= h(peso($totals["paid"])) ?></div>
                <div class="stat-note"><?= (int) $totals["paid_count"] ?> <?= (int) $totals["paid_count"] === 1 ? "payment" : "payments" ?> accepted</div>
            </div>
            <div class="stat-icon"><?= icon("wallet") ?></div>
        </div>

        <div class="stat tone-cyan">
            <div class="stat-main">
                <div class="stat-label">Being checked</div>
                <div class="stat-value"><?= number_format((int) $totals["checking"]) ?></div>
                <div class="stat-note"><?= (int) $totals["checking"] > 0 ? h(peso($totals["checking_amount"])) . " waiting for us" : "Nothing waiting" ?></div>
            </div>
            <div class="stat-icon"><?= icon("clock") ?></div>
        </div>

        <a class="stat tone-amber" href="reservations.php?show=to_pay">
            <div class="stat-main">
                <div class="stat-label">To pay</div>
                <div class="stat-value"><?= number_format($counts["to_pay"]) ?></div>
                <div class="stat-note"><?= $counts["to_pay"] > 0 ? h(peso($counts["to_pay_amount"])) . " for your bookings" : "All paid" ?></div>
            </div>
            <div class="stat-icon"><?= icon("credit-card") ?></div>
        </a>

        <div class="stat tone-rose">
            <div class="stat-main">
                <div class="stat-label">Not accepted</div>
                <div class="stat-value"><?= number_format((int) $totals["rejected"]) ?></div>
                <div class="stat-note">Can be sent again</div>
            </div>
            <div class="stat-icon"><?= icon("x-circle") ?></div>
        </div>

    </section>


    <!-- TABLE (phones: cards) -->

    <section class="card">
        <div class="card-head">
            <h2 class="card-title">All payments</h2>
        </div>

        <?php if ($payments): ?>
            <div class="table-wrap">
                <table class="table table-stack">
                    <thead>
                        <tr>
                            <th>Reservation</th>
                            <th>Method</th>
                            <th class="hide-narrow">Reference</th>
                            <th class="right">Amount</th>
                            <th>Status</th>
                            <th>Sent</th>
                            <th class="right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <?php $reservationId = (int) $payment["reservation_id"]; ?>
                            <tr>
                                <td class="cell-lead">
                                    <strong><?= h($payment["room_name"]) ?></strong>
                                    <span class="cell-sub">#<?= $reservationId ?> · <?= h(admin_stay($payment["check_in"], $payment["check_out"])) ?></span>
                                </td>
                                <td data-label="Method"><?= h(payment_method_label((string) $payment["payment_method"])) ?></td>
                                <td data-label="Reference" class="hide-narrow mono"><?= h($payment["reference_number"] ?: "—") ?></td>
                                <td data-label="Amount" class="right num strong"><?= h(peso($payment["amount"], 2)) ?></td>
                                <td data-label="Status"><?= customer_payment_pill($payment["status"]) ?></td>
                                <td data-label="Sent" class="nowrap soft"><?= h(admin_date($payment["created_at"], "M j, Y")) ?></td>
                                <td class="cell-wide">
                                    <div class="table-actions">
                                        <?php
                                        $again = (int) $payment["is_latest"] === 1
                                            && in_array($payment["status"], ["rejected", "cancelled"], true)
                                            && !in_array($payment["reservation_status"], ["cancelled", "declined", "completed", "expired"], true);
                                        ?>
                                        <?php if ($again): ?>
                                            <a class="btn btn-sm btn-primary" href="../payment.php?reservation_id=<?= $reservationId ?>">Pay again</a>
                                        <?php endif; ?>
                                        <a class="btn btn-sm btn-ghost" href="reservations.php?open=<?= $reservationId ?>">Reservation</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?= admin_pager($paging, fn ($page) => admin_url("payments.php", ["page" => $page]), "payments") ?>
        <?php else: ?>
            <div class="empty">
                <div class="empty-icon"><?= icon("credit-card", 22) ?></div>
                <h3>No payments yet</h3>
                <p>When you pay for a booking, it shows up here with its status.</p>
                <?php if ($counts["to_pay"] > 0): ?>
                    <a class="btn btn-primary" href="reservations.php?show=to_pay">See what to pay</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <p class="hint">
        Paid through GCash or at the property? We check it and mark it as paid; online payments are accepted at once.
        Questions about a payment? <a href="messages.php">Message us</a>.
    </p>

</div>

<?php customer_shell_end(); ?>
