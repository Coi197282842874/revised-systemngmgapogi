<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/availability.php";
require_once __DIR__ . "/../includes/icons.php";

// ======================================================
// BOOKING CALENDAR + CLOSED DATES
// ======================================================

if (!isset($_SESSION["user_id"]) || ($_SESSION["role"] ?? "") !== "admin") {
    header("Location: ../login.php");
    exit;
}

ensure_availability_schema($pdo);

$rooms = $pdo->query("SELECT id, room_name FROM rooms ORDER BY room_name")->fetchAll();
$roomNames = array_column($rooms, "room_name", "id");

$roomFilter = $_GET["room"] ?? "all";
$roomFilter = ($roomFilter !== "all" && isset($roomNames[(int) $roomFilter])) ? (int) $roomFilter : "all";

$monthParam = preg_match('/^\d{4}-\d{2}$/', $_GET["month"] ?? "") ? $_GET["month"] : date("Y-m");
$monthStart = new DateTimeImmutable($monthParam . "-01");
$monthEnd = $monthStart->modify("last day of this month");

$message = "";
$error = "";
$form = ["room_id" => "all", "start_date" => "", "end_date" => "", "reason" => ""];

// ------------------------------------------------------
// ADD / REMOVE A CLOSED PERIOD
// ------------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_valid($_POST["csrf_token"] ?? null)) {
        $error = "Your session expired. Please try again.";
    } elseif (isset($_POST["delete_block"])) {
        $pdo->prepare("DELETE FROM room_blocks WHERE id = ?")->execute([(int) $_POST["delete_block"]]);
        header("Location: calendar.php?" . http_build_query(["month" => $monthParam, "room" => $roomFilter, "removed" => 1]));
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
            $pdo->prepare(
                "INSERT INTO room_blocks (room_id, start_date, end_date, reason, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([$roomId, $form["start_date"], $form["end_date"], mb_substr($form["reason"], 0, 150), (int) $_SESSION["user_id"], utc_now()]);

            // bookings already inside the closed dates are kept; tell the admin about them
            $clash = $pdo->prepare(
                "SELECT COUNT(*) FROM reservations
                 WHERE status IN " . AVAILABILITY_HOLDING_STATUSES . "
                 AND check_in <= ? AND check_out > ?" . ($roomId !== null ? " AND room_id = ?" : "")
            );
            $clash->execute(array_merge([$form["end_date"], $form["start_date"]], $roomId !== null ? [$roomId] : []));
            $clashes = (int) $clash->fetchColumn();

            header("Location: calendar.php?" . http_build_query([
                "month" => $start->format("Y-m"),
                "room" => $roomId ?? "all",
                "added" => 1,
                "clashes" => $clashes,
            ]));
            exit;
        }
    }
}

if (isset($_GET["added"])) {
    $message = "Dates closed. Guests can no longer book them.";
    if ((int) ($_GET["clashes"] ?? 0) > 0) {
        $error = (int) $_GET["clashes"] . " existing reservation(s) fall inside those dates and were kept. Contact the guest(s) if needed.";
    }
}

if (isset($_GET["removed"])) {
    $message = "Closed dates removed. Those dates can be booked again.";
}

// ------------------------------------------------------
// WHAT HAPPENS EACH DAY OF THE MONTH
// ------------------------------------------------------

$from = $monthStart->format("Y-m-d");
$to = $monthEnd->format("Y-m-d");

$sql = "SELECT r.id, r.room_id, r.check_in, r.check_out, r.status, r.guests, u.full_name
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

function cal_link(string $month, $room): string
{
    return "calendar.php?" . http_build_query(["month" => $month, "room" => $room]);
}

function block_label(array $block, array $roomNames): string
{
    $range = $block["start_date"] === $block["end_date"]
        ? date("M j, Y", strtotime($block["start_date"]))
        : date("M j", strtotime($block["start_date"])) . " – " . date("M j, Y", strtotime($block["end_date"]));

    return $range . " · " . ($block["room_id"] === null ? "All rooms" : ($roomNames[$block["room_id"]] ?? "Room"));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#f3f4f6">
    <title>Calendar | ARVE'S House</title>
    <style>
        :root { --ease-out: cubic-bezier(0.23, 1, 0.32, 1); --ink: #111827; --muted: #6b7280; --line: rgba(17, 24, 39, 0.08); --accent: #2563eb; }
        * { box-sizing: border-box; }
        html { -webkit-tap-highlight-color: transparent; -webkit-text-size-adjust: 100%; text-size-adjust: 100%; }
        body { margin: 0; padding: 24px 20px; padding: max(24px, env(safe-area-inset-top, 0px)) max(20px, env(safe-area-inset-right, 0px)) max(24px, env(safe-area-inset-bottom, 0px)) max(20px, env(safe-area-inset-left, 0px)); font-family: Arial, sans-serif; background: #f3f4f6; color: var(--ink); }
        a { touch-action: manipulation; }
        .page { max-width: 1180px; margin: 0 auto; }
        .topbar { display: flex; justify-content: space-between; align-items: center; gap: 15px; margin-bottom: 18px; }
        .topbar h1 { margin: 0; font-size: 26px; }
        .topbar p { margin: 4px 0 0; color: var(--muted); font-size: 14px; }
        .topbar a { color: var(--accent); text-decoration: none; font-size: 14px; white-space: nowrap; }

        .layout { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 18px; align-items: start; }
        .panel { border-radius: 16px; background: #fff; box-shadow: 0 4px 15px rgba(0,0,0,.08); }
        .panel-pad { padding: 18px; }
        .panel h2 { margin: 0 0 12px; font-size: 17px; }

        .cal-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; padding: 14px 16px; border-bottom: 1px solid var(--line); }
        .cal-nav { display: flex; align-items: center; gap: 8px; }
        .cal-nav a { display: grid; place-items: center; width: 36px; height: 36px; border: 1px solid #e5e7eb; border-radius: 999px; background: #fff; color: var(--ink); font-size: 20px; text-decoration: none; }
        .cal-nav strong { min-width: 150px; text-align: center; font-size: 16px; }
        .cal-bar select { padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 8px; font: inherit; font-size: 14px; background: #fff; }

        .cal { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); }
        .wd { padding: 8px 6px; border-bottom: 1px solid var(--line); font-size: 11px; font-weight: 700; color: var(--muted); text-align: center; text-transform: uppercase; }
        .day { min-height: 108px; padding: 6px; border-right: 1px solid var(--line); border-bottom: 1px solid var(--line); }
        .day:nth-child(7n) { border-right: 0; }
        .day.out { background: rgba(17, 24, 39, 0.025); }
        .day.closed { background: repeating-linear-gradient(135deg, rgba(220, 38, 38, 0.05) 0 6px, rgba(220, 38, 38, 0.09) 6px 12px); }
        .num { display: inline-grid; place-items: center; min-width: 24px; height: 24px; padding: 0 5px; border-radius: 999px; font-size: 12px; font-weight: 700; color: var(--muted); }
        .day.today .num { background: var(--accent); color: #fff; }
        .chip { display: block; margin-top: 4px; padding: 3px 6px; overflow: hidden; border-radius: 6px; font-size: 11px; line-height: 1.3; text-overflow: ellipsis; white-space: nowrap; text-decoration: none; }
        .chip.confirmed { background: #dcfce7; color: #166534; }
        .chip.pending { background: #fef3c7; color: #92400e; }
        .chip.completed { background: #e0e7ff; color: #3730a3; }
        .chip.block { background: #fee2e2; color: #991b1b; }

        .legend { display: flex; flex-wrap: wrap; gap: 12px; padding: 12px 16px; font-size: 12px; color: var(--muted); }
        .legend span { display: inline-flex; align-items: center; gap: 6px; }
        .legend i { width: 12px; height: 12px; border-radius: 4px; }

        label { display: block; margin: 12px 0 6px; font-size: 13px; font-weight: 700; }
        input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font: inherit; font-size: 14px; background: #fff; }
        .row2 { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 10px; }
        .row2 input { min-width: 0; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; width: 100%; margin-top: 16px; padding: 12px; border: 0; border-radius: 10px; background: #dc2626; color: #fff; font: inherit; font-weight: 700; cursor: pointer; transition: transform 140ms var(--ease-out); }
        .btn:active { transform: scale(.97); }
        .hint { margin: 8px 0 0; font-size: 12px; color: var(--muted); line-height: 1.45; }
        .notice { margin-bottom: 14px; padding: 11px 14px; border-radius: 10px; font-size: 14px; }
        .notice.ok { background: #dcfce7; color: #166534; }
        .notice.warn { background: #fef3c7; color: #92400e; }

        .blocks { list-style: none; margin: 0; padding: 0; }
        .blocks li { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; padding: 10px 0; border-top: 1px solid var(--line); font-size: 13px; }
        .blocks li:first-child { border-top: 0; }
        .blocks strong { display: block; }
        .blocks small { color: var(--muted); }
        .remove { flex: none; padding: 6px 10px; border: 1px solid #fecaca; border-radius: 8px; background: #fff; color: #b91c1c; font: inherit; font-size: 12px; font-weight: 700; cursor: pointer; }
        .none { color: var(--muted); font-size: 13px; }

        @media (pointer: coarse) { input, select, textarea { font-size: 16px; } }
        @media (max-width: 900px) { .layout { grid-template-columns: 1fr; } }
        @media (max-width: 640px) {
            body { padding-left: 10px; padding-right: 10px; }
            .topbar { flex-direction: column; align-items: flex-start; gap: 8px; }
            .topbar h1 { font-size: 22px; }
            .day { min-height: 64px; padding: 3px; }
            .chip { padding: 2px 3px; font-size: 9.5px; }
            .chip .who { display: none; }
            .cal-nav strong { min-width: 120px; font-size: 15px; }
        }
    </style>
<?php $glassTheme = "admin"; require __DIR__ . "/../includes/glass.php"; ?>
</head>
<body>
<main class="page">

    <div class="topbar">
        <div>
            <h1>Calendar</h1>
            <p>Bookings per day, and dates you close for maintenance or personal use.</p>
        </div>
        <a href="dashboard.php">Back to Dashboard</a>
    </div>

    <?php if ($message !== ""): ?><div class="notice ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error !== ""): ?><div class="notice warn" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="layout">

        <section class="panel" aria-label="Month">
            <div class="cal-bar">
                <div class="cal-nav">
                    <a href="<?= htmlspecialchars(cal_link($prevMonth, $roomFilter)) ?>" aria-label="Previous month">‹</a>
                    <strong><?= $monthStart->format("F Y") ?></strong>
                    <a href="<?= htmlspecialchars(cal_link($nextMonth, $roomFilter)) ?>" aria-label="Next month">›</a>
                </div>
                <form method="get">
                    <input type="hidden" name="month" value="<?= htmlspecialchars($monthParam) ?>">
                    <label for="room" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)">Room</label>
                    <select id="room" name="room" onchange="this.form.submit()" style="width:auto">
                        <option value="all">All rooms</option>
                        <?php foreach ($rooms as $room): ?>
                            <option value="<?= (int) $room["id"] ?>"<?= $roomFilter === (int) $room["id"] ? " selected" : "" ?>><?= htmlspecialchars($room["room_name"]) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button type="submit">Show</button></noscript>
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
                    <div class="day<?= $closed ? " closed" : "" ?><?= $key === $today ? " today" : "" ?>">
                        <span class="num"><?= (int) $d->format("j") ?></span>

                        <?php foreach ($info["blocks"] ?? [] as $b): ?>
                            <span class="chip block" title="<?= htmlspecialchars("Closed" . ($b["reason"] !== "" ? ": " . $b["reason"] : "")) ?>">
                                <?= icon("lock") ?> <span class="who"><?= $b["reason"] !== "" ? htmlspecialchars($b["reason"]) : "Closed" ?></span>
                            </span>
                        <?php endforeach; ?>

                        <?php foreach ($info["stays"] ?? [] as $r): ?>
                            <a class="chip <?= htmlspecialchars($r["status"]) ?>"
                               href="reservations.php"
                               title="<?= htmlspecialchars($r["full_name"] . " · " . ($roomNames[$r["room_id"]] ?? "Room") . " · " . $r["check_in"] . " → " . $r["check_out"] . " · " . ucfirst($r["status"])) ?>">
                                <?= $key === $r["check_in"] ? icon("log-in") : "" ?>
                                <span class="who"><?= htmlspecialchars(explode(" ", trim($r["full_name"]))[0]) ?><?= $roomFilter === "all" && count($roomNames) > 1 ? " · " . htmlspecialchars($roomNames[$r["room_id"]] ?? "") : "" ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endfor; ?>

                <?php for ($i = ((int) $monthStart->format("w") + (int) $monthEnd->format("j")) % 7; $i > 0 && $i < 7; $i++): ?>
                    <div class="day out"></div>
                <?php endfor; ?>
            </div>

            <div class="legend">
                <span><i style="background:#dcfce7"></i>Confirmed</span>
                <span><i style="background:#fef3c7"></i>Pending payment</span>
                <span><i style="background:#e0e7ff"></i>Completed</span>
                <span><i style="background:#fee2e2"></i>Closed</span>
                <span><?= icon("log-in") ?> Check-in day</span>
            </div>
        </section>

        <aside>
            <section class="panel panel-pad">
                <h2>Close dates</h2>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

                    <label for="room_id">Room</label>
                    <select id="room_id" name="room_id">
                        <option value="all">All rooms (whole property)</option>
                        <?php foreach ($rooms as $room): ?>
                            <option value="<?= (int) $room["id"] ?>"<?= (string) $form["room_id"] === (string) $room["id"] ? " selected" : "" ?>><?= htmlspecialchars($room["room_name"]) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <div class="row2">
                        <div>
                            <label for="start_date">From</label>
                            <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($form["start_date"]) ?>" required>
                        </div>
                        <div>
                            <label for="end_date">To (included)</label>
                            <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($form["end_date"]) ?>" required>
                        </div>
                    </div>

                    <label for="reason">Reason <span style="font-weight:400;color:var(--muted)">(optional, only admins see it)</span></label>
                    <input id="reason" name="reason" maxlength="150" value="<?= htmlspecialchars($form["reason"]) ?>" placeholder="e.g. Maintenance, family use">

                    <button type="submit" class="btn"><?= icon("lock") ?> Close These Dates</button>
                    <p class="hint">Guests won't be able to book the nights from the first to the last date. Existing reservations are kept.</p>
                </form>
            </section>

            <section class="panel panel-pad" style="margin-top:18px">
                <h2>Upcoming closed dates</h2>

                <?php if (!$upcomingBlocks): ?>
                    <p class="none">None. Every date is open for booking.</p>
                <?php else: ?>
                    <ul class="blocks">
                        <?php foreach ($upcomingBlocks as $b): ?>
                            <li>
                                <div>
                                    <strong><?= htmlspecialchars(block_label($b, $roomNames)) ?></strong>
                                    <?php if ($b["reason"] !== ""): ?><small><?= htmlspecialchars($b["reason"]) ?></small><?php endif; ?>
                                </div>
                                <form method="post" onsubmit="return confirm('Open these dates for booking again?');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                    <input type="hidden" name="delete_block" value="<?= (int) $b["id"] ?>">
                                    <button type="submit" class="remove">Remove</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </aside>

    </div>
</main>
</body>
</html>
