<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/admin-shell.php";
require_once __DIR__ . "/../includes/photos.php";
require_once __DIR__ . "/../includes/hero-scenes.php";

$admin = admin_boot($pdo, "settings");

// the large pictures at the top of the homepage: uploads/home/hero_1.jpg … hero_6.jpg
const HOME_PHOTOS = "home/hero";

// The default house emoji shows as the site's line icon (same as the homepage)
function hero_icon_html(string $value): string
{
    return in_array(trim($value), ["🏡", "🏠", "⌂", ""], true) ? icon("home") : htmlspecialchars($value);
}

$defaults = [
    "hero_card_icon" => "🏡",
    "hero_card_title" => "ARVE'S House",
    "hero_card_subtitle" => "Simple stay. Greater memories.",
    "hero_card_bg_start" => "#c9a27f",
    "hero_card_bg_end" => "#7b5841"
];

$settings = $defaults;
$stmt = $pdo->query("SELECT setting_key, setting_value FROM site_settings");
foreach ($stmt->fetchAll() as $setting) {
    if (array_key_exists($setting["setting_key"], $settings)) {
        $settings[$setting["setting_key"]] = $setting["setting_value"];
    }
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = (string) ($_POST["action"] ?? "card");

    // ======================================================
    // HOMEPAGE PHOTOS: add, remove, show first
    // ======================================================

    // a request larger than the server accepts arrives with nothing in it
    $tooLarge = !$_POST && (int) ($_SERVER["CONTENT_LENGTH"] ?? 0) > 0;

    if ($tooLarge || $action !== "card") {

        if ($tooLarge) {
            admin_flash("Those photos are too large to upload in one go. Try fewer or smaller photos.", "error");

        } elseif (!admin_check_csrf()) {
            admin_flash("Your session expired. Please try again.", "error");

        } elseif ($action === "photos_add") {
            [$saved, $problems] = photo_add_many($_FILES["photos"] ?? null, HOME_PHOTOS);

            if ($saved > 0) {
                log_activity($pdo, "settings.updated", "Added " . $saved . ($saved === 1 ? " photo" : " photos") . " to the homepage", [
                    "entity_type" => "settings",
                    "link" => "site_settings.php#homepage-photos",
                ]);

                admin_flash($saved . ($saved === 1 ? " photo was" : " photos were") . " added to the homepage. Visitors see " . ($saved === 1 ? "it" : "them") . " right away.");
            }

            if ($problems) {
                admin_flash(implode(" ", $problems), "error");
            } elseif ($saved === 0) {
                admin_flash("Choose one or more photos first.", "error");
            }

        } elseif ($action === "photo_remove") {
            if (photo_remove(HOME_PHOTOS, (int) ($_POST["slot"] ?? 0))) {
                log_activity($pdo, "settings.updated", "Removed a photo from the homepage", [
                    "entity_type" => "settings",
                    "link" => "site_settings.php#homepage-photos",
                ]);

                admin_flash("Photo removed from the homepage.");
            }

        } elseif ($action === "photo_first") {
            if (photo_first(HOME_PHOTOS, (int) ($_POST["slot"] ?? 0))) {
                admin_flash("That photo now shows first.");
            }
        }

        header("Location: site_settings.php#homepage-photos");
        exit;
    }

    // ======================================================
    // THE HOMEPAGE CARD
    // ======================================================

    $submitted = [
        "hero_card_icon" => trim($_POST["hero_card_icon"] ?? ""),
        "hero_card_title" => trim($_POST["hero_card_title"] ?? ""),
        "hero_card_subtitle" => trim($_POST["hero_card_subtitle"] ?? ""),
        "hero_card_bg_start" => trim($_POST["hero_card_bg_start"] ?? ""),
        "hero_card_bg_end" => trim($_POST["hero_card_bg_end"] ?? "")
    ];

    $validColors = static function (string $color): bool {
        return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $color);
    };

    $isReset = isset($_POST["reset"]);

    if (!admin_check_csrf()) {
        $error = "Your session expired. Please try again.";
        $settings = $submitted + $settings;
    } elseif (!$isReset && ($submitted["hero_card_icon"] === "" || $submitted["hero_card_title"] === "" || $submitted["hero_card_subtitle"] === "")) {
        $error = "Icon, title, and subtitle are required.";
        $settings = $submitted + $settings;
    } elseif (!$isReset && (!$validColors($submitted["hero_card_bg_start"]) || !$validColors($submitted["hero_card_bg_end"]))) {
        $error = "Please select valid six-digit hex colors.";
        $settings = ["hero_card_bg_start" => $settings["hero_card_bg_start"], "hero_card_bg_end" => $settings["hero_card_bg_end"]] + $submitted;
    } else {
        foreach ($isReset ? $defaults : $submitted as $key => $value) {
            save_site_setting($pdo, $key, $value);
        }

        log_activity($pdo, "settings.updated", $isReset ? "Put the homepage card back to the original" : "Changed the homepage card", [
            "entity_type" => "settings",
            "link" => "site_settings.php",
        ]);

        admin_flash($isReset ? "The homepage card is back to the original." : "Homepage card saved. Visitors see it right away.");

        header("Location: site_settings.php");
        exit;
    }
}

$isDefault = $settings === $defaults;

// What the homepage shows at the top now: these photos, else the rooms' photos, else drawings.
// (The homepage looks at the four newest available rooms, like here.)
$homePhotos = photo_list(HOME_PHOTOS, "../");
$roomPhotos = 0;

foreach ($pdo->query("SELECT id FROM rooms WHERE status = 'available' ORDER BY id DESC LIMIT 4") as $room) {
    $roomPhotos += count(photo_list("rooms/room_" . (int) $room["id"]));
}

$showing = $homePhotos ? "photos" : ($roomPhotos > 0 ? "rooms" : "drawings");

// the colors are only ever printed when they look like #c9a27f
$previewStart = preg_match('/^#[0-9a-fA-F]{6}$/', $settings["hero_card_bg_start"]) ? $settings["hero_card_bg_start"] : $defaults["hero_card_bg_start"];
$previewEnd = preg_match('/^#[0-9a-fA-F]{6}$/', $settings["hero_card_bg_end"]) ? $settings["hero_card_bg_end"] : $defaults["hero_card_bg_end"];

admin_shell_head([
    "title" => "Settings",
    "subtitle" => "The homepage: its large photos and the card on them",
    "active" => "settings",
]);
?>
<style>
    /* the preview shows the card where visitors see it: on the large picture, bottom left */
    .preview-hero {
        position: relative;
        isolation: isolate;
        display: flex;
        align-items: flex-end;
        min-height: 260px;
        padding: 18px;
        overflow: hidden;
        border-radius: 18px;
        background: #1a110d;
        color: #fff;
    }

    .preview-hero > img,
    .preview-hero > .scene {
        position: absolute;
        inset: 0;
        z-index: -2;
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .preview-hero::after {
        content: "";
        position: absolute;
        inset: 0;
        z-index: -1;
        background: linear-gradient(to bottom, rgba(26, 17, 13, 0.2), rgba(26, 17, 13, 0.75));
    }

    .preview-badge {
        display: flex;
        align-items: center;
        gap: 12px;
        max-width: 100%;
        padding: 10px 20px 10px 10px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 16px;
        background: rgba(255, 255, 255, 0.1);
        -webkit-backdrop-filter: blur(12px);
        backdrop-filter: blur(12px);
    }

    .preview-chip {
        display: grid;
        flex: none;
        place-items: center;
        width: 44px;
        height: 44px;
        border-radius: 12px;
        font-size: 22px;
        line-height: 1;
    }

    .preview-badge strong {
        display: block;
        font-family: Georgia, serif;
        font-size: 17px;
        line-height: 1.25;
        overflow-wrap: anywhere;
    }

    .preview-badge small {
        display: block;
        color: rgba(255, 255, 255, 0.75);
        font-size: 12px;
        line-height: 1.35;
        overflow-wrap: anywhere;
    }

    .color-pair {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        gap: 16px;
        margin-top: 16px;
    }

    .color-pair .field + .field {
        margin-top: 0;
    }

    .showing {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 16px;
        color: var(--text-2);
        font-size: 13px;
    }
</style>
<?php admin_shell_body(); ?>

<div class="stack">

    <!-- THE LARGE PHOTOS AT THE TOP OF THE HOMEPAGE -->

    <section class="card card-pad" id="homepage-photos">
        <h2 class="card-title">Homepage photos</h2>
        <p class="card-sub" style="margin-bottom:12px">The large pictures at the top of the homepage. They take turns every 7 seconds, in this order.</p>

        <p class="showing">
            <?= icon("eye", 15) ?>
            <?php if ($showing === "photos"): ?>
                Visitors now see these photos.
            <?php elseif ($showing === "rooms"): ?>
                There are no photos here yet, so visitors now see the rooms' photos (Rooms → Photos).
            <?php else: ?>
                There are no photos yet, so visitors now see drawn pictures of a house. Add photos of the house or the rooms here.
            <?php endif; ?>
        </p>

        <div class="photo-grid photo-grid-wide">
            <?php for ($slot = 1; $slot <= PHOTO_SLOTS; $slot++): ?>
                <div class="photo-slot">
                    <?php if (isset($homePhotos[$slot])): ?>
                        <img src="<?= h($homePhotos[$slot]) ?>" alt="Homepage photo <?= $slot ?>" loading="lazy" decoding="async">

                        <?php if ($slot === 1): ?>
                            <span class="photo-slot-tag">First</span>
                        <?php endif; ?>

                        <div class="photo-slot-actions">
                            <?php if ($slot > 1): ?>
                                <form class="inline-form" method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="photo_first">
                                    <input type="hidden" name="slot" value="<?= $slot ?>">
                                    <button class="btn" type="submit">Make first</button>
                                </form>
                            <?php endif; ?>

                            <form class="inline-form" method="post"
                                data-confirm="This photo will be removed from the homepage."
                                data-confirm-title="Remove this photo?"
                                data-confirm-ok="Remove"
                                data-confirm-tone="danger"
                            >
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="photo_remove">
                                <input type="hidden" name="slot" value="<?= $slot ?>">
                                <button class="btn" type="submit" aria-label="Remove homepage photo <?= $slot ?>"><?= icon("trash", 14) ?></button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="photo-slot-empty">Empty</div>
                    <?php endif; ?>
                </div>
            <?php endfor; ?>
        </div>

        <hr class="divider">

        <?php if (count($homePhotos) < PHOTO_SLOTS): ?>
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="photos_add">

                <label class="field">
                    <span class="label">Add photos (<?= PHOTO_SLOTS - count($homePhotos) ?> more fit)</span>
                    <input class="input" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required>
                </label>
                <p class="hint">
                    Wide photos of the house or the rooms work best, at least 1600 pixels across.
                    On phones only the middle of each photo shows. JPG, PNG or WebP, up to 5 MB each.
                </p>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit"><?= icon("upload") ?> Upload</button>
                    <a class="btn btn-ghost" href="../index.php" target="_blank" rel="noopener"><?= icon("external-link") ?> View homepage</a>
                </div>
            </form>
        <?php else: ?>
            <p class="note">All <?= PHOTO_SLOTS ?> places are used. Remove a photo to add another.</p>
        <?php endif; ?>
    </section>

    <div class="grid grid-2" style="align-items:start">

        <!-- THE HOMEPAGE CARD -->

        <section class="card card-pad">
            <h2 class="card-title">Homepage card</h2>
            <p class="card-sub" style="margin-bottom:16px">Its icon, its two lines of text and its colors</p>

            <?php if ($error !== ""): ?>
                <div class="alert alert-error alert-in" role="alert"><?= icon("alert") ?><p><?= h($error) ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="card">

                <label class="field">
                    <span class="label">House icon or emoji</span>
                    <input class="input" id="hero_card_icon" name="hero_card_icon" value="<?= h($settings["hero_card_icon"]) ?>" maxlength="16" required>
                </label>

                <label class="field">
                    <span class="label">Title</span>
                    <input class="input" id="hero_card_title" name="hero_card_title" value="<?= h($settings["hero_card_title"]) ?>" maxlength="80" required>
                </label>

                <label class="field">
                    <span class="label">Subtitle</span>
                    <input class="input" id="hero_card_subtitle" name="hero_card_subtitle" value="<?= h($settings["hero_card_subtitle"]) ?>" maxlength="160" required>
                </label>

                <div class="color-pair">
                    <label class="field">
                        <span class="label">Icon color, top</span>
                        <input class="input" type="color" id="hero_card_bg_start" name="hero_card_bg_start" value="<?= h($previewStart) ?>" required>
                    </label>

                    <label class="field">
                        <span class="label">Icon color, bottom</span>
                        <input class="input" type="color" id="hero_card_bg_end" name="hero_card_bg_end" value="<?= h($previewEnd) ?>" required>
                    </label>
                </div>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit"><?= icon("save") ?> Save homepage card</button>
                    <button class="btn" type="submit" name="reset" value="1" <?= $isDefault ? "disabled" : "" ?>
                        data-confirm="The icon, the texts and the colors go back to the original ones."
                        data-confirm-title="Back to the original card?"
                        data-confirm-ok="Reset"
                    >Reset</button>
                    <a class="btn btn-ghost" href="../index.php" target="_blank" rel="noopener"><?= icon("external-link") ?> View homepage</a>
                </div>
            </form>
        </section>

        <section class="card card-pad">
            <h2 class="card-title">Live preview</h2>
            <p class="card-sub" style="margin-bottom:16px">Where visitors see it: on the large picture, bottom left (on computers and tablets)</p>

            <div class="preview-hero">
                <?php if ($homePhotos): ?>
                    <img src="<?= h(reset($homePhotos)) ?>" alt="" decoding="async">
                <?php else: ?>
                    <?= hero_scene("dusk", "preview") ?>
                <?php endif; ?>

                <div class="preview-badge">
                    <span class="preview-chip" id="preview-card" style="background:linear-gradient(145deg, <?= h($previewStart) ?>, <?= h($previewEnd) ?>)">
                        <span id="preview-icon"><?= hero_icon_html($settings["hero_card_icon"]) ?></span>
                    </span>
                    <span>
                        <strong id="preview-title"><?= h($settings["hero_card_title"]) ?></strong>
                        <small id="preview-subtitle"><?= h($settings["hero_card_subtitle"]) ?></small>
                    </span>
                </div>
            </div>
            <p class="hint">Changes as you type; saved only when you press Save.</p>
        </section>

    </div>

</div>

<script>
    const iconInput = document.getElementById("hero_card_icon");
    const titleInput = document.getElementById("hero_card_title");
    const subtitleInput = document.getElementById("hero_card_subtitle");
    const startInput = document.getElementById("hero_card_bg_start");
    const endInput = document.getElementById("hero_card_bg_end");
    const previewCard = document.getElementById("preview-card");

    function updatePreview() {
        const houseIcon = <?= json_encode(icon("home")) ?>;
        const previewIcon = document.getElementById("preview-icon");
        if (["🏡", "🏠", "⌂", ""].includes(iconInput.value.trim())) {
            previewIcon.innerHTML = houseIcon;
        } else {
            previewIcon.textContent = iconInput.value;
        }
        document.getElementById("preview-title").textContent = titleInput.value;
        document.getElementById("preview-subtitle").textContent = subtitleInput.value;
        previewCard.style.background = `linear-gradient(145deg, ${startInput.value}, ${endInput.value})`;
    }

    [iconInput, titleInput, subtitleInput, startInput, endInput].forEach((input) => {
        input.addEventListener("input", updatePreview);
    });
</script>

<?php admin_shell_end(); ?>
