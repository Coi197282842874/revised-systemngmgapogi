<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/admin-shell.php";
require_once __DIR__ . "/../includes/photos.php";

$admin = admin_boot($pdo, "rooms");

/*
 * Photos of a room are files, not database rows: uploads/rooms/room_<id>_<1 to 6>.jpg
 * (the public rooms page and the booking page look for exactly these names).
 * includes/photos.php does the work; these are the room's names for it.
 */
const ROOM_PHOTO_SLOTS = PHOTO_SLOTS;

const ROOM_STATUSES = [
    "available" => "Available: guests can book it",
    "maintenance" => "Maintenance: closed for repairs, hidden from guests",
    "inactive" => "Inactive: no longer offered, hidden from guests",
];

function room_photo_path(int $roomId, int $slot): string
{
    return photo_file("rooms/room_" . $roomId, $slot);
}

// slot => address of the photo, as seen from an admin page
function room_photos(int $roomId): array
{
    return photo_list("rooms/room_" . $roomId, "../");
}

function room_photo_save(array $file, int $roomId, int $slot): string
{
    return photo_save($file, "rooms/room_" . $roomId, $slot);
}

// What the add and edit forms send, checked. Returns [values, error].
function room_form_values(): array
{
    $values = [
        "room_name" => mb_substr(trim((string) ($_POST["room_name"] ?? "")), 0, 150),
        "description" => trim((string) ($_POST["description"] ?? "")),
        "capacity" => (int) ($_POST["capacity"] ?? 0),
        "price" => (float) ($_POST["price"] ?? 0),
        "status" => (string) ($_POST["status"] ?? "available"),
    ];

    $error = "";

    if ($values["room_name"] === "") {
        $error = "Please enter the room's name.";
    } elseif ($values["capacity"] < 1 || $values["capacity"] > 100) {
        $error = "The number of guests must be between 1 and 100.";
    } elseif ($values["price"] < 0 || $values["price"] > 9999999) {
        $error = "Please enter a valid price per night.";
    } elseif (!isset(ROOM_STATUSES[$values["status"]])) {
        $error = "Please choose a status from the list.";
    }

    return [$values, $error];
}


// ======================================================
// FILTER: ?q= (room name or description)
// ======================================================

$search = admin_query();
$here = admin_url("rooms.php", ["q" => $search]);

// a form that failed is shown again, open, with what was typed
$openDialog = "";
$formError = "";
$formValues = [];


// ======================================================
// ADD, EDIT, DELETE, PHOTOS
// ======================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = (string) ($_POST["action"] ?? "");
    $roomId = (int) ($_POST["room_id"] ?? 0);

    $room = null;

    if ($roomId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ? LIMIT 1");
        $stmt->execute([$roomId]);
        $room = $stmt->fetch() ?: null;
    }

    $done = false;

    // a request larger than the server accepts arrives with nothing in it
    if (!$_POST && (int) ($_SERVER["CONTENT_LENGTH"] ?? 0) > 0) {
        admin_flash("Those photos are too large to upload in one go. Try fewer or smaller photos.", "error");
        $done = true;

    } elseif (!admin_check_csrf()) {
        admin_flash("Your session expired. Please try again.", "error");
        $done = true;

    } elseif ($action === "add") {

        [$formValues, $formError] = room_form_values();

        if ($formError === "") {
            $pdo->prepare(
                "INSERT INTO rooms (room_name, description, capacity, price, status) VALUES (?, ?, ?, ?, ?)"
            )->execute(array_values($formValues));

            $newId = (int) $pdo->lastInsertId();

            log_activity($pdo, "room.created", "Added room " . $formValues["room_name"], [
                "entity_type" => "room",
                "entity_id" => $newId,
                "link" => "rooms.php?q=" . rawurlencode($formValues["room_name"]),
            ]);

            admin_flash("Room “" . $formValues["room_name"] . "” was added. You can add its photos now.");
            $done = true;
        } else {
            $openDialog = "room-add";
        }

    } elseif ($room && $action === "edit") {

        [$formValues, $formError] = room_form_values();

        if ($formError === "") {
            $pdo->prepare(
                "UPDATE rooms SET room_name = ?, description = ?, capacity = ?, price = ?, status = ? WHERE id = ?"
            )->execute(array_merge(array_values($formValues), [$roomId]));

            log_activity($pdo, "room.updated", "Edited room " . $formValues["room_name"], [
                "entity_type" => "room",
                "entity_id" => $roomId,
                "link" => "rooms.php?q=" . rawurlencode($formValues["room_name"]),
            ]);

            admin_flash("Room “" . $formValues["room_name"] . "” was saved.");
            $done = true;
        } else {
            $openDialog = "room-edit-" . $roomId;
        }

    } elseif ($room && $action === "delete") {

        try {
            $pdo->prepare("DELETE FROM rooms WHERE id = ?")->execute([$roomId]);

            for ($slot = 1; $slot <= ROOM_PHOTO_SLOTS; $slot++) {
                if (is_file(room_photo_path($roomId, $slot))) {
                    @unlink(room_photo_path($roomId, $slot));
                }
            }

            log_activity($pdo, "room.deleted", "Deleted room " . $room["room_name"], [
                "entity_type" => "room",
                "entity_id" => $roomId,
            ]);

            admin_flash("Room “" . $room["room_name"] . "” was deleted.");
        } catch (PDOException $e) {
            admin_flash(
                "“" . $room["room_name"] . "” cannot be deleted because it has reservations. "
                    . "Set its status to Inactive to hide it from guests instead.",
                "error"
            );
        }

        $done = true;

    } elseif ($room && $action === "photos_add") {

        $files = $_FILES["photos"] ?? null;
        $free = array_values(array_diff(range(1, ROOM_PHOTO_SLOTS), array_keys(room_photos($roomId))));
        $saved = 0;
        $problems = [];

        if ($files && is_array($files["name"])) {
            foreach ($files["name"] as $index => $name) {
                if ($files["error"][$index] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }

                if (!$free) {
                    $problems[] = "A room holds " . ROOM_PHOTO_SLOTS . " photos. Remove one to add another.";
                    break;
                }

                $problem = room_photo_save([
                    "name" => $name,
                    "tmp_name" => $files["tmp_name"][$index],
                    "error" => $files["error"][$index],
                    "size" => $files["size"][$index],
                ], $roomId, $free[0]);

                if ($problem === "") {
                    array_shift($free);
                    $saved++;
                } else {
                    $problems[] = $problem;
                }
            }
        }

        if ($saved > 0) {
            log_activity($pdo, "room.updated", "Added " . $saved . ($saved === 1 ? " photo" : " photos") . " to room " . $room["room_name"], [
                "entity_type" => "room",
                "entity_id" => $roomId,
                "link" => "rooms.php?q=" . rawurlencode($room["room_name"]),
            ]);

            admin_flash($saved . ($saved === 1 ? " photo" : " photos") . " added to “" . $room["room_name"] . "”.");
        }

        if ($problems) {
            admin_flash(implode(" ", array_unique($problems)), "error");
        } elseif ($saved === 0) {
            admin_flash("Choose one or more photos first.", "error");
        }

        header("Location: " . admin_url("rooms.php", ["q" => $search, "photos" => $roomId]));
        exit;

    } elseif ($room && $action === "photo_remove") {

        $slot = (int) ($_POST["slot"] ?? 0);

        if ($slot >= 1 && $slot <= ROOM_PHOTO_SLOTS && is_file(room_photo_path($roomId, $slot))) {
            @unlink(room_photo_path($roomId, $slot));

            // close the gap, so the first photo (the one guests see first) is never missing
            $rest = array_keys(room_photos($roomId));

            foreach (array_values($rest) as $index => $from) {
                if ($from !== $index + 1) {
                    @rename(room_photo_path($roomId, $from), room_photo_path($roomId, $index + 1));
                }
            }

            log_activity($pdo, "room.updated", "Removed a photo from room " . $room["room_name"], [
                "entity_type" => "room",
                "entity_id" => $roomId,
                "link" => "rooms.php?q=" . rawurlencode($room["room_name"]),
            ]);

            admin_flash("Photo removed.");
        }

        header("Location: " . admin_url("rooms.php", ["q" => $search, "photos" => $roomId]));
        exit;

    } elseif ($room && $action === "photo_first") {

        // the chosen photo changes places with the first one
        $slot = (int) ($_POST["slot"] ?? 0);

        if ($slot > 1 && $slot <= ROOM_PHOTO_SLOTS && is_file(room_photo_path($roomId, $slot)) && is_file(room_photo_path($roomId, 1))) {
            $spare = room_photo_path($roomId, 1) . ".swap";

            @rename(room_photo_path($roomId, 1), $spare);
            @rename(room_photo_path($roomId, $slot), room_photo_path($roomId, 1));
            @rename($spare, room_photo_path($roomId, $slot));
            @touch(room_photo_path($roomId, 1));
            @touch(room_photo_path($roomId, $slot));

            admin_flash("That photo is now the first one guests see.");
        }

        header("Location: " . admin_url("rooms.php", ["q" => $search, "photos" => $roomId]));
        exit;

    } else {
        admin_flash("That room no longer exists.", "error");
        $done = true;
    }

    if ($done) {
        header("Location: " . $here);
        exit;
    }
}

// after a photo was added or removed, the photos window of that room opens again
if ($openDialog === "" && isset($_GET["photos"])) {
    $openDialog = "room-photos-" . (int) $_GET["photos"];
}


// ======================================================
// THE ROOMS
// ======================================================

$counts = ["all" => 0, "available" => 0, "maintenance" => 0, "inactive" => 0];
$priceSum = 0.0;

foreach ($pdo->query("SELECT status, COUNT(*) AS total, SUM(price) AS prices FROM rooms GROUP BY status") as $row) {
    if (isset($counts[$row["status"]])) {
        $counts[$row["status"]] = (int) $row["total"];
    }

    $counts["all"] += (int) $row["total"];
    $priceSum += (float) $row["prices"];
}

$sql = "
    SELECT
        rooms.*,
        (
            SELECT COUNT(*) FROM reservations
            WHERE reservations.room_id = rooms.id
        ) AS reservations,
        (
            SELECT COUNT(*) FROM reservations
            WHERE reservations.room_id = rooms.id
            AND reservations.status IN ('pending', 'confirmed')
            AND reservations.check_out > CURDATE()
        ) AS upcoming
    FROM rooms
";

$params = [];

if ($search !== "") {
    $sql .= " WHERE rooms.room_name LIKE ? OR rooms.description LIKE ?";
    $params = [admin_like($search), admin_like($search)];
}

$stmt = $pdo->prepare($sql . " ORDER BY rooms.status = 'available' DESC, rooms.room_name");
$stmt->execute($params);
$rooms = $stmt->fetchAll();

// the add / edit form, used in several windows
$roomForm = function (string $prefix, array $values) {
    ?>
    <div class="form-grid">
        <label class="field field-wide">
            <span class="label">Room name</span>
            <input class="input" type="text" name="room_name" value="<?= h($values["room_name"] ?? "") ?>" placeholder="Example: Family Room" maxlength="150" required>
        </label>

        <label class="field">
            <span class="label">Guests it holds</span>
            <input class="input" type="number" name="capacity" value="<?= h($values["capacity"] ?? "") ?>" inputmode="numeric" min="1" max="100" placeholder="Example: 4" required>
        </label>

        <label class="field">
            <span class="label">Price per night (₱)</span>
            <input class="input" type="number" name="price" value="<?= h($values["price"] ?? "") ?>" inputmode="decimal" min="0" step="0.01" placeholder="Example: 1500" required>
        </label>

        <label class="field field-wide">
            <span class="label">Status</span>
            <select class="select" name="status">
                <?php foreach (ROOM_STATUSES as $status => $label): ?>
                    <option value="<?= h($status) ?>" <?= ($values["status"] ?? "available") === $status ? "selected" : "" ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="field field-wide">
            <span class="label">Description</span>
            <textarea class="textarea" name="description" placeholder="Beds, air conditioning, bathroom, what is included…"><?= h($values["description"] ?? "") ?></textarea>
        </label>
    </div>
    <?php
};

admin_shell_head([
    "title" => "Rooms",
    "subtitle" => "The rooms guests can book, their prices and photos",
    "active" => "rooms",
]);
?>
<style>
    .rooms {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 20px;
    }

    .room {
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    .room-photo {
        position: relative;
        aspect-ratio: 16 / 10;
        background: var(--surface-3);
    }

    .room-photo img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .room-photo-none {
        display: grid;
        place-items: center;
        align-content: center;
        gap: 6px;
        height: 100%;
        color: var(--text-3);
        font-size: 12.5px;
    }

    .room-photo .pill {
        position: absolute;
        top: 12px;
        left: 12px;
        background: var(--surface);
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.25);
    }

    .room-photo-count {
        position: absolute;
        right: 12px;
        bottom: 12px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 8px;
        border-radius: 999px;
        background: rgba(0, 0, 0, 0.62);
        color: #fff;
        font-size: 11.5px;
        font-weight: 600;
    }

    .room-body {
        display: flex;
        flex: 1;
        flex-direction: column;
        gap: 10px;
        padding: 16px 18px 18px;
    }

    .room-title {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 12px;
    }

    .room-title h2 {
        min-width: 0;
        font-size: 16px;
        font-weight: 700;
        letter-spacing: -0.01em;
        overflow-wrap: anywhere;
    }

    .room-price {
        flex: none;
        font-size: 15px;
        font-weight: 700;
        white-space: nowrap;
    }

    .room-price span {
        color: var(--text-3);
        font-size: 12px;
        font-weight: 400;
    }

    .room-text {
        display: -webkit-box;
        overflow: hidden;
        color: var(--text-2);
        font-size: 13px;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 3;
        line-clamp: 3;
    }

    .room-facts {
        display: flex;
        flex-wrap: wrap;
        gap: 6px 14px;
        color: var(--text-3);
        font-size: 12.5px;
    }

    .room-facts span {
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    .room-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: auto;
        padding-top: 6px;
    }

    .room-actions .btn {
        flex: 1 1 auto;
    }

    @media (max-width: 767px) {
        .rooms {
            grid-template-columns: minmax(0, 1fr);
            gap: 14px;
        }
    }
</style>
<?php admin_shell_body(); ?>

<div class="stack">

    <!-- NUMBERS -->

    <section class="stats stats-compact" aria-label="All rooms">

        <div class="stat tone-blue">
            <div class="stat-main">
                <div class="stat-label">Rooms</div>
                <div class="stat-value"><?= number_format($counts["all"]) ?></div>
                <div class="stat-note">In total</div>
            </div>
            <div class="stat-icon"><?= icon("bed") ?></div>
        </div>

        <div class="stat tone-green">
            <div class="stat-main">
                <div class="stat-label">Available</div>
                <div class="stat-value"><?= number_format($counts["available"]) ?></div>
                <div class="stat-note">Guests can book them</div>
            </div>
            <div class="stat-icon"><?= icon("check-circle") ?></div>
        </div>

        <div class="stat tone-amber">
            <div class="stat-main">
                <div class="stat-label">Closed</div>
                <div class="stat-value"><?= number_format($counts["maintenance"] + $counts["inactive"]) ?></div>
                <div class="stat-note"><?= number_format($counts["maintenance"]) ?> maintenance, <?= number_format($counts["inactive"]) ?> inactive</div>
            </div>
            <div class="stat-icon"><?= icon("lock") ?></div>
        </div>

        <div class="stat tone-violet">
            <div class="stat-main">
                <div class="stat-label">Average price</div>
                <div class="stat-value"><?= h(peso($counts["all"] > 0 ? $priceSum / $counts["all"] : 0)) ?></div>
                <div class="stat-note">Per night</div>
            </div>
            <div class="stat-icon"><?= icon("banknote") ?></div>
        </div>

    </section>


    <div>

        <div class="page-bar">
            <form class="filter-form" method="get" role="search">
                <div class="filter-search">
                    <?= icon("search") ?>
                    <label class="sr-only" for="q">Search rooms</label>
                    <input class="input" type="search" id="q" name="q" value="<?= h($search) ?>" placeholder="Search rooms" autocomplete="off" enterkeyhint="search">
                </div>
                <button class="btn" type="submit">Search</button>
            </form>

            <div class="page-bar-end">
                <button class="btn btn-primary" type="button" data-dialog-open="room-add"><?= icon("plus") ?> Add room</button>
            </div>
        </div>

        <?php if ($search !== ""): ?>
            <p class="filter-note">
                <?= count($rooms) ?> <?= count($rooms) === 1 ? "room matches" : "rooms match" ?> “<?= h($search) ?>”.
                <a href="rooms.php">Clear the search</a>
            </p>
        <?php endif; ?>

        <?php if ($rooms): ?>

            <div class="rooms">
                <?php foreach ($rooms as $room): ?>
                    <?php
                    $id = (int) $room["id"];
                    $photos = room_photos($id);
                    ?>
                    <article class="card room">
                        <div class="room-photo">
                            <?php if ($photos): ?>
                                <img src="<?= h(reset($photos)) ?>" alt="" loading="lazy" decoding="async">
                                <span class="room-photo-count"><?= icon("image", 13) ?> <?= count($photos) ?></span>
                            <?php else: ?>
                                <div class="room-photo-none">
                                    <?= icon("image", 26) ?>
                                    <span>No photos yet</span>
                                </div>
                            <?php endif; ?>
                            <?= status_pill($room["status"]) ?>
                        </div>

                        <div class="room-body">
                            <div class="room-title">
                                <h2><?= h($room["room_name"]) ?></h2>
                                <div class="room-price"><?= h(peso($room["price"])) ?> <span>/ night</span></div>
                            </div>

                            <?php if (trim((string) $room["description"]) !== ""): ?>
                                <p class="room-text"><?= h($room["description"]) ?></p>
                            <?php endif; ?>

                            <div class="room-facts">
                                <span><?= icon("users", 14) ?> Up to <?= (int) $room["capacity"] ?> <?= (int) $room["capacity"] === 1 ? "guest" : "guests" ?></span>
                                <span><?= icon("calendar", 14) ?> <?= (int) $room["reservations"] ?> <?= (int) $room["reservations"] === 1 ? "reservation" : "reservations" ?><?= (int) $room["upcoming"] > 0 ? ", " . (int) $room["upcoming"] . " upcoming" : "" ?></span>
                            </div>

                            <div class="room-actions">
                                <button class="btn btn-sm" type="button" data-dialog-open="room-edit-<?= $id ?>"><?= icon("edit") ?> Edit</button>
                                <button class="btn btn-sm" type="button" data-dialog-open="room-photos-<?= $id ?>"><?= icon("image") ?> Photos</button>
                                <?php if (admin_can("calendar")): ?>
                                    <a class="btn btn-sm" href="calendar.php?room=<?= $id ?>"><?= icon("calendar") ?> Calendar</a>
                                <?php endif; ?>

                                <?php if ((int) $room["reservations"] > 0): ?>
                                    <!-- a room with reservations stays: its bookings and payments point to it -->
                                    <button class="btn btn-sm btn-ghost" type="button" disabled
                                        title="This room has reservations, so it cannot be deleted. Set its status to Inactive to hide it from guests."
                                        aria-label="Delete (not possible: this room has reservations)"><?= icon("trash") ?></button>
                                <?php else: ?>
                                    <form class="inline-form" method="post" action="<?= h($here) ?>"
                                        data-confirm="“<?= h($room["room_name"]) ?>” and its photos will be deleted. This cannot be undone."
                                        data-confirm-title="Delete this room?"
                                        data-confirm-ok="Delete"
                                        data-confirm-tone="danger"
                                    >
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="room_id" value="<?= $id ?>">
                                        <button class="btn btn-sm btn-danger-soft" type="submit" aria-label="Delete <?= h($room["room_name"]) ?>"><?= icon("trash") ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

        <?php else: ?>

            <section class="card">
                <div class="empty">
                    <div class="empty-icon"><?= icon("bed", 22) ?></div>
                    <h3><?= $search !== "" ? "No rooms found" : "No rooms yet" ?></h3>
                    <p><?= $search !== "" ? "Nothing matches what you typed." : "Add your first room so guests can start booking." ?></p>
                    <?php if ($search !== ""): ?>
                        <a class="btn" href="rooms.php">Show all rooms</a>
                    <?php else: ?>
                        <button class="btn btn-primary" type="button" data-dialog-open="room-add"><?= icon("plus") ?> Add room</button>
                    <?php endif; ?>
                </div>
            </section>

        <?php endif; ?>

    </div>

</div>


<!-- ADD A ROOM -->

<dialog class="dialog dialog-wide" id="room-add" aria-labelledby="room-add-title" <?= $openDialog === "room-add" ? "data-dialog-auto" : "" ?>>
    <form method="post" action="<?= h($here) ?>">
        <div class="dialog-head">
            <h2 id="room-add-title" tabindex="-1" autofocus>Add a room</h2>
            <button class="btn btn-ghost btn-icon" type="button" data-dialog-close aria-label="Close"><?= icon("x") ?></button>
        </div>

        <div class="dialog-body">
            <?php if ($openDialog === "room-add" && $formError !== ""): ?>
                <div class="alert alert-error" role="alert"><?= icon("alert") ?><p><?= h($formError) ?></p></div>
            <?php endif; ?>

            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <?php $roomForm("add", $openDialog === "room-add" ? $formValues : []); ?>
        </div>

        <div class="dialog-actions">
            <button class="btn" type="button" data-dialog-close>Cancel</button>
            <button class="btn btn-primary" type="submit">Add room</button>
        </div>
    </form>
</dialog>


<!-- EDIT AND PHOTOS, PER ROOM -->

<?php foreach ($rooms as $room): ?>
    <?php
    $id = (int) $room["id"];
    $photos = room_photos($id);
    $editing = $openDialog === "room-edit-" . $id;
    ?>

    <dialog class="dialog dialog-wide" id="room-edit-<?= $id ?>" aria-labelledby="room-edit-<?= $id ?>-title" <?= $editing ? "data-dialog-auto" : "" ?>>
        <form method="post" action="<?= h($here) ?>">
            <div class="dialog-head">
                <h2 id="room-edit-<?= $id ?>-title" tabindex="-1" autofocus>Edit <?= h($room["room_name"]) ?></h2>
                <button class="btn btn-ghost btn-icon" type="button" data-dialog-close aria-label="Close"><?= icon("x") ?></button>
            </div>

            <div class="dialog-body">
                <?php if ($editing && $formError !== ""): ?>
                    <div class="alert alert-error" role="alert"><?= icon("alert") ?><p><?= h($formError) ?></p></div>
                <?php endif; ?>

                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="room_id" value="<?= $id ?>">
                <?php $roomForm("edit-" . $id, $editing ? $formValues : $room); ?>

                <?php if ((int) $room["upcoming"] > 0): ?>
                    <p class="hint">
                        This room has <?= (int) $room["upcoming"] ?> upcoming <?= (int) $room["upcoming"] === 1 ? "reservation" : "reservations" ?>.
                        A new price only applies to new bookings, and closing the room does not cancel them.
                    </p>
                <?php endif; ?>
            </div>

            <div class="dialog-actions">
                <button class="btn" type="button" data-dialog-close>Cancel</button>
                <button class="btn btn-primary" type="submit">Save changes</button>
            </div>
        </form>
    </dialog>

    <dialog class="dialog dialog-wide" id="room-photos-<?= $id ?>" aria-labelledby="room-photos-<?= $id ?>-title" <?= $openDialog === "room-photos-" . $id ? "data-dialog-auto" : "" ?>>
        <div class="dialog-head">
            <h2 id="room-photos-<?= $id ?>-title" tabindex="-1" autofocus>Photos of <?= h($room["room_name"]) ?></h2>
            <button class="btn btn-ghost btn-icon" type="button" data-dialog-close aria-label="Close"><?= icon("x") ?></button>
        </div>

        <div class="dialog-body">
            <div class="photo-grid">
                <?php for ($slot = 1; $slot <= ROOM_PHOTO_SLOTS; $slot++): ?>
                    <div class="photo-slot">
                        <?php if (isset($photos[$slot])): ?>
                            <img src="<?= h($photos[$slot]) ?>" alt="Photo <?= $slot ?>" loading="lazy" decoding="async">

                            <?php if ($slot === 1): ?>
                                <span class="photo-slot-tag">First</span>
                            <?php endif; ?>

                            <div class="photo-slot-actions">
                                <?php if ($slot > 1): ?>
                                    <form class="inline-form" method="post" action="<?= h($here) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="photo_first">
                                        <input type="hidden" name="room_id" value="<?= $id ?>">
                                        <input type="hidden" name="slot" value="<?= $slot ?>">
                                        <button class="btn" type="submit">Make first</button>
                                    </form>
                                <?php endif; ?>

                                <form class="inline-form" method="post" action="<?= h($here) ?>"
                                    data-confirm="This photo will be removed from the room."
                                    data-confirm-title="Remove this photo?"
                                    data-confirm-ok="Remove"
                                    data-confirm-tone="danger"
                                >
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="photo_remove">
                                    <input type="hidden" name="room_id" value="<?= $id ?>">
                                    <input type="hidden" name="slot" value="<?= $slot ?>">
                                    <button class="btn" type="submit" aria-label="Remove photo <?= $slot ?>"><?= icon("trash", 14) ?></button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="photo-slot-empty">Empty</div>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>

            <hr class="divider">

            <?php if (count($photos) < ROOM_PHOTO_SLOTS): ?>
                <form method="post" action="<?= h($here) ?>" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="photos_add">
                    <input type="hidden" name="room_id" value="<?= $id ?>">

                    <label class="field">
                        <span class="label">Add photos (<?= ROOM_PHOTO_SLOTS - count($photos) ?> more fit)</span>
                        <input class="input" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required>
                    </label>
                    <p class="hint">JPG, PNG or WebP, up to 5 MB each. The first photo is the one guests see first.</p>

                    <div class="form-actions">
                        <button class="btn btn-primary" type="submit"><?= icon("upload") ?> Upload</button>
                    </div>
                </form>
            <?php else: ?>
                <p class="note">This room has all <?= ROOM_PHOTO_SLOTS ?> photos. Remove one to add another.</p>
            <?php endif; ?>
        </div>

        <div class="dialog-actions">
            <button class="btn" type="button" data-dialog-close>Done</button>
        </div>
    </dialog>

<?php endforeach; ?>

<?php admin_shell_end(); ?>
