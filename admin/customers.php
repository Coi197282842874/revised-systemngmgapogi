<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/admin-shell.php";

$admin = admin_boot($pdo, "customers");

const CUSTOMERS_PER_PAGE = 25;


// ======================================================
// FILTERS: ?status=  ?q=  ?sort=  ?page=
// q is looked up in the name, the email and the phone number.
// ======================================================

$statuses = ["all", "active", "inactive"];

$statusFilter = $_GET["status"] ?? "all";

if (!in_array($statusFilter, $statuses, true)) {
    $statusFilter = "all";
}

$sorts = [
    "newest" => ["label" => "Newest first", "sql" => "users.created_at DESC, users.id DESC"],
    "name" => ["label" => "Name, A to Z", "sql" => "users.full_name, users.id"],
    "reservations" => ["label" => "Most reservations", "sql" => "reservations DESC, users.full_name"],
    "spent" => ["label" => "Highest spent", "sql" => "spent DESC, users.full_name"],
];

$sort = $_GET["sort"] ?? "newest";

if (!isset($sorts[$sort])) {
    $sort = "newest";
}

$search = admin_query();

$here = fn (array $change = []) => admin_url(
    "customers.php",
    $change + [
        "status" => $statusFilter,
        "q" => $search,
        "sort" => $sort === "newest" ? "" : $sort,
        "page" => (int) ($_GET["page"] ?? 1),
    ]
);


// ======================================================
// TURN AN ACCOUNT OFF OR ON
// ======================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $customerId = (int) ($_POST["customer_id"] ?? 0);
    $action = $_POST["action"] ?? "";

    if (!admin_check_csrf()) {
        admin_flash("Your session expired. Please try again.", "error");
    } elseif ($customerId > 0 && in_array($action, ["activate", "deactivate"], true)) {

        $stmt = $pdo->prepare("SELECT id, full_name, email, status FROM users WHERE id = ? AND role = 'customer' LIMIT 1");
        $stmt->execute([$customerId]);
        $customer = $stmt->fetch();

        $newStatus = $action === "activate" ? "active" : "inactive";

        if (!$customer) {
            admin_flash("That customer no longer exists.", "error");
        } elseif ($customer["status"] !== $newStatus) {

            $pdo->prepare("UPDATE users SET status = ? WHERE id = ? AND role = 'customer'")
                ->execute([$newStatus, $customerId]);

            log_activity(
                $pdo,
                $action === "activate" ? "customer.activated" : "customer.deactivated",
                ($action === "activate" ? "Turned on " : "Turned off ") . $customer["full_name"] . "'s account",
                [
                    "entity_type" => "customer",
                    "entity_id" => $customerId,
                    "link" => "customers.php?q=" . rawurlencode($customer["email"]),
                ]
            );

            admin_flash(
                $action === "activate"
                    ? $customer["full_name"] . " can log in and book again."
                    : $customer["full_name"] . "'s account is turned off. They can no longer log in."
            );
        }
    }

    header("Location: " . $here());
    exit;
}


// ======================================================
// NUMBERS ON TOP
// ======================================================

$totals = $pdo->query("
    SELECT
        COUNT(*) AS everyone,
        COALESCE(SUM(status = 'active'), 0) AS active,
        COALESCE(SUM(created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)), 0) AS recent,
        COALESCE(SUM(EXISTS (SELECT 1 FROM reservations WHERE reservations.user_id = users.id)), 0) AS booked
    FROM users
    WHERE role = 'customer'
")->fetch();


// ======================================================
// THE LIST
// ======================================================

$where = ["users.role = 'customer'"];
$params = [];

if ($search !== "") {
    $where[] = "(users.full_name LIKE ? OR users.email LIKE ? OR users.phone LIKE ?)";
    array_push($params, admin_like($search), admin_like($search), admin_like($search));
}

// the numbers on the tabs follow the search
$tabCounts = ["all" => 0, "active" => 0, "inactive" => 0];

$stmt = $pdo->prepare(
    "SELECT status = 'active' AS is_active, COUNT(*) AS total FROM users WHERE " . implode(" AND ", $where) . " GROUP BY status = 'active'"
);
$stmt->execute($params);

foreach ($stmt as $row) {
    $tabCounts[$row["is_active"] ? "active" : "inactive"] = (int) $row["total"];
    $tabCounts["all"] += (int) $row["total"];
}

if ($statusFilter === "active") {
    $where[] = "users.status = 'active'";
} elseif ($statusFilter === "inactive") {
    $where[] = "users.status <> 'active'";
}

$paging = admin_paginate($tabCounts[$statusFilter], CUSTOMERS_PER_PAGE);

$stmt = $pdo->prepare("
    SELECT
        users.id,
        users.full_name,
        users.email,
        users.phone,
        users.profile_image,
        users.status,
        users.created_at,
        users.email_verified,
        users.google_id IS NOT NULL AS uses_google,
        (
            SELECT COUNT(*) FROM reservations
            WHERE reservations.user_id = users.id
        ) AS reservations,
        (
            SELECT COALESCE(SUM(payments.amount), 0) FROM payments
            WHERE payments.user_id = users.id AND payments.status = 'verified'
        ) AS spent
    FROM users
    WHERE " . implode(" AND ", $where) . "
    ORDER BY " . $sorts[$sort]["sql"] . "
    LIMIT " . $paging["per_page"] . " OFFSET " . $paging["offset"]
);

$stmt->execute($params);
$customers = $stmt->fetchAll();

// the latest reservations of the customers on this page, for their details window
$latest = [];

if ($customers) {
    $ids = array_map("intval", array_column($customers, "id"));

    $stmt = $pdo->query("
        SELECT reservations.id, reservations.user_id, reservations.check_in, reservations.check_out,
               reservations.total_amount, reservations.status, rooms.room_name
        FROM reservations
        INNER JOIN rooms ON rooms.id = reservations.room_id
        WHERE reservations.user_id IN (" . implode(",", $ids) . ")
        ORDER BY reservations.created_at DESC, reservations.id DESC
    ");

    foreach ($stmt as $row) {
        if (count($latest[$row["user_id"]] ?? []) < 5) {
            $latest[$row["user_id"]][] = $row;
        }
    }
}

admin_shell_head([
    "title" => "Customers",
    "subtitle" => "Everyone with an account, what they booked and what they paid",
    "active" => "customers",
]);
?>
<style>
    .tags {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
    }

    .mini-list li {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 10px 0;
        border-top: 1px solid var(--line);
        font-size: 13px;
    }

    .mini-list li:first-child {
        border-top: 0;
    }

    .mini-list small {
        display: block;
        color: var(--text-3);
        font-size: 12px;
    }

    .section-title {
        margin: 20px 0 8px;
        color: var(--text-3);
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .filter-form {
        flex-basis: 460px;
        max-width: 600px;
    }

    .filter-form .select {
        width: auto;
        flex: none;
        background-color: var(--surface);
    }

    @media (max-width: 767px) {
        .filter-form {
            flex-wrap: wrap;
            max-width: none;
        }

        .filter-form .select {
            flex: 1;
        }
    }
</style>
<?php admin_shell_body(); ?>

<div class="stack">

    <!-- NUMBERS -->

    <section class="stats stats-compact" aria-label="All customers">

        <div class="stat tone-violet">
            <div class="stat-main">
                <div class="stat-label">Customers</div>
                <div class="stat-value"><?= number_format($totals["everyone"]) ?></div>
                <div class="stat-note">With an account</div>
            </div>
            <div class="stat-icon"><?= icon("users") ?></div>
        </div>

        <div class="stat tone-green">
            <div class="stat-main">
                <div class="stat-label">Active</div>
                <div class="stat-value"><?= number_format($totals["active"]) ?></div>
                <div class="stat-note">Can log in and book</div>
            </div>
            <div class="stat-icon"><?= icon("user-check") ?></div>
        </div>

        <div class="stat tone-blue">
            <div class="stat-main">
                <div class="stat-label">New</div>
                <div class="stat-value"><?= number_format($totals["recent"]) ?></div>
                <div class="stat-note">In the last 30 days</div>
            </div>
            <div class="stat-icon"><?= icon("user-plus") ?></div>
        </div>

        <div class="stat tone-cyan">
            <div class="stat-main">
                <div class="stat-label">Have booked</div>
                <div class="stat-value"><?= number_format($totals["booked"]) ?></div>
                <div class="stat-note">At least one reservation</div>
            </div>
            <div class="stat-icon"><?= icon("calendar") ?></div>
        </div>

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
                    <label class="sr-only" for="q">Search customers</label>
                    <input
                        class="input"
                        type="search"
                        id="q"
                        name="q"
                        value="<?= h($search) ?>"
                        placeholder="Name, email or phone"
                        autocomplete="off"
                        autocapitalize="none"
                        enterkeyhint="search"
                    >
                </div>
                <label class="sr-only" for="sort">Order</label>
                <select class="select" id="sort" name="sort" onchange="this.form.submit()">
                    <?php foreach ($sorts as $key => $option): ?>
                        <option value="<?= h($key) ?>" <?= $sort === $key ? "selected" : "" ?>><?= h($option["label"]) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn" type="submit">Search</button>
            </form>
        </div>

        <?php if ($search !== ""): ?>
            <p class="filter-note">
                <?= number_format($tabCounts[$statusFilter]) ?>
                <?= $tabCounts[$statusFilter] === 1 ? "customer matches" : "customers match" ?>
                “<?= h($search) ?>”.
                <a href="<?= h($here(["q" => "", "page" => 1])) ?>">Clear the search</a>
            </p>
        <?php endif; ?>


        <!-- TABLE -->

        <section class="card">

            <?php if ($customers): ?>

                <div class="table-wrap">
                    <table class="table table-stack">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th class="hide-narrow">Phone</th>
                                <th>Sign-in</th>
                                <th class="right">Reservations</th>
                                <th class="right">Spent</th>
                                <th>Status</th>
                                <th class="hide-narrow">Joined</th>
                                <th class="right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($customers as $customer): ?>
                                <?php
                                $id = (int) $customer["id"];
                                $active = $customer["status"] === "active";
                                $phone = trim((string) $customer["phone"]);
                                ?>
                                <tr>
                                    <td class="cell-lead">
                                        <div class="person">
                                            <?= admin_avatar($customer, 34) ?>
                                            <div class="person-text">
                                                <span class="person-name"><?= h($customer["full_name"]) ?></span>
                                                <span class="person-sub"><?= h($customer["email"]) ?></span>
                                            </div>
                                        </div>
                                    </td>

                                    <td data-label="Phone" class="nowrap hide-narrow"><?= $phone !== "" ? h($phone) : '<span class="muted">None</span>' ?></td>

                                    <td data-label="Sign-in">
                                        <div class="tags">
                                            <span class="tag"><?= $customer["uses_google"] ? "Google" : "Email" ?></span>
                                            <?php if (!(int) $customer["email_verified"]): ?>
                                                <span class="tag">Not verified</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <td data-label="Reservations" class="right num">
                                        <?php if ((int) $customer["reservations"] > 0 && admin_can("reservations")): ?>
                                            <a href="reservations.php?q=<?= h(rawurlencode($customer["email"])) ?>"><?= number_format($customer["reservations"]) ?></a>
                                        <?php else: ?>
                                            <?= number_format($customer["reservations"]) ?>
                                        <?php endif; ?>
                                    </td>

                                    <td data-label="Spent" class="right num strong"><?= h(peso($customer["spent"])) ?></td>

                                    <td data-label="Status"><?= status_pill($active ? "active" : "inactive") ?></td>

                                    <td data-label="Joined" class="nowrap soft hide-narrow"><?= h(admin_date($customer["created_at"])) ?></td>

                                    <td class="cell-wide">
                                        <div class="table-actions">

                                            <button class="btn btn-sm btn-ghost" type="button" data-dialog-open="customer-<?= $id ?>">View</button>

                                            <?php if (admin_can("messages")): ?>
                                                <a class="btn btn-sm btn-ghost" href="messages.php?customer=<?= $id ?>">Message</a>
                                            <?php endif; ?>

                                            <?php if ($active): ?>
                                                <form class="inline-form" method="post" action="<?= h($here()) ?>"
                                                    data-confirm="<?= h($customer["full_name"]) ?> will no longer be able to log in or book. Their reservations and payments stay as they are. You can turn the account on again at any time."
                                                    data-confirm-title="Turn off this account?"
                                                    data-confirm-ok="Turn off"
                                                    data-confirm-tone="danger"
                                                >
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="customer_id" value="<?= $id ?>">
                                                    <input type="hidden" name="action" value="deactivate">
                                                    <button class="btn btn-sm btn-danger-soft" type="submit">Turn off</button>
                                                </form>
                                            <?php else: ?>
                                                <form class="inline-form" method="post" action="<?= h($here()) ?>"
                                                    data-confirm="<?= h($customer["full_name"]) ?> will be able to log in and book again."
                                                    data-confirm-title="Turn on this account?"
                                                    data-confirm-ok="Turn on"
                                                >
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="customer_id" value="<?= $id ?>">
                                                    <input type="hidden" name="action" value="activate">
                                                    <button class="btn btn-sm btn-soft" type="submit">Turn on</button>
                                                </form>
                                            <?php endif; ?>

                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?= admin_pager($paging, fn (int $page) => $here(["page" => $page]), "customers") ?>

            <?php else: ?>

                <div class="empty">
                    <div class="empty-icon"><?= icon("users", 22) ?></div>
                    <h3>No customers found</h3>
                    <p>
                        <?= $search !== "" || $statusFilter !== "all"
                            ? "Nothing matches what you picked."
                            : "People who create an account show up here." ?>
                    </p>
                    <?php if ($search !== "" || $statusFilter !== "all"): ?>
                        <a class="btn" href="customers.php">Show all customers</a>
                    <?php endif; ?>
                </div>

            <?php endif; ?>

        </section>

    </div>

</div>


<!-- DETAILS, ONE WINDOW PER ROW -->

<?php foreach ($customers as $customer): ?>
    <?php
    $id = (int) $customer["id"];
    $phone = trim((string) $customer["phone"]);
    ?>
    <dialog class="dialog dialog-wide" id="customer-<?= $id ?>" aria-labelledby="customer-<?= $id ?>-title">
        <div class="dialog-head">
            <h2 id="customer-<?= $id ?>-title" tabindex="-1" autofocus><?= h($customer["full_name"]) ?></h2>
            <button class="btn btn-ghost btn-icon" type="button" data-dialog-close aria-label="Close"><?= icon("x") ?></button>
        </div>

        <div class="dialog-body">
            <div class="person" style="margin-bottom:14px">
                <?= admin_avatar($customer, 52) ?>
                <div class="person-text">
                    <span class="person-name" style="max-width:none"><?= h($customer["email"]) ?></span>
                    <span class="person-sub" style="max-width:none">Customer #<?= $id ?> · joined <?= h(admin_date($customer["created_at"])) ?></span>
                </div>
            </div>

            <dl class="kv">
                <dt>Status</dt>
                <dd><?= status_pill($customer["status"] === "active" ? "active" : "inactive") ?></dd>

                <dt>Phone</dt>
                <dd><?= $phone !== "" ? h($phone) : "None given" ?></dd>

                <dt>Signs in with</dt>
                <dd><?= $customer["uses_google"] ? "Google" : "Email and password" ?></dd>

                <dt>Email</dt>
                <dd><?= (int) $customer["email_verified"] ? "Verified" : "Not verified yet" ?></dd>

                <dt>Reservations</dt>
                <dd><?= number_format($customer["reservations"]) ?></dd>

                <dt>Total paid</dt>
                <dd class="strong"><?= h(peso($customer["spent"], 2)) ?></dd>
            </dl>

            <h3 class="section-title">Latest reservations</h3>

            <?php if (!empty($latest[$id])): ?>
                <ul class="mini-list">
                    <?php foreach ($latest[$id] as $reservation): ?>
                        <li>
                            <div>
                                <strong>#<?= (int) $reservation["id"] ?> · <?= h($reservation["room_name"]) ?></strong>
                                <small><?= h(admin_stay($reservation["check_in"], $reservation["check_out"])) ?> · <?= h(peso($reservation["total_amount"])) ?></small>
                            </div>
                            <?= status_pill($reservation["status"]) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="muted" style="font-size:13px">No reservations yet.</p>
            <?php endif; ?>
        </div>

        <div class="dialog-actions">
            <?php if (admin_can("messages")): ?>
                <a class="btn" href="messages.php?customer=<?= $id ?>"><?= icon("message") ?> Message</a>
            <?php endif; ?>
            <?php if ((int) $customer["reservations"] > 0 && admin_can("reservations")): ?>
                <a class="btn" href="reservations.php?q=<?= h(rawurlencode($customer["email"])) ?>"><?= icon("calendar") ?> All reservations</a>
            <?php endif; ?>
            <button class="btn btn-primary" type="button" data-dialog-close>Close</button>
        </div>
    </dialog>
<?php endforeach; ?>

<?php admin_shell_end(); ?>
