<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/auth.php";

if (!isset($_SESSION["user_id"]) || ($_SESSION["role"] ?? "") !== "admin") {
    header("Location: ../login.php");
    exit;
}

ensure_auth_schema($pdo);

$message = "";
$error = "";
$form = ["full_name" => "", "email" => ""];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $form["full_name"] = trim($_POST["full_name"] ?? "");
    $form["email"] = strtolower(trim($_POST["email"] ?? ""));
    $password = $_POST["password"] ?? "";
    $confirm = $_POST["confirm_password"] ?? "";

    if (!csrf_valid($_POST["csrf_token"] ?? null)) {
        $error = "Your session expired. Please try again.";
    } elseif ($form["full_name"] === "" || $form["email"] === "") {
        $error = "Please enter a name and an email address.";
    } elseif (!filter_var($form["email"], FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (strlen($password) < 10) {
        $error = "The password must be at least 10 characters.";
    } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        $error = "The password must contain letters and numbers.";
    } elseif ($password !== $confirm) {
        $error = "The passwords don't match.";
    } else {
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $check->execute([$form["email"]]);

        if ($check->fetch()) {
            $error = "An account with this email already exists.";
        } else {
            $pdo->prepare(
                "INSERT INTO users
                    (full_name, email, phone, password, role, status, email_verified, email_verified_at)
                 VALUES (?, ?, '', ?, 'admin', 'active', 1, ?)"
            )->execute([
                mb_substr($form["full_name"], 0, 150),
                $form["email"],
                password_hash($password, PASSWORD_DEFAULT),
                utc_now(),
            ]);

            $message = "Admin account created for " . $form["email"] . ". They can log in now.";
            $form = ["full_name" => "", "email" => ""];
        }
    }
}

$admins = $pdo->query(
    "SELECT id, full_name, email, status, created_at FROM users WHERE role = 'admin' ORDER BY id"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#f3f4f6">
    <title>Admins | ARVE'S House</title>
    <style>
        :root { --ease-out: cubic-bezier(0.23, 1, 0.32, 1); }
        * { box-sizing: border-box; }
        html { -webkit-tap-highlight-color: transparent; -webkit-text-size-adjust: 100%; text-size-adjust: 100%; }
        body { margin: 0; padding: 35px 20px; padding: max(35px, env(safe-area-inset-top, 0px)) max(20px, env(safe-area-inset-right, 0px)) max(35px, env(safe-area-inset-bottom, 0px)) max(20px, env(safe-area-inset-left, 0px)); font-family: Arial, sans-serif; background: #f3f4f6; color: #1f2937; }
        .page { max-width: 900px; margin: 0 auto; }
        .topbar { display: flex; justify-content: space-between; align-items: center; gap: 15px; margin-bottom: 25px; }
        .topbar a { color: #2563eb; text-decoration: none; touch-action: manipulation; }
        .layout { display: grid; grid-template-columns: 1fr 1fr; gap: 25px; align-items: start; }
        .panel { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,.08); }
        .panel h2 { margin: 0 0 6px; font-size: 20px; }
        .panel > p { margin: 0 0 8px; color: #6b7280; font-size: 14px; }
        label { display: block; margin: 16px 0 7px; font-weight: bold; }
        input { width: 100%; padding: 11px; border: 1px solid #d1d5db; border-radius: 7px; font-size: 15px; }
        .hint { margin: 6px 0 0; font-size: 13px; color: #6b7280; }
        button { margin-top: 22px; width: 100%; border: 0; border-radius: 7px; padding: 12px 18px; background: #2563eb; color: white; font-weight: bold; cursor: pointer; touch-action: manipulation; -webkit-user-select: none; user-select: none; transition: transform 140ms var(--ease-out); }
        button:active:not(:disabled) { transform: scale(0.97); }
        .message { padding: 12px; border-radius: 7px; margin-bottom: 15px; background: #d1fae5; color: #065f46; animation: alert-in 240ms var(--ease-out) both; }
        .error { padding: 12px; border-radius: 7px; margin-bottom: 15px; background: #fee2e2; color: #991b1b; animation: alert-in 240ms var(--ease-out) both; }
        @keyframes alert-in { from { opacity: 0; transform: translateY(-4px); } }
        .admin-list { list-style: none; margin: 14px 0 0; padding: 0; }
        .admin-list li { padding: 12px 0; border-top: 1px solid #e5e7eb; }
        .admin-list li:first-child { border-top: 0; }
        .admin-list strong { display: block; }
        .admin-list span { font-size: 14px; color: #6b7280; overflow-wrap: anywhere; }
        .badge { display: inline-block; margin-left: 6px; padding: 2px 8px; border-radius: 999px; background: #dbeafe; color: #1e40af; font-size: 12px; font-weight: bold; vertical-align: 1px; }
        @media (max-width: 700px) { .layout { grid-template-columns: 1fr; } .topbar { align-items: flex-start; flex-direction: column; } }
        @media (pointer: coarse) { input { font-size: 16px; } }
        @media (prefers-reduced-motion: reduce) {
            button, .message, .error { transform: none !important; }
            *, *::before, *::after { animation-duration: 1ms !important; animation-iteration-count: 1 !important; }
        }
    </style>
<?php $glassTheme = "admin"; require __DIR__ . "/../includes/glass.php"; ?>
</head>
<body>
    <main class="page">
        <div class="topbar">
            <div>
                <h1>Admins</h1>
                <p>People who can manage rooms, reservations and payments.</p>
            </div>
            <a href="dashboard.php">Back to Dashboard</a>
        </div>

        <div class="layout">
            <section class="panel">
                <h2>Add an admin</h2>
                <p>The new admin logs in with this email and password on the normal login page.</p>

                <?php if ($message !== ""): ?><div class="message"><?= htmlspecialchars($message) ?></div><?php endif; ?>
                <?php if ($error !== ""): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

                <form method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

                    <label for="full_name">Full name</label>
                    <input id="full_name" name="full_name" value="<?= htmlspecialchars($form["full_name"]) ?>" autocomplete="off" required>

                    <label for="email">Email address</label>
                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($form["email"]) ?>" autocomplete="off" autocapitalize="none" spellcheck="false" required>

                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" autocomplete="new-password" minlength="10" required>
                    <p class="hint">At least 10 characters, with letters and numbers.</p>

                    <label for="confirm_password">Confirm password</label>
                    <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" minlength="10" required>

                    <button type="submit">Create Admin Account</button>
                </form>
            </section>

            <section class="panel">
                <h2>Current admins</h2>
                <ul class="admin-list">
                    <?php foreach ($admins as $admin): ?>
                        <li>
                            <strong>
                                <?= htmlspecialchars($admin["full_name"]) ?>
                                <?php if ((int) $admin["id"] === (int) $_SESSION["user_id"]): ?><span class="badge">You</span><?php endif; ?>
                            </strong>
                            <span><?= htmlspecialchars($admin["email"]) ?> · <?= htmlspecialchars($admin["status"]) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        </div>
    </main>
</body>
</html>
