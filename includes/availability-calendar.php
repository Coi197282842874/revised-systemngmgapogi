<?php
/*
 * Availability calendar for one room, linked to the form's check-in / check-out date inputs.
 *
 * Include inside the booking form, after the date inputs:
 *     <?php $calendarRoomId = $roomId; require __DIR__ . "/includes/availability-calendar.php"; ?>
 * Expects inputs #check_in and #check_out. Clicking a free night sets check-in, the next click
 * sets check-out; both inputs then fire "input" + "change" so the page's own price total updates.
 * Typing dates by hand is checked too. The server still re-checks everything on submit.
 */

require_once __DIR__ . "/availability.php";

$calendarToday = new DateTimeImmutable("today");
$calendarLast = $calendarToday->modify("+12 months");
$calendarNights = room_unavailable_nights(
    $pdo,
    (int) $calendarRoomId,
    $calendarToday->format("Y-m-d"),
    $calendarLast->format("Y-m-d")
);
?>

<div class="form-group full">
    <div class="ac" id="availability-calendar" aria-label="Room availability">

        <div class="ac-head">
            <button type="button" class="ac-nav" data-ac-prev aria-label="Previous month">‹</button>
            <strong class="ac-title">Availability</strong>
            <button type="button" class="ac-nav" data-ac-next aria-label="Next month">›</button>
        </div>

        <div class="ac-months" data-ac-months></div>

        <div class="ac-legend">
            <span><i class="ac-dot free"></i>Available</span>
            <span><i class="ac-dot taken"></i>Not available</span>
            <span><i class="ac-dot picked"></i>Your stay</span>
        </div>

        <p class="ac-hint" data-ac-hint role="status">Tap your check-in date, then your check-out date.</p>

    </div>
</div>

<style>
    .ac {
        --ac-brown: #7a4f36;
        --ac-brown-dark: #513421;
        --ac-ink: #241a15;
        --ac-muted: #786d66;
        --ac-line: rgba(122, 79, 54, 0.14);
        padding: 14px;
        border: 1px solid rgba(255, 255, 255, 0.8);
        border-radius: 16px;
        background: rgba(255, 255, 255, 0.55);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.9);
    }

    .ac * { box-sizing: border-box; }

    .ac-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
    .ac-title { font-size: 13px; letter-spacing: 0.4px; color: var(--ac-brown-dark); text-transform: uppercase; }

    .ac-nav {
        display: grid;
        place-items: center;
        width: 36px;
        height: 36px;
        padding: 0;
        border: 1px solid var(--ac-line);
        border-radius: 999px;
        background: #fff;
        color: var(--ac-brown-dark);
        font-size: 22px;
        line-height: 1;
        cursor: pointer;
        touch-action: manipulation;
    }

    .ac-nav:disabled { opacity: 0.35; cursor: default; }
    .ac-nav:focus-visible { outline: 2px solid var(--ac-brown); outline-offset: 2px; }

    .ac-months { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }

    .ac-month h4 { margin: 4px 0 8px; font-size: 14px; text-align: center; color: var(--ac-ink); }

    .ac-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 3px; }

    .ac-wd { padding: 4px 0; font-size: 10.5px; font-weight: 700; text-align: center; color: var(--ac-muted); }

    .ac-day {
        position: relative;
        aspect-ratio: 1;
        min-height: 34px;
        padding: 0;
        border: 0;
        border-radius: 9px;
        background: transparent;
        color: var(--ac-ink);
        font: inherit;
        font-size: 13px;
        font-variant-numeric: tabular-nums;
        cursor: pointer;
        touch-action: manipulation;
        transition: background-color 120ms ease;
    }

    .ac-day.free { background: rgba(255, 255, 255, 0.85); box-shadow: inset 0 0 0 1px var(--ac-line); }
    .ac-day.taken { background: repeating-linear-gradient(135deg, #efe7df 0 4px, #e6dbd0 4px 8px); color: #a89a90; text-decoration: line-through; cursor: not-allowed; }
    .ac-day.past { color: #c9beb5; cursor: default; }
    .ac-day.checkout-only { background: rgba(255, 255, 255, 0.85); box-shadow: inset 0 0 0 1px var(--ac-line); color: var(--ac-ink); text-decoration: none; }
    .ac-day.in-range { background: rgba(122, 79, 54, 0.16); box-shadow: none; }
    .ac-day.start, .ac-day.end { background: linear-gradient(145deg, #8a5e42, #5b3a26); color: #fff; box-shadow: none; text-decoration: none; }
    .ac-day.today::after { content: ""; position: absolute; left: 50%; bottom: 4px; width: 4px; height: 4px; border-radius: 50%; background: currentColor; transform: translateX(-50%); opacity: 0.6; }
    .ac-day:focus-visible { outline: 2px solid var(--ac-brown); outline-offset: 1px; }
    .ac-empty { aspect-ratio: 1; }

    .ac-legend { display: flex; flex-wrap: wrap; gap: 14px; margin-top: 12px; font-size: 12px; color: var(--ac-muted); }
    .ac-legend span { display: inline-flex; align-items: center; gap: 6px; }
    .ac-dot { display: inline-block; width: 12px; height: 12px; border-radius: 4px; }
    .ac-dot.free { background: #fff; box-shadow: inset 0 0 0 1px var(--ac-line); }
    .ac-dot.taken { background: repeating-linear-gradient(135deg, #efe7df 0 3px, #e0d3c7 3px 6px); }
    .ac-dot.picked { background: linear-gradient(145deg, #8a5e42, #5b3a26); }

    .ac-hint { margin: 10px 0 0; font-size: 12.5px; line-height: 1.45; color: var(--ac-muted); }
    .ac-hint.ac-hint-error { color: #b42318; font-weight: 700; }

    @media (hover: hover) and (pointer: fine) {
        .ac-day:is(.free, .checkout-only):not(.start):not(.end):not(.in-range):hover { background: rgba(122, 79, 54, 0.12); }
    }

    @media (max-width: 640px) {
        .ac-months { grid-template-columns: 1fr; }
        .ac-day { font-size: 14px; }
    }
</style>

<script>
(function () {
    var root = document.getElementById("availability-calendar");
    var checkIn = document.getElementById("check_in");
    var checkOut = document.getElementById("check_out");
    if (!root || !checkIn || !checkOut) return;

    var taken = new Set(<?= json_encode($calendarNights) ?>);
    var TODAY = <?= json_encode($calendarToday->format("Y-m-d")) ?>;
    var LAST = <?= json_encode($calendarLast->format("Y-m-d")) ?>;
    var monthsBox = root.querySelector("[data-ac-months]");
    var hint = root.querySelector("[data-ac-hint]");
    var prev = root.querySelector("[data-ac-prev]");
    var next = root.querySelector("[data-ac-next]");
    var twoUp = window.matchMedia("(min-width: 641px)");
    var DEFAULT_HINT = hint.textContent;

    function pad(n) { return String(n).padStart(2, "0"); }
    function iso(d) { return d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate()); }
    function parse(s) { var p = s.split("-"); return new Date(+p[0], +p[1] - 1, +p[2]); }
    function addDays(s, n) { var d = parse(s); d.setDate(d.getDate() + n); return iso(d); }

    // every night of [from, to) is bookable
    function rangeFree(from, to) {
        for (var d = from; d < to; d = addDays(d, 1)) {
            if (taken.has(d)) return false;
        }
        return true;
    }

    var start = checkIn.value || "";
    var end = checkOut.value || "";
    var view = parse((start || TODAY).slice(0, 7) + "-01");

    function setHint(text, isError) {
        hint.textContent = text || DEFAULT_HINT;
        hint.classList.toggle("ac-hint-error", !!isError);
    }

    function push(input, value) {
        input.value = value;
        input.dispatchEvent(new Event("input", { bubbles: true }));
        input.dispatchEvent(new Event("change", { bubbles: true }));
    }

    function pick(day) {
        if (day < TODAY) return;

        var choosingEnd = start && !end && day > start;

        if (choosingEnd) {
            if (!rangeFree(start, day)) {
                setHint("Some nights between those dates are not available. Choose an earlier check-out.", true);
                return;
            }
            end = day;
            push(checkOut, end);
            var nights = Math.round((parse(end) - parse(start)) / 86400000);
            setHint(nights + " night" + (nights === 1 ? "" : "s") + " selected. Tap another date to start over.");
        } else {
            if (taken.has(day)) {
                setHint("That night is not available. Please pick another check-in date.", true);
                return;
            }
            start = day;
            end = "";
            push(checkIn, start);
            push(checkOut, "");
            setHint("Now tap your check-out date.");
        }
        render();
    }

    function render() {
        monthsBox.textContent = "";
        var count = twoUp.matches ? 2 : 1;
        var choosingEnd = start && !end;

        for (var m = 0; m < count; m++) {
            var first = new Date(view.getFullYear(), view.getMonth() + m, 1);
            var month = document.createElement("div");
            month.className = "ac-month";

            var title = document.createElement("h4");
            title.textContent = first.toLocaleDateString(undefined, { month: "long", year: "numeric" });
            month.appendChild(title);

            var grid = document.createElement("div");
            grid.className = "ac-grid";

            ["Su", "Mo", "Tu", "We", "Th", "Fr", "Sa"].forEach(function (w) {
                var el = document.createElement("span");
                el.className = "ac-wd";
                el.textContent = w;
                grid.appendChild(el);
            });

            for (var e = 0; e < first.getDay(); e++) {
                var empty = document.createElement("span");
                empty.className = "ac-empty";
                grid.appendChild(empty);
            }

            var days = new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate();

            for (var n = 1; n <= days; n++) {
                var day = iso(new Date(first.getFullYear(), first.getMonth(), n));
                var btn = document.createElement("button");
                btn.type = "button";
                btn.className = "ac-day";
                btn.textContent = n;
                btn.dataset.day = day;

                var label = parse(day).toLocaleDateString(undefined, { weekday: "long", month: "long", day: "numeric" });

                if (day < TODAY || day > LAST) {
                    btn.classList.add("past");
                    btn.disabled = true;
                } else if (taken.has(day)) {
                    // a taken night can still be the check-out morning of a free stay
                    if (choosingEnd && day > start && rangeFree(start, day)) {
                        btn.classList.add("checkout-only");
                        btn.setAttribute("aria-label", label + ", check-out only");
                    } else {
                        btn.classList.add("taken");
                        btn.setAttribute("aria-label", label + ", not available");
                        btn.setAttribute("aria-disabled", "true");
                    }
                } else {
                    btn.classList.add("free");
                    btn.setAttribute("aria-label", label + ", available");
                }

                if (day === TODAY) btn.classList.add("today");
                if (start && day === start) btn.classList.add("start");
                if (end && day === end) btn.classList.add("end");
                if (start && end && day > start && day < end) btn.classList.add("in-range");

                btn.addEventListener("click", function () { pick(this.dataset.day); });
                grid.appendChild(btn);
            }

            month.appendChild(grid);
            monthsBox.appendChild(month);
        }

        var lastShown = new Date(view.getFullYear(), view.getMonth() + count - 1, 1);
        prev.disabled = iso(view) <= TODAY.slice(0, 7) + "-01";
        next.disabled = iso(lastShown) >= LAST.slice(0, 7) + "-01";
    }

    prev.addEventListener("click", function () { view = new Date(view.getFullYear(), view.getMonth() - 1, 1); render(); });
    next.addEventListener("click", function () { view = new Date(view.getFullYear(), view.getMonth() + 1, 1); render(); });
    twoUp.addEventListener("change", render);

    // dates typed by hand: follow them and warn if they include a closed night
    function fromInputs() {
        start = checkIn.value || "";
        end = checkOut.value && checkOut.value > start ? checkOut.value : "";
        if (start && end && !rangeFree(start, end)) {
            setHint("Some of those nights are not available. Please choose other dates.", true);
        } else if (start && taken.has(start) && !end) {
            setHint("That check-in night is not available. Please pick another date.", true);
        } else {
            setHint("");
        }
        if (start) view = parse(start.slice(0, 7) + "-01");
        render();
    }

    checkIn.addEventListener("change", function (e) { if (e.isTrusted) fromInputs(); });
    checkOut.addEventListener("change", function (e) { if (e.isTrusted) fromInputs(); });

    render();
    if (start && end && !rangeFree(start, end)) {
        setHint("Some of the selected nights are not available. Please choose other dates.", true);
    }
})();
</script>
