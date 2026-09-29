<?php

session_start();

require_once __DIR__ . "/../config/database.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if (empty($email) || empty($password)) {

        $error = "Please enter your email and password.";

    } else {

        $stmt = $pdo->prepare(
            "SELECT id, full_name, email, password, role, status
             FROM users
             WHERE email = ?
             LIMIT 1"
        );

        $stmt->execute([$email]);

        $user = $stmt->fetch();

        if (
            !$user ||
            !password_verify($password, $user["password"])
        ) {

            $error = "Invalid administrator credentials.";

        } elseif ($user["role"] !== "admin") {

            $error = "You do not have administrator access.";

        } elseif ($user["status"] !== "active") {

            $error = "This administrator account is inactive.";

        } else {

            session_regenerate_id(true);

            $_SESSION["user_id"] = $user["id"];
            $_SESSION["full_name"] = $user["full_name"];
            $_SESSION["email"] = $user["email"];
            $_SESSION["role"] = "admin";

            header("Location: dashboard.php");
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

    <meta
        name="theme-color"
        content="#211914"
    >

    <title>Admin Login | ARVE'S House</title>

    <style>

        :root {
            --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            --ease-in-out: cubic-bezier(0.77, 0, 0.175, 1);
            --ease-drawer: cubic-bezier(0.32, 0.72, 0, 1);
        }

        * {
            box-sizing: border-box;
        }

        html {
            -webkit-tap-highlight-color: transparent;
            -webkit-text-size-adjust: 100%;
            text-size-adjust: 100%;
        }

        body {
            margin: 0;
            min-height: 100vh;
            min-height: 100svh;

            font-family: Arial, sans-serif;

            background: #211914;

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

        .login-box {
            width: 100%;
            max-width: 420px;

            background: #ffffff;

            padding: 40px;

            border-radius: 16px;

            box-shadow: 0 15px 40px rgba(0,0,0,.3);
        }

        .logo {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo h1 {
            margin: 0;
            color: #4b3025;
        }

        .logo p {
            color: #777;
            margin-top: 8px;
        }

        .admin-label {
            display: inline-block;

            margin-top: 10px;

            padding: 6px 12px;

            border-radius: 20px;

            background: #f0e3d2;

            color: #6f4e37;

            font-size: 12px;

            font-weight: bold;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: bold;
        }

        input {
            width: 100%;

            padding: 13px;

            border: 1px solid #ccc;

            border-radius: 8px;

            font-size: 15px;
        }

        button {
            width: 100%;

            padding: 14px;

            border: 0;

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

        button:active:not(:disabled) {
            transform: scale(0.97);
        }

        button:focus-visible {
            background: #563a29;
        }

        @media (hover: hover) and (pointer: fine) {

            button:hover {
                background: #563a29;
            }

        }

        .error {
            background: #ffe5e5;
            color: #b00020;

            padding: 12px;

            border-radius: 8px;

            margin-bottom: 20px;

            text-align: center;

            animation: alert-in 240ms var(--ease-out) both;
        }

        @keyframes alert-in {
            from {
                opacity: 0;
                transform: translateY(-4px);
            }
        }

        .back {
            text-align: center;
            margin-top: 20px;
        }

        .back a {
            color: #6f4e37;
            text-decoration: none;

            touch-action: manipulation;
        }

        @media (pointer: coarse) {

            input,
            select,
            textarea {
                font-size: 16px;
            }

        }

        @media (prefers-reduced-motion: reduce) {

            html {
                scroll-behavior: auto;
            }

            /* no press scale, no alert slide; the button colour fade stays */
            button,
            .error {
                transform: none !important;
            }

            *,
            *::before,
            *::after {
                animation-duration: 1ms !important;
                animation-iteration-count: 1 !important;
            }

        }

    </style>

<?php $glassTheme = "admin"; require __DIR__ . "/../includes/glass.php"; ?>
</head>

<body>

    <div class="login-box">

        <div class="logo">

            <h1>ARVE'S House</h1>

            <p>Transient & Reservation System</p>

            <span class="admin-label">
                ADMINISTRATOR
            </span>

        </div>

        <?php if (!empty($error)): ?>

            <div class="error">
                <?= htmlspecialchars($error) ?>
            </div>

        <?php endif; ?>

        <form method="POST">

            <div class="form-group">

                <label for="email">
                    Email Address
                </label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="Administrator email"
                    autocomplete="username"
                    autocapitalize="none"
                    autocorrect="off"
                    spellcheck="false"
                    required
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
                    placeholder="Administrator password"
                    autocomplete="current-password"
                    enterkeyhint="go"
                    required
                >

            </div>

            <button type="submit">
                Admin Login
            </button>

        </form>

        <div class="back">
            <a href="../login.php">
                ← Customer Login
            </a>
        </div>

    </div>

</body>

</html>
