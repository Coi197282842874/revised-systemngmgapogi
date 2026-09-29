<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/admin-shell.php";

const NOTIFICATIONS_PER_PAGE = 25;


// ======================================================
// ANSWERS FOR THE BELL IN THE TOP BAR (assets/admin.js)
// ?api=count | list | read_all
// ======================================================

$api = (string) ($_GET["api"] ?? "");

if ($api !== "") {
    admin_boot_json($pdo);

    if ($api === "read_all") {
        if ($_SERVER["REQUEST_METHOD"] !== "POST" || !admin_check_csrf()) {
            http_response_code(400);
            echo json_encode(["ok" => false, "error" => "Your session expired. Reload the page and try again."]);
            exit;
        }

        admin_notifications_mark_read($pdo);
    }

    $response = ["ok" => true, "unread" => admin_notifications_unread_count($pdo)];

    if ($api !== "count") {
        $response["html"] = admin_feed(
            admin_notifications($pdo, 6),
            ["notify_links" => true, "empty" => "No notifications yet"]
        );
    }

    echo json_encode($response);
    exit;
}

$admin = admin_boot($pdo);


// ======================================================
// OPEN ONE: mark it read, then go to its page
// ======================================================

if (isset($_GET["open"])) {
    $stmt = $pdo->prepare("SELECT id, link FROM activity_log WHERE id = ? AND notify = 1 LIMIT 1");
    $stmt->execute([(int) $_GET["open"]]);
    $event = $stmt->fetch();

    $link = "";

    if ($event) {
        admin_notifications_mark_read($pdo, (int) $event["id"]);
        $link = admin_safe_link($event["link"]);
    }

    header("Location: " . ($link !== "" ? $link : "notifications.php"));
    exit;
}


// ======================================================
// FILTERS
// ======================================================

$show = ($_GET["show"] ?? "all") === "unread" ? "unread" : "all";

$groups = [
    "" => "Everything",
    "reservation." => "Reservations",
    "payment." => "Payments",
    "message." => "Messages",
    "customer." => "Customers",
];

$type = (string) ($_GET["type"] ?? "");

if (!isset($groups[$type])) {
    $type = "";
}

$before = max(0, (int) ($_GET["before"] ?? 0));

// the address of this page with some of its filters changed
$address = function (array $change = []) use ($show, $type): string {
    $query = array_filter(
        $change + ["show" => $show === "unread" ? "unread" : "", "type" => $type],
        fn ($value) => $value !== "" && $value !== 0
    );

    return "notifications.php" . ($query ? "?" . http_build_query($query) : "");
};


// ======================================================
// MARK AS READ
// ======================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!admin_check_csrf()) {
        admin_flash("Your session expired. Please try again.", "error");
    } elseif (isset($_POST["read_all"])) {
        admin_notifications_mark_read($pdo);
        admin_flash("All notifications marked as read.");
    } elseif (isset($_POST["read"])) {
        admin_notifications_mark_read($pdo, (int) $_POST["read"]);
    }

    header("Location: " . $address(["before" => $before]));
    exit;
}


// ======================================================
// THE LIST
// ======================================================

$filters = ["notify" => true];

if ($show === "unread") {
    $filters["unread"] = true;
}

if ($type !== "") {
    $filters["type_prefix"] = $type;
}

if ($before > 0) {
    $filters["before_id"] = $before;
}

// one more than a page holds tells us whether older ones exist
$events = activity_recent($pdo, NOTIFICATIONS_PER_PAGE + 1, $filters);
$hasOlder = count($events) > NOTIFICATIONS_PER_PAGE;
$events = array_slice($events, 0, NOTIFICATIONS_PER_PAGE);

$unread = admin_notifications_unread_count($pdo);

$total = (int) $pdo->query("SELECT COUNT(*) FROM activity_log WHERE notify = 1")->fetchColumn();

// "Today", "Yesterday", then the date
$days = [];
$today = date("Y-m-d");
$yesterday = date("Y-m-d", strtotime("-1 day"));

foreach ($events as $event) {
    $day = activity_time($event["created_at"], "Y-m-d");

    if ($day === $today) {
        $title = "Today";
    } elseif ($day === $yesterday) {
        $title = "Yesterday";
    } else {
        $title = activity_time($event["created_at"], "l, F j, Y");
    }

    $days[$title][] = $event;
}

admin_shell_head([
    "title" => "Notifications",
    "subtitle" => $unread > 0
        ? $unread . " unread " . ($unread === 1 ? "notification" : "notifications")
        : "You're all caught up",
    "active" => "notifications",
    "narrow" => true,
]);
?>
<style>
    .day-title {
        padding: 14px 20px 6px;
        color: var(--text-3);
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .notice {
        display: flex;
        align-items: stretch;
        border-top: 1px solid var(--line);
    }

    .day-title + .notice {
        border-top: 0;
    }

    .notice .feed {
        flex: 1;
        min-width: 0;
    }

    .notice form {
        display: flex;
        align-items: center;
        flex: none;
        padding-right: 14px;
    }

    @media (max-width: 767px) {
        .day-title {
            padding: 14px 16px 6px;
        }

        .notice form {
            padding-right: 10px;
        }
    }
</style>
<?php admin_shell_body(); ?>

<div class="page-bar">
    <div class="tabs" role="tablist" aria-label="Which notifications">
        <a class="tab<?= $show === "all" ? " is-active" : "" ?>" href="<?= h($address(["show" => ""])) ?>" <?= $show === "all" ? 'aria-current="page"' : "" ?>>
            All <span class="tab-count"><?= number_format($total) ?></span>
        </a>
        <a class="tab<?= $show === "unread" ? " is-active" : "" ?>" href="<?= h($address(["show" => "unread"])) ?>" <?= $show === "unread" ? 'aria-current="page"' : "" ?>>
            Unread <span class="tab-count"><?= number_format($unread) ?></span>
        </a>
    </div>

    <div class="page-bar-end">
        <form method="get" class="row">
            <?php if ($show === "unread"): ?>
                <input type="hidden" name="show" value="unread">
            <?php endif; ?>
            <label class="sr-only" for="type">Show only</label>
            <select class="select select-sm" id="type" name="type" onchange="this.form.submit()">
                <?php foreach ($groups as $prefix => $label): ?>
                    <option value="<?= h($prefix) ?>" <?= $prefix === $type ? "selected" : "" ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button class="btn btn-sm" type="submit">Show</button></noscript>
        </form>

        <form method="post">
            <?= csrf_field() ?>
            <button class="btn btn-sm" type="submit" name="read_all" value="1" <?= $unread > 0 ? "" : "disabled" ?>>
                <?= icon("check") ?> Mark all as read
            </button>
        </form>
    </div>
</div>

<section class="card">
    <?php if ($events): ?>
        <?php foreach ($days as $title => $dayEvents): ?>
            <h2 class="day-title"><?= h($title) ?></h2>

            <?php foreach ($dayEvents as $event): ?>
                <div class="notice">
                    <?= admin_feed([$event], ["notify_links" => true, "actor" => true]) ?>

                    <?php if ($event["read_at"] === null): ?>
                        <form method="post" action="<?= h($address(["before" => $before])) ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn-ghost btn-icon" type="submit" name="read" value="<?= (int) $event["id"] ?>" aria-label="Mark as read" title="Mark as read">
                                <?= icon("check") ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <?php if ($hasOlder || $before > 0): ?>
            <div class="pager">
                <span><?= $before > 0 ? "Older notifications" : "Newest first" ?></span>
                <div class="pager-links">
                    <?php if ($before > 0): ?>
                        <a class="btn btn-sm" href="<?= h($address()) ?>"><?= icon("chevron-left") ?> Newest</a>
                    <?php endif; ?>
                    <?php if ($hasOlder): ?>
                        <a class="btn btn-sm" href="<?= h($address(["before" => (int) end($events)["id"]])) ?>">Older <?= icon("chevron-right") ?></a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="empty">
            <div class="empty-icon"><?= icon("bell", 22) ?></div>
            <h3><?= $show === "unread" ? "Nothing unread" : "No notifications yet" ?></h3>
            <p>
                <?= $show === "unread" || $type !== ""
                    ? "Nothing matches what you picked."
                    : "New bookings, payments and messages from guests will show up here." ?>
            </p>
            <?php if ($show === "unread" || $type !== ""): ?>
                <a class="btn" href="notifications.php">Show all notifications</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<?php admin_shell_end(); ?>
