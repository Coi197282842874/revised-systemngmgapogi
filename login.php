<?php

session_start();

require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/icons.php";
require_once __DIR__ . "/includes/auth.php";

ensure_auth_schema($pdo);

$error = "";


// ======================================================
// MESSAGE FROM GOOGLE SIGN-IN / EMAIL VERIFICATION
// ======================================================

if (!empty($_SESSION["auth_flash"])) {
    $error = (string) $_SESSION["auth_flash"];
    unset($_SESSION["auth_flash"]);
}


// ======================================================
// GET REDIRECT DESTINATION
// SECURITY: ONLY ALLOW LOCAL REDIRECTS (see safe_redirect)
// ======================================================

$redirect = safe_redirect(
    (string) ($_GET["redirect"] ?? $_POST["redirect"] ?? "")
);


// ======================================================
// FLOATING LOGIN (includes/login-modal.php) POSTS VIA FETCH
// AND EXPECTS JSON INSTEAD OF A REDIRECT / HTML PAGE
// ======================================================

$wantsJson =
    ($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") === "fetch";

function finishLogin(string $destination, bool $wantsJson): void
{
    if ($wantsJson) {
        header("Content-Type: application/json");
        echo json_encode(["ok" => true, "redirect" => $destination]);
        exit;
    }

    header("Location: " . $destination);
    exit;
}


// ======================================================
// HANDLE LOGIN
// ======================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if (empty($email) || empty($password)) {

        $error = "Please enter your email and password.";

    } else {

        $stmt = $pdo->prepare(
            "SELECT
                id,
                full_name,
                email,
                password,
                role,
                status,
                email_verified
             FROM users
             WHERE email = ?
             LIMIT 1"
        );

        $stmt->execute([$email]);

        $user = $stmt->fetch();


        if (!$user) {

            $error = "Invalid email or password.";

        } elseif ($user["status"] !== "active") {

            $error = "Your account is inactive.";

        } elseif (!password_verify(
            $password,
            $user["password"]
        )) {

            $error = "Invalid email or password.";

        } elseif (
            $user["role"] === "customer"
            && (int) $user["email_verified"] !== 1
        ) {


            // ======================================================
            // EMAIL NOT VERIFIED YET: SEND (OR REUSE) A CODE
            // AND GO TO THE VERIFICATION PAGE INSTEAD OF LOGGING IN
            // ======================================================

            session_regenerate_id(true);

            $_SESSION["pending_verification_user_id"] = (int) $user["id"];
            $_SESSION["pending_redirect"] = $redirect;

            $sent = issue_verification_code($pdo, $user, true);

            $_SESSION["verify_flash"] = $sent["ok"]
                ? ["type" => "notice", "message" => "Please confirm your email to finish logging in."]
                : ["type" => "error", "message" => $sent["error"]];

            finishLogin("verify-email.php", $wantsJson);

        } else {


            // ======================================================
            // SAVE USER SESSION (regenerates the session id)
            // ======================================================

            login_user($user);

            finishLogin(destination_for($user, $redirect), $wantsJson);
        }
    }


    // ======================================================
    // FLOATING LOGIN: REPORT THE ERROR AS JSON
    // ======================================================

    if ($wantsJson) {

        http_response_code(422);

        header("Content-Type: application/json");

        echo json_encode(["ok" => false, "error" => $error]);

        exit;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0, viewport-fit=cover"
    >

    <meta
        name="theme-color"
        content="#f4f1ec"
    >

    <title>
        Login | ARVE'S House
    </title>

    <style>

        :root {

            --ease-out: cubic-bezier(0.23, 1, 0.32, 1);

            --ease-in-out: cubic-bezier(0.77, 0, 0.175, 1);

            --ease-drawer: cubic-bezier(0.32, 0.72, 0, 1);
        }


        html {

            -webkit-tap-highlight-color: transparent;

            -webkit-text-size-adjust: 100%;

            text-size-adjust: 100%;
        }


        a {

            touch-action: manipulation;
        }


        * {
            box-sizing: border-box;
        }

        body {

            margin: 0;

            min-height: 100vh;

            min-height: 100svh;

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            background:
                linear-gradient(
                    135deg,
                    #f4f1ec,
                    #e8dfd5
                );

            display: flex;

            justify-content: center;

            align-items: center;

            padding: 20px;

            padding:
                max(20px, env(safe-area-inset-top, 0px))
                max(20px, env(safe-area-inset-right, 0px))
                max(20px, env(safe-area-inset-bottom, 0px))
                max(20px, env(safe-area-inset-left, 0px));
        }


        .login-container {

            width: 100%;

            max-width: 430px;

            background: #ffffff;

            padding: 40px;

            border-radius: 18px;

            box-shadow:
                0 15px 45px
                rgba(0, 0, 0, 0.13);
        }


        .logo {

            text-align: center;

            margin-bottom: 30px;
        }


        .logo h1 {

            margin: 0;

            color: #4b3025;

            font-size: 30px;
        }


        .logo h1 span {

            color: #d6a25e;
        }


        .logo p {

            margin-top: 8px;

            color: #777;
        }


        .booking-message {

            background: #fff7ed;

            border:
                1px solid #fed7aa;

            color: #9a3412;

            border-radius: 9px;

            padding: 13px;

            margin-bottom: 22px;

            text-align: center;

            font-size: 14px;
        }


        .form-group {

            margin-bottom: 20px;
        }


        label {

            display: block;

            margin-bottom: 8px;

            font-weight: bold;

            color: #333;
        }


        input {

            width: 100%;

            padding: 14px;

            border:
                1px solid #ccc;

            border-radius: 8px;

            font-size: 15px;

            outline: none;

            transition:
                border-color 180ms ease,
                box-shadow 180ms ease;
        }


        input:focus {

            border-color: #8b5e3c;

            box-shadow:
                0 0 0 3px
                rgba(139,94,60,0.1);
        }


        button {

            width: 100%;

            padding: 14px;

            border: none;

            border-radius: 8px;

            background: #6f4e37;

            color: white;

            font-size: 16px;

            font-weight: bold;

            cursor: pointer;

            touch-action: manipulation;

            -webkit-user-select: none;

            user-select: none;

            transition:
                transform 140ms var(--ease-out),
                background-color 180ms ease;
        }


        @media (hover: hover) and (pointer: fine) {

            button:hover {

                background: #563a29;
            }

        }


        button:focus-visible {

            background: #563a29;
        }


        button:active:not(:disabled) {

            background: #563a29;

            transform: scale(0.97);
        }


        .error {

            padding: 12px;

            margin-bottom: 20px;

            border-radius: 8px;

            background: #ffe5e5;

            color: #b00020;

            text-align: center;

            animation:
                alert-enter 240ms var(--ease-out) both;
        }


        @keyframes alert-enter {

            from {

                opacity: 0;

                transform: translateY(-4px);
            }

            to {

                opacity: 1;

                transform: translateY(0);
            }
        }


        .register-link {

            text-align: center;

            margin-top: 22px;

            color: #666;
        }


        .register-link a {

            color: #6f4e37;

            font-weight: bold;

            text-decoration: none;
        }


        .register-link a:hover,
        .register-link a:active {

            text-decoration: underline;
        }


        .home-link {

            text-align: center;

            margin-top: 14px;
        }


        .home-link a {

            color: #777;

            font-size: 13px;

            text-decoration: none;
        }


        .home-link a:hover,
        .home-link a:active {

            color: #6f4e37;
        }


        @media (max-width: 500px) {

            .login-container {

                padding:
                    30px 22px;
            }

        }


        /* stop iOS zooming into the 15px inputs; desktop keeps 15px */
        @media (pointer: coarse) {

            input,
            select,
            textarea {

                font-size: 16px;
            }

        }


        /* reduced motion: drop press scale and the alert slide, keep the fade */
        @media (prefers-reduced-motion: reduce) {

            html {

                scroll-behavior: auto;
            }

            button:active,
            .error {

                transform: none !important;
            }

            *,
            *::before,
            *::after {

                animation-duration: 1ms !important;

                animation-iteration-count: 1 !important;
            }

            .error {

                animation-duration: 240ms !important;
            }

        }

    </style>

<?php require __DIR__ . "/includes/glass.php"; ?>
</head>

<body>

<div class="login-container">


    <div class="logo">

        <h1>
            ARVE'S <span>House</span>
        </h1>

        <p>
            Transient & Reservation System
        </p>

    </div>


    <?php if (!empty($redirect)): ?>

        <div class="booking-message">

            <?= icon("log-in") ?> Please log in using your
            customer account to continue your booking.

        </div>

    <?php endif; ?>


    <?php if (!empty($error)): ?>

        <div class="error">

            <?= htmlspecialchars($error) ?>

        </div>

    <?php endif; ?>


    <?php
        $googleRedirect = $redirect;
        require __DIR__ . "/includes/google-button.php";
    ?>


    <form
        method="POST"
        action=""
    >


        <!-- KEEP REDIRECT AFTER FORM SUBMISSION -->

        <input
            type="hidden"
            name="redirect"
            value="<?= htmlspecialchars($redirect) ?>"
        >


        <div class="form-group">

            <label for="email">
                Email Address
            </label>

            <input
                type="email"
                id="email"
                name="email"
                placeholder="Enter your email"
                value="<?= htmlspecialchars(
                    $_POST["email"] ?? ""
                ) ?>"
                required
                autocomplete="email"
            >

        </div>


        <div class="form-group">

            <label for="password">
                Password
            </label>

            <input
                type="password"
                id="password"
                name="password"
                placeholder="Enter your password"
                required
                autocomplete="current-password"
                enterkeyhint="go"
            >

        </div>


        <button type="submit">

            Login

        </button>

    </form>


    <div class="register-link">

        Don't have an account?

        <a href="register.php">
            Create one
        </a>

    </div>


    <div class="home-link">

        <a href="index.php">
            ← Back to Home
        </a>

    </div>

</div>

<?php require __DIR__ . "/includes/terms-modal.php"; ?>

</body>

</html>