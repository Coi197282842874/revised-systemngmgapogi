<?php
/*
 * Room availability: reservations plus dates the admin closed (room_blocks).
 *
 * A stay is the nights check_in … check_out − 1 (guests leave on the check-out morning).
 * A block closes the nights start_date … end_date, both included, for one room or, with
 * room_id NULL, for every room.
 * Pending and confirmed reservations hold their nights; cancelled/declined ones don't.
 */

require_once __DIR__ . "/auth.php";

const AVAILABILITY_HOLDING_STATUSES = "('pending', 'confirmed')";

function ensure_availability_schema(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS room_blocks (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            room_id INT UNSIGNED NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            reason VARCHAR(150) NOT NULL DEFAULT '',
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY room_blocks_dates (start_date, end_date),
            KEY room_blocks_room (room_id),
            CONSTRAINT room_blocks_room_fk FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/*
 * SQL condition (for a query on `rooms`) that is true when the room is free for
 * [? check-in, ? check-out). Bind: check_out, check_in, check_out, check_in.
 */
function room_free_sql(string $roomColumn = "rooms.id"): string
{
    return "NOT EXISTS (
                SELECT 1 FROM reservations
                WHERE reservations.room_id = {$roomColumn}
                AND reservations.status IN " . AVAILABILITY_HOLDING_STATUSES . "
                AND reservations.check_in < ?
                AND reservations.check_out > ?
            )
            AND NOT EXISTS (
                SELECT 1 FROM room_blocks
                WHERE (room_blocks.room_id = {$roomColumn} OR room_blocks.room_id IS NULL)
                AND room_blocks.start_date < ?
                AND room_blocks.end_date >= ?
            )";
}

/*
 * Why a room can't be booked for a stay: "" when it can, otherwise a message for the guest.
 */
function room_unavailable_reason(PDO $pdo, int $roomId, string $checkIn, string $checkOut): string
{
    ensure_availability_schema($pdo);

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM reservations
         WHERE room_id = ? AND status IN " . AVAILABILITY_HOLDING_STATUSES . "
         AND check_in < ? AND check_out > ?"
    );
    $stmt->execute([$roomId, $checkOut, $checkIn]);

    if ((int) $stmt->fetchColumn() > 0) {
        return "Sorry, this room is already reserved for some of the selected dates.";
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM room_blocks
         WHERE (room_id = ? OR room_id IS NULL)
         AND start_date < ? AND end_date >= ?"
    );
    $stmt->execute([$roomId, $checkOut, $checkIn]);

    if ((int) $stmt->fetchColumn() > 0) {
        return "Sorry, the property is closed on some of the selected dates. Please choose other dates.";
    }

    return "";
}

/*
 * Nights that can't be booked for a room between $from and $to (Y-m-d, both included),
 * as a sorted list of Y-m-d strings. Used by the customer calendar.
 */
function room_unavailable_nights(PDO $pdo, int $roomId, string $from, string $to): array
{
    ensure_availability_schema($pdo);

    $nights = [];
    $fromDate = new DateTimeImmutable($from);
    $toDate = new DateTimeImmutable($to);

    $add = function (string $start, string $endExclusive) use (&$nights, $fromDate, $toDate) {
        $day = max(new DateTimeImmutable($start), $fromDate);
        $end = min(new DateTimeImmutable($endExclusive), $toDate->modify("+1 day"));

        for (; $day < $end; $day = $day->modify("+1 day")) {
            $nights[$day->format("Y-m-d")] = true;
        }
    };

    $stmt = $pdo->prepare(
        "SELECT check_in, check_out FROM reservations
         WHERE room_id = ? AND status IN " . AVAILABILITY_HOLDING_STATUSES . "
         AND check_in <= ? AND check_out > ?"
    );
    $stmt->execute([$roomId, $to, $from]);

    foreach ($stmt->fetchAll() as $row) {
        $add($row["check_in"], $row["check_out"]);
    }

    $stmt = $pdo->prepare(
        "SELECT start_date, end_date FROM room_blocks
         WHERE (room_id = ? OR room_id IS NULL)
         AND start_date <= ? AND end_date >= ?"
    );
    $stmt->execute([$roomId, $to, $from]);

    foreach ($stmt->fetchAll() as $row) {
        $add($row["start_date"], (new DateTimeImmutable($row["end_date"]))->modify("+1 day")->format("Y-m-d"));
    }

    $list = array_keys($nights);
    sort($list);

    return $list;
}
