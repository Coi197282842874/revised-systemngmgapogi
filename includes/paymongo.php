<?php
/*
 * PayMongo online payments (GCash, Maya, GrabPay, cards) through PayMongo Checkout.
 *
 * Flow: payment.php creates a pending "online" payment and a Checkout Session, and sends the
 * customer to PayMongo. When they come back (payment-return.php), or later when the payment,
 * dashboard or admin page loads, paymongo_settle() asks PayMongo whether the session was paid
 * and marks the payment verified + the reservation confirmed, exactly like an admin would.
 *
 * No webhooks: the free host (InfinityFree) blocks incoming server-to-server requests, but
 * outgoing requests to PayMongo work.
 *
 * Keys go in config/auth.php → "paymongo" (dashboard.paymongo.com → Developers → API Keys).
 * sk_test_... = test mode (no real money), sk_live_... = real payments.
 */

require_once __DIR__ . "/auth.php";

const PAYMONGO_API = "https://api.paymongo.com/v1";
const PAYMONGO_METHODS = ["gcash", "paymaya", "grab_pay", "card"];

function paymongo_secret(): string
{
    return trim((string) (auth_config()["paymongo"]["secret_key"] ?? ""));
}

function paymongo_enabled(): bool
{
    return (bool) preg_match('/^sk_(test|live)_[A-Za-z0-9]+$/', paymongo_secret());
}

function paymongo_test_mode(): bool
{
    return str_starts_with(paymongo_secret(), "sk_test_");
}

// Remembers which PayMongo Checkout Session belongs to a payment.
function ensure_paymongo_schema(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    $exists = $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'checkout_session_id'"
    )->fetchColumn();

    if (!$exists) {
        try {
            $pdo->exec("ALTER TABLE payments ADD COLUMN checkout_session_id VARCHAR(64) NULL AFTER reference_number");
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1060) { // added by a parallel request
                throw $e;
            }
        }
    }

    // Older databases made these ENUMs without "online_*" methods or the "cancelled" status;
    // MySQL would silently store "" instead. Widen them to text (existing values stay).
    $types = $pdo->query(
        "SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments'
         AND COLUMN_NAME IN ('payment_method', 'status')"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    if (($types["payment_method"] ?? "") === "enum") {
        $pdo->exec("ALTER TABLE payments MODIFY payment_method VARCHAR(30) NOT NULL");
    }

    if (($types["status"] ?? "") === "enum") {
        $pdo->exec("ALTER TABLE payments MODIFY status VARCHAR(20) NOT NULL DEFAULT 'pending'");
    }
}

/*
 * Calls the PayMongo API. Returns [HTTP status, decoded JSON]; status 0 means no connection.
 */
function paymongo_request(string $method, string $path, ?array $body = null): array
{
    $ch = curl_init(PAYMONGO_API . $path);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_USERPWD => paymongo_secret() . ":",
        CURLOPT_HTTPHEADER => ["Accept: application/json", "Content-Type: application/json"],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $failure = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        error_log("PayMongo {$method} {$path} failed: {$failure}");
        return [0, []];
    }

    $json = json_decode($raw, true);

    if ($status >= 400) {
        error_log("PayMongo {$method} {$path} → {$status}: " . substr((string) $raw, 0, 500));
    }

    return [$status, is_array($json) ? $json : []];
}

// Absolute URL of a file in the site's root folder (https outside localhost).
function site_url(string $file): string
{
    $host = strtolower($_SERVER["HTTP_HOST"] ?? "localhost");
    $isLocal = (bool) preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/', $host);
    $https = !$isLocal || (!empty($_SERVER["HTTPS"]) && strtolower($_SERVER["HTTPS"]) !== "off");

    $dir = rtrim(str_replace("\\", "/", dirname($_SERVER["SCRIPT_NAME"] ?? "/")), "/");
    $dir = preg_replace('#/(customer|admin)$#', "", $dir); // called from a sub-folder page

    return ($https ? "https" : "http") . "://" . $host . $dir . "/" . ltrim($file, "/");
}

/*
 * Creates a Checkout Session for a reservation.
 * Returns ["ok" => true, "session_id" => ..., "checkout_url" => ...] or ["ok" => false, "error" => ...].
 */
function paymongo_create_checkout(array $reservation, array $customer, int $paymentId): array
{
    $centavos = (int) round((float) $reservation["total_amount"] * 100);

    if ($centavos < 2000) {
        return ["ok" => false, "error" => "Online payment needs an amount of at least ₱20.00."];
    }

    $nights = (int) ($reservation["total_nights"] ?? 1);
    $name = trim((string) ($reservation["room_name"] ?? "Room")) . " · " . $nights . " night" . ($nights === 1 ? "" : "s");

    $billing = array_filter([
        "name" => trim((string) ($customer["full_name"] ?? "")),
        "email" => trim((string) ($customer["email"] ?? "")),
        "phone" => trim((string) ($customer["phone"] ?? "")),
    ]);

    [$status, $json] = paymongo_request("POST", "/checkout_sessions", [
        "data" => [
            "attributes" => [
                "line_items" => [[
                    "currency" => "PHP",
                    "amount" => $centavos,
                    "name" => mb_substr($name, 0, 250),
                    "quantity" => 1,
                ]],
                "payment_method_types" => PAYMONGO_METHODS,
                "description" => "ARVE'S House reservation #" . (int) $reservation["id"]
                    . " (" . $reservation["check_in"] . " to " . $reservation["check_out"] . ")",
                "reference_number" => "RES" . (int) $reservation["id"] . "-P" . $paymentId,
                "success_url" => site_url("payment-return.php?payment=" . $paymentId),
                "cancel_url" => site_url("payment-return.php?payment=" . $paymentId . "&cancelled=1"),
                "send_email_receipt" => true,
                "show_description" => true,
                "show_line_items" => true,
                "billing" => $billing ?: null,
                "metadata" => [
                    "reservation_id" => (string) (int) $reservation["id"],
                    "payment_id" => (string) $paymentId,
                ],
            ],
        ],
    ]);

    $id = $json["data"]["id"] ?? "";
    $url = $json["data"]["attributes"]["checkout_url"] ?? "";

    if ($status !== 200 || $id === "" || $url === "") {
        $detail = $json["errors"][0]["detail"] ?? "";

        return [
            "ok" => false,
            "error" => "We couldn't start the online payment right now. Please try again or choose another method."
                . ($detail !== "" && paymongo_test_mode() ? " (PayMongo: {$detail})" : ""),
        ];
    }

    return ["ok" => true, "session_id" => $id, "checkout_url" => $url];
}

/*
 * What PayMongo says about a Checkout Session.
 * Returns ["paid" => bool, "payment_ref" => "pay_...", "method" => "gcash", "active" => bool,
 *          "checkout_url" => ...] or null if PayMongo couldn't be reached.
 */
function paymongo_session_status(string $sessionId): ?array
{
    if (!preg_match('/^cs_[A-Za-z0-9]+$/', $sessionId)) {
        return null;
    }

    [$status, $json] = paymongo_request("GET", "/checkout_sessions/" . $sessionId);

    if ($status !== 200) {
        return null;
    }

    $attributes = $json["data"]["attributes"] ?? [];
    $result = [
        "paid" => false,
        "payment_ref" => "",
        "method" => "",
        "active" => ($attributes["status"] ?? "") === "active",
        "checkout_url" => $attributes["checkout_url"] ?? "",
    ];

    foreach ($attributes["payments"] ?? [] as $payment) {
        if (($payment["attributes"]["status"] ?? "") === "paid") {
            $result["paid"] = true;
            $result["payment_ref"] = (string) ($payment["id"] ?? "");
            $result["method"] = (string) ($payment["attributes"]["source"]["type"] ?? "");
            break;
        }
    }

    if (!$result["paid"] && ($attributes["payment_intent"]["attributes"]["status"] ?? "") === "succeeded") {
        $result["paid"] = true;
    }

    return $result;
}

/*
 * Brings one pending online payment up to date with PayMongo.
 * Returns "verified", "pending" (not paid yet / PayMongo unreachable) or "cancelled".
 * $cancelIfUnpaid: the customer backed out, so expire the session and cancel the attempt.
 */
function paymongo_settle(PDO $pdo, array $payment, bool $cancelIfUnpaid = false): string
{
    if (($payment["payment_method"] ?? "") !== "online" && !str_starts_with((string) ($payment["payment_method"] ?? ""), "online_")) {
        return (string) $payment["status"];
    }

    if ($payment["status"] !== "pending" || empty($payment["checkout_session_id"]) || !paymongo_enabled()) {
        return (string) $payment["status"];
    }

    $session = paymongo_session_status($payment["checkout_session_id"]);

    if ($session === null) {
        return "pending";
    }

    if ($session["paid"]) {
        $pdo->beginTransaction();

        try {
            $method = in_array($session["method"], PAYMONGO_METHODS, true) ? "online_" . $session["method"] : "online";

            $update = $pdo->prepare(
                "UPDATE payments
                 SET status = 'verified', payment_method = ?, reference_number = ?
                 WHERE id = ? AND status = 'pending'"
            );
            $update->execute([$method, $session["payment_ref"] ?: $payment["checkout_session_id"], $payment["id"]]);

            if ($update->rowCount() > 0) {
                $pdo->prepare("UPDATE reservations SET status = 'confirmed' WHERE id = ? AND status = 'pending'")
                    ->execute([$payment["reservation_id"]]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log("PayMongo settle failed for payment #{$payment["id"]}: " . $e->getMessage());
            return "pending";
        }

        return "verified";
    }

    if ($cancelIfUnpaid || !$session["active"]) {
        if ($session["active"]) {
            paymongo_request("POST", "/checkout_sessions/" . $payment["checkout_session_id"] . "/expire");

            // paid in the moment between checking and expiring?
            $again = paymongo_session_status($payment["checkout_session_id"]);
            if ($again && $again["paid"]) {
                return paymongo_settle($pdo, $payment);
            }
        }

        $pdo->prepare("UPDATE payments SET status = 'cancelled' WHERE id = ? AND status = 'pending'")
            ->execute([$payment["id"]]);

        return "cancelled";
    }

    return "pending";
}

// Settles every pending online payment of one customer (or of everyone, for the admin page).
function paymongo_settle_pending(PDO $pdo, ?int $userId = null, int $limit = 10): void
{
    if (!paymongo_enabled()) {
        return;
    }

    ensure_paymongo_schema($pdo);

    $sql = "SELECT * FROM payments
            WHERE status = 'pending' AND payment_method = 'online' AND checkout_session_id IS NOT NULL"
        . ($userId !== null ? " AND user_id = ?" : "")
        . " ORDER BY id DESC LIMIT " . max(1, $limit);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($userId !== null ? [$userId] : []);

    foreach ($stmt->fetchAll() as $payment) {
        paymongo_settle($pdo, $payment);
    }
}

// Label for a payment method code, e.g. "online_gcash" → "Online · GCash".
function payment_method_label(string $method): string
{
    $names = [
        "online" => "Online (PayMongo)",
        "online_gcash" => "Online · GCash",
        "online_paymaya" => "Online · Maya",
        "online_grab_pay" => "Online · GrabPay",
        "online_card" => "Online · Card",
        "gcash" => "GCash",
        "cash" => "Cash",
        "pay_at_property" => "Pay at Property",
    ];

    return $names[$method] ?? ucwords(str_replace("_", " ", $method));
}
