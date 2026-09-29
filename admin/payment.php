<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/admin-shell.php";
require_once __DIR__ . "/../includes/paymongo.php";

$admin = admin_boot($pdo, "payments");

const PAYMENTS_PER_PAGE = 25;

// Online (PayMongo) payments confirm themselves; bring any unfinished ones up to date
paymongo_settle_pending($pdo);


// ======================================================
// FILTERS: ?status=  ?q=  ?page=
// q: "#12" or a short number finds the payments of reservation 12 (and payment 12); anything else is
// looked up in the reference number, the guest's name and the guest's email.
// ======================================================

$statuses = ["all", "pending", "verified", "rejected", "cancelled"];

$statusFilter = $_GET["status"] ?? "all";

if (!in_array($statusFilter, $statuses, true)) {
    $statusFilter = "all";
}

$search = admin_query();

$here = fn (array $change = []) => admin_url(
    "payment.php",
    $change + ["status" => $statusFilter, "q" => $search, "page" => (int) ($_GET["page"] ?? 1)]
);


// ======================================================
// VERIFY / REJECT
// ======================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $paymentId = (int) ($_POST["payment_id"] ?? 0);
    $action = $_POST["action"] ?? "";

    if (!admin_check_csrf()) {
        admin_flash("Your session expired. Please try again.", "error");
    } elseif ($paymentId > 0 && in_array($action, ["verify", "reject"], true)) {

        $changed = false;
        $payment = null;

        try {

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT
                    payments.id,
                    payments.reservation_id,
                    payments.user_id,
                    payments.amount,
                    payments.status,
                    users.full_name AS customer_name
                FROM payments
                INNER JOIN users ON users.id = payments.user_id
                WHERE payments.id = ?
                LIMIT 1
            ");

            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch();

            if ($payment && $payment["status"] === "pending") {

                if ($action === "verify") {

                    $stmt = $pdo->prepare("UPDATE payments SET status = 'verified' WHERE id = ? AND status = 'pending'");
                    $stmt->execute([$paymentId]);
                    $changed = $stmt->rowCount() > 0;

                    // a verified payment confirms its reservation
                    $stmt = $pdo->prepare("UPDATE reservations SET status = 'confirmed' WHERE id = ? AND status = 'pending'");
                    $stmt->execute([$payment["reservation_id"]]);

                } else {

                    $stmt = $pdo->prepare("UPDATE payments SET status = 'rejected' WHERE id = ? AND status = 'pending'");
                    $stmt->execute([$paymentId]);
                    $changed = $stmt->rowCount() > 0;
                }
            }

            $pdo->commit();

        } catch (PDOException $exception) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $changed = false;
            $payment = null;
            error_log("ARVE'S House payment " . $action . " failed: " . $exception->getMessage());
            admin_flash("The payment could not be saved. Please try again.", "error");
        }

        if ($changed) {

            $reservationId = (int) $payment["reservation_id"];
            $amount = peso($payment["amount"], 2);

            log_activity(
                $pdo,
                $action === "verify" ? "payment.verified" : "payment.rejected",
                ($action === "verify" ? "Verified " : "Rejected ") . $payment["customer_name"]
                    . "'s payment of " . $amount . " for reservation #" . $reservationId,
                ["entity_type" => "payment", "entity_id" => $paymentId, "link" => "payment.php?q=%23" . $reservationId]
            );

            admin_flash(
                $action === "verify"
                    ? "Payment of " . $amount . " verified. Reservation #" . $reservationId . " is confirmed."
                    : "Payment of " . $amount . " rejected. The guest can send a new payment for reservation #" . $reservationId . "."
            );

        } elseif ($payment) {
            admin_flash("That payment was already " . $payment["status"] . ".", "error");
        }
    }

    header("Location: " . $here());
    exit;
}


// ======================================================
// NUMBERS ON TOP (all payments, whatever the filters)
// ======================================================

$totals = array_fill_keys($statuses, 0);

foreach ($pdo->query("SELECT status, COUNT(*) AS total FROM payments GROUP BY status") as $row) {
    if (isset($totals[$row["status"]])) {
        $totals[$row["status"]] = (int) $row["total"];
    }

    $totals["all"] += (int) $row["total"];
}

$verifiedAmount = (float) $pdo->query(
    "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'verified'"
)->fetchColumn();

$pendingAmount = (float) $pdo->query(
    "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'pending'"
)->fetchColumn();


// ======================================================
// THE LIST
// ======================================================

$from = "
    FROM payments
    INNER JOIN reservations ON payments.reservation_id = reservations.id
    INNER JOIN rooms ON reservations.room_id = rooms.id
    INNER JOIN users ON payments.user_id = users.id
";

$where = [];
$params = [];

if ($search !== "") {
    // "#12", or a short number: a reservation or payment number. Long numbers are references.
    if (preg_match('/^#(\d{1,9})$/', $search, $match) || preg_match('/^(\d{1,4})$/', $search, $match)) {
        $where[] = "(payments.reservation_id = ? OR payments.id = ?)";
        array_push($params, (int) $match[1], (int) $match[1]);
    } else {
        $where[] = "(payments.reference_number LIKE ? OR users.full_name LIKE ? OR users.email LIKE ?)";
        array_push($params, admin_like($search), admin_like($search), admin_like($search));
    }
}

// the numbers on the tabs follow the search
$tabCounts = array_fill_keys($statuses, 0);

$stmt = $pdo->prepare(
    "SELECT payments.status, COUNT(*) AS total" . $from
    . ($where ? " WHERE " . implode(" AND ", $where) : "")
    . " GROUP BY payments.status"
);
$stmt->execute($params);

foreach ($stmt as $row) {
    if (isset($tabCounts[$row["status"]])) {
        $tabCounts[$row["status"]] = (int) $row["total"];
    }

    $tabCounts["all"] += (int) $row["total"];
}

// "Cancelled" (online payments the guest backed out of) only gets a tab when there are some
$tabs = array_filter(
    $statuses,
    fn ($status) => $status !== "cancelled" || $totals["cancelled"] > 0 || $statusFilter === "cancelled"
);

if ($statusFilter !== "all") {
    $where[] = "payments.status = ?";
    $params[] = $statusFilter;
}

$paging = admin_paginate($tabCounts[$statusFilter], PAYMENTS_PER_PAGE);

$stmt = $pdo->prepare("
    SELECT
        payments.*,
        reservations.check_in,
        reservations.check_out,
        reservations.status AS reservation_status,
        rooms.room_name,
        users.full_name AS customer_name,
        users.email AS customer_email,
        users.phone AS customer_phone,
        users.profile_image
    " . $from
    . ($where ? " WHERE " . implode(" AND ", $where) : "")
    . " ORDER BY payments.created_at DESC, payments.id DESC
        LIMIT " . $paging["per_page"] . " OFFSET " . $paging["offset"]
);

$stmt->execute($params);
$payments = $stmt->fetchAll();

admin_shell_head([
    "title" => "Payments",
    "subtitle" => "Check what guests paid, then verify or reject it",
    "active" => "payments",
]);
?>
<style>
    .reference {
        font-family: var(--mono);
        font-size: 12.5px;
        overflow-wrap: anywhere;
    }

    .pay-number {
        color: var(--text-3);
        font-variant-numeric: tabular-nums;
    }
</style>
<?php admin_shell_body(); ?>

<div class="stack">

    <!-- NUMBERS -->

    <section class="stats stats-compact" aria-label="All payments">

        <div class="stat tone-green">
            <div class="stat-main">
                <div class="stat-label">Verified revenue</div>
                <div class="stat-value"><?= h(peso($verifiedAmount)) ?></div>
                <div class="stat-note"><?= number_format($totals["verified"]) ?> verified <?= $totals["verified"] === 1 ? "payment" : "payments" ?></div>
            </div>
            <div class="stat-icon"><?= icon("wallet") ?></div>
        </div>

        <a class="stat tone-amber" href="payment.php?status=pending">
            <div class="stat-main">
                <div class="stat-label">Pending</div>
                <div class="stat-value"><?= number_format($totals["pending"]) ?></div>
                <div class="stat-note"><?= $totals["pending"] > 0 ? h(peso($pendingAmount)) . " to check" : "Nothing to check" ?></div>
            </div>
            <div class="stat-icon"><?= icon("clock") ?></div>
        </a>

        <a class="stat tone-blue" href="payment.php?status=verified">
            <div class="stat-main">
                <div class="stat-label">Verified</div>
                <div class="stat-value"><?= number_format($totals["verified"]) ?></div>
                <div class="stat-note">Money received</div>
            </div>
            <div class="stat-icon"><?= icon("check-circle") ?></div>
        </a>

        <a class="stat tone-rose" href="payment.php?status=rejected">
            <div class="stat-main">
                <div class="stat-label">Rejected</div>
                <div class="stat-value"><?= number_format($totals["rejected"]) ?></div>
                <div class="stat-note">Not accepted</div>
            </div>
            <div class="stat-icon"><?= icon("x-circle") ?></div>
        </a>

    </section>


    <div>

        <!-- FILTERS -->

        <div class="page-bar">
            <nav class="tabs" aria-label="Status">
                <?php foreach ($tabs as $status): ?>
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
                    <label class="sr-only" for="q">Search payments</label>
                    <input
                        class="input"
                        type="search"
                        id="q"
                        name="q"
                        value="<?= h($search) ?>"
                        placeholder="Reference, guest or #reservation"
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
                <?= $tabCounts[$statusFilter] === 1 ? "payment matches" : "payments match" ?>
                “<?= h($search) ?>”.
                <a href="<?= h($here(["q" => "", "page" => 1])) ?>">Clear the search</a>
            </p>
        <?php endif; ?>


        <!-- TABLE -->

        <section class="card">

            <?php if ($payments): ?>

                <div class="table-wrap">
                    <table class="table table-stack">
                        <thead>
                            <tr>
                                <th>Guest</th>
                                <th>Reservation</th>
                                <th>Method</th>
                                <th>Reference</th>
                                <th class="right">Amount</th>
                                <th>Status</th>
                                <th class="hide-narrow">Date</th>
                                <th class="right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                                <?php
                                $id = (int) $payment["id"];
                                $reservationId = (int) $payment["reservation_id"];
                                $online = str_starts_with((string) $payment["payment_method"], "online");
                                $reference = trim((string) $payment["reference_number"]);
                                ?>
                                <tr>
                                    <td class="cell-lead">
                                        <div class="person">
                                            <?= admin_avatar(["full_name" => $payment["customer_name"], "profile_image" => $payment["profile_image"]], 34) ?>
                                            <div class="person-text">
                                                <span class="person-name"><?= h($payment["customer_name"]) ?></span>
                                                <span class="person-sub"><?= h($payment["customer_email"]) ?></span>
                                            </div>
                                        </div>
                                    </td>

                                    <td data-label="Reservation">
                                        <?php if (admin_can("reservations")): ?>
                                            <a class="cell-main" href="reservations.php?q=<?= $reservationId ?>">#<?= $reservationId ?></a>
                                        <?php else: ?>
                                            <span class="cell-main">#<?= $reservationId ?></span>
                                        <?php endif; ?>
                                        · <?= h($payment["room_name"]) ?>
                                        <span class="cell-sub nowrap"><?= h(admin_stay($payment["check_in"], $payment["check_out"])) ?></span>
                                    </td>

                                    <td data-label="Method"><?= h(payment_method_label((string) $payment["payment_method"])) ?></td>

                                    <td data-label="Reference" class="reference"><?= $reference !== "" ? h($reference) : '<span class="muted">None</span>' ?></td>

                                    <td data-label="Amount" class="right num strong"><?= h(peso($payment["amount"], 2)) ?></td>

                                    <td data-label="Status"><?= status_pill($payment["status"]) ?></td>

                                    <td data-label="Date" class="nowrap soft hide-narrow">
                                        <?= h(admin_date($payment["created_at"])) ?>
                                        <span class="cell-sub"><?= h(admin_date($payment["created_at"], "g:i A")) ?></span>
                                    </td>

                                    <td class="cell-wide">
                                        <div class="table-actions">

                                            <button class="btn btn-sm btn-ghost" type="button" data-dialog-open="payment-<?= $id ?>">Details</button>

                                            <?php if ($payment["status"] === "pending"): ?>

                                                <form class="inline-form" method="post" action="<?= h($here()) ?>"
                                                    data-confirm="<?= $online
                                                        ? "PayMongo has not confirmed this online payment yet. Verify it only if you can see the " . h(peso($payment["amount"], 2)) . " in your PayMongo account. Reservation #" . $reservationId . " will be confirmed."
                                                        : "Verify " . h($payment["customer_name"]) . "'s payment of " . h(peso($payment["amount"], 2)) . "? Reservation #" . $reservationId . " will be confirmed." ?>"
                                                    data-confirm-title="Verify this payment?"
                                                    data-confirm-ok="Verify"
                                                >
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="payment_id" value="<?= $id ?>">
                                                    <input type="hidden" name="action" value="verify">
                                                    <button class="btn btn-sm btn-success" type="submit">Verify</button>
                                                </form>

                                                <form class="inline-form" method="post" action="<?= h($here()) ?>"
                                                    data-confirm="Reject <?= h($payment["customer_name"]) ?>'s payment of <?= h(peso($payment["amount"], 2)) ?>? The guest will have to send a new payment. This cannot be undone."
                                                    data-confirm-title="Reject this payment?"
                                                    data-confirm-ok="Reject"
                                                    data-confirm-tone="danger"
                                                >
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="payment_id" value="<?= $id ?>">
                                                    <input type="hidden" name="action" value="reject">
                                                    <button class="btn btn-sm btn-danger-soft" type="submit">Reject</button>
                                                </form>

                                            <?php endif; ?>

                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?= admin_pager($paging, fn (int $page) => $here(["page" => $page]), "payments") ?>

            <?php else: ?>

                <div class="empty">
                    <div class="empty-icon"><?= icon("credit-card", 22) ?></div>
                    <h3>No payments found</h3>
                    <p>
                        <?= $search !== "" || $statusFilter !== "all"
                            ? "Nothing matches what you picked."
                            : "Payments show up here as soon as a guest sends one." ?>
                    </p>
                    <?php if ($search !== "" || $statusFilter !== "all"): ?>
                        <a class="btn" href="payment.php">Show all payments</a>
                    <?php endif; ?>
                </div>

            <?php endif; ?>

        </section>

    </div>

</div>


<!-- DETAILS, ONE WINDOW PER ROW -->

<?php foreach ($payments as $payment): ?>
    <?php
    $id = (int) $payment["id"];
    $reservationId = (int) $payment["reservation_id"];
    $reference = trim((string) $payment["reference_number"]);
    ?>
    <dialog class="dialog dialog-wide" id="payment-<?= $id ?>" aria-labelledby="payment-<?= $id ?>-title">
        <div class="dialog-head">
            <h2 id="payment-<?= $id ?>-title" tabindex="-1" autofocus>Payment #<?= $id ?></h2>
            <button class="btn btn-ghost btn-icon" type="button" data-dialog-close aria-label="Close"><?= icon("x") ?></button>
        </div>

        <div class="dialog-body">
            <div class="person" style="margin-bottom:14px">
                <?= admin_avatar(["full_name" => $payment["customer_name"], "profile_image" => $payment["profile_image"]], 44) ?>
                <div class="person-text">
                    <span class="person-name" style="max-width:none"><?= h($payment["customer_name"]) ?></span>
                    <span class="person-sub" style="max-width:none">
                        <?= h($payment["customer_email"]) ?><?= trim((string) $payment["customer_phone"]) !== "" ? " · " . h($payment["customer_phone"]) : "" ?>
                    </span>
                </div>
            </div>

            <dl class="kv">
                <dt>Status</dt>
                <dd><?= status_pill($payment["status"]) ?></dd>

                <dt>Amount</dt>
                <dd class="strong"><?= h(peso($payment["amount"], 2)) ?></dd>

                <dt>Method</dt>
                <dd><?= h(payment_method_label((string) $payment["payment_method"])) ?></dd>

                <dt>Reference</dt>
                <dd class="reference">
                    <?php if ($reference !== ""): ?>
                        <?= h($reference) ?>
                        <button class="btn btn-ghost btn-sm" type="button" data-copy="<?= h($reference) ?>">Copy</button>
                    <?php else: ?>
                        None given
                    <?php endif; ?>
                </dd>

                <dt>Sent on</dt>
                <dd><?= h(admin_date($payment["created_at"], "M j, Y · g:i A")) ?></dd>

                <dt>Reservation</dt>
                <dd>#<?= $reservationId ?> · <?= h($payment["room_name"]) ?> <?= status_pill($payment["reservation_status"]) ?></dd>

                <dt>Stay</dt>
                <dd><?= h(admin_stay($payment["check_in"], $payment["check_out"])) ?></dd>
            </dl>
        </div>

        <div class="dialog-actions">
            <?php if (admin_can("messages")): ?>
                <a class="btn" href="messages.php?customer=<?= (int) $payment["user_id"] ?>"><?= icon("message") ?> Message guest</a>
            <?php endif; ?>
            <?php if (admin_can("reservations")): ?>
                <a class="btn" href="reservations.php?q=<?= $reservationId ?>"><?= icon("calendar") ?> Open reservation</a>
            <?php endif; ?>
            <button class="btn btn-primary" type="button" data-dialog-close>Close</button>
        </div>
    </dialog>
<?php endforeach; ?>

<?php admin_shell_end(); ?>
