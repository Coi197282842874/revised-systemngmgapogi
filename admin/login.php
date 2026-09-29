<?php

session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/admin-shell.php";

// already logged in as an admin: straight to the dashboard
if (isset($_SESSION["user_id"]) && ($_SESSION["role"] ?? "") === "admin") {
    header("Location: dashboard.php");
    exit;
}

$error = "";
$email = "";

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

            // admin logins are kept in the activity log
            log_activity($pdo, "admin.login", $user["full_name"] . " logged in", [
                "ip" => true,
                "actor_id" => (int) $user["id"],
                "actor_role" => "admin",
                "actor_name" => $user["full_name"],
            ]);

            header("Location: dashboard.php");
            exit;
        }
    }
}

$theme = admin_theme();

?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, interactive-widget=resizes-content">
    <meta name="theme-color" content="<?= ADMIN_THEME_COLORS[$theme] ?>">
    <meta name="color-scheme" content="<?= $theme === "light" ? "light dark" : "dark light" ?>">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin Login | ARVE'S House</title>
    <link rel="stylesheet" href="<?= h(admin_asset("admin.css")) ?>">
    <style>
        body {
            display: grid;
            place-items: center;
            min-height: 100vh;
            min-height: 100svh;
            padding: max(24px, env(safe-area-inset-top, 0px)) max(16px, env(safe-area-inset-right, 0px)) max(24px, env(safe-area-inset-bottom, 0px)) max(16px, env(safe-area-inset-left, 0px));
        }

        /* a soft light behind the card, like the tiles of the dashboard */
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            z-index: -1;
            background:
                radial-gradient(40rem 30rem at 50% -10%, rgba(37, 99, 235, 0.22), transparent 70%),
                radial-gradient(30rem 24rem at 100% 100%, rgba(124, 58, 237, 0.12), transparent 70%);
            pointer-events: none;
        }

        .login {
            width: 100%;
            max-width: 400px;
        }

        .login-brand {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 22px;
            text-align: center;
        }

        .login-brand .brand-mark {
            width: 52px;
            height: 52px;
            margin-bottom: 14px;
            border-radius: 15px;
            font-size: 26px;
        }

        .login-brand h1 {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .login-brand p {
            margin-top: 2px;
            color: var(--text-3);
            font-size: 13.5px;
        }

        .login .card {
            padding: 24px;
        }

        .login .btn {
            min-height: 46px;
        }

        .login-links {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 8px 16px;
            margin-top: 18px;
            font-size: 13px;
        }

        .login-links a {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-height: 32px;
        }

        .login-theme {
            position: fixed;
            top: max(16px, env(safe-area-inset-top, 0px));
            right: max(16px, env(safe-area-inset-right, 0px));
        }
    </style>
</head>
<body>

    <button class="icon-btn theme-toggle login-theme" type="button" id="theme-toggle" aria-label="Switch between the light and dark theme">
        <?= icon("sun") ?><?= icon("moon") ?>
    </button>

    <main class="login">

        <div class="login-brand">
            <span class="brand-mark"><?= icon("home") ?></span>
            <h1>ARVE'S House</h1>
            <p>Admin panel</p>
        </div>

        <div class="card">

            <?php if (!empty($error)): ?>
                <div class="alert alert-error alert-in" role="alert">
                    <?= icon("alert") ?>
                    <p><?= h($error) ?></p>
                </div>
            <?php endif; ?>

            <form method="post">

                <label class="field">
                    <span class="label">Email address</span>
                    <input
                        class="input"
                        type="email"
                        id="email"
                        name="email"
                        value="<?= h($email) ?>"
                        placeholder="Administrator email"
                        autocomplete="username"
                        autocapitalize="none"
                        autocorrect="off"
                        spellcheck="false"
                        required
                        <?= $email === "" ? "autofocus" : "" ?>
                    >
                </label>

                <label class="field">
                    <span class="label">Password</span>
                    <input
                        class="input"
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Administrator password"
                        autocomplete="current-password"
                        enterkeyhint="go"
                        required
                        <?= $email !== "" ? "autofocus" : "" ?>
                    >
                </label>

                <div class="form-actions">
                    <button class="btn btn-primary btn-block" type="submit"><?= icon("log-in") ?> Log in</button>
                </div>

            </form>

        </div>

        <div class="login-links">
            <a href="../login.php"><?= icon("arrow-left", 14) ?> Customer login</a>
            <a href="../index.php">View website</a>
        </div>

    </main>

    <script>
        // the same switch as inside the panel: remembered for a year
        document.getElementById("theme-toggle").addEventListener("click", function () {
            var theme = document.documentElement.dataset.theme === "light" ? "dark" : "light";
            var colors = <?= json_encode(ADMIN_THEME_COLORS) ?>;

            document.documentElement.dataset.theme = theme;
            document.cookie = "<?= ADMIN_THEME_COOKIE ?>=" + theme + "; path=/; max-age=31536000; samesite=lax";
            document.querySelector('meta[name="theme-color"]').content = colors[theme];
        });
    </script>

</body>
</html>
