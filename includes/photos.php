<?php
/*
 * Photos kept as files in uploads/. A "set" is a folder and a name and holds up to six photos,
 * numbered 1 to 6 and always saved as JPEG:
 *
 *     rooms/room_3   uploads/rooms/room_3_1.jpg … room_3_6.jpg   (rooms.php and reservation.php
 *                                                                  look for exactly these names)
 *     home/hero      uploads/home/hero_1.jpg … hero_6.jpg        (the large photos of the homepage)
 *
 * Photo 1 is the one people see first. There are never gaps: removing photo 2 of 4 makes the
 * old 3 and 4 the new 2 and 3.
 */

const PHOTO_SLOTS = 6;
const PHOTO_MAX_BYTES = 5 * 1024 * 1024;

// "rooms/room_3", "home/hero": lowercase letters, one slash, an optional number at the end
function photo_set_ok(string $set): bool
{
    return (bool) preg_match('/^[a-z]+\/[a-z]+(_\d+)?$/', $set);
}

// Where a photo is kept on the disk.
function photo_file(string $set, int $slot): string
{
    if (!photo_set_ok($set)) {
        throw new InvalidArgumentException("Not a photo set: " . $set);
    }

    return __DIR__ . "/../uploads/" . $set . "_" . $slot . ".jpg";
}

/*
 * The photos of a set: slot => address. $base is the way from the page to the site's main
 * folder ("" on the homepage, "../" on admin pages). The address carries the time the file
 * changed, so a replaced photo shows at once instead of the browser's old copy.
 */
function photo_list(string $set, string $base = ""): array
{
    $photos = [];

    for ($slot = 1; $slot <= PHOTO_SLOTS; $slot++) {
        $path = photo_file($set, $slot);

        if (is_file($path)) {
            $photos[$slot] = $base . "uploads/" . $set . "_" . $slot . ".jpg?v=" . filemtime($path);
        }
    }

    return $photos;
}

/*
 * Saves one uploaded file (an entry of $_FILES) as the JPEG of the given slot.
 * Returns "" when it worked, otherwise the reason it did not, in words for the admin.
 */
function photo_save(array $file, string $set, int $slot): string
{
    $name = (string) ($file["name"] ?? "photo");

    if (($file["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return in_array($file["error"], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? $name . " is too large."
            : $name . " could not be uploaded. Please try again.";
    }

    if (!is_uploaded_file($file["tmp_name"])) {
        return $name . " could not be uploaded. Please try again.";
    }

    if ($file["size"] > PHOTO_MAX_BYTES) {
        return $name . " is larger than 5 MB. Please use a smaller photo.";
    }

    $info = @getimagesize($file["tmp_name"]);
    $type = $info["mime"] ?? "";

    if (!in_array($type, ["image/jpeg", "image/png", "image/webp"], true)) {
        return $name . " is not a photo. Use a JPG, PNG or WebP file.";
    }

    $target = photo_file($set, $slot);
    $folder = dirname($target);

    if (!is_dir($folder) && !mkdir($folder, 0755, true) && !is_dir($folder)) {
        return "The photo folder could not be created on the server.";
    }

    if ($type === "image/jpeg") {
        return move_uploaded_file($file["tmp_name"], $target) ? "" : $name . " could not be saved.";
    }

    // PNG and WebP are turned into JPEG, because the site looks for .jpg files
    if (!function_exists("imagecreatefromstring") || !function_exists("imagejpeg")) {
        return $name . " is a " . strtoupper(substr($type, 6)) . " file. This server can only take JPG photos.";
    }

    $image = @imagecreatefromstring((string) file_get_contents($file["tmp_name"]));

    if (!$image) {
        return $name . " could not be read as a photo.";
    }

    // a white sheet behind see-through parts
    $canvas = imagecreatetruecolor(imagesx($image), imagesy($image));
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
    imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

    $saved = imagejpeg($canvas, $target, 88);

    imagedestroy($image);
    imagedestroy($canvas);

    return $saved ? "" : $name . " could not be saved.";
}

/*
 * Saves several uploaded files (a multiple file input, e.g. $_FILES["photos"]) into the free
 * slots of a set. Returns [how many were saved, the problems as sentences].
 */
function photo_add_many(?array $files, string $set): array
{
    $free = array_values(array_diff(range(1, PHOTO_SLOTS), array_keys(photo_list($set))));
    $saved = 0;
    $problems = [];

    if ($files && is_array($files["name"] ?? null)) {
        foreach ($files["name"] as $index => $name) {
            if ($files["error"][$index] === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if (!$free) {
                $problems[] = "There is room for " . PHOTO_SLOTS . " photos. Remove one to add another.";
                break;
            }

            $problem = photo_save([
                "name" => $name,
                "tmp_name" => $files["tmp_name"][$index],
                "error" => $files["error"][$index],
                "size" => $files["size"][$index],
            ], $set, $free[0]);

            if ($problem === "") {
                array_shift($free);
                $saved++;
            } else {
                $problems[] = $problem;
            }
        }
    }

    return [$saved, array_values(array_unique($problems))];
}

// Removes one photo and closes the gap it leaves. False when there was no such photo.
function photo_remove(string $set, int $slot): bool
{
    if ($slot < 1 || $slot > PHOTO_SLOTS || !is_file(photo_file($set, $slot))) {
        return false;
    }

    @unlink(photo_file($set, $slot));

    foreach (array_values(array_keys(photo_list($set))) as $index => $from) {
        if ($from !== $index + 1) {
            @rename(photo_file($set, $from), photo_file($set, $index + 1));
        }
    }

    return true;
}

// Removes every photo of a set (a room that is deleted).
function photo_remove_all(string $set): void
{
    for ($slot = 1; $slot <= PHOTO_SLOTS; $slot++) {
        if (is_file(photo_file($set, $slot))) {
            @unlink(photo_file($set, $slot));
        }
    }
}

// The chosen photo changes places with photo 1. False when that is not possible.
function photo_first(string $set, int $slot): bool
{
    if ($slot <= 1 || $slot > PHOTO_SLOTS || !is_file(photo_file($set, $slot)) || !is_file(photo_file($set, 1))) {
        return false;
    }

    $spare = photo_file($set, 1) . ".swap";

    @rename(photo_file($set, 1), $spare);
    @rename(photo_file($set, $slot), photo_file($set, 1));
    @rename($spare, photo_file($set, $slot));

    // new change times, so browsers fetch both again
    @touch(photo_file($set, 1));
    @touch(photo_file($set, $slot));

    return true;
}
