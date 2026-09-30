<?php
/*
 * Booking tickets: a code and a QR code for each confirmed reservation, and checking them.
 *
 * The code looks like ARV-000032-7F3A9C1B: the reservation's number and a signature made with a
 * secret of this site (site_settings "ticket_secret"), so nobody can make up a code that passes.
 * The QR code on the guest's ticket (customer/ticket.php) holds the address of the admin's
 * scanner with the code in it:
 *     https://arveshouse.great-site.net/admin/scan.php?code=ARV-000032-7F3A9C1B
 * A phone's camera opens that address; the scanner page (admin/scan.php) reads the QR too.
 */

require_once __DIR__ . "/admin.php";      // site_setting()
require_once __DIR__ . "/paymongo.php";   // site_url()

// Only a confirmed (or completed) stay has a ticket.
function ticket_available(array $reservation): bool
{
    return in_array($reservation["status"] ?? "", ["confirmed", "completed"], true);
}

// The site's secret for signing codes, made once and kept (never shown anywhere).
function ticket_secret(PDO $pdo): string
{
    static $secret = null;

    if ($secret !== null) {
        return $secret;
    }

    $secret = site_setting($pdo, "ticket_secret", "");

    if (!preg_match('/^[0-9a-f]{64}$/', $secret)) {
        // INSERT IGNORE: when two pages make one at the same moment, the first one stays
        $pdo->prepare("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('ticket_secret', ?)")
            ->execute([bin2hex(random_bytes(32))]);

        $secret = site_setting($pdo, "ticket_secret", "");
    }

    return $secret;
}

function ticket_signature(PDO $pdo, int $reservationId): string
{
    return strtoupper(substr(hash_hmac("sha256", "ticket:" . $reservationId, ticket_secret($pdo)), 0, 8));
}

// "ARV-000032-7F3A9C1B"
function ticket_code(PDO $pdo, int $reservationId): string
{
    return "ARV-" . str_pad((string) $reservationId, 6, "0", STR_PAD_LEFT) . "-" . ticket_signature($pdo, $reservationId);
}

// What the QR code holds: the scanner's address with the code.
function ticket_url(PDO $pdo, int $reservationId): string
{
    return site_url("admin/scan.php?code=" . ticket_code($pdo, $reservationId));
}

/*
 * The reservation number in a code, or in a scanned address that holds one. 0 when the text has
 * no code or the signature does not match (a code that was made up or mistyped).
 */
function ticket_reservation_id(PDO $pdo, string $text): int
{
    if (!preg_match('/ARV-?(\d{1,9})-?([0-9A-F]{8})\b/i', $text, $m)) {
        return 0;
    }

    $id = (int) $m[1];

    return $id > 0 && hash_equals(ticket_signature($pdo, $id), strtoupper($m[2])) ? $id : 0;
}
