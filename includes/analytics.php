<?php
/*
 * Numbers for the dashboard and the Analytics page.
 *
 * Money counts once a payment is verified (payments.status = 'verified'), on the day the
 * payment was made. A night counts as booked when its reservation is confirmed or completed.
 * Dates are Philippine time, like everything else in the database.
 *
 * Periods always end today and are compared with the same number of days just before them:
 * on the 5th of a month, "this month against last month" would compare 5 days with 30.
 *
 *     $now = analytics_window(30);            // the last 30 days, today included
 *     $before = analytics_window(30, 1);      // the 30 days before those
 *     $totals = analytics_totals($pdo, $now);
 */

const ANALYTICS_RANGES = [
    "7" => ["label" => "Last 7 days", "short" => "7 days", "days" => 7, "bucket" => "day"],
    "30" => ["label" => "Last 30 days", "short" => "30 days", "days" => 30, "bucket" => "day"],
    "90" => ["label" => "Last 13 weeks", "short" => "13 weeks", "days" => 91, "bucket" => "week"],
    "365" => ["label" => "Last 12 months", "short" => "12 months", "days" => 365, "bucket" => "month"],
];

/*
 * $days days ending today. $periodsBack = 1 gives the period just before it, 2 the one before
 * that. "from" is included, "to" is not: [from, to).
 */
function analytics_window(int $days, int $periodsBack = 0): array
{
    $days = max(1, $days);
    $end = new DateTimeImmutable("tomorrow");
    $end = $end->modify("-" . ($days * $periodsBack) . " days");
    $start = $end->modify("-" . $days . " days");

    return [
        "days" => $days,
        "from" => $start->format("Y-m-d 00:00:00"),
        "to" => $end->format("Y-m-d 00:00:00"),
        "from_date" => $start->format("Y-m-d"),
        "to_date" => $end->format("Y-m-d"),
        "label" => $start->format("M j") . " – " . $end->modify("-1 day")->format("M j, Y"),
    ];
}

// The last 12 calendar months, this month included; with $periodsBack = 1 the 12 before them.
function analytics_window_months(int $months = 12, int $periodsBack = 0): array
{
    $end = (new DateTimeImmutable("first day of next month"))->setTime(0, 0);
    $end = $end->modify("-" . ($months * $periodsBack) . " months");
    $start = $end->modify("-" . $months . " months");

    return [
        "days" => (int) $start->diff($end)->days,
        "from" => $start->format("Y-m-d 00:00:00"),
        "to" => $end->format("Y-m-d 00:00:00"),
        "from_date" => $start->format("Y-m-d"),
        "to_date" => $end->format("Y-m-d"),
        "label" => $start->format("M Y") . " – " . $end->modify("-1 day")->format("M Y"),
    ];
}

// The window for one of ANALYTICS_RANGES ("7", "30", "90", "365").
function analytics_range_window(string $range, int $periodsBack = 0): array
{
    $settings = ANALYTICS_RANGES[$range] ?? ANALYTICS_RANGES["30"];

    return $settings["bucket"] === "month"
        ? analytics_window_months(12, $periodsBack)
        : analytics_window($settings["days"], $periodsBack);
}

function analytics_scalar(PDO $pdo, string $sql, array $values): float
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);

    return (float) $stmt->fetchColumn();
}

// Rooms guests can book (not under maintenance, not switched off).
function analytics_room_count(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM rooms WHERE status = 'available'")->fetchColumn();
}

// Nights booked inside the window, by confirmed and completed reservations.
function analytics_booked_nights(PDO $pdo, array $window): int
{
    return (int) analytics_scalar(
        $pdo,
        "SELECT COALESCE(SUM(DATEDIFF(LEAST(check_out, ?), GREATEST(check_in, ?))), 0)
         FROM reservations
         WHERE status IN ('confirmed', 'completed') AND check_in < ? AND check_out > ?",
        [$window["to_date"], $window["from_date"], $window["to_date"], $window["from_date"]]
    );
}

function analytics_totals(PDO $pdo, array $window): array
{
    $range = [$window["from"], $window["to"]];

    $revenue = analytics_scalar(
        $pdo,
        "SELECT COALESCE(SUM(amount), 0) FROM payments
         WHERE status = 'verified' AND created_at >= ? AND created_at < ?",
        $range
    );

    $payments = (int) analytics_scalar(
        $pdo,
        "SELECT COUNT(*) FROM payments WHERE status = 'verified' AND created_at >= ? AND created_at < ?",
        $range
    );

    $reservations = (int) analytics_scalar(
        $pdo,
        "SELECT COUNT(*) FROM reservations WHERE created_at >= ? AND created_at < ?",
        $range
    );

    $lost = (int) analytics_scalar(
        $pdo,
        "SELECT COUNT(*) FROM reservations
         WHERE status IN ('cancelled', 'declined') AND created_at >= ? AND created_at < ?",
        $range
    );

    $customers = (int) analytics_scalar(
        $pdo,
        "SELECT COUNT(*) FROM users WHERE role = 'customer' AND created_at >= ? AND created_at < ?",
        $range
    );

    $stay = analytics_scalar(
        $pdo,
        "SELECT COALESCE(AVG(total_nights), 0) FROM reservations
         WHERE status IN ('confirmed', 'completed') AND created_at >= ? AND created_at < ?",
        $range
    );

    $bookedNights = analytics_booked_nights($pdo, $window);
    $roomNights = analytics_room_count($pdo) * $window["days"];

    return [
        "revenue" => $revenue,
        "payments" => $payments,
        "reservations" => $reservations,
        "lost" => $lost,
        "lost_rate" => $reservations > 0 ? $lost / $reservations * 100 : 0.0,
        "customers" => $customers,
        "average_stay" => $stay,
        "average_payment" => $payments > 0 ? $revenue / $payments : 0.0,
        "booked_nights" => $bookedNights,
        "room_nights" => $roomNights,
        "occupancy" => $roomNights > 0 ? min(100.0, $bookedNights / $roomNights * 100) : 0.0,
    ];
}

// date => value for every day that has one ("2026-09-29" => 2000.0)
function analytics_daily(PDO $pdo, string $metric, array $window): array
{
    $range = [$window["from"], $window["to"]];

    if ($metric === "occupancy") {
        $rooms = analytics_room_count($pdo);
        $stmt = $pdo->prepare(
            "SELECT check_in, check_out FROM reservations
             WHERE status IN ('confirmed', 'completed') AND check_in < ? AND check_out > ?"
        );
        $stmt->execute([$window["to_date"], $window["from_date"]]);

        $nights = [];

        foreach ($stmt as $row) {
            $night = new DateTimeImmutable(max($row["check_in"], $window["from_date"]));
            $last = new DateTimeImmutable(min($row["check_out"], $window["to_date"]));

            for (; $night < $last; $night = $night->modify("+1 day")) {
                $key = $night->format("Y-m-d");
                $nights[$key] = ($nights[$key] ?? 0) + 1;
            }
        }

        // kept as booked nights; analytics_series() turns them into a percentage per bucket
        return ["values" => $nights, "rooms" => $rooms];
    }

    $queries = [
        "revenue" => "SELECT DATE(created_at) AS day, SUM(amount) AS value FROM payments
                      WHERE status = 'verified' AND created_at >= ? AND created_at < ? GROUP BY DATE(created_at)",
        "reservations" => "SELECT DATE(created_at) AS day, COUNT(*) AS value FROM reservations
                           WHERE created_at >= ? AND created_at < ? GROUP BY DATE(created_at)",
        "customers" => "SELECT DATE(created_at) AS day, COUNT(*) AS value FROM users
                        WHERE role = 'customer' AND created_at >= ? AND created_at < ? GROUP BY DATE(created_at)",
    ];

    $stmt = $pdo->prepare($queries[$metric] ?? $queries["revenue"]);
    $stmt->execute($range);

    $values = [];

    foreach ($stmt as $row) {
        $values[$row["day"]] = (float) $row["value"];
    }

    return ["values" => $values, "rooms" => 0];
}

/*
 * One line of a chart: what to write under it, what the pointer shows, and the numbers.
 * $metric: revenue | reservations | customers | occupancy.  $bucket: day | week | month.
 * Returns ["labels" => [...], "ticks" => [...], "values" => [...]].
 */
function analytics_series(PDO $pdo, string $metric, array $window, string $bucket = "day"): array
{
    $daily = analytics_daily($pdo, $metric, $window);
    $start = new DateTimeImmutable($window["from_date"]);
    $end = new DateTimeImmutable($window["to_date"]);

    $labels = [];
    $ticks = [];
    $values = [];

    for ($from = $start; $from < $end; $from = $to) {
        if ($bucket === "month") {
            $to = $from->modify("first day of next month");
        } elseif ($bucket === "week") {
            $to = $from->modify("+7 days");
        } else {
            $to = $from->modify("+1 day");
        }

        $to = min($to, $end);
        $sum = 0.0;
        $days = 0;

        for ($day = $from; $day < $to; $day = $day->modify("+1 day")) {
            $sum += $daily["values"][$day->format("Y-m-d")] ?? 0;
            $days++;
        }

        if ($metric === "occupancy") {
            $capacity = $daily["rooms"] * $days;
            $sum = $capacity > 0 ? round(min(100, $sum / $capacity * 100), 1) : 0.0;
        }

        $lastDay = $to->modify("-1 day");

        if ($bucket === "month") {
            $labels[] = $from->format("F Y");
            $ticks[] = $from->format("M");
        } elseif ($bucket === "week") {
            $labels[] = $from->format("M j") . " – " . $lastDay->format($from->format("m") === $lastDay->format("m") ? "j" : "M j");
            $ticks[] = $from->format("M j");
        } else {
            $labels[] = $from->format("D, M j");
            $ticks[] = $from->format("M j");
        }

        $values[] = round($sum, 2);
    }

    return ["labels" => $labels, "ticks" => $ticks, "values" => $values];
}

/*
 * Shares for the small rings on the dashboard, each from 0 to 100:
 *   paid       of the value of the bookings made in the window (not cancelled or declined),
 *              how much has been paid with a verified payment
 *   confirmed  of the bookings made in the window, how many are confirmed or completed
 *   booked     of all customers, how many have booked at least once (not tied to the window)
 */
function analytics_shares(PDO $pdo, array $window): array
{
    $range = [$window["from"], $window["to"]];

    $value = analytics_scalar(
        $pdo,
        "SELECT COALESCE(SUM(total_amount), 0) FROM reservations
         WHERE status NOT IN ('cancelled', 'declined') AND created_at >= ? AND created_at < ?",
        $range
    );

    $paid = analytics_scalar(
        $pdo,
        "SELECT COALESCE(SUM(payments.amount), 0)
         FROM payments
         INNER JOIN reservations ON reservations.id = payments.reservation_id
         WHERE payments.status = 'verified'
         AND reservations.status NOT IN ('cancelled', 'declined')
         AND reservations.created_at >= ? AND reservations.created_at < ?",
        $range
    );

    $made = analytics_scalar($pdo, "SELECT COUNT(*) FROM reservations WHERE created_at >= ? AND created_at < ?", $range);

    $kept = analytics_scalar(
        $pdo,
        "SELECT COUNT(*) FROM reservations
         WHERE status IN ('confirmed', 'completed') AND created_at >= ? AND created_at < ?",
        $range
    );

    $customers = analytics_scalar($pdo, "SELECT COUNT(*) FROM users WHERE role = 'customer'", []);

    $booked = analytics_scalar(
        $pdo,
        "SELECT COUNT(DISTINCT reservations.user_id)
         FROM reservations
         INNER JOIN users ON users.id = reservations.user_id
         WHERE users.role = 'customer'",
        []
    );

    $share = fn (float $part, float $whole) => $whole > 0 ? max(0.0, min(100.0, $part / $whole * 100)) : 0.0;

    return [
        "paid" => $share($paid, $value),
        "confirmed" => $share($kept, $made),
        "booked" => $share($booked, $customers),
    ];
}

/*
 * A small ring that fills up to a share (0 to 100), with the number in the middle.
 * $about says what the share is ("of bookings are paid"): read out by screen readers and
 * shown as the hint on hover. $caption is the one word printed under the ring ("paid").
 */
function ring_html(float $share, string $about, string $caption = ""): string
{
    $share = max(0.0, min(100.0, $share));
    $around = 2 * M_PI * 18;
    $shown = (int) round($share);

    return '<span class="ring" role="img" aria-label="' . htmlspecialchars($shown . " percent " . $about, ENT_QUOTES, "UTF-8")
        . '" title="' . htmlspecialchars($shown . "% " . $about, ENT_QUOTES, "UTF-8") . '">'
        . '<svg viewBox="0 0 44 44" width="44" height="44" aria-hidden="true" focusable="false">'
        . '<circle class="ring-track" cx="22" cy="22" r="18"/>'
        . '<circle class="ring-fill" cx="22" cy="22" r="18" stroke-dasharray="'
        . number_format($around * $share / 100, 2, ".", "") . ' ' . number_format($around, 2, ".", "") . '"/>'
        . '</svg><span class="ring-number">' . $shown . '%</span>'
        . ($caption !== "" ? '<span class="ring-caption" aria-hidden="true">' . htmlspecialchars($caption, ENT_QUOTES, "UTF-8") . '</span>' : "")
        . '</span>';
}

// Reservations made in the window, by status: "confirmed" => 4
function analytics_by_status(PDO $pdo, array $window): array
{
    $stmt = $pdo->prepare(
        "SELECT status, COUNT(*) AS total FROM reservations
         WHERE created_at >= ? AND created_at < ?
         GROUP BY status ORDER BY total DESC"
    );
    $stmt->execute([$window["from"], $window["to"]]);

    $result = [];

    foreach ($stmt as $row) {
        $result[$row["status"]] = (int) $row["total"];
    }

    return $result;
}

// Verified payments in the window, by method: rows of method, payments, amount (largest first)
function analytics_by_method(PDO $pdo, array $window): array
{
    $stmt = $pdo->prepare(
        "SELECT payment_method AS method, COUNT(*) AS payments, SUM(amount) AS amount
         FROM payments
         WHERE status = 'verified' AND created_at >= ? AND created_at < ?
         GROUP BY payment_method ORDER BY amount DESC"
    );
    $stmt->execute([$window["from"], $window["to"]]);

    return $stmt->fetchAll();
}

// Every room with what it earned and how many nights it was booked in the window.
function analytics_by_room(PDO $pdo, array $window): array
{
    $stmt = $pdo->prepare(
        "SELECT
            rooms.id,
            rooms.room_name,
            rooms.status,
            (
                SELECT COALESCE(SUM(payments.amount), 0)
                FROM payments
                INNER JOIN reservations ON reservations.id = payments.reservation_id
                WHERE reservations.room_id = rooms.id
                AND payments.status = 'verified'
                AND payments.created_at >= ? AND payments.created_at < ?
            ) AS revenue,
            (
                SELECT COUNT(*)
                FROM reservations
                WHERE reservations.room_id = rooms.id
                AND reservations.created_at >= ? AND reservations.created_at < ?
            ) AS reservations,
            (
                SELECT COALESCE(SUM(DATEDIFF(LEAST(reservations.check_out, ?), GREATEST(reservations.check_in, ?))), 0)
                FROM reservations
                WHERE reservations.room_id = rooms.id
                AND reservations.status IN ('confirmed', 'completed')
                AND reservations.check_in < ? AND reservations.check_out > ?
            ) AS nights
         FROM rooms
         ORDER BY revenue DESC, nights DESC, rooms.room_name"
    );

    $stmt->execute([
        $window["from"], $window["to"],
        $window["from"], $window["to"],
        $window["to_date"], $window["from_date"], $window["to_date"], $window["from_date"],
    ]);

    $rooms = $stmt->fetchAll();

    foreach ($rooms as &$room) {
        $room["revenue"] = (float) $room["revenue"];
        $room["reservations"] = (int) $room["reservations"];
        $room["nights"] = (int) $room["nights"];
        $room["occupancy"] = $window["days"] > 0 ? min(100, $room["nights"] / $window["days"] * 100) : 0;
    }

    return $rooms;
}

// Arrivals by day of the week, Monday first: "Mon" => 3
function analytics_weekdays(PDO $pdo, array $window): array
{
    $stmt = $pdo->prepare(
        "SELECT WEEKDAY(check_in) AS weekday, COUNT(*) AS total
         FROM reservations
         WHERE status IN ('confirmed', 'completed') AND check_in >= ? AND check_in < ?
         GROUP BY WEEKDAY(check_in)"
    );
    $stmt->execute([$window["from_date"], $window["to_date"]]);

    $result = array_fill_keys(["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"], 0);
    $names = array_keys($result);

    foreach ($stmt as $row) {
        $result[$names[(int) $row["weekday"]]] = (int) $row["total"];
    }

    return $result;
}

// Customers who paid the most in the window.
function analytics_top_customers(PDO $pdo, array $window, int $limit = 5): array
{
    $stmt = $pdo->prepare(
        "SELECT users.id, users.full_name, users.email, users.profile_image,
                COUNT(DISTINCT payments.reservation_id) AS reservations,
                SUM(payments.amount) AS amount
         FROM payments
         INNER JOIN users ON users.id = payments.user_id
         WHERE payments.status = 'verified' AND payments.created_at >= ? AND payments.created_at < ?
         GROUP BY users.id, users.full_name, users.email, users.profile_image
         ORDER BY amount DESC
         LIMIT " . max(1, min(50, $limit))
    );
    $stmt->execute([$window["from"], $window["to"]]);

    return $stmt->fetchAll();
}

/*
 * The chart's settings as JSON for assets/admin.js:
 *     <div class="chart" id="revenue-chart" data-chart><?= chart_json([...]) ?></div>
 * Keys: type ("line" | "bars"), labels, ticks, series [["name" => ..., "values" => [...]]],
 * format ("peso" | "number" | "percent"), height, label (read out by screen readers), axis.
 */
function chart_json(array $config): string
{
    return '<script type="application/json">'
        . json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)
        . '</script>';
}
