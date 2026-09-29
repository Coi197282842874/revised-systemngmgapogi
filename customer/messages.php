<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/customer-shell.php";

// ======================================================
// MESSAGES WITH ARVE'S HOUSE (inbox, not live)
//
// A normal page: new replies show when the page is opened or refreshed.
// No background polling (the free host does not allow live chat scripts).
// ======================================================

$me = customer_boot($pdo);
$userId = (int) $me["id"];

$error = "";
$draft = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $draft = (string) ($_POST["body"] ?? "");

    if (!admin_check_csrf()) {
        $error = "Your session expired. Please try again.";
    } else {
        $result = chat_send($pdo, $userId, "customer", $userId, $draft);

        if ($result["ok"]) {
            // Post/Redirect/Get: a refresh won't send the message twice
            header("Location: messages.php?sent=1#latest");
            exit;
        }

        $error = $result["error"];
    }
}

// "Ask about it" on a reservation: the message starts with which one
if ($draft === "" && isset($_GET["about"])) {
    $stmt = $pdo->prepare("
        SELECT r.id, r.check_in, r.check_out, rooms.room_name
        FROM reservations r
        INNER JOIN rooms ON rooms.id = r.room_id
        WHERE r.id = ? AND r.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([(int) $_GET["about"], $userId]);

    if ($about = $stmt->fetch()) {
        $draft = "About reservation #" . (int) $about["id"] . " (" . $about["room_name"] . ", "
            . admin_stay($about["check_in"], $about["check_out"]) . "): ";
    }
}

// reading the conversation marks our replies as read (before the menu counts them)
chat_mark_read($pdo, $userId, "customer");

$messages = chat_fetch($pdo, $userId, 0);

function inbox_time(string $iso): string
{
    // stored in UTC; show Philippine time
    $date = new DateTime($iso);
    $date->setTimezone(new DateTimeZone("Asia/Manila"));

    return $date->format("M j, Y · g:i A");
}

customer_shell_head([
    "title" => "Messages",
    "subtitle" => "Questions about rooms, your booking or a payment",
    "active" => "messages",
    "narrow" => true,
]);
?>
<?php customer_shell_body(); ?>

<div class="stack">

    <section class="card chat" aria-label="Your conversation with ARVE'S House">

        <div class="card-head">
            <div class="person">
                <span class="brand-mark" aria-hidden="true"><?= icon("home") ?></span>
                <div class="person-text">
                    <span class="person-name">ARVE'S House</span>
                    <span class="person-sub">Our replies show up here</span>
                </div>
            </div>
            <a class="btn btn-sm btn-ghost" href="messages.php#latest" aria-label="Check for new replies"><?= icon("refresh") ?><span class="hide-sm"> Refresh</span></a>
        </div>

        <div class="chat-thread" id="thread">
            <?php if (!$messages): ?>
                <div class="empty empty-sm">
                    <div class="empty-icon"><?= icon("message", 20) ?></div>
                    <p><strong>Hi <?= h(customer_first_name($me)) ?>!</strong> No messages yet. Write to us below.</p>
                </div>
            <?php endif; ?>

            <?php foreach ($messages as $m): ?>
                <div class="msg <?= $m["sender"] === "customer" ? "me" : "them" ?>"><?php if ($m["sender"] === "admin"): ?><span class="msg-who">ARVE'S House</span><?php endif; ?><?= h($m["body"]) ?><small><?= h(inbox_time($m["at"])) ?></small></div>
            <?php endforeach; ?>

            <span id="latest"></span>
        </div>

        <?php if (isset($_GET["sent"]) && $error === ""): ?>
            <div class="alert alert-success chat-note" role="status"><?= icon("check-circle") ?><p>Message sent. We will reply as soon as we can.</p></div>
        <?php endif; ?>

        <?php if ($error !== ""): ?>
            <div class="alert alert-error chat-note" role="alert"><?= icon("alert") ?><p><?= h($error) ?></p></div>
        <?php endif; ?>

        <form method="post" class="compose" action="messages.php">
            <?= csrf_field() ?>
            <label class="sr-only" for="body">Your message</label>
            <textarea class="textarea" id="body" name="body" rows="1" maxlength="<?= CHAT_MAX_LENGTH ?>" placeholder="Type your message…" enterkeyhint="send" required><?= h($draft) ?></textarea>
            <button class="btn btn-primary" type="submit"><?= icon("send") ?> Send</button>
        </form>

    </section>

</div>

<script>
    // open at the newest message
    var thread = document.getElementById("thread");
    if (thread) thread.scrollTop = thread.scrollHeight;

    // a message about a reservation: the cursor waits after its first words
    var box = document.getElementById("body");
    if (box && box.value && location.search.indexOf("about=") !== -1) {
        box.focus();
        box.setSelectionRange(box.value.length, box.value.length);
    }

    // Coming back to this page (another tab, another app, phone unlocked): reload once to show
    // new messages. No timer, at most once per 15 seconds, and never while a reply is being typed.
    (function () {
        var loadedAt = Date.now();

        function onReturn() {
            if (document.hidden || Date.now() - loadedAt < 15000) return;
            if (box && box.value.trim()) return;
            // a URL without "#..." makes a real reload; drop the one-time "sent" notice
            location.assign(location.pathname + location.search.replace(/([?&])sent=1(&|$)/, "$1").replace(/[?&]$/, ""));
        }

        document.addEventListener("visibilitychange", onReturn);
        window.addEventListener("focus", onReturn);
    })();
</script>

<?php customer_shell_end(); ?>
