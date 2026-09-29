<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/admin-shell.php";

// Every admin can open this page: it only concerns their own account.
$admin = admin_boot($pdo);

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $current = $_POST["current_password"] ?? "";
    $new = $_POST["new_password"] ?? "";
    $confirm = $_POST["confirm_password"] ?? "";

    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ? AND role = 'admin'");
    $stmt->execute([$admin["id"]]);
    $hash = $stmt->fetchColumn();

    if (!admin_check_csrf()) {
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
            ->execute([password_hash($new, PASSWORD_DEFAULT), $admin["id"]]);

        session_regenerate_id(true);

        log_activity($pdo, "admin.password_changed", "Changed their password", [
            "entity_type" => "admin",
            "entity_id" => (int) $admin["id"],
            "link" => "account.php",
        ]);

        admin_flash("Password changed. Use the new password the next time you log in.");

        header("Location: account.php");
        exit;
    }
}

$permissions = admin_role_permissions($pdo)[$admin["admin_role"]] ?? [];

admin_shell_head([
    "title" => "Your account",
    "subtitle" => "Your details and your password",
    "active" => "",
    "narrow" => true,
]);
?>
<?php admin_shell_body(); ?>

<div class="grid grid-2" style="align-items:start">

    <section class="card card-pad">
        <div class="person" style="margin-bottom:16px">
            <?= admin_avatar($admin, 56) ?>
            <div class="person-text">
                <span class="person-name" style="max-width:none;font-size:16px"><?= h($admin["full_name"]) ?></span>
                <span class="person-sub" style="max-width:none"><?= h($admin["email"]) ?></span>
            </div>
        </div>

        <dl class="kv">
            <dt>Role</dt>
            <dd><?= h(admin_role_label($admin["admin_role"])) ?></dd>

            <dt>Admin since</dt>
            <dd><?= h(admin_date($admin["created_at"], "F j, Y")) ?></dd>

            <dt>You can open</dt>
            <dd>
                <?php if ($admin["admin_role"] === "owner"): ?>
                    Everything
                <?php elseif ($permissions): ?>
                    <?= h(implode(", ", array_map(fn ($key) => ADMIN_PERMISSIONS[$key]["label"], $permissions))) ?>
                <?php else: ?>
                    The Dashboard and Notifications
                <?php endif; ?>
            </dd>
        </dl>

        <?php if ($admin["admin_role"] !== "owner"): ?>
            <p class="hint">A Super Admin can change your role and what it opens.</p>
        <?php endif; ?>
    </section>

    <section class="card card-pad">
        <h2 class="card-title">Change password</h2>
        <p class="card-sub" style="margin-bottom:16px">At least 10 characters, with letters and numbers</p>

        <?php if ($error !== ""): ?>
            <div class="alert alert-error alert-in" role="alert"><?= icon("alert") ?><p><?= h($error) ?></p></div>
        <?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>

            <!-- helps password managers save the new password under the right account -->
            <input type="text" name="username" value="<?= h($admin["email"]) ?>" autocomplete="username" hidden>

            <label class="field">
                <span class="label">Current password</span>
                <input class="input" type="password" name="current_password" autocomplete="current-password" required>
            </label>

            <label class="field">
                <span class="label">New password</span>
                <input class="input" type="password" name="new_password" autocomplete="new-password" minlength="10" required>
            </label>

            <label class="field">
                <span class="label">Confirm new password</span>
                <input class="input" type="password" name="confirm_password" autocomplete="new-password" minlength="10" required>
            </label>

            <div class="form-actions">
                <button class="btn btn-primary btn-block" type="submit"><?= icon("key") ?> Change password</button>
            </div>
        </form>
    </section>

</div>

<?php admin_shell_end(); ?>
