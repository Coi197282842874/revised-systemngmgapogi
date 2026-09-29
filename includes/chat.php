<?php
/*
 * ARVE'S House messages: one conversation per customer, answered by any admin.
 *
 * An inbox, not a live chat: nothing polls in the background. Messages load when a page or the
 * floating Messages window is opened, when someone sends, or when they press refresh.
 * (The free host, InfinityFree, does not allow live chat scripts.)
 * Used by customer/messages.php, admin/messages.php, messages-api.php and inbox-widget.php.
 */

require_once __DIR__ . "/auth.php";

const CHAT_MAX_LENGTH = 1000;          // characters per message
const CHAT_MAX_PER_MINUTE = 20;        // messages one person may send per minute
const CHAT_HISTORY = 60;               // messages loaded when a conversation opens

function ensure_chat_schema(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS chat_messages (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id INT UNSIGNED NOT NULL,
            sender ENUM('customer', 'admin') NOT NULL,
            sender_id INT UNSIGNED NOT NULL,
            body TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            read_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY chat_customer_idx (customer_id, id),
            KEY chat_unread_idx (sender, read_at),
            CONSTRAINT chat_customer_fk
                FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

// Shape sent to the browser. Times are UTC with a "Z" so the browser shows local time.
function chat_message_out(array $row): array
{
    return [
        "id" => (int) $row["id"],
        "sender" => $row["sender"],
        "body" => $row["body"],
        "at" => str_replace(" ", "T", $row["created_at"]) . "Z",
        "read" => $row["read_at"] !== null,
    ];
}

/*
 * Messages of one conversation. $after = 0 loads the latest CHAT_HISTORY messages;
 * otherwise only messages newer than $after.
 */
function chat_fetch(PDO $pdo, int $customerId, int $after): array
{
    if ($after > 0) {
        $stmt = $pdo->prepare(
            "SELECT id, sender, body, created_at, read_at
             FROM chat_messages
             WHERE customer_id = ? AND id > ?
             ORDER BY id
             LIMIT 200"
        );
        $stmt->execute([$customerId, $after]);
        $rows = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, sender, body, created_at, read_at
             FROM chat_messages
             WHERE customer_id = ?
             ORDER BY id DESC
             LIMIT " . CHAT_HISTORY
        );
        $stmt->execute([$customerId]);
        $rows = array_reverse($stmt->fetchAll());
    }

    return array_map("chat_message_out", $rows);
}

// Marks the other side's messages in a conversation as read.
function chat_mark_read(PDO $pdo, int $customerId, string $readerRole): void
{
    $from = $readerRole === "admin" ? "customer" : "admin";

    $pdo->prepare(
        "UPDATE chat_messages SET read_at = ?
         WHERE customer_id = ? AND sender = ? AND read_at IS NULL"
    )->execute([utc_now(), $customerId, $from]);
}

// Unread messages waiting for a customer (from admins).
function chat_unread_for_customer(PDO $pdo, int $customerId): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM chat_messages
         WHERE customer_id = ? AND sender = 'admin' AND read_at IS NULL"
    );
    $stmt->execute([$customerId]);

    return (int) $stmt->fetchColumn();
}

// Unread messages waiting for the admins (from all customers).
function chat_unread_for_admins(PDO $pdo): int
{
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM chat_messages WHERE sender = 'customer' AND read_at IS NULL"
    )->fetchColumn();
}

// Validates and stores a message. Returns ["ok" => true, "message" => ...] or ["ok" => false, "error" => ...].
function chat_send(PDO $pdo, int $customerId, string $sender, int $senderId, string $body): array
{
    $body = trim(str_replace("\r\n", "\n", $body));

    if ($body === "") {
        return ["ok" => false, "error" => "Type a message first."];
    }

    if (mb_strlen($body) > CHAT_MAX_LENGTH) {
        return ["ok" => false, "error" => "Messages can be up to " . CHAT_MAX_LENGTH . " characters."];
    }

    $recent = $pdo->prepare(
        "SELECT COUNT(*) FROM chat_messages
         WHERE sender = ? AND sender_id = ? AND created_at > ?"
    );
    $recent->execute([$sender, $senderId, gmdate("Y-m-d H:i:s", time() - 60)]);

    if ((int) $recent->fetchColumn() >= CHAT_MAX_PER_MINUTE) {
        return ["ok" => false, "error" => "You're sending messages too fast. Please wait a moment."];
    }

    $now = utc_now();
    $firstOfItsKind = chat_starts_a_turn($pdo, $customerId, $sender);

    $pdo->prepare(
        "INSERT INTO chat_messages (customer_id, sender, sender_id, body, created_at)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([$customerId, $sender, $senderId, $body, $now]);

    $messageId = $pdo->lastInsertId();

    if ($firstOfItsKind) {
        chat_log($pdo, $customerId, $sender);
    }

    return [
        "ok" => true,
        "message" => chat_message_out([
            "id" => $messageId,
            "sender" => $sender,
            "body" => $body,
            "created_at" => $now,
            "read_at" => null,
        ]),
    ];
}

/*
 * Several messages in a row count as one turn. True when this message starts a turn:
 * for a customer, when none of their messages is still waiting to be read by an admin;
 * for an admin, when the last message of the conversation is not already an admin's.
 */
function chat_starts_a_turn(PDO $pdo, int $customerId, string $sender): bool
{
    try {
        if ($sender === "customer") {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM chat_messages
                 WHERE customer_id = ? AND sender = 'customer' AND read_at IS NULL"
            );
            $stmt->execute([$customerId]);

            return (int) $stmt->fetchColumn() === 0;
        }

        $stmt = $pdo->prepare("SELECT sender FROM chat_messages WHERE customer_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$customerId]);

        return $stmt->fetchColumn() !== "admin";
    } catch (Throwable $e) {
        return false;
    }
}

/*
 * Writes the start of a turn into the activity log (includes/activity.php). A customer's
 * message also becomes a notification for the admins: one per turn, not one per message.
 */
function chat_log(PDO $pdo, int $customerId, string $sender): void
{
    try {
        require_once __DIR__ . "/activity.php";

        $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
        $stmt->execute([$customerId]);
        $name = (string) ($stmt->fetchColumn() ?: "A customer");

        $about = [
            "entity_type" => "customer",
            "entity_id" => $customerId,
            "link" => "messages.php?customer=" . $customerId,
        ];

        if ($sender === "customer") {
            log_activity($pdo, "message.received", $name . " sent a message", $about + [
                "actor_id" => $customerId,
                "actor_role" => "customer",
                "actor_name" => $name,
                "notify" => true,
            ]);
        } else {
            log_activity($pdo, "message.sent", "Replied to " . $name, $about);
        }
    } catch (Throwable $e) {
        error_log("ARVE'S House message log failed: " . $e->getMessage());
    }
}
