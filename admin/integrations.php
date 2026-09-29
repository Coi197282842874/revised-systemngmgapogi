<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/admin-shell.php";
require_once __DIR__ . "/../includes/paymongo.php";

// ======================================================
// INTEGRATIONS: the outside services the site talks to
// Google sign-in, email (SMTP) and PayMongo. Their keys live in config/auth.php on the
// server; this page shows whether each one is connected and lets an admin test it.
// Secrets are never printed: a password or client secret not at all, a key at most by its
// last four characters, to tell two keys apart.
// ======================================================

$admin = admin_boot($pdo, "integrations");

const INTEGRATION_TEST_WAIT = 30;   // seconds between two tests of the same service

$config = auth_config();
$isLocal = (bool) preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/', strtolower($_SERVER["HTTP_HOST"] ?? "localhost"));

// "1234567890-abc…xyz.apps.googleusercontent.com": enough to recognize, not enough to copy
function integration_hint(string $value, int $start = 6, int $end = 4): string
{
    $value = trim($value);

    if ($value === "") {
        return "";
    }

    if (mb_strlen($value) <= $start + $end + 3) {
        return str_repeat("•", mb_strlen($value));
    }

    return mb_substr($value, 0, $start) . "…" . mb_substr($value, -$end);
}

// Where Google sends people back to (the site's root folder, not /admin/).
function integration_google_redirect(): string
{
    $configured = trim(auth_config()["google"]["redirect_uri"]);

    if ($configured !== "") {
        return $configured;
    }

    $host = $_SERVER["HTTP_HOST"] ?? "localhost";
    $local = preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/', strtolower($host));
    $root = rtrim(str_replace("\\", "/", dirname(dirname($_SERVER["SCRIPT_NAME"] ?? "/admin/x"))), "/");

    return ($local ? "http" : "https") . "://" . $host . $root . "/google-callback.php";
}

// Sends one short email through the site's own mail settings. Returns "" or what went wrong.
function integration_send_test_email(string $to, string $name): string
{
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
        $mail->addAddress($to, $name);

        $when = date("M j, Y · g:i A");

        $mail->Subject = "ARVE'S House: test email";
        $mail->isHTML(true);
        $mail->Body = '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#111827">'
            . '<p>Hi ' . htmlspecialchars($name) . ',</p>'
            . '<p>This is a test from the ARVE\'S House admin panel. If you are reading it, the site can send email.</p>'
            . '<p style="color:#6b7280;font-size:13px">Sent ' . htmlspecialchars($when) . ' (Philippine time) from Integrations.</p></div>';
        $mail->AltBody = "Hi {$name},\n\nThis is a test from the ARVE'S House admin panel. "
            . "If you are reading it, the site can send email.\n\nSent {$when} (Philippine time) from Integrations.";

        $mail->send();

        return "";
    } catch (Throwable $e) {
        error_log("ARVE'S House test email failed: " . $mail->ErrorInfo);

        // the mail server's own words help to fix it; they never contain the password
        return trim(preg_replace('/\s+/', " ", strip_tags((string) $mail->ErrorInfo))) ?: "The mail server did not accept the message.";
    }
}


// ======================================================
// TESTS
// ======================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $test = (string) ($_POST["test"] ?? "");
    $last = (int) ($_SESSION["integration_tested"][$test] ?? 0);

    if (!admin_check_csrf()) {
        admin_flash("Your session expired. Please try again.", "error");

    } elseif (time() - $last < INTEGRATION_TEST_WAIT) {
        admin_flash("Please wait " . (INTEGRATION_TEST_WAIT - (time() - $last)) . " seconds before testing again.", "error");

    } elseif ($test === "mail") {

        $_SESSION["integration_tested"][$test] = time();

        if (!mail_enabled()) {
            admin_flash("Email is not set up yet, so there is nothing to test.", "error");
        } else {
            $problem = integration_send_test_email($admin["email"], $admin["full_name"]);

            if ($problem === "") {
                admin_flash("Test email sent to " . $admin["email"] . ". It can take a minute to arrive; look in Spam too.");
            } else {
                admin_flash("The test email could not be sent: " . mb_substr($problem, 0, 200), "error");
            }
        }

    } elseif ($test === "paymongo") {

        $_SESSION["integration_tested"][$test] = time();

        if (!paymongo_enabled()) {
            admin_flash("PayMongo is not set up yet, so there is nothing to test.", "error");
        } else {
            [$status] = paymongo_request("GET", "/webhooks");

            if ($status === 200) {
                admin_flash("PayMongo answered and accepted the key (" . (paymongo_test_mode() ? "test mode" : "live mode") . ").");
            } elseif ($status === 401 || $status === 403) {
                admin_flash("PayMongo refused the key. Check the secret key in config/auth.php.", "error");
            } elseif ($status === 0) {
                admin_flash("PayMongo could not be reached from this server. Try again in a few minutes.", "error");
            } else {
                admin_flash("PayMongo answered with an unexpected code (" . $status . "). Try again in a few minutes.", "error");
            }
        }

    } else {
        admin_flash("Unknown test.", "error");
    }

    header("Location: integrations.php");
    exit;
}


// ======================================================
// WHAT IS CONNECTED
// ======================================================

$googleOn = google_enabled();
$mailOn = mail_enabled();
$payOn = paymongo_enabled();

$googleUsers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE google_id IS NOT NULL")->fetchColumn();

$online = $pdo->query(
    "SELECT COUNT(*) AS payments, COALESCE(SUM(amount), 0) AS amount
     FROM payments WHERE status = 'verified' AND payment_method LIKE 'online%'"
)->fetch();

$unverified = (int) $pdo->query(
    "SELECT COUNT(*) FROM users WHERE role = 'customer' AND email_verified = 0"
)->fetchColumn();

$connected = (int) $googleOn + (int) $mailOn + (int) $payOn;

admin_shell_head([
    "title" => "Integrations",
    "subtitle" => $connected . " of 3 outside services connected",
    "active" => "integrations",
]);
?>
<style>
    .integrations {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 20px;
        align-items: start;
    }

    .integration-head {
        display: flex;
        align-items: flex-start;
        gap: 14px;
        padding: 20px 20px 14px;
    }

    .integration-head .tile-icon {
        width: 46px;
        height: 46px;
        border-radius: 13px;
        font-size: 22px;
    }

    .integration-head h2 {
        font-size: 16px;
        font-weight: 700;
        letter-spacing: -0.01em;
    }

    .integration-head p {
        margin-top: 2px;
        color: var(--text-2);
        font-size: 13px;
    }

    .integration-title {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 6px 10px;
    }

    .integration .kv {
        grid-template-columns: minmax(96px, 36%) minmax(0, 1fr);
    }

    .integration-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        padding: 14px 20px 18px;
    }

    .integration .faq {
        border-top: 1px solid var(--line);
    }

    @media (max-width: 1280px) {
        .integrations {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 860px) {
        .integrations {
            grid-template-columns: minmax(0, 1fr);
            gap: 14px;
        }
    }

    @media (max-width: 767px) {
        .integration-head {
            padding: 16px 16px 12px;
        }

        .integration-actions {
            padding: 12px 16px 16px;
        }

        .integration-actions .btn {
            flex: 1 1 auto;
        }
    }
</style>
<?php admin_shell_body(); ?>

<div class="stack">

    <div class="integrations">

        <!-- GOOGLE SIGN-IN -->

        <section class="card integration">
            <div class="integration-head">
                <span class="tile-icon tone-blue"><?= icon("log-in") ?></span>
                <div>
                    <div class="integration-title">
                        <h2>Google sign-in</h2>
                        <?= $googleOn ? status_pill("connected") : status_pill("not set up") ?>
                    </div>
                    <p>Lets customers log in and register with "Continue with Google".</p>
                </div>
            </div>

            <div class="card-body">
                <dl class="kv">
                    <dt>Client ID</dt>
                    <dd class="mono"><?= $googleOn ? h(integration_hint($config["google"]["client_id"], 10, 28)) : '<span class="muted">Not set</span>' ?></dd>

                    <dt>Returns to</dt>
                    <dd class="mono break"><?= h(integration_google_redirect()) ?></dd>

                    <dt>Accounts using it</dt>
                    <dd><?= number_format($googleUsers) ?></dd>
                </dl>
            </div>

            <div class="integration-actions">
                <button class="btn btn-sm" type="button" data-copy="<?= h(integration_google_redirect()) ?>"><?= icon("clipboard") ?> Copy the return address</button>
                <a class="btn btn-sm btn-ghost" href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener"><?= icon("external-link") ?> Google Cloud Console</a>
            </div>

            <details class="faq">
                <summary><span>How to set it up</span><?= icon("chevron-down", 16) ?></summary>
                <div class="faq-body">
                    <ol>
                        <li>In Google Cloud Console, create or pick a project.</li>
                        <li>APIs &amp; Services → OAuth consent screen: user type "External", add the scopes openid, email and profile, then publish the app.</li>
                        <li>Credentials → Create credentials → OAuth client ID → "Web application".</li>
                        <li>Under "Authorized redirect URIs" paste the return address shown above, exactly.</li>
                        <li>Put the Client ID and the Client secret in <span class="mono">config/auth.php</span> under "google".</li>
                    </ol>
                    <p>The button stays hidden on the site until both are filled in.</p>
                </div>
            </details>
        </section>


        <!-- EMAIL -->

        <section class="card integration">
            <div class="integration-head">
                <span class="tile-icon tone-violet"><?= icon("mail") ?></span>
                <div>
                    <div class="integration-title">
                        <h2>Email</h2>
                        <?= $mailOn ? status_pill("connected") : status_pill("not set up") ?>
                    </div>
                    <p>Sends the 6-digit code that confirms a new customer's email address.</p>
                </div>
            </div>

            <div class="card-body">
                <dl class="kv">
                    <dt>Mail server</dt>
                    <dd class="mono"><?= h($config["mail"]["host"] . ":" . (int) $config["mail"]["port"]) ?> (<?= h(strtoupper((string) $config["mail"]["encryption"])) ?>)</dd>

                    <dt>Sends as</dt>
                    <dd class="break">
                        <?php if ($mailOn): ?>
                            <?= h($config["mail"]["from_name"]) ?> &lt;<?= h(trim((string) $config["mail"]["from_email"]) ?: $config["mail"]["username"]) ?>&gt;
                        <?php else: ?>
                            <span class="muted">Not set</span>
                        <?php endif; ?>
                    </dd>

                    <dt>Not verified yet</dt>
                    <dd><?= number_format($unverified) ?> <?= $unverified === 1 ? "customer" : "customers" ?></dd>
                </dl>

                <?php if (!$mailOn): ?>
                    <p class="note" style="margin-top:12px">
                        <?= $isLocal
                            ? "On this computer the codes are written to a log file instead of being emailed."
                            : "Until email is set up, new customers cannot receive their code." ?>
                    </p>
                <?php endif; ?>
            </div>

            <div class="integration-actions">
                <form class="inline-form" method="post"
                    data-confirm="A short test email will be sent to <?= h($admin["email"]) ?>."
                    data-confirm-title="Send a test email?"
                    data-confirm-ok="Send"
                >
                    <?= csrf_field() ?>
                    <input type="hidden" name="test" value="mail">
                    <button class="btn btn-sm btn-primary" type="submit" <?= $mailOn ? "" : "disabled" ?>><?= icon("send") ?> Send a test email</button>
                </form>
                <a class="btn btn-sm btn-ghost" href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener"><?= icon("external-link") ?> Gmail app passwords</a>
            </div>

            <details class="faq">
                <summary><span>How to set it up</span><?= icon("chevron-down", 16) ?></summary>
                <div class="faq-body">
                    <ol>
                        <li>On the Gmail account that will send the emails, turn on 2-Step Verification.</li>
                        <li>Create an App Password named "ARVE'S House" (16 characters).</li>
                        <li>Put the Gmail address in "username" and the App Password in "password", in <span class="mono">config/auth.php</span> under "mail".</li>
                        <li>Come back here and send a test email.</li>
                    </ol>
                    <p>Some free hosts block outgoing mail. If the test fails, ask the host which mail server and port to use.</p>
                </div>
            </details>
        </section>


        <!-- PAYMONGO -->

        <section class="card integration">
            <div class="integration-head">
                <span class="tile-icon tone-green"><?= icon("credit-card") ?></span>
                <div>
                    <div class="integration-title">
                        <h2>PayMongo</h2>
                        <?php if (!$payOn): ?>
                            <?= status_pill("not set up") ?>
                        <?php elseif (paymongo_test_mode()): ?>
                            <?= status_pill("test mode") ?>
                        <?php else: ?>
                            <?= status_pill("connected", "Live") ?>
                        <?php endif; ?>
                    </div>
                    <p>Online payments with GCash, Maya, GrabPay and cards. They confirm themselves.</p>
                </div>
            </div>

            <div class="card-body">
                <dl class="kv">
                    <dt>Mode</dt>
                    <dd>
                        <?php if (!$payOn): ?>
                            <span class="muted">Not set</span>
                        <?php elseif (paymongo_test_mode()): ?>
                            Test: no real money moves
                        <?php else: ?>
                            Live: real payments
                        <?php endif; ?>
                    </dd>

                    <dt>Secret key</dt>
                    <dd class="mono"><?= $payOn ? (paymongo_test_mode() ? "sk_test_" : "sk_live_") . "…" . h(mb_substr(paymongo_secret(), -4)) : '<span class="muted">Not set</span>' ?></dd>

                    <dt>Paid online</dt>
                    <dd><?= number_format($online["payments"]) ?> <?= (int) $online["payments"] === 1 ? "payment" : "payments" ?>, <?= h(peso($online["amount"])) ?></dd>
                </dl>

                <?php if ($payOn && paymongo_test_mode() && !$isLocal): ?>
                    <p class="note" style="margin-top:12px">
                        The live site is in test mode: guests can finish a payment without paying real money.
                        Put the <span class="mono">sk_live_</span> key in <span class="mono">config/auth.php</span> when you are ready for real payments.
                    </p>
                <?php endif; ?>
            </div>

            <div class="integration-actions">
                <form class="inline-form" method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="test" value="paymongo">
                    <button class="btn btn-sm btn-primary" type="submit" <?= $payOn ? "" : "disabled" ?>><?= icon("refresh") ?> Check the connection</button>
                </form>
                <a class="btn btn-sm btn-ghost" href="https://dashboard.paymongo.com/" target="_blank" rel="noopener"><?= icon("external-link") ?> PayMongo dashboard</a>
            </div>

            <details class="faq">
                <summary><span>How to set it up</span><?= icon("chevron-down", 16) ?></summary>
                <div class="faq-body">
                    <ol>
                        <li>In the PayMongo dashboard open Developers → API Keys.</li>
                        <li>Copy the secret key: <span class="mono">sk_test_…</span> to try it out, <span class="mono">sk_live_…</span> for real money.</li>
                        <li>Put it in <span class="mono">config/auth.php</span> under "paymongo" → "secret_key".</li>
                        <li>Come back here and check the connection.</li>
                    </ol>
                    <p>"Pay Online" stays hidden from guests until the key is filled in.</p>
                </div>
            </details>
        </section>

    </div>

    <p class="muted" style="font-size:12.5px">
        The keys and passwords of these services are kept in the file <span class="mono">config/auth.php</span> on the
        server and are never shown here in full. To change one, edit that file on your computer and on the live host.
    </p>

</div>

<?php admin_shell_end(); ?>
