<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/icons.php";
require_once __DIR__ . "/../includes/chat.php";

ensure_chat_schema($pdo);
$inboxUnread = chat_unread_for_customer($pdo, (int) ($_SESSION["user_id"] ?? 0));


// ======================================================
// CUSTOMER LOGIN REQUIRED
// ======================================================

if (
    !isset($_SESSION["user_id"])
    || ($_SESSION["role"] ?? "") !== "customer"
) {
    header("Location: ../login.php");
    exit;
}

$user_id = (int) $_SESSION["user_id"];


// ======================================================
// GET USER
// ======================================================

$stmt = $pdo->prepare("
    SELECT *
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$user_id]);

$user = $stmt->fetch();

if (!$user) {

    session_destroy();

    header("Location: ../login.php");

    exit;
}


// ======================================================
// MESSAGES
// ======================================================

$message = "";
$error = "";


// ======================================================
// PROFILE IMAGE UPLOAD
// ======================================================

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["upload_profile"])
) {

    if (
        !isset($_FILES["profile_image"])
        || $_FILES["profile_image"]["error"] !== UPLOAD_ERR_OK
    ) {

        $error = "Please choose an image.";

    } else {

        $file = $_FILES["profile_image"];

        // Use finfo instead of trusting the browser MIME type.
        $finfo =
            new finfo(FILEINFO_MIME_TYPE);

        $mimeType =
            $finfo->file(
                $file["tmp_name"]
            );

        $allowedTypes = [
            "image/jpeg" => "jpg",
            "image/png" => "png",
            "image/webp" => "webp"
        ];


        if (
            !isset(
                $allowedTypes[
                    $mimeType
                ]
            )
        ) {

            $error =
                "Only JPG, PNG, and WEBP images are allowed.";

        } elseif (
            $file["size"]
            > 2 * 1024 * 1024
        ) {

            $error =
                "Image must be 2MB or smaller.";

        } else {

            $extension =
                $allowedTypes[
                    $mimeType
                ];

            $newFileName =
                "user_" .
                $user_id .
                "_" .
                time() .
                "." .
                $extension;


            $uploadDirectory =
                __DIR__ .
                "/../uploads/profiles/";


            if (
                !is_dir(
                    $uploadDirectory
                )
            ) {

                mkdir(
                    $uploadDirectory,
                    0777,
                    true
                );
            }


            $targetPath =
                $uploadDirectory .
                $newFileName;


            if (
                move_uploaded_file(
                    $file["tmp_name"],
                    $targetPath
                )
            ) {

                $databasePath =
                    "uploads/profiles/" .
                    $newFileName;


                // Delete old locally stored profile picture.
                if (
                    !empty(
                        $user["profile_image"]
                    )
                    &&
                    str_starts_with(
                        $user["profile_image"],
                        "uploads/profiles/"
                    )
                ) {

                    $oldImagePath =
                        __DIR__ .
                        "/../" .
                        $user["profile_image"];

                    if (
                        is_file(
                            $oldImagePath
                        )
                    ) {

                        @unlink(
                            $oldImagePath
                        );
                    }
                }


                $stmt = $pdo->prepare("
                    UPDATE users
                    SET profile_image = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $databasePath,
                    $user_id
                ]);


                $message =
                    "Profile picture updated successfully.";

                $user["profile_image"] =
                    $databasePath;

            } else {

                $error =
                    "Failed to upload image.";
            }
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
        content="#fffaf4"
    >

    <title>
        My Profile | ARVE'S House
    </title>

    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html {
            scroll-behavior: smooth;
            -webkit-tap-highlight-color: transparent;
            -webkit-text-size-adjust: 100%;
            text-size-adjust: 100%;
        }

        :root {
            --cream: #fffaf4;
            --cream-2: #f4e7d9;
            --brown: #7a4f36;
            --brown-dark: #513421;
            --gold: #d4a76a;
            --text: #241a15;
            --muted: #786d66;
            --white: rgba(255,255,255,.92);
            --border: rgba(122,79,54,.14);
            --shadow:
                0 18px 50px
                rgba(76,50,34,.10);

            /* Motion tokens */
            --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            --ease-in-out: cubic-bezier(0.77, 0, 0.175, 1);
            --ease-drawer: cubic-bezier(0.32, 0.72, 0, 1);
        }

        body {

            min-height: 100vh;
            min-height: 100svh;

            /* Landscape notch: keep content out of the side insets
               (0px everywhere else, so desktop is unchanged). */
            padding-left:
                env(safe-area-inset-left, 0px);

            padding-right:
                env(safe-area-inset-right, 0px);

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            color:
                var(--text);

            background:
                radial-gradient(
                    circle at 8% 10%,
                    rgba(212,167,106,.22),
                    transparent 28%
                ),
                radial-gradient(
                    circle at 92% 45%,
                    rgba(122,79,54,.11),
                    transparent 28%
                ),
                linear-gradient(
                    135deg,
                    #fffdf9 0%,
                    #f8f0e6 48%,
                    #eee0d1 100%
                );
        }

        a {
            color: inherit;
        }


        /* ==================================================
           TOUCH CONTROLS
        ================================================== */

        /* No double-tap-zoom wait on tappable controls. */
        button,
        .brand,
        .nav-links a,
        .mini-link,
        .file-zone {

            touch-action: manipulation;
        }

        /* Long-press must not select button labels (content stays selectable). */
        button,
        .submit-button,
        .mini-link,
        .logout-link {

            -webkit-user-select: none;

            user-select: none;
        }


        /* ==================================================
           NAVBAR
        ================================================== */

        .navbar {

            min-height: 78px;
            min-height:
                calc(78px + env(safe-area-inset-top, 0px));

            padding:
                0 7%;

            /* Sticky bar clears the notch/status bar (0px on desktop). */
            padding-top:
                env(safe-area-inset-top, 0px);

            display: flex;

            align-items: center;

            justify-content:
                space-between;

            gap: 18px;

            position: sticky;

            top: 0;

            z-index: 1000;

            background:
                rgba(255,250,244,.90);

            backdrop-filter:
                blur(16px);

            border-bottom:
                1px solid
                var(--border);
        }

        .brand {

            display: flex;

            align-items: center;

            gap: 11px;

            color:
                var(--text);

            text-decoration: none;

            font-size: 21px;

            font-weight: 800;
        }

        .brand-mark {

            width: 43px;

            height: 43px;

            border-radius: 13px;

            display: grid;

            place-items: center;

            background:
                linear-gradient(
                    135deg,
                    var(--brown),
                    var(--brown-dark)
                );

            color: white;

            box-shadow:
                0 8px 20px
                rgba(90,56,38,.20);
        }

        .brand small {

            display: block;

            color:
                var(--muted);

            font-size: 9px;

            font-weight: normal;

            letter-spacing: 1.2px;
        }

        .nav-links {

            display: flex;

            align-items: center;

            gap: 7px;
        }

        .nav-links a {

            padding:
                9px 12px;

            border-radius: 9px;

            color:
                var(--text);

            text-decoration: none;

            font-size: 13px;

            font-weight: 700;

            transition:
                background-color 180ms ease,
                color 180ms ease,
                transform 140ms var(--ease-out);
        }

        .nav-links a:active {

            transform: scale(0.97);
        }

        /* Touch taps fake a sticky :hover, so only real pointers get it. */
        @media (hover: hover) and (pointer: fine) {

            .nav-links a:hover {

                background:
                    rgba(122,79,54,.08);

                color:
                    var(--brown);
            }
        }

        /* Keyboard parity for the gated hover (Logout keeps its own fill). */
        .nav-links a:not(.logout-link):focus-visible {

            background:
                rgba(122,79,54,.08);

            color:
                var(--brown);
        }

        .logout-link {

            background:
                linear-gradient(
                    135deg,
                    var(--brown),
                    var(--brown-dark)
                );

            color:
                white !important;
        }


        /* ==================================================
           PAGE
        ================================================== */

        .page {

            width: 90%;

            max-width: 1050px;

            margin:
                45px auto 70px;
        }

        .page-heading {

            max-width: 680px;

            margin:
                0 auto 28px;

            text-align: center;
        }

        .page-heading span {

            color:
                var(--brown);

            font-size: 10px;

            font-weight: 800;

            letter-spacing: 3px;

            text-transform:
                uppercase;
        }

        .page-heading h1 {

            margin:
                8px 0 9px;

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size:
                clamp(
                    34px,
                    5vw,
                    48px
                );
        }

        .page-heading p {

            color:
                var(--muted);

            font-size: 13px;
        }


        /* ==================================================
           PROFILE CARD
        ================================================== */

        .profile-grid {

            display: grid;

            grid-template-columns:
                .85fr 1.15fr;

            gap: 26px;

            align-items: stretch;
        }

        .profile-card,
        .upload-card {

            border:
                1px solid
                rgba(255,255,255,.90);

            border-radius: 24px;

            background:
                rgba(255,255,255,.88);

            backdrop-filter:
                blur(14px);

            box-shadow:
                var(--shadow);
        }

        .profile-card {

            padding: 30px;

            display: flex;

            flex-direction: column;

            align-items: center;

            justify-content: center;

            text-align: center;
        }

        .profile-image-shell {

            width: 170px;

            height: 170px;

            margin-bottom: 20px;

            padding: 7px;

            border-radius: 50%;

            background:
                linear-gradient(
                    135deg,
                    #efd3b2,
                    var(--brown)
                );

            box-shadow:
                0 14px 35px
                rgba(90,56,38,.18);
        }

        .profile-image {

            width: 100%;

            height: 100%;

            border-radius: 50%;

            object-fit: cover;

            display: block;

            background: white;
        }

        .placeholder {

            width: 100%;

            height: 100%;

            border-radius: 50%;

            display: grid;

            place-items: center;

            background:
                linear-gradient(
                    145deg,
                    #ead7c5,
                    #c49a78
                );

            color: white;

            font-size: 68px;
        }

        .profile-card h2 {

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 28px;

            margin-bottom: 5px;
        }

        .profile-email {

            color:
                var(--muted);

            font-size: 13px;
        }

        .role-pill {

            display: inline-flex;

            align-items: center;

            margin-top: 12px;

            padding:
                6px 10px;

            border-radius: 20px;

            background:
                rgba(122,79,54,.08);

            color:
                var(--brown);

            font-size: 10px;

            font-weight: 800;

            letter-spacing: .7px;

            text-transform:
                uppercase;
        }

        .profile-actions {

            width: 100%;

            margin-top: 24px;

            display: grid;

            grid-template-columns:
                1fr 1fr;

            gap: 10px;
        }

        .mini-link {

            min-height: 43px;

            display: flex;

            align-items: center;

            justify-content: center;

            border:
                1px solid
                var(--border);

            border-radius: 10px;

            background:
                rgba(255,250,244,.62);

            color:
                var(--brown-dark);

            text-decoration: none;

            font-size: 11px;

            font-weight: 800;

            transition:
                transform 140ms var(--ease-out);
        }

        .mini-link:active {

            transform: scale(0.97);
        }


        /* ==================================================
           UPLOAD CARD
        ================================================== */

        .upload-card {

            padding: 30px;
        }

        .upload-card h2 {

            margin-bottom: 7px;

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 27px;
        }

        .upload-card > p {

            margin-bottom: 22px;

            color:
                var(--muted);

            font-size: 12px;

            line-height: 1.6;
        }

        .alert {

            margin-bottom: 18px;

            padding:
                12px 14px;

            border-radius: 10px;

            font-size: 12px;

            /* Rendered once per upload POST, so a one-shot keyframe is fine. */
            animation:
                alert-in 240ms var(--ease-out) both;
        }

        @keyframes alert-in {

            from {
                opacity: 0;
                transform: translateY(-4px);
            }
        }

        .success {

            border:
                1px solid #bbf7d0;

            background: #dcfce7;

            color: #166534;
        }

        .error {

            border:
                1px solid #fecaca;

            background: #fee2e2;

            color: #991b1b;
        }


        /* ==================================================
           DROP ZONE
        ================================================== */

        .file-zone {

            position: relative;

            min-height: 235px;

            border:
                2px dashed
                rgba(122,79,54,.24);

            border-radius: 18px;

            display: flex;

            align-items: center;

            justify-content: center;

            padding: 25px;

            text-align: center;

            background:
                linear-gradient(
                    135deg,
                    rgba(255,250,244,.76),
                    rgba(244,231,217,.46)
                );

            transition:
                border-color 180ms ease,
                background-color 180ms ease,
                transform 140ms var(--ease-out);
        }

        /* Large tap target: barely-there press, confirms the picker is opening. */
        .file-zone:active {

            transform: scale(0.99);
        }

        /* The real input is invisible, so show keyboard focus on the zone. */
        .file-zone:has(input:focus-visible) {

            outline:
                2px solid
                var(--brown);

            outline-offset: 3px;
        }

        .file-zone.dragging {

            border-color:
                var(--brown);

            background:
                rgba(122,79,54,.08);
        }

        .file-zone input {

            position: absolute;

            inset: 0;

            width: 100%;

            height: 100%;

            opacity: 0;

            cursor: pointer;
        }

        .upload-icon {

            font-size: 42px;

            margin-bottom: 10px;
        }

        .file-zone h3 {

            margin-bottom: 6px;

            font-size: 15px;
        }

        .file-zone p {

            color:
                var(--muted);

            font-size: 11px;

            line-height: 1.5;
        }

        .chosen-file {

            margin-top: 12px;

            color:
                var(--brown);

            font-size: 11px;

            font-weight: 800;
        }

        .preview-wrap {

            display: none;

            margin-top: 18px;

            padding: 14px;

            border:
                1px solid
                var(--border);

            border-radius: 14px;

            background:
                rgba(255,250,244,.58);
        }

        .preview-wrap.show {

            display: flex;

            align-items: center;

            gap: 13px;

            opacity: 1;

            transform: none;

            transition:
                opacity 240ms var(--ease-out),
                transform 240ms var(--ease-out);
        }

        /* Entrance when JS adds .show (display:none -> flex).
           Browsers without @starting-style just show it instantly. */
        @starting-style {

            .preview-wrap.show {

                opacity: 0;

                transform: translateY(-4px);
            }
        }

        .preview-image {

            width: 70px;

            height: 70px;

            border-radius: 12px;

            object-fit: cover;

            background: #eee;
        }

        .preview-info {

            color:
                var(--muted);

            font-size: 11px;
        }

        .preview-info strong {

            display: block;

            color:
                var(--text);

            font-size: 12px;

            margin-bottom: 3px;
        }


        /* ==================================================
           BUTTON
        ================================================== */

        .submit-button {

            width: 100%;

            min-height: 48px;

            margin-top: 18px;

            border: none;

            border-radius: 10px;

            background:
                linear-gradient(
                    135deg,
                    var(--brown),
                    var(--brown-dark)
                );

            color: white;

            cursor: pointer;

            font-size: 12px;

            font-weight: 800;

            transition:
                transform 140ms var(--ease-out),
                box-shadow 180ms ease;
        }

        .submit-button:active:not(:disabled) {

            transform:
                translateY(0)
                scale(0.97);
        }

        @media (hover: hover) and (pointer: fine) {

            .submit-button:hover {

                transform:
                    translateY(-1px);

                box-shadow:
                    0 10px 24px
                    rgba(90,56,38,.20);
            }
        }

        /* Keyboard parity: same lift shadow, no movement. */
        .submit-button:focus-visible {

            box-shadow:
                0 10px 24px
                rgba(90,56,38,.20);
        }

        .upload-note {

            margin-top: 13px;

            color:
                var(--muted);

            font-size: 10px;

            line-height: 1.5;
        }


        /* ==================================================
           FOOTER
        ================================================== */

        footer {

            margin-top: 70px;

            padding:
                36px 7% 22px;

            /* Last thing on the page: clear the home indicator. */
            padding-bottom:
                calc(22px + env(safe-area-inset-bottom, 0px));

            background:
                #2d1d16;

            color:
                #d8c9bf;
        }

        .footer-inner {

            max-width: 1050px;

            margin: auto;

            display: flex;

            justify-content:
                space-between;

            align-items: center;

            gap: 25px;
        }

        footer h3 {

            margin-bottom: 3px;

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            color: white;
        }

        footer p {

            color:
                #bdaea5;

            font-size: 11px;
        }


        /* ==================================================
           RESPONSIVE
        ================================================== */

        @media (
            max-width: 800px
        ) {

            .profile-grid {

                grid-template-columns:
                    1fr;
            }
        }

        @media (
            max-width: 650px
        ) {

            .navbar {

                padding:
                    0 5%;

                padding-top:
                    env(safe-area-inset-top, 0px);
            }

            .brand small {

                display: none;
            }

            .nav-links a:not(.logout-link) {

                display: none;
            }

            .page {

                width: 92%;

                margin-top: 28px;
            }

            .profile-actions {

                grid-template-columns:
                    1fr;
            }

            .footer-inner {

                flex-direction: column;

                align-items: flex-start;
            }
        }


        /* ==================================================
           TOUCH INPUTS
        ================================================== */

        /* iOS zooms into inputs under 16px; desktop size unchanged. */
        @media (pointer: coarse) {

            input,
            select,
            textarea {

                font-size: 16px;
            }
        }


        /* ==================================================
           REDUCED MOTION
        ================================================== */

        @media (prefers-reduced-motion: reduce) {

            html {
                scroll-behavior: auto;
            }

            /* Drop movement: nav/button press scale, submit lift,
               drop-zone press, alert + preview slide. Fades stay. */
            .nav-links a,
            .mini-link,
            .submit-button,
            .file-zone,
            .preview-wrap,
            .alert {
                transform: none !important;
            }

            *,
            *::before,
            *::after {
                animation-duration: 1ms !important;
                animation-iteration-count: 1 !important;
            }

            /* Keep the alert's opacity fade (its translate is removed above). */
            .alert {
                animation-duration: 240ms !important;
            }
        }

    </style>

<style>
/* Phone polish: smaller avatar and upload icons */
@media (max-width: 560px) {
    .placeholder { font-size: 44px; }
    .upload-icon { font-size: 28px; margin-bottom: 6px; }
}
</style>
<style>
/* ======================================================
   PROFILE: one centered card + floating photo dialog
====================================================== */

.profile-grid {
    grid-template-columns: minmax(0, 460px);
    justify-content: center;
}

.profile-card .alert {
    width: 100%;
    text-align: left;
}

.profile-image-shell {
    position: relative;
}

.photo-badge {
    position: absolute;
    right: 6px;
    bottom: 6px;
    z-index: 1;
    display: grid;
    place-items: center;
    width: 40px;
    height: 40px;
    padding: 0;
    border: 3px solid #fff;
    border-radius: 999px;
    background: var(--brown);
    color: #fff;
    cursor: pointer;
    touch-action: manipulation;
    box-shadow: 0 6px 16px rgba(90,56,38,.28);
    transition: background-color 180ms ease, transform 140ms var(--ease-out);
}

.photo-badge:active { transform: scale(0.92); }
.photo-badge:focus-visible { outline: 2px solid var(--brown); outline-offset: 3px; }

.change-photo {
    grid-column: 1 / -1;
    min-height: 46px;
    border: 0;
    border-radius: 10px;
    background: linear-gradient(135deg, var(--brown), var(--brown-dark));
    color: #fff;
    font: inherit;
    font-size: 13px;
    font-weight: 800;
    cursor: pointer;
    touch-action: manipulation;
    -webkit-user-select: none;
    user-select: none;
    box-shadow: 0 8px 20px rgba(90,56,38,.20);
    transition: transform 140ms var(--ease-out);
}

.change-photo:active { transform: scale(0.97); }
.change-photo:focus-visible { outline: 2px solid var(--brown); outline-offset: 3px; }

/* ---------- dialog ---------- */

.pp {
    width: min(360px, calc(100vw - 32px));
    max-width: none;
    margin: auto;
    padding: 0;
    border: 0;
    border-radius: 20px;
    background: #fff;
    color: var(--text);
    box-shadow: 0 1px 2px rgba(36,26,21,.06), 0 12px 32px rgba(36,26,21,.14), 0 32px 64px rgba(36,26,21,.12);
    opacity: 0;
    transform: scale(0.96);
    transition: opacity 160ms ease, transform 160ms var(--ease-out);
    transition: opacity 160ms ease, transform 160ms var(--ease-out), overlay 160ms allow-discrete, display 160ms allow-discrete;
}

.pp[open] { opacity: 1; transform: none; transition-duration: 240ms; }

@starting-style {
    .pp[open] { opacity: 0; transform: scale(0.96); }
}

.pp::backdrop {
    background: rgba(36,26,21,0);
    transition: background-color 200ms ease, overlay 200ms allow-discrete, display 200ms allow-discrete;
}

.pp[open]::backdrop { background: rgba(36,26,21,.45); }

@starting-style {
    .pp[open]::backdrop { background: rgba(36,26,21,0); }
}

.pp-panel {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 28px 24px 24px;
    text-align: center;
}

.pp-close {
    position: absolute;
    top: 12px;
    right: 12px;
    display: grid;
    place-items: center;
    width: 40px;
    height: 40px;
    padding: 0;
    border: 0;
    border-radius: 999px;
    background: transparent;
    color: var(--muted);
    cursor: pointer;
    touch-action: manipulation;
    transition: background-color 180ms ease, transform 140ms var(--ease-out);
}

.pp-close:active { transform: scale(0.94); }
.pp-close:focus-visible { outline: 2px solid var(--brown); outline-offset: 2px; }

.pp h2 {
    margin: 0 0 18px;
    font-family: Georgia, "Times New Roman", serif;
    font-size: 22px;
    outline: none;
}

.pp-preview {
    display: grid;
    place-items: center;
    width: 132px;
    height: 132px;
    padding: 5px;
    border-radius: 50%;
    background: linear-gradient(135deg, #efd3b2, var(--brown));
}

.pp-preview img,
.pp-preview span {
    width: 100%;
    height: 100%;
    border-radius: 50%;
}

.pp-preview img { object-fit: cover; background: #fff; }
.pp-preview img[hidden], .pp-preview span[hidden] { display: none; }

.pp-preview span {
    display: grid;
    place-items: center;
    background: linear-gradient(145deg, #ead7c5, #c49a78);
    font-size: 48px;
}

.pp-hint {
    max-width: 100%;
    margin: 12px 0 18px;
    overflow: hidden;
    font-size: 13px;
    color: var(--muted);
    text-overflow: ellipsis;
    white-space: nowrap;
}

.pp-hint.is-error { color: #b42318; white-space: normal; }

/* the real input stays in the form (for "required" and the upload) but out of sight */
.pp-file {
    position: absolute;
    width: 1px;
    height: 1px;
    opacity: 0;
    pointer-events: none;
}

.pp-choose,
.pp-save {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 48px;
    border-radius: 12px;
    font: inherit;
    font-size: 15px;
    font-weight: 800;
    cursor: pointer;
    touch-action: manipulation;
    -webkit-user-select: none;
    user-select: none;
    transition: background-color 180ms ease, opacity 180ms ease, transform 140ms var(--ease-out);
}

.pp-choose {
    border: 1px solid var(--border);
    background: #fffaf4;
    color: var(--brown-dark);
}

.pp-file:focus-visible + .pp-choose { outline: 2px solid var(--brown); outline-offset: 2px; }

.pp-save {
    margin-top: 10px;
    border: 0;
    background: linear-gradient(135deg, var(--brown), var(--brown-dark));
    color: #fff;
}

.pp-save:disabled { opacity: .45; cursor: not-allowed; }
.pp-choose:active, .pp-save:active:not(:disabled) { transform: scale(0.97); }
.pp-save:focus-visible { outline: 2px solid var(--brown); outline-offset: 3px; }

@media (hover: hover) and (pointer: fine) {
    .photo-badge:hover { background: var(--brown-dark); }
    .pp-close:hover { background: rgba(36,26,21,.06); }
    .pp-choose:hover { background: #f6ebdf; }
}

/* phones: slide up from the bottom like the login sheet */
@media (max-width: 560px) {
    .pp {
        width: 100%;
        margin: auto 0 0;
        border-radius: 20px 20px 0 0;
        transform: translateY(100%);
        transition-timing-function: ease, var(--ease-drawer);
    }

    .pp[open] { transform: none; transition-duration: 300ms; }

    @starting-style {
        .pp[open] { transform: translateY(100%); }
    }

    .pp-panel { padding-bottom: calc(24px + env(safe-area-inset-bottom, 0px)); }
    .pp-choose, .pp-save { font-size: 16px; }
}

@media (prefers-reduced-motion: reduce) {
    .pp, .pp[open], .photo-badge:active, .change-photo:active,
    .pp-close:active, .pp-choose:active, .pp-save:active { transform: none !important; }

    @starting-style {
        .pp[open] { transform: none !important; }
    }
}
</style>

<?php require __DIR__ . "/../includes/glass.php"; ?>
<style>
/* Messages: envelope button in the header (visible on phones too) */
.msg-link {
    position: relative;
    display: inline-grid !important;
    place-items: center;
    width: 42px;
    height: 42px;
    border-radius: 999px;
    border: 1px solid var(--border, rgba(122,79,54,.14));
    background: rgba(255, 250, 244, 0.7);
    color: var(--brown-dark, #513421);
    text-decoration: none;
}
.msg-link svg { display: block; }
.msg-count {
    position: absolute;
    top: -4px;
    right: -4px;
    min-width: 19px;
    height: 19px;
    padding: 0 5px;
    border: 2px solid #fff;
    border-radius: 999px;
    background: #dc2626;
    color: #fff;
    font: 800 10px/15px Arial, sans-serif;
    text-align: center;
}
.btn .msg-count, .mini-link .msg-count { position: static; display: inline-block; margin-left: 6px; border: 0; line-height: 19px; }
</style>
</head>

<body>


<!-- ======================================================
     NAVBAR
====================================================== -->

<nav class="navbar">

    <a
        href="../index.php"
        class="brand"
    >

        <span class="brand-mark">
            <?= icon("home") ?>
        </span>

        <span>

            ARVE'S House

            <small>
                YOUR HOME AWAY FROM HOME
            </small>

        </span>

    </a>


    <div class="nav-links">

        <a href="../index.php">
            Home
        </a>

        <a href="../rooms.php">
            Rooms
        </a>

        <a href="dashboard.php">
            My Reservations
        </a>

        <a href="messages.php">Messages</a>

        <a
            href="../logout.php"
            class="logout-link"
        >
            Logout
        </a>

    </div>

</nav>


<!-- ======================================================
     PAGE
====================================================== -->

<main class="page">


    <div class="page-heading">

        <span>
            Customer Profile
        </span>

        <h1>
            My Profile
        </h1>

        <p>
            Manage your profile picture and keep your
            ARVE'S House customer account looking personal.
        </p>

    </div>


    <div class="profile-grid">


        <!-- ==================================================
             PROFILE SUMMARY
        =================================================== -->

        <section class="profile-card">


            <?php if ($message !== ""): ?>

                <div class="alert success" role="status">
                    ✓ <?= htmlspecialchars($message) ?>
                </div>

            <?php endif; ?>


            <?php if ($error !== ""): ?>

                <div class="alert error" role="alert">
                    <?= htmlspecialchars($error) ?>
                </div>

            <?php endif; ?>


            <div class="profile-image-shell">


                <button
                    type="button"
                    class="photo-badge"
                    data-photo-open
                    aria-label="Change profile picture"
                >
                    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3z"/><circle cx="12" cy="13" r="3.5"/></svg>
                </button>


                <?php if (
                    !empty(
                        $user["profile_image"]
                    )
                ): ?>

                    <img
                        src="../<?= htmlspecialchars(
                            $user["profile_image"]
                        ) ?>"
                        alt="Profile Picture"
                        class="profile-image"
                        id="currentProfileImage"
                    >

                <?php else: ?>

                    <div
                        class="placeholder"
                        id="currentProfilePlaceholder"
                    >
                        <?= icon("user") ?>
                    </div>

                <?php endif; ?>


            </div>


            <h2>

                <?= htmlspecialchars(
                    $user["name"]
                    ?? $user["full_name"]
                    ?? "Customer"
                ) ?>

            </h2>


            <p class="profile-email">

                <?= htmlspecialchars(
                    $user["email"]
                    ?? ""
                ) ?>

            </p>


            <span class="role-pill">
                Customer Account
            </span>


            <div class="profile-actions">

                <button
                    type="button"
                    class="change-photo"
                    data-photo-open
                >
                    Change Profile Picture
                </button>

                <a
                    href="dashboard.php"
                    class="mini-link"
                >
                    My Reservations
                </a>

                <a href="messages.php" class="mini-link">Messages <?php if ($inboxUnread > 0): ?><span class="msg-count"><?= $inboxUnread > 9 ? "9+" : $inboxUnread ?></span><?php endif; ?></a>

                <a
                    href="../rooms.php"
                    class="mini-link"
                >
                    Browse Rooms
                </a>

            </div>


        </section>




    </div>


</main>


<!-- ======================================================
     CHANGE PROFILE PICTURE (floating)
====================================================== -->

<dialog class="pp" id="photo-modal" aria-labelledby="pp-title">

    <form method="POST" enctype="multipart/form-data" class="pp-panel">

        <button type="button" class="pp-close" data-pp-close aria-label="Close">
            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>

        <h2 id="pp-title" tabindex="-1">Profile picture</h2>

        <div class="pp-preview">
            <?php if (!empty($user["profile_image"])): ?>
                <img src="../<?= htmlspecialchars($user["profile_image"]) ?>" alt="" id="ppImage">
            <?php else: ?>
                <img src="" alt="" id="ppImage" hidden>
                <span id="ppPlaceholder" aria-hidden="true"><?= icon("user") ?></span>
            <?php endif; ?>
        </div>

        <p class="pp-hint" id="ppHint">JPG, PNG or WEBP · up to 2MB</p>

        <input
            type="file"
            name="profile_image"
            id="profile_image"
            class="pp-file"
            accept=".jpg,.jpeg,.png,.webp"
            required
        >

        <label for="profile_image" class="pp-choose">Choose Photo</label>

        <button type="submit" name="upload_profile" class="pp-save" id="ppSave" disabled>
            Save
        </button>

    </form>

</dialog>


<!-- ======================================================
     FOOTER
====================================================== -->

<footer>

    <div class="footer-inner">

        <div>

            <h3>
                ARVE'S House
            </h3>

            <p>
                Your Home Away From Home
            </p>

        </div>


        <div>

            <p>
                Customer Profile
            </p>

            <p>
                Comfort · Relax · Stay
            </p>

        </div>

    </div>

</footer>


<script>

// ======================================================
// CHANGE PROFILE PICTURE (floating dialog)
// ======================================================

(function () {
    const dialog = document.getElementById("photo-modal");

    if (!dialog || typeof dialog.showModal !== "function") {
        return;
    }

    const input = document.getElementById("profile_image");
    const image = document.getElementById("ppImage");
    const placeholder = document.getElementById("ppPlaceholder");
    const hint = document.getElementById("ppHint");
    const save = document.getElementById("ppSave");
    const title = document.getElementById("pp-title");
    const form = dialog.querySelector("form");
    const originalSrc = image.getAttribute("src");
    const defaultHint = hint.textContent;
    const allowed = ["image/jpeg", "image/png", "image/webp"];
    let previewUrl = "";

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

        if (originalSrc) {
            image.src = originalSrc;
        } else {
            image.hidden = true;
            if (placeholder) placeholder.hidden = false;
        }
    }

    function showHintError(text) {
        hint.textContent = text;
        hint.classList.add("is-error");
        save.disabled = true;
    }

    // same scroll lock as the other floating windows
    let savedOverflow = "";
    let savedPadding = "";

    function open() {
        reset();
        const scrollbar = window.innerWidth - document.documentElement.clientWidth;
        savedOverflow = document.documentElement.style.overflow;
        savedPadding = document.body.style.paddingRight;
        document.documentElement.style.overflow = "hidden";
        if (scrollbar > 0) {
            document.body.style.paddingRight = scrollbar + "px";
        }
        dialog.showModal();
        title.focus();
    }

    document.querySelectorAll("[data-photo-open]").forEach(button => {
        button.addEventListener("click", open);
    });

    dialog.querySelectorAll("[data-pp-close]").forEach(button => {
        button.addEventListener("click", () => dialog.close());
    });

    let pressStartedOnBackdrop = false;

    dialog.addEventListener("pointerdown", event => {
        pressStartedOnBackdrop = event.target === dialog;
    });

    dialog.addEventListener("click", event => {
        if (event.target === dialog && pressStartedOnBackdrop) {
            dialog.close();
        }
        pressStartedOnBackdrop = false;
    });

    dialog.addEventListener("close", () => {
        document.documentElement.style.overflow = savedOverflow;
        document.body.style.paddingRight = savedPadding;
    });

    input.addEventListener("change", () => {
        const file = input.files[0];

        if (!file) {
            return;
        }

        if (!allowed.includes(file.type)) {
            showHintError("Please choose a JPG, PNG or WEBP image.");
            return;
        }

        if (file.size > 2 * 1024 * 1024) {
            showHintError("That image is over 2MB. Please choose a smaller one.");
            return;
        }

        if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
        }

        previewUrl = URL.createObjectURL(file);
        image.src = previewUrl;
        image.hidden = false;
        if (placeholder) placeholder.hidden = true;

        hint.textContent = file.name;
        hint.classList.remove("is-error");
        save.disabled = false;
    });

    form.addEventListener("submit", () => {
        // disable after the browser has read the button: upload_profile comes from the submit button
        setTimeout(() => {
            save.disabled = true;
            save.textContent = "Saving…";
        }, 0);
    });
})();


// ======================================================
// PRESS FEEDBACK ON iOS
// ======================================================

// iOS Safari only applies :active styles when a touch
// listener exists; this passive no-op enables them.
document.addEventListener(
    "touchstart",
    () => {},
    { passive: true }
);

</script>


<?php require __DIR__ . "/../includes/inbox-widget.php"; ?>
<?php require __DIR__ . "/../includes/terms-modal.php"; ?>

</body>

</html>
