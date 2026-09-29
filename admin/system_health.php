<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/admin-shell.php";
require_once __DIR__ . "/../includes/paymongo.php";

// ======================================================
// SYSTEM HEALTH: is the server well, and a backup of the database
// Every check is read-only. The backup holds customers' details and password hashes, so
// this page is for the roles that were given "System Health" (the owner, unless changed).
// ======================================================

$admin = admin_boot($pdo, "system");

$root = dirname(__DIR__);
$isLocal = (bool) preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/', strtolower($_SERVER["HTTP_HOST"] ?? "localhost"));


// ======================================================
// BACKUP: the whole database as one .sql file
// ======================================================

function backup_value(PDO $pdo, $value): string
{
    return $value === null ? "NULL" : $pdo->quote((string) $value);
}

function backup_send(PDO $pdo): void
{
    @set_time_limit(180);

    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);

    header("Content-Type: application/sql; charset=utf-8");
    header('Content-Disposition: attachment; filename="arves-house-backup-' . date("Y-m-d-Hi") . '.sql"');
    header("Cache-Control: no-store");
    header("X-Content-Type-Options: nosniff");

    echo "-- ARVE'S House database backup\n";
    echo "-- Made on " . date("Y-m-d H:i:s") . " (Philippine time)\n";
    echo "-- To put it back: open phpMyAdmin, choose the database, Import, pick this file.\n";
    echo "-- Keep this file private: it holds customers' details.\n\n";
    echo "SET NAMES utf8mb4;\nSET time_zone = '+08:00';\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach ($tables as [$table]) {
        $name = "`" . str_replace("`", "``", $table) . "`";
        $create = $pdo->query("SHOW CREATE TABLE " . $name)->fetch(PDO::FETCH_NUM)[1];

        echo "-- ------------------------------------------------------\n-- " . $table . "\n-- ------------------------------------------------------\n\n";
        echo "DROP TABLE IF EXISTS " . $name . ";\n" . $create . ";\n\n";

        $rows = $pdo->query("SELECT * FROM " . $name);
        $columns = null;
        $batch = [];

        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            if ($columns === null) {
                $columns = implode(", ", array_map(fn ($column) => "`" . str_replace("`", "``", $column) . "`", array_keys($row)));
            }

            $batch[] = "(" . implode(", ", array_map(fn ($value) => backup_value($pdo, $value), $row)) . ")";

            if (count($batch) === 100) {
                echo "INSERT INTO " . $name . " (" . $columns . ") VALUES\n" . implode(",\n", $batch) . ";\n";
                $batch = [];
                flush();
            }
        }

        if ($batch) {
            echo "INSERT INTO " . $name . " (" . $columns . ") VALUES\n" . implode(",\n", $batch) . ";\n";
        }

        echo "\n";
    }

    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!admin_check_csrf()) {
        admin_flash("Your session expired. Please try again.", "error");
    } elseif (($_POST["action"] ?? "") === "backup") {
        log_activity($pdo, "backup.downloaded", "Downloaded a database backup", [
            "entity_type" => "backup",
            "link" => "system_health.php",
            "ip" => true,
        ]);

        backup_send($pdo);
        exit;
    }

    header("Location: system_health.php");
    exit;
}


// ======================================================
// THE CHECKS
// Each one: name, status (ok | warning | error | info), what was found, and what to do.
// ======================================================

function health_bytes(float $bytes): string
{
    foreach (["bytes", "KB", "MB", "GB"] as $index => $unit) {
        if ($bytes < 1024 || $unit === "GB") {
            return ($index === 0 ? number_format($bytes) : number_format($bytes, $bytes < 10 ? 1 : 0)) . " " . $unit;
        }

        $bytes /= 1024;
    }

    return "";
}

// "40M" → bytes
function health_ini_bytes(string $value): float
{
    $value = trim($value);
    $number = (float) $value;

    return match (strtolower(substr($value, -1))) {
        "g" => $number * 1024 * 1024 * 1024,
        "m" => $number * 1024 * 1024,
        "k" => $number * 1024,
        default => $number,
    };
}

// number of files and their size, in a folder and the folders inside it
function health_folder(string $path): array
{
    $files = 0;
    $bytes = 0;

    if (is_dir($path)) {
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($walk as $file) {
            if ($file->isFile() && $file->getFilename() !== ".htaccess") {
                $files++;
                $bytes += $file->getSize();
            }
        }
    }

    return [$files, $bytes];
}

$groups = [];

$check = function (string $group, string $name, string $status, string $found, string $advice = "") use (&$groups) {
    $groups[$group][] = ["name" => $name, "status" => $status, "found" => $found, "advice" => $advice];
};


// ---- server ----

$check(
    "Server",
    "PHP version",
    version_compare(PHP_VERSION, "8.0.0", ">=") ? "ok" : "error",
    PHP_VERSION,
    version_compare(PHP_VERSION, "8.0.0", ">=") ? "" : "The site needs PHP 8.0 or newer. Change it in the hosting control panel."
);

$needed = ["pdo_mysql" => "the database", "curl" => "Google sign-in and PayMongo", "openssl" => "secure connections and email", "mbstring" => "names with accents", "json" => "the search and the charts", "fileinfo" => "checking uploaded photos"];
$missing = array_keys(array_filter($needed, fn ($use, $extension) => !extension_loaded($extension), ARRAY_FILTER_USE_BOTH));

$check(
    "Server",
    "PHP extensions",
    $missing ? "error" : "ok",
    $missing ? "Missing: " . implode(", ", $missing) : "All " . count($needed) . " needed are there",
    $missing ? "Ask the host to turn on: " . implode(", ", array_map(fn ($e) => $e . " (for " . $needed[$e] . ")", $missing)) . "." : ""
);

$check(
    "Server",
    "Photo conversion (GD)",
    extension_loaded("gd") ? "ok" : "info",
    extension_loaded("gd") ? "Available" : "Not available",
    extension_loaded("gd") ? "" : "Room photos must be uploaded as JPG: PNG and WebP photos cannot be converted on this server."
);

$https = (!empty($_SERVER["HTTPS"]) && strtolower((string) $_SERVER["HTTPS"]) !== "off")
    || strtolower($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "") === "https"
    || (int) ($_SERVER["SERVER_PORT"] ?? 80) === 443;

$check(
    "Server",
    "Secure connection (HTTPS)",
    $https ? "ok" : ($isLocal ? "info" : "warning"),
    $https ? "On" : ($isLocal ? "Off on this computer, which is normal" : "This page was opened without HTTPS"),
    $https || $isLocal ? "" : "Open the site with https:// and turn on the free SSL certificate in the hosting control panel."
);

$uploadLimit = min(health_ini_bytes((string) ini_get("upload_max_filesize")), health_ini_bytes((string) ini_get("post_max_size")));

$check(
    "Server",
    "Largest upload",
    $uploadLimit >= 5 * 1024 * 1024 ? "ok" : "warning",
    health_bytes($uploadLimit) . " per file",
    $uploadLimit >= 5 * 1024 * 1024 ? "" : "Room photos of up to 5 MB are allowed by the site, but this server stops smaller ones."
);

$shown = ini_get("display_errors");
$errorsShown = !in_array(strtolower((string) $shown), ["", "0", "off", "false", "stderr"], true);

$check(
    "Server",
    "Error messages on pages",
    $errorsShown ? ($isLocal ? "info" : "warning") : "ok",
    $errorsShown ? "Shown" : "Hidden",
    $errorsShown && !$isLocal ? "Visitors could see technical details. Errors should be hidden on the live site." : ($errorsShown ? "Fine on this computer; they are hidden on the live site." : "")
);


// ---- database ----

$started = microtime(true);
$version = (string) $pdo->query("SELECT VERSION()")->fetchColumn();
$ping = (microtime(true) - $started) * 1000;

$check("Database", "Connection", $ping < 300 ? "ok" : "warning", "Answers in " . number_format($ping, $ping < 10 ? 1 : 0) . " ms", $ping < 300 ? "" : "The database is slow right now. On a free host this comes and goes.");
$check("Database", "Version", "info", $version);

$tables = [];
$databaseBytes = 0.0;

try {
    $stmt = $pdo->query(
        "SELECT TABLE_NAME AS name, DATA_LENGTH + INDEX_LENGTH AS bytes
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
         ORDER BY TABLE_NAME"
    );

    foreach ($stmt as $row) {
        $rows = (int) $pdo->query("SELECT COUNT(*) FROM `" . str_replace("`", "``", $row["name"]) . "`")->fetchColumn();
        $tables[] = ["name" => $row["name"], "rows" => $rows, "bytes" => (float) $row["bytes"]];
        $databaseBytes += (float) $row["bytes"];
    }
} catch (Throwable $e) {
    error_log("ARVE'S House system health: " . $e->getMessage());
}

$check("Database", "Size", "info", $tables ? health_bytes($databaseBytes) . " in " . count($tables) . " tables" : "Could not be read");

$drift = abs(time() - (int) strtotime((string) $pdo->query("SELECT NOW()")->fetchColumn()));

$check(
    "Database",
    "Clock",
    $drift <= 5 ? "ok" : "warning",
    $drift <= 5 ? "Philippine time, same as the server (" . date("M j, g:i A") . ")" : "The database and the server differ by " . $drift . " seconds",
    $drift <= 5 ? "" : "Dates of bookings and payments may look shifted. Tell your developer."
);

$logRows = activity_count($pdo);

$check("Database", "Activity log", "info", number_format($logRows) . ($logRows === 1 ? " entry" : " entries"));


// ---- files and folders ----

[$uploadFiles, $uploadBytes] = health_folder($root . "/uploads");

foreach (["uploads" => "Uploads folder", "uploads/profiles" => "Profile photos", "uploads/rooms" => "Room photos"] as $folder => $label) {
    $path = $root . "/" . $folder;

    if (!is_dir($path)) {
        $check("Files and folders", $label, $folder === "uploads" ? "error" : "info", "No folder yet", $folder === "uploads" ? "Create the folder \"uploads\" in the site's main folder." : "It is made when the first photo is uploaded.");
        continue;
    }

    [$files, $bytes] = health_folder($path);

    $check(
        "Files and folders",
        $label,
        is_writable($path) ? "ok" : "error",
        (is_writable($path) ? "Can be written" : "Cannot be written") . ($folder === "uploads" ? "" : ", " . number_format($files) . ($files === 1 ? " file, " : " files, ") . health_bytes($bytes)),
        is_writable($path) ? "" : "Photos cannot be saved. Give the folder write permission (755) in the file manager."
    );
}

$check("Files and folders", "Space used by uploads", "info", health_bytes($uploadBytes) . " in " . number_format($uploadFiles) . ($uploadFiles === 1 ? " file" : " files"));

$free = function_exists("disk_free_space") ? @disk_free_space($root) : false;

if ($free !== false && $free > 0) {
    $check(
        "Files and folders",
        "Free space on the server",
        $free > 200 * 1024 * 1024 ? "ok" : "warning",
        health_bytes((float) $free),
        $free > 200 * 1024 * 1024 ? "" : "Space is running out. Remove photos that are no longer needed."
    );
}


// ---- security ----

$guards = ["config", "includes", "database", "lib", "uploads"];
$unguarded = array_values(array_filter($guards, fn ($folder) => is_dir($root . "/" . $folder) && !is_file($root . "/" . $folder . "/.htaccess")));

$check(
    "Security",
    "Private folders",
    $unguarded ? "error" : "ok",
    $unguarded ? "Not protected: " . implode(", ", $unguarded) : "Closed to visitors",
    $unguarded ? "The file .htaccess is missing in: " . implode(", ", $unguarded) . ". Upload it again from your computer." : ""
);

$leftovers = array_map("basename", array_merge(glob($root . "/create_admin.php") ?: [], glob($root . "/admin-setup-*.php") ?: []));

$check(
    "Security",
    "Setup scripts",
    $leftovers ? "error" : "ok",
    $leftovers ? "Still on the server: " . implode(", ", $leftovers) : "None left on the server",
    $leftovers ? "Delete " . (count($leftovers) === 1 ? "this file" : "these files") . ": anyone who finds " . (count($leftovers) === 1 ? "it" : "them") . " could make an admin account." : ""
);

$check(
    "Security",
    "Sign-in settings",
    is_file($root . "/config/auth.php") ? "ok" : "warning",
    is_file($root . "/config/auth.php") ? "config/auth.php is in place" : "config/auth.php is missing",
    is_file($root . "/config/auth.php") ? "" : "Copy config/auth.sample.php to config/auth.php and fill it in. Until then Google sign-in, email and PayMongo are off."
);

$owners = (int) $pdo->query(
    "SELECT COUNT(*) FROM users
     WHERE role = 'admin' AND status = 'active' AND (admin_role IS NULL OR admin_role = '' OR admin_role = 'owner')"
)->fetchColumn();

$check(
    "Security",
    "Super Admins",
    $owners >= 1 ? "ok" : "error",
    $owners . " active",
    $owners === 1 ? "With only one, a forgotten password locks everyone out of Roles & Permissions. Consider a second one." : ""
);

$services = array_filter(["Google sign-in" => google_enabled(), "Email" => mail_enabled(), "PayMongo" => paymongo_enabled()]);

$check(
    "Security",
    "Outside services",
    count($services) === 3 ? "ok" : "info",
    count($services) . " of 3 connected" . ($services ? " (" . implode(", ", array_keys($services)) . ")" : ""),
    count($services) === 3 ? "" : "See Integrations for the ones that are not set up."
);


// ---- summary ----

$problems = 0;
$warnings = 0;

foreach ($groups as $checks) {
    foreach ($checks as $one) {
        $problems += $one["status"] === "error" ? 1 : 0;
        $warnings += $one["status"] === "warning" ? 1 : 0;
    }
}

$lastBackup = activity_recent($pdo, 1, ["type_prefix" => "backup."])[0] ?? null;

$statusPills = [
    "ok" => ["ok", "OK"],
    "warning" => ["warning", "Look at this"],
    "error" => ["error", "Problem"],
    "info" => ["off", "For your information"],
];

$groupIcons = ["Server" => "server", "Database" => "database", "Files and folders" => "image", "Security" => "shield"];
$groupTones = ["Server" => "blue", "Database" => "violet", "Files and folders" => "cyan", "Security" => "green"];

admin_shell_head([
    "title" => "System Health",
    "subtitle" => $problems + $warnings === 0
        ? "Everything looks good"
        : ($problems > 0 ? $problems . ($problems === 1 ? " problem" : " problems") : "")
            . ($problems > 0 && $warnings > 0 ? " and " : "")
            . ($warnings > 0 ? $warnings . ($warnings === 1 ? " thing" : " things") . " to look at" : ""),
    "active" => "system",
]);
?>
<style>
    .summary {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 18px 20px;
    }

    .summary .tile-icon {
        width: 50px;
        height: 50px;
        border-radius: 15px;
        font-size: 24px;
    }

    .summary h2 {
        font-size: 17px;
        font-weight: 700;
        letter-spacing: -0.01em;
    }

    .summary p {
        color: var(--text-2);
        font-size: 13px;
    }

    .summary .btn {
        flex: none;
        margin-left: auto;
    }

    .checks li {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 4px 14px;
        align-items: center;
        padding: 12px 20px;
        border-top: 1px solid var(--line);
    }

    .check-name {
        font-size: 13.5px;
        font-weight: 600;
    }

    .check-found {
        grid-column: 1;
        color: var(--text-2);
        font-size: 13px;
        overflow-wrap: anywhere;
    }

    .checks .pill {
        grid-column: 2;
        grid-row: 1 / 3;
    }

    .check-advice {
        grid-column: 1 / -1;
        margin-top: 4px;
        padding: 9px 12px;
        border-radius: var(--radius-sm);
        background: var(--warning-soft);
        color: var(--warning);
        font-size: 12.5px;
    }

    .check-advice.is-error {
        background: var(--danger-soft);
        color: var(--danger);
    }

    .check-advice.is-info {
        background: var(--surface-2);
        color: var(--text-2);
    }

    .group-head {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 16px 20px;
    }

    .group-head .tile-icon {
        width: 34px;
        height: 34px;
        border-radius: 10px;
        font-size: 17px;
    }

    @media (max-width: 767px) {
        .summary {
            flex-wrap: wrap;
            padding: 16px;
        }

        .summary .btn {
            width: 100%;
            margin-left: 0;
        }

        .checks li,
        .group-head {
            padding-right: 16px;
            padding-left: 16px;
        }
    }
</style>
<?php admin_shell_body(); ?>

<div class="stack">

    <!-- IN ONE LINE -->

    <section class="card summary">
        <span class="tile-icon tone-<?= $problems > 0 ? "rose" : ($warnings > 0 ? "amber" : "green") ?>">
            <?= icon($problems > 0 ? "x-circle" : ($warnings > 0 ? "alert" : "heart-pulse")) ?>
        </span>

        <div>
            <h2>
                <?php if ($problems > 0): ?>
                    <?= $problems ?> <?= $problems === 1 ? "problem needs" : "problems need" ?> fixing
                <?php elseif ($warnings > 0): ?>
                    <?= $warnings ?> <?= $warnings === 1 ? "thing" : "things" ?> to look at
                <?php else: ?>
                    Everything looks good
                <?php endif; ?>
            </h2>
            <p>Checked just now, <?= date("M j, Y · g:i A") ?> (Philippine time), on <?= $isLocal ? "this computer" : h($_SERVER["HTTP_HOST"] ?? "the live site") ?>.</p>
        </div>

        <a class="btn" href="system_health.php"><?= icon("refresh") ?> Check again</a>
    </section>


    <div class="grid grid-2" style="align-items:start">

        <?php foreach ($groups as $group => $checks): ?>
            <section class="card">
                <div class="group-head">
                    <span class="tile-icon tone-<?= $groupTones[$group] ?>"><?= icon($groupIcons[$group]) ?></span>
                    <h2 class="card-title"><?= h($group) ?></h2>
                </div>

                <ul class="checks">
                    <?php foreach ($checks as $one): ?>
                        <li>
                            <span class="check-name"><?= h($one["name"]) ?></span>
                            <?= status_pill($statusPills[$one["status"]][0], $statusPills[$one["status"]][1]) ?>
                            <span class="check-found"><?= h($one["found"]) ?></span>
                            <?php if ($one["advice"] !== ""): ?>
                                <p class="check-advice<?= $one["status"] === "error" ? " is-error" : ($one["status"] === "warning" ? "" : " is-info") ?>"><?= h($one["advice"]) ?></p>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>

    </div>


    <div class="grid grid-main">

        <!-- WHAT IS IN THE DATABASE -->

        <section class="card">
            <div class="card-head">
                <div>
                    <h2 class="card-title">What the database holds</h2>
                    <p class="card-sub"><?= h(health_bytes($databaseBytes)) ?> in total</p>
                </div>
            </div>

            <?php if ($tables): ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Table</th>
                                <th class="right">Rows</th>
                                <th class="right">Size</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tables as $table): ?>
                                <tr>
                                    <td class="mono"><?= h($table["name"]) ?></td>
                                    <td class="right num"><?= number_format($table["rows"]) ?></td>
                                    <td class="right num soft"><?= h(health_bytes($table["bytes"])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty empty-sm"><p>The list of tables could not be read on this server.</p></div>
            <?php endif; ?>
        </section>


        <!-- BACKUP -->

        <section class="card card-pad">
            <h2 class="card-title">Backup</h2>
            <p class="card-sub" style="margin-bottom:14px">A copy of the whole database in one file</p>

            <dl class="kv">
                <dt>Last download</dt>
                <dd>
                    <?php if ($lastBackup): ?>
                        <?= h(activity_time_ago($lastBackup["created_at"])) ?>
                        <span class="cell-sub" style="display:block;color:var(--text-3);font-size:12px;font-weight:400">
                            <?= h(activity_time($lastBackup["created_at"])) ?><?= trim((string) $lastBackup["actor_name"]) !== "" ? " by " . h($lastBackup["actor_name"]) : "" ?>
                        </span>
                    <?php else: ?>
                        Never
                    <?php endif; ?>
                </dd>

                <dt>It holds</dt>
                <dd>Customers, rooms, reservations, payments, messages and this panel's settings</dd>

                <dt>It does not hold</dt>
                <dd>Photos (the uploads folder) and the keys in config/auth.php</dd>
            </dl>

            <form method="post" style="margin-top:16px"
                data-confirm="The file holds your customers' names, emails and phone numbers. Keep it somewhere private, and never send it by chat or email."
                data-confirm-title="Download a backup?"
                data-confirm-ok="Download"
            >
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="backup">
                <button class="btn btn-primary btn-block" type="submit"><?= icon("download") ?> Download a backup</button>
            </form>

            <p class="hint">Do it before big changes, and about once a week when bookings are coming in. The download is written in the Activity Logs.</p>
        </section>

    </div>

</div>

<?php admin_shell_end(); ?>
