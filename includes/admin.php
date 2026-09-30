<?php
/*
 * Admin panel core: who is logged in, what they may open, and the menu.
 *
 *     session_start();
 *     require_once __DIR__ . "/../config/database.php";
 *     require_once __DIR__ . "/../includes/admin-shell.php";     // loads this file
 *     $admin = admin_boot($pdo, "reservations");                 // login + permission check
 *
 * Roles (users.admin_role, only for users with role = 'admin'; empty means owner):
 *   owner    "Super Admin"  everything, always
 *   manager  "Manager"      runs the house day to day
 *   staff    "Front Desk"   guests, bookings and payments
 * What manager and staff may open is set on Roles & Permissions (admin/admins.php) and kept in
 * site_settings under "admin_role_permissions". The Dashboard, Notifications, Help and the
 * admin's own account are open to every admin.
 */

require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/icons.php";
require_once __DIR__ . "/activity.php";
require_once __DIR__ . "/chat.php";

const ADMIN_ROLES = [
    "owner" => [
        "label" => "Super Admin",
        "about" => "The owner. Opens and changes everything, including admins and backups.",
    ],
    "manager" => [
        "label" => "Manager",
        "about" => "Runs the house day to day: bookings, rooms, payments and reports.",
    ],
    "staff" => [
        "label" => "Front Desk",
        "about" => "Looks after guests: bookings, payments and messages.",
    ],
];

const ADMIN_PERMISSIONS = [
    "reservations" => ["label" => "Reservations", "about" => "See bookings, decline or complete them, scan tickets"],
    "calendar" => ["label" => "Calendar", "about" => "See the calendar, close and open dates"],
    "rooms" => ["label" => "Rooms", "about" => "Add, edit and remove rooms"],
    "payments" => ["label" => "Payments", "about" => "Verify or reject payments"],
    "customers" => ["label" => "Customers", "about" => "See customer accounts, turn them on or off"],
    "messages" => ["label" => "Messages", "about" => "Read and answer customer messages"],
    "analytics" => ["label" => "Analytics", "about" => "Revenue and booking reports"],
    "activity" => ["label" => "Activity Logs", "about" => "See who did what, and when"],
    "settings" => ["label" => "Settings", "about" => "Edit the homepage card"],
    "admins" => ["label" => "Roles & Permissions", "about" => "Add admins, change roles and access"],
    "integrations" => ["label" => "Integrations", "about" => "Google sign-in, email and PayMongo status"],
    "system" => ["label" => "System Health", "about" => "Server checks and database backups"],
];

const ADMIN_DEFAULT_PERMISSIONS = [
    "manager" => [
        "reservations", "calendar", "rooms", "payments", "customers",
        "messages", "analytics", "activity", "settings",
    ],
    "staff" => ["reservations", "calendar", "payments", "customers", "messages"],
];


// ======================================================
// SMALL HELPERS
// ======================================================

if (!function_exists("h")) {
    function h($value): string
    {
        return htmlspecialchars((string) ($value ?? ""), ENT_QUOTES, "UTF-8");
    }
}

// ₱1,250 (or ₱1,250.50 when there are centavos, or always with $decimals = 2)
function peso($amount, ?int $decimals = null): string
{
    $amount = (float) $amount;

    if ($decimals === null) {
        $decimals = abs($amount - round($amount)) < 0.005 ? 0 : 2;
    }

    return ($amount < 0 ? "-" : "") . "₱" . number_format(abs($amount), $decimals);
}

// 12,900 → "12.9K", 4,200,000 → "4.2M": for tight places such as chart axes
function compact_number($value): string
{
    $value = (float) $value;
    $abs = abs($value);

    if ($abs >= 1000000) {
        return rtrim(rtrim(number_format($value / 1000000, 1), "0"), ".") . "M";
    }

    if ($abs >= 10000) {
        return rtrim(rtrim(number_format($value / 1000, 1), "0"), ".") . "K";
    }

    return number_format($value);
}

// "Maria Clara Santos" → "MS"
function admin_initials(string $name): string
{
    // letters and digits only: "Maria Santos (test)" is MT, not M(
    $words = array_map(
        fn ($word) => (string) preg_replace('/[^\p{L}\p{N}]/u', "", $word),
        preg_split('/\s+/', trim($name)) ?: []
    );
    $words = array_values(array_filter($words, fn ($word) => $word !== ""));

    if (!$words) {
        return "?";
    }

    $first = mb_substr($words[0], 0, 1);
    $last = count($words) > 1 ? mb_substr($words[count($words) - 1], 0, 1) : "";

    return mb_strtoupper($first . $last);
}

/*
 * A round profile picture, or the person's initials when there is none.
 * $user needs full_name (or customer_name) and optionally profile_image (a path from the site root).
 */
function admin_avatar(array $user, int $size = 36, string $class = ""): string
{
    $name = (string) ($user["full_name"] ?? $user["customer_name"] ?? "");
    $image = (string) ($user["profile_image"] ?? "");
    $style = "width:{$size}px;height:{$size}px;font-size:" . max(10, (int) round($size * 0.38)) . "px";
    $class = "avatar" . ($class !== "" ? " " . $class : "");

    if ($image !== "") {
        return '<img class="' . $class . '" style="' . $style . '" src="../' . h($image)
            . '" alt="" loading="lazy" decoding="async">';
    }

    // the same person always gets the same color
    $tone = (abs(crc32(mb_strtolower($name))) % 6) + 1;

    return '<span class="' . $class . ' avatar-tone-' . $tone . '" style="' . $style . '" aria-hidden="true">'
        . h(admin_initials($name)) . '</span>';
}

// A date from the database ("2026-10-12" or "2026-10-12 14:03:00", Philippine time) for reading.
function admin_date(?string $value, string $format = "M j, Y"): string
{
    if ($value === null || $value === "" || str_starts_with($value, "0000")) {
        return "";
    }

    $time = strtotime($value);

    return $time === false ? "" : date($format, $time);
}

// "Oct 12 – 14, 2026", "Oct 30 – Nov 2, 2026"
function admin_stay(string $checkIn, string $checkOut): string
{
    $from = strtotime($checkIn);
    $to = strtotime($checkOut);

    if ($from === false || $to === false) {
        return "";
    }

    if (date("Y-m", $from) === date("Y-m", $to)) {
        return date("M j", $from) . " – " . date("j, Y", $to);
    }

    if (date("Y", $from) === date("Y", $to)) {
        return date("M j", $from) . " – " . date("M j, Y", $to);
    }

    return date("M j, Y", $from) . " – " . date("M j, Y", $to);
}

/*
 * The address of a list page with its filters. Empty values are left out:
 *     admin_url("reservations.php", ["status" => "pending", "q" => $q, "page" => 2])
 */
function admin_url(string $page, array $query = []): string
{
    $query = array_filter($query, fn ($value) => $value !== null && $value !== "" && $value !== 0 && $value !== "all");

    if (($query["page"] ?? 0) === 1) {
        unset($query["page"]);
    }

    return $page . ($query ? "?" . http_build_query($query, "", "&", PHP_QUERY_RFC3986) : "");
}

// "%text%" for LIKE, with the characters LIKE treats as special made harmless
function admin_like(string $text): string
{
    return "%" . str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $text) . "%";
}

// What was typed in a search box: trimmed, at most 80 characters.
function admin_query(string $name = "q"): string
{
    $value = $_GET[$name] ?? "";

    return is_string($value) ? mb_substr(trim($value), 0, 80) : "";
}

/*
 * Splits a long list into pages. $page defaults to ?page= of the address.
 * Returns page, pages, per_page, offset, total, from, to (from and to are row numbers, 1-based).
 */
function admin_paginate(int $total, int $perPage = 25, ?int $page = null): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($pages, $page ?? (int) ($_GET["page"] ?? 1)));
    $offset = ($page - 1) * $perPage;

    return [
        "page" => $page,
        "pages" => $pages,
        "per_page" => $perPage,
        "offset" => $offset,
        "total" => $total,
        "from" => $total > 0 ? $offset + 1 : 0,
        "to" => min($total, $offset + $perPage),
    ];
}

/*
 * The bar under a table: "1 to 25 of 60" and Previous / Next.
 * $url gets a page number and returns the address of that page. Nothing is shown for one page.
 */
function admin_pager(array $paging, callable $url, string $things = "rows"): string
{
    if ($paging["pages"] < 2) {
        return "";
    }

    $html = '<div class="pager"><span>' . number_format($paging["from"]) . ' to ' . number_format($paging["to"])
        . ' of ' . number_format($paging["total"]) . ' ' . h($things) . '</span><div class="pager-links">';

    $html .= $paging["page"] > 1
        ? '<a class="btn btn-sm" href="' . h($url($paging["page"] - 1)) . '" rel="prev">' . icon("chevron-left") . ' Previous</a>'
        : '<span class="btn btn-sm" aria-disabled="true">' . icon("chevron-left") . ' Previous</span>';

    $html .= '<span class="pager-now">Page ' . $paging["page"] . ' of ' . $paging["pages"] . '</span>';

    $html .= $paging["page"] < $paging["pages"]
        ? '<a class="btn btn-sm" href="' . h($url($paging["page"] + 1)) . '" rel="next">Next ' . icon("chevron-right") . '</a>'
        : '<span class="btn btn-sm" aria-disabled="true">Next ' . icon("chevron-right") . '</span>';

    return $html . '</div></div>';
}

/*
 * A colored status label. $status is a reservation, payment, room or account status.
 * Always text plus a dot, never color alone.
 */
function status_pill(?string $status, ?string $label = null): string
{
    $status = strtolower(trim((string) $status));

    $tones = [
        "confirmed" => "success", "verified" => "success", "active" => "success",
        "available" => "success", "paid" => "success", "connected" => "success", "ok" => "success",
        "completed" => "info",
        "pending" => "warning", "test mode" => "warning", "warning" => "warning",
        "declined" => "danger", "rejected" => "danger", "inactive" => "danger",
        "failed" => "danger", "error" => "danger",
        "cancelled" => "neutral", "expired" => "neutral", "unavailable" => "neutral",
        "maintenance" => "neutral", "not set up" => "neutral", "off" => "neutral",
    ];

    $tone = $tones[$status] ?? "neutral";
    $label = $label ?? ucfirst($status === "" ? "unknown" : $status);

    return '<span class="pill pill-' . $tone . '">' . h($label) . '</span>';
}


// ======================================================
// SCHEMA
// ======================================================

function ensure_admin_schema(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    ensure_auth_schema($pdo);

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS site_settings (
            setting_key VARCHAR(100) NOT NULL,
            setting_value TEXT NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $stmt = $pdo->query(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = 'users'
         AND COLUMN_NAME = 'admin_role'"
    );

    if (!$stmt->fetchColumn()) {
        try {
            // NULL on an admin means owner: admins from before roles existed keep full access
            $pdo->exec("ALTER TABLE users ADD COLUMN admin_role VARCHAR(20) NULL AFTER role");
        } catch (PDOException $e) {
            // 1060: another request added the column a moment ago
            if ((int) ($e->errorInfo[1] ?? 0) !== 1060) {
                throw $e;
            }
        }
    }
}

function site_setting(PDO $pdo, string $key, string $default = ""): string
{
    $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    return $value === false ? $default : (string) $value;
}

function save_site_setting(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare(
        "INSERT INTO site_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    )->execute([$key, $value]);
}


// ======================================================
// ROLES AND PERMISSIONS
// ======================================================

function admin_role_of(array $user): string
{
    $role = (string) ($user["admin_role"] ?? "");

    return isset(ADMIN_ROLES[$role]) ? $role : "owner";
}

function admin_role_label(string $role): string
{
    return ADMIN_ROLES[$role]["label"] ?? ADMIN_ROLES["owner"]["label"];
}

// role => list of permission keys. The owner always has all of them.
function admin_role_permissions(PDO $pdo): array
{
    static $map = null;

    if ($map !== null) {
        return $map;
    }

    $map = ADMIN_DEFAULT_PERMISSIONS;
    $saved = json_decode(site_setting($pdo, "admin_role_permissions", ""), true);

    if (is_array($saved)) {
        foreach (array_keys(ADMIN_DEFAULT_PERMISSIONS) as $role) {
            if (isset($saved[$role]) && is_array($saved[$role])) {
                $map[$role] = array_values(array_intersect(array_keys(ADMIN_PERMISSIONS), $saved[$role]));
            }
        }
    }

    // only the owner manages admins, whatever was saved
    foreach ($map as $role => $permissions) {
        $map[$role] = array_values(array_diff($permissions, ["admins"]));
    }

    $map["owner"] = array_keys(ADMIN_PERMISSIONS);

    return $map;
}

function admin_save_role_permissions(PDO $pdo, array $submitted): void
{
    $clean = [];

    foreach (array_keys(ADMIN_DEFAULT_PERMISSIONS) as $role) {
        $picked = is_array($submitted[$role] ?? null) ? $submitted[$role] : [];
        $clean[$role] = array_values(array_diff(
            array_intersect(array_keys(ADMIN_PERMISSIONS), $picked),
            ["admins"]
        ));
    }

    save_site_setting($pdo, "admin_role_permissions", json_encode($clean));
}

// The logged-in admin (set by admin_boot), with "admin_role" always filled in.
function admin_current(): array
{
    return $GLOBALS["arvesAdmin"]["user"] ?? [];
}

function admin_can(string $permission): bool
{
    if ($permission === "") {
        return true;
    }

    return in_array($permission, $GLOBALS["arvesAdmin"]["permissions"] ?? [], true);
}


// ======================================================
// LOGIN AND PERMISSION CHECK
// ======================================================

/*
 * The admin panel counts days, shows dates and reads the database's clock in Philippine time.
 * config/database.php does the same for the whole site; it is repeated here so the panel is
 * right even on a host whose config/database.php is an older one.
 */
function admin_philippine_time(PDO $pdo): void
{
    date_default_timezone_set("Asia/Manila");

    try {
        $pdo->exec("SET time_zone = '+08:00'");
    } catch (PDOException $e) {
        error_log("ARVE'S House: the database refused the Philippine time zone: " . $e->getMessage());
    }
}

/*
 * Call at the top of every admin page, after session_start() and config/database.php.
 * Sends visitors who are not an active admin to the login page. With $permission, admins
 * whose role does not include it get a "no access" page instead. Returns the admin's row.
 */
function admin_boot(PDO $pdo, string $permission = ""): array
{
    if (!isset($_SESSION["user_id"]) || ($_SESSION["role"] ?? "") !== "admin") {
        header("Location: ../login.php");
        exit;
    }

    admin_philippine_time($pdo);
    ensure_admin_schema($pdo);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([(int) $_SESSION["user_id"]]);
    $user = $stmt->fetch();

    // deleted, demoted or turned off while logged in: the session ends here
    if (!$user || $user["role"] !== "admin" || $user["status"] !== "active") {
        $_SESSION = [];
        session_destroy();
        header("Location: ../login.php");
        exit;
    }

    unset($user["password"]);
    $user["admin_role"] = admin_role_of($user);

    $GLOBALS["arvesAdmin"] = [
        "pdo" => $pdo,
        "user" => $user,
        "permissions" => admin_role_permissions($pdo)[$user["admin_role"]] ?? [],
    ];

    // the first time the log is opened it is filled with what already happened
    activity_backfill($pdo);

    if (!admin_can($permission)) {
        http_response_code(403);
        admin_shell_head(["title" => "No access", "subtitle" => "", "active" => ""]);
        admin_shell_body();
        echo '<div class="card"><div class="empty">'
            . '<div class="empty-icon">' . icon("lock", 22) . '</div>'
            . '<h3>You don\'t have access to this page</h3>'
            . '<p>Your role (' . h(admin_role_label($user["admin_role"])) . ') does not include '
            . h(ADMIN_PERMISSIONS[$permission]["label"] ?? "this page")
            . '. Ask the owner if you need it.</p>'
            . '<a class="btn btn-primary" href="dashboard.php">Back to the dashboard</a>'
            . '</div></div>';
        admin_shell_end();
        exit;
    }

    return $user;
}

// For admin pages that answer with JSON: same checks as admin_boot, but replies 401/403 in JSON.
function admin_boot_json(PDO $pdo, string $permission = ""): array
{
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store");

    $fail = function (int $code, string $error) {
        http_response_code($code);
        echo json_encode(["ok" => false, "error" => $error]);
        exit;
    };

    if (!isset($_SESSION["user_id"]) || ($_SESSION["role"] ?? "") !== "admin") {
        $fail(401, "Please log in again.");
    }

    admin_philippine_time($pdo);
    ensure_admin_schema($pdo);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([(int) $_SESSION["user_id"]]);
    $user = $stmt->fetch();

    if (!$user || $user["role"] !== "admin" || $user["status"] !== "active") {
        $fail(401, "Please log in again.");
    }

    unset($user["password"]);
    $user["admin_role"] = admin_role_of($user);

    $GLOBALS["arvesAdmin"] = [
        "pdo" => $pdo,
        "user" => $user,
        "permissions" => admin_role_permissions($pdo)[$user["admin_role"]] ?? [],
    ];

    if (!admin_can($permission)) {
        $fail(403, "Your role does not include this.");
    }

    return $user;
}

// POST forms on admin pages: stops the request when the hidden csrf_token is missing or wrong.
function admin_check_csrf(): bool
{
    return csrf_valid($_POST["csrf_token"] ?? null);
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

/*
 * One-time message shown on the next page load (after a redirect):
 *     admin_flash("Payment verified.");   admin_flash("Could not save.", "error");
 */
function admin_flash(string $message, string $type = "success"): void
{
    $_SESSION["admin_flash"][] = ["message" => $message, "type" => $type];
}

function admin_take_flashes(): array
{
    $flashes = $_SESSION["admin_flash"] ?? [];
    unset($_SESSION["admin_flash"]);

    return is_array($flashes) ? $flashes : [];
}


// ======================================================
// MENU
// ======================================================

// Things waiting for an admin: shown as badges in the menu and as the dashboard's to-do list.
function admin_counts(PDO $pdo): array
{
    static $counts = null;

    if ($counts !== null) {
        return $counts;
    }

    ensure_chat_schema($pdo);

    $counts = [
        "pending_reservations" => (int) $pdo->query(
            "SELECT COUNT(*) FROM reservations WHERE status = 'pending'"
        )->fetchColumn(),
        // online payments confirm themselves; only the others wait for an admin
        "payments_to_verify" => (int) $pdo->query(
            "SELECT COUNT(*) FROM payments WHERE status = 'pending' AND payment_method NOT LIKE 'online%'"
        )->fetchColumn(),
        "unread_messages" => chat_unread_for_admins($pdo),
        "unread_notifications" => admin_notifications_unread_count($pdo),
    ];

    return $counts;
}

/*
 * The side menu: sections of items. Items the admin's role may not open are left out.
 * Each item: key, label, icon, href, badge (a number, 0 hides it).
 */
function admin_menu(PDO $pdo): array
{
    $counts = admin_counts($pdo);

    $sections = [
        "" => [
            ["dashboard", "Dashboard", "home", "dashboard.php", "", 0],
        ],
        "Bookings" => [
            ["reservations", "Reservations", "clipboard", "reservations.php", "reservations", $counts["pending_reservations"]],
            ["scan", "Scan Ticket", "scan", "scan.php", "reservations", 0],
            ["calendar", "Calendar", "calendar", "calendar.php", "calendar", 0],
            ["rooms", "Rooms", "bed", "rooms.php", "rooms", 0],
            ["payments", "Payments", "credit-card", "payment.php", "payments", $counts["payments_to_verify"]],
        ],
        "People" => [
            ["customers", "Customers", "users", "customers.php", "customers", 0],
            ["messages", "Messages", "message", "messages.php", "messages", $counts["unread_messages"]],
            ["admins", "Roles & Permissions", "shield", "admins.php", "admins", 0],
        ],
        "Insights" => [
            ["analytics", "Analytics", "chart", "analytics.php", "analytics", 0],
            ["notifications", "Notifications", "bell", "notifications.php", "", $counts["unread_notifications"]],
            ["activity", "Activity Logs", "file-text", "activity.php", "activity", 0],
        ],
        "System" => [
            ["settings", "Settings", "settings", "site_settings.php", "settings", 0],
            ["integrations", "Integrations", "plug", "integrations.php", "integrations", 0],
            ["system", "System Health", "heart-pulse", "system_health.php", "system", 0],
            ["help", "Help & Support", "help", "help.php", "", 0],
        ],
    ];

    $menu = [];

    foreach ($sections as $title => $items) {
        $visible = [];

        foreach ($items as [$key, $label, $iconName, $href, $permission, $badge]) {
            // a page that is not built yet stays out of the menu
            if (admin_can($permission) && is_file(__DIR__ . "/../admin/" . $href)) {
                $visible[] = [
                    "key" => $key,
                    "label" => $label,
                    "icon" => $iconName,
                    "href" => $href,
                    "badge" => (int) $badge,
                ];
            }
        }

        if ($visible) {
            $menu[$title] = $visible;
        }
    }

    return $menu;
}
