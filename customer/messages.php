<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/icons.php";
require_once __DIR__ . "/../includes/chat.php";

// ======================================================
// MESSAGES WITH ARVE'S HOUSE (inbox, not live)
//
// A normal page: new replies show when the page is opened or refreshed.
// No background polling (the free host does not allow live chat scripts).
// ======================================================

if (!isset($_SESSION["user_id"]) || ($_SESSION["role"] ?? "") !== "customer") {
    header("Location: ../login.php?redirect=" . urlencode("customer/messages.php"));
    exit;
}

$userId = (int) $_SESSION["user_id"];

ensure_chat_schema($pdo);

$error = "";
$draft = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $draft = (string) ($_POST["body"] ?? "");

    if (!csrf_valid($_POST["csrf_token"] ?? null)) {
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

chat_mark_read($pdo, $userId, "customer");

$messages = chat_fetch($pdo, $userId, 0);
$firstName = explode(" ", trim($_SESSION["full_name"] ?? ""))[0] ?: "there";

function inbox_time(string $iso): string
{
    // stored in UTC; show Philippine time
    $date = new DateTime($iso);
    $date->setTimezone(new DateTimeZone("Asia/Manila"));

    return $date->format("M j, Y · g:i A");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#f4e7d9">
    <title>Messages | ARVE'S House</title>
    <style>
        :root {
            --brown: #7a4f36;
            --brown-dark: #513421;
            --text: #241a15;
            --muted: #786d66;
            --border: rgba(122, 79, 54, 0.14);
            --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
        }

        * { box-sizing: border-box; }
        html { -webkit-tap-highlight-color: transparent; -webkit-text-size-adjust: 100%; text-size-adjust: 100%; }

        body {
            margin: 0;
            min-height: 100vh;
            min-height: 100dvh;
            font-family: Arial, Helvetica, sans-serif;
            color: var(--text);
            background: linear-gradient(135deg, #fffdf9 0%, #f8f0e6 48%, #eee0d1 100%);
        }

        .navbar {
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            min-height: 70px;
            padding: 0 max(5%, env(safe-area-inset-right, 0px)) 0 max(5%, env(safe-area-inset-left, 0px));
            padding-top: env(safe-area-inset-top, 0px);
            background: rgba(255, 250, 244, 0.9);
            border-bottom: 1px solid var(--border);
        }

        .brand { display: flex; align-items: center; gap: 10px; color: var(--text); font-size: 18px; font-weight: 800; text-decoration: none; }
        .brand-mark { display: grid; place-items: center; width: 38px; height: 38px; border-radius: 12px; background: linear-gradient(135deg, var(--brown), var(--brown-dark)); color: #fff; }
        .nav-back { color: var(--brown-dark); font-size: 14px; font-weight: 700; text-decoration: none; }

        .page { width: min(720px, 92%); margin: 28px auto 40px; }

        .head h1 { margin: 0 0 4px; font-family: Georgia, "Times New Roman", serif; font-size: 32px; }
        .head p { margin: 0 0 18px; color: var(--muted); font-size: 14px; line-height: 1.5; }

        .box { overflow: hidden; border-radius: 20px; }

        .thread { display: flex; flex-direction: column; gap: 10px; min-height: 260px; max-height: 60vh; max-height: 60dvh; overflow-y: auto; padding: 20px 18px; }

        .empty { margin: auto; text-align: center; color: var(--muted); font-size: 14px; line-height: 1.6; }
        .empty strong { display: block; color: var(--text); font-size: 17px; }

        .msg { max-width: 82%; padding: 10px 14px; border-radius: 16px; font-size: 14.5px; line-height: 1.5; white-space: pre-wrap; overflow-wrap: anywhere; }
        .msg small { display: block; margin-top: 4px; font-size: 11px; opacity: 0.7; }
        .msg.me { align-self: flex-end; border-bottom-right-radius: 5px; background: linear-gradient(145deg, #8a5e42, #5b3a26); color: #fff; }
        .msg.them { align-self: flex-start; border-bottom-left-radius: 5px; border: 1px solid rgba(255, 255, 255, 0.9); background: rgba(255, 255, 255, 0.85); }
        .msg.them .who { display: block; margin-bottom: 2px; font-size: 11.5px; font-weight: 800; color: var(--brown); }

        .notice { margin: 0 18px 12px; padding: 10px 14px; border-radius: 10px; font-size: 13px; }
        .notice.ok { background: #e5f7e9; color: #167532; }
        .notice.error { background: #fdecec; color: #9f1d1d; }

        .compose { display: flex; align-items: flex-end; gap: 10px; padding: 14px 16px 16px; border-top: 1px solid var(--border); }
        .compose textarea { flex: 1; min-height: 48px; max-height: 160px; padding: 12px 14px; border: 1px solid rgba(36, 26, 21, 0.14); border-radius: 14px; background: #fff; font: inherit; font-size: 15px; line-height: 1.4; color: var(--text); resize: vertical; outline: none; }
        .compose textarea:focus { border-color: var(--brown); box-shadow: 0 0 0 3px rgba(122, 79, 54, 0.14); }
        .compose button { flex: none; height: 48px; padding: 0 20px; border: 0; border-radius: 14px; background: linear-gradient(135deg, var(--brown), var(--brown-dark)); color: #fff; font: inherit; font-weight: 800; cursor: pointer; touch-action: manipulation; transition: transform 140ms var(--ease-out); }
        .compose button:active { transform: scale(0.97); }

        .tools { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-top: 12px; color: var(--muted); font-size: 13px; }
        .tools a { color: var(--brown-dark); font-weight: 700; text-decoration: none; }

        @media (pointer: coarse) { .compose textarea { font-size: 16px; } }
        @media (max-width: 560px) {
            .head h1 { font-size: 26px; }
            .thread { max-height: 58dvh; padding: 16px 12px; }
            .compose { flex-direction: column; align-items: stretch; }
            .compose button { width: 100%; }
        }
        @media (prefers-reduced-motion: reduce) { .compose button:active { transform: none; } }
    </style>
<?php require __DIR__ . "/../includes/glass.php"; ?>
<style>
    /* the message box is a glass card too */
    html body .box {
        background: var(--glass-bg) !important;
        border: 1px solid var(--glass-border) !important;
        box-shadow: var(--glass-highlight), var(--glass-shadow) !important;
        -webkit-backdrop-filter: var(--glass-blur);
        backdrop-filter: var(--glass-blur);
    }
</style>
</head>
<body>

<nav class="navbar">
    <a href="../index.php" class="brand"><span class="brand-mark"><?= icon("home") ?></span> ARVE'S House</a>
    <a href="dashboard.php" class="nav-back">← My Reservations</a>
</nav>

<main class="page">

    <div class="head">
        <h1>Messages</h1>
        <p>Send us a question about rooms, your booking or payment. We'll reply here. New replies show when you come back to this page.</p>
    </div>

    <section class="box">

        <div class="thread" id="thread">
            <?php if (!$messages): ?>
                <div class="empty">
                    <strong>Hi <?= htmlspecialchars($firstName) ?>!</strong>
                    No messages yet. Write to us below.
                </div>
            <?php endif; ?>

            <?php foreach ($messages as $m): ?>
                <div class="msg <?= $m["sender"] === "customer" ? "me" : "them" ?>"><?php if ($m["sender"] === "admin"): ?><span class="who">ARVE'S House</span><?php endif; ?><?= htmlspecialchars($m["body"]) ?><small><?= htmlspecialchars(inbox_time($m["at"])) ?></small></div>
            <?php endforeach; ?>

            <span id="latest"></span>
        </div>

        <?php if (isset($_GET["sent"]) && $error === ""): ?>
            <p class="notice ok">Message sent. We'll reply as soon as we can.</p>
        <?php endif; ?>

        <?php if ($error !== ""): ?>
            <p class="notice error" role="alert"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <form method="POST" class="compose">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <label for="body" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)">Your message</label>
            <textarea id="body" name="body" maxlength="<?= CHAT_MAX_LENGTH ?>" placeholder="Type your message…" required><?= htmlspecialchars($draft) ?></textarea>
            <button type="submit">Send</button>
        </form>

    </section>

    <div class="tools">
        <span>New replies also show when you come back to this page.</span>
        <a href="messages.php#latest">↻ Refresh</a>
    </div>

</main>

<script>
    // open at the newest message
    var thread = document.getElementById("thread");
    thread.scrollTop = thread.scrollHeight;

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

<?php require __DIR__ . "/../includes/terms-modal.php"; ?>

</body>
</html>
