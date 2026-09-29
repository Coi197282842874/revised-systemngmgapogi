<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/admin-shell.php";
require_once __DIR__ . "/../includes/analytics.php";

$admin = admin_boot($pdo);


// ======================================================
// THE PERIOD: ?range=7 | 30 | 90 | 365 (see ANALYTICS_RANGES)
// Every number on the page is for the chosen period and is compared with the same number
// of days just before it.
// ======================================================

$periods = [
    "7" => ["name" => "Weekly", "unit" => "/week"],
    "30" => ["name" => "Monthly", "unit" => "/month"],
    "90" => ["name" => "Quarterly", "unit" => "/quarter"],
    "365" => ["name" => "Yearly", "unit" => "/year"],
];

$range = (string) ($_GET["range"] ?? "30");

if (!isset($periods[$range])) {
    $range = "30";
}

$settings = ANALYTICS_RANGES[$range];
$window = analytics_range_window($range);
$unit = $periods[$range]["unit"];
$versus = "vs previous " . $settings["short"];

$now = analytics_totals($pdo, $window);
$before = analytics_totals($pdo, analytics_range_window($range, 1));
$shares = analytics_shares($pdo, $window);

$totalCustomers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'customer'")->fetchColumn();
$occupancyChange = $now["occupancy"] - $before["occupancy"];

$revenue = analytics_series($pdo, "revenue", $window, $settings["bucket"]);
$revenueChange = stat_change($now["revenue"], $before["revenue"]);

$bucketName = ["day" => "day", "week" => "week", "month" => "month"][$settings["bucket"]];


// ======================================================
// WAITING FOR AN ADMIN (on phones: the quick actions)
// Each one: how many, the name, the short name for a phone, icon, color, where it goes.
// ======================================================

$counts = admin_counts($pdo);

$arrivalsToday = (int) $pdo->query(
    "SELECT COUNT(*) FROM reservations WHERE status = 'confirmed' AND check_in = CURDATE()"
)->fetchColumn();

$todo = [];

if (admin_can("reservations")) {
    $todo[] = [$counts["pending_reservations"], "Pending reservations", "Pending", "clock", "amber", "reservations.php?status=pending"];
}

if (admin_can("payments")) {
    $todo[] = [$counts["payments_to_verify"], "Payments to verify", "Verify", "banknote", "green", "payment.php?status=pending"];
}

if (admin_can("messages")) {
    $todo[] = [$counts["unread_messages"], "Unread messages", "Messages", "message", "cyan", "messages.php"];
}

if (admin_can("reservations")) {
    $todo[] = [$arrivalsToday, "Arrivals today", "Arrivals", "log-in", "violet", "reservations.php?status=confirmed"];
}

// on a phone one tile stands out: the first one with something waiting
$featured = 0;

foreach ($todo as $index => $item) {
    if ($item[0] > 0) {
        $featured = $index;
        break;
    }
}


// ======================================================
// LISTS
// ======================================================

$recentReservations = $pdo->query(
    "SELECT
        reservations.id,
        reservations.check_in,
        reservations.check_out,
        reservations.total_amount,
        reservations.total_nights,
        reservations.status,
        reservations.created_at,
        rooms.room_name,
        users.full_name,
        users.email,
        users.profile_image
     FROM reservations
     INNER JOIN rooms ON reservations.room_id = rooms.id
     INNER JOIN users ON reservations.user_id = users.id
     ORDER BY reservations.created_at DESC
     LIMIT 6"
)->fetchAll();

$notifications = admin_notifications($pdo, 4);
$guestActivity = activity_recent($pdo, 5, ["not_actor_role" => "admin"]);
$adminActivity = admin_can("activity") ? activity_recent($pdo, 5, ["actor_role" => "admin"]) : [];

// the full log has its own page; until that page exists the "View all" links stay hidden
$activityPage = admin_can("activity") && is_file(__DIR__ . "/activity.php");

admin_shell_head([
    "title" => "Dashboard",
    "subtitle" => "Welcome back, " . $admin["full_name"],
    "active" => "dashboard",
    "mobile_title" => false,    // on phones the title shares its row with the period
]);
?>
<style>
    /* the title (phones) or the dates (wide screens) on the left, the period on the right */
    .dash-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }

    .dash-title {
        min-width: 0;
    }

    .dash-title h1 {
        font-size: 24px;
        font-weight: 700;
        line-height: 1.25;
        letter-spacing: -0.02em;
    }

    .dash-title p {
        margin-top: 2px;
        color: var(--text-3);
        font-size: 13px;
    }

    .dash-dates {
        color: var(--text-3);
        font-size: 13px;
    }

    .period {
        position: relative;
        flex: none;
        margin: 0;
    }

    .period select {
        height: 38px;
        padding: 0 38px 0 16px;
        border: 1px solid var(--line);
        border-radius: 999px;
        background: var(--surface);
        color: var(--text);
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        -webkit-appearance: none;
        appearance: none;
        transition: border-color 150ms ease;
    }

    .period select:focus-visible {
        outline: 2px solid var(--ring);
        outline-offset: 2px;
    }

    .period .i {
        position: absolute;
        top: 50%;
        right: 14px;
        width: 15px;
        height: 15px;
        color: var(--text-3);
        transform: translateY(-50%);
        pointer-events: none;
    }

    @media (hover: hover) and (pointer: fine) {
        .period select:hover {
            border-color: var(--line-2);
        }
    }

    /* phones: the latest bookings as a list of rounded rows */
    .recent-head {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 10px;
    }

    .recent-head h2 {
        font-size: 16px;
        font-weight: 700;
        letter-spacing: -0.01em;
    }

    .recent-head a {
        font-size: 12.5px;
        font-weight: 600;
    }

    .recent-list {
        display: grid;
        gap: 8px;
    }

    .recent-item {
        display: flex;
        align-items: center;
        gap: 12px;
        min-height: 68px;
        padding: 10px 16px 10px 12px;
        border: 1px solid var(--line);
        border-radius: 22px;
        background: var(--surface);
        color: var(--text);
        transition: background-color 120ms ease, transform 140ms var(--ease-out);
    }

    .recent-item:hover {
        text-decoration: none;
    }

    .recent-item:active {
        background: var(--surface-2);
        transform: scale(0.985);
    }

    .recent-text {
        flex: 1;
        min-width: 0;
    }

    .recent-name {
        display: block;
        overflow: hidden;
        font-size: 14px;
        font-weight: 600;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    .recent-sub {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-top: 3px;
        overflow: hidden;
        color: var(--text-3);
        font-size: 12px;
        white-space: nowrap;
    }

    .recent-sub .pill {
        flex: none;
        height: 20px;
        padding: 0 8px;
        font-size: 11px;
    }

    .recent-end {
        flex: none;
        text-align: right;
    }

    .recent-amount {
        display: block;
        font-size: 15px;
        font-weight: 700;
        font-variant-numeric: tabular-nums;
    }

    .recent-note {
        display: block;
        overflow: hidden;
        max-width: 96px;
        color: var(--text-3);
        font-size: 11.5px;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    @media (prefers-reduced-motion: reduce) {
        .recent-item {
            transform: none !important;
        }
    }
</style>
<?php admin_shell_body(); ?>

<div class="stack">

    <!-- TITLE AND PERIOD -->

    <div class="dash-head">
        <div class="dash-title show-sm">
            <h1>Dashboard</h1>
            <p>Welcome back, <?= h($admin["full_name"]) ?></p>
        </div>

        <p class="dash-dates hide-sm"><?= h($settings["label"]) ?> · <?= h($window["label"]) ?></p>

        <form class="period" method="get">
            <label class="sr-only" for="range">Period</label>
            <select id="range" name="range" onchange="this.form.submit()">
                <?php foreach ($periods as $key => $period): ?>
                    <option value="<?= h($key) ?>" <?= (string) $key === $range ? "selected" : "" ?>><?= h($period["name"]) ?></option>
                <?php endforeach; ?>
            </select>
            <?= icon("calendar") ?>
            <noscript><button class="btn btn-sm" type="submit">Show</button></noscript>
        </form>
    </div>


    <!-- STATS -->

    <section class="stats" aria-label="<?= h($settings["label"]) ?>">

        <a class="stat has-ring tone-green" href="<?= admin_can("analytics") ? "analytics.php?range=" . h($range) : "payment.php" ?>">
            <div class="stat-main">
                <div class="stat-label">Revenue</div>
                <div class="stat-value"><?= h(peso($now["revenue"])) ?><span class="stat-unit"><?= h($unit) ?></span></div>
                <?= stat_change_html($revenueChange, $versus) ?>
            </div>
            <div class="stat-icon"><?= icon("wallet") ?></div>
            <?= ring_html($shares["paid"], "of the value booked in this period is paid", "paid") ?>
        </a>

        <a class="stat has-ring is-featured tone-blue" href="reservations.php">
            <div class="stat-main">
                <div class="stat-label">Reservations</div>
                <div class="stat-value"><?= number_format($now["reservations"]) ?><span class="stat-unit"><?= h($unit) ?></span></div>
                <?= stat_change_html(stat_change($now["reservations"], $before["reservations"]), $versus) ?>
            </div>
            <div class="stat-icon"><?= icon("calendar") ?></div>
            <?= ring_html($shares["confirmed"], "of the bookings made in this period are confirmed", "confirmed") ?>
        </a>

        <a class="stat has-ring tone-violet" href="customers.php">
            <div class="stat-main">
                <div class="stat-label">Customers</div>
                <div class="stat-value"><?= number_format($totalCustomers) ?><span class="stat-unit"> in total</span></div>
                <?= stat_change_html(stat_change($totalCustomers, $totalCustomers - $now["customers"]), "vs " . $settings["short"] . " ago") ?>
            </div>
            <div class="stat-icon"><?= icon("users") ?></div>
            <?= ring_html($shares["booked"], "of all customers have booked at least once", "have booked") ?>
        </a>

        <a class="stat has-ring tone-cyan" href="<?= admin_can("analytics") ? "analytics.php?range=" . h($range) : "calendar.php" ?>">
            <div class="stat-main">
                <!-- wide screens show the share; phones show the nights, the ring shows the share -->
                <div class="stat-label"><span class="hide-sm">Occupancy</span><span class="show-sm">Booked nights</span></div>
                <div class="stat-value">
                    <span class="hide-sm"><?= number_format($now["occupancy"], $now["occupancy"] < 10 ? 1 : 0) ?>%</span>
                    <span class="show-sm"><?= number_format($now["booked_nights"]) ?><span class="stat-unit"><?= h($unit) ?></span></span>
                </div>
                <?php if (abs($occupancyChange) < 0.05): ?>
                    <div class="stat-delta"><?= icon("arrow-right", 14) ?> No change</div>
                <?php else: ?>
                    <div class="stat-delta <?= $occupancyChange > 0 ? "is-good" : "is-bad" ?>">
                        <?= icon($occupancyChange > 0 ? "arrow-up-right" : "arrow-down-right", 14) ?>
                        <span class="sr-only"><?= $occupancyChange > 0 ? "Up" : "Down" ?></span>
                        <?= number_format(abs($occupancyChange), 1) ?> pts
                    </div>
                <?php endif; ?>
                <div class="stat-note"><?= h($versus) ?></div>
            </div>
            <div class="stat-icon"><?= icon("trending-up") ?></div>
            <?= ring_html($now["occupancy"], "of the room nights in this period are booked", "occupied") ?>
        </a>

    </section>


    <!-- WAITING FOR YOU (phones: quick actions) -->

    <?php if ($todo): ?>
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
    <?php endif; ?>


    <!-- PHONES: THE LATEST BOOKINGS AS A LIST -->

    <?php if ($recentReservations): ?>
        <section class="recent show-sm" aria-label="Recent reservations">
            <div class="recent-head">
                <h2>Recent Reservations</h2>
                <?php if (admin_can("reservations")): ?>
                    <a href="reservations.php">View All</a>
                <?php endif; ?>
            </div>

            <ul class="recent-list">
                <?php foreach (array_slice($recentReservations, 0, 5) as $reservation): ?>
                    <li>
                        <a class="recent-item" href="<?= admin_can("reservations") ? "reservations.php?q=" . (int) $reservation["id"] : "#" ?>">
                            <?= admin_avatar($reservation, 44) ?>
                            <span class="recent-text">
                                <span class="recent-name"><?= h($reservation["full_name"]) ?></span>
                                <span class="recent-sub">
                                    <?= status_pill($reservation["status"]) ?>
                                    <span><?= h(admin_stay($reservation["check_in"], $reservation["check_out"])) ?></span>
                                </span>
                            </span>
                            <span class="recent-end">
                                <span class="recent-amount"><?= h(peso($reservation["total_amount"])) ?></span>
                                <span class="recent-note"><?= h($reservation["room_name"]) ?></span>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>


    <div class="grid grid-main">

        <!-- LEFT: reservations, then what the team did -->

        <div class="col">

            <section class="card hide-sm">
                <div class="card-head">
                    <h2 class="card-title">Recent Reservations</h2>
                    <?php if (admin_can("reservations")): ?>
                        <a class="card-link" href="reservations.php">View all</a>
                    <?php endif; ?>
                </div>

                <?php if ($recentReservations): ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Guest</th>
                                    <th>Room</th>
                                    <th>Stay</th>
                                    <th class="right">Amount</th>
                                    <th>Status</th>
                                    <th class="hide-narrow">Booked</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentReservations as $reservation): ?>
                                    <tr>
                                        <td>
                                            <div class="person">
                                                <?= admin_avatar($reservation, 32) ?>
                                                <div class="person-text">
                                                    <span class="person-name"><?= h($reservation["full_name"]) ?></span>
                                                    <span class="person-sub"><?= h($reservation["email"]) ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="cell-clip"><?= h($reservation["room_name"]) ?></td>
                                        <td class="nowrap"><?= h(admin_stay($reservation["check_in"], $reservation["check_out"])) ?></td>
                                        <td class="right num strong"><?= h(peso($reservation["total_amount"])) ?></td>
                                        <td><?= status_pill($reservation["status"]) ?></td>
                                        <td class="nowrap soft hide-narrow"><?= h(admin_date($reservation["created_at"], "M j, Y")) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (admin_can("reservations")): ?>
                        <div class="card-foot">
                            <a class="card-link" href="reservations.php">View all reservations <?= icon("chevron-right", 14) ?></a>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="empty">
                        <div class="empty-icon"><?= icon("calendar", 22) ?></div>
                        <h3>No reservations yet</h3>
                        <p>New bookings show up here as soon as a guest makes one.</p>
                    </div>
                <?php endif; ?>
            </section>

            <?php if (admin_can("activity")): ?>
                <section class="card sm-5">
                    <div class="card-head">
                        <h2 class="card-title">Activity Logs</h2>
                        <?php if ($activityPage): ?>
                            <a class="card-link" href="activity.php">View all</a>
                        <?php endif; ?>
                    </div>

                    <?php if ($adminActivity): ?>
                        <div class="table-wrap">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Action</th>
                                        <th class="hide-sm">Details</th>
                                        <th>By</th>
                                        <th class="right">When</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($adminActivity as $event): ?>
                                        <tr>
                                            <td>
                                                <div class="row">
                                                    <span class="feed-icon tone-<?= activity_tone($event["type"]) ?>"><?= icon(activity_icon($event["type"])) ?></span>
                                                    <span class="strong"><?= h(activity_label($event["type"])) ?></span>
                                                </div>
                                            </td>
                                            <td class="soft hide-sm break"><?= h($event["summary"]) ?></td>
                                            <td><?= h($event["actor_name"] !== "" ? $event["actor_name"] : "Admin") ?></td>
                                            <td class="right nowrap soft" title="<?= h(activity_time($event["created_at"])) ?>"><?= h(activity_time_ago($event["created_at"])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty empty-sm">
                            <div class="empty-icon"><?= icon("file-text", 20) ?></div>
                            <p>What admins do (logins, verified payments, edited rooms) is listed here.</p>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

        </div>


        <!-- RIGHT: money, notifications, what guests did -->

        <div class="col">

            <section class="card sm-1">
                <div class="card-head">
                    <div>
                        <h2 class="card-title">Analytics Overview</h2>
                        <p class="card-sub">Revenue per <?= h($bucketName) ?>, <?= h(strtolower($settings["label"])) ?></p>
                    </div>
                    <?php if (admin_can("analytics")): ?>
                        <a class="card-link" href="analytics.php?range=<?= h($range) ?>">Open analytics</a>
                    <?php endif; ?>
                </div>

                <div class="chart-head">
                    <div class="chart-figure"><?= h(peso($now["revenue"])) ?></div>
                    <div class="right">
                        <?php if ($revenueChange["direction"] === "up" || $revenueChange["direction"] === "down"): ?>
                            <div class="stat-delta <?= $revenueChange["good"] ? "is-good" : "is-bad" ?>" style="margin-top:0">
                                <?= icon($revenueChange["direction"] === "up" ? "arrow-up-right" : "arrow-down-right", 14) ?>
                                <span class="sr-only"><?= $revenueChange["direction"] === "up" ? "Up" : "Down" ?></span>
                                <?= h($revenueChange["text"]) ?>
                            </div>
                            <div class="chart-figure-note"><?= h($versus) ?></div>
                        <?php else: ?>
                            <div class="chart-figure-note"><?= number_format($now["payments"]) ?> verified <?= $now["payments"] === 1 ? "payment" : "payments" ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="chart" id="revenue-chart" data-chart>
                    <?= chart_json([
                        "type" => "line",
                        "labels" => $revenue["labels"],
                        "ticks" => $revenue["ticks"],
                        "series" => [["name" => "Revenue", "values" => $revenue["values"]]],
                        "format" => "peso",
                        "height" => 210,
                        "axis" => ucfirst($bucketName),
                        "label" => "Revenue per " . $bucketName . ", " . strtolower($settings["label"]) . ", " . peso($now["revenue"])
                            . " in total. Use the left and right arrow keys to read each value.",
                    ]) ?>
                </div>

                <div class="card-foot">
                    <button class="link-btn" type="button" data-chart-table="revenue-chart">Show as a table</button>
                </div>
            </section>

            <section class="card sm-3">
                <div class="card-head">
                    <h2 class="card-title">Notifications</h2>
                    <a class="card-link" href="notifications.php">View all</a>
                </div>
                <?= admin_feed($notifications, ["notify_links" => true, "empty" => "No notifications yet"]) ?>
            </section>

            <section class="card sm-4">
                <div class="card-head">
                    <h2 class="card-title">Recent Activity</h2>
                    <?php if ($activityPage): ?>
                        <a class="card-link" href="activity.php">View all</a>
                    <?php endif; ?>
                </div>
                <?= admin_feed($guestActivity, ["empty" => "What guests do (bookings, payments, messages) is listed here"]) ?>
            </section>

        </div>

    </div>

</div>

<?php admin_shell_end(); ?>
