<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/admin-shell.php";
require_once __DIR__ . "/../includes/analytics.php";
require_once __DIR__ . "/../includes/paymongo.php";

$admin = admin_boot($pdo, "analytics");


// ======================================================
// THE PERIOD: one choice for everything on the page
// ======================================================

$range = (string) ($_GET["range"] ?? "30");

if (!isset(ANALYTICS_RANGES[$range])) {
    $range = "30";
}

$settings = ANALYTICS_RANGES[$range];
$bucket = $settings["bucket"];
$window = analytics_range_window($range);
$versus = "vs previous " . $settings["short"];

$now = analytics_totals($pdo, $window);
$before = analytics_totals($pdo, analytics_range_window($range, 1));

$revenue = analytics_series($pdo, "revenue", $window, $bucket);
$reservations = analytics_series($pdo, "reservations", $window, $bucket);
$occupancy = analytics_series($pdo, "occupancy", $window, $bucket);
$customers = analytics_series($pdo, "customers", $window, $bucket);

$byStatus = analytics_by_status($pdo, $window);
$byMethod = analytics_by_method($pdo, $window);
$byRoom = analytics_by_room($pdo, $window);
$weekdays = analytics_weekdays($pdo, $window);
$topCustomers = analytics_top_customers($pdo, $window, 5);

$periodName = ["day" => "Day", "week" => "Week", "month" => "Month"][$bucket];


// ======================================================
// DOWNLOAD AS A SPREADSHEET (CSV)
// ======================================================

if (($_GET["export"] ?? "") === "csv") {
    header("Content-Type: text/csv; charset=utf-8");
    header('Content-Disposition: attachment; filename="arves-house-analytics-' . $window["from_date"] . '-to-'
        . date("Y-m-d", strtotime($window["to_date"] . " -1 day")) . '.csv"');
    header("Cache-Control: no-store");

    $out = fopen("php://output", "w");
    fwrite($out, "\xEF\xBB\xBF");   // so Excel reads the peso sign and accents correctly

    fputcsv($out, ["ARVE'S House analytics", $settings["label"], $window["label"]]);
    fputcsv($out, []);
    fputcsv($out, [$periodName, "Revenue (PHP)", "Reservations", "New customers", "Occupancy (%)"]);

    foreach ($revenue["labels"] as $index => $label) {
        fputcsv($out, [
            $label,
            number_format($revenue["values"][$index], 2, ".", ""),
            $reservations["values"][$index],
            $customers["values"][$index],
            $occupancy["values"][$index],
        ]);
    }

    fputcsv($out, []);
    fputcsv($out, ["Total revenue (PHP)", number_format($now["revenue"], 2, ".", "")]);
    fputcsv($out, ["Verified payments", $now["payments"]]);
    fputcsv($out, ["Reservations", $now["reservations"]]);
    fputcsv($out, ["Cancelled or declined", $now["lost"]]);
    fputcsv($out, ["New customers", $now["customers"]]);
    fputcsv($out, ["Booked nights", $now["booked_nights"]]);
    fputcsv($out, ["Occupancy (%)", number_format($now["occupancy"], 1, ".", "")]);

    fputcsv($out, []);
    fputcsv($out, ["Room", "Revenue (PHP)", "Reservations", "Booked nights", "Occupancy (%)"]);

    foreach ($byRoom as $room) {
        fputcsv($out, [
            $room["room_name"],
            number_format($room["revenue"], 2, ".", ""),
            $room["reservations"],
            $room["nights"],
            number_format($room["occupancy"], 1, ".", ""),
        ]);
    }

    fclose($out);
    exit;
}


// ======================================================
// SMALL PIECES
// ======================================================

// horizontal bars: rows of [label, value, text shown at the tip]
$bars = function (array $rows): string {
    $highest = max(array_merge([0], array_column($rows, 1)));
    $html = '<div class="bars">';

    foreach ($rows as [$label, $value, $text]) {
        $width = $highest > 0 ? max(0, $value / $highest * 100) : 0;

        $html .= '<div class="bar-row">'
            . '<span class="bar-label" title="' . h($label) . '">' . h($label) . '</span>'
            . '<span class="bar-track"><span class="bar-fill" style="width:' . number_format($width, 2, ".", "") . '%"></span></span>'
            . '<span class="bar-value">' . h($text) . '</span>'
            . '</div>';
    }

    return $html . '</div>';
};

// "↑ 4.2 pts" for numbers that are already percentages
$points = function (float $change, bool $upIsGood = true) use ($versus): string {
    if (abs($change) < 0.05) {
        return '<div class="stat-delta">' . icon("arrow-right", 14) . ' No change</div><div class="stat-note">' . h($versus) . '</div>';
    }

    $good = ($change > 0) === $upIsGood;

    return '<div class="stat-delta ' . ($good ? "is-good" : "is-bad") . '">'
        . icon($change > 0 ? "arrow-up-right" : "arrow-down-right", 14)
        . '<span class="sr-only">' . ($change > 0 ? "Up" : "Down") . '</span> '
        . number_format(abs($change), 1) . ' pts</div><div class="stat-note">' . h($versus) . '</div>';
};

$statusRows = [];

foreach ($byStatus as $status => $total) {
    $statusRows[] = [ucfirst($status), $total, number_format($total)];
}

$methodRows = [];

foreach ($byMethod as $method) {
    $methodRows[] = [payment_method_label($method["method"]), (float) $method["amount"], peso($method["amount"])];
}

$hasData = $now["reservations"] > 0 || $now["revenue"] > 0 || $now["booked_nights"] > 0 || $now["customers"] > 0;

admin_shell_head([
    "title" => "Analytics",
    "subtitle" => $settings["label"] . " · " . $window["label"],
    "active" => "analytics",
]);
?>
<style>
    .mini-stats {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
    }

    .mini-stat {
        min-width: 0;
        padding: 14px 16px;
        border: 1px solid var(--line);
        border-radius: var(--radius);
        background: var(--surface);
    }

    .mini-stat-label {
        color: var(--text-3);
        font-size: 12px;
    }

    .mini-stat-value {
        margin-top: 2px;
        font-size: 18px;
        font-weight: 700;
        letter-spacing: -0.01em;
    }

    .mini-stat-note {
        color: var(--text-3);
        font-size: 12px;
    }

    .room-meter {
        display: flex;
        align-items: center;
        gap: 10px;
        min-width: 130px;
    }

    .room-meter .meter {
        flex: 1;
    }

    .room-meter span:last-child {
        min-width: 38px;
        font-variant-numeric: tabular-nums;
        text-align: right;
    }

    @media (max-width: 1280px) {
        .mini-stats {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 767px) {
        .mini-stats {
            gap: 10px;
        }
    }

    @media print {
        .grid-2 {
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        }
    }
</style>
<?php admin_shell_body(); ?>

<!-- ONE ROW OF FILTERS FOR THE WHOLE PAGE -->

<div class="page-bar no-print">
    <nav class="tabs" aria-label="Period">
        <?php foreach (ANALYTICS_RANGES as $key => $option): ?>
            <a
                class="tab<?= (string) $key === $range ? " is-active" : "" ?>"
                href="analytics.php?range=<?= h($key) ?>"
                <?= (string) $key === $range ? 'aria-current="page"' : "" ?>
            ><?= h($option["label"]) ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="page-bar-end">
        <a class="btn btn-sm" href="analytics.php?range=<?= h($range) ?>&amp;export=csv"><?= icon("download") ?> Download CSV</a>
        <button class="btn btn-sm" type="button" onclick="window.print()"><?= icon("printer") ?> Print</button>
    </div>
</div>

<div class="stack">

    <?php if (!$hasData): ?>
        <div class="note">
            Nothing happened in this period yet: no bookings, payments or new customers. Try a longer period.
        </div>
    <?php endif; ?>


    <!-- THE FOUR HEADLINE NUMBERS -->

    <section class="stats" aria-label="<?= h($settings["label"]) ?>">

        <div class="stat tone-green">
            <div class="stat-main">
                <div class="stat-label">Revenue</div>
                <div class="stat-value"><?= h(peso($now["revenue"])) ?></div>
                <?= stat_change_html(stat_change($now["revenue"], $before["revenue"]), $versus) ?>
            </div>
            <div class="stat-icon"><?= icon("wallet") ?></div>
        </div>

        <div class="stat tone-blue">
            <div class="stat-main">
                <div class="stat-label">Reservations</div>
                <div class="stat-value"><?= number_format($now["reservations"]) ?></div>
                <?= stat_change_html(stat_change($now["reservations"], $before["reservations"]), $versus) ?>
            </div>
            <div class="stat-icon"><?= icon("calendar") ?></div>
        </div>

        <div class="stat tone-cyan">
            <div class="stat-main">
                <div class="stat-label">Occupancy</div>
                <div class="stat-value"><?= number_format($now["occupancy"], $now["occupancy"] < 10 ? 1 : 0) ?>%</div>
                <?= $points($now["occupancy"] - $before["occupancy"]) ?>
            </div>
            <div class="stat-icon"><?= icon("trending-up") ?></div>
        </div>

        <div class="stat tone-violet">
            <div class="stat-main">
                <div class="stat-label">New customers</div>
                <div class="stat-value"><?= number_format($now["customers"]) ?></div>
                <?= stat_change_html(stat_change($now["customers"], $before["customers"]), $versus) ?>
            </div>
            <div class="stat-icon"><?= icon("user-plus") ?></div>
        </div>

    </section>

    <section class="mini-stats" aria-label="More numbers">
        <div class="mini-stat">
            <div class="mini-stat-label">Average payment</div>
            <div class="mini-stat-value"><?= h(peso($now["average_payment"])) ?></div>
            <div class="mini-stat-note"><?= number_format($now["payments"]) ?> verified <?= $now["payments"] === 1 ? "payment" : "payments" ?></div>
        </div>
        <div class="mini-stat">
            <div class="mini-stat-label">Average stay</div>
            <div class="mini-stat-value"><?= number_format($now["average_stay"], 1) ?> <?= abs($now["average_stay"] - 1) < 0.05 ? "night" : "nights" ?></div>
            <div class="mini-stat-note">Confirmed and completed stays</div>
        </div>
        <div class="mini-stat">
            <div class="mini-stat-label">Booked nights</div>
            <div class="mini-stat-value"><?= number_format($now["booked_nights"]) ?></div>
            <div class="mini-stat-note">of <?= number_format($now["room_nights"]) ?> room nights</div>
        </div>
        <div class="mini-stat">
            <div class="mini-stat-label">Cancelled or declined</div>
            <div class="mini-stat-value"><?= number_format($now["lost_rate"], 1) ?>%</div>
            <div class="mini-stat-note"><?= number_format($now["lost"]) ?> of <?= number_format($now["reservations"]) ?> reservations</div>
        </div>
    </section>


    <!-- OVER TIME -->

    <div class="grid grid-2">

        <section class="card">
            <div class="card-head">
                <div>
                    <h2 class="card-title">Revenue</h2>
                    <p class="card-sub">Verified payments per <?= strtolower($periodName) ?></p>
                </div>
                <button class="link-btn no-print" type="button" data-chart-table="chart-revenue">Show as a table</button>
            </div>
            <div class="chart-head">
                <div class="chart-figure"><?= h(peso($now["revenue"])) ?></div>
            </div>
            <div class="chart" id="chart-revenue" data-chart>
                <?= chart_json([
                    "type" => "line",
                    "labels" => $revenue["labels"],
                    "ticks" => $revenue["ticks"],
                    "series" => [["name" => "Revenue", "values" => $revenue["values"]]],
                    "format" => "peso",
                    "height" => 240,
                    "axis" => $periodName,
                    "label" => "Revenue per " . strtolower($periodName) . ", " . peso($now["revenue"])
                        . " in total. Use the left and right arrow keys to read each value.",
                ]) ?>
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <div>
                    <h2 class="card-title">Reservations</h2>
                    <p class="card-sub">Bookings made per <?= strtolower($periodName) ?></p>
                </div>
                <button class="link-btn no-print" type="button" data-chart-table="chart-reservations">Show as a table</button>
            </div>
            <div class="chart-head">
                <div class="chart-figure"><?= number_format($now["reservations"]) ?></div>
            </div>
            <div class="chart" id="chart-reservations" data-chart>
                <?= chart_json([
                    "type" => "bars",
                    "labels" => $reservations["labels"],
                    "ticks" => $reservations["ticks"],
                    "series" => [["name" => "Reservations", "values" => $reservations["values"]]],
                    "format" => "number",
                    "height" => 240,
                    "axis" => $periodName,
                    "label" => "Reservations per " . strtolower($periodName) . ", " . number_format($now["reservations"])
                        . " in total. Use the left and right arrow keys to read each value.",
                ]) ?>
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <div>
                    <h2 class="card-title">Occupancy</h2>
                    <p class="card-sub">Share of room nights booked per <?= strtolower($periodName) ?></p>
                </div>
                <button class="link-btn no-print" type="button" data-chart-table="chart-occupancy">Show as a table</button>
            </div>
            <div class="chart-head">
                <div class="chart-figure"><?= number_format($now["occupancy"], $now["occupancy"] < 10 ? 1 : 0) ?>%</div>
            </div>
            <div class="chart" id="chart-occupancy" data-chart>
                <?= chart_json([
                    "type" => "line",
                    "labels" => $occupancy["labels"],
                    "ticks" => $occupancy["ticks"],
                    "series" => [["name" => "Occupancy", "values" => $occupancy["values"]]],
                    "format" => "percent",
                    "height" => 240,
                    "axis" => $periodName,
                    "label" => "Occupancy per " . strtolower($periodName) . ", " . number_format($now["occupancy"], 1)
                        . " percent over the whole period. Use the left and right arrow keys to read each value.",
                ]) ?>
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <div>
                    <h2 class="card-title">New customers</h2>
                    <p class="card-sub">Accounts created per <?= strtolower($periodName) ?></p>
                </div>
                <button class="link-btn no-print" type="button" data-chart-table="chart-customers">Show as a table</button>
            </div>
            <div class="chart-head">
                <div class="chart-figure"><?= number_format($now["customers"]) ?></div>
            </div>
            <div class="chart" id="chart-customers" data-chart>
                <?= chart_json([
                    "type" => "bars",
                    "labels" => $customers["labels"],
                    "ticks" => $customers["ticks"],
                    "series" => [["name" => "New customers", "values" => $customers["values"]]],
                    "format" => "number",
                    "height" => 240,
                    "axis" => $periodName,
                    "label" => "New customers per " . strtolower($periodName) . ", " . number_format($now["customers"])
                        . " in total. Use the left and right arrow keys to read each value.",
                ]) ?>
            </div>
        </section>

    </div>


    <!-- WHAT IT IS MADE OF -->

    <div class="grid grid-3">

        <section class="card">
            <div class="card-head">
                <div>
                    <h2 class="card-title">Reservations by status</h2>
                    <p class="card-sub">Bookings made in this period</p>
                </div>
            </div>
            <?php if ($statusRows): ?>
                <div class="card-body"><?= $bars($statusRows) ?></div>
            <?php else: ?>
                <div class="empty empty-sm"><p>No reservations in this period</p></div>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="card-head">
                <div>
                    <h2 class="card-title">Payment methods</h2>
                    <p class="card-sub">Verified payments, by amount</p>
                </div>
            </div>
            <?php if ($methodRows): ?>
                <div class="card-body"><?= $bars($methodRows) ?></div>
            <?php else: ?>
                <div class="empty empty-sm"><p>No verified payments in this period</p></div>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="card-head">
                <div>
                    <h2 class="card-title">Arrivals by day</h2>
                    <p class="card-sub">Check-ins of confirmed stays</p>
                </div>
                <button class="link-btn no-print" type="button" data-chart-table="chart-weekdays">Show as a table</button>
            </div>
            <div class="chart" id="chart-weekdays" data-chart>
                <?= chart_json([
                    "type" => "bars",
                    "labels" => ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"],
                    "ticks" => array_keys($weekdays),
                    "series" => [["name" => "Arrivals", "values" => array_values($weekdays)]],
                    "format" => "number",
                    "height" => 190,
                    "axis" => "Day of the week",
                    "label" => "Arrivals by day of the week. Use the left and right arrow keys to read each value.",
                ]) ?>
            </div>
        </section>

    </div>


    <!-- ROOMS AND CUSTOMERS -->

    <div class="grid grid-main">

        <section class="card">
            <div class="card-head">
                <div>
                    <h2 class="card-title">Rooms</h2>
                    <p class="card-sub">What each room earned and how full it was</p>
                </div>
                <?php if (admin_can("rooms")): ?>
                    <a class="card-link no-print" href="rooms.php">Manage rooms</a>
                <?php endif; ?>
            </div>

            <?php if ($byRoom): ?>
                <div class="table-wrap">
                    <table class="table table-stack">
                        <thead>
                            <tr>
                                <th>Room</th>
                                <th class="right">Revenue</th>
                                <th class="right">Reservations</th>
                                <th class="right">Nights</th>
                                <th>Occupancy</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($byRoom as $room): ?>
                                <tr>
                                    <td class="cell-lead">
                                        <span class="cell-main"><?= h($room["room_name"]) ?></span>
                                        <?php if ($room["status"] !== "available"): ?>
                                            <?= status_pill($room["status"]) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Revenue" class="right num strong"><?= h(peso($room["revenue"])) ?></td>
                                    <td data-label="Reservations" class="right num"><?= number_format($room["reservations"]) ?></td>
                                    <td data-label="Nights" class="right num"><?= number_format($room["nights"]) ?></td>
                                    <td data-label="Occupancy">
                                        <div class="room-meter">
                                            <span class="meter" role="img" aria-label="<?= number_format($room["occupancy"], 0) ?> percent booked">
                                                <span class="meter-fill" style="width:<?= number_format($room["occupancy"], 2, ".", "") ?>%"></span>
                                            </span>
                                            <span><?= number_format($room["occupancy"], 0) ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty empty-sm"><p>No rooms yet</p></div>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="card-head">
                <div>
                    <h2 class="card-title">Top customers</h2>
                    <p class="card-sub">By verified payments in this period</p>
                </div>
            </div>

            <?php if ($topCustomers): ?>
                <ul class="feed">
                    <?php foreach ($topCustomers as $customer): ?>
                        <li>
                            <div class="feed-item" style="align-items:center">
                                <?= admin_avatar($customer, 34) ?>
                                <div class="feed-body">
                                    <span class="feed-text strong"><?= h($customer["full_name"]) ?></span>
                                    <span class="feed-meta">
                                        <?= (int) $customer["reservations"] ?> <?= (int) $customer["reservations"] === 1 ? "reservation" : "reservations" ?>
                                    </span>
                                </div>
                                <span class="strong num"><?= h(peso($customer["amount"])) ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="empty empty-sm"><p>No verified payments in this period</p></div>
            <?php endif; ?>
        </section>

    </div>

    <p class="muted" style="font-size:12.5px">
        Revenue counts verified payments on the day they were made. A night counts as booked when its
        reservation is confirmed or completed. Rooms under maintenance or switched off are left out of occupancy.
    </p>

</div>

<?php admin_shell_end(); ?>
