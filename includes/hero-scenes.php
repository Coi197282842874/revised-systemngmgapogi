<?php
/*
 * Drawn scenes for the homepage, used until real photos are uploaded
 * (Admin → Settings → Homepage photos, or Admin → Rooms → Photos).
 *
 *     <?= hero_scene("dusk") ?>          a house with lit windows under a sky: dusk | night | dawn
 *     <?= room_scene("night", "r3") ?>   a bedroom with a window on that sky
 *
 * They are small drawings, not photos of the property: they promise nothing about the rooms.
 * Each fills its box like a photo would (preserveAspectRatio="xMidYMid slice"). What matters
 * is drawn in the middle, because a narrow box only shows the middle.
 *
 * The second argument is only needed when the same drawing is on a page twice: it keeps the
 * names inside the drawings apart.
 */

const HERO_SCENES = [
    "dusk" => [
        "sky" => ["#232544", "#6a3f63", "#e0895a", "#f6c27a"],
        "glow" => "#ffd9a0",
        "orb" => ["x" => 1185, "y" => 640, "r" => 70, "fill" => "#ffdca8"],
        "far" => "#4a3350",
        "near" => "#2a1d2c",
        "ground" => "#1b1319",
        "house" => "#1d1418",
        "roof" => "#0f0a0d",
        "window" => ["#ffe2a6", "#ff9d4d"],
        "tree" => "#140e13",
        "stars" => 0.35,
        "room" => [
            "wall" => ["#4d362c", "#2f201a"], "floor" => "#22160f", "trim" => "#2a1b15",
            "curtain" => ["#94735f", "#745746"], "headboard" => ["#7a5440", "#5c3d2d"],
            "pillow" => "#f6e9d8", "sheet" => "#e6d3bd", "blanket" => ["#b3703f", "#dcb077"],
            "table" => "#2b1c16", "shade" => "#ffe0a8", "lamp" => "#ffb45e", "rug" => "#3b281f", "leaf" => "#3f4a33",
            "orb" => [868, 394, 24],
        ],
    ],
    "night" => [
        "sky" => ["#070b1e", "#121a3d", "#2a2a5a", "#4a3a66"],
        "glow" => "#cfd8ff",
        "orb" => ["x" => 1240, "y" => 210, "r" => 46, "fill" => "#f4ecd8"],
        "far" => "#1a2147",
        "near" => "#0e1330",
        "ground" => "#080b1c",
        "house" => "#0c0f22",
        "roof" => "#05070f",
        "window" => ["#fff0c2", "#ffb560"],
        "tree" => "#05070f",
        "stars" => 1,
        "room" => [
            "wall" => ["#2b2f55", "#191b36"], "floor" => "#0e1024", "trim" => "#0c0e20",
            "curtain" => ["#565b8c", "#3f436d"], "headboard" => ["#454a7d", "#30345c"],
            "pillow" => "#f1f2fb", "sheet" => "#d6d9ee", "blanket" => ["#5d63a6", "#979de0"],
            "table" => "#121430", "shade" => "#fff1c6", "lamp" => "#ffc879", "rug" => "#1e2142", "leaf" => "#2c4a4a",
            "orb" => [716, 214, 18],
        ],
    ],
    "dawn" => [
        "sky" => ["#3d5a80", "#c08a8a", "#f2b880", "#fde3b0"],
        "glow" => "#fff1c9",
        "orb" => ["x" => 430, "y" => 650, "r" => 78, "fill" => "#fff0c4"],
        "far" => "#8a6f7c",
        "near" => "#54404a",
        "ground" => "#33262b",
        "house" => "#2b2024",
        "roof" => "#1a1216",
        "window" => ["#ffe8b8", "#ffb066"],
        "tree" => "#221a1d",
        "stars" => 0,
        "room" => [
            "wall" => ["#7a5d52", "#4c3934"], "floor" => "#2e2220", "trim" => "#3b2b28",
            "curtain" => ["#d3b3a3", "#b08f80"], "headboard" => ["#94695a", "#714d41"],
            "pillow" => "#fff7ec", "sheet" => "#f1e1cf", "blanket" => ["#c98867", "#f4bf8a"],
            "table" => "#3b2b28", "shade" => "#fff3cf", "lamp" => "#ffc98a", "rug" => "#54403a", "leaf" => "#5b6b47",
            "orb" => [712, 398, 26],
        ],
    ],
];

// "dusk", "night" or "dawn", and the name that keeps this drawing's parts apart from others
function scene_names(string $mood, string $prefix, string $key): array
{
    $mood = isset(HERO_SCENES[$mood]) ? $mood : "dusk";

    return [$mood, $prefix . "-" . $mood . preg_replace("/[^a-z0-9]/", "", strtolower($key))];
}

// the sky's colors, top to bottom, as the stops of a gradient
function scene_sky_stops(array $sky): string
{
    return '<stop offset="0" stop-color="' . $sky[0] . '"/><stop offset=".45" stop-color="' . $sky[1] . '"/>'
        . '<stop offset=".78" stop-color="' . $sky[2] . '"/><stop offset="1" stop-color="' . $sky[3] . '"/>';
}

function hero_scene(string $mood, string $key = ""): string
{
    [$mood, $id] = scene_names($mood, "scene", $key);
    $s = HERO_SCENES[$mood];

    // the same stars every time: a fixed sprinkle, not random on each page load
    $stars = "";

    if ($s["stars"] > 0) {
        $spots = [
            [120, 90, 1.6], [260, 190, 1.1], [390, 70, 1.4], [520, 240, 1], [640, 120, 1.8], [770, 60, 1.2],
            [880, 210, 1], [990, 110, 1.5], [1090, 260, 1.1], [1360, 90, 1.7], [1450, 230, 1.2], [1530, 140, 1],
            [200, 330, 1], [470, 360, 1.3], [720, 300, 1], [1120, 380, 1.2], [1400, 340, 1], [60, 250, 1.2],
        ];

        foreach ($spots as [$x, $y, $r]) {
            $stars .= '<circle cx="' . $x . '" cy="' . $y . '" r="' . $r . '" fill="#fff" opacity="' . round(0.85 * $s["stars"], 2) . '"/>';
        }
    }

    $pine = function (int $x, int $base, int $height) use ($s): string {
        $w = (int) round($height * 0.34);

        return '<path fill="' . $s["tree"] . '" d="M' . $x . ' ' . ($base - $height)
            . ' L' . ($x + (int) ($w * 0.55)) . ' ' . ($base - (int) ($height * 0.62))
            . ' L' . ($x + (int) ($w * 0.3)) . ' ' . ($base - (int) ($height * 0.62))
            . ' L' . ($x + (int) ($w * 0.8)) . ' ' . ($base - (int) ($height * 0.3))
            . ' L' . ($x + (int) ($w * 0.45)) . ' ' . ($base - (int) ($height * 0.3))
            . ' L' . ($x + $w) . ' ' . $base
            . ' L' . ($x - $w) . ' ' . $base
            . ' L' . ($x - (int) ($w * 0.45)) . ' ' . ($base - (int) ($height * 0.3))
            . ' L' . ($x - (int) ($w * 0.8)) . ' ' . ($base - (int) ($height * 0.3))
            . ' L' . ($x - (int) ($w * 0.3)) . ' ' . ($base - (int) ($height * 0.62))
            . ' L' . ($x - (int) ($w * 0.55)) . ' ' . ($base - (int) ($height * 0.62)) . ' Z"/>';
    };

    return '<svg class="scene" viewBox="0 0 1600 900" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">'
        . '<defs>'
        . '<linearGradient id="' . $id . '-sky" x1="0" y1="0" x2="0" y2="1">' . scene_sky_stops($s["sky"]) . '</linearGradient>'
        . '<radialGradient id="' . $id . '-glow" cx=".5" cy=".5" r=".5">'
        . '<stop offset="0" stop-color="' . $s["glow"] . '" stop-opacity=".75"/><stop offset="1" stop-color="' . $s["glow"] . '" stop-opacity="0"/>'
        . '</radialGradient>'
        . '<linearGradient id="' . $id . '-window" x1="0" y1="0" x2="0" y2="1">'
        . '<stop offset="0" stop-color="' . $s["window"][0] . '"/><stop offset="1" stop-color="' . $s["window"][1] . '"/>'
        . '</linearGradient>'
        . '<radialGradient id="' . $id . '-spill" cx=".5" cy="0" r="1">'
        . '<stop offset="0" stop-color="' . $s["window"][1] . '" stop-opacity=".38"/><stop offset="1" stop-color="' . $s["window"][1] . '" stop-opacity="0"/>'
        . '</radialGradient>'
        . '</defs>'

        // sky, stars, sun or moon
        . '<rect width="1600" height="900" fill="url(#' . $id . '-sky)"/>'
        . $stars
        . '<circle cx="' . $s["orb"]["x"] . '" cy="' . $s["orb"]["y"] . '" r="' . ($s["orb"]["r"] * 4) . '" fill="url(#' . $id . '-glow)"/>'
        . '<circle cx="' . $s["orb"]["x"] . '" cy="' . $s["orb"]["y"] . '" r="' . $s["orb"]["r"] . '" fill="' . $s["orb"]["fill"] . '"/>'

        // hills, far and near
        . '<path fill="' . $s["far"] . '" d="M0 640 C160 560 300 600 450 570 C620 535 720 470 900 520 C1060 565 1180 500 1340 540 C1460 570 1540 545 1600 560 L1600 900 L0 900 Z"/>'
        . '<path fill="' . $s["near"] . '" d="M0 720 C200 650 380 700 560 680 C760 655 900 700 1100 675 C1300 650 1460 700 1600 670 L1600 900 L0 900 Z"/>'

        // trees behind the house
        . $pine(250, 735, 250) . $pine(360, 740, 190) . $pine(1310, 735, 270) . $pine(1430, 742, 200) . $pine(1210, 738, 170)

        // the ground and the light the windows throw on it
        . '<rect y="728" width="1600" height="172" fill="' . $s["ground"] . '"/>'
        . '<ellipse cx="800" cy="728" rx="520" ry="120" fill="url(#' . $id . '-spill)"/>'

        // the house: a gable with a glass front, and a low wing beside it
        . '<rect x="880" y="408" width="30" height="92" fill="' . $s["roof"] . '"/>'
        . '<path fill="' . $s["house"] . '" d="M560 730 L560 530 L760 392 L960 530 L960 730 Z"/>'
        . '<path fill="url(#' . $id . '-window)" d="M612 730 L612 556 L760 452 L908 556 L908 730 Z"/>'
        . '<g stroke="' . $s["house"] . '" stroke-width="7" fill="none">'
        . '<path d="M760 452 L760 730"/><path d="M686 504 L686 730"/><path d="M834 504 L834 730"/>'
        . '<path d="M612 622 L908 622"/>'
        . '</g>'
        . '<path fill="none" stroke="' . $s["roof"] . '" stroke-width="20" stroke-linejoin="round" stroke-linecap="round" d="M528 552 L760 386 L992 552"/>'
        . '<rect x="960" y="604" width="236" height="126" fill="' . $s["house"] . '"/>'
        . '<rect x="948" y="592" width="262" height="16" rx="3" fill="' . $s["roof"] . '"/>'
        . '<rect x="994" y="636" width="68" height="58" rx="3" fill="url(#' . $id . '-window)"/>'
        . '<rect x="1090" y="636" width="68" height="58" rx="3" fill="url(#' . $id . '-window)"/>'
        . '<rect x="524" y="728" width="700" height="12" rx="3" fill="' . $s["roof"] . '"/>'

        // trees in front, at the sides
        . $pine(120, 800, 330) . $pine(1520, 800, 350)
        . '</svg>';
}

/*
 * A bedroom seen from the foot of the bed: a window on the mood's sky between two curtains,
 * a bed with pillows, and a lamp on a small table at each side. A plant and a framed picture
 * stand further out, so they only show in wide boxes.
 */
function room_scene(string $mood, string $key = ""): string
{
    [$mood, $id] = scene_names($mood, "room", $key);
    $s = HERO_SCENES[$mood];
    $r = $s["room"];
    [$orbX, $orbY, $orbR] = $r["orb"];

    $stars = "";

    if ($s["stars"] > 0) {
        foreach ([[672, 184, 1.8], [748, 262, 1.3], [790, 176, 1.6], [846, 232, 1.2], [905, 190, 1.9], [930, 280, 1.2], [690, 318, 1.1], [872, 318, 1.4]] as [$x, $y, $radius]) {
            $stars .= '<circle cx="' . $x . '" cy="' . $y . '" r="' . $radius . '" fill="#fff" opacity="' . round(0.9 * $s["stars"], 2) . '"/>';
        }
    }

    // a small table with a lit lamp on it, centered on $x
    $lamp = function (int $x) use ($r): string {
        return '<rect x="' . ($x - 52) . '" y="612" width="104" height="128" rx="6" fill="' . $r["table"] . '"/>'
            . '<rect x="' . ($x - 36) . '" y="646" width="72" height="4" rx="2" fill="#fff" opacity=".08"/>'
            . '<rect x="' . ($x - 12) . '" y="582" width="24" height="30" rx="4" fill="' . $r["trim"] . '"/>'
            . '<path fill="' . $r["shade"] . '" d="M' . ($x - 30) . ' 520 H' . ($x + 30) . ' L' . ($x + 46) . ' 584 H' . ($x - 46) . ' Z"/>';
    };

    return '<svg class="scene" viewBox="0 0 1600 900" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">'
        . '<defs>'
        . '<linearGradient id="' . $id . '-wall" x1="0" y1="0" x2="0" y2="1">'
        . '<stop offset="0" stop-color="' . $r["wall"][0] . '"/><stop offset="1" stop-color="' . $r["wall"][1] . '"/>'
        . '</linearGradient>'
        . '<linearGradient id="' . $id . '-sky" x1="0" y1="0" x2="0" y2="1">' . scene_sky_stops($s["sky"]) . '</linearGradient>'
        . '<radialGradient id="' . $id . '-orb" cx=".5" cy=".5" r=".5">'
        . '<stop offset="0" stop-color="' . $s["glow"] . '" stop-opacity=".8"/><stop offset="1" stop-color="' . $s["glow"] . '" stop-opacity="0"/>'
        . '</radialGradient>'
        . '<radialGradient id="' . $id . '-lamp" cx=".5" cy=".5" r=".5">'
        . '<stop offset="0" stop-color="' . $r["lamp"] . '" stop-opacity=".5"/><stop offset="1" stop-color="' . $r["lamp"] . '" stop-opacity="0"/>'
        . '</radialGradient>'
        . '<radialGradient id="' . $id . '-daylight" cx=".5" cy=".5" r=".5">'
        . '<stop offset="0" stop-color="' . $s["sky"][3] . '" stop-opacity=".22"/><stop offset="1" stop-color="' . $s["sky"][3] . '" stop-opacity="0"/>'
        . '</radialGradient>'
        . '<linearGradient id="' . $id . '-curtain" x1="0" y1="0" x2="1" y2="0">'
        . '<stop offset="0" stop-color="' . $r["curtain"][0] . '"/><stop offset=".3" stop-color="' . $r["curtain"][1] . '"/>'
        . '<stop offset=".55" stop-color="' . $r["curtain"][0] . '"/><stop offset=".8" stop-color="' . $r["curtain"][1] . '"/>'
        . '<stop offset="1" stop-color="' . $r["curtain"][0] . '"/>'
        . '</linearGradient>'
        . '<linearGradient id="' . $id . '-headboard" x1="0" y1="0" x2="0" y2="1">'
        . '<stop offset="0" stop-color="' . $r["headboard"][0] . '"/><stop offset="1" stop-color="' . $r["headboard"][1] . '"/>'
        . '</linearGradient>'
        . '<radialGradient id="' . $id . '-shade" cx=".5" cy=".45" r=".75">'
        . '<stop offset=".55" stop-color="#000" stop-opacity="0"/><stop offset="1" stop-color="#000" stop-opacity=".45"/>'
        . '</radialGradient>'
        . '<clipPath id="' . $id . '-pane"><rect x="640" y="150" width="320" height="300"/></clipPath>'
        . '</defs>'

        // the wall, lit by the window and by the two lamps
        . '<rect width="1600" height="900" fill="url(#' . $id . '-wall)"/>'
        . '<ellipse cx="800" cy="300" rx="430" ry="310" fill="url(#' . $id . '-daylight)"/>'
        . '<circle cx="488" cy="552" r="280" fill="url(#' . $id . '-lamp)"/>'
        . '<circle cx="1112" cy="552" r="280" fill="url(#' . $id . '-lamp)"/>'

        // what the window looks out on
        . '<g clip-path="url(#' . $id . '-pane)">'
        . '<rect x="640" y="150" width="320" height="300" fill="url(#' . $id . '-sky)"/>'
        . $stars
        . '<circle cx="' . $orbX . '" cy="' . $orbY . '" r="' . ($orbR * 4) . '" fill="url(#' . $id . '-orb)"/>'
        . '<circle cx="' . $orbX . '" cy="' . $orbY . '" r="' . $orbR . '" fill="' . $s["orb"]["fill"] . '"/>'
        . '<path fill="' . $s["far"] . '" d="M640 402 C700 374 760 394 820 380 C880 366 920 382 960 374 L960 450 L640 450 Z"/>'
        . '<path fill="' . $s["near"] . '" d="M640 428 C720 410 800 432 880 416 C920 408 945 416 960 412 L960 450 L640 450 Z"/>'
        . '</g>'

        // the window frame and its sill
        . '<rect x="640" y="150" width="320" height="300" fill="none" stroke="' . $r["trim"] . '" stroke-width="16"/>'
        . '<path d="M800 150 V450 M640 300 H960" stroke="' . $r["trim"] . '" stroke-width="9"/>'
        . '<rect x="620" y="446" width="360" height="16" rx="3" fill="' . $r["trim"] . '"/>'

        // curtains drawn aside, and their rod
        . '<rect x="556" y="120" width="488" height="9" rx="4.5" fill="' . $r["trim"] . '"/>'
        . '<circle cx="556" cy="124.5" r="9" fill="' . $r["trim"] . '"/><circle cx="1044" cy="124.5" r="9" fill="' . $r["trim"] . '"/>'
        . '<path fill="url(#' . $id . '-curtain)" d="M578 128 L690 128 C682 250 668 380 700 520 L586 520 C572 380 590 250 578 128 Z"/>'
        . '<path fill="url(#' . $id . '-curtain)" d="M1022 128 L910 128 C918 250 932 380 900 520 L1014 520 C1028 380 1010 250 1022 128 Z"/>'

        // a framed picture of the same sky, far right
        . '<rect x="1236" y="236" width="150" height="190" rx="4" fill="' . $r["trim"] . '"/>'
        . '<rect x="1248" y="248" width="126" height="166" fill="url(#' . $id . '-sky)"/>'
        . '<path fill="' . $s["near"] . '" d="M1248 372 C1290 344 1330 362 1374 344 V414 H1248 Z"/>'

        // the floor and the rug
        . '<rect y="690" width="1600" height="210" fill="' . $r["floor"] . '"/>'
        . '<rect y="682" width="1600" height="12" fill="' . $r["trim"] . '"/>'
        . '<ellipse cx="800" cy="852" rx="600" ry="42" fill="' . $r["rug"] . '"/>'

        // a plant in a pot, far left
        . '<g fill="' . $r["leaf"] . '">'
        . '<ellipse cx="318" cy="630" rx="20" ry="70" transform="rotate(-24 318 630)"/>'
        . '<ellipse cx="362" cy="622" rx="20" ry="74" transform="rotate(22 362 622)"/>'
        . '<ellipse cx="340" cy="600" rx="18" ry="80"/>'
        . '<ellipse cx="300" cy="668" rx="16" ry="52" transform="rotate(-52 300 668)"/>'
        . '<ellipse cx="382" cy="664" rx="16" ry="52" transform="rotate(50 382 664)"/>'
        . '</g>'
        . '<path fill="' . $r["trim"] . '" d="M300 698 H380 L370 776 H310 Z"/>'

        // the headboard, the tables with their lamps, the bed
        . '<rect x="566" y="468" width="468" height="220" rx="28" fill="url(#' . $id . '-headboard)"/>'
        . '<path d="M683 492 V640 M800 492 V640 M917 492 V640" stroke="' . $r["headboard"][1] . '" stroke-width="3" opacity=".6"/>'
        . $lamp(488) . $lamp(1112)
        . '<path fill="' . $r["sheet"] . '" d="M556 622 H1044 L1100 760 H500 Z"/>'
        . '<rect x="606" y="566" width="182" height="66" rx="30" fill="' . $r["pillow"] . '"/>'
        . '<rect x="812" y="566" width="182" height="66" rx="30" fill="' . $r["pillow"] . '"/>'
        . '<path fill="' . $r["blanket"][0] . '" d="M546 672 H1054 L1100 760 H500 Z"/>'
        . '<path fill="' . $r["sheet"] . '" d="M546 672 H1054 L1061 690 H539 Z"/>'
        . '<rect x="500" y="758" width="600" height="72" fill="' . $r["blanket"][0] . '"/>'
        . '<rect x="500" y="758" width="600" height="72" fill="#000" opacity=".2"/>'
        . '<rect x="500" y="782" width="600" height="9" fill="' . $r["blanket"][1] . '" opacity=".85"/>'
        . '<path d="M524 716 H1076" stroke="' . $r["blanket"][1] . '" stroke-width="6" opacity=".7"/>'
        . '<rect x="492" y="828" width="616" height="18" rx="4" fill="' . $r["trim"] . '"/>'
        . '<rect x="506" y="846" width="16" height="22" fill="' . $r["trim"] . '"/><rect x="1078" y="846" width="16" height="22" fill="' . $r["trim"] . '"/>'

        // the corners a little darker, like a photo taken at night
        . '<rect width="1600" height="900" fill="url(#' . $id . '-shade)"/>'
        . '</svg>';
}
