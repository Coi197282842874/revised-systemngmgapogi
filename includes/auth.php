<?php
/*
 * Shared authentication helpers for ARVE'S House.
 *
 *  - Settings for Google sign-in and outgoing mail (config/auth.php)
 *  - Schema for email verification + Google accounts (ensure_auth_schema)
 *  - Safe redirects, session login, CSRF tokens
 *  - 6-digit email verification codes (issue / check / send)
 *  - Google OAuth 2.0 / OpenID Connect (authorization URL, callback handling)
 *
 * Pages require config/database.php first, then this file, then call ensure_auth_schema($pdo)
 * if they touch users. The migration lives here (not in config/database.php) so it ships
 * with the code even on hosts where config/database.php is kept separately.
 */

const VERIFY_CODE_TTL = 900;            // a code is valid for 15 minutes
const VERIFY_MAX_ATTEMPTS = 5;          // wrong guesses before a new code is required
const VERIFY_RESEND_COOLDOWN = 60;      // seconds between emails
const VERIFY_MAX_SENDS_PER_HOUR = 5;
const GOOGLE_OAUTH_MAX_AGE = 600;       // seconds a sign-in attempt may take

const GOOGLE_AUTH_ENDPOINT = "https://accounts.google.com/o/oauth2/v2/auth";
const GOOGLE_TOKEN_ENDPOINT = "https://oauth2.googleapis.com/token";


// ======================================================
// SETTINGS
// ======================================================

function auth_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $defaults = [
        "google" => [
            "client_id" => "",
            "client_secret" => "",
            "redirect_uri" => "",   // empty: worked out from the current URL
        ],
        "mail" => [
            "host" => "smtp.gmail.com",
            "port" => 587,
            "encryption" => "tls",  // "tls" (STARTTLS, port 587) or "ssl" (port 465)
            "username" => "",
            "password" => "",       // Gmail: a 16-character App Password
            "from_email" => "",     // empty: same as username
            "from_name" => "ARVE'S House",
        ],
        "paymongo" => [
            "secret_key" => "",     // sk_test_... or sk_live_...; empty hides "Pay Online"
        ],
        "booking" => [
            "unpaid_hold_hours" => 24,  // a reservation with no payment is cancelled after this long; 0 = never
        ],
        "notifications" => [
            "enabled" => true,      // false: no booking / payment / message emails at all
            "admin_email" => "",    // where the owner's notifications go; empty: the mail username
        ],
        "business" => [             // shown on receipts and to search engines; empty fields are left out
            "name" => "ARVE'S House",
            "tagline" => "Your Home Away From Home",
            "phone" => "",
            "email" => "",
            "street" => "",
            "city" => "",
            "province" => "",
            "postal_code" => "",
            "country" => "PH",
            "map_url" => "",        // Google Maps link
            "facebook_url" => "",
        ],
    ];

    $file = getenv("ARVES_AUTH_CONFIG") ?: __DIR__ . "/../config/auth.php";
    $loaded = is_file($file) ? require $file : [];

    $config = array_replace_recursive($defaults, is_array($loaded) ? $loaded : []);

    return $config;
}

function google_enabled(): bool
{
    $google = auth_config()["google"];

    return trim($google["client_id"]) !== "" && trim($google["client_secret"]) !== "";
}

function mail_enabled(): bool
{
    $mail = auth_config()["mail"];

    return trim($mail["username"]) !== "" && trim($mail["password"]) !== "";
}


// ======================================================
// SCHEMA
// ======================================================

function ensure_auth_schema(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    $hasColumn = function (string $table, string $column) use ($pdo): bool {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = ?
             AND COLUMN_NAME = ?"
        );
        $stmt->execute([$table, $column]);

        return (bool) $stmt->fetchColumn();
    };

    // A concurrent request may add the same column first; MySQL then reports
    // 1060 (duplicate column) / 1061 (duplicate key), which is fine to ignore.
    $alter = function (string $sql) use ($pdo): bool {
        try {
            $pdo->exec($sql);
            return true;
        } catch (PDOException $e) {
            if (in_array((int) ($e->errorInfo[1] ?? 0), [1060, 1061], true)) {
                return false;
            }
            throw $e;
        }
    };

    if (!$hasColumn("users", "email_verified")) {
        $added = $alter(
            "ALTER TABLE users
             ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER email,
             ADD COLUMN email_verified_at DATETIME NULL AFTER email_verified"
        );

        // Accounts created before verification existed keep working as before.
        if ($added) {
            $pdo->exec("UPDATE users SET email_verified = 1, email_verified_at = UTC_TIMESTAMP()");
        }
    }

    // When the customer accepted the Terms and Conditions at registration (NULL: older accounts).
    if (!$hasColumn("users", "terms_accepted_at")) {
        $alter("ALTER TABLE users ADD COLUMN terms_accepted_at DATETIME NULL AFTER email_verified_at");
    }

    // When the guest accepted the Terms and Conditions for this booking (NULL: older bookings).
    if (!$hasColumn("reservations", "terms_accepted_at")) {
        $alter("ALTER TABLE reservations ADD COLUMN terms_accepted_at DATETIME NULL AFTER status");
    }

    if (!$hasColumn("users", "google_id")) {
        $alter(
            "ALTER TABLE users
             ADD COLUMN google_id VARCHAR(64) NULL AFTER email_verified_at,
             ADD UNIQUE KEY users_google_id_unique (google_id)"
        );
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS email_verifications (
            user_id INT UNSIGNED NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            send_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
            window_started_at DATETIME NOT NULL,
            last_sent_at DATETIME NOT NULL,
            PRIMARY KEY (user_id),
            CONSTRAINT email_verifications_user_fk
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}


// ======================================================
// REDIRECTS, SESSION LOGIN, CSRF
// ======================================================

// Relative paths inside the site only: no scheme ("javascript:", "https:"),
// no protocol-relative or backslash tricks ("//evil", "/\evil"), no control chars.
function safe_redirect(string $redirect): string
{
    $redirect = trim($redirect);

    if (
        $redirect === ""
        || preg_match('#^[a-z][a-z0-9+.\-]*:#i', $redirect)
        || preg_match('#^[/\\\\]#', $redirect)
        || strpos($redirect, "\\") !== false
        || preg_match('#[\x00-\x1F\x7F]#', $redirect)
    ) {
        return "";
    }

    return $redirect;
}

function destination_for(array $user, string $redirect = ""): string
{
    if ($user["role"] === "admin") {
        return "admin/dashboard.php";
    }

    if ($user["role"] === "customer") {
        return safe_redirect($redirect) ?: "customer/dashboard.php";
    }

    return "index.php";
}

function login_user(array $user): void
{
    // prevent session fixation
    session_regenerate_id(true);

    $_SESSION["user_id"] = $user["id"];
    $_SESSION["full_name"] = $user["full_name"];
    $_SESSION["email"] = $user["email"];
    $_SESSION["role"] = $user["role"];

    unset($_SESSION["pending_verification_user_id"], $_SESSION["pending_redirect"]);
}

function csrf_token(): string
{
    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }

    return $_SESSION["csrf_token"];
}

function csrf_valid($token): bool
{
    return is_string($token)
        && !empty($_SESSION["csrf_token"])
        && hash_equals($_SESSION["csrf_token"], $token);
}

function mask_email(string $email): string
{
    $at = strrpos($email, "@");

    if ($at === false) {
        return $email;
    }

    $name = substr($email, 0, $at);
    $visible = mb_substr($name, 0, min(2, max(1, mb_strlen($name) - 1)));

    return $visible . str_repeat("•", max(1, min(6, mb_strlen($name) - mb_strlen($visible)))) . substr($email, $at);
}


// ======================================================
// EMAIL VERIFICATION CODES
// ======================================================

function utc_now(): string
{
    return gmdate("Y-m-d H:i:s");
}

function utc_ts(string $datetime): int
{
    return (int) strtotime($datetime . " UTC");
}

/*
 * Creates (or replaces) the user's code and emails it.
 * $onlyIfNeeded: keep a still-valid code instead of sending another (used on login).
 * Returns ["ok" => bool, "sent" => bool, "error" => string, "retry_after" => int].
 */
function issue_verification_code(PDO $pdo, array $user, bool $onlyIfNeeded = false): array
{
    $now = time();

    $stmt = $pdo->prepare("SELECT * FROM email_verifications WHERE user_id = ?");
    $stmt->execute([$user["id"]]);
    $row = $stmt->fetch();

    if ($row) {
        $sinceLast = $now - utc_ts($row["last_sent_at"]);
        $attempts = (int) $row["attempts"];

        if (
            $onlyIfNeeded
            && utc_ts($row["expires_at"]) > $now + 60
            && $attempts < VERIFY_MAX_ATTEMPTS
        ) {
            return ["ok" => true, "sent" => false, "error" => "", "retry_after" => max(0, VERIFY_RESEND_COOLDOWN - $sinceLast)];
        }

        if ($sinceLast < VERIFY_RESEND_COOLDOWN) {
            $wait = VERIFY_RESEND_COOLDOWN - $sinceLast;
            return ["ok" => false, "sent" => false, "error" => "Please wait {$wait} seconds before requesting another code.", "retry_after" => $wait];
        }

        $windowAge = $now - utc_ts($row["window_started_at"]);

        if ($windowAge < 3600 && (int) $row["send_count"] >= VERIFY_MAX_SENDS_PER_HOUR) {
            $minutes = (int) ceil((3600 - $windowAge) / 60);
            return ["ok" => false, "sent" => false, "error" => "Too many codes requested. Please try again in {$minutes} minute" . ($minutes === 1 ? "" : "s") . ".", "retry_after" => 3600 - $windowAge];
        }

        $windowStart = $windowAge >= 3600 ? utc_now() : $row["window_started_at"];
        $sendCount = $windowAge >= 3600 ? 1 : (int) $row["send_count"] + 1;
    } else {
        $windowStart = utc_now();
        $sendCount = 1;
    }

    $code = str_pad((string) random_int(0, 999999), 6, "0", STR_PAD_LEFT);

    $pdo->prepare(
        "REPLACE INTO email_verifications
            (user_id, code_hash, expires_at, attempts, send_count, window_started_at, last_sent_at)
         VALUES (?, ?, ?, 0, ?, ?, ?)"
    )->execute([
        $user["id"],
        password_hash($code, PASSWORD_DEFAULT),
        gmdate("Y-m-d H:i:s", $now + VERIFY_CODE_TTL),
        $sendCount,
        $windowStart,
        utc_now(),
    ]);

    if (!send_verification_email($user, $code)) {
        return ["ok" => false, "sent" => false, "error" => "We couldn't send the verification email right now. Please try “Resend code” in a minute.", "retry_after" => VERIFY_RESEND_COOLDOWN];
    }

    return ["ok" => true, "sent" => true, "error" => "", "retry_after" => VERIFY_RESEND_COOLDOWN];
}

/*
 * Checks a code; on success marks the email verified and removes the code.
 * Returns ["ok" => bool, "error" => string].
 */
function check_verification_code(PDO $pdo, int $userId, string $input): array
{
    $code = preg_replace('/\D+/', "", $input);

    if (strlen($code) !== 6) {
        return ["ok" => false, "error" => "Enter the 6-digit code from the email."];
    }

    $pdo->beginTransaction();

    try {
        // lock the row so parallel guesses can't exceed the attempt limit
        $stmt = $pdo->prepare("SELECT * FROM email_verifications WHERE user_id = ? FOR UPDATE");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row) {
            $pdo->commit();
            return ["ok" => false, "error" => "There's no active code for this account. Tap “Resend code” to get a new one."];
        }

        if ((int) $row["attempts"] >= VERIFY_MAX_ATTEMPTS) {
            $pdo->commit();
            return ["ok" => false, "error" => "Too many incorrect attempts. Tap “Resend code” to get a new code."];
        }

        if (utc_ts($row["expires_at"]) < time()) {
            $pdo->commit();
            return ["ok" => false, "error" => "This code has expired. Tap “Resend code” to get a new one."];
        }

        if (!password_verify($code, $row["code_hash"])) {
            $pdo->prepare("UPDATE email_verifications SET attempts = attempts + 1 WHERE user_id = ?")
                ->execute([$userId]);
            $pdo->commit();

            $left = VERIFY_MAX_ATTEMPTS - (int) $row["attempts"] - 1;

            return ["ok" => false, "error" => $left > 0
                ? "That code isn't right. {$left} attempt" . ($left === 1 ? "" : "s") . " left."
                : "Too many incorrect attempts. Tap “Resend code” to get a new code."];
        }

        $pdo->prepare("UPDATE users SET email_verified = 1, email_verified_at = ? WHERE id = ?")
            ->execute([utc_now(), $userId]);
        $pdo->prepare("DELETE FROM email_verifications WHERE user_id = ?")
            ->execute([$userId]);
        $pdo->commit();

        return ["ok" => true, "error" => ""];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function send_verification_email(array $user, string $code): bool
{
    $name = trim($user["full_name"] ?? "") ?: "there";
    $minutes = (int) (VERIFY_CODE_TTL / 60);
    $subject = "{$code} is your ARVE'S House verification code";

    $text = "Hi {$name},\n\n"
        . "Your ARVE'S House verification code is: {$code}\n\n"
        . "Enter it on the verification page to confirm your email. It expires in {$minutes} minutes.\n\n"
        . "If you didn't create an account, you can ignore this email.\n\n"
        . "— ARVE'S House";

    if (!mail_enabled()) {
        $host = strtolower($_SERVER["HTTP_HOST"] ?? "localhost");
        if (!preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/', $host)) {
            // Live site without mail settings: fail loudly instead of pretending the code was sent.
            error_log("ARVE'S House: mail is not configured in config/auth.php; verification email not sent.");
            return false;
        }

        // Mail isn't configured yet (config/auth.php). For local testing the message is written to a
        // log OUTSIDE the web root; it is never shown on a page.
        error_log(
            "[" . gmdate("c") . "] To: {$user["email"]}\nSubject: {$subject}\n\n{$text}\n\n",
            3,
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . "arves-house-mail.log"
        );
        return true;
    }

    require_once __DIR__ . "/../lib/PHPMailer/Exception.php";
    require_once __DIR__ . "/../lib/PHPMailer/PHPMailer.php";
    require_once __DIR__ . "/../lib/PHPMailer/SMTP.php";

    $settings = auth_config()["mail"];
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $settings["host"];
        $mail->Port = (int) $settings["port"];
        $mail->SMTPAuth = true;
        $mail->Username = $settings["username"];
        $mail->Password = $settings["password"];
        $mail->SMTPSecure = $settings["encryption"] === "ssl"
            ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Timeout = 15;
        $mail->CharSet = "UTF-8";

        $mail->setFrom($settings["from_email"] ?: $settings["username"], $settings["from_name"]);
        $mail->addAddress($user["email"], $user["full_name"] ?? "");

        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = verification_email_html($name, $code, $minutes);
        $mail->AltBody = $text;

        $mail->send();

        return true;
    } catch (Throwable $e) {
        error_log("ARVE'S House verification email failed: " . $mail->ErrorInfo);
        return false;
    }
}

function verification_email_html(string $name, string $code, int $minutes): string
{
    $name = htmlspecialchars($name, ENT_QUOTES, "UTF-8");
    $digits = htmlspecialchars(implode(" ", str_split($code)), ENT_QUOTES, "UTF-8");

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<body style="margin:0;padding:0;background:#f4efe8;font-family:Arial,Helvetica,sans-serif;color:#241a15;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4efe8;padding:32px 16px;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background:#ffffff;border-radius:16px;padding:32px;">
        <tr><td style="font-size:14px;font-weight:bold;color:#6f4e37;letter-spacing:0.04em;">ARVE'S <span style="color:#b7813f;">House</span></td></tr>
        <tr><td style="padding-top:20px;font-size:22px;font-weight:bold;">Confirm your email</td></tr>
        <tr><td style="padding-top:12px;font-size:15px;line-height:1.6;color:#4a3f39;">Hi {$name}, use this code to verify your email address:</td></tr>
        <tr><td align="center" style="padding:24px 0;">
          <div style="display:inline-block;padding:14px 24px;border-radius:12px;background:#fbf4ea;font-size:32px;font-weight:bold;letter-spacing:6px;color:#241a15;">{$digits}</div>
        </td></tr>
        <tr><td style="font-size:14px;line-height:1.6;color:#6b5f58;">The code expires in {$minutes} minutes. If you didn't create an ARVE'S House account, you can ignore this email.</td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}


// ======================================================
// GOOGLE SIGN-IN (OAuth 2.0 + OpenID Connect)
// ======================================================

function google_redirect_uri(): string
{
    $configured = trim(auth_config()["google"]["redirect_uri"]);

    if ($configured !== "") {
        return $configured;
    }

    $host = strtolower($_SERVER["HTTP_HOST"] ?? "localhost");

    // Google only accepts https redirect URIs outside localhost, and some hosts (e.g. InfinityFree)
    // terminate SSL before PHP, so the request itself can't be trusted to report https.
    $https = !preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/', $host)
        || (!empty($_SERVER["HTTPS"]) && strtolower($_SERVER["HTTPS"]) !== "off")
        || strtolower($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "") === "https"
        || (int) ($_SERVER["SERVER_PORT"] ?? 80) === 443;

    $dir = rtrim(str_replace("\\", "/", dirname($_SERVER["SCRIPT_NAME"] ?? "/")), "/");

    return ($https ? "https" : "http") . "://" . ($_SERVER["HTTP_HOST"] ?? "localhost") . $dir . "/google-callback.php";
}

// Starts a sign-in attempt and returns the Google URL to send the browser to.
function google_authorization_url(string $redirect = ""): string
{
    $state = bin2hex(random_bytes(16));
    $nonce = bin2hex(random_bytes(16));

    $_SESSION["google_oauth"] = [
        "state" => $state,
        "nonce" => $nonce,
        "redirect" => safe_redirect($redirect),
        "created" => time(),
    ];

    return GOOGLE_AUTH_ENDPOINT . "?" . http_build_query([
        "client_id" => auth_config()["google"]["client_id"],
        "redirect_uri" => google_redirect_uri(),
        "response_type" => "code",
        "scope" => "openid email profile",
        "state" => $state,
        "nonce" => $nonce,
        "prompt" => "select_account",
    ]);
}

// Exchanges the authorization code for tokens at Google's token endpoint (HTTPS only).
function google_exchange_code(string $code): array
{
    $google = auth_config()["google"];
    $curl = curl_init(GOOGLE_TOKEN_ENDPOINT);

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            "code" => $code,
            "client_id" => $google["client_id"],
            "client_secret" => $google["client_secret"],
            "redirect_uri" => google_redirect_uri(),
            "grant_type" => "authorization_code",
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ["Accept: application/json"],
    ]);

    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);

    if ($body === false) {
        error_log("Google token exchange failed: " . $error);
        return [];
    }

    $data = json_decode($body, true);

    if ($status !== 200 || !is_array($data)) {
        error_log("Google token exchange HTTP {$status}: " . substr((string) $body, 0, 300));
        return [];
    }

    return $data;
}

function base64url_decode(string $value): string
{
    $value = strtr($value, "-_", "+/");

    return (string) base64_decode($value . str_repeat("=", (4 - strlen($value) % 4) % 4), true);
}

/*
 * Validates the ID token's claims. The token comes straight from Google's token endpoint over
 * verified TLS, so per OpenID Connect Core §3.1.3.7 TLS stands in for the signature check;
 * issuer, audience, expiry, nonce and email_verified are still enforced here.
 * Returns the claims, or [] if anything is off.
 */
function google_id_token_claims(string $idToken, string $expectedNonce): array
{
    $parts = explode(".", $idToken);

    if (count($parts) !== 3) {
        return [];
    }

    $claims = json_decode(base64url_decode($parts[1]), true);

    if (!is_array($claims)) {
        return [];
    }

    $clientId = auth_config()["google"]["client_id"];
    $audience = (array) ($claims["aud"] ?? []);
    $emailVerified = ($claims["email_verified"] ?? false) === true || ($claims["email_verified"] ?? "") === "true";

    if (
        !in_array($claims["iss"] ?? "", ["https://accounts.google.com", "accounts.google.com"], true)
        || !in_array($clientId, $audience, true)
        || (int) ($claims["exp"] ?? 0) < time() - 60
        || !hash_equals($expectedNonce, (string) ($claims["nonce"] ?? ""))
        || empty($claims["sub"])
        || empty($claims["email"])
        || !$emailVerified
    ) {
        return [];
    }

    return $claims;
}

/*
 * Finishes a Google sign-in. $exchange turns the authorization code into Google's token response
 * (google_exchange_code in production). Returns ["ok" => true, "user" => row, "redirect" => string]
 * or ["ok" => false, "error" => message for the login page].
 */
function google_handle_callback(PDO $pdo, array $query, ?array $attempt, callable $exchange): array
{
    $fail = function (string $message): array {
        return ["ok" => false, "error" => $message];
    };

    if (!$attempt || empty($attempt["state"])) {
        return $fail("Your Google sign-in session expired. Please try again.");
    }

    if (isset($query["error"])) {
        return $fail($query["error"] === "access_denied"
            ? "Google sign-in was cancelled."
            : "Google sign-in didn't complete. Please try again.");
    }

    if (
        !hash_equals($attempt["state"], (string) ($query["state"] ?? ""))
        || time() - (int) $attempt["created"] > GOOGLE_OAUTH_MAX_AGE
        || empty($query["code"])
    ) {
        return $fail("Your Google sign-in session expired. Please try again.");
    }

    $tokens = $exchange((string) $query["code"]);
    $claims = isset($tokens["id_token"]) ? google_id_token_claims((string) $tokens["id_token"], $attempt["nonce"]) : [];

    if (!$claims) {
        return $fail("We couldn't confirm your Google account. Please try again.");
    }

    $googleId = (string) $claims["sub"];
    $email = trim((string) $claims["email"]);
    $name = trim((string) ($claims["name"] ?? "")) ?: strstr($email, "@", true);

    $select = "SELECT id, full_name, email, role, status, email_verified, google_id FROM users";

    // 1. Returning Google user
    $stmt = $pdo->prepare("{$select} WHERE google_id = ? LIMIT 1");
    $stmt->execute([$googleId]);
    $user = $stmt->fetch();

    if (!$user) {
        // 2. Existing account with the same email
        $stmt = $pdo->prepare("{$select} WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            if ($user["role"] !== "customer") {
                return $fail("This email belongs to an administrator account. Please use the admin login.");
            }

            if (!empty($user["google_id"]) && $user["google_id"] !== $googleId) {
                return $fail("This email is already linked to a different Google account.");
            }

            if ((int) $user["email_verified"] === 1) {
                $pdo->prepare("UPDATE users SET google_id = ? WHERE id = ?")
                    ->execute([$googleId, $user["id"]]);
            } else {
                // The email was never confirmed, so the existing password may have been set by someone
                // else (account pre-hijacking). Google has now proven who owns the address: link it and
                // retire that password. The owner signs in with Google from now on.
                $pdo->prepare(
                    "UPDATE users
                     SET google_id = ?, email_verified = 1, email_verified_at = ?, password = ?
                     WHERE id = ?"
                )->execute([$googleId, utc_now(), password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $user["id"]]);

                $pdo->prepare("DELETE FROM email_verifications WHERE user_id = ?")->execute([$user["id"]]);
            }
        } else {
            // 3. New customer. Google accounts have no phone number; it stays empty until they add one.
            try {
                $pdo->prepare(
                    "INSERT INTO users
                        (full_name, email, phone, password, role, status, email_verified, email_verified_at, terms_accepted_at, google_id)
                     VALUES (?, ?, '', ?, 'customer', 'active', 1, ?, ?, ?)"
                )->execute([
                    mb_substr($name, 0, 150),
                    $email,
                    password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                    utc_now(),
                    utc_now(), // the Google button says continuing means agreeing to the Terms
                    $googleId,
                ]);
            } catch (PDOException $e) {
                // someone registered the same email a moment ago
                return $fail("Please try signing in with Google again.");
            }

            $stmt = $pdo->prepare("{$select} WHERE google_id = ? LIMIT 1");
            $stmt->execute([$googleId]);
            $user = $stmt->fetch();
        }
    }

    if (!$user) {
        return $fail("We couldn't sign you in with Google. Please try again.");
    }

    if ($user["role"] !== "customer") {
        return $fail("This email belongs to an administrator account. Please use the admin login.");
    }

    if ($user["status"] !== "active") {
        return $fail("Your account is inactive.");
    }

    return ["ok" => true, "user" => $user, "redirect" => (string) ($attempt["redirect"] ?? "")];
}
