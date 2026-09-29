<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/admin-shell.php";

// ======================================================
// ROLES & PERMISSIONS
// Who the admins are, which role each one has, and what each role can open.
// The model (roles, permissions, defaults) is in includes/admin.php.
// Only the owner ("Super Admin") opens this page.
// ======================================================

$admin = admin_boot($pdo, "admins");

$myId = (int) $admin["id"];

$error = "";
$form = ["full_name" => "", "email" => "", "admin_role" => "staff"];
$openDialog = "";

// Active owners other than this one: there must always be one owner left who can log in.
$otherActiveOwners = function (int $exceptId) use ($pdo): int {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM users
         WHERE role = 'admin' AND status = 'active' AND id <> ?
         AND (admin_role IS NULL OR admin_role = '' OR admin_role = 'owner')"
    );
    $stmt->execute([$exceptId]);

    return (int) $stmt->fetchColumn();
};

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = (string) ($_POST["action"] ?? "");
    $targetId = (int) ($_POST["admin_id"] ?? 0);
    $done = true;

    $target = null;

    if ($targetId > 0) {
        $stmt = $pdo->prepare("SELECT id, full_name, email, status, admin_role FROM users WHERE id = ? AND role = 'admin' LIMIT 1");
        $stmt->execute([$targetId]);
        $target = $stmt->fetch() ?: null;
    }

    if (!admin_check_csrf()) {
        admin_flash("Your session expired. Please try again.", "error");

    } elseif ($action === "add") {

        $form["full_name"] = trim((string) ($_POST["full_name"] ?? ""));
        $form["email"] = strtolower(trim((string) ($_POST["email"] ?? "")));
        $form["admin_role"] = (string) ($_POST["admin_role"] ?? "staff");
        $password = (string) ($_POST["password"] ?? "");
        $confirm = (string) ($_POST["confirm_password"] ?? "");

        if ($form["full_name"] === "" || $form["email"] === "") {
            $error = "Please enter a name and an email address.";
        } elseif (!filter_var($form["email"], FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } elseif (!isset(ADMIN_ROLES[$form["admin_role"]])) {
            $error = "Please choose a role from the list.";
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
                        (full_name, email, phone, password, role, admin_role, status, email_verified, email_verified_at)
                     VALUES (?, ?, '', ?, 'admin', ?, 'active', 1, ?)"
                )->execute([
                    mb_substr($form["full_name"], 0, 150),
                    $form["email"],
                    password_hash($password, PASSWORD_DEFAULT),
                    $form["admin_role"],
                    utc_now(),
                ]);

                log_activity(
                    $pdo,
                    "admin.created",
                    "Added " . $form["full_name"] . " as " . admin_role_label($form["admin_role"]),
                    ["entity_type" => "admin", "entity_id" => (int) $pdo->lastInsertId(), "link" => "admins.php"]
                );

                admin_flash($form["full_name"] . " can log in now with " . $form["email"] . " and the password you set.");
            }
        }

        if ($error !== "") {
            $openDialog = "admin-add";
            $done = false;
        }

    } elseif ($action === "role" && $target) {

        $newRole = (string) ($_POST["admin_role"] ?? "");
        $oldRole = admin_role_of($target);

        if (!isset(ADMIN_ROLES[$newRole])) {
            admin_flash("Please choose a role from the list.", "error");
        } elseif ($targetId === $myId) {
            admin_flash("You cannot change your own role. Ask another Super Admin to do it.", "error");
        } elseif ($oldRole === "owner" && $newRole !== "owner" && $target["status"] === "active" && $otherActiveOwners($targetId) === 0) {
            admin_flash("There must always be one Super Admin who can log in.", "error");
        } elseif ($newRole !== $oldRole) {
            $pdo->prepare("UPDATE users SET admin_role = ? WHERE id = ? AND role = 'admin'")->execute([$newRole, $targetId]);

            log_activity(
                $pdo,
                "admin.role_changed",
                "Changed " . $target["full_name"] . "'s role from " . admin_role_label($oldRole) . " to " . admin_role_label($newRole),
                ["entity_type" => "admin", "entity_id" => $targetId, "link" => "admins.php"]
            );

            admin_flash($target["full_name"] . " is now " . admin_role_label($newRole) . ".");
        }

    } elseif (in_array($action, ["activate", "deactivate"], true) && $target) {

        $newStatus = $action === "activate" ? "active" : "inactive";

        if ($targetId === $myId) {
            admin_flash("You cannot turn off your own account.", "error");
        } elseif ($action === "deactivate" && admin_role_of($target) === "owner" && $otherActiveOwners($targetId) === 0) {
            admin_flash("There must always be one Super Admin who can log in.", "error");
        } elseif ($target["status"] !== $newStatus) {
            $pdo->prepare("UPDATE users SET status = ? WHERE id = ? AND role = 'admin'")->execute([$newStatus, $targetId]);

            log_activity(
                $pdo,
                $action === "activate" ? "admin.activated" : "admin.deactivated",
                ($action === "activate" ? "Turned on " : "Turned off ") . $target["full_name"] . "'s admin account",
                ["entity_type" => "admin", "entity_id" => $targetId, "link" => "admins.php"]
            );

            admin_flash(
                $action === "activate"
                    ? $target["full_name"] . " can log in again."
                    : $target["full_name"] . " can no longer log in. If they are logged in now, they are logged out on their next click."
            );
        }

    } elseif ($action === "permissions" || $action === "reset") {

        $before = admin_role_permissions($pdo);

        $submitted = $action === "reset"
            ? ADMIN_DEFAULT_PERMISSIONS
            : (is_array($_POST["can"] ?? null) ? $_POST["can"] : []);

        admin_save_role_permissions($pdo, $submitted);

        $changed = [];

        foreach (array_keys(ADMIN_DEFAULT_PERMISSIONS) as $role) {
            $now = array_values(array_diff(
                array_intersect(array_keys(ADMIN_PERMISSIONS), is_array($submitted[$role] ?? null) ? $submitted[$role] : []),
                ["admins"]
            ));

            $was = $before[$role];
            sort($now);
            sort($was);

            if ($now !== $was) {
                $changed[] = admin_role_label($role);

                log_activity(
                    $pdo,
                    "admin.permissions_changed",
                    "Changed what " . admin_role_label($role) . " can open",
                    ["entity_type" => "role", "link" => "admins.php#roles"]
                );
            }
        }

        admin_flash(
            $changed
                ? "Saved. " . implode(" and ", $changed) . " now " . (count($changed) === 1 ? "has" : "have") . " different access."
                : "Nothing changed."
        );

        header("Location: admins.php#roles");
        exit;

    } else {
        admin_flash("That admin no longer exists.", "error");
    }

    if ($done) {
        header("Location: admins.php");
        exit;
    }
}

$admins = $pdo->query(
    "SELECT id, full_name, email, profile_image, status, admin_role, created_at
     FROM users
     WHERE role = 'admin'
     ORDER BY status = 'active' DESC, id"
)->fetchAll();

$permissions = admin_role_permissions($pdo);

$roleCounts = array_fill_keys(array_keys(ADMIN_ROLES), 0);

foreach ($admins as $row) {
    $roleCounts[admin_role_of($row)]++;
}

$isDefault = true;

foreach (ADMIN_DEFAULT_PERMISSIONS as $role => $defaults) {
    $current = $permissions[$role];
    sort($current);
    sort($defaults);

    if ($current !== $defaults) {
        $isDefault = false;
    }
}

$roleTones = ["owner" => "violet", "manager" => "blue", "staff" => "cyan"];

admin_shell_head([
    "title" => "Roles & Permissions",
    "subtitle" => "Who manages the house, and what each role can open",
    "active" => "admins",
]);
?>
<style>
    .role-select {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .role-select .select {
        width: auto;
        min-width: 150px;
    }

    .roles {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
        padding: 0 20px 18px;
    }

    .role-card {
        display: flex;
        gap: 12px;
        min-width: 0;
        padding: 14px;
        border: 1px solid var(--line);
        border-radius: var(--radius);
        background: var(--surface-2);
    }

    .role-card .tile-icon {
        width: 38px;
        height: 38px;
        border-radius: 11px;
        font-size: 18px;
    }

    .role-card strong {
        display: block;
        font-size: 14px;
    }

    .role-card p {
        margin-top: 2px;
        color: var(--text-2);
        font-size: 12.5px;
    }

    .role-card small {
        display: block;
        margin-top: 6px;
        color: var(--text-3);
        font-size: 12px;
    }

    .matrix th,
    .matrix td {
        text-align: center;
    }

    .matrix th:first-child,
    .matrix td:first-child {
        text-align: left;
    }

    .matrix td:first-child {
        min-width: 220px;
    }

    .matrix .check {
        justify-content: center;
        min-width: 44px;
        min-height: 36px;
    }

    .matrix-foot {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 14px 20px;
        border-top: 1px solid var(--line);
    }

    @media (max-width: 1100px) {
        .roles {
            grid-template-columns: minmax(0, 1fr);
        }
    }

    @media (max-width: 767px) {
        .roles {
            padding: 0 16px 16px;
        }

        .role-select,
        .role-select .select {
            width: 100%;
        }

        .matrix td:first-child {
            min-width: 150px;
        }

        .matrix-foot {
            padding: 14px 16px;
        }

        .matrix-foot .btn {
            flex: 1 1 auto;
        }
    }
</style>
<?php admin_shell_body(); ?>

<div class="stack">

    <!-- ADMINS -->

    <section class="card">
        <div class="card-head">
            <div>
                <h2 class="card-title">Admins</h2>
                <p class="card-sub">People who can log in to this panel</p>
            </div>
            <button class="btn btn-primary btn-sm" type="button" data-dialog-open="admin-add"><?= icon("user-plus") ?> Add admin</button>
        </div>

        <div class="table-wrap">
            <table class="table table-stack">
                <thead>
                    <tr>
                        <th>Admin</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th class="hide-narrow">Added</th>
                        <th class="right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($admins as $row): ?>
                        <?php
                        $id = (int) $row["id"];
                        $role = admin_role_of($row);
                        $isMe = $id === $myId;
                        $active = $row["status"] === "active";
                        ?>
                        <tr>
                            <td class="cell-lead">
                                <div class="person">
                                    <?= admin_avatar($row, 34) ?>
                                    <div class="person-text">
                                        <span class="person-name">
                                            <?= h($row["full_name"]) ?>
                                            <?php if ($isMe): ?><span class="tag" style="margin-left:4px">You</span><?php endif; ?>
                                        </span>
                                        <span class="person-sub"><?= h($row["email"]) ?></span>
                                    </div>
                                </div>
                            </td>

                            <td data-label="Role">
                                <?php if ($isMe): ?>
                                    <span class="strong"><?= h(admin_role_label($role)) ?></span>
                                <?php else: ?>
                                    <form class="role-select" method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="role">
                                        <input type="hidden" name="admin_id" value="<?= $id ?>">
                                        <label class="sr-only" for="role-<?= $id ?>">Role of <?= h($row["full_name"]) ?></label>
                                        <select class="select select-sm" id="role-<?= $id ?>" name="admin_role" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
                                            <?php foreach (ADMIN_ROLES as $key => $info): ?>
                                                <option value="<?= h($key) ?>" <?= $key === $role ? "selected" : "" ?>><?= h($info["label"]) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <noscript><button class="btn btn-sm" type="submit">Save</button></noscript>
                                    </form>
                                <?php endif; ?>
                            </td>

                            <td data-label="Status"><?= status_pill($active ? "active" : "inactive") ?></td>

                            <td data-label="Added" class="nowrap soft hide-narrow"><?= h(admin_date($row["created_at"])) ?></td>

                            <td class="cell-wide">
                                <div class="table-actions">
                                    <?php if ($isMe): ?>
                                        <a class="btn btn-sm btn-ghost" href="account.php"><?= icon("key") ?> Change password</a>
                                    <?php elseif ($active): ?>
                                        <form class="inline-form" method="post"
                                            data-confirm="<?= h($row["full_name"]) ?> will no longer be able to log in to the admin panel. You can turn the account on again at any time."
                                            data-confirm-title="Turn off this admin?"
                                            data-confirm-ok="Turn off"
                                            data-confirm-tone="danger"
                                        >
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="deactivate">
                                            <input type="hidden" name="admin_id" value="<?= $id ?>">
                                            <button class="btn btn-sm btn-danger-soft" type="submit">Turn off</button>
                                        </form>
                                    <?php else: ?>
                                        <form class="inline-form" method="post"
                                            data-confirm="<?= h($row["full_name"]) ?> will be able to log in again as <?= h(admin_role_label($role)) ?>."
                                            data-confirm-title="Turn on this admin?"
                                            data-confirm-ok="Turn on"
                                        >
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="activate">
                                            <input type="hidden" name="admin_id" value="<?= $id ?>">
                                            <button class="btn btn-sm btn-soft" type="submit">Turn on</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>


    <!-- ROLES -->

    <section class="card" id="roles">
        <div class="card-head">
            <div>
                <h2 class="card-title">What each role can open</h2>
                <p class="card-sub">The Dashboard, Notifications, Help and one's own password are open to every admin</p>
            </div>
        </div>

        <div class="roles">
            <?php foreach (ADMIN_ROLES as $key => $info): ?>
                <div class="role-card">
                    <span class="tile-icon tone-<?= $roleTones[$key] ?>"><?= icon($key === "owner" ? "shield" : ($key === "manager" ? "clipboard" : "headset")) ?></span>
                    <div>
                        <strong><?= h($info["label"]) ?></strong>
                        <p><?= h($info["about"]) ?></p>
                        <small><?= $roleCounts[$key] ?> <?= $roleCounts[$key] === 1 ? "admin" : "admins" ?></small>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <form method="post" id="permissions-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="permissions">

            <div class="table-wrap">
                <table class="table matrix">
                    <thead>
                        <tr>
                            <th scope="col">Part of the panel</th>
                            <?php foreach (ADMIN_ROLES as $info): ?>
                                <th scope="col"><?= h($info["label"]) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (ADMIN_PERMISSIONS as $permission => $info): ?>
                            <tr>
                                <th scope="row" style="font-weight:400;white-space:normal">
                                    <span class="cell-main" style="color:var(--text);font-size:13px"><?= h($info["label"]) ?></span>
                                    <span class="cell-sub"><?= h($info["about"]) ?></span>
                                </th>

                                <?php foreach (ADMIN_ROLES as $role => $roleInfo): ?>
                                    <?php
                                    $locked = $role === "owner" || $permission === "admins";
                                    $checked = in_array($permission, $permissions[$role], true);
                                    ?>
                                    <td>
                                        <label class="check">
                                            <input
                                                type="checkbox"
                                                name="can[<?= h($role) ?>][]"
                                                value="<?= h($permission) ?>"
                                                <?= $checked ? "checked" : "" ?>
                                                <?= $locked ? "disabled" : "" ?>
                                                aria-label="<?= h($roleInfo["label"] . " can open " . $info["label"]) ?>"
                                            >
                                        </label>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </form>

        <div class="matrix-foot">
            <p class="muted" style="font-size:12.5px">
                Super Admin always opens everything, and only a Super Admin manages admins.
            </p>

            <div class="row row-wrap">
                <form class="inline-form" method="post"
                    data-confirm="Manager and Front Desk go back to the access they had at the start."
                    data-confirm-title="Reset to the defaults?"
                    data-confirm-ok="Reset"
                >
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reset">
                    <button class="btn" type="submit" <?= $isDefault ? "disabled" : "" ?>>Reset to the defaults</button>
                </form>

                <button class="btn btn-primary" type="submit" form="permissions-form"><?= icon("save") ?> Save access</button>
            </div>
        </div>
    </section>

</div>


<!-- ADD AN ADMIN -->

<dialog class="dialog dialog-wide" id="admin-add" aria-labelledby="admin-add-title" <?= $openDialog === "admin-add" ? "data-dialog-auto" : "" ?>>
    <form method="post" autocomplete="off">
        <div class="dialog-head">
            <h2 id="admin-add-title" tabindex="-1" autofocus>Add an admin</h2>
            <button class="btn btn-ghost btn-icon" type="button" data-dialog-close aria-label="Close"><?= icon("x") ?></button>
        </div>

        <div class="dialog-body">
            <?php if ($error !== ""): ?>
                <div class="alert alert-error" role="alert"><?= icon("alert") ?><p><?= h($error) ?></p></div>
            <?php endif; ?>

            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">

            <div class="form-grid">
                <label class="field">
                    <span class="label">Full name</span>
                    <input class="input" name="full_name" value="<?= h($form["full_name"]) ?>" autocomplete="off" maxlength="150" required>
                </label>

                <label class="field">
                    <span class="label">Email address</span>
                    <input class="input" type="email" name="email" value="<?= h($form["email"]) ?>" autocomplete="off" autocapitalize="none" spellcheck="false" required>
                </label>

                <label class="field field-wide">
                    <span class="label">Role</span>
                    <select class="select" name="admin_role">
                        <?php foreach (ADMIN_ROLES as $key => $info): ?>
                            <option value="<?= h($key) ?>" <?= $form["admin_role"] === $key ? "selected" : "" ?>><?= h($info["label"] . ": " . $info["about"]) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="field">
                    <span class="label">Password</span>
                    <input class="input" type="password" name="password" autocomplete="new-password" minlength="10" required>
                </label>

                <label class="field">
                    <span class="label">Confirm password</span>
                    <input class="input" type="password" name="confirm_password" autocomplete="new-password" minlength="10" required>
                </label>
            </div>

            <p class="hint">
                At least 10 characters, with letters and numbers. The new admin logs in with this email and
                password on the normal login page, and can change the password afterwards.
            </p>
        </div>

        <div class="dialog-actions">
            <button class="btn" type="button" data-dialog-close>Cancel</button>
            <button class="btn btn-primary" type="submit">Create admin account</button>
        </div>
    </form>
</dialog>

<?php admin_shell_end(); ?>
