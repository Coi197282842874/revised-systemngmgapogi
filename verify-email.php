<?php

// ======================================================
// CONFIRM EMAIL WITH THE 6-DIGIT CODE
// Reached after registering, or after logging in to an unverified account.
// ======================================================

session_start();

require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/auth.php";

ensure_auth_schema($pdo);


// "Use a different account"
if (isset($_GET["cancel"])) {
    unset($_SESSION["pending_verification_user_id"], $_SESSION["pending_redirect"]);
    header("Location: login.php");
    exit;
}

$userId = (int) ($_SESSION["pending_verification_user_id"] ?? 0);

if ($userId <= 0) {
    header("Location: login.php");
    exit;
}

$stmt = $pdo->prepare(
    "SELECT id, full_name, email, role, status, email_verified
     FROM users
     WHERE id = ?
     LIMIT 1"
);
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user || $user["status"] !== "active") {
    unset($_SESSION["pending_verification_user_id"], $_SESSION["pending_redirect"]);
    $_SESSION["auth_flash"] = "Please log in again.";
    header("Location: login.php");
    exit;
}

$redirect = (string) ($_SESSION["pending_redirect"] ?? "");

if ((int) $user["email_verified"] === 1) {
    login_user($user);
    header("Location: " . destination_for($user, $redirect));
    exit;
}


// ======================================================
// MESSAGES FROM REGISTER / LOGIN
// ======================================================

$error = "";
$notice = "";

if (!empty($_SESSION["verify_flash"])) {
    $flash = $_SESSION["verify_flash"];
    unset($_SESSION["verify_flash"]);

    if (($flash["type"] ?? "") === "error") {
        $error = (string) $flash["message"];
    } else {
        $notice = (string) ($flash["message"] ?? "");
    }
}


// ======================================================
// VERIFY OR RESEND
// ======================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!csrf_valid($_POST["csrf_token"] ?? null)) {

        $error = "Your session expired. Please try again.";

    } elseif (($_POST["action"] ?? "") === "resend") {

        $result = issue_verification_code($pdo, $user);

        if ($result["ok"]) {
            $notice = "We sent a new code to " . mask_email($user["email"]) . ".";
        } else {
            $error = $result["error"];
        }

    } else {

        $result = check_verification_code($pdo, $userId, (string) ($_POST["code"] ?? ""));

        if ($result["ok"]) {
            $user["email_verified"] = 1;
            login_user($user);
            header("Location: " . destination_for($user, $redirect));
            exit;
        }

        $error = $result["error"];
    }
}


// seconds until "Resend code" is allowed again
$stmt = $pdo->prepare("SELECT last_sent_at FROM email_verifications WHERE user_id = ?");
$stmt->execute([$userId]);
$lastSent = $stmt->fetchColumn();
$resendIn = $lastSent ? max(0, VERIFY_RESEND_COOLDOWN - (time() - utc_ts($lastSent))) : 0;

$isLocal = in_array($_SERVER["REMOTE_ADDR"] ?? "", ["127.0.0.1", "::1"], true);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0, viewport-fit=cover"
    >

    <meta name="theme-color" content="#f4f1ec">

    <title>Verify your email | ARVE'S House</title>

    <style>

        :root {
            --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
        }

        html {
            -webkit-tap-highlight-color: transparent;
            -webkit-text-size-adjust: 100%;
            text-size-adjust: 100%;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            min-height: 100svh;
            font-family: Arial, Helvetica, sans-serif;
            color: #241a15;
            background: linear-gradient(135deg, #f4f1ec, #e8dfd5);
            display: flex;
            justify-content: center;
            align-items: center;
            padding:
                max(20px, env(safe-area-inset-top, 0px))
                max(20px, env(safe-area-inset-right, 0px))
                max(20px, env(safe-area-inset-bottom, 0px))
                max(20px, env(safe-area-inset-left, 0px));
        }

        .verify-card {
            width: 100%;
            max-width: 430px;
            background: #ffffff;
            padding: 40px;
            border-radius: 18px;
            box-shadow:
                0 1px 2px rgba(36, 26, 21, 0.06),
                0 15px 45px rgba(36, 26, 21, 0.12);
        }

        .brand {
            display: block;
            margin-bottom: 22px;
            text-align: center;
            font-size: 22px;
            font-weight: 700;
            color: #4b3025;
            text-decoration: none;
        }

        .brand span {
            color: #b7813f;
        }

        .mail-icon {
            display: grid;
            place-items: center;
            width: 56px;
            height: 56px;
            margin: 0 auto 18px;
            border-radius: 16px;
            background: #fbf4ea;
            color: #6f4e37;
        }

        h1 {
            margin: 0 0 8px;
            text-align: center;
            font-family: Georgia, "Times New Roman", serif;
            font-size: 28px;
            font-weight: 600;
        }

        .lead {
            margin: 0 0 24px;
            text-align: center;
            font-size: 15px;
            line-height: 1.55;
            color: #6b5f58;
        }

        .lead strong {
            color: #241a15;
            overflow-wrap: anywhere;
        }

        .alert {
            margin-bottom: 18px;
            padding: 12px 14px;
            border-radius: 12px;
            font-size: 14px;
            line-height: 1.45;
            animation: alert-in 240ms var(--ease-out) both;
        }

        .alert-error {
            background: #fdecec;
            color: #9f1d1d;
            box-shadow: inset 0 0 0 1px rgba(159, 29, 29, 0.2);
        }

        .alert-notice {
            background: #eaf6ee;
            color: #1f6b3a;
            box-shadow: inset 0 0 0 1px rgba(31, 107, 58, 0.2);
        }

        .alert-dev {
            background: #f3f0ff;
            color: #4b3a8f;
            box-shadow: inset 0 0 0 1px rgba(75, 58, 143, 0.2);
            overflow-wrap: anywhere;
        }

        @keyframes alert-in {
            from { opacity: 0; transform: translateY(-4px); }
            to   { opacity: 1; transform: none; }
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 700;
        }

        .code-input {
            width: 100%;
            height: 60px;
            padding: 0 16px;
            border: 0;
            border-radius: 12px;
            box-shadow: inset 0 0 0 1px rgba(36, 26, 21, 0.18);
            font-family: inherit;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 0.4em;
            text-align: center;
            font-variant-numeric: tabular-nums;
            color: #241a15;
            outline: none;
            transition: box-shadow 180ms ease;
        }

        .code-input::placeholder {
            color: #c9beb6;
            letter-spacing: 0.4em;
        }

        .code-input:focus {
            box-shadow:
                inset 0 0 0 1px #6f4e37,
                0 0 0 4px rgba(111, 78, 55, 0.14);
        }

        .btn-primary {
            display: block;
            width: 100%;
            height: 50px;
            margin-top: 16px;
            border: 0;
            border-radius: 12px;
            background: #6f4e37;
            font-family: inherit;
            font-size: 16px;
            font-weight: 700;
            color: #ffffff;
            cursor: pointer;
            touch-action: manipulation;
            -webkit-user-select: none;
            user-select: none;
            transition:
                background-color 180ms ease,
                transform 140ms var(--ease-out);
        }

        .btn-primary:active:not(:disabled) {
            background: #563a29;
            transform: scale(0.97);
        }

        .btn-primary:focus-visible,
        .btn-link:focus-visible,
        .foot a:focus-visible {
            outline: 2px solid #6f4e37;
            outline-offset: 3px;
        }

        .resend {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: center;
            gap: 4px;
            margin: 20px 0 0;
            font-size: 14px;
            color: #6b5f58;
        }

        .btn-link {
            padding: 6px 8px;
            border: 0;
            border-radius: 8px;
            background: transparent;
            font-family: inherit;
            font-size: 14px;
            font-weight: 700;
            color: #6f4e37;
            cursor: pointer;
            touch-action: manipulation;
            transition: background-color 180ms ease;
        }

        .btn-link:disabled {
            color: #8c7f77;
            cursor: default;
            font-variant-numeric: tabular-nums;
        }

        .hint {
            margin: 6px 0 0;
            text-align: center;
            font-size: 13px;
            color: #8c7f77;
        }

        .foot {
            margin: 22px 0 0;
            padding-top: 18px;
            border-top: 1px solid rgba(36, 26, 21, 0.08);
            text-align: center;
            font-size: 14px;
        }

        .foot a {
            color: #6b5f58;
            text-decoration: none;
        }

        @media (hover: hover) and (pointer: fine) {
            .btn-primary:hover { background: #563a29; }
            .btn-link:hover:not(:disabled) { background: rgba(111, 78, 55, 0.08); }
            .foot a:hover { color: #6f4e37; text-decoration: underline; }
        }

        @media (max-width: 500px) {
            .verify-card { padding: 30px 22px; }
        }

        @media (prefers-reduced-motion: reduce) {
            .btn-primary:active,
            .alert {
                transform: none !important;
            }
        }

    </style>

<?php require __DIR__ . "/includes/glass.php"; ?>
</head>

<body>

<main class="verify-card">

    <a class="brand" href="index.php">ARVE'S <span>House</span></a>

    <div class="mail-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
    </div>

    <h1>Check your email</h1>

    <p class="lead">
        We sent a 6-digit code to
        <strong><?= htmlspecialchars(mask_email($user["email"])) ?></strong>.
        Enter it below to confirm your email address.
    </p>

    <?php if ($error !== ""): ?>
        <div class="alert alert-error" role="alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($notice !== ""): ?>
        <div class="alert alert-notice" role="status"><?= htmlspecialchars($notice) ?></div>
    <?php endif; ?>

    <?php if (!mail_enabled() && $isLocal): ?>
        <div class="alert alert-dev">
            Email sending isn't set up yet (config/auth.php). For local testing, codes are written to
            <?= htmlspecialchars(sys_get_temp_dir() . DIRECTORY_SEPARATOR . "arves-house-mail.log") ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="verify-email.php" id="verify-form">

        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="verify">

        <label for="code">Verification code</label>

        <input
            class="code-input"
            type="text"
            id="code"
            name="code"
            inputmode="numeric"
            autocomplete="one-time-code"
            pattern="[0-9]{6}"
            maxlength="6"
            placeholder="000000"
            enterkeyhint="done"
            required
            autofocus
        >

        <button type="submit" class="btn-primary">Verify email</button>

    </form>

    <form method="POST" action="verify-email.php" class="resend">

        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="resend">

        <span>Didn't get it?</span>

        <button
            type="submit"
            class="btn-link"
            id="resend-button"
            data-wait="<?= (int) $resendIn ?>"
            <?= $resendIn > 0 ? "disabled" : "" ?>
        >
            <?= $resendIn > 0 ? "Resend in {$resendIn}s" : "Resend code" ?>
        </button>

    </form>

    <p class="hint">Codes expire after <?= (int) (VERIFY_CODE_TTL / 60) ?> minutes. Check your spam folder too.</p>

    <p class="foot">
        <a href="verify-email.php?cancel=1">Wrong email? Use a different account</a>
    </p>

</main>

<script>
(function () {
    var input = document.getElementById("code");
    var form = document.getElementById("verify-form");
    var resend = document.getElementById("resend-button");
    var submitted = false;

    // digits only (pasting "123 456" or "123-456" works), submit once all 6 are in
    input.addEventListener("input", function () {
        var digits = input.value.replace(/\D/g, "").slice(0, 6);

        if (digits !== input.value) {
            input.value = digits;
        }

        if (digits.length === 6 && !submitted && form.checkValidity()) {
            submitted = true;
            form.requestSubmit ? form.requestSubmit() : form.submit();
        }

        if (digits.length < 6) {
            submitted = false;
        }
    });

    var wait = parseInt(resend.getAttribute("data-wait"), 10) || 0;

    if (wait > 0) {
        var timer = setInterval(function () {
            wait -= 1;

            if (wait <= 0) {
                clearInterval(timer);
                resend.disabled = false;
                resend.textContent = "Resend code";
            } else {
                resend.textContent = "Resend in " + wait + "s";
            }
        }, 1000);
    }
})();
</script>

<?php require __DIR__ . "/includes/terms-modal.php"; ?>

</body>

</html>
