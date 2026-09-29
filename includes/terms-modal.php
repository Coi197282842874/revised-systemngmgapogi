<?php
/*
 * Floating Terms and Conditions.
 *
 * Include just before </body> on guest and customer pages. Adds a round button in the
 * bottom-right corner that opens the terms in a dialog styled like the login modal
 * (a centered card on desktop, a bottom sheet on phones).
 *
 * Any link to #terms (or any element with data-terms-open) also opens it, so a form can say
 * "I agree to the <a href="#terms">Terms and Conditions</a>". A #terms URL hash opens it on load.
 *
 * Pages with their own fixed bottom bar can set $termsFabLift (px) before including, to lift
 * the button above that bar on phones.
 */

$termsFabLift = (int) ($termsFabLift ?? 0);

$termsSections = [
    ["Reservation and Booking", [
        "All reservations are subject to room availability. Guests must provide complete and accurate information when making a booking.",
        "A reservation is considered confirmed only after the required booking process and payment requirements have been completed.",
    ]],
    ["Check-In and Check-Out", [
        "Standard check-in and check-out times must be followed.",
        "Guests who wish to check in earlier or check out later should contact the management in advance. Early check-in and late check-out are subject to availability and may require additional charges.",
    ]],
    ["Valid Identification", [
        "Guests may be required to present a valid government-issued ID upon check-in for identification and security purposes.",
    ]],
    ["Payment", [
        "Guests must pay the agreed accommodation fee according to the payment terms stated during the reservation.",
        "Any remaining balance must be settled before or upon check-in unless otherwise agreed by the management.",
    ]],
    ["Cancellation and Refund", [
        "Guests who wish to cancel their reservation must notify the management as early as possible.",
        "Cancellation and refund eligibility may depend on how many days before the scheduled check-in date the cancellation is made.",
        "Booking or reservation fees may be non-refundable unless otherwise approved by the management.",
    ]],
    ["Number of Guests", [
        "Only the number of guests declared during the reservation is allowed to stay in the accommodation.",
        "Additional guests must be reported to the management and may be subject to additional charges.",
    ]],
    ["Visitors", [
        "Visitors may only be allowed during permitted hours and with the approval of the management.",
        "Unregistered visitors are not allowed to stay overnight unless they are properly registered and any applicable additional fee has been paid.",
    ]],
    ["Cleanliness", [
        "Guests are expected to maintain the cleanliness of the room and common areas during their stay.",
        "Excessive mess, stains, or improper disposal of garbage may result in an additional cleaning fee.",
    ]],
    ["Damage to Property", [
        "Guests are responsible for any loss, breakage, or damage to furniture, appliances, equipment, or other property caused during their stay.",
        "The guest may be required to pay the cost of repair or replacement.",
    ]],
    ["Smoking", [
        "Smoking is prohibited inside rooms and other designated non-smoking areas.",
        "Guests may only smoke in designated smoking areas, if available.",
    ]],
    ["Alcohol and Prohibited Substances", [
        "Alcohol consumption must be responsible and must not disturb other guests.",
        "Illegal drugs and other prohibited substances are strictly prohibited within the property.",
    ]],
    ["Noise and Disturbance", [
        "Guests must respect other occupants and nearby residents.",
        "Excessive noise, loud music, shouting, or disruptive behavior is prohibited, especially during designated quiet hours.",
    ]],
    ["Illegal Activities", [
        "Any illegal activity within the property is strictly prohibited.",
        "Management reserves the right to ask guests involved in illegal, dangerous, or disruptive activities to leave the premises.",
    ]],
    ["Personal Belongings", [
        "Guests are responsible for securing their personal belongings.",
        "Management is not responsible for lost, stolen, or damaged personal items unless the loss is directly caused by the negligence of the management or staff.",
    ]],
    ["Safety and Security", [
        "Guests must follow all safety and security rules of the property.",
        "Guests should properly lock doors and windows when leaving the room and immediately report any security concern, accident, or emergency to the management.",
    ]],
    ["Pets", [
        "Pets are only allowed with prior approval from the management.",
        "Additional rules or charges may apply for guests bringing pets.",
    ]],
    ["Children and Minors", [
        "Children must be supervised by a responsible adult at all times.",
        "Parents or guardians are responsible for the safety and behavior of minors staying at the property.",
    ]],
    ["Use of Facilities", [
        "Guests must use rooms, appliances, furniture, amenities, and facilities responsibly and only for their intended purposes.",
        "Guests may be charged for damage caused by improper use.",
    ]],
    ["Force Majeure", [
        "Management shall not be held responsible for interruptions or cancellations caused by circumstances beyond its reasonable control, including natural disasters, power interruptions, severe weather, government restrictions, or other emergencies.",
    ]],
    ["Privacy", [
        "Guest information collected during registration and reservation will be used only for legitimate booking, communication, security, and accommodation purposes.",
        "Personal information will be handled in accordance with applicable privacy laws and policies.",
    ]],
    ["Right to Refuse or Terminate Accommodation", [
        "Management reserves the right to refuse accommodation or terminate a guest's stay when the guest violates these terms and conditions, causes serious disturbance, damages property, threatens the safety of others, or participates in illegal activities.",
        "Where appropriate, no refund may be provided for the unused portion of the stay.",
    ]],
    ["Agreement", [
        "By submitting a reservation, making payment, checking in, or staying at the property, the guest confirms that they have read, understood, and agreed to these Terms and Conditions.",
    ]],
];
?>

<button type="button" class="tm-fab" data-terms-open aria-haspopup="dialog" aria-controls="terms-modal" aria-label="Terms and Conditions">
    <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/></svg>
    <span class="tm-fab-tip" aria-hidden="true">Terms &amp; Conditions</span>
</button>

<dialog class="tm" id="terms-modal" aria-labelledby="tm-title">

    <div class="tm-head">
        <span class="tm-brand">ARVE'S <span>House</span></span>
        <h2 id="tm-title" tabindex="-1">Terms and Conditions</h2>

        <button type="button" class="tm-close" data-tm-close aria-label="Close terms and conditions">
            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
    </div>

    <div class="tm-body">

        <p class="tm-intro">By making a reservation and staying at the transient house, guests agree to comply with the following terms and conditions:</p>

        <ol class="tm-list">
            <?php foreach ($termsSections as [$heading, $paragraphs]): ?>
                <li>
                    <h3><?= htmlspecialchars($heading) ?></h3>
                    <?php foreach ($paragraphs as $paragraph): ?>
                        <p><?= htmlspecialchars($paragraph) ?></p>
                    <?php endforeach; ?>
                </li>
            <?php endforeach; ?>
        </ol>

        <div class="tm-agreement">
            <strong>Guest Agreement</strong>
            <p>I have read and understood the Terms and Conditions and agree to follow the rules and policies of the transient house.</p>
        </div>

    </div>

    <div class="tm-foot">
        <button type="button" class="tm-done" data-tm-close>I understand</button>
    </div>

</dialog>

<style>

    .tm-fab,
    .tm {
        --tm-ease-out: cubic-bezier(0.23, 1, 0.32, 1);
        --tm-ease-drawer: cubic-bezier(0.32, 0.72, 0, 1);
        --tm-brown: #6f4e37;
        --tm-brown-dark: #563a29;
        --tm-ink: #241a15;
        --tm-muted: #6b5f58;
        --tm-gold: #b7813f;
        --tm-line: rgba(36, 26, 21, 0.1);
    }

    .tm,
    .tm *,
    .tm *::before,
    .tm *::after,
    .tm-fab,
    .tm-fab * {
        box-sizing: border-box;
    }

    /* ---------- floating button ---------- */

    .tm-fab {
        position: fixed;
        right: max(20px, env(safe-area-inset-right, 0px));
        bottom: max(20px, calc(env(safe-area-inset-bottom, 0px) + 12px));
        z-index: 1150;
        display: grid;
        place-items: center;
        width: 56px;
        height: 56px;
        padding: 0;
        border: 0;
        border-radius: 999px;
        background: var(--tm-brown);
        color: #ffffff;
        cursor: pointer;
        touch-action: manipulation;
        -webkit-user-select: none;
        user-select: none;
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.14),
            0 2px 4px rgba(36, 26, 21, 0.14),
            0 10px 24px rgba(111, 78, 55, 0.32);
        animation: tm-fab-in 420ms var(--tm-ease-out) 300ms both;
        transition:
            background-color 180ms ease,
            transform 160ms var(--tm-ease-out);
    }

    @keyframes tm-fab-in {
        from { opacity: 0; transform: scale(0.6); }
        to   { opacity: 1; transform: none; }
    }

    .tm-fab:active {
        background: var(--tm-brown-dark);
        transform: scale(0.94);
    }

    .tm-fab:focus-visible {
        outline: 2px solid var(--tm-brown);
        outline-offset: 3px;
    }

    .tm-fab-tip {
        position: absolute;
        right: calc(100% + 12px);
        top: 50%;
        padding: 7px 11px;
        border-radius: 8px;
        background: var(--tm-ink);
        color: #ffffff;
        font: 600 13px/1.2 Arial, Helvetica, sans-serif;
        white-space: nowrap;
        pointer-events: none;
        opacity: 0;
        transform: translate(4px, -50%);
        transition:
            opacity 160ms ease,
            transform 160ms var(--tm-ease-out);
    }

    .tm-fab:focus-visible .tm-fab-tip {
        opacity: 1;
        transform: translate(0, -50%);
    }

    <?php if ($termsFabLift > 0): ?>
    @media (max-width: 780px) {
        .tm-fab {
            bottom: calc(max(20px, calc(env(safe-area-inset-bottom, 0px) + 12px)) + <?= $termsFabLift ?>px);
        }
    }
    <?php endif; ?>

    /* ---------- dialog ---------- */

    .tm {
        width: min(620px, calc(100vw - 32px));
        max-width: none;
        height: min(720px, calc(100vh - 32px));
        height: min(720px, calc(100dvh - 32px));
        max-height: none;
        margin: auto;
        padding: 0;
        border: 0;
        border-radius: 20px;
        background: #ffffff;
        color: var(--tm-ink);
        overflow: hidden;
        font-family: Arial, Helvetica, sans-serif;
        box-shadow:
            0 1px 2px rgba(36, 26, 21, 0.06),
            0 12px 32px rgba(36, 26, 21, 0.14),
            0 32px 64px rgba(36, 26, 21, 0.12);

        /* exit: quicker than the entrance */
        opacity: 0;
        transform: scale(0.96);
        transition:
            opacity 160ms ease,
            transform 160ms var(--tm-ease-out);
        transition:
            opacity 160ms ease,
            transform 160ms var(--tm-ease-out),
            overlay 160ms allow-discrete,
            display 160ms allow-discrete;
    }

    .tm[open] {
        display: flex;
        flex-direction: column;
        opacity: 1;
        transform: none;
        transition-duration: 240ms;
    }

    @starting-style {
        .tm[open] {
            opacity: 0;
            transform: scale(0.96);
        }
    }

    .tm::backdrop {
        background: rgba(36, 26, 21, 0);
        transition:
            background-color 200ms ease,
            overlay 200ms allow-discrete,
            display 200ms allow-discrete;
    }

    .tm[open]::backdrop {
        background: rgba(36, 26, 21, 0.45);
    }

    @starting-style {
        .tm[open]::backdrop {
            background: rgba(36, 26, 21, 0);
        }
    }

    .tm-head {
        position: relative;
        flex: none;
        padding: 26px 72px 18px 32px;
        border-bottom: 1px solid var(--tm-line);
    }

    .tm-brand {
        display: block;
        margin-bottom: 10px;
        font-size: 13px;
        font-weight: 700;
        letter-spacing: 0.04em;
        color: var(--tm-brown);
    }

    .tm-brand span {
        color: var(--tm-gold);
    }

    .tm-head h2 {
        margin: 0;
        font-family: var(--font-display, Georgia, "Times New Roman", serif);
        font-size: 26px;
        font-weight: 600;
        line-height: 1.15;
        color: var(--tm-ink);
        outline: none;
    }

    .tm-close {
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
        color: var(--tm-muted);
        cursor: pointer;
        touch-action: manipulation;
        transition:
            background-color 180ms ease,
            color 180ms ease,
            transform 140ms var(--tm-ease-out);
    }

    .tm-close:active {
        transform: scale(0.94);
    }

    .tm-close:focus-visible,
    .tm-done:focus-visible {
        outline: 2px solid var(--tm-brown);
        outline-offset: 2px;
    }

    .tm-body {
        flex: 1;
        min-height: 0;
        padding: 22px 32px 26px;
        overflow-y: auto;
        overscroll-behavior: contain;
        -webkit-overflow-scrolling: touch;
        font-size: 15px;
        line-height: 1.6;
        color: var(--tm-muted);
    }

    .tm-intro {
        margin: 0 0 20px;
        color: var(--tm-ink);
    }

    .tm-list {
        margin: 0;
        padding: 0;
        list-style: none;
        counter-reset: tm;
    }

    .tm-list li {
        position: relative;
        padding-left: 40px;
        counter-increment: tm;
    }

    .tm-list li + li {
        margin-top: 20px;
    }

    .tm-list li::before {
        content: counter(tm);
        position: absolute;
        top: 0;
        left: 0;
        display: grid;
        place-items: center;
        width: 26px;
        height: 26px;
        border-radius: 999px;
        background: #fbf4ea;
        box-shadow: inset 0 0 0 1px rgba(183, 129, 63, 0.3);
        font-size: 12px;
        font-weight: 700;
        font-variant-numeric: tabular-nums;
        color: var(--tm-brown);
    }

    .tm-list h3 {
        margin: 2px 0 6px;
        font-size: 16px;
        font-weight: 700;
        line-height: 1.35;
        color: var(--tm-ink);
    }

    .tm-list p {
        margin: 0;
    }

    .tm-list p + p {
        margin-top: 6px;
    }

    .tm-agreement {
        margin-top: 26px;
        padding: 16px 18px;
        border-radius: 12px;
        background: #fbf4ea;
        box-shadow: inset 0 0 0 1px rgba(183, 129, 63, 0.28);
        color: #6b4a2b;
    }

    .tm-agreement strong {
        display: block;
        margin-bottom: 4px;
        color: var(--tm-ink);
    }

    .tm-agreement p {
        margin: 0;
    }

    .tm-foot {
        flex: none;
        padding: 14px 32px 18px;
        border-top: 1px solid var(--tm-line);
    }

    .tm-done {
        display: block;
        width: 100%;
        height: 50px;
        border: 0;
        border-radius: 12px;
        background: var(--tm-brown);
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
            transform 140ms var(--tm-ease-out);
    }

    .tm-done:active {
        background: var(--tm-brown-dark);
        transform: scale(0.97);
    }

    @media (hover: hover) and (pointer: fine) {
        .tm-fab:hover { background: var(--tm-brown-dark); }
        .tm-fab:hover .tm-fab-tip { opacity: 1; transform: translate(0, -50%); }
        .tm-close:hover { background: rgba(36, 26, 21, 0.06); color: var(--tm-ink); }
        .tm-done:hover { background: var(--tm-brown-dark); }
    }

    /* phones: a bottom sheet that slides up, like the login sheet */
    @media (max-width: 560px) {
        .tm-fab {
            width: 52px;
            height: 52px;
            right: max(16px, env(safe-area-inset-right, 0px));
        }

        .tm {
            width: 100%;
            height: calc(100vh - 24px);
            height: calc(100dvh - 24px);
            margin: auto 0 0;
            border-radius: 20px 20px 0 0;
            transform: translateY(100%);
            transition-timing-function: ease, var(--tm-ease-drawer);
        }

        .tm[open] {
            transform: none;
            transition-duration: 300ms;
        }

        @starting-style {
            .tm[open] {
                transform: translateY(100%);
            }
        }

        .tm-head {
            padding: 22px 64px 16px 20px;
        }

        .tm-head h2 {
            font-size: 23px;
        }

        .tm-body {
            padding: 18px 20px 22px;
        }

        .tm-foot {
            padding: 12px 20px calc(14px + env(safe-area-inset-bottom, 0px));
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .tm-fab,
        .tm,
        .tm[open],
        .tm-fab:active,
        .tm-close:active,
        .tm-done:active {
            transform: none !important;
        }

        .tm-fab {
            animation: none;
        }

        @starting-style {
            .tm[open] { transform: none !important; }
        }
    }

    @media print {
        .tm-fab { display: none; }
    }

</style>

<script>
(function () {
    var dialog = document.getElementById("terms-modal");
    var fab = document.querySelector(".tm-fab");

    if (!dialog || typeof dialog.showModal !== "function") {
        if (fab) {
            fab.hidden = true; // no <dialog> support: nothing to open
        }
        return;
    }

    var body = dialog.querySelector(".tm-body");
    var title = dialog.querySelector("#tm-title");
    var opener = null;

    // same scroll lock as the login modal: pad for a disappearing classic scrollbar
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

    function open(trigger) {
        if (dialog.open) {
            return;
        }

        opener = trigger || null;
        body.scrollTop = 0;
        lockScroll();
        dialog.showModal();
        title.focus();
    }

    document.addEventListener("click", function (event) {
        var trigger = event.target.closest
            ? event.target.closest('[data-terms-open], a[href="#terms"]')
            : null;

        if (!trigger) {
            return;
        }

        event.preventDefault();
        open(trigger);
    });

    dialog.querySelectorAll("[data-tm-close]").forEach(function (button) {
        button.addEventListener("click", function () {
            dialog.close();
        });
    });

    // backdrop click closes; a drag that starts inside the card does not
    var pressStartedOnBackdrop = false;

    dialog.addEventListener("pointerdown", function (event) {
        pressStartedOnBackdrop = event.target === dialog;
    });

    dialog.addEventListener("click", function (event) {
        if (event.target === dialog && pressStartedOnBackdrop) {
            dialog.close();
        }

        pressStartedOnBackdrop = false;
    });

    dialog.addEventListener("close", function () {
        unlockScroll();

        if (window.location.hash === "#terms" && window.history.replaceState) {
            window.history.replaceState(null, "", window.location.pathname + window.location.search);
        }

        if (opener && document.contains(opener) && opener.focus) {
            opener.focus({ preventScroll: true });
        }
    });

    function openFromHash() {
        if (window.location.hash === "#terms") {
            open(null);
        }
    }

    window.addEventListener("hashchange", openFromHash);
    openFromHash();
})();
</script>
