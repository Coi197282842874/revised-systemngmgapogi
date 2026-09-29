<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/customer-shell.php";

const UPDATES_PER_PAGE = 20;


// ======================================================
// ANSWERS FOR THE BELL IN THE TOP BAR (assets/admin.js)
// ?api=count | list | read_all
// ======================================================

$api = (string) ($_GET["api"] ?? "");

if ($api !== "") {
    $me = customer_boot_json($pdo);

    if ($api === "read_all") {
        if ($_SERVER["REQUEST_METHOD"] !== "POST" || !admin_check_csrf()) {
            http_response_code(400);
            echo json_encode(["ok" => false, "error" => "Your session expired. Reload the page and try again."]);
            exit;
        }

        customer_updates_mark_seen($pdo, $me);
    }

    $response = ["ok" => true, "unread" => customer_updates_unread($pdo, $me)];

    if ($api !== "count") {
        $response["html"] = customer_feed(
            customer_events($pdo, (int) $me["id"], 6),
            (int) $me["id"],
            ["new_since" => $me["notifications_seen_at"], "empty" => "No updates yet"]
        );
    }

    echo json_encode($response);
    exit;
}

$me = customer_boot($pdo);
$userId = (int) $me["id"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (admin_check_csrf()) {
        customer_updates_mark_seen($pdo, $me);
        admin_flash("All updates are marked as read.");
    } else {
        admin_flash("Your session expired. Please try again.", "error");
    }

    header("Location: notifications.php");
    exit;
}


// ======================================================
// THE LIST: updates from ARVE'S House, or everything that happened
// ======================================================

$show = ($_GET["show"] ?? "") === "all" ? "all" : "updates";
$before = max(0, (int) ($_GET["before"] ?? 0));

$events = customer_events($pdo, $userId, UPDATES_PER_PAGE + 1, $show === "updates", $before);
$more = count($events) > UPDATES_PER_PAGE;
$events = array_slice($events, 0, UPDATES_PER_PAGE);

// what is new shows as new this time; from now on it counts as seen
$newSince = $me["notifications_seen_at"];
$hadUnread = customer_updates_unread($pdo, $me);

if ($hadUnread > 0 && $before === 0) {
    customer_updates_mark_seen($pdo, $me);
}

customer_shell_head([
    "title" => "Notifications",
    "subtitle" => "News about your bookings and payments",
    "active" => "notifications",
    "narrow" => true,
]);
?>
<?php customer_shell_body(); ?>

<div class="stack">

    <div class="page-bar">
        <nav class="tabs" aria-label="Show">
            <a class="tab<?= $show === "updates" ? " is-active" : "" ?>" href="notifications.php" <?= $show === "updates" ? 'aria-current="page"' : "" ?>>
                Updates from us
                <?php if ($hadUnread > 0): ?>
                    <span class="tab-count"><?= $hadUnread ?> new</span>
                <?php endif; ?>
            </a>
            <a class="tab<?= $show === "all" ? " is-active" : "" ?>" href="notifications.php?show=all" <?= $show === "all" ? 'aria-current="page"' : "" ?>>All activity</a>
        </nav>
    </div>

    <section class="card">
        <?= customer_feed($events, $userId, [
            "new_since" => $newSince,
            "empty" => $show === "updates"
                ? "No updates yet. When we confirm a booking or check a payment, you will see it here."
                : "Your bookings and payments will show up here.",
        ]) ?>

        <?php if ($more): ?>
            <div class="card-foot">
                <a class="card-link" href="<?= h(admin_url("notifications.php", ["show" => $show === "all" ? "all" : "", "before" => (int) end($events)["id"]])) ?>">Show older <?= icon("chevron-right", 14) ?></a>
            </div>
        <?php elseif ($before > 0): ?>
            <div class="card-foot">
                <a class="card-link" href="<?= h(admin_url("notifications.php", ["show" => $show === "all" ? "all" : ""])) ?>"><?= icon("chevron-left", 14) ?> Back to the newest</a>
            </div>
        <?php endif; ?>
    </section>

    <p class="hint">Messages from ARVE'S House are in <a href="messages.php">Messages</a>.</p>

</div>

<?php customer_shell_end(); ?>
