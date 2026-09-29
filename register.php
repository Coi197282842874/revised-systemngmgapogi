<?php

session_start();

require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/activity.php";

ensure_auth_schema($pdo);

$message = "";
$messageType = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $fullName = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";
    $acceptedTerms = ($_POST["accept_terms"] ?? "") === "1";

    // Validate required fields
    if (
        empty($fullName) ||
        empty($email) ||
        empty($phone) ||
        empty($password) ||
        empty($confirmPassword)
    ) {
        $message = "Please fill in all fields.";
        $messageType = "error";
    }

    // Validate email
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
        $messageType = "error";
    }

    // Validate password length
    elseif (strlen($password) < 8) {
        $message = "Password must be at least 8 characters.";
        $messageType = "error";
    }

    // Check passwords
    elseif ($password !== $confirmPassword) {
        $message = "Passwords do not match.";
        $messageType = "error";
    }

    // Terms and Conditions
    elseif (!$acceptedTerms) {
        $message = "Please agree to the Terms and Conditions to create an account.";
        $messageType = "error";
    }

    else {

        // Check if email already exists
        $check = $pdo->prepare(
            "SELECT id FROM users WHERE email = ? LIMIT 1"
        );

        $check->execute([$email]);

        if ($check->fetch()) {

            $message = "An account with this email already exists.";
            $messageType = "error";

        } else {

            // Securely hash password
            $hashedPassword = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            // Insert customer (email not verified yet)
            $stmt = $pdo->prepare(
                "INSERT INTO users
                (full_name, email, phone, password, role, status, email_verified, terms_accepted_at)
                VALUES (?, ?, ?, ?, 'customer', 'active', 0, ?)"
            );

            $stmt->execute([
                $fullName,
                $email,
                $phone,
                $hashedPassword,
                utc_now()
            ]);

            $newUser = [
                "id" => (int) $pdo->lastInsertId(),
                "full_name" => $fullName,
                "email" => $email,
            ];

            log_activity($pdo, "customer.registered", $fullName . " created an account", [
                "actor_id" => $newUser["id"],
                "actor_role" => "customer",
                "actor_name" => $fullName,
                "entity_type" => "customer",
                "entity_id" => $newUser["id"],
                "link" => "customers.php?q=" . str_replace("~", "%7E", rawurlencode($email)),
                "notify" => true,
            ]);


            // Email a 6-digit code and continue on the verification page
            session_regenerate_id(true);

            $_SESSION["pending_verification_user_id"] = $newUser["id"];
            $_SESSION["pending_redirect"] = "";

            $sent = issue_verification_code($pdo, $newUser);

            $_SESSION["verify_flash"] = $sent["ok"]
                ? ["type" => "notice", "message" => "Account created! One last step: confirm your email."]
                : ["type" => "error", "message" => $sent["error"]];

            header("Location: verify-email.php");
            exit;
        }
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

    <meta name="theme-color" content="#f4f1ec">

    <title>Register | ARVE'S House</title>

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
            font-family: Arial, sans-serif;
            background: #f4f1ec;

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

        .register-container {
            width: 100%;
            max-width: 480px;
            background: white;
            padding: 40px;

            border-radius: 18px;

            box-shadow:
                0 10px 35px rgba(0, 0, 0, 0.12);
        }

        .logo {
            text-align: center;
            margin-bottom: 25px;
        }

        .logo h1 {
            margin: 0;
            font-size: 30px;
            color: #4b3025;
        }

        .logo p {
            margin-top: 8px;
            color: #777;
        }

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: bold;
            color: #333;
        }

        input {
            width: 100%;
            padding: 13px 14px;

            border: 1px solid #ccc;
            border-radius: 8px;

            font-size: 15px;
            outline: none;
        }

        input:focus {
            border-color: #8b5e3c;
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

        .terms-check {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin: 4px 0 22px;
        }

        .terms-check input {
            flex: none;
            width: 20px;
            height: 20px;
            margin: 1px 0 0;
            padding: 0;
            accent-color: #6f4e37;
            cursor: pointer;
        }

        .terms-check label {
            margin: 0;
            font-weight: normal;
            font-size: 14px;
            line-height: 1.5;
            color: #555;
            cursor: pointer;
        }

        .terms-check a {
            color: #6f4e37;
            font-weight: bold;
            text-decoration: underline;
            text-underline-offset: 2px;
        }

        .message {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;

            animation: message-enter 240ms var(--ease-out) both;
        }

        @keyframes message-enter {
            from {
                opacity: 0;
                transform: translateY(-4px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .message.error {
            background: #ffe5e5;
            color: #b00020;
        }

        .message.success {
            background: #e5f7e9;
            color: #167532;
        }

        .login-link {
            text-align: center;
            margin-top: 22px;
            color: #666;
        }

        .login-link a {
            color: #6f4e37;
            font-weight: bold;
            text-decoration: none;
        }

        .login-link a:hover,
        .login-link a:active {
            text-decoration: underline;
        }

        /* stop iOS zooming into the 15px inputs; desktop keeps 15px */
        @media (pointer: coarse) {
            input,
            select,
            textarea {
                font-size: 16px;
            }
        }

        /* reduced motion: drop press scale and the message slide, keep the fade */
        @media (prefers-reduced-motion: reduce) {
            html {
                scroll-behavior: auto;
            }

            button:active,
            .message {
                transform: none !important;
            }

            *,
            *::before,
            *::after {
                animation-duration: 1ms !important;
                animation-iteration-count: 1 !important;
            }

            .message {
                animation-duration: 240ms !important;
            }
        }

    </style>

<?php require __DIR__ . "/includes/glass.php"; ?>
</head>

<body>

    <div class="register-container">

        <div class="logo">

            <h1>ARVE'S House</h1>

            <p>Create your account</p>

        </div>


        <?php if (!empty($message)): ?>

            <div class="message <?= $messageType ?>">

                <?= htmlspecialchars($message) ?>

            </div>

        <?php endif; ?>


        <?php require __DIR__ . "/includes/google-button.php"; ?>


        <form method="POST" action="">

            <div class="form-group">

                <label for="full_name">
                    Full Name
                </label>

                <input
                    type="text"
                    id="full_name"
                    name="full_name"
                    placeholder="Enter your full name"
                    required
                    autocomplete="name"
                    autocapitalize="words"
                >

            </div>


            <div class="form-group">

                <label for="email">
                    Email Address
                </label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="Enter your email"
                    required
                    autocomplete="email"
                >

            </div>


            <div class="form-group">

                <label for="phone">
                    Phone Number
                </label>

                <input
                    type="tel"
                    id="phone"
                    name="phone"
                    placeholder="Enter your phone number"
                    required
                    autocomplete="tel"
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
                    placeholder="Minimum 8 characters"
                    minlength="8"
                    required
                    autocomplete="new-password"
                >

            </div>


            <div class="form-group">

                <label for="confirm_password">
                    Confirm Password
                </label>

                <input
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    placeholder="Re-enter your password"
                    minlength="8"
                    required
                    autocomplete="new-password"
                    enterkeyhint="go"
                >

            </div>


            <div class="terms-check">

                <input
                    type="checkbox"
                    id="accept_terms"
                    name="accept_terms"
                    value="1"
                    required
                >

                <label for="accept_terms">
                    I have read and agree to the
                    <a href="#terms">Terms and Conditions</a>
                </label>

            </div>


            <button type="submit">
                Create Account
            </button>

        </form>


        <div class="login-link">

            Already have an account?

            <a href="login.php">
                Login
            </a>

        </div>

    </div>

<?php require __DIR__ . "/includes/terms-modal.php"; ?>

</body>

</html>