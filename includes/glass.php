<?php
/*
 * Glass look for every page: frosted cards, glass icon chips and soft color blobs behind them.
 *
 * Include just before </head>, after the page's own <style>, so these rules win:
 *     <?php require __DIR__ . "/includes/glass.php"; ?>
 * Admin pages set $glassTheme = "admin" first for the cooler admin palette.
 *
 * Only backgrounds, borders, shadows and blur change; layout is untouched. Sticky navbars and
 * top bars are left alone on purpose: a backdrop-filter there would trap their position:fixed
 * mobile menus inside the bar.
 */

$glassTheme = ($glassTheme ?? "guest") === "admin" ? "admin" : "guest";
?>
<style>
/* ======================================================
   GLASS: tokens
====================================================== */
:root {
    --glass-bg: linear-gradient(135deg, rgba(255, 255, 255, 0.58) 0%, rgba(255, 255, 255, 0.24) 48%, rgba(255, 255, 255, 0.14) 100%);
    --glass-bg-strong: rgba(255, 255, 255, 0.66);
    --glass-bg-inner: rgba(255, 255, 255, 0.30);
    --glass-border: rgba(255, 255, 255, 0.85);
    --glass-highlight: inset 0 1px 0 rgba(255, 255, 255, 0.95), inset 0 0 0 1px rgba(255, 255, 255, 0.22), inset 0 -18px 30px -24px rgba(255, 255, 255, 0.6);
    --glass-blur: blur(22px) saturate(180%);
<?php if ($glassTheme === "admin"): ?>
    --glass-shadow: 0 14px 38px rgba(30, 41, 59, 0.14);
    --glass-tint: rgba(99, 102, 241, 0.16);
<?php else: ?>
    --glass-shadow: 0 14px 38px rgba(90, 56, 38, 0.16);
    --glass-tint: rgba(212, 167, 106, 0.24);
<?php endif; ?>
}

/* soft color blobs behind everything, so the frosted glass has something to blur */
html::before {
    content: "";
    position: fixed;
    inset: 0;
    z-index: -1;
    pointer-events: none;
<?php if ($glassTheme === "admin"): ?>
    background:
        radial-gradient(30rem 30rem at 10% 6%, rgba(99, 102, 241, 0.38), transparent 70%),
        radial-gradient(28rem 28rem at 95% 28%, rgba(14, 165, 233, 0.32), transparent 70%),
        radial-gradient(26rem 26rem at 30% 62%, rgba(236, 72, 153, 0.16), transparent 70%),
        radial-gradient(34rem 34rem at 70% 105%, rgba(168, 85, 247, 0.30), transparent 70%);
<?php else: ?>
    background:
        radial-gradient(30rem 30rem at 6% 5%, rgba(226, 170, 96, 0.55), transparent 70%),
        radial-gradient(26rem 26rem at 96% 30%, rgba(196, 106, 74, 0.34), transparent 70%),
        radial-gradient(24rem 24rem at 28% 60%, rgba(217, 163, 160, 0.30), transparent 70%),
        radial-gradient(34rem 34rem at 70% 106%, rgba(160, 104, 62, 0.34), transparent 70%);
<?php endif; ?>
}

/* ======================================================
   GLASS: light cards and panels
====================================================== */
html body :is(
    .hero-card, .feature-card, .search-card, .room-card, .availability-card, .form-card,
    .room-summary, .total-card, .success-card, .summary-card, .payment-card,
    .status-panel, .login-container, .register-container, .verify-card,
    .reservation-card, .empty, .profile-card, .rooms-card, .customer-card,
    .stat-card, .quick-card, .table-card, .panel
<?php if ($glassTheme === "guest"): ?>
    , .welcome
<?php endif; ?>
) {
    background: var(--glass-bg) !important;
    border: 1px solid var(--glass-border) !important;
    box-shadow: var(--glass-highlight), var(--glass-shadow) !important;
    -webkit-backdrop-filter: var(--glass-blur);
    backdrop-filter: var(--glass-blur);
}

/* boxes inside a glass card: lighter glass, no second blur (it would only cost battery) */
html body :is(.detail-box, .total-box, .reference-box, .reservation-top) {
    background: var(--glass-bg-inner) !important;
    border-color: rgba(255, 255, 255, 0.7) !important;
    box-shadow: var(--glass-highlight) !important;
}

html body .reservation-top {
    border-width: 0 0 1px !important;
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.55), var(--glass-tint)) !important;
}

/* floating windows: a little more solid so text stays easy to read */
html body :is(.lm, .tm, .pp) {
    background: var(--glass-bg-strong) !important;
    border: 1px solid var(--glass-border) !important;
    -webkit-backdrop-filter: blur(24px) saturate(170%);
    backdrop-filter: blur(24px) saturate(170%);
}

html body :is(.tm-head, .tm-foot) {
    background: transparent;
}

/* ======================================================
   GLASS: colored panels keep their color, gain a glass edge
====================================================== */
html body :is(.hero-card-inner, .room-visual, .about-visual, .cta-box, .preview-card) {
    border: 1px solid rgba(255, 255, 255, 0.35) !important;
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.35),
        inset 0 0 0 1px rgba(255, 255, 255, 0.06),
        var(--glass-shadow) !important;
}

<?php if ($glassTheme === "admin"): ?>
/* dark admin panels: smoked glass */
html body :is(.sidebar, .welcome) {
    background: linear-gradient(160deg, rgba(17, 24, 39, 0.86), rgba(30, 41, 59, 0.80)) !important;
    border: 1px solid rgba(255, 255, 255, 0.08) !important;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.10), 0 16px 40px rgba(15, 23, 42, 0.22) !important;
    -webkit-backdrop-filter: blur(18px) saturate(150%);
    backdrop-filter: blur(18px) saturate(150%);
}

html body .sidebar {
    border-width: 0 1px 0 0 !important;
}
<?php endif; ?>

/* ======================================================
   GLASS: icons
====================================================== */

/* light glass chips */
html body :is(
    .feature-icon, .stat-icon, .quick-icon, .method-icon, .drawer-icon,
    .bottom-icon, .mail-icon, .upload-icon
) {
    background: linear-gradient(145deg, rgba(255, 255, 255, 0.70) 0%, rgba(255, 255, 255, 0.22) 55%, rgba(255, 255, 255, 0.12) 100%) !important;
    border: 1px solid rgba(255, 255, 255, 0.95) !important;
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 1),
        inset 0 -10px 16px var(--glass-tint),
        0 8px 18px rgba(36, 26, 21, 0.12) !important;
    -webkit-backdrop-filter: blur(14px) saturate(180%);
    backdrop-filter: blur(14px) saturate(180%);
}

/* brand-colored buttons and marks: tinted glass */
html body :is(.logo-mark, .brand-mark, .photo-badge, .tm-fab, .success-icon) {
    border: 1px solid rgba(255, 255, 255, 0.45) !important;
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.45),
        inset 0 -8px 16px rgba(0, 0, 0, 0.12),
        0 8px 20px rgba(90, 56, 38, 0.24) !important;
}

html body :is(.logo-mark, .brand-mark, .photo-badge, .tm-fab) {
    background: linear-gradient(145deg, rgba(138, 94, 66, 0.88), rgba(81, 52, 33, 0.92)) !important;
    -webkit-backdrop-filter: blur(10px);
    backdrop-filter: blur(10px);
}

html body .photo-badge {
    border: 3px solid rgba(255, 255, 255, 0.9) !important;
}

/* small badges sitting on photos or colored panels */
html body :is(.room-badge, .available-badge) {
    background: rgba(255, 255, 255, 0.62) !important;
    border: 1px solid rgba(255, 255, 255, 0.8) !important;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.9), 0 4px 10px rgba(0, 0, 0, 0.08) !important;
    -webkit-backdrop-filter: blur(10px) saturate(160%);
    backdrop-filter: blur(10px) saturate(160%);
}

<?php if ($glassTheme === "admin"): ?>
/* icons on the dark sidebar */
html body :is(.menu-icon, .logo-icon) {
    display: inline-grid;
    place-items: center;
    flex: none;
    width: 30px;
    height: 30px;
    border-radius: 9px;
    background: rgba(255, 255, 255, 0.08) !important;
    border: 1px solid rgba(255, 255, 255, 0.12) !important;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.12) !important;
}

html body .logo-icon {
    width: auto;
    height: auto;
    min-width: 40px;
    min-height: 40px;
}
<?php endif; ?>

/* ======================================================
   LINE ICONS (includes/icons.php): one color per theme
====================================================== */
.i {
    flex: none;
    vertical-align: -0.14em;
}

html body :is(
    .feature-icon, .stat-icon, .quick-icon, .method-icon, .drawer-icon, .bottom-icon, .upload-icon
) {
<?php if ($glassTheme === "admin"): ?>
    color: #4f46e5;
<?php else: ?>
    color: #7a4f36;
<?php endif; ?>
}

html body :is(.feature-icon, .stat-icon, .quick-icon, .method-icon) .i {
    stroke-width: 1.7;
}

/* ======================================================
   GLASS: fallbacks
====================================================== */

/* no backdrop-filter support: make the glass more solid so text stays readable */
@supports not ((backdrop-filter: blur(1px)) or (-webkit-backdrop-filter: blur(1px))) {
    :root {
        --glass-bg: rgba(255, 255, 255, 0.86);
        --glass-bg-strong: rgba(255, 255, 255, 0.96);
    }
}

/* people who ask the OS for less transparency get solid surfaces */
@media (prefers-reduced-transparency: reduce) {
    :root {
        --glass-bg: rgba(255, 255, 255, 0.96);
        --glass-bg-strong: #ffffff;
        --glass-bg-inner: rgba(255, 255, 255, 0.9);
    }

    html::before {
        display: none;
    }
}
</style>
