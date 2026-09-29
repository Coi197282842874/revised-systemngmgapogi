<?php
/*
 * Floating customer login.
 *
 * Include just before </body> on guest-facing root pages (index.php, indexagoda.php, rooms.php).
 * Any link to login.php (not admin/login.php) opens this dialog instead of navigating;
 * a link's ?redirect= value is carried through, so "Login to Book" still returns to the room.
 *
 * Progressive enhancement: without JavaScript or <dialog> support the links keep going to
 * login.php, and if the fetch fails the form falls back to a normal POST to login.php.
 * login.php answers requests sent with "X-Requested-With: fetch" as JSON.
 *
 * Open directly with a #login URL hash.
 */

if (!empty($_SESSION["user_id"])) {
    return;
}
?>

<dialog class="lm" id="login-modal" aria-labelledby="lm-title">

    <div class="lm-panel">

        <button type="button" class="lm-close" data-lm-close aria-label="Close login">
            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>

        <div class="lm-head">
            <span class="lm-brand">ARVE'S <span>House</span></span>
            <h2 id="lm-title" tabindex="-1">Welcome back</h2>
            <p>Log in to book and manage your stays.</p>
        </div>

        <p class="lm-note" data-lm-booking hidden>
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg>
            <span>Log in with your customer account to continue your booking.</span>
        </p>

        <div class="lm-error" role="alert" data-lm-error hidden></div>

        <?php
            // href is updated with the booking redirect when the dialog opens
            $googleRedirect = "";
            require __DIR__ . "/google-button.php";
        ?>

        <form class="lm-form" method="POST" action="login.php">

            <input type="hidden" name="redirect" value="">

            <div class="lm-field">
                <label for="lm-email">Email address</label>
                <input
                    type="email"
                    id="lm-email"
                    name="email"
                    placeholder="you@example.com"
                    autocomplete="email"
                    autocapitalize="none"
                    spellcheck="false"
                    enterkeyhint="next"
                    required
                >
            </div>

            <div class="lm-field">
                <label for="lm-password">Password</label>
                <div class="lm-password">
                    <input
                        type="password"
                        id="lm-password"
                        name="password"
                        placeholder="Your password"
                        autocomplete="current-password"
                        enterkeyhint="go"
                        required
                    >
                    <button type="button" class="lm-toggle" data-lm-toggle aria-controls="lm-password" aria-pressed="false">Show</button>
                </div>
            </div>

            <button type="submit" class="lm-submit">
                <span class="lm-spinner" aria-hidden="true"></span>
                <span class="lm-submit-label">Log in</span>
            </button>

        </form>

        <p class="lm-foot">
            Don't have an account? <a href="register.php">Create one</a>
        </p>

    </div>

</dialog>

<style>

    .lm {
        --lm-ease-out: cubic-bezier(0.23, 1, 0.32, 1);
        --lm-ease-drawer: cubic-bezier(0.32, 0.72, 0, 1);
        --lm-brown: #6f4e37;
        --lm-brown-dark: #563a29;
        --lm-ink: #241a15;
        --lm-muted: #6b5f58;
        --lm-gold: #b7813f;
        --lm-line: rgba(36, 26, 21, 0.14);

        width: min(420px, calc(100vw - 32px));
        max-width: none;
        max-height: calc(100vh - 32px);
        max-height: calc(100dvh - 32px);
        margin: auto;
        padding: 0;
        border: 0;
        border-radius: 20px;
        background: #ffffff;
        color: var(--lm-ink);
        overflow: auto;
        overscroll-behavior: contain;
        box-shadow:
            0 1px 2px rgba(36, 26, 21, 0.06),
            0 12px 32px rgba(36, 26, 21, 0.14),
            0 32px 64px rgba(36, 26, 21, 0.12);

        /* exit: quicker than the entrance */
        opacity: 0;
        transform: scale(0.96);
        transition:
            opacity 160ms ease,
            transform 160ms var(--lm-ease-out);
        transition:
            opacity 160ms ease,
            transform 160ms var(--lm-ease-out),
            overlay 160ms allow-discrete,
            display 160ms allow-discrete;
    }

    .lm[open] {
        opacity: 1;
        transform: none;
        transition-duration: 240ms;
    }

    @starting-style {
        .lm[open] {
            opacity: 0;
            transform: scale(0.96);
        }
    }

    .lm::backdrop {
        background: rgba(36, 26, 21, 0);
        transition:
            background-color 200ms ease,
            overlay 200ms allow-discrete,
            display 200ms allow-discrete;
    }

    .lm[open]::backdrop {
        background: rgba(36, 26, 21, 0.45);
    }

    @starting-style {
        .lm[open]::backdrop {
            background: rgba(36, 26, 21, 0);
        }
    }

    /* self-contained: don't depend on the host page's box-sizing reset */
    .lm,
    .lm *,
    .lm *::before,
    .lm *::after {
        box-sizing: border-box;
    }

    .lm-panel {
        position: relative;
        padding: 32px 32px 28px;
    }

    .lm-close {
        position: absolute;
        top: 14px;
        right: 14px;
        display: grid;
        place-items: center;
        width: 40px;
        height: 40px;
        padding: 0;
        border: 0;
        border-radius: 999px;
        background: transparent;
        color: var(--lm-muted);
        cursor: pointer;
        touch-action: manipulation;
        transition:
            background-color 180ms ease,
            color 180ms ease,
            transform 140ms var(--lm-ease-out);
    }

    .lm-close:active {
        transform: scale(0.94);
    }

    .lm-close:focus-visible {
        outline: 2px solid var(--lm-brown);
        outline-offset: 2px;
    }

    .lm-head {
        margin-bottom: 22px;
        padding-right: 36px;
    }

    .lm-brand {
        display: block;
        margin-bottom: 14px;
        font-size: 13px;
        font-weight: 700;
        letter-spacing: 0.04em;
        color: var(--lm-brown);
    }

    .lm-brand span {
        color: var(--lm-gold);
    }

    .lm-head h2 {
        margin: 0 0 6px;
        font-family: var(--font-display, Georgia, "Times New Roman", serif);
        font-size: 28px;
        font-weight: 600;
        line-height: 1.15;
        color: var(--lm-ink);
        outline: none;
    }

    .lm-head p {
        margin: 0;
        font-size: 15px;
        line-height: 1.5;
        color: var(--lm-muted);
    }

    .lm-note,
    .lm-error {
        margin: 0 0 18px;
        padding: 12px 14px;
        border-radius: 12px;
        font-size: 14px;
        line-height: 1.45;
    }

    .lm-note {
        display: flex;
        gap: 10px;
        align-items: flex-start;
        background: #fbf4ea;
        color: #6b4a2b;
        box-shadow: inset 0 0 0 1px rgba(183, 129, 63, 0.28);
    }

    .lm-note[hidden],
    .lm-error[hidden] {
        display: none;
    }

    .lm-note svg {
        flex: none;
        margin-top: 1px;
    }

    .lm-error {
        background: #fdecec;
        color: #9f1d1d;
        box-shadow: inset 0 0 0 1px rgba(159, 29, 29, 0.2);
        animation: lm-alert-in 240ms var(--lm-ease-out) both;
    }

    @keyframes lm-alert-in {
        from { opacity: 0; transform: translateY(-4px); }
        to   { opacity: 1; transform: none; }
    }

    .lm-field {
        margin-bottom: 16px;
    }

    .lm-field label {
        display: block;
        margin-bottom: 7px;
        font-size: 14px;
        font-weight: 600;
        color: var(--lm-ink);
    }

    .lm-field input {
        width: 100%;
        height: 48px;
        padding: 0 14px;
        border: 0;
        border-radius: 12px;
        background: #ffffff;
        box-shadow: inset 0 0 0 1px var(--lm-line);
        font: inherit;
        font-size: 15px;
        color: var(--lm-ink);
        outline: none;
        transition: box-shadow 180ms ease;
    }

    .lm-field input::placeholder {
        color: #9a8e86;
    }

    .lm-field input:focus {
        box-shadow:
            inset 0 0 0 1px var(--lm-brown),
            0 0 0 4px rgba(111, 78, 55, 0.14);
    }

    .lm-password {
        position: relative;
    }

    .lm-password input {
        padding-right: 72px;
    }

    .lm-toggle {
        position: absolute;
        top: 50%;
        right: 6px;
        height: 36px;
        padding: 0 12px;
        border: 0;
        border-radius: 8px;
        background: transparent;
        font: inherit;
        font-size: 13px;
        font-weight: 600;
        color: var(--lm-brown);
        cursor: pointer;
        touch-action: manipulation;
        -webkit-user-select: none;
        user-select: none;
        transform: translateY(-50%);
        transition: background-color 180ms ease;
    }

    .lm-toggle:focus-visible {
        outline: 2px solid var(--lm-brown);
        outline-offset: 1px;
    }

    .lm-submit {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        width: 100%;
        height: 50px;
        margin-top: 6px;
        border: 0;
        border-radius: 12px;
        background: var(--lm-brown);
        font: inherit;
        font-size: 16px;
        font-weight: 700;
        color: #ffffff;
        cursor: pointer;
        touch-action: manipulation;
        -webkit-user-select: none;
        user-select: none;
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.12),
            0 1px 2px rgba(36, 26, 21, 0.12),
            0 6px 16px rgba(111, 78, 55, 0.22);
        transition:
            background-color 180ms ease,
            transform 140ms var(--lm-ease-out);
    }

    .lm-submit:active:not(:disabled) {
        background: var(--lm-brown-dark);
        transform: scale(0.97);
    }

    .lm-submit:focus-visible {
        outline: 2px solid var(--lm-brown);
        outline-offset: 3px;
    }

    .lm-submit:disabled {
        cursor: progress;
    }

    .lm-spinner {
        display: none;
        width: 16px;
        height: 16px;
        border: 2px solid rgba(255, 255, 255, 0.35);
        border-top-color: #ffffff;
        border-radius: 50%;
        animation: lm-spin 600ms linear infinite;
    }

    .lm-submit[aria-busy="true"] .lm-spinner {
        display: block;
    }

    @keyframes lm-spin {
        to { transform: rotate(360deg); }
    }

    .lm-foot {
        margin: 20px 0 0;
        font-size: 14px;
        text-align: center;
        color: var(--lm-muted);
    }

    .lm-foot a {
        font-weight: 700;
        color: var(--lm-brown);
        text-decoration: none;
    }

    .lm-foot a:focus-visible {
        outline: 2px solid var(--lm-brown);
        outline-offset: 2px;
        border-radius: 4px;
    }

    @media (hover: hover) and (pointer: fine) {
        .lm-close:hover { background: rgba(36, 26, 21, 0.06); color: var(--lm-ink); }
        .lm-toggle:hover { background: rgba(111, 78, 55, 0.08); }
        .lm-submit:hover:not(:disabled) { background: var(--lm-brown-dark); }
        .lm-foot a:hover { text-decoration: underline; }
    }

    /* stop iOS zooming into the inputs */
    @media (pointer: coarse) {
        .lm-field input { font-size: 16px; }
    }

    /* phones: a bottom sheet that slides up, like a native login sheet */
    @media (max-width: 560px) {
        .lm {
            width: 100%;
            max-height: calc(100vh - 24px);
            max-height: calc(100dvh - 24px);
            margin: auto 0 0;
            border-radius: 20px 20px 0 0;
            transform: translateY(100%);
            transition-timing-function: ease, var(--lm-ease-drawer);
        }

        .lm[open] {
            transform: none;
            transition-duration: 300ms;
        }

        @starting-style {
            .lm[open] {
                transform: translateY(100%);
            }
        }

        .lm-panel {
            padding: 28px 20px calc(24px + env(safe-area-inset-bottom, 0px));
        }

        .lm-head h2 {
            font-size: 25px;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .lm,
        .lm[open],
        .lm-submit:active,
        .lm-close:active,
        .lm-error {
            transform: none !important;
        }

        @starting-style {
            .lm[open] { transform: none !important; }
        }

        .lm-spinner {
            animation-duration: 1500ms;
        }
    }

</style>

<script>
(function () {
    var dialog = document.getElementById("login-modal");

    if (!dialog || typeof dialog.showModal !== "function") {
        return; // no <dialog> support: login links keep navigating to login.php
    }

    var form = dialog.querySelector(".lm-form");
    var redirectInput = form.querySelector('input[name="redirect"]');
    var email = form.querySelector("#lm-email");
    var password = form.querySelector("#lm-password");
    var submit = form.querySelector(".lm-submit");
    var submitLabel = submit.querySelector(".lm-submit-label");
    var toggle = form.querySelector("[data-lm-toggle]");
    var errorBox = dialog.querySelector("[data-lm-error]");
    var bookingNote = dialog.querySelector("[data-lm-booking]");
    var title = dialog.querySelector("#lm-title");
    var panel = dialog.querySelector(".lm-panel");
    var reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)");
    var finePointer = window.matchMedia("(pointer: fine)");
    var busy = false;

    function loginUrl(link) {
        var url;

        try {
            url = new URL(link.href, window.location.href);
        } catch (e) {
            return null;
        }

        if (
            url.origin !== window.location.origin
            || !/\/login\.php$/.test(url.pathname)
            || /\/admin\/login\.php$/.test(url.pathname)
        ) {
            return null;
        }

        return url;
    }

    function showError(message) {
        errorBox.textContent = message;
        errorBox.hidden = false;
        // restart the entrance so a repeated error still reads as new
        errorBox.style.animation = "none";
        void errorBox.offsetWidth;
        errorBox.style.animation = "";
    }

    function clearError() {
        errorBox.hidden = true;
        errorBox.textContent = "";
    }

    function setBusy(value) {
        busy = value;
        submit.disabled = value;
        submit.setAttribute("aria-busy", value ? "true" : "false");
        submitLabel.textContent = value ? "Logging in…" : "Log in";
    }

    function shake() {
        if (reduceMotion.matches || !panel.animate) {
            return;
        }

        panel.animate(
            [
                { transform: "translateX(0)" },
                { transform: "translateX(-6px)" },
                { transform: "translateX(5px)" },
                { transform: "translateX(-3px)" },
                { transform: "translateX(2px)" },
                { transform: "translateX(0)" }
            ],
            { duration: 320, easing: "ease-out" }
        );
    }

    // Lock page scroll behind the dialog. Pad the body by the scrollbar's width so a classic
    // (Windows) scrollbar disappearing doesn't shift the page sideways; the navs are sticky,
    // so they follow the body padding.
    var savedOverflow = "";
    var savedPaddingRight = "";

    function lockScroll() {
        var scrollbar = window.innerWidth - document.documentElement.clientWidth;
        savedOverflow = document.documentElement.style.overflow;
        savedPaddingRight = document.body.style.paddingRight;
        document.documentElement.style.overflow = "hidden";

        if (scrollbar > 0) {
            var current = parseFloat(window.getComputedStyle(document.body).paddingRight) || 0;
            document.body.style.paddingRight = (current + scrollbar) + "px";
        }
    }

    function unlockScroll() {
        document.documentElement.style.overflow = savedOverflow;
        document.body.style.paddingRight = savedPaddingRight;
    }

    var googleLink = dialog.querySelector("[data-google-login]");

    function open(redirect) {
        redirectInput.value = redirect || "";
        bookingNote.hidden = !redirect;
        clearError();

        if (googleLink) {
            googleLink.href = "google-login.php" + (redirect ? "?redirect=" + encodeURIComponent(redirect) : "");
        }

        if (!dialog.open) {
            lockScroll();
            dialog.showModal();
        }

        // mouse/trackpad: straight into the email field.
        // touch: don't throw the keyboard over the sheet before the user has seen it.
        if (finePointer.matches) {
            (email.value ? password : email).focus();
        } else {
            title.focus();
        }
    }

    function close() {
        dialog.close();
    }

    document.addEventListener("click", function (event) {
        if (
            event.defaultPrevented
            || event.button !== 0
            || event.metaKey
            || event.ctrlKey
            || event.shiftKey
            || event.altKey
        ) {
            return; // let new-tab / new-window clicks through
        }

        var link = event.target.closest ? event.target.closest("a[href]") : null;

        if (!link || (link.target && link.target !== "_self")) {
            return;
        }

        var url = loginUrl(link);

        if (!url) {
            return;
        }

        event.preventDefault();
        open(url.searchParams.get("redirect") || "");
    });

    dialog.querySelectorAll("[data-lm-close]").forEach(function (button) {
        button.addEventListener("click", close);
    });

    // click on the dimmed backdrop closes; a drag that starts inside the card and
    // ends outside (e.g. selecting text in an input) does not
    var pressStartedOnBackdrop = false;

    dialog.addEventListener("pointerdown", function (event) {
        pressStartedOnBackdrop = event.target === dialog;
    });

    dialog.addEventListener("click", function (event) {
        if (event.target === dialog && pressStartedOnBackdrop) {
            close();
        }

        pressStartedOnBackdrop = false;
    });

    dialog.addEventListener("close", function () {
        unlockScroll();

        if (window.location.hash === "#login" && window.history.replaceState) {
            window.history.replaceState(null, "", window.location.pathname + window.location.search);
        }
    });

    toggle.addEventListener("click", function () {
        var show = password.type === "password";
        password.type = show ? "text" : "password";
        toggle.textContent = show ? "Hide" : "Show";
        toggle.setAttribute("aria-pressed", show ? "true" : "false");
        password.focus();
    });

    form.addEventListener("submit", function (event) {
        if (!window.fetch || !window.FormData) {
            return; // plain POST to login.php
        }

        event.preventDefault();

        if (busy) {
            return;
        }

        clearError();
        setBusy(true);

        fetch(form.action, {
            method: "POST",
            body: new FormData(form),
            credentials: "same-origin",
            headers: {
                "X-Requested-With": "fetch",
                "Accept": "application/json"
            }
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                var target = data && typeof data.redirect === "string" ? data.redirect : "";

                if (
                    data
                    && data.ok
                    && target
                    && !/^[a-z][a-z0-9+.\-]*:/i.test(target)
                    && !/^[\/\\]/.test(target)
                ) {
                    // stay in the busy state while the next page loads
                    window.location.href = new URL(target, form.action).href;
                    return;
                }

                setBusy(false);
                showError((data && data.error) || "Something went wrong. Please try again.");
                shake();
                password.select();
            })
            .catch(function () {
                // unexpected response: fall back to the full login page, which shows its own errors
                form.submit();
            });
    });

    if (window.location.hash === "#login") {
        open("");
    }
})();
</script>
