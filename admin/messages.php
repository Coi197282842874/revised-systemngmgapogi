<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/chat.php";

// ======================================================
// CUSTOMER MESSAGES (inbox, not live)
//
// Server-rendered: new messages show when the page is opened or refreshed.
// No background polling (the free host does not allow live chat scripts).
// ======================================================

if (!isset($_SESSION["user_id"]) || ($_SESSION["role"] ?? "") !== "admin") {
    header("Location: ../login.php");
    exit;
}

ensure_chat_schema($pdo);

$adminId = (int) $_SESSION["user_id"];
$customerId = (int) ($_GET["customer"] ?? $_POST["customer_id"] ?? 0);
$error = "";
$draft = "";

$customer = null;

if ($customerId > 0) {
    $stmt = $pdo->prepare("SELECT id, full_name, email, phone, profile_image, created_at FROM users WHERE id = ? AND role = 'customer'");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch() ?: null;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $draft = (string) ($_POST["body"] ?? "");

    if (!csrf_valid($_POST["csrf_token"] ?? null)) {
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

function inbox_initial(string $name): string
{
    return mb_strtoupper(mb_substr(trim($name) ?: "?", 0, 1));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#f3f4f6">
    <title><?= $totalUnread ? "(" . $totalUnread . ") " : "" ?>Messages | ARVE'S House</title>
    <style>
        :root { --ease-out: cubic-bezier(0.23, 1, 0.32, 1); --ink: #111827; --muted: #6b7280; --line: rgba(17, 24, 39, 0.08); --accent: #2563eb; }
        * { box-sizing: border-box; }
        html { -webkit-tap-highlight-color: transparent; -webkit-text-size-adjust: 100%; text-size-adjust: 100%; }
        body { margin: 0; padding: 24px 20px; padding: max(24px, env(safe-area-inset-top, 0px)) max(20px, env(safe-area-inset-right, 0px)) max(24px, env(safe-area-inset-bottom, 0px)) max(20px, env(safe-area-inset-left, 0px)); font-family: Arial, sans-serif; background: #f3f4f6; color: var(--ink); }
        a { touch-action: manipulation; }
        .page { max-width: 1100px; margin: 0 auto; }
        .topbar { display: flex; justify-content: space-between; align-items: center; gap: 15px; margin-bottom: 18px; }
        .topbar h1 { margin: 0; font-size: 26px; }
        .topbar p { margin: 4px 0 0; color: var(--muted); font-size: 14px; }
        .topbar-links { display: flex; gap: 16px; white-space: nowrap; }
        .topbar-links a { color: var(--accent); text-decoration: none; font-size: 14px; }

        .inbox { display: grid; grid-template-columns: 320px minmax(0, 1fr); gap: 16px; align-items: start; }
        .panel { overflow: hidden; border-radius: 16px; background: #fff; box-shadow: 0 4px 15px rgba(0,0,0,.08); }
        .panel-head { padding: 14px 18px; font-weight: 700; border-bottom: 1px solid var(--line); }

        .convo { display: flex; gap: 12px; align-items: center; padding: 12px 16px; border-bottom: 1px solid var(--line); color: inherit; text-decoration: none; transition: background-color 160ms ease; }
        .convo.active { background: rgba(37, 99, 235, 0.08); }
        .avatar { flex: none; display: grid; place-items: center; width: 42px; height: 42px; overflow: hidden; border-radius: 50%; background: linear-gradient(145deg, #c7d2fe, #818cf8); color: #fff; font-weight: 800; }
        .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .convo-text { flex: 1; min-width: 0; }
        .convo-top { display: flex; justify-content: space-between; gap: 8px; }
        .convo-name { overflow: hidden; font-weight: 700; font-size: 14px; text-overflow: ellipsis; white-space: nowrap; }
        .convo-time { flex: none; font-size: 11px; color: var(--muted); }
        .convo-last { display: flex; justify-content: space-between; gap: 8px; margin-top: 3px; font-size: 13px; color: var(--muted); }
        .convo-last span:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .convo.unread .convo-last span:first-child { color: var(--ink); font-weight: 700; }
        .count { flex: none; min-width: 20px; height: 20px; padding: 0 6px; border-radius: 999px; background: #dc2626; color: #fff; font: 800 11px/20px Arial, sans-serif; text-align: center; }
        .none { padding: 30px 20px; text-align: center; color: var(--muted); font-size: 14px; line-height: 1.5; }

        .who { display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-bottom: 1px solid var(--line); }
        .who strong { display: block; font-size: 15px; }
        .who small { display: block; color: var(--muted); font-size: 12px; overflow-wrap: anywhere; }
        .back { display: none; color: var(--accent); text-decoration: none; font-size: 14px; }
        .thread { display: flex; flex-direction: column; gap: 8px; max-height: 60vh; max-height: 60dvh; overflow-y: auto; padding: 16px; }
        .msg { max-width: 78%; padding: 9px 13px; border-radius: 16px; font-size: 14px; line-height: 1.45; white-space: pre-wrap; overflow-wrap: anywhere; }
        .msg small { display: block; margin-top: 3px; font-size: 11px; opacity: .7; }
        .msg.me { align-self: flex-end; border-bottom-right-radius: 5px; background: var(--accent); color: #fff; }
        .msg.them { align-self: flex-start; border-bottom-left-radius: 5px; background: #f1f5f9; }
        .notice { margin: 0 16px 10px; padding: 9px 12px; border-radius: 10px; font-size: 13px; }
        .notice.ok { background: #dcfce7; color: #166534; }
        .notice.error { background: #fee2e2; color: #991b1b; }
        .compose { display: flex; align-items: flex-end; gap: 10px; padding: 12px 14px 14px; border-top: 1px solid var(--line); }
        .compose textarea { flex: 1; min-height: 46px; max-height: 160px; padding: 11px 14px; border: 1px solid #d1d5db; border-radius: 12px; font: inherit; font-size: 14px; line-height: 1.4; resize: vertical; outline: none; }
        .compose textarea:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(37,99,235,.14); }
        .compose button { flex: none; height: 46px; padding: 0 20px; border: 0; border-radius: 12px; background: var(--accent); color: #fff; font: inherit; font-weight: 700; cursor: pointer; transition: transform 140ms var(--ease-out); }
        .compose button:active { transform: scale(.97); }
        .pick { padding: 60px 20px; text-align: center; color: var(--muted); font-size: 14px; }

        @media (hover: hover) and (pointer: fine) { .convo:hover { background: rgba(17,24,39,.04); } .convo.active:hover { background: rgba(37,99,235,.1); } }
        @media (pointer: coarse) { .compose textarea { font-size: 16px; } }

        /* phones: the list, or one conversation */
        @media (max-width: 760px) {
            body { padding-left: 12px; padding-right: 12px; }
            .topbar { align-items: flex-start; flex-direction: column; gap: 8px; }
            .topbar h1 { font-size: 22px; }
            .inbox { grid-template-columns: 1fr; }
            .inbox.viewing .list { display: none; }
            .inbox:not(.viewing) .thread-panel { display: none; }
            .back { display: inline; }
            .msg { max-width: 88%; }
            .compose { flex-direction: column; align-items: stretch; }
        }
    </style>
<?php $glassTheme = "admin"; require __DIR__ . "/../includes/glass.php"; ?>
</head>
<body>
    <main class="page">
        <div class="topbar">
            <div>
                <h1>Messages<?= $totalUnread ? " (" . $totalUnread . " new)" : "" ?></h1>
                <p>Customer questions. The page refreshes when you come back to it.</p>
            </div>
            <div class="topbar-links">
                <a href="messages.php<?= $customer ? "?customer=" . $customerId : "" ?>">↻ Refresh</a>
                <a href="dashboard.php">Back to Dashboard</a>
            </div>
        </div>

        <div class="inbox<?= $customer ? " viewing" : "" ?>">

            <section class="panel list" aria-label="Conversations">
                <div class="panel-head">Conversations</div>

                <?php if (!$conversations): ?>
                    <p class="none">No messages yet. When a customer writes from their account, it shows up here.</p>
                <?php endif; ?>

                <?php foreach ($conversations as $c): ?>
                    <a class="convo<?= (int) $c["unread"] ? " unread" : "" ?><?= (int) $c["id"] === $customerId ? " active" : "" ?>" href="messages.php?customer=<?= (int) $c["id"] ?>#latest">
                        <span class="avatar">
                            <?php if (!empty($c["profile_image"])): ?>
                                <img src="../<?= htmlspecialchars($c["profile_image"]) ?>" alt="">
                            <?php else: ?>
                                <?= htmlspecialchars(inbox_initial($c["full_name"])) ?>
                            <?php endif; ?>
                        </span>
                        <span class="convo-text">
                            <span class="convo-top">
                                <span class="convo-name"><?= htmlspecialchars($c["full_name"]) ?></span>
                                <span class="convo-time"><?= htmlspecialchars(inbox_time($c["last_at"], true)) ?></span>
                            </span>
                            <span class="convo-last">
                                <span><?= $c["last_sender"] === "admin" ? "You: " : "" ?><?= htmlspecialchars(mb_substr($c["last_body"], 0, 90)) ?></span>
                                <?php if ((int) $c["unread"]): ?><span class="count"><?= (int) $c["unread"] > 9 ? "9+" : (int) $c["unread"] ?></span><?php endif; ?>
                            </span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </section>

            <section class="panel thread-panel" aria-label="Conversation">
                <?php if (!$customer): ?>
                    <p class="pick">Choose a conversation to read and reply.</p>
                <?php else: ?>
                    <div class="who">
                        <span class="avatar">
                            <?php if (!empty($customer["profile_image"])): ?>
                                <img src="../<?= htmlspecialchars($customer["profile_image"]) ?>" alt="">
                            <?php else: ?>
                                <?= htmlspecialchars(inbox_initial($customer["full_name"])) ?>
                            <?php endif; ?>
                        </span>
                        <div style="flex:1;min-width:0">
                            <strong><?= htmlspecialchars($customer["full_name"]) ?></strong>
                            <small><?= htmlspecialchars($customer["email"]) ?><?= $customer["phone"] !== "" ? " · " . htmlspecialchars($customer["phone"]) : "" ?></small>
                            <small>Customer #<?= (int) $customer["id"] ?> · <?= $reservationCount ?> reservation<?= $reservationCount === 1 ? "" : "s" ?></small>
                        </div>
                        <a class="back" href="messages.php">← All</a>
                    </div>

                    <div class="thread" id="thread">
                        <?php foreach ($messages as $m): ?>
                            <div class="msg <?= $m["sender"] === "admin" ? "me" : "them" ?>"><?= htmlspecialchars($m["body"]) ?><small><?= htmlspecialchars(inbox_time($m["at"])) ?></small></div>
                        <?php endforeach; ?>
                        <span id="latest"></span>
                    </div>

                    <?php if (isset($_GET["sent"]) && $error === ""): ?>
                        <p class="notice ok">Reply sent. The customer sees it next time they open Messages.</p>
                    <?php endif; ?>

                    <?php if ($error !== ""): ?>
                        <p class="notice error" role="alert"><?= htmlspecialchars($error) ?></p>
                    <?php endif; ?>

                    <form method="POST" class="compose" action="messages.php?customer=<?= $customerId ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="customer_id" value="<?= $customerId ?>">
                        <label for="body" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)">Reply</label>
                        <textarea id="body" name="body" maxlength="<?= CHAT_MAX_LENGTH ?>" placeholder="Write a reply…" required><?= htmlspecialchars($draft) ?></textarea>
                        <button type="submit">Send</button>
                    </form>
                <?php endif; ?>
            </section>

        </div>
    </main>

    <script>
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
</body>
</html>
