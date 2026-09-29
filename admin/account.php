<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/auth.php";

if (!isset($_SESSION["user_id"]) || ($_SESSION["role"] ?? "") !== "admin") {
    header("Location: ../login.php");
    exit;
}

$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $current = $_POST["current_password"] ?? "";
    $new = $_POST["new_password"] ?? "";
    $confirm = $_POST["confirm_password"] ?? "";

    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ? AND role = 'admin'");
    $stmt->execute([$_SESSION["user_id"]]);
    $hash = $stmt->fetchColumn();

    if (!csrf_valid($_POST["csrf_token"] ?? null)) {
        $error = "Your session expired. Please try again.";
    } elseif ($hash === false || !password_verify($current, $hash)) {
        $error = "Your current password is incorrect.";
    } elseif (strlen($new) < 10) {
        $error = "The new password must be at least 10 characters.";
    } elseif (!preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
        $error = "The new password must contain letters and numbers.";
    } elseif ($new === $current) {
        $error = "The new password must be different from the current one.";
    } elseif ($new !== $confirm) {
        $error = "The new passwords don't match.";
    } else {
        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
            ->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION["user_id"]]);
        session_regenerate_id(true);
        $message = "Password changed. Use the new password the next time you log in.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#f3f4f6">
    <title>Change Password | ARVE'S House</title>
    <style>
        :root { --ease-out: cubic-bezier(0.23, 1, 0.32, 1); }
        * { box-sizing: border-box; }
        html { -webkit-tap-highlight-color: transparent; -webkit-text-size-adjust: 100%; text-size-adjust: 100%; }
        body { margin: 0; padding: 35px 20px; padding: max(35px, env(safe-area-inset-top, 0px)) max(20px, env(safe-area-inset-right, 0px)) max(35px, env(safe-area-inset-bottom, 0px)) max(20px, env(safe-area-inset-left, 0px)); font-family: Arial, sans-serif; background: #f3f4f6; color: #1f2937; }
        .page { max-width: 480px; margin: 0 auto; }
        .topbar { display: flex; justify-content: space-between; align-items: center; gap: 15px; margin-bottom: 25px; }
        .topbar a { color: #2563eb; text-decoration: none; touch-action: manipulation; }
        .panel { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,.08); }
        label { display: block; margin: 16px 0 7px; font-weight: bold; }
        input { width: 100%; padding: 11px; border: 1px solid #d1d5db; border-radius: 7px; font-size: 15px; }
        .hint { margin: 6px 0 0; font-size: 13px; color: #6b7280; }
        button { margin-top: 22px; width: 100%; border: 0; border-radius: 7px; padding: 12px 18px; background: #2563eb; color: white; font-weight: bold; cursor: pointer; touch-action: manipulation; -webkit-user-select: none; user-select: none; transition: transform 140ms var(--ease-out); }
        button:active:not(:disabled) { transform: scale(0.97); }
        .message { padding: 12px; border-radius: 7px; margin-bottom: 15px; background: #d1fae5; color: #065f46; animation: alert-in 240ms var(--ease-out) both; }
        .error { padding: 12px; border-radius: 7px; margin-bottom: 15px; background: #fee2e2; color: #991b1b; animation: alert-in 240ms var(--ease-out) both; }
        @keyframes alert-in { from { opacity: 0; transform: translateY(-4px); } }
        @media (max-width: 700px) { .topbar { align-items: flex-start; flex-direction: column; } }
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
                <h1>Change Password</h1>
                <p><?= htmlspecialchars($_SESSION["email"] ?? "") ?></p>
            </div>
            <a href="dashboard.php">Back to Dashboard</a>
        </div>

        <section class="panel">
            <?php if ($message !== ""): ?><div class="message"><?= htmlspecialchars($message) ?></div><?php endif; ?>
            <?php if ($error !== ""): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

                <label for="current_password">Current password</label>
                <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>

                <label for="new_password">New password</label>
                <input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="10" required>
                <p class="hint">At least 10 characters, with letters and numbers.</p>

                <label for="confirm_password">Confirm new password</label>
                <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" minlength="10" required>

                <button type="submit">Change Password</button>
            </form>
        </section>
    </main>
</body>
</html>
