<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/admin-shell.php";
require_once __DIR__ . "/../includes/availability.php";

// ======================================================
// BOOKING CALENDAR + CLOSED DATES
// ======================================================

$admin = admin_boot($pdo, "calendar");

ensure_availability_schema($pdo);

$rooms = $pdo->query("SELECT id, room_name FROM rooms ORDER BY room_name")->fetchAll();
$roomNames = array_column($rooms, "room_name", "id");

$roomFilter = $_GET["room"] ?? "all";
$roomFilter = ($roomFilter !== "all" && isset($roomNames[(int) $roomFilter])) ? (int) $roomFilter : "all";

$monthParam = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $_GET["month"] ?? "") ? $_GET["month"] : date("Y-m");
$monthStart = new DateTimeImmutable($monthParam . "-01");
$monthEnd = $monthStart->modify("last day of this month");

$error = "";
$form = ["room_id" => $roomFilter === "all" ? "all" : (string) $roomFilter, "start_date" => "", "end_date" => "", "reason" => ""];

function cal_link(string $month, $room): string
{
    return admin_url("calendar.php", ["month" => $month === date("Y-m") ? "" : $month, "room" => $room]);
}

// "Oct 12 to Oct 14" (or "Oct 12" for one day), for the activity log
function cal_range(string $start, string $end): string
{
    return $start === $end
        ? date("M j", strtotime($start))
        : date("M j", strtotime($start)) . " to " . date("M j", strtotime($end));
}

function block_label(array $block, array $roomNames): string
{
    $range = $block["start_date"] === $block["end_date"]
        ? date("M j, Y", strtotime($block["start_date"]))
        : date("M j", strtotime($block["start_date"])) . " – " . date("M j, Y", strtotime($block["end_date"]));

    return $range . " · " . ($block["room_id"] === null ? "All rooms" : ($roomNames[$block["room_id"]] ?? "Room"));
}

// ------------------------------------------------------
// ADD / REMOVE A CLOSED PERIOD
// ------------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!admin_check_csrf()) {
        $error = "Your session expired. Please try again.";
    } elseif (isset($_POST["delete_block"])) {
        $stmt = $pdo->prepare("SELECT * FROM room_blocks WHERE id = ?");
        $stmt->execute([(int) $_POST["delete_block"]]);
        $block = $stmt->fetch();

        if ($block) {
            $pdo->prepare("DELETE FROM room_blocks WHERE id = ?")->execute([(int) $block["id"]]);

            log_activity(
                $pdo,
                "dates.opened",
                "Opened " . ($block["room_id"] === null ? "all rooms" : ($roomNames[$block["room_id"]] ?? "a room"))
                    . " again, " . cal_range($block["start_date"], $block["end_date"]),
                ["entity_type" => "block", "entity_id" => (int) $block["id"], "link" => "calendar.php?month=" . substr($block["start_date"], 0, 7)]
            );

            admin_flash("Closed dates removed. Those dates can be booked again.");
        }

        header("Location: " . cal_link($monthParam, $roomFilter));
        exit;
    } else {
        $form = [
            "room_id" => $_POST["room_id"] ?? "all",
            "start_date" => trim($_POST["start_date"] ?? ""),
            "end_date" => trim($_POST["end_date"] ?? ""),
            "reason" => trim($_POST["reason"] ?? ""),
        ];

        $start = DateTimeImmutable::createFromFormat("!Y-m-d", $form["start_date"]);
        $end = DateTimeImmutable::createFromFormat("!Y-m-d", $form["end_date"]);
        $roomId = $form["room_id"] === "all" ? null : (int) $form["room_id"];

        if (!$start || !$end || $start->format("Y-m-d") !== $form["start_date"] || $end->format("Y-m-d") !== $form["end_date"]) {
            $error = "Please choose valid start and end dates.";
        } elseif ($end < $start) {
            $error = "The end date must be on or after the start date.";
        } elseif ($start->diff($end)->days > 366) {
            $error = "Please close at most one year at a time.";
        } elseif ($roomId !== null && !isset($roomNames[$roomId])) {
            $error = "Please choose a valid room.";
        } else {
            $reason = mb_substr($form["reason"], 0, 150);

            $pdo->prepare(
                "INSERT INTO room_blocks (room_id, start_date, end_date, reason, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([$roomId, $form["start_date"], $form["end_date"], $reason, (int) $admin["id"], utc_now()]);

            $blockId = (int) $pdo->lastInsertId();

            // bookings already inside the closed dates are kept; tell the admin about them
            $clash = $pdo->prepare(
                "SELECT COUNT(*) FROM reservations
                 WHERE status IN " . AVAILABILITY_HOLDING_STATUSES . "
                 AND check_in <= ? AND check_out > ?" . ($roomId !== null ? " AND room_id = ?" : "")
            );
            $clash->execute(array_merge([$form["end_date"], $form["start_date"]], $roomId !== null ? [$roomId] : []));
            $clashes = (int) $clash->fetchColumn();

            log_activity(
                $pdo,
                "dates.closed",
                "Closed " . ($roomId === null ? "all rooms" : $roomNames[$roomId]) . ", "
                    . cal_range($form["start_date"], $form["end_date"]) . ($reason !== "" ? ": " . $reason : ""),
                ["entity_type" => "block", "entity_id" => $blockId, "link" => "calendar.php?month=" . $start->format("Y-m")]
            );

            admin_flash("Dates closed. Guests can no longer book them.");

            if ($clashes > 0) {
                admin_flash(
                    $clashes . ($clashes === 1 ? " existing reservation falls" : " existing reservations fall")
                        . " inside those dates and " . ($clashes === 1 ? "was" : "were") . " kept. Contact the guest"
                        . ($clashes === 1 ? "" : "s") . " if needed.",
                    "error"
                );
            }

            header("Location: " . cal_link($start->format("Y-m"), $roomId ?? "all"));
            exit;
        }
    }
}

// ------------------------------------------------------
// WHAT HAPPENS EACH DAY OF THE MONTH
// ------------------------------------------------------

$from = $monthStart->format("Y-m-d");
$to = $monthEnd->format("Y-m-d");

$sql = "SELECT r.id, r.room_id, r.check_in, r.check_out, r.status, r.guests, r.total_amount, u.full_name, u.profile_image
        FROM reservations r
        JOIN users u ON u.id = r.user_id
        WHERE r.status IN ('pending', 'confirmed', 'completed')
        AND r.check_in <= ? AND r.check_out > ?"
    . ($roomFilter !== "all" ? " AND r.room_id = ?" : "")
    . " ORDER BY r.check_in";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$to, $from], $roomFilter !== "all" ? [$roomFilter] : []));
$reservations = $stmt->fetchAll();

$sql = "SELECT * FROM room_blocks WHERE start_date <= ? AND end_date >= ?"
    . ($roomFilter !== "all" ? " AND (room_id = ? OR room_id IS NULL)" : "")
    . " ORDER BY start_date";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$to, $from], $roomFilter !== "all" ? [$roomFilter] : []));
$blocks = $stmt->fetchAll();

$days = [];

foreach ($reservations as $r) {
    for ($d = new DateTimeImmutable(max($r["check_in"], $from)); $d->format("Y-m-d") < $r["check_out"] && $d <= $monthEnd; $d = $d->modify("+1 day")) {
        $days[$d->format("Y-m-d")]["stays"][] = $r;
    }
}

foreach ($blocks as $b) {
    for ($d = new DateTimeImmutable(max($b["start_date"], $from)); $d->format("Y-m-d") <= $b["end_date"] && $d <= $monthEnd; $d = $d->modify("+1 day")) {
        $days[$d->format("Y-m-d")]["blocks"][] = $b;
    }
}

$upcomingBlocks = $pdo->query(
    "SELECT * FROM room_blocks WHERE end_date >= CURDATE() ORDER BY start_date LIMIT 50"
)->fetchAll();

$prevMonth = $monthStart->modify("-1 month")->format("Y-m");
$nextMonth = $monthStart->modify("+1 month")->format("Y-m");
$today = date("Y-m-d");

// each kind of day entry has its own icon, so it is never told apart by color alone
$chipIcons = ["confirmed" => "check-circle", "pending" => "clock", "completed" => "star"];

admin_shell_head([
    "title" => "Calendar",
    "subtitle" => "Bookings per day, and dates you close for maintenance or personal use",
    "active" => "calendar",
]);
?>
<style>
    .cal-bar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 14px 16px;
        border-bottom: 1px solid var(--line);
    }

    .cal-nav {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .cal-nav strong {
        min-width: 150px;
        font-size: 16px;
        text-align: center;
    }

    .cal-tools {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .cal-tools .select {
        width: auto;
        min-width: 150px;
        background-color: var(--surface);
    }

    .cal {
        display: grid;
        grid-template-columns: repeat(7, minmax(0, 1fr));
    }

    .wd {
        padding: 9px 6px;
        border-bottom: 1px solid var(--line);
        color: var(--text-3);
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-align: center;
        text-transform: uppercase;
    }

    .day {
        min-width: 0;
        min-height: 112px;
        padding: 6px;
        border-right: 1px solid var(--line);
        border-bottom: 1px solid var(--line);
    }

    .day:nth-child(7n) {
        border-right: 0;
    }

    .day.out {
        background: var(--surface-2);
    }

    .day.past .day-num {
        opacity: 0.55;
    }

    /* closed days wear stripes */
    .day.closed {
        background-image: repeating-linear-gradient(135deg, transparent 0 7px, var(--danger-soft) 7px 14px);
    }

    .day-num {
        display: inline-grid;
        place-items: center;
        min-width: 24px;
        height: 24px;
        padding: 0 5px;
        border-radius: 999px;
        color: var(--text-2);
        font-size: 12px;
        font-weight: 700;
        font-variant-numeric: tabular-nums;
    }

    .day.today .day-num {
        background: var(--accent);
        color: var(--on-accent);
        opacity: 1;
    }

    .chip {
        display: flex;
        align-items: center;
        gap: 4px;
        margin-top: 4px;
        padding: 3px 6px;
        overflow: hidden;
        border-radius: 6px;
        font-size: 11px;
        font-weight: 600;
        line-height: 1.35;
        white-space: nowrap;
    }

    .chip:hover {
        text-decoration: none;
    }

    .chip .i {
        width: 11px;
        height: 11px;
    }

    .chip .who {
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .chip.confirmed { background: var(--success-soft); color: var(--success); }
    .chip.pending { background: var(--warning-soft); color: var(--warning); }
    .chip.completed { background: var(--info-soft); color: var(--info); }
    .chip.block { background: var(--danger-soft); color: var(--danger); }

    @media (hover: hover) and (pointer: fine) {
        a.chip:hover {
            filter: brightness(1.12);
        }
    }

    .cal-legend {
        display: flex;
        flex-wrap: wrap;
        gap: 8px 16px;
        padding: 12px 16px;
        color: var(--text-2);
        font-size: 12px;
    }

    .cal-legend span {
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .cal-legend .chip {
        margin: 0;
        padding: 3px 5px;
    }

    .date-pair {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        gap: 12px;
        margin-top: 16px;
    }

    .date-pair .field + .field {
        margin-top: 0;
    }

    .date-pair .input {
        min-width: 0;
    }

    .blocks li {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 11px 0;
        border-top: 1px solid var(--line);
        font-size: 13px;
    }

    .blocks li:first-child {
        padding-top: 0;
        border-top: 0;
    }

    .blocks strong {
        display: block;
    }

    .blocks small {
        color: var(--text-3);
        font-size: 12px;
        overflow-wrap: anywhere;
    }

    @media (max-width: 767px) {
        .day {
            min-height: 66px;
            padding: 3px;
        }

        .day-num {
            min-width: 22px;
            height: 22px;
            font-size: 11.5px;
        }

        .chip {
            justify-content: center;
            padding: 3px 2px;
        }

        /* on a phone a day is too narrow for names: the icon stays, the list below has the names */
        .chip .who {
            display: none;
        }

        .cal-bar {
            padding: 12px;
        }

        .cal-nav strong {
            min-width: 120px;
            font-size: 15px;
        }

        .cal-tools,
        .cal-tools .select {
            flex: 1;
        }
    }
</style>
<?php admin_shell_body(); ?>

<?php if ($error !== ""): ?>
    <div class="alert alert-error alert-in" role="alert"><?= icon("alert") ?><p><?= h($error) ?></p></div>
<?php endif; ?>

<div class="grid grid-main">

    <div class="col">

        <!-- THE MONTH -->

        <section class="card" aria-label="<?= $monthStart->format("F Y") ?>">
            <div class="cal-bar">
                <div class="cal-nav">
                    <a class="btn btn-icon" href="<?= h(cal_link($prevMonth, $roomFilter)) ?>" aria-label="Previous month" rel="prev"><?= icon("chevron-left") ?></a>
                    <strong><?= $monthStart->format("F Y") ?></strong>
                    <a class="btn btn-icon" href="<?= h(cal_link($nextMonth, $roomFilter)) ?>" aria-label="Next month" rel="next"><?= icon("chevron-right") ?></a>
                </div>

                <form class="cal-tools" method="get">
                    <?php if ($monthParam !== date("Y-m")): ?>
                        <a class="btn btn-sm" href="<?= h(cal_link(date("Y-m"), $roomFilter)) ?>">Today</a>
                        <input type="hidden" name="month" value="<?= h($monthParam) ?>">
                    <?php endif; ?>
                    <label class="sr-only" for="room">Room</label>
                    <select class="select select-sm" id="room" name="room" onchange="this.form.submit()">
                        <option value="all">All rooms</option>
                        <?php foreach ($rooms as $room): ?>
                            <option value="<?= (int) $room["id"] ?>"<?= $roomFilter === (int) $room["id"] ? " selected" : "" ?>><?= h($room["room_name"]) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button class="btn btn-sm" type="submit">Show</button></noscript>
                </form>
            </div>

            <div class="cal">
                <?php foreach (["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"] as $wd): ?>
                    <div class="wd"><?= $wd ?></div>
                <?php endforeach; ?>

                <?php for ($i = 0; $i < (int) $monthStart->format("w"); $i++): ?>
                    <div class="day out"></div>
                <?php endfor; ?>

                <?php for ($d = $monthStart; $d <= $monthEnd; $d = $d->modify("+1 day")):
                    $key = $d->format("Y-m-d");
                    $info = $days[$key] ?? [];
                    $closed = !empty($info["blocks"]);
                ?>
                    <div class="day<?= $closed ? " closed" : "" ?><?= $key === $today ? " today" : "" ?><?= $key < $today ? " past" : "" ?>">
                        <span class="day-num" <?= $key === $today ? 'aria-label="Today, ' . (int) $d->format("j") . '"' : "" ?>><?= (int) $d->format("j") ?></span>

                        <?php foreach ($info["blocks"] ?? [] as $b): ?>
                            <span class="chip block" title="<?= h("Closed" . ($b["reason"] !== "" ? ": " . $b["reason"] : "")) ?>">
                                <?= icon("lock") ?> <span class="who"><?= $b["reason"] !== "" ? h($b["reason"]) : "Closed" ?></span>
                            </span>
                        <?php endforeach; ?>

                        <?php foreach ($info["stays"] ?? [] as $r): ?>
                            <a class="chip <?= h($r["status"]) ?>"
                               href="<?= admin_can("reservations") ? "reservations.php?q=" . (int) $r["id"] : "#stays" ?>"
                               title="<?= h($r["full_name"] . " · " . ($roomNames[$r["room_id"]] ?? "Room") . " · " . admin_stay($r["check_in"], $r["check_out"]) . " · " . ucfirst($r["status"])) ?>">
                                <?= icon($key === $r["check_in"] ? "log-in" : $chipIcons[$r["status"]]) ?>
                                <span class="who"><?= h(explode(" ", trim($r["full_name"]))[0]) ?><?= $roomFilter === "all" && count($roomNames) > 1 ? " · " . h($roomNames[$r["room_id"]] ?? "") : "" ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endfor; ?>

                <?php for ($i = ((int) $monthStart->format("w") + (int) $monthEnd->format("j")) % 7; $i > 0 && $i < 7; $i++): ?>
                    <div class="day out"></div>
                <?php endfor; ?>
            </div>

            <div class="cal-legend">
                <span><span class="chip confirmed"><?= icon("check-circle") ?></span> Confirmed</span>
                <span><span class="chip pending"><?= icon("clock") ?></span> Pending payment</span>
                <span><span class="chip completed"><?= icon("star") ?></span> Completed</span>
                <span><span class="chip block"><?= icon("lock") ?></span> Closed</span>
                <span><span class="chip confirmed"><?= icon("log-in") ?></span> Check-in day</span>
            </div>
        </section>


        <!-- THE SAME MONTH AS A LIST -->

        <section class="card" id="stays">
            <div class="card-head">
                <div>
                    <h2 class="card-title">Stays in <?= $monthStart->format("F") ?></h2>
                    <p class="card-sub"><?= $roomFilter === "all" ? "All rooms" : h($roomNames[$roomFilter]) ?></p>
                </div>
            </div>

            <?php if ($reservations): ?>
                <div class="table-wrap">
                    <table class="table table-stack">
                        <thead>
                            <tr>
                                <th>Guest</th>
                                <th>Room</th>
                                <th>Stay</th>
                                <th class="right hide-narrow">Guests</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reservations as $r): ?>
                                <tr>
                                    <td class="cell-lead">
                                        <div class="person">
                                            <?= admin_avatar($r, 32) ?>
                                            <div class="person-text">
                                                <?php if (admin_can("reservations")): ?>
                                                    <a class="person-name" href="reservations.php?q=<?= (int) $r["id"] ?>"><?= h($r["full_name"]) ?></a>
                                                <?php else: ?>
                                                    <span class="person-name"><?= h($r["full_name"]) ?></span>
                                                <?php endif; ?>
                                                <span class="person-sub">Reservation #<?= (int) $r["id"] ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td data-label="Room" class="cell-clip"><?= h($roomNames[$r["room_id"]] ?? "Room") ?></td>
                                    <td data-label="Stay" class="nowrap"><?= h(admin_stay($r["check_in"], $r["check_out"])) ?></td>
                                    <td data-label="Guests" class="right num hide-narrow"><?= (int) $r["guests"] ?></td>
                                    <td data-label="Status"><?= status_pill($r["status"]) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty empty-sm">
                    <div class="empty-icon"><?= icon("calendar", 20) ?></div>
                    <p>No stays in <?= $monthStart->format("F Y") ?><?= $roomFilter === "all" ? "" : " for this room" ?>.</p>
                </div>
            <?php endif; ?>
        </section>

    </div>


    <div class="col">

        <!-- CLOSE DATES -->

        <section class="card card-pad">
            <h2 class="card-title">Close dates</h2>
            <p class="card-sub">For repairs, cleaning or your own use</p>

            <form method="post" action="<?= h(cal_link($monthParam, $roomFilter)) ?>" style="margin-top:16px">
                <?= csrf_field() ?>

                <label class="field">
                    <span class="label">Room</span>
                    <select class="select" name="room_id">
                        <option value="all">All rooms (whole property)</option>
                        <?php foreach ($rooms as $room): ?>
                            <option value="<?= (int) $room["id"] ?>"<?= (string) $form["room_id"] === (string) $room["id"] ? " selected" : "" ?>><?= h($room["room_name"]) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="date-pair">
                    <label class="field">
                        <span class="label">From</span>
                        <input class="input" type="date" name="start_date" value="<?= h($form["start_date"]) ?>" required>
                    </label>
                    <label class="field">
                        <span class="label">To (included)</span>
                        <input class="input" type="date" name="end_date" value="<?= h($form["end_date"]) ?>" required>
                    </label>
                </div>

                <label class="field" style="margin-top:16px">
                    <span class="label">Reason <span class="muted" style="font-weight:400">(optional, only admins see it)</span></span>
                    <input class="input" name="reason" maxlength="150" value="<?= h($form["reason"]) ?>" placeholder="e.g. Maintenance, family use">
                </label>

                <div class="form-actions">
                    <button class="btn btn-danger btn-block" type="submit"><?= icon("lock") ?> Close these dates</button>
                </div>

                <p class="hint">Guests won't be able to book the nights from the first to the last date. Existing reservations are kept.</p>
            </form>
        </section>


        <!-- CLOSED DATES STILL TO COME -->

        <section class="card card-pad">
            <h2 class="card-title" style="margin-bottom:14px">Upcoming closed dates</h2>

            <?php if (!$upcomingBlocks): ?>
                <p class="muted" style="font-size:13px">None. Every date is open for booking.</p>
            <?php else: ?>
                <ul class="blocks">
                    <?php foreach ($upcomingBlocks as $b): ?>
                        <li>
                            <div>
                                <strong><?= h(block_label($b, $roomNames)) ?></strong>
                                <?php if ($b["reason"] !== ""): ?><small><?= h($b["reason"]) ?></small><?php endif; ?>
                            </div>
                            <form class="inline-form" method="post" action="<?= h(cal_link($monthParam, $roomFilter)) ?>"
                                data-confirm="<?= h(block_label($b, $roomNames)) ?> will be open for booking again."
                                data-confirm-title="Open these dates again?"
                                data-confirm-ok="Open the dates"
                            >
                                <?= csrf_field() ?>
                                <input type="hidden" name="delete_block" value="<?= (int) $b["id"] ?>">
                                <button class="btn btn-sm" type="submit">Open</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

    </div>

</div>

<?php admin_shell_end(); ?>
