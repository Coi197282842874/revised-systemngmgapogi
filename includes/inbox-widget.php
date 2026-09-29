<?php
/*
 * Floating "Customer Service" button that opens the customer's Messages in a small window.
 *
 * Include just before </body>, BEFORE terms-modal.php (the Terms button moves up to make room):
 *     <?php require __DIR__ . "/includes/inbox-widget.php"; ?>
 * Pages with a fixed bottom bar on phones set $inboxFabLift (px) first, like $termsFabLift.
 *
 * Not a live chat: nothing runs on a timer. Messages load when the window opens, when the
 * customer sends or presses refresh, and once when they come back to the tab (at most every
 * 15 seconds). The free host bans chat scripts that poll.
 * Guests see the same button; it takes them to log in first.
 */

require_once __DIR__ . "/chat.php";

$inboxRoot = str_contains(str_replace("\\", "/", $_SERVER["SCRIPT_NAME"] ?? ""), "/customer/") ? "../" : "";
$inboxFabLift = (int) ($inboxFabLift ?? 0);
$inboxCustomer = !empty($_SESSION["user_id"]) && ($_SESSION["role"] ?? "") === "customer";
$inboxAdmin = !empty($_SESSION["user_id"]) && ($_SESSION["role"] ?? "") === "admin";

if ($inboxAdmin) {
    return; // admins answer from Admin → Messages
}

$inboxUnreadCount = 0;

if ($inboxCustomer) {
    ensure_chat_schema($pdo);
    $inboxUnreadCount = chat_unread_for_customer($pdo, (int) $_SESSION["user_id"]);
}

$inboxHeadset = '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14v-2a8 8 0 0 1 16 0v2"/><rect x="2.5" y="13" width="4.5" height="7" rx="2"/><rect x="17" y="13" width="4.5" height="7" rx="2"/><path d="M19.25 20v.5a2.5 2.5 0 0 1-2.5 2.5H13"/></svg>';
?>

<?php if (!$inboxCustomer): ?>
    <a class="ib-fab" href="<?= $inboxRoot ?>login.php?redirect=<?= urlencode("customer/messages.php") ?>" aria-label="Customer service: log in to message us">
        <?= $inboxHeadset ?>
        <span class="ib-tip" aria-hidden="true">Customer Service</span>
    </a>
<?php else: ?>
    <button type="button" class="ib-fab" id="ib-fab" aria-haspopup="dialog" aria-controls="ib-panel" aria-expanded="false" aria-label="Customer service messages<?= $inboxUnreadCount ? ", " . $inboxUnreadCount . " new" : "" ?>">
        <?= $inboxHeadset ?>
        <span class="ib-tip" aria-hidden="true">Customer Service</span>
        <span class="ib-badge" id="ib-badge"<?= $inboxUnreadCount ? "" : " hidden" ?>><?= $inboxUnreadCount > 9 ? "9+" : $inboxUnreadCount ?></span>
    </button>

    <section class="ib-panel" id="ib-panel" role="dialog" aria-labelledby="ib-title" hidden>

        <header class="ib-head">
            <span class="ib-avatar" aria-hidden="true"><?= $inboxHeadset ?></span>
            <div class="ib-head-text">
                <h2 id="ib-title">Customer Service</h2>
                <p>ARVE'S House · we usually reply within the day</p>
            </div>
            <button type="button" class="ib-icon-btn" id="ib-refresh" aria-label="Check for new replies" title="Check for new replies">
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg>
            </button>
            <button type="button" class="ib-icon-btn" id="ib-close" aria-label="Close">
                <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </header>

        <div class="ib-log" id="ib-log" aria-live="polite">
            <p class="ib-empty" id="ib-empty">Loading…</p>
        </div>

        <p class="ib-note" id="ib-note" role="status" hidden></p>

        <form class="ib-form" id="ib-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <label class="ib-sr" for="ib-input">Your message</label>
            <textarea id="ib-input" name="body" rows="1" maxlength="<?= CHAT_MAX_LENGTH ?>" placeholder="Type your message…"></textarea>
            <button type="submit" class="ib-send" id="ib-send" aria-label="Send" disabled>
                <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4z"/></svg>
            </button>
        </form>

    </section>
<?php endif; ?>

<style>
    .ib-fab, .ib-panel {
        --ib-ease-out: cubic-bezier(0.23, 1, 0.32, 1);
        --ib-brown: #7a4f36;
        --ib-ink: #241a15;
        --ib-muted: #786d66;
        --ib-bottom: max(20px, calc(env(safe-area-inset-bottom, 0px) + 12px));
    }

    .ib-fab, .ib-fab *, .ib-panel, .ib-panel * { box-sizing: border-box; }

    /* ---------- button: bottom-right, the Terms button sits above it ---------- */
    .ib-fab {
        position: fixed;
        right: max(20px, env(safe-area-inset-right, 0px));
        bottom: var(--ib-bottom);
        z-index: 1160;
        display: grid;
        place-items: center;
        width: 58px;
        height: 58px;
        padding: 0;
        border: 1px solid rgba(255, 255, 255, 0.45);
        border-radius: 999px;
        background: linear-gradient(145deg, rgba(138, 94, 66, 0.94), rgba(81, 52, 33, 0.96));
        color: #fff;
        text-decoration: none;
        cursor: pointer;
        touch-action: manipulation;
        -webkit-user-select: none;
        user-select: none;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.45), inset 0 -8px 16px rgba(0, 0, 0, 0.12), 0 10px 24px rgba(90, 56, 38, 0.34);
        -webkit-backdrop-filter: blur(10px);
        backdrop-filter: blur(10px);
        animation: ib-in 420ms var(--ib-ease-out) 200ms both;
        transition: transform 160ms var(--ib-ease-out);
    }

    @keyframes ib-in { from { opacity: 0; transform: scale(0.6); } }

    .ib-fab:active { transform: scale(0.94); }
    .ib-fab:focus-visible { outline: 2px solid var(--ib-brown); outline-offset: 3px; }

    .ib-tip {
        position: absolute;
        right: calc(100% + 12px);
        top: 50%;
        padding: 7px 11px;
        border-radius: 8px;
        background: var(--ib-ink);
        color: #fff;
        font: 600 13px/1.2 Arial, Helvetica, sans-serif;
        white-space: nowrap;
        pointer-events: none;
        opacity: 0;
        transform: translate(4px, -50%);
        transition: opacity 160ms ease, transform 160ms var(--ib-ease-out);
    }

    .ib-fab:focus-visible .ib-tip { opacity: 1; transform: translate(0, -50%); }

    .ib-badge {
        position: absolute;
        top: -3px;
        right: -3px;
        min-width: 22px;
        height: 22px;
        padding: 0 6px;
        border: 2px solid #fff;
        border-radius: 999px;
        background: #dc2626;
        color: #fff;
        font: 800 11px/18px Arial, Helvetica, sans-serif;
        text-align: center;
    }

    .ib-badge[hidden] { display: none; }

    html body .tm-fab {
        bottom: calc(max(20px, calc(env(safe-area-inset-bottom, 0px) + 12px)) + 70px) !important;
    }

    <?php if ($inboxFabLift > 0): ?>
    @media (max-width: 780px) {
        .ib-fab { bottom: calc(var(--ib-bottom) + <?= $inboxFabLift ?>px); }
        html body .tm-fab { bottom: calc(max(20px, calc(env(safe-area-inset-bottom, 0px) + 12px)) + <?= $inboxFabLift + 66 ?>px) !important; }
    }
    <?php endif; ?>

    /* ---------- window ---------- */
    .ib-panel {
        position: fixed;
        right: max(20px, env(safe-area-inset-right, 0px));
        bottom: calc(var(--ib-bottom) + 72px);
        z-index: 1170;
        display: flex;
        flex-direction: column;
        width: 370px;
        height: min(540px, calc(100vh - 120px));
        height: min(540px, calc(100dvh - 120px));
        overflow: hidden;
        border: 1px solid rgba(255, 255, 255, 0.85);
        border-radius: 20px;
        background: rgba(255, 255, 255, 0.8);
        color: var(--ib-ink);
        font-family: Arial, Helvetica, sans-serif;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.9), 0 24px 60px rgba(36, 26, 21, 0.22);
        -webkit-backdrop-filter: blur(24px) saturate(170%);
        backdrop-filter: blur(24px) saturate(170%);
        transform-origin: bottom right;
        animation: ib-open 220ms var(--ib-ease-out) both;
    }

    .ib-panel[hidden] { display: none; }

    @keyframes ib-open { from { opacity: 0; transform: translateY(8px) scale(0.97); } }

    .ib-head {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 8px 12px 14px;
        background: linear-gradient(135deg, rgba(138, 94, 66, 0.96), rgba(81, 52, 33, 0.98));
        color: #fff;
    }

    .ib-avatar {
        flex: none;
        display: grid;
        place-items: center;
        width: 40px;
        height: 40px;
        border: 1px solid rgba(255, 255, 255, 0.35);
        border-radius: 12px;
        background: rgba(255, 255, 255, 0.14);
    }

    .ib-avatar svg { width: 22px; height: 22px; }
    .ib-head-text { flex: 1; min-width: 0; }
    .ib-head h2 { margin: 0; font: 700 16px/1.2 Georgia, "Times New Roman", serif; color: #fff; }
    .ib-head p { margin: 2px 0 0; overflow: hidden; font-size: 11.5px; opacity: 0.82; text-overflow: ellipsis; white-space: nowrap; }

    .ib-icon-btn {
        flex: none;
        display: grid;
        place-items: center;
        width: 38px;
        height: 38px;
        padding: 0;
        border: 0;
        border-radius: 999px;
        background: transparent;
        color: #fff;
        cursor: pointer;
        touch-action: manipulation;
        transition: background-color 160ms ease, transform 140ms var(--ib-ease-out);
    }

    .ib-icon-btn:active { transform: scale(0.92); }
    .ib-icon-btn:focus-visible { outline: 2px solid #fff; outline-offset: 1px; }
    .ib-icon-btn.spin svg { animation: ib-spin 700ms linear infinite; }
    @keyframes ib-spin { to { transform: rotate(360deg); } }

    .ib-log {
        flex: 1;
        min-height: 0;
        overflow-y: auto;
        overscroll-behavior: contain;
        display: flex;
        flex-direction: column;
        gap: 6px;
        padding: 16px 14px;
    }

    .ib-empty { margin: auto 10px; text-align: center; font-size: 14px; line-height: 1.55; color: var(--ib-muted); }
    .ib-empty strong { display: block; margin-bottom: 4px; font-size: 16px; color: var(--ib-ink); }

    .ib-day { align-self: center; margin: 8px 0 2px; font-size: 11px; color: var(--ib-muted); }

    .ib-msg { max-width: 80%; padding: 9px 12px; border-radius: 16px; font-size: 14px; line-height: 1.45; white-space: pre-wrap; overflow-wrap: anywhere; }
    .ib-msg time { display: block; margin-top: 3px; font-size: 10.5px; opacity: 0.7; }
    .ib-msg.me { align-self: flex-end; border-bottom-right-radius: 5px; background: linear-gradient(145deg, #8a5e42, #5b3a26); color: #fff; }
    .ib-msg.them { align-self: flex-start; border-bottom-left-radius: 5px; border: 1px solid rgba(255, 255, 255, 0.9); background: rgba(255, 255, 255, 0.88); box-shadow: 0 2px 6px rgba(36, 26, 21, 0.06); }
    .ib-msg.them b { display: block; margin-bottom: 1px; font-size: 11px; color: var(--ib-brown); }
    .ib-msg.pending { opacity: 0.6; }

    .ib-note { margin: 0 14px 8px; padding: 8px 12px; border-radius: 10px; font-size: 12.5px; line-height: 1.4; background: #f4ece3; color: #6b4a2b; }
    .ib-note.error { background: #fdecec; color: #9f1d1d; }
    .ib-note[hidden] { display: none; }

    .ib-form { display: flex; align-items: flex-end; gap: 8px; padding: 10px 12px 12px; border-top: 1px solid rgba(36, 26, 21, 0.08); background: rgba(255, 255, 255, 0.55); }
    .ib-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }

    .ib-form textarea {
        flex: 1;
        min-height: 42px;
        max-height: 120px;
        padding: 11px 14px;
        border: 0;
        border-radius: 21px;
        background: #fff;
        box-shadow: inset 0 0 0 1px rgba(36, 26, 21, 0.12);
        font: inherit;
        font-size: 14px;
        line-height: 1.4;
        color: var(--ib-ink);
        resize: none;
        outline: none;
    }

    .ib-form textarea:focus { box-shadow: inset 0 0 0 1px var(--ib-brown), 0 0 0 3px rgba(122, 79, 54, 0.14); }

    .ib-send {
        flex: none;
        display: grid;
        place-items: center;
        width: 42px;
        height: 42px;
        padding: 0;
        border: 0;
        border-radius: 999px;
        background: linear-gradient(145deg, #8a5e42, #5b3a26);
        color: #fff;
        cursor: pointer;
        touch-action: manipulation;
        transition: opacity 160ms ease, transform 140ms var(--ib-ease-out);
    }

    .ib-send:disabled { opacity: 0.4; cursor: default; }
    .ib-send:active:not(:disabled) { transform: scale(0.92); }
    .ib-send:focus-visible { outline: 2px solid var(--ib-brown); outline-offset: 2px; }

    @media (hover: hover) and (pointer: fine) {
        .ib-fab:hover .ib-tip { opacity: 1; transform: translate(0, -50%); }
        .ib-icon-btn:hover { background: rgba(255, 255, 255, 0.14); }
    }

    @media (pointer: coarse) { .ib-form textarea { font-size: 16px; } }

    /* phones: a sheet from the bottom */
    @media (max-width: 560px) {
        .ib-fab { width: 54px; height: 54px; right: max(16px, env(safe-area-inset-right, 0px)); }

        .ib-panel {
            right: 0;
            left: 0;
            bottom: 0;
            width: 100%;
            height: calc(100vh - 40px);
            height: calc(100dvh - 40px);
            border-radius: 20px 20px 0 0;
            transform-origin: bottom center;
            animation-name: ib-sheet;
            animation-duration: 300ms;
        }

        .ib-form { padding-bottom: calc(12px + env(safe-area-inset-bottom, 0px)); }
    }

    @keyframes ib-sheet { from { transform: translateY(100%); } }

    @media (prefers-reduced-motion: reduce) {
        .ib-fab, .ib-panel { animation: none; }
        .ib-fab:active, .ib-send:active, .ib-icon-btn:active { transform: none; }
        .ib-icon-btn.spin svg { animation-duration: 2s; }
    }

    @media print { .ib-fab, .ib-panel { display: none !important; } }
</style>

<?php if ($inboxCustomer): ?>
<script>
(function () {
    var API = <?= json_encode($inboxRoot . "messages-api.php") ?>;
    var firstName = <?= json_encode(explode(" ", trim($_SESSION["full_name"] ?? ""))[0] ?: "there", JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    var fab = document.getElementById("ib-fab");
    var panel = document.getElementById("ib-panel");
    var log = document.getElementById("ib-log");
    var badge = document.getElementById("ib-badge");
    var note = document.getElementById("ib-note");
    var form = document.getElementById("ib-form");
    var input = document.getElementById("ib-input");
    var send = document.getElementById("ib-send");
    var refresh = document.getElementById("ib-refresh");
    var token = form.querySelector('input[name="csrf_token"]').value;
    var finePointer = window.matchMedia("(pointer: fine)");
    var isOpen = false, busy = false, loading = false, lastDay = "";

    function dayLabel(d) {
        var today = new Date(), yesterday = new Date(Date.now() - 86400000);
        if (d.toDateString() === today.toDateString()) return "Today";
        if (d.toDateString() === yesterday.toDateString()) return "Yesterday";
        return d.toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" });
    }

    function showNote(text, isError) {
        note.textContent = text || "";
        note.className = "ib-note" + (isError ? " error" : "");
        note.hidden = !text;
    }

    function renderEmpty() {
        log.textContent = "";
        var p = document.createElement("div");
        p.className = "ib-empty";
        var s = document.createElement("strong");
        s.textContent = "Hi " + firstName + "!";
        p.appendChild(s);
        p.appendChild(document.createTextNode("Ask us anything about rooms, your booking or payment. We'll reply here."));
        log.appendChild(p);
    }

    function addMessage(m, pending) {
        var empty = log.querySelector(".ib-empty");
        if (empty) empty.remove();

        var d = new Date(m.at), day = dayLabel(d);
        if (day !== lastDay) {
            var sep = document.createElement("div");
            sep.className = "ib-day";
            sep.textContent = day;
            log.appendChild(sep);
            lastDay = day;
        }

        var el = document.createElement("div");
        el.className = "ib-msg " + (m.sender === "customer" ? "me" : "them") + (pending ? " pending" : "");
        if (m.sender !== "customer") {
            var who = document.createElement("b");
            who.textContent = "ARVE'S House";
            el.appendChild(who);
        }
        el.appendChild(document.createTextNode(m.body));
        var t = document.createElement("time");
        t.dateTime = m.at;
        t.textContent = pending ? "Sending…" : d.toLocaleTimeString(undefined, { hour: "numeric", minute: "2-digit" });
        el.appendChild(t);
        log.appendChild(el);
        return el;
    }

    var lastCheck = Date.now();
    var RECHECK_GAP = 15000; // at most one automatic check per 15 seconds

    function setBadge(n) {
        badge.textContent = n > 9 ? "9+" : String(n);
        badge.hidden = n <= 0 || isOpen;
    }

    // one request, only when asked: opening the window, pressing refresh, or coming back to the tab
    function load(manual) {
        if (loading) return;
        loading = true;
        lastCheck = Date.now();
        refresh.classList.add("spin");

        fetch(API + "?action=list", { credentials: "same-origin", headers: { "Accept": "application/json" } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) throw new Error(data.error || "error");
                log.textContent = "";
                lastDay = "";
                if (!data.messages.length) renderEmpty();
                data.messages.forEach(function (m) { addMessage(m, false); });
                log.scrollTop = log.scrollHeight;
                badge.hidden = true;
                showNote(manual ? "Up to date. New replies also show when you come back to this page." : "", false);
            })
            .catch(function () {
                showNote("Couldn't load your messages. Check your connection and tap ↻.", true);
            })
            .then(function () {
                loading = false;
                refresh.classList.remove("spin");
            });
    }

    function open() {
        isOpen = true;
        panel.hidden = false;
        fab.setAttribute("aria-expanded", "true");
        showNote("", false);
        load(false);
        if (finePointer.matches) input.focus();
    }

    function close() {
        isOpen = false;
        panel.hidden = true;
        fab.setAttribute("aria-expanded", "false");
        fab.focus({ preventScroll: true });
    }

    function checkUnread() {
        lastCheck = Date.now();
        fetch(API + "?action=unread", { credentials: "same-origin", headers: { "Accept": "application/json" } })
            .then(function (r) { return r.json(); })
            .then(function (data) { if (data.ok) setBadge(data.unread); })
            .catch(function () {});
    }

    // Coming back to the page (another tab, another app, the phone unlocked): check once.
    // No timer: nothing is requested while the customer isn't looking.
    function onReturn() {
        if (document.hidden || Date.now() - lastCheck < RECHECK_GAP) return;
        if (isOpen) {
            if (!busy) load(false); // the typed draft is untouched
        } else {
            checkUnread();
        }
    }

    document.addEventListener("visibilitychange", onReturn);
    window.addEventListener("focus", onReturn);

    fab.addEventListener("click", function () { isOpen ? close() : open(); });
    document.getElementById("ib-close").addEventListener("click", close);
    refresh.addEventListener("click", function () { load(true); });

    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape" && isOpen && !document.querySelector("dialog[open]")) close();
    });

    function grow() {
        input.style.height = "auto";
        input.style.height = Math.min(input.scrollHeight, 120) + "px";
        send.disabled = !input.value.trim() || busy;
    }

    input.addEventListener("input", grow);
    input.addEventListener("keydown", function (e) {
        // Enter sends on a keyboard; on phones Enter is a new line and the button sends
        if (e.key === "Enter" && !e.shiftKey && finePointer.matches) {
            e.preventDefault();
            form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event("submit", { cancelable: true }));
        }
    });

    form.addEventListener("submit", function (e) {
        e.preventDefault();
        var body = input.value.trim();
        if (!body || busy) return;

        busy = true;
        showNote("", false);
        var pendingEl = addMessage({ sender: "customer", body: body, at: new Date().toISOString() }, true);
        log.scrollTop = log.scrollHeight;
        input.value = "";
        grow();

        var data = new FormData();
        data.append("csrf_token", token);
        data.append("body", body);

        fetch(API + "?action=send", { method: "POST", body: data, credentials: "same-origin" })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                pendingEl.remove();
                if (!res.ok) {
                    input.value = body; grow();
                    showNote(res.error || "Message not sent. Please try again.", true);
                    return;
                }
                addMessage(res.message, false);
                log.scrollTop = log.scrollHeight;
                showNote("Sent! We'll reply here. Replies show when you open this window or come back to this page.", false);
            })
            .catch(function () {
                pendingEl.remove();
                input.value = body; grow();
                showNote("Message not sent. Check your connection and try again.", true);
            })
            .then(function () { busy = false; grow(); });
    });
})();
</script>
<?php endif; ?>
