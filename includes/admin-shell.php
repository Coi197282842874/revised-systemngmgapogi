<?php
/*
 * The frame around every admin page: side menu, top bar (search, light/dark, notifications,
 * account) and, on phones, the bottom bar. The look lives in assets/admin.css, the behavior
 * in assets/admin.js.
 *
 *     <?php
 *     session_start();
 *     require_once __DIR__ . "/../config/database.php";
 *     require_once __DIR__ . "/../includes/admin-shell.php";
 *
 *     $admin = admin_boot($pdo, "rooms");            // login + permission (includes/admin.php)
 *     // ... the page's own work ...
 *
 *     admin_shell_head([
 *         "title" => "Rooms",
 *         "subtitle" => "Add, edit and remove rooms",
 *         "active" => "rooms",                       // menu item to highlight (its key)
 *         // optional: "narrow" => true (a slim page), "mobile_title" => false (the page
 *         // prints its own title on phones)
 *     ]);
 *     ?>
 *     <style> ...only what this page needs on top of admin.css... </style>
 *     <?php admin_shell_body(); ?>
 *         ...the page...
 *     <?php admin_shell_end(); ?>
 */

require_once __DIR__ . "/admin.php";

const ADMIN_THEME_COOKIE = "arves_admin_theme";

// Colors of the browser bar on phones: the same as the top of the page in each theme.
const ADMIN_THEME_COLORS = ["dark" => "#060912", "light" => "#f3f5fa"];

function admin_theme(): string
{
    $theme = $_COOKIE[ADMIN_THEME_COOKIE] ?? "dark";

    return $theme === "light" ? "light" : "dark";
}

// assets/admin.css?v=1727600000: the browser fetches the file again only after it changed
function admin_asset(string $file): string
{
    $path = __DIR__ . "/../assets/" . $file;

    return "../assets/" . $file . "?v=" . (is_file($path) ? filemtime($path) : 1);
}

function admin_shell_head(array $page): void
{
    $page += ["title" => "Admin", "subtitle" => "", "active" => "", "narrow" => false, "mobile_title" => true];
    $GLOBALS["arvesAdmin"]["page"] = $page;

    $theme = admin_theme();
    ?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, interactive-widget=resizes-content">
    <meta name="theme-color" content="<?= ADMIN_THEME_COLORS[$theme] ?>">
    <meta name="color-scheme" content="<?= $theme === "light" ? "light dark" : "dark light" ?>">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
    <title><?= h($page["title"]) ?> | ARVE'S House Admin</title>
    <link rel="stylesheet" href="<?= h(admin_asset("admin.css")) ?>">
    <?php
}

function admin_shell_body(): void
{
    $state = $GLOBALS["arvesAdmin"];
    $pdo = $state["pdo"];
    $admin = $state["user"];
    $page = $state["page"];

    $menu = admin_menu($pdo);
    $counts = admin_counts($pdo);
    $roleLabel = admin_role_label($admin["admin_role"]);
    $unread = $counts["unread_notifications"];
    ?>
</head>
<body>
<a class="skip-link" href="#main">Skip to the page</a>

<aside class="sidebar" id="sidebar" aria-label="Admin menu">
    <a class="brand" href="dashboard.php">
        <span class="brand-mark"><?= icon("home") ?></span>
        <span class="brand-text">
            <span class="brand-name">ARVE'S House</span>
            <span class="brand-tag">Admin panel</span>
        </span>
    </a>

    <!-- phones: search lives here; the top bar keeps only the menu, the bell and the account -->
    <button class="sidebar-search" type="button" data-search-open>
        <?= icon("search", 17) ?>
        <span>Search anything...</span>
    </button>

    <nav class="sidebar-scroll">
        <?php foreach ($menu as $title => $items): ?>
            <div class="nav-section">
                <?php if ($title !== ""): ?>
                    <div class="nav-label"><?= h($title) ?></div>
                <?php endif; ?>

                <?php foreach ($items as $item): ?>
                    <a
                        class="nav-item<?= $item["key"] === $page["active"] ? " is-active" : "" ?>"
                        href="<?= h($item["href"]) ?>"
                        <?= $item["key"] === $page["active"] ? 'aria-current="page"' : "" ?>
                    >
                        <?= icon($item["icon"]) ?>
                        <span><?= h($item["label"]) ?></span>
                        <?php if ($item["badge"] > 0): ?>
                            <span class="nav-badge" <?= $item["key"] === "notifications" ? "data-notif-count" : "" ?>><?= $item["badge"] > 99 ? "99+" : $item["badge"] ?></span>
                        <?php elseif ($item["key"] === "notifications"): ?>
                            <span class="nav-badge" data-notif-count hidden>0</span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-foot">
        <button class="user-card" type="button" data-menu="account-menu-side" aria-haspopup="menu" aria-expanded="false">
            <?= admin_avatar($admin, 36) ?>
            <span class="user-card-text">
                <span class="user-card-name"><?= h($admin["full_name"]) ?></span>
                <span class="user-card-role"><?= h($roleLabel) ?></span>
            </span>
            <?= icon("chevron-up", 16) ?>
        </button>

        <div class="menu menu-up" id="account-menu-side" role="menu">
            <?php admin_account_menu_items(); ?>
        </div>
    </div>
</aside>

<div class="scrim" data-sidebar-close></div>

<div class="main">
    <header class="topbar" id="topbar">
        <button class="icon-btn topbar-menu" type="button" data-sidebar-open aria-label="Open the menu" aria-controls="sidebar" aria-expanded="false">
            <?= icon("grid") ?>
        </button>

        <div class="topbar-title">
            <h1><?= h($page["title"]) ?></h1>
            <?php if ($page["subtitle"] !== ""): ?>
                <p><?= h($page["subtitle"]) ?></p>
            <?php endif; ?>
        </div>

        <div class="search" id="search" role="search">
            <label class="search-field">
                <?= icon("search", 17) ?>
                <span class="sr-only">Search the admin panel</span>
                <input
                    type="search"
                    id="search-input"
                    placeholder="Search anything..."
                    autocomplete="off"
                    autocapitalize="none"
                    autocorrect="off"
                    spellcheck="false"
                    enterkeyhint="search"
                    role="combobox"
                    aria-expanded="false"
                    aria-controls="search-panel"
                    aria-autocomplete="list"
                >
                <kbd class="search-key" aria-hidden="true">/</kbd>
                <button class="search-close" type="button" data-search-close aria-label="Close search">
                    <?= icon("x", 18) ?>
                </button>
            </label>

            <div class="search-panel" id="search-panel" role="listbox" aria-label="Search results"></div>
        </div>

        <div class="topbar-actions">
            <button class="icon-btn theme-toggle" type="button" data-theme-toggle aria-label="Switch between the light and dark theme">
                <?= icon("sun") ?><?= icon("moon") ?>
            </button>

            <div class="dropdown">
                <a
                    class="icon-btn"
                    href="notifications.php"
                    role="button"
                    data-menu="notif-menu"
                    data-notif-bell
                    aria-haspopup="true"
                    aria-expanded="false"
                    aria-label="Notifications<?= $unread > 0 ? ", " . $unread . " unread" : "" ?>"
                >
                    <?= icon("bell") ?>
                    <span class="icon-badge" data-notif-count <?= $unread > 0 ? "" : "hidden" ?>><?= $unread > 99 ? "99+" : $unread ?></span>
                </a>

                <div class="menu notif-menu" id="notif-menu">
                    <div class="notif-head">
                        <h2>Notifications</h2>
                        <button class="link-btn" type="button" data-notif-read-all <?= $unread > 0 ? "" : "disabled" ?>>Mark all as read</button>
                    </div>
                    <div class="menu-scroll" data-notif-list>
                        <?= admin_feed(admin_notifications($pdo, 6), ["notify_links" => true, "empty" => "No notifications yet"]) ?>
                    </div>
                    <a class="notif-foot" href="notifications.php">View all notifications</a>
                </div>
            </div>

            <div class="dropdown">
                <button class="avatar-btn" type="button" data-menu="account-menu" aria-haspopup="menu" aria-expanded="false" aria-label="Your account">
                    <?= admin_avatar($admin, 40) ?>
                </button>

                <div class="menu" id="account-menu" role="menu">
                    <div class="menu-head">
                        <strong><?= h($admin["full_name"]) ?></strong>
                        <span><?= h($admin["email"]) ?></span>
                    </div>
                    <div class="menu-sep"></div>
                    <?php admin_account_menu_items(); ?>
                </div>
            </div>
        </div>
    </header>

    <main class="page<?= $page["narrow"] ? " page-narrow" : "" ?>" id="main">
        <?php if ($page["mobile_title"]): ?>
            <div class="page-title-mobile">
                <h1><?= h($page["title"]) ?></h1>
                <?php if ($page["subtitle"] !== ""): ?>
                    <p><?= h($page["subtitle"]) ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php foreach (admin_take_flashes() as $flash): ?>
            <div class="alert alert-<?= $flash["type"] === "error" ? "error" : "success" ?> alert-in" role="status">
                <?= icon($flash["type"] === "error" ? "alert" : "check-circle") ?>
                <p><?= h($flash["message"]) ?></p>
            </div>
        <?php endforeach; ?>
    <?php
}

function admin_shell_end(): void
{
    $pdo = $GLOBALS["arvesAdmin"]["pdo"];
    $active = $GLOBALS["arvesAdmin"]["page"]["active"] ?? "";
    $counts = admin_counts($pdo);

    // phones: the four places an admin goes most, in a bar that floats over the page.
    // What the role may not open is left out, and Notifications steps in.
    $bar = [];

    foreach ([
        ["dashboard", "Home", "home", "dashboard.php", "", 0],
        ["reservations", "Reservations", "clipboard", "reservations.php", "reservations", $counts["pending_reservations"]],
        ["payments", "Payments", "credit-card", "payment.php", "payments", $counts["payments_to_verify"]],
        ["messages", "Messages", "message", "messages.php", "messages", $counts["unread_messages"]],
        ["notifications", "Notifications", "bell", "notifications.php", "", $counts["unread_notifications"]],
    ] as $item) {
        if (count($bar) < 4 && admin_can($item[4])) {
            $bar[] = $item;
        }
    }
    ?>
    </main>
</div>

<nav class="bottom-bar" aria-label="Shortcuts">
    <?php foreach ($bar as [$key, $label, $iconName, $href, $permission, $badge]): ?>
        <a
            class="bottom-item<?= $key === $active ? " is-active" : "" ?>"
            href="<?= h($href) ?>"
            title="<?= h($label) ?>"
            <?= $key === $active ? 'aria-current="page"' : "" ?>
        >
            <?= icon($iconName) ?>
            <span class="sr-only"><?= h($label) ?><?= $badge > 0 ? ", " . $badge . " waiting" : "" ?></span>
            <?php if ($badge > 0): ?>
                <span class="bottom-badge" aria-hidden="true"><?= $badge > 9 ? "9+" : $badge ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>

<div class="toasts" id="toasts" aria-live="polite"></div>

<dialog class="dialog" id="confirm-dialog" aria-labelledby="confirm-title">
    <form method="dialog">
        <div class="dialog-body">
            <h2 id="confirm-title">Are you sure?</h2>
            <p id="confirm-text"></p>
        </div>
        <div class="dialog-actions">
            <button class="btn" type="submit" value="cancel">Cancel</button>
            <button class="btn btn-primary" type="submit" value="ok" id="confirm-ok">Continue</button>
        </div>
    </form>
</dialog>

<script src="<?= h(admin_asset("admin.js")) ?>"></script>
</body>
</html>
    <?php
}

function admin_account_menu_items(): void
{
    ?>
    <button class="menu-item theme-item" type="button" role="menuitem" data-theme-toggle>
        <span class="theme-item-light"><?= icon("sun") ?> Light theme</span>
        <span class="theme-item-dark"><?= icon("moon") ?> Dark theme</span>
    </button>
    <a class="menu-item" href="account.php" role="menuitem"><?= icon("key") ?> Change password</a>
    <?php if (is_file(__DIR__ . "/../admin/help.php")): ?>
        <a class="menu-item" href="help.php" role="menuitem"><?= icon("help") ?> Help &amp; Support</a>
    <?php endif; ?>
    <a class="menu-item" href="../index.php" target="_blank" rel="noopener" role="menuitem"><?= icon("external-link") ?> View website</a>
    <div class="menu-sep"></div>
    <a class="menu-item is-danger" href="../logout.php" role="menuitem"><?= icon("log-out") ?> Log out</a>
    <?php
}


// ======================================================
// PIECES PAGES SHARE
// ======================================================

// The color of an event's icon tile (see activity_icon() for the icon itself).
function activity_tone(string $type): string
{
    $exact = [
        "reservation.created" => "blue",
        "reservation.confirmed" => "green",
        "reservation.completed" => "green",
        "reservation.cancelled" => "gray",
        "reservation.expired" => "gray",
        "reservation.declined" => "rose",
        "payment.submitted" => "amber",
        "payment.verified" => "green",
        "payment.online_paid" => "green",
        "payment.rejected" => "rose",
        "payment.online_cancelled" => "gray",
        "customer.registered" => "violet",
        "message.received" => "cyan",
        "dates.closed" => "amber",
        "dates.opened" => "green",
        "room.deleted" => "rose",
        "backup.downloaded" => "cyan",
        "ticket.scanned" => "green",
    ];

    if (isset($exact[$type])) {
        return $exact[$type];
    }

    foreach (["admin." => "violet", "customer." => "violet", "room." => "blue", "settings." => "gray", "review." => "amber"] as $prefix => $tone) {
        if (str_starts_with($type, $prefix)) {
            return $tone;
        }
    }

    return "blue";
}

// Only pages inside /admin/ may be opened from a notification ("reservations.php?status=pending").
function admin_safe_link(?string $link): string
{
    $link = trim((string) $link);

    if ($link === "" || !preg_match('/^[a-z0-9_\-]+\.php(\?[A-Za-z0-9_\-=&%.+]*)?(#[A-Za-z0-9_\-]*)?$/', $link)) {
        return "";
    }

    return $link;
}

/*
 * A list of events (rows of activity_log) as icon, sentence and time.
 * Options:
 *   notify_links  true: each row opens notifications.php?open=ID, which marks it read and
 *                 goes on to the event's page. false: rows link straight to their page.
 *   actor         true: show who did it under the sentence
 *   empty         text for an empty list
 */
function admin_feed(array $events, array $options = []): string
{
    $options += ["notify_links" => false, "actor" => false, "empty" => "Nothing here yet"];

    if (!$events) {
        return '<div class="empty empty-sm"><div class="empty-icon">' . icon("inbox", 20) . '</div>'
            . '<p>' . h($options["empty"]) . '</p></div>';
    }

    $html = '<ul class="feed">';

    foreach ($events as $event) {
        $link = admin_safe_link($event["link"] ?? "");
        $unread = (int) $event["notify"] === 1 && $event["read_at"] === null;

        if ($options["notify_links"] && (int) $event["notify"] === 1) {
            $href = "notifications.php?open=" . (int) $event["id"];
        } else {
            $href = $link;
        }

        $meta = "";

        if ($options["actor"]) {
            $who = trim((string) $event["actor_name"]);
            $role = (string) $event["actor_role"];

            if ($role === "system") {
                $meta = "Automatic";
            } elseif ($who !== "") {
                $meta = $who . ($role === "admin" ? " · Admin" : ($role === "customer" ? " · Customer" : ""));
            } else {
                $meta = ucfirst($role);
            }
        }

        $inner = '<span class="feed-icon tone-' . activity_tone($event["type"]) . '">'
            . icon(activity_icon($event["type"])) . '</span>'
            . '<span class="feed-body"><span class="feed-text">' . h($event["summary"]) . '</span>'
            . ($meta !== "" ? '<span class="feed-meta">' . h($meta) . '</span>' : "")
            . '</span>'
            . '<time class="feed-time" datetime="' . h(str_replace(" ", "T", $event["created_at"])) . 'Z" title="'
            . h(activity_time($event["created_at"])) . '">' . h(activity_time_ago($event["created_at"])) . '</time>';

        $class = "feed-item" . ($unread ? " is-unread" : "");

        $html .= $href !== ""
            ? '<li><a class="' . $class . '" href="' . h($href) . '">' . $inner . '</a></li>'
            : '<li><div class="' . $class . '">' . $inner . '</div></li>';
    }

    return $html . '</ul>';
}

/*
 * "↑ 12.5%" against an earlier period.
 * Returns ["text" => "12.5%", "direction" => "up" | "down" | "flat" | "none", "good" => bool|null].
 * "none": there is nothing to compare with (the earlier period was zero).
 * $upIsGood false is for numbers where less is better (cancellations).
 */
function stat_change(float $now, float $before, bool $upIsGood = true): array
{
    if ($before == 0.0) {
        return ["text" => "", "direction" => $now == 0.0 ? "flat" : "none", "good" => null];
    }

    $change = ($now - $before) / abs($before) * 100;

    if (abs($change) < 0.05) {
        return ["text" => "0%", "direction" => "flat", "good" => null];
    }

    $shown = abs($change) >= 100 ? number_format(abs($change), 0) : number_format(abs($change), 1);

    return [
        "text" => $shown . "%",
        "direction" => $change > 0 ? "up" : "down",
        "good" => ($change > 0) === $upIsGood,
    ];
}

// The small line under a stat: arrow, percent, and what it is compared with.
function stat_change_html(array $change, string $versus = "vs last month", string $noneText = "No earlier data"): string
{
    if ($change["direction"] === "none") {
        return '<div class="stat-delta">New</div><div class="stat-note">' . h($noneText) . '</div>';
    }

    if ($change["direction"] === "flat") {
        return '<div class="stat-delta">' . icon("arrow-right", 14) . ' No change</div>'
            . '<div class="stat-note">' . h($versus) . '</div>';
    }

    $up = $change["direction"] === "up";
    $class = $change["good"] === null ? "" : ($change["good"] ? " is-good" : " is-bad");

    return '<div class="stat-delta' . $class . '">' . icon($up ? "arrow-up-right" : "arrow-down-right", 14)
        . '<span class="sr-only">' . ($up ? "Up" : "Down") . '</span> ' . h($change["text"]) . '</div>'
        . '<div class="stat-note">' . h($versus) . '</div>';
}
