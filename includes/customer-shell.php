<?php
/*
 * The frame around every customer page: side menu, top bar (search, light/dark, updates,
 * account) and, on phones, the bottom bar. The same frame as the admin panel, in the site's own
 * colors: assets/admin.css draws it, assets/customer.css gives it cream, brown and gold, and
 * assets/admin.js makes it work.
 *
 *     <?php
 *     session_start();
 *     require_once __DIR__ . "/../config/database.php";
 *     require_once __DIR__ . "/../includes/customer-shell.php";
 *
 *     $me = customer_boot($pdo);
 *     // ... the page's own work ...
 *
 *     customer_shell_head([
 *         "title" => "My Reservations",
 *         "subtitle" => "Your bookings, their payments and dates",
 *         "active" => "reservations",                // menu item to highlight (its key)
 *         // optional: "narrow" => true, "mobile_title" => false
 *     ]);
 *     ?>
 *     <style> ...only what this page needs... </style>
 *     <?php customer_shell_body(); ?>
 *         ...the page...
 *     <?php customer_shell_end(); ?>
 */

require_once __DIR__ . "/admin-shell.php";
require_once __DIR__ . "/customer.php";

const CUSTOMER_THEME_COOKIE = "arves_theme";

// Colors of the browser bar on phones: the same as the top of the page in each theme.
const CUSTOMER_THEME_COLORS = ["light" => "#f8efe4", "dark" => "#140d0a"];

// Light (cream) unless the guest chose dark.
function customer_theme(): string
{
    return ($_COOKIE[CUSTOMER_THEME_COOKIE] ?? "light") === "dark" ? "dark" : "light";
}

function customer_shell_head(array $page): void
{
    $page += ["title" => "My Account", "subtitle" => "", "active" => "", "narrow" => false, "mobile_title" => true];
    $GLOBALS["arvesCustomer"]["page"] = $page;

    $theme = customer_theme();
    ?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $theme ?>" data-area="customer" data-theme-cookie="<?= CUSTOMER_THEME_COOKIE ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, interactive-widget=resizes-content">
    <meta name="theme-color" content="<?= CUSTOMER_THEME_COLORS[$theme] ?>" data-light="<?= CUSTOMER_THEME_COLORS["light"] ?>" data-dark="<?= CUSTOMER_THEME_COLORS["dark"] ?>">
    <meta name="color-scheme" content="<?= $theme === "dark" ? "dark light" : "light dark" ?>">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
    <title><?= h($page["title"]) ?> | ARVE'S House</title>
    <link rel="stylesheet" href="<?= h(admin_asset("admin.css")) ?>">
    <link rel="stylesheet" href="<?= h(admin_asset("customer.css")) ?>">
    <?php
}

function customer_shell_body(): void
{
    $state = $GLOBALS["arvesCustomer"];
    $pdo = $state["pdo"];
    $me = $state["user"];
    $page = $state["page"];

    $menu = customer_menu($pdo, $me);
    $counts = customer_counts($pdo, (int) $me["id"]);
    $unread = $counts["unread_updates"];
    ?>
</head>
<body>
<a class="skip-link" href="#main">Skip to the page</a>

<aside class="sidebar" id="sidebar" aria-label="Your account">
    <a class="brand" href="dashboard.php">
        <span class="brand-mark"><?= icon("home") ?></span>
        <span class="brand-text">
            <span class="brand-name">ARVE'S House</span>
            <span class="brand-tag">Guest portal</span>
        </span>
    </a>

    <!-- phones: search lives here; the top bar keeps only the menu, the bell and the account -->
    <button class="sidebar-search" type="button" data-search-open>
        <?= icon("search", 17) ?>
        <span>Search your bookings...</span>
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
            <?= admin_avatar($me, 36) ?>
            <span class="user-card-text">
                <span class="user-card-name"><?= h($me["full_name"]) ?></span>
                <span class="user-card-role">Guest</span>
            </span>
            <?= icon("chevron-up", 16) ?>
        </button>

        <div class="menu menu-up" id="account-menu-side" role="menu">
            <?php customer_account_menu_items(); ?>
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
                <span class="sr-only">Search your bookings and payments</span>
                <input
                    type="search"
                    id="search-input"
                    placeholder="Search your bookings..."
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
                    aria-label="Updates<?= $unread > 0 ? ", " . $unread . " new" : "" ?>"
                >
                    <?= icon("bell") ?>
                    <span class="icon-badge" data-notif-count <?= $unread > 0 ? "" : "hidden" ?>><?= $unread > 99 ? "99+" : $unread ?></span>
                </a>

                <div class="menu notif-menu" id="notif-menu">
                    <div class="notif-head">
                        <h2>Updates</h2>
                        <button class="link-btn" type="button" data-notif-read-all <?= $unread > 0 ? "" : "disabled" ?>>Mark all as read</button>
                    </div>
                    <?php if ($counts["unread_messages"] > 0): ?>
                        <a class="notif-message" href="messages.php#latest">
                            <span class="feed-icon tone-cyan"><?= icon("message") ?></span>
                            <span>
                                <strong><?= $counts["unread_messages"] ?> new <?= $counts["unread_messages"] === 1 ? "message" : "messages" ?></strong>
                                from ARVE'S House
                            </span>
                        </a>
                    <?php endif; ?>
                    <div class="menu-scroll" data-notif-list>
                        <?= customer_feed(
                            customer_events($pdo, (int) $me["id"], 6),
                            (int) $me["id"],
                            ["new_since" => $me["notifications_seen_at"], "empty" => "No updates yet"]
                        ) ?>
                    </div>
                    <a class="notif-foot" href="notifications.php">View all updates</a>
                </div>
            </div>

            <div class="dropdown">
                <button class="avatar-btn" type="button" data-menu="account-menu" aria-haspopup="menu" aria-expanded="false" aria-label="Your account">
                    <?= admin_avatar($me, 40) ?>
                </button>

                <div class="menu" id="account-menu" role="menu">
                    <div class="menu-head">
                        <strong><?= h($me["full_name"]) ?></strong>
                        <span><?= h($me["email"]) ?></span>
                    </div>
                    <div class="menu-sep"></div>
                    <?php customer_account_menu_items(); ?>
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

function customer_shell_end(): void
{
    $state = $GLOBALS["arvesCustomer"];
    $active = $state["page"]["active"] ?? "";
    $counts = customer_counts($state["pdo"], (int) $state["user"]["id"]);

    // phones: the four places a guest goes most, in a bar that floats over the page
    $bar = [
        ["dashboard", "Home", "home", "dashboard.php", 0],
        ["reservations", "My Reservations", "clipboard", "reservations.php", $counts["to_pay"]],
        ["book", "Book a Room", "bed", "../rooms.php", 0],
        ["messages", "Messages", "message", "messages.php", $counts["unread_messages"]],
    ];
    ?>
    </main>
</div>

<nav class="bottom-bar" aria-label="Shortcuts">
    <?php foreach ($bar as [$key, $label, $iconName, $href, $badge]): ?>
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

<?php
// the terms open from the account menu here, so their round button stays hidden (customer.css)
require __DIR__ . "/terms-modal.php";
?>

<script src="<?= h(admin_asset("admin.js")) ?>"></script>
</body>
</html>
    <?php
}

function customer_account_menu_items(): void
{
    ?>
    <button class="menu-item theme-item" type="button" role="menuitem" data-theme-toggle>
        <span class="theme-item-light"><?= icon("sun") ?> Light theme</span>
        <span class="theme-item-dark"><?= icon("moon") ?> Dark theme</span>
    </button>
    <a class="menu-item" href="profile.php" role="menuitem"><?= icon("user") ?> My profile</a>
    <a class="menu-item" href="profile.php#password" role="menuitem"><?= icon("key") ?> Change password</a>
    <button class="menu-item" type="button" role="menuitem" data-terms-open><?= icon("file-text") ?> Terms &amp; conditions</button>
    <a class="menu-item" href="../index.php" role="menuitem"><?= icon("globe") ?> Back to the website</a>
    <div class="menu-sep"></div>
    <a class="menu-item is-danger" href="../logout.php" role="menuitem"><?= icon("log-out") ?> Log out</a>
    <?php
}
