<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/admin-shell.php";

// ======================================================
// ACTIVITY LOGS: who did what, and when
// Everything written by log_activity() (includes/activity.php), newest first.
// ======================================================

$admin = admin_boot($pdo, "activity");

const ACTIVITY_PER_PAGE = 30;
const ACTIVITY_EXPORT_LIMIT = 5000;


// ======================================================
// FILTERS: ?by=  ?type=  ?when=  ?q=  ?before=
// ======================================================

$actors = [
    "all" => "Everyone",
    "admin" => "Admins",
    "customer" => "Customers",
    "system" => "Automatic",
];

$by = $_GET["by"] ?? "all";

if (!isset($actors[$by])) {
    $by = "all";
}

$type = (string) ($_GET["type"] ?? "");

if (!isset(ACTIVITY_GROUPS[$type])) {
    $type = "";
}

$periods = [
    "" => "Any time",
    "today" => "Today",
    "7" => "Last 7 days",
    "30" => "Last 30 days",
];

$when = (string) ($_GET["when"] ?? "");

if (!isset($periods[$when])) {
    $when = "";
}

$search = admin_query();
$before = max(0, (int) ($_GET["before"] ?? 0));

// the log keeps UTC; days are counted in Philippine time
$since = function (int $daysBack): string {
    return gmdate("Y-m-d H:i:s", strtotime("today -" . $daysBack . " days"));
};

$filters = [];

if ($by !== "all") {
    $filters["actor_role"] = $by;
}

if ($type !== "") {
    $filters["type_prefix"] = $type;
}

if ($when === "today") {
    $filters["since"] = $since(0);
} elseif ($when !== "") {
    $filters["since"] = $since((int) $when - 1);
}

if ($search !== "") {
    $filters["search"] = $search;
}

$here = fn (array $change = []) => admin_url(
    "activity.php",
    $change + ["by" => $by, "type" => $type, "when" => $when, "q" => $search]
);


// ======================================================
// DOWNLOAD WHAT IS SHOWN (CSV)
// ======================================================

if (($_GET["export"] ?? "") === "csv") {
    header("Content-Type: text/csv; charset=utf-8");
    header('Content-Disposition: attachment; filename="arves-house-activity-' . date("Y-m-d") . '.csv"');
    header("Cache-Control: no-store");

    $out = fopen("php://output", "w");
    fwrite($out, "\xEF\xBB\xBF");   // so Excel reads the peso sign and accents correctly

    fputcsv($out, ["Date (Philippine time)", "Event", "Details", "By", "Role", "IP address"]);

    foreach (activity_recent($pdo, ACTIVITY_EXPORT_LIMIT, $filters) as $event) {
        $cells = [
            activity_time($event["created_at"], "Y-m-d H:i:s"),
            activity_label($event["type"]),
            $event["summary"],
            $event["actor_name"],
            $event["actor_role"],
            (string) $event["ip"],
        ];

        // a cell that starts like a formula is kept as text when a spreadsheet opens the file
        fputcsv($out, array_map(
            fn ($cell) => preg_match('/^[=+\-@\t\r]/', (string) $cell) ? "'" . $cell : $cell,
            $cells
        ));
    }

    fclose($out);
    exit;
}


// ======================================================
// NUMBERS AND THE LIST
// ======================================================

$today = activity_count($pdo, ["since" => $since(0)]);
$week = activity_count($pdo, ["since" => $since(6)]);
$weekAdmins = activity_count($pdo, ["since" => $since(6), "actor_role" => "admin"]);
$weekCustomers = activity_count($pdo, ["since" => $since(6), "actor_role" => "customer"]);

$matching = activity_count($pdo, $filters);

// one more than a page holds tells us whether older ones exist
$events = activity_recent($pdo, ACTIVITY_PER_PAGE + 1, $filters + ($before > 0 ? ["before_id" => $before] : []));
$hasOlder = count($events) > ACTIVITY_PER_PAGE;
$events = array_slice($events, 0, ACTIVITY_PER_PAGE);

$filtered = $by !== "all" || $type !== "" || $when !== "" || $search !== "";

$roleNames = ["admin" => "Admin", "customer" => "Customer", "system" => "Automatic", "guest" => "Visitor"];

admin_shell_head([
    "title" => "Activity Logs",
    "subtitle" => "Who did what, and when",
    "active" => "activity",
]);
?>
<style>
    .filter-row {
        display: flex;
        flex: 1 1 420px;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: 8px;
    }

    .filter-row .select {
        width: auto;
        background-color: var(--surface);
    }

    .filter-row .filter-search {
        flex: 1 1 200px;
        max-width: 300px;
    }

    .event {
        display: flex;
        align-items: center;
        gap: 10px;
        min-width: 0;
    }

    .event .feed-icon {
        margin-top: 0;
    }

    @media (max-width: 767px) {
        .filter-row {
            justify-content: stretch;
        }

        .filter-row .select {
            flex: 1 1 130px;
        }

        .filter-row .filter-search {
            flex-basis: 100%;
            max-width: none;
        }
    }
</style>
<?php admin_shell_body(); ?>

<div class="stack">

    <!-- NUMBERS -->

    <section class="stats stats-compact" aria-label="How busy it was">

        <div class="stat tone-blue">
            <div class="stat-main">
                <div class="stat-label">Today</div>
                <div class="stat-value"><?= number_format($today) ?></div>
                <div class="stat-note"><?= $today === 1 ? "event" : "events" ?></div>
            </div>
            <div class="stat-icon"><?= icon("activity") ?></div>
        </div>

        <div class="stat tone-cyan">
            <div class="stat-main">
                <div class="stat-label">Last 7 days</div>
                <div class="stat-value"><?= number_format($week) ?></div>
                <div class="stat-note"><?= $week === 1 ? "event" : "events" ?></div>
            </div>
            <div class="stat-icon"><?= icon("calendar") ?></div>
        </div>

        <div class="stat tone-violet">
            <div class="stat-main">
                <div class="stat-label">By admins</div>
                <div class="stat-value"><?= number_format($weekAdmins) ?></div>
                <div class="stat-note">In the last 7 days</div>
            </div>
            <div class="stat-icon"><?= icon("shield") ?></div>
        </div>

        <div class="stat tone-green">
            <div class="stat-main">
                <div class="stat-label">By customers</div>
                <div class="stat-value"><?= number_format($weekCustomers) ?></div>
                <div class="stat-note">In the last 7 days</div>
            </div>
            <div class="stat-icon"><?= icon("users") ?></div>
        </div>

    </section>


    <div>

        <!-- ONE ROW OF FILTERS -->

        <div class="page-bar">
            <nav class="tabs" aria-label="Done by">
                <?php foreach ($actors as $key => $label): ?>
                    <a
                        class="tab<?= $by === $key ? " is-active" : "" ?>"
                        href="<?= h($here(["by" => $key])) ?>"
                        <?= $by === $key ? 'aria-current="page"' : "" ?>
                    ><?= h($label) ?></a>
                <?php endforeach; ?>
            </nav>

            <form class="filter-row" method="get" role="search">
                <?php if ($by !== "all"): ?>
                    <input type="hidden" name="by" value="<?= h($by) ?>">
                <?php endif; ?>

                <label class="sr-only" for="when">Period</label>
                <select class="select" id="when" name="when" onchange="this.form.submit()">
                    <?php foreach ($periods as $key => $label): ?>
                        <option value="<?= h($key) ?>" <?= (string) $key === $when ? "selected" : "" ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>

                <label class="sr-only" for="type">Kind of event</label>
                <select class="select" id="type" name="type" onchange="this.form.submit()">
                    <option value="">Every kind</option>
                    <?php foreach (ACTIVITY_GROUPS as $prefix => $label): ?>
                        <option value="<?= h($prefix) ?>" <?= $prefix === $type ? "selected" : "" ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>

                <div class="filter-search">
                    <?= icon("search") ?>
                    <label class="sr-only" for="q">Search the log</label>
                    <input class="input" type="search" id="q" name="q" value="<?= h($search) ?>" placeholder="Name or words" autocomplete="off" enterkeyhint="search">
                </div>

                <button class="btn" type="submit">Search</button>
            </form>
        </div>

        <p class="filter-note">
            <?= number_format($matching) ?> <?= $matching === 1 ? "event" : "events" ?><?= $filtered ? " match" . ($matching === 1 ? "es" : "") . " what you picked" : " in the log" ?>.
            <?php if ($filtered): ?>
                <a href="activity.php">Show everything</a>
            <?php endif; ?>
        </p>


        <!-- THE LOG -->

        <section class="card">

            <?php if ($events): ?>

                <div class="table-wrap">
                    <table class="table table-stack">
                        <thead>
                            <tr>
                                <th>Event</th>
                                <th>Details</th>
                                <th>By</th>
                                <th class="right">When</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $event): ?>
                                <?php
                                $link = admin_safe_link($event["link"]);
                                $who = trim((string) $event["actor_name"]);
                                $role = $roleNames[$event["actor_role"]] ?? ucfirst((string) $event["actor_role"]);
                                ?>
                                <tr>
                                    <td class="cell-lead">
                                        <div class="event">
                                            <span class="feed-icon tone-<?= activity_tone($event["type"]) ?>"><?= icon(activity_icon($event["type"])) ?></span>
                                            <span class="strong nowrap"><?= h(activity_label($event["type"])) ?></span>
                                        </div>
                                    </td>

                                    <td data-label="Details" class="cell-wide break">
                                        <?php if ($link !== ""): ?>
                                            <a href="<?= h($link) ?>"><?= h($event["summary"]) ?></a>
                                        <?php else: ?>
                                            <?= h($event["summary"]) ?>
                                        <?php endif; ?>
                                        <?php if (trim((string) $event["ip"]) !== ""): ?>
                                            <span class="cell-sub">From <?= h($event["ip"]) ?></span>
                                        <?php endif; ?>
                                    </td>

                                    <td data-label="By">
                                        <?= h($who !== "" ? $who : $role) ?>
                                        <?php if ($who !== ""): ?>
                                            <span class="cell-sub"><?= h($role) ?></span>
                                        <?php endif; ?>
                                    </td>

                                    <td data-label="When" class="right nowrap">
                                        <?= h(activity_time_ago($event["created_at"])) ?>
                                        <span class="cell-sub"><?= h(activity_time($event["created_at"])) ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pager">
                    <a class="link-btn" href="<?= h($here(["export" => "csv"])) ?>"><?= icon("download", 14) ?> Download as CSV</a>

                    <div class="pager-links">
                        <?php if ($before > 0): ?>
                            <a class="btn btn-sm" href="<?= h($here()) ?>"><?= icon("chevron-left") ?> Newest</a>
                        <?php endif; ?>
                        <?php if ($hasOlder): ?>
                            <a class="btn btn-sm" href="<?= h($here(["before" => (int) end($events)["id"]])) ?>" rel="next">Older <?= icon("chevron-right") ?></a>
                        <?php endif; ?>
                    </div>
                </div>

            <?php else: ?>

                <div class="empty">
                    <div class="empty-icon"><?= icon("file-text", 22) ?></div>
                    <h3><?= $filtered ? "Nothing matches" : "The log is empty" ?></h3>
                    <p>
                        <?= $filtered
                            ? "Try another period, another kind of event or other words."
                            : "Bookings, payments, messages and what admins do are written here as they happen." ?>
                    </p>
                    <?php if ($filtered): ?>
                        <a class="btn" href="activity.php">Show everything</a>
                    <?php endif; ?>
                </div>

            <?php endif; ?>

        </section>

    </div>

</div>

<?php admin_shell_end(); ?>
