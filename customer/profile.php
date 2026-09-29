<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/customer-shell.php";

$me = customer_boot($pdo);
$userId = (int) $me["id"];

const PROFILE_PHOTO_MAX_BYTES = 2 * 1024 * 1024;
const PROFILE_PHOTO_TYPES = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];

$errors = ["details" => "", "password" => ""];
$form = ["full_name" => $me["full_name"], "phone" => (string) ($me["phone"] ?? "")];

// Removes a profile picture this site stored (never a picture from elsewhere).
function remove_profile_photo(?string $path): void
{
    if ($path && str_starts_with($path, "uploads/profiles/") && is_file(__DIR__ . "/../" . $path)) {
        @unlink(__DIR__ . "/../" . $path);
    }
}


// ======================================================
// SAVING
// ======================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = (string) ($_POST["action"] ?? "");

    // a file larger than the server takes arrives with nothing in the form
    if (!$_POST && (int) ($_SERVER["CONTENT_LENGTH"] ?? 0) > 0) {
        admin_flash("That picture is too large. Please choose one of 2 MB or less.", "error");
        header("Location: profile.php");
        exit;
    }

    if (!admin_check_csrf()) {
        admin_flash("Your session expired. Please try again.", "error");
        header("Location: profile.php");
        exit;
    }

    // ---- profile picture ----

    if ($action === "photo") {
        $file = $_FILES["profile_image"] ?? null;
        $problem = "";

        if (!$file || $file["error"] !== UPLOAD_ERR_OK || !is_uploaded_file($file["tmp_name"])) {
            $problem = in_array($file["error"] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? "That picture is too large. Please choose one of 2 MB or less."
                : "Please choose a picture.";
        } else {
            // the file's real type, not what the browser says it is
            $type = (new finfo(FILEINFO_MIME_TYPE))->file($file["tmp_name"]);

            if (!isset(PROFILE_PHOTO_TYPES[$type])) {
                $problem = "Only JPG, PNG and WEBP pictures can be used.";
            } elseif ($file["size"] > PROFILE_PHOTO_MAX_BYTES) {
                $problem = "That picture is too large. Please choose one of 2 MB or less.";
            }
        }

        if ($problem === "") {
            $folder = __DIR__ . "/../uploads/profiles/";

            if (!is_dir($folder)) {
                mkdir($folder, 0755, true);
            }

            $name = "user_" . $userId . "_" . time() . "." . PROFILE_PHOTO_TYPES[$type];

            if (move_uploaded_file($file["tmp_name"], $folder . $name)) {
                remove_profile_photo($me["profile_image"] ?? null);

                $pdo->prepare("UPDATE users SET profile_image = ? WHERE id = ?")
                    ->execute(["uploads/profiles/" . $name, $userId]);

                admin_flash("Your profile picture is updated.");
            } else {
                $problem = "The picture could not be saved. Please try again.";
            }
        }

        if ($problem !== "") {
            admin_flash($problem, "error");
        }

        header("Location: profile.php");
        exit;
    }

    if ($action === "photo_remove") {
        remove_profile_photo($me["profile_image"] ?? null);
        $pdo->prepare("UPDATE users SET profile_image = NULL WHERE id = ?")->execute([$userId]);

        admin_flash("Your profile picture is removed.");
        header("Location: profile.php");
        exit;
    }

    // ---- name and phone ----

    if ($action === "details") {
        $form["full_name"] = preg_replace('/\s+/', " ", trim((string) ($_POST["full_name"] ?? "")));
        $form["phone"] = trim((string) ($_POST["phone"] ?? ""));

        if (mb_strlen($form["full_name"]) < 2 || mb_strlen($form["full_name"]) > 150) {
            $errors["details"] = "Please enter your full name.";
        } elseif ($form["phone"] !== "" && !preg_match('/^\+?[0-9 ()\-]{7,20}$/', $form["phone"])) {
            $errors["details"] = "Please enter a phone number with digits only, for example 0917 123 4567.";
        } else {
            $pdo->prepare("UPDATE users SET full_name = ?, phone = ? WHERE id = ?")
                ->execute([$form["full_name"], $form["phone"], $userId]);

            $_SESSION["full_name"] = $form["full_name"];

            admin_flash("Your details are saved.");
            header("Location: profile.php");
            exit;
        }
    }

    // ---- password ----

    if ($action === "password") {
        $current = (string) ($_POST["current_password"] ?? "");
        $new = (string) ($_POST["new_password"] ?? "");
        $confirm = (string) ($_POST["confirm_password"] ?? "");

        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $hash = $stmt->fetchColumn();

        if ($hash === false || $hash === null || !password_verify($current, (string) $hash)) {
            $errors["password"] = "Your current password is not right.";
        } elseif (strlen($new) < 8) {
            $errors["password"] = "The new password needs at least 8 characters.";
        } elseif ($new !== $confirm) {
            $errors["password"] = "The two new passwords are not the same.";
        } elseif (password_verify($new, (string) $hash)) {
            $errors["password"] = "That is your current password. Please choose a new one.";
        } else {
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
                ->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);

            log_activity($pdo, "customer.password_changed", ($me["full_name"] ?: "A customer") . " changed their password", [
                "entity_type" => "customer",
                "entity_id" => $userId,
            ]);

            admin_flash("Your password is changed. Use the new one next time you log in.");
            header("Location: profile.php");
            exit;
        }
    }
}

customer_shell_head([
    "title" => "My Profile",
    "subtitle" => "Your picture, your details and your password",
    "active" => "profile",
]);
?>
<?php customer_shell_body(); ?>

<div class="grid grid-side">

    <!-- WHO YOU ARE -->

    <section class="card profile-hero">
        <div class="profile-photo">
            <?= admin_avatar($me, 104) ?>
            <button class="profile-photo-btn" type="button" data-dialog-open="photo-dialog" aria-label="Change your profile picture">
                <?= icon("camera") ?>
            </button>
        </div>

        <h2><?= h($me["full_name"]) ?></h2>
        <p><?= h($me["email"]) ?></p>

        <ul class="profile-facts">
            <li>
                <span>Email</span>
                <strong><?= (int) ($me["email_verified"] ?? 0) === 1 ? status_pill("verified", "Verified") : status_pill("pending", "Not verified") ?></strong>
            </li>
            <li><span>Phone</span><strong><?= h(($me["phone"] ?? "") !== "" ? $me["phone"] : "Not added") ?></strong></li>
            <li><span>Signs in with</span><strong><?= !empty($me["google_id"]) ? "Google" . ($me["has_password"] ? " or password" : "") : "Email and password" ?></strong></li>
            <li><span>Guest since</span><strong><?= h(admin_date($me["created_at"], "F Y")) ?></strong></li>
        </ul>

        <div class="row row-wrap" style="justify-content:center;margin-top:14px">
            <button class="btn" type="button" data-dialog-open="photo-dialog"><?= icon("camera") ?> Change picture</button>
            <?php if (!empty($me["profile_image"])): ?>
                <form class="inline-form" method="post"
                    data-confirm="Your picture will be removed. Your initials show in its place."
                    data-confirm-title="Remove your picture?"
                    data-confirm-ok="Remove"
                    data-confirm-tone="danger"
                >
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="photo_remove">
                    <button class="btn btn-ghost" type="submit">Remove</button>
                </form>
            <?php endif; ?>
        </div>
    </section>


    <div class="col">

        <!-- NAME AND PHONE -->

        <section class="card card-pad" id="details">
            <h2 class="card-title">Your details</h2>
            <p class="card-sub" style="margin-bottom:16px">We use them for your bookings and to reach you about your stay.</p>

            <?php if ($errors["details"] !== ""): ?>
                <div class="alert alert-error alert-in" role="alert"><?= icon("alert") ?><p><?= h($errors["details"]) ?></p></div>
            <?php endif; ?>

            <form method="post" action="profile.php#details">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="details">

                <div class="form-grid">
                    <label class="field field-wide">
                        <span class="label">Full name</span>
                        <input class="input" type="text" name="full_name" value="<?= h($form["full_name"]) ?>" autocomplete="name" maxlength="150" required>
                    </label>

                    <label class="field">
                        <span class="label">Phone number</span>
                        <input class="input" type="tel" name="phone" value="<?= h($form["phone"]) ?>" autocomplete="tel" inputmode="tel" maxlength="20" placeholder="0917 123 4567">
                    </label>

                    <label class="field">
                        <span class="label">Email</span>
                        <input class="input" type="email" value="<?= h($me["email"]) ?>" disabled>
                    </label>
                </div>

                <p class="hint">Need to change your email? <a href="messages.php">Message us</a> and we will help.</p>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit"><?= icon("save") ?> Save details</button>
                </div>
            </form>
        </section>


        <!-- PASSWORD -->

        <section class="card card-pad" id="password">
            <h2 class="card-title">Password</h2>
            <p class="card-sub" style="margin-bottom:16px">
                <?php if (!empty($me["google_id"])): ?>
                    You can keep signing in with Google. A password is only needed to log in with your email.
                <?php else: ?>
                    Use at least 8 characters. A longer password is harder to guess.
                <?php endif; ?>
            </p>

            <?php if ($errors["password"] !== ""): ?>
                <div class="alert alert-error alert-in" role="alert"><?= icon("alert") ?><p><?= h($errors["password"]) ?></p></div>
            <?php endif; ?>

            <form method="post" action="profile.php#password">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="password">
                <input type="hidden" name="username" value="<?= h($me["email"]) ?>" autocomplete="username">

                <div class="form-grid">
                    <label class="field field-wide">
                        <span class="label">Current password</span>
                        <input class="input" type="password" name="current_password" autocomplete="current-password" required>
                    </label>

                    <label class="field">
                        <span class="label">New password</span>
                        <input class="input" type="password" name="new_password" autocomplete="new-password" minlength="8" required>
                    </label>

                    <label class="field">
                        <span class="label">New password again</span>
                        <input class="input" type="password" name="confirm_password" autocomplete="new-password" minlength="8" required>
                    </label>
                </div>

                <?php if (!empty($me["google_id"])): ?>
                    <p class="hint">Signed up with Google and never made a password? Then there is none to change: keep using “Continue with Google”.</p>
                <?php endif; ?>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit"><?= icon("key") ?> Change password</button>
                </div>
            </form>
        </section>

    </div>

</div>


<!-- ======================================================
     CHANGE PROFILE PICTURE
====================================================== -->

<dialog class="dialog" id="photo-dialog" aria-labelledby="photo-title">
    <form method="post" enctype="multipart/form-data" id="photo-form">
        <div class="dialog-head">
            <h2 id="photo-title" tabindex="-1" autofocus>Profile picture</h2>
            <button class="btn btn-ghost btn-icon" type="button" data-dialog-close aria-label="Close"><?= icon("x") ?></button>
        </div>

        <div class="dialog-body">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="photo">

            <div class="photo-preview" id="photo-preview">
                <?php if (!empty($me["profile_image"])): ?>
                    <img src="../<?= h($me["profile_image"]) ?>" alt="" id="photo-image">
                <?php else: ?>
                    <img src="" alt="" id="photo-image" hidden>
                    <span id="photo-placeholder"><?= icon("user") ?></span>
                <?php endif; ?>
            </div>

            <p class="photo-hint" id="photo-hint">JPG, PNG or WEBP · up to 2 MB</p>

            <input class="photo-file" type="file" name="profile_image" id="profile_image" tabindex="-1" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required>
        </div>

        <div class="dialog-actions">
            <button class="btn" type="button" id="photo-choose"><?= icon("image") ?> Choose picture</button>
            <button class="btn btn-primary" type="submit" id="photo-save" disabled>Save</button>
        </div>
    </form>
</dialog>

<script>
    // the chosen picture shows before it is saved; a wrong type or size is said at once
    (function () {
        var input = document.getElementById("profile_image");
        var image = document.getElementById("photo-image");
        var placeholder = document.getElementById("photo-placeholder");
        var hint = document.getElementById("photo-hint");
        var save = document.getElementById("photo-save");
        var form = document.getElementById("photo-form");
        var dialog = document.getElementById("photo-dialog");
        var original = image.getAttribute("src");
        var defaultHint = hint.textContent;
        var allowed = ["image/jpeg", "image/png", "image/webp"];
        var previewUrl = "";

        function reset() {
            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
                previewUrl = "";
            }

            form.reset();
            save.disabled = true;
            save.textContent = "Save";
            hint.textContent = defaultHint;
            hint.classList.remove("is-error");

            if (original) {
                image.src = original;
            } else {
                image.hidden = true;
                if (placeholder) placeholder.hidden = false;
            }
        }

        dialog.addEventListener("close", reset);

        document.getElementById("photo-choose").addEventListener("click", function () {
            input.click();
        });

        input.addEventListener("change", function () {
            var file = input.files[0];

            if (!file) return;

            if (allowed.indexOf(file.type) === -1) {
                hint.textContent = "Please choose a JPG, PNG or WEBP picture.";
                hint.classList.add("is-error");
                save.disabled = true;
                return;
            }

            if (file.size > <?= PROFILE_PHOTO_MAX_BYTES ?>) {
                hint.textContent = "That picture is over 2 MB. Please choose a smaller one.";
                hint.classList.add("is-error");
                save.disabled = true;
                return;
            }

            if (previewUrl) URL.revokeObjectURL(previewUrl);

            previewUrl = URL.createObjectURL(file);
            image.src = previewUrl;
            image.hidden = false;
            if (placeholder) placeholder.hidden = true;

            hint.textContent = file.name;
            hint.classList.remove("is-error");
            save.disabled = false;
        });

        form.addEventListener("submit", function () {
            // after the browser has read the form, so the file still goes
            setTimeout(function () {
                save.disabled = true;
                save.textContent = "Saving…";
            }, 0);
        });
    })();
</script>

<?php customer_shell_end(); ?>
