<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/admin-shell.php";

// ======================================================
// CUSTOMER MESSAGES (inbox, not live)
//
// Server-rendered: new messages show when the page is opened or refreshed.
// No background polling (the free host does not allow live chat scripts).
// ======================================================

$admin = admin_boot($pdo, "messages");

ensure_chat_schema($pdo);

$adminId = (int) $admin["id"];
$customerId = (int) ($_GET["customer"] ?? $_POST["customer_id"] ?? 0);
$error = "";
$draft = "";

$customer = null;

if ($customerId > 0) {
    $stmt = $pdo->prepare("SELECT id, full_name, email, phone, profile_image, status, created_at FROM users WHERE id = ? AND role = 'customer'");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch() ?: null;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $draft = (string) ($_POST["body"] ?? "");

    if (!admin_check_csrf()) {
        $error = "Your session expired. Please try again.";
    } elseif (!$customer) {
        $error = "That customer no longer exists.";
    } else {
        $result = chat_send($pdo, $customerId, "admin", $adminId, $draft);

        if ($result["ok"]) {
            header("Location: messages.php?customer=" . $customerId . "&sent=1#latest");
            exit;
        }

        $error = $result["error"];
    }
}

$conversations = $pdo->query(
    "SELECT u.id, u.full_name, u.email, u.profile_image,
            m.body AS last_body, m.sender AS last_sender, m.created_at AS last_at,
            (SELECT COUNT(*) FROM chat_messages c
             WHERE c.customer_id = u.id AND c.sender = 'customer' AND c.read_at IS NULL) AS unread
     FROM users u
     JOIN chat_messages m ON m.id = (SELECT MAX(id) FROM chat_messages WHERE customer_id = u.id)
     ORDER BY m.id DESC
     LIMIT 200"
)->fetchAll();

$messages = [];
$reservationCount = 0;

if ($customer) {
    chat_mark_read($pdo, $customerId, "admin");
    $messages = chat_fetch($pdo, $customerId, 0);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE user_id = ?");
    $stmt->execute([$customerId]);
    $reservationCount = (int) $stmt->fetchColumn();

    foreach ($conversations as &$c) {
        if ((int) $c["id"] === $customerId) {
            $c["unread"] = 0;
        }
    }
    unset($c);
}

$totalUnread = array_sum(array_map(fn ($c) => (int) $c["unread"], $conversations));

// Message times are kept in UTC; shown in Philippine time.
function inbox_time(string $utc, bool $short = false): string
{
    $date = new DateTime(str_replace(" ", "T", $utc) . (str_ends_with($utc, "Z") ? "" : "Z"));
    $date->setTimezone(new DateTimeZone("Asia/Manila"));

    if ($short) {
        $today = new DateTime("now", new DateTimeZone("Asia/Manila"));
        return $date->format("Y-m-d") === $today->format("Y-m-d") ? $date->format("g:i A") : $date->format("M j");
    }

    return $date->format("M j, Y · g:i A");
}

admin_shell_head([
    "title" => "Messages",
    "subtitle" => $totalUnread > 0
        ? $totalUnread . " unread " . ($totalUnread === 1 ? "message" : "messages")
        : "Questions from customers, answered by any admin",
    "active" => "messages",
]);
?>
<style>
    .page {
        padding-bottom: 20px;
    }

    /* the two panes fill the screen; the list and the conversation scroll on their own */
    .inbox {
        display: grid;
        grid-template-columns: 340px minmax(0, 1fr);
        gap: 20px;
        height: calc(100vh - var(--topbar-height) - 32px);
        height: calc(100dvh - var(--topbar-height) - 32px);
        min-height: 460px;
    }

    .inbox .card {
        display: flex;
        flex-direction: column;
        min-height: 0;
        overflow: hidden;
    }

    .pane-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        flex: none;
        padding: 14px 16px;
        border-bottom: 1px solid var(--line);
    }

    .pane-head h2 {
        font-size: 15px;
        font-weight: 700;
    }

    .pane-scroll {
        flex: 1;
        min-height: 0;
        overflow-y: auto;
        overscroll-behavior: contain;
    }

    .convo {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 16px;
        border-bottom: 1px solid var(--line);
        color: inherit;
    }

    .convo:hover {
        text-decoration: none;
    }

    @media (hover: hover) and (pointer: fine) {
        .convo {
            transition: background-color 120ms ease;
        }

        .convo:not(.is-active):hover {
            background: var(--surface-2);
        }
    }

    .convo:active {
        background: var(--surface-3);
    }

    .convo.is-active {
        background: var(--accent-soft);
    }

    .convo-text {
        flex: 1;
        min-width: 0;
    }

    .convo-top,
    .convo-last {
        display: flex;
        justify-content: space-between;
        gap: 8px;
    }

    .convo-name {
        overflow: hidden;
        font-size: 13.5px;
        font-weight: 600;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    .convo-time {
        flex: none;
        color: var(--text-3);
        font-size: 11.5px;
    }

    .convo-last {
        margin-top: 2px;
        color: var(--text-3);
        font-size: 12.5px;
    }

    .convo-last span:first-child {
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    .convo.is-unread .convo-name {
        font-weight: 700;
    }

    .convo.is-unread .convo-last span:first-child {
        color: var(--text);
        font-weight: 600;
    }

    .who {
        display: flex;
        align-items: center;
        gap: 12px;
        flex: none;
        padding: 12px 16px;
        border-bottom: 1px solid var(--line);
    }

    .who-text {
        flex: 1;
        min-width: 0;
    }

    .who strong {
        display: block;
        overflow: hidden;
        font-size: 14.5px;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    .who small {
        display: block;
        overflow: hidden;
        color: var(--text-3);
        font-size: 12px;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    .who-actions {
        display: flex;
        flex: none;
        gap: 6px;
    }

    .back {
        display: none;
    }

    .thread {
        display: flex;
        flex-direction: column;
        gap: 8px;
        padding: 16px;
    }

    .msg {
        max-width: 76%;
        padding: 9px 13px;
        border-radius: 16px;
        font-size: 13.5px;
        line-height: 1.45;
        white-space: pre-wrap;
        overflow-wrap: anywhere;
    }

    .msg small {
        display: block;
        margin-top: 3px;
        font-size: 11px;
        opacity: 0.75;
    }

    .msg.me {
        align-self: flex-end;
        border-bottom-right-radius: 5px;
        background: var(--accent);
        color: var(--on-accent);
    }

    .msg.them {
        align-self: flex-start;
        border-bottom-left-radius: 5px;
        background: var(--surface-3);
    }

    .thread-note {
        flex: none;
        margin: 0 16px 10px;
    }

    .compose {
        display: flex;
        align-items: flex-end;
        gap: 10px;
        flex: none;
        padding: 12px 14px 14px;
        border-top: 1px solid var(--line);
    }

    .compose .textarea {
        flex: 1;
        min-height: 46px;
        max-height: 160px;
    }

    .compose .btn {
        flex: none;
        min-height: 46px;
    }

    .pick {
        display: grid;
        place-items: center;
        flex: 1;
    }

    @media (max-width: 1023px) {
        .inbox {
            grid-template-columns: 290px minmax(0, 1fr);
            gap: 14px;
        }
    }

    /* phones: the list, or one conversation */
    @media (max-width: 767px) {
        .inbox {
            grid-template-columns: minmax(0, 1fr);
            height: auto;
            min-height: 0;
        }

        .inbox.is-viewing .list,
        .inbox:not(.is-viewing) .thread-pane {
            display: none;
        }

        .inbox.is-viewing .thread-pane {
            height: calc(100vh - var(--topbar-height) - var(--bottom-bar-height) - 44px);
            height: calc(100dvh - var(--topbar-height) - var(--bottom-bar-height) - env(safe-area-inset-bottom, 0px) - 44px);
            min-height: 380px;
        }

        .back {
            display: inline-flex;
        }

        .who {
            padding: 10px 12px;
        }

        .who-actions .btn span {
            display: none;
        }

        .msg {
            max-width: 88%;
        }

        <?php if ($customer): ?>
        /* in a conversation the page title steps aside: the customer's name is the title */
        .page-title-mobile {
            display: none;
        }

        .page {
            padding-top: 0;
            padding-bottom: calc(var(--bottom-bar-height) + env(safe-area-inset-bottom, 0px) + 14px);
        }
        <?php endif; ?>
    }
</style>
<?php admin_shell_body(); ?>

<div class="inbox<?= $customer ? " is-viewing" : "" ?>">

    <!-- CONVERSATIONS -->

    <section class="card list" aria-label="Conversations">
        <div class="pane-head">
            <h2>Conversations</h2>
            <a class="btn btn-sm btn-ghost" href="messages.php<?= $customer ? "?customer=" . $customerId : "" ?>"><?= icon("refresh") ?> Refresh</a>
        </div>

        <div class="pane-scroll">
            <?php if (!$conversations): ?>
                <div class="empty empty-sm">
                    <div class="empty-icon"><?= icon("message", 20) ?></div>
                    <p>No messages yet. When a customer writes from their account, it shows up here.</p>
                </div>
            <?php endif; ?>

            <?php foreach ($conversations as $c): ?>
                <a
                    class="convo<?= (int) $c["unread"] ? " is-unread" : "" ?><?= (int) $c["id"] === $customerId ? " is-active" : "" ?>"
                    href="messages.php?customer=<?= (int) $c["id"] ?>#latest"
                    <?= (int) $c["id"] === $customerId ? 'aria-current="page"' : "" ?>
                >
                    <?= admin_avatar($c, 40) ?>
                    <span class="convo-text">
                        <span class="convo-top">
                            <span class="convo-name"><?= h($c["full_name"]) ?></span>
                            <span class="convo-time"><?= h(inbox_time($c["last_at"], true)) ?></span>
                        </span>
                        <span class="convo-last">
                            <span><?= $c["last_sender"] === "admin" ? "You: " : "" ?><?= h(mb_substr($c["last_body"], 0, 90)) ?></span>
                            <?php if ((int) $c["unread"]): ?>
                                <span class="nav-badge"><?= (int) $c["unread"] > 9 ? "9+" : (int) $c["unread"] ?><span class="sr-only"> unread</span></span>
                            <?php endif; ?>
                        </span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>


    <!-- ONE CONVERSATION -->

    <section class="card thread-pane" aria-label="Conversation">
        <?php if (!$customer): ?>

            <div class="pick">
                <div class="empty">
                    <div class="empty-icon"><?= icon("inbox", 22) ?></div>
                    <h3><?= $customerId > 0 ? "That customer no longer exists" : "Choose a conversation" ?></h3>
                    <p>Pick one on the left to read it and reply. To write to someone first, open Customers and press Message.</p>
                    <?php if (admin_can("customers")): ?>
                        <a class="btn" href="customers.php"><?= icon("users") ?> Open Customers</a>
                    <?php endif; ?>
                </div>
            </div>

        <?php else: ?>

            <div class="who">
                <a class="btn btn-ghost btn-icon back" href="messages.php" aria-label="Back to all conversations"><?= icon("arrow-left") ?></a>

                <?= admin_avatar($customer, 40) ?>

                <div class="who-text">
                    <strong><?= h($customer["full_name"]) ?></strong>
                    <small><?= h($customer["email"]) ?><?= trim((string) $customer["phone"]) !== "" ? " · " . h($customer["phone"]) : "" ?></small>
                    <small>
                        Customer #<?= (int) $customer["id"] ?> · <?= $reservationCount ?> reservation<?= $reservationCount === 1 ? "" : "s" ?><?= $customer["status"] !== "active" ? " · account turned off" : "" ?>
                    </small>
                </div>

                <div class="who-actions">
                    <?php if ($reservationCount > 0 && admin_can("reservations")): ?>
                        <a class="btn btn-sm" href="reservations.php?q=<?= h(rawurlencode($customer["email"])) ?>" title="Reservations of this customer"><?= icon("calendar") ?> <span>Reservations</span></a>
                    <?php endif; ?>
                    <?php if (admin_can("customers")): ?>
                        <a class="btn btn-sm" href="customers.php?q=<?= h(rawurlencode($customer["email"])) ?>" title="This customer's account"><?= icon("user") ?> <span>Account</span></a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="pane-scroll" id="thread">
                <div class="thread">
                    <?php if (!$messages): ?>
                        <div class="empty empty-sm">
                            <div class="empty-icon"><?= icon("message", 20) ?></div>
                            <p>No messages with <?= h($customer["full_name"]) ?> yet. Write the first one below.</p>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($messages as $m): ?>
                        <div class="msg <?= $m["sender"] === "admin" ? "me" : "them" ?>"><?= h($m["body"]) ?><small><?= $m["sender"] === "admin" ? "Admin · " : "" ?><?= h(inbox_time($m["at"])) ?></small></div>
                    <?php endforeach; ?>

                    <span id="latest"></span>
                </div>
            </div>

            <?php if (isset($_GET["sent"]) && $error === ""): ?>
                <div class="alert alert-success thread-note" role="status"><?= icon("check-circle") ?><p>Reply sent. The customer sees it next time they open Messages.</p></div>
            <?php endif; ?>

            <?php if ($error !== ""): ?>
                <div class="alert alert-error thread-note" role="alert"><?= icon("alert") ?><p><?= h($error) ?></p></div>
            <?php endif; ?>

            <form method="post" class="compose" action="messages.php?customer=<?= $customerId ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="customer_id" value="<?= $customerId ?>">
                <label class="sr-only" for="body">Reply</label>
                <textarea class="textarea" id="body" name="body" rows="1" maxlength="<?= CHAT_MAX_LENGTH ?>" placeholder="Write a reply…" enterkeyhint="send" required><?= h($draft) ?></textarea>
                <button class="btn btn-primary" type="submit"><?= icon("send") ?> Send</button>
            </form>

        <?php endif; ?>
    </section>

</div>

<script>
    // the newest message is at the bottom: start there
    var thread = document.getElementById("thread");
    if (thread) thread.scrollTop = thread.scrollHeight;

    // Coming back to this page (another tab, another app, phone unlocked): reload once to show
    // new messages. No timer, at most once per 15 seconds, and never while a reply is being typed.
    (function () {
        var loadedAt = Date.now();
        var box = document.getElementById("body");

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

<?php admin_shell_end(); ?>
