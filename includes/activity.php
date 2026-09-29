<?php
/*
 * Activity log and admin notifications.
 *
 * One table, activity_log: every noteworthy event (a booking, a payment, an admin action, a
 * login, an automatic cancellation). Rows with notify = 1 are also the admin's notifications:
 * the bell in the admin top bar counts the ones with read_at IS NULL.
 *
 *     log_activity($pdo, "reservation.created", "Maria Santos booked family room, Oct 12 to Oct 14", [
 *         "entity_type" => "reservation", "entity_id" => 21,
 *         "link" => "reservations.php?status=pending",    // relative to /admin/
 *         "notify" => true,
 *     ]);
 *
 * log_activity() never throws and never blocks the page: logging must not break a booking.
 * Times are stored in UTC (like the messages); show them with activity_time_ago() or
 * activity_time() which convert to Philippine time.
 *
 * Event types in use (keep new ones in the same "thing.verb" style):
 *   reservation.created   reservation.cancelled (by the customer)   reservation.expired (unpaid, automatic)
 *   reservation.declined  reservation.completed  reservation.confirmed
 *   payment.submitted (cash / pay at property, needs verifying)     payment.verified   payment.rejected
 *   payment.online_paid   payment.online_cancelled
 *   customer.registered   message.received   review.created   review.hidden   review.shown
 *   admin.login   admin.created   admin.role_changed   admin.password_changed
 *   room.created  room.updated  room.deleted   dates.closed   dates.opened
 *   settings.updated   backup.downloaded
 */

require_once __DIR__ . "/auth.php";

function ensure_activity_schema(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS activity_log (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            actor_id INT UNSIGNED NULL,
            actor_role VARCHAR(20) NOT NULL DEFAULT 'system',
            actor_name VARCHAR(150) NOT NULL DEFAULT '',
            type VARCHAR(50) NOT NULL,
            summary VARCHAR(255) NOT NULL,
            entity_type VARCHAR(30) NULL,
            entity_id INT UNSIGNED NULL,
            link VARCHAR(255) NULL,
            notify TINYINT(1) NOT NULL DEFAULT 0,
            read_at DATETIME NULL,
            ip VARCHAR(45) NULL,
            PRIMARY KEY (id),
            KEY activity_created_idx (created_at),
            KEY activity_notify_idx (notify, read_at),
            KEY activity_type_idx (type),
            KEY activity_entity_idx (entity_type, entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/*
 * Records an event. Options (all optional):
 *   actor_id, actor_role ("admin" | "customer" | "system" | "guest"), actor_name
 *       default: the logged-in user from the session, otherwise "system"
 *   entity_type, entity_id   what the event is about ("reservation", 21)
 *   link                     page to open from the admin, relative to /admin/
 *   notify                   true: also show it as an admin notification
 *   ip                       true: store the visitor's IP address (use for logins only)
 */
function log_activity(PDO $pdo, string $type, string $summary, array $options = []): void
{
    try {
        ensure_activity_schema($pdo);

        $sessionId = (int) ($_SESSION["user_id"] ?? 0);

        $actorId = array_key_exists("actor_id", $options) ? $options["actor_id"] : ($sessionId ?: null);
        $actorRole = $options["actor_role"] ?? ($sessionId ? (string) ($_SESSION["role"] ?? "customer") : "system");
        $actorName = $options["actor_name"] ?? ($sessionId ? (string) ($_SESSION["full_name"] ?? "") : "");

        $pdo->prepare(
            "INSERT INTO activity_log
                (created_at, actor_id, actor_role, actor_name, type, summary, entity_type, entity_id, link, notify, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            utc_now(),
            $actorId ? (int) $actorId : null,
            mb_substr($actorRole, 0, 20),
            mb_substr($actorName, 0, 150),
            mb_substr($type, 0, 50),
            mb_substr($summary, 0, 255),
            isset($options["entity_type"]) ? mb_substr((string) $options["entity_type"], 0, 30) : null,
            isset($options["entity_id"]) ? (int) $options["entity_id"] : null,
            isset($options["link"]) ? mb_substr((string) $options["link"], 0, 255) : null,
            !empty($options["notify"]) ? 1 : 0,
            !empty($options["ip"]) ? mb_substr((string) ($_SERVER["REMOTE_ADDR"] ?? ""), 0, 45) : null,
        ]);
    } catch (Throwable $e) {
        error_log("ARVE'S House activity log failed: " . $e->getMessage());
    }
}

/*
 * Latest events, newest first. Filters (all optional):
 *   type_prefix ("payment." or "reservation."), actor_role, notify (bool), unread (bool),
 *   search (text in the summary or the actor's name), before_id (for "older" paging)
 */
function activity_recent(PDO $pdo, int $limit = 20, array $filters = []): array
{
    ensure_activity_schema($pdo);

    $where = [];
    $values = [];

    if (!empty($filters["type_prefix"])) {
        $where[] = "type LIKE ?";
        $values[] = str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], (string) $filters["type_prefix"]) . "%";
    }

    if (!empty($filters["actor_role"])) {
        $where[] = "actor_role = ?";
        $values[] = (string) $filters["actor_role"];
    }

    if (array_key_exists("notify", $filters)) {
        $where[] = "notify = ?";
        $values[] = $filters["notify"] ? 1 : 0;
    }

    if (!empty($filters["unread"])) {
        $where[] = "read_at IS NULL";
    }

    if (!empty($filters["search"])) {
        $like = "%" . str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], (string) $filters["search"]) . "%";
        $where[] = "(summary LIKE ? OR actor_name LIKE ?)";
        $values[] = $like;
        $values[] = $like;
    }

    if (!empty($filters["before_id"])) {
        $where[] = "id < ?";
        $values[] = (int) $filters["before_id"];
    }

    $stmt = $pdo->prepare(
        "SELECT * FROM activity_log"
        . ($where ? " WHERE " . implode(" AND ", $where) : "")
        . " ORDER BY id DESC LIMIT " . max(1, min(200, $limit))
    );
    $stmt->execute($values);

    return $stmt->fetchAll();
}

function admin_notifications(PDO $pdo, int $limit = 8, bool $unreadOnly = false): array
{
    return activity_recent($pdo, $limit, ["notify" => true] + ($unreadOnly ? ["unread" => true] : []));
}

function admin_notifications_unread_count(PDO $pdo): int
{
    ensure_activity_schema($pdo);

    return (int) $pdo->query(
        "SELECT COUNT(*) FROM activity_log WHERE notify = 1 AND read_at IS NULL"
    )->fetchColumn();
}

// Marks one notification (by id) or all of them as read.
function admin_notifications_mark_read(PDO $pdo, ?int $id = null): void
{
    ensure_activity_schema($pdo);

    if ($id === null) {
        $pdo->prepare("UPDATE activity_log SET read_at = ? WHERE notify = 1 AND read_at IS NULL")
            ->execute([utc_now()]);
        return;
    }

    $pdo->prepare("UPDATE activity_log SET read_at = ? WHERE id = ? AND notify = 1 AND read_at IS NULL")
        ->execute([utc_now(), $id]);
}

// Icon name (includes/icons.php) for an event type.
function activity_icon(string $type): string
{
    $exact = [
        "reservation.created" => "calendar",
        "reservation.confirmed" => "check-circle",
        "reservation.completed" => "check-circle",
        "reservation.cancelled" => "x-circle",
        "reservation.declined" => "x-circle",
        "reservation.expired" => "clock",
        "payment.submitted" => "banknote",
        "payment.verified" => "check-circle",
        "payment.rejected" => "x-circle",
        "payment.online_paid" => "credit-card",
        "payment.online_cancelled" => "x-circle",
        "customer.registered" => "user-plus",
        "message.received" => "mail",
        "admin.login" => "log-in",
        "admin.created" => "user-plus",
        "admin.role_changed" => "shield",
        "admin.password_changed" => "key",
        "dates.closed" => "lock",
        "dates.opened" => "calendar",
        "settings.updated" => "settings",
        "backup.downloaded" => "download",
    ];

    if (isset($exact[$type])) {
        return $exact[$type];
    }

    $byPrefix = [
        "reservation." => "calendar",
        "payment." => "credit-card",
        "review." => "star",
        "room." => "bed",
        "admin." => "shield",
        "customer." => "user",
        "message." => "mail",
    ];

    foreach ($byPrefix as $prefix => $icon) {
        if (str_starts_with($type, $prefix)) {
            return $icon;
        }
    }

    return "activity";
}

// "Just now", "5 min ago", "3 hr ago", "Yesterday", then the date. $utc is "Y-m-d H:i:s" in UTC.
function activity_time_ago(string $utc): string
{
    $seconds = time() - utc_ts($utc);

    if ($seconds < 60) {
        return "Just now";
    }

    if ($seconds < 3600) {
        return floor($seconds / 60) . " min ago";
    }

    if ($seconds < 86400) {
        return floor($seconds / 3600) . " hr ago";
    }

    if ($seconds < 172800) {
        return "Yesterday";
    }

    if ($seconds < 604800) {
        return floor($seconds / 86400) . " days ago";
    }

    return activity_time($utc, "M j, Y");
}

// A UTC "Y-m-d H:i:s" shown in Philippine time.
function activity_time(string $utc, string $format = "M j, Y · g:i A"): string
{
    $date = new DateTime($utc, new DateTimeZone("UTC"));
    $date->setTimezone(new DateTimeZone("Asia/Manila"));

    return $date->format($format);
}
