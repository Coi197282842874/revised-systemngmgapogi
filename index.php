<?php
session_start();

require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/icons.php";
require_once __DIR__ . "/includes/photos.php";
require_once __DIR__ . "/includes/hero-scenes.php";

/*
 * The landing page. Its look is written with Tailwind CSS classes: they are collected from
 * this file into assets/site.css (see assets/tailwind.css and build-css.bat). After changing
 * classes here, build the CSS again.
 */

$isLoggedIn = isset($_SESSION["user_id"]);
$isCustomer = $isLoggedIn && ($_SESSION["role"] ?? "") === "customer";
$isAdmin = $isLoggedIn && ($_SESSION["role"] ?? "") === "admin";

$stmt = $pdo->query("
    SELECT *
    FROM rooms
    WHERE status = 'available'
    ORDER BY id DESC
    LIMIT 4
");
$rooms = $stmt->fetchAll();

$customerRequired = isset($_GET["customer_required"]);

$siteSettings = [
    "hero_card_icon" => "🏡",
    "hero_card_title" => "ARVE'S House",
    "hero_card_subtitle" => "Simple stay. Greater memories.",
    "hero_card_bg_start" => "#c9a27f",
    "hero_card_bg_end" => "#7b5841"
];

try {
    $stmt = $pdo->query("
        SELECT setting_key, setting_value
        FROM site_settings
        WHERE setting_key IN (
            'hero_card_icon',
            'hero_card_title',
            'hero_card_subtitle',
            'hero_card_bg_start',
            'hero_card_bg_end'
        )
    ");

    foreach ($stmt->fetchAll() as $setting) {
        if (array_key_exists($setting["setting_key"], $siteSettings)) {
            $siteSettings[$setting["setting_key"]] = $setting["setting_value"];
        }
    }
} catch (PDOException $e) {
    // Keep defaults until the site_settings table is created.
}

// only colors like #c9a27f are ever printed into the page's styles
foreach (["hero_card_bg_start" => "#c9a27f", "hero_card_bg_end" => "#7b5841"] as $key => $fallback) {
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $siteSettings[$key])) {
        $siteSettings[$key] = $fallback;
    }
}


// ======================================================
// THE LARGE PICTURES
// 1. the photos chosen for the homepage (Admin → Settings → Homepage photos)
// 2. otherwise the rooms' own photos: the first photo of each room, then their others
// 3. otherwise three drawn scenes, until real photos are uploaded
// ======================================================

$roomPhotos = [];

foreach ($rooms as $room) {
    $roomPhotos[(int) $room["id"]] = photo_list("rooms/room_" . (int) $room["id"]);
}

$slides = [];

foreach (photo_list("home/hero") as $photo) {
    $slides[] = ["photo" => $photo, "scene" => ""];
}

if (!$slides) {
    for ($slot = 1; $slot <= PHOTO_SLOTS && count($slides) < PHOTO_SLOTS; $slot++) {
        foreach ($roomPhotos as $photos) {
            if (isset($photos[$slot]) && count($slides) < PHOTO_SLOTS) {
                $slides[] = ["photo" => $photos[$slot], "scene" => ""];
            }
        }
    }
}

$drawn = !$slides;

if ($drawn) {
    $slides = [
        ["photo" => "", "scene" => "dusk"],
        ["photo" => "", "scene" => "night"],
        ["photo" => "", "scene" => "dawn"],
    ];
}

// The homepage's own photos (assets/photos, 4:3): name.jpg is 1200 pixels wide, name-800.jpg 800.
// Returns what an <img> needs, or null when a file is missing.
function site_photo(string $name): ?array
{
    $files = [];

    foreach (["large" => $name . ".jpg", "small" => $name . "-800.jpg"] as $size => $file) {
        if (!is_file(__DIR__ . "/assets/photos/" . $file)) {
            return null;
        }

        $files[$size] = "assets/photos/" . $file . "?v=" . filemtime(__DIR__ . "/assets/photos/" . $file);
    }

    return ["src" => $files["large"], "srcset" => $files["small"] . " 800w, " . $files["large"] . " 1200w"];
}

$aboutPhoto = site_photo("house");

// "Inside ARVE'S House": each photo with the words guests read under it
$inside = array_values(array_filter([
    ["photo" => site_photo("living"), "title" => "Living and dining", "text" => "A sofa, a dining table and board games"],
    ["photo" => site_photo("kitchen"), "title" => "Kitchen", "text" => "Sink, induction cooker, rice cooker and kettle"],
    ["photo" => site_photo("bathroom"), "title" => "Bathroom", "text" => "Sink, soap and a bidet spray"],
    ["photo" => site_photo("smart-tv"), "title" => "Smart TV with Disney+", "text" => "For movie nights during your stay"],
], fn (array $item): bool => $item["photo"] !== null));


// ======================================================
// CLASSES USED MORE THAN ONCE
// ======================================================

$button = "inline-flex min-h-12 cursor-pointer items-center justify-center gap-2 rounded-[11px] border border-transparent px-6 "
    . "text-[13px] font-extrabold transition-transform duration-150 ease-out-strong hover:-translate-y-0.5 "
    . "active:translate-y-0 active:scale-[0.97] motion-reduce:transform-none";

$buttonBrown = $button . " bg-linear-to-br from-brown-500 to-brown-700 text-white shadow-[0_10px_25px_rgba(90,56,38,0.25)]";
$buttonGlass = $button . " border-white/30 bg-white/10 text-white backdrop-blur-md";
$buttonGold = $button . " bg-gold text-brown-900 shadow-[0_10px_25px_rgba(0,0,0,0.25)]";

$eyebrow = "text-[11px] font-extrabold uppercase tracking-[4px]";

$navLink = "rounded-[9px] px-3.5 py-2.5 text-[13px] font-semibold transition-colors duration-200 hover:bg-current/10 "
    . "focus-visible:bg-current/10 max-md:flex max-md:min-h-[46px] max-md:items-center max-md:justify-center max-md:text-[15px]";

$navButton = $navLink . " transition-transform duration-150 ease-out-strong active:scale-[0.97]";

$heroIcon = in_array(trim($siteSettings["hero_card_icon"]), ["🏡", "🏠", "⌂", ""], true)
    ? icon("home")
    : htmlspecialchars($siteSettings["hero_card_icon"]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#1a110d">
<meta name="description" content="ARVE'S House: comfortable rooms and simple online reservations. Check availability and book your stay.">
<title>ARVE'S House | Transient & Reservation</title>

<script>
    // pages that can run scripts wait with their reveals; others show everything at once
    document.documentElement.classList.add("js");
</script>

<link rel="stylesheet" href="assets/site.css?v=<?= (int) @filemtime(__DIR__ . "/assets/site.css") ?>">

<?php if (!$drawn): ?>
    <link rel="preload" as="image" href="<?= htmlspecialchars($slides[0]["photo"]) ?>" fetchpriority="high">
<?php endif; ?>

<?php require __DIR__ . "/includes/glass.php"; ?>
</head>

<body>

<!-- ======================================================
     MENU: clear over the picture, solid once the page has scrolled
====================================================== -->

<header
    class="fixed inset-x-0 top-0 z-[1000] pt-[env(safe-area-inset-top,0px)] text-white transition-[background-color,box-shadow,color] duration-300 data-solid:bg-cream/92 data-solid:text-ink data-solid:shadow-[0_1px_0_rgba(122,79,54,0.14),0_10px_30px_rgba(76,50,34,0.08)] data-solid:backdrop-blur-xl"
    data-nav
>
    <div class="mx-auto flex h-[72px] max-w-[1320px] items-center justify-between gap-4 px-[18px] md:h-20 md:px-8 lg:px-10">

        <a href="index.php" class="flex min-w-0 items-center gap-3 text-[19px] font-extrabold md:text-[21px]">
            <span class="grid size-[42px] flex-none place-items-center rounded-[13px] bg-linear-to-br from-brown-500 to-brown-700 text-[21px] text-white shadow-[0_8px_20px_rgba(90,56,38,0.28)]"><?= icon("home") ?></span>
            <span class="leading-tight">
                ARVE'S House
                <small class="block whitespace-nowrap text-[8.5px] font-medium tracking-[1.2px] opacity-70">YOUR HOME AWAY FROM HOME</small>
            </span>
        </a>

        <button
            class="relative z-[1002] grid size-11 cursor-pointer place-items-center rounded-[10px] border-0 bg-transparent text-[24px] text-current transition-transform duration-150 ease-out-strong active:scale-[0.97] md:hidden"
            id="mobileMenuButton"
            onclick="toggleMenu()"
            type="button"
            aria-label="Open menu"
            aria-expanded="false"
            aria-controls="navLinks"
        ><?= icon("menu") ?></button>

        <nav
            class="nav-links items-center gap-1.5 md:flex max-md:absolute max-md:inset-x-0 max-md:top-full max-md:max-h-[calc(100dvh-72px)] max-md:flex-col max-md:items-stretch max-md:gap-2 max-md:overflow-auto max-md:overscroll-contain max-md:border-b max-md:border-brown-500/15 max-md:bg-cream max-md:px-[18px] max-md:pt-4 max-md:pb-[calc(22px+env(safe-area-inset-bottom,0px))] max-md:text-ink max-md:shadow-[0_16px_35px_rgba(76,50,34,0.10)]"
            id="navLinks"
            aria-label="Main"
        >
            <a href="index.php" class="<?= $navLink ?>">Home</a>
            <a href="rooms.php" class="<?= $navLink ?>">Rooms</a>
            <a href="#about" class="<?= $navLink ?>">About</a>

            <?php if ($isCustomer): ?>
                <a href="customer/dashboard.php" class="<?= $navLink ?>">My Reservations</a>
                <a href="customer/profile.php" class="<?= $navLink ?>">My Profile</a>
                <a href="logout.php" class="<?= $navButton ?> bg-linear-to-br from-brown-500 to-brown-700 px-[18px] text-white">Logout</a>
            <?php elseif ($isAdmin): ?>
                <a href="logout.php" class="<?= $navButton ?> bg-linear-to-br from-brown-500 to-brown-700 px-[18px] text-white">Logout</a>
            <?php else: ?>
                <a href="login.php" class="<?= $navButton ?> border border-current/30">Login</a>
                <a href="register.php" class="<?= $navButton ?> bg-linear-to-br from-brown-500 to-brown-700 px-[18px] text-white">Register</a>
            <?php endif; ?>
        </nav>

    </div>
</header>


<div class="bg-night">

<!-- ======================================================
     HERO: large pictures that replace each other, the words on top
====================================================== -->

<section class="relative isolate flex min-h-[640px] flex-col overflow-hidden bg-night text-white md:min-h-[88svh]" id="top" data-hero>

    <div class="slides absolute inset-0 -z-30" data-slides aria-hidden="true">
        <?php foreach ($slides as $index => $slide): ?>
            <div class="slide<?= $index === 0 ? " is-current" : "" ?>" data-slide>
                <?php if ($slide["photo"] !== ""): ?>
                    <?php if ($index === 0): ?>
                        <img src="<?= htmlspecialchars($slide["photo"]) ?>" alt="" decoding="async" fetchpriority="high">
                    <?php else: ?>
                        <!-- fetched by the script shortly before it is shown -->
                        <img data-src="<?= htmlspecialchars($slide["photo"]) ?>" alt="" decoding="async">
                    <?php endif; ?>
                <?php else: ?>
                    <?= hero_scene($slide["scene"]) ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- keeps the words readable on any picture, and lets the picture melt into the rooms below -->
    <div class="absolute inset-0 -z-20 bg-linear-to-b from-night/75 via-night/40 to-night"></div>
    <div class="absolute inset-0 -z-20 bg-[radial-gradient(ellipse_65%_50%_at_50%_42%,rgb(26_17_13/0.5),transparent_72%)]"></div>
    <div class="hero-dim absolute inset-0 -z-10 bg-night"></div>

    <div class="hero-copy mx-auto flex w-full max-w-[980px] flex-1 flex-col items-center justify-center px-5 pt-[120px] pb-[290px] text-center md:pb-[190px]">

        <?php if ($customerRequired): ?>
            <div class="fade-up mb-7 flex flex-wrap items-center justify-center gap-x-4 gap-y-1 rounded-xl border border-[#fed7aa] bg-[#fff7ed] px-[18px] py-3 text-left text-[14px] text-[#9a3412]" role="alert">
                <span><?= icon("alert") ?> Booking requires a customer account. Administrator accounts cannot create reservations.</span>
                <?php if ($isAdmin): ?>
                    <a class="font-extrabold underline" href="admin/dashboard.php">Back to Admin</a>
                <?php else: ?>
                    <a class="font-extrabold underline" href="login.php">Customer Login</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <span class="fade-up <?= $eyebrow ?> mb-5 text-gold" style="--i:0">Comfort · Relax · Stay</span>

        <h1 class="font-display text-[clamp(44px,7.2vw,92px)] leading-[1.02] tracking-[-0.02em] text-balance">
            <span class="line"><span class="line-in" style="--i:1">Find your</span></span>
            <span class="line"><span class="line-in text-gold" style="--i:2">perfect stay.</span></span>
        </h1>

        <p class="fade-up mt-6 max-w-[600px] text-[16px] text-white/80 md:text-[17px]" style="--i:4">
            Comfortable rooms, simple reservations and a relaxing place to stay.
            Discover ARVE'S House and book the room that fits your trip.
        </p>

        <div class="fade-up mt-8 flex flex-wrap justify-center gap-3" style="--i:5">
            <a href="rooms.php" class="<?= $buttonGold ?> max-sm:px-5">Explore Rooms <?= icon("arrow-right") ?></a>

            <?php if (!$isLoggedIn): ?>
                <a href="login.php" class="<?= $buttonGlass ?> max-sm:px-5">Customer Login</a>
            <?php elseif ($isCustomer): ?>
                <a href="customer/dashboard.php" class="<?= $buttonGlass ?> max-sm:px-5">My Reservations</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- the homepage card of Admin → Settings, and the controls of the pictures -->
    <div class="fade-up absolute inset-x-0 bottom-[150px] z-10 mx-auto flex w-full max-w-[1320px] items-end justify-between gap-4 px-5 max-md:bottom-[226px] max-md:justify-center md:px-8 lg:px-10" style="--i:7">

        <div class="flex items-center gap-3 rounded-2xl border border-white/20 bg-white/10 py-2.5 pr-5 pl-2.5 backdrop-blur-md max-md:hidden">
            <span
                class="grid size-11 flex-none place-items-center rounded-xl text-[22px] text-white"
                style="background:linear-gradient(145deg, <?= htmlspecialchars($siteSettings["hero_card_bg_start"]) ?>, <?= htmlspecialchars($siteSettings["hero_card_bg_end"]) ?>)"
            ><?= $heroIcon ?></span>
            <span class="leading-tight">
                <strong class="block font-display text-[17px] font-bold"><?= htmlspecialchars($siteSettings["hero_card_title"]) ?></strong>
                <span class="block text-[12px] text-white/75"><?= htmlspecialchars($siteSettings["hero_card_subtitle"]) ?></span>
            </span>
        </div>

        <?php if (count($slides) > 1): ?>
            <div class="flex items-center gap-2 rounded-full border border-white/15 bg-black/25 py-1.5 pr-1.5 pl-4 backdrop-blur-md" data-slide-controls>
                <?php foreach ($slides as $index => $slide): ?>
                    <button
                        class="slide-dot relative h-1 w-8 cursor-pointer overflow-hidden rounded-full border-0 bg-white/30 p-0 before:absolute before:-inset-x-1 before:-inset-y-4 before:content-['']"
                        type="button"
                        data-slide-to="<?= $index ?>"
                        aria-label="Picture <?= $index + 1 ?> of <?= count($slides) ?>"
                        aria-current="<?= $index === 0 ? "true" : "false" ?>"
                    ></button>
                <?php endforeach; ?>

                <button
                    class="ml-1 grid size-9 cursor-pointer place-items-center rounded-full border-0 bg-white/15 text-[15px] text-white transition-transform duration-150 ease-out-strong active:scale-[0.94]"
                    type="button"
                    data-slide-pause
                    aria-label="Stop changing the pictures"
                    aria-pressed="false"
                >
                    <span data-when-playing><?= icon("pause") ?></span>
                    <span data-when-paused hidden><?= icon("play") ?></span>
                </button>
            </div>
        <?php endif; ?>
    </div>

</section>


<!-- ======================================================
     CHECK AVAILABILITY: rests on the picture, then follows the page
====================================================== -->

<div class="pointer-events-none relative z-[900] mx-auto -mt-[208px] w-full max-w-[1080px] px-5 md:-mt-[104px] lg:sticky lg:top-[96px]" data-finder-wrap>

    <form
        action="rooms.php"
        method="GET"
        class="fade-up pointer-events-auto grid grid-cols-2 items-end gap-x-3 gap-y-4 rounded-[20px] border border-white/20 bg-white/12 p-4 text-white shadow-[0_24px_60px_rgba(0,0,0,0.35)] backdrop-blur-xl transition-[background-color,border-color] duration-300 md:grid-cols-[1fr_1fr_auto] md:gap-4 md:p-[18px] data-stuck:border-white/10 data-stuck:bg-night/85"
        style="--i:6"
        data-finder
    >
        <div class="min-w-0 border-white/20 px-2 md:border-r md:px-3.5">
            <label class="mb-1.5 block text-[11px] font-extrabold tracking-[1.5px] text-white/70" for="home_check_in">CHECK-IN</label>
            <input class="min-h-11 w-full min-w-0 border-0 bg-transparent text-[16px] text-white scheme-dark outline-0" type="date" id="home_check_in" name="check_in" min="<?= date("Y-m-d") ?>" required>
        </div>

        <div class="min-w-0 border-white/20 px-2 md:border-r md:px-3.5">
            <label class="mb-1.5 block text-[11px] font-extrabold tracking-[1.5px] text-white/70" for="home_check_out">CHECK-OUT</label>
            <input class="min-h-11 w-full min-w-0 border-0 bg-transparent text-[16px] text-white scheme-dark outline-0" type="date" id="home_check_out" name="check_out" min="<?= date("Y-m-d") ?>" required>
        </div>

        <button type="submit" class="<?= $buttonGold ?> col-span-2 min-h-[52px] px-7 md:col-span-1">
            <?= icon("search") ?> Check Availability
        </button>
    </form>
</div>


<!-- ======================================================
     ROOMS: large cards, uncovered as they scroll into view
====================================================== -->

<section class="px-5 pt-16 pb-24 text-white md:px-8 md:pt-20 lg:px-10" id="rooms">
    <div class="mx-auto max-w-[1320px]">

        <div class="reveal mb-9 flex flex-wrap items-end justify-between gap-5" data-reveal>
            <div>
                <span class="<?= $eyebrow ?> text-gold">Our Rooms</span>
                <h2 class="mt-2 font-display text-[clamp(32px,4vw,52px)] leading-[1.12]">Featured Rooms</h2>
                <p class="mt-2 text-[14px] text-white/60">Choose from our currently available accommodation.</p>
            </div>

            <a href="rooms.php" class="<?= $buttonGlass ?>">View All Rooms <?= icon("arrow-right") ?></a>
        </div>

        <?php if (count($rooms) > 0): ?>
            <div class="grid gap-5 md:grid-cols-2">
                <?php foreach ($rooms as $index => $room): ?>
                    <?php
                    $bookingUrl = "reservation.php?room_id=" . (int) $room["id"];
                    $photo = $roomPhotos[(int) $room["id"]][1] ?? "";
                    $guests = (int) $room["capacity"];
                    $description = trim((string) ($room["description"] ?? "")) ?: "Comfortable room available for your stay.";

                    // an odd number of rooms: the first one takes the whole width
                    $wide = count($rooms) % 2 === 1 && $index === 0;
                    $column = ($index + (count($rooms) % 2 === 1 ? 1 : 0)) % 2;
                    ?>
                    <article class="reveal-photo <?= $wide ? "md:col-span-2" : "" ?>" data-reveal style="--i:<?= $wide ? 0 : $column ?>">
                    <div class="photo-frame group relative isolate aspect-[4/5] overflow-hidden rounded-[22px] bg-brown-900 <?= $wide ? "md:aspect-[16/9] lg:aspect-[21/9]" : "md:aspect-[16/11]" ?>">
                        <?php if ($photo !== ""): ?>
                            <img
                                class="card-photo absolute inset-0 -z-20 size-full max-w-none object-cover transition-[scale] duration-[900ms] ease-out-strong group-hover:scale-[1.05] motion-reduce:scale-100"
                                src="<?= htmlspecialchars($photo) ?>"
                                alt="<?= htmlspecialchars($room["room_name"]) ?>"
                                loading="lazy"
                                decoding="async"
                            >
                        <?php else: ?>
                            <!-- no photo yet: a drawn bedroom, until one is added in Admin → Rooms → Photos -->
                            <div class="card-photo absolute inset-0 -z-20 bg-brown-900 transition-[scale] duration-[900ms] ease-out-strong group-hover:scale-[1.05] motion-reduce:scale-100">
                                <?= room_scene(["dusk", "night", "dawn"][$index % 3], "r" . (int) $room["id"]) ?>
                            </div>
                        <?php endif; ?>

                        <div class="absolute inset-0 -z-10 bg-linear-to-t from-black/85 via-black/30 to-black/5"></div>
                        <div class="absolute inset-0 -z-10 bg-black/55 opacity-0 transition-opacity duration-500 group-focus-within:opacity-100 group-hover:opacity-100"></div>

                        <span class="absolute top-4 left-4 inline-flex items-center gap-1.5 rounded-full bg-white/90 px-3 py-1.5 text-[11px] font-extrabold text-[#2f5d31]">
                            <?= icon("check") ?> Available
                        </span>

                        <div class="absolute inset-x-0 bottom-0 p-6 text-center">

                            <!-- steps up to make room for the details (always up on touch screens) -->
                            <div class="transition-transform duration-500 ease-out-strong group-focus-within:-translate-y-[112px] group-hover:-translate-y-[112px] motion-reduce:transition-none [@media(hover:none)]:-translate-y-[112px]">
                                <h3 class="font-display text-[26px] leading-tight text-balance"><?= htmlspecialchars($room["room_name"]) ?></h3>
                                <p class="mt-1 text-[13px] text-white/80">
                                    Up to <?= $guests ?> guest<?= $guests !== 1 ? "s" : "" ?>
                                    · ₱<?= number_format((float) $room["price"], 2) ?> / night
                                </p>
                            </div>

                            <div class="absolute inset-x-6 bottom-6 translate-y-3 opacity-0 transition-[opacity,translate] duration-500 ease-out-strong group-focus-within:translate-y-0 group-focus-within:opacity-100 group-hover:translate-y-0 group-hover:opacity-100 motion-reduce:translate-y-0 [@media(hover:none)]:translate-y-0 [@media(hover:none)]:opacity-100">
                                <p class="mx-auto mb-3 line-clamp-1 max-w-[480px] text-[13px] text-white/75"><?= htmlspecialchars($description) ?></p>

                                <?php if ($isCustomer): ?>
                                    <a href="<?= htmlspecialchars($bookingUrl) ?>" class="<?= $buttonGold ?> min-w-[200px]"><?= icon("calendar") ?> Book Now</a>
                                <?php elseif (!$isLoggedIn): ?>
                                    <a href="login.php?redirect=<?= urlencode($bookingUrl) ?>" class="<?= $buttonGold ?> min-w-[200px]"><?= icon("log-in") ?> Login to Book</a>
                                <?php else: ?>
                                    <span class="inline-flex min-h-12 min-w-[200px] items-center justify-center rounded-[11px] bg-white/15 px-6 text-[13px] font-extrabold text-white/70">Customer Account Required</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="reveal mx-auto max-w-[700px] rounded-[20px] border border-white/15 bg-white/8 p-8 text-center" data-reveal>
                <h3 class="mb-1.5 text-[17px] font-bold">No rooms currently available</h3>
                <p class="text-[14px] text-white/65">Please check again later for room availability.</p>
            </div>
        <?php endif; ?>

    </div>
</section>


<!-- ======================================================
     INSIDE ARVE'S HOUSE: large photo cards, uncovered as they scroll into view
====================================================== -->

<?php if ($inside): ?>
<section class="px-5 pb-24 text-white md:px-8 lg:px-10" id="inside">
    <div class="mx-auto max-w-[1320px]">

        <div class="reveal mb-9" data-reveal>
            <span class="<?= $eyebrow ?> text-gold">Take a Look</span>
            <h2 class="mt-2 font-display text-[clamp(32px,4vw,52px)] leading-[1.12]">Inside ARVE'S House</h2>
            <p class="mt-2 text-[14px] text-white/60">Real photos of the place you will stay in.</p>
        </div>

        <div class="grid gap-5 md:grid-cols-2">
            <?php foreach ($inside as $index => $item): ?>
                <div class="reveal-photo" data-reveal style="--i:<?= $index % 2 ?>">
                    <figure class="photo-frame group relative isolate aspect-[4/3] overflow-hidden rounded-[22px] bg-brown-900">
                        <img
                            class="card-photo absolute inset-0 -z-20 size-full max-w-none object-cover transition-[scale] duration-[900ms] ease-out-strong group-hover:scale-[1.05] motion-reduce:scale-100"
                            src="<?= htmlspecialchars($item["photo"]["src"]) ?>"
                            srcset="<?= htmlspecialchars($item["photo"]["srcset"]) ?>"
                            sizes="(min-width: 1400px) 650px, (min-width: 768px) 50vw, 100vw"
                            width="1200"
                            height="900"
                            alt="<?= htmlspecialchars($item["title"]) ?> at ARVE'S House"
                            loading="lazy"
                            decoding="async"
                        >
                        <div class="absolute inset-0 -z-10 bg-linear-to-t from-black/80 via-black/15 to-transparent"></div>

                        <figcaption class="absolute inset-x-0 bottom-0 p-6 text-center">
                            <h3 class="font-display text-[24px] leading-tight text-balance"><?= htmlspecialchars($item["title"]) ?></h3>
                            <p class="mt-1 text-[13px] text-white/80"><?= htmlspecialchars($item["text"]) ?></p>
                        </figcaption>
                    </figure>
                </div>
            <?php endforeach; ?>
        </div>

    </div>
</section>
<?php endif; ?>

</div>


<!-- ======================================================
     WHY CHOOSE US
====================================================== -->

<section class="px-5 py-[90px] md:px-8 lg:px-10">
    <div class="mx-auto max-w-[1180px]">

        <div class="reveal mx-auto mb-[46px] max-w-[720px] text-center" data-reveal>
            <span class="text-[10px] font-extrabold uppercase tracking-[3px] text-brown-500">Why Choose Us</span>
            <h2 class="mt-2 mb-3 font-display text-[clamp(34px,4vw,48px)] leading-[1.15] text-balance">Everything you need for a comfortable stay</h2>
            <p class="text-[14px] text-muted">A simple reservation experience designed for convenience, comfort and peace of mind.</p>
        </div>

        <div class="grid gap-5 md:grid-cols-3">
            <?php foreach ([
                ["bed", "Comfortable Rooms", "Clean and relaxing rooms prepared to make every visit more comfortable."],
                ["calendar", "Easy Reservation", "Check room availability and create your reservation directly from the website."],
                ["lock", "Secure Booking", "Your reservation and payment workflow stays organized through your customer account."],
            ] as $index => [$featureIcon, $title, $text]): ?>
                <div class="reveal rounded-[20px] border border-white/80 bg-white/75 p-7 text-center shadow-[0_12px_35px_rgba(76,50,34,0.06)] backdrop-blur-md" data-reveal style="--i:<?= $index ?>">
                    <div class="mx-auto mb-4 grid size-[58px] place-items-center rounded-[17px] bg-linear-to-br from-[#f6e6d3] to-[#ead1b4] text-[25px] text-brown-500"><?= icon($featureIcon) ?></div>
                    <h3 class="mb-1.5 text-[16px] font-bold"><?= $title ?></h3>
                    <p class="text-[13px] text-muted"><?= $text ?></p>
                </div>
            <?php endforeach; ?>
        </div>

    </div>
</section>


<!-- ======================================================
     ABOUT
====================================================== -->

<section class="px-5 py-[90px] md:px-8 lg:px-10" id="about">
    <div class="mx-auto grid max-w-[1180px] items-center gap-9 lg:grid-cols-[0.9fr_1.1fr] lg:gap-[55px]">

        <div class="reveal-photo" data-reveal>
            <?php if ($aboutPhoto): ?>
                <figure class="photo-frame relative isolate aspect-[4/3] overflow-hidden rounded-[22px] bg-brown-700">
                    <img
                        class="card-photo absolute inset-0 -z-10 size-full max-w-none object-cover"
                        src="<?= htmlspecialchars($aboutPhoto["src"]) ?>"
                        srcset="<?= htmlspecialchars($aboutPhoto["srcset"]) ?>"
                        sizes="(min-width: 1024px) 510px, calc(100vw - 40px)"
                        width="1200"
                        height="900"
                        alt="ARVE'S House seen from outside"
                        loading="lazy"
                        decoding="async"
                    >

                    <figcaption class="absolute bottom-3 left-3 flex items-center gap-3 rounded-2xl border border-white/20 bg-black/45 py-2 pr-4 pl-2 text-white backdrop-blur-md md:bottom-4 md:left-4">
                        <span class="grid size-10 flex-none place-items-center rounded-xl bg-white/15 text-[19px]"><?= icon("home") ?></span>
                        <span class="leading-tight">
                            <strong class="block text-[14px]">ARVE'S House</strong>
                            <span class="block text-[12px] text-white/75">Your home away from home</span>
                        </span>
                    </figcaption>
                </figure>
            <?php else: ?>
                <div class="photo-frame relative isolate grid aspect-[4/3] place-items-center overflow-hidden rounded-[22px] bg-linear-to-br from-[#b98e6d] to-[#694936] text-[100px] text-white">
                    <span class="card-photo"><?= icon("home") ?></span>
                </div>
            <?php endif; ?>
        </div>

        <div class="reveal" data-reveal style="--i:1">
            <span class="<?= $eyebrow ?> mb-2 inline-block text-brown-500">About ARVE'S House</span>
            <h2 class="mb-[18px] font-display text-[clamp(34px,4vw,48px)] leading-[1.12] text-balance">A simple, comfortable place to call home for a while.</h2>
            <p class="mb-3.5 text-muted">
                ARVE'S House gives guests an accessible way to browse rooms,
                check availability, create reservations and manage their stay.
            </p>
            <p class="mb-6 text-muted">
                Our online reservation system keeps the booking process organized
                for customers and administrators while maintaining a comfortable,
                welcoming experience.
            </p>
            <a href="rooms.php" class="<?= $buttonBrown ?>">Explore Our Rooms</a>
        </div>

    </div>
</section>


<!-- ======================================================
     LAST WORD
====================================================== -->

<section class="px-5 py-[75px] md:px-8 lg:px-10">
    <div class="reveal mx-auto max-w-[1180px] rounded-[28px] bg-linear-to-br from-brown-500 to-[#4d3021] px-[30px] py-[55px] text-center text-white shadow-[0_22px_55px_rgba(74,46,31,0.20)]" data-reveal>
        <?php if ($isCustomer): ?>
            <h2 class="mb-2.5 font-display text-[clamp(31px,4vw,45px)] text-balance">Ready for your next stay?</h2>
            <p class="mx-auto mb-[22px] max-w-[620px] text-[#eadfd8]">Choose your dates, check room availability and create your next reservation.</p>
            <a href="rooms.php" class="<?= $button ?> bg-white text-brown-900">Book Your Stay</a>
        <?php elseif (!$isLoggedIn): ?>
            <h2 class="mb-2.5 font-display text-[clamp(31px,4vw,45px)] text-balance">Your comfortable stay starts here.</h2>
            <p class="mx-auto mb-[22px] max-w-[620px] text-[#eadfd8]">Create a customer account or sign in to reserve an available room.</p>
            <a href="register.php" class="<?= $button ?> bg-white text-brown-900">Create an Account</a>
        <?php else: ?>
            <h2 class="mb-2.5 font-display text-[clamp(31px,4vw,45px)] text-balance">Administrator Website Preview</h2>
            <p class="mx-auto max-w-[620px] text-[#eadfd8]">This is the website as your guests see it. Booking is limited to customer accounts.</p>
        <?php endif; ?>
    </div>
</section>

<footer class="bg-brown-900 px-5 pt-[45px] pb-[calc(25px+env(safe-area-inset-bottom,0px))] text-[#d8c9bf] md:px-8 lg:px-10">
    <div class="mx-auto flex max-w-[1180px] flex-wrap items-center justify-between gap-[30px]">
        <div>
            <h3 class="font-display text-[22px] text-white">ARVE'S House</h3>
            <p class="text-[12px] text-[#bdaea5]">Your Home Away From Home</p>
        </div>

        <div>
            <p class="text-[12px] text-[#bdaea5]">Transient & Reservation System</p>
            <p class="text-[12px] text-[#bdaea5]">Comfort · Relax · Stay</p>
        </div>
    </div>

    <div class="mx-auto mt-7 max-w-[1180px] border-t border-white/10 pt-5 text-[11px] text-[#a9988e]">
        &copy; <?= date("Y") ?> ARVE'S House. All rights reserved.
    </div>
</footer>

<script>
const navLinks = document.getElementById("navLinks");
const mobileMenuButton = document.getElementById("mobileMenuButton");
const siteNav = document.querySelector("[data-nav]");
const lessMotion = window.matchMedia("(prefers-reduced-motion: reduce)");


// ======================================================
// MENU
// ======================================================

// clear over the picture; solid once the page has scrolled or the phone menu is open
function paintNav() {
    siteNav.toggleAttribute("data-solid", window.scrollY > 24 || navLinks.classList.contains("show"));
}

function setMenu(open) {
    navLinks.classList.toggle("show", open);
    document.body.classList.toggle("menu-open", open);

    if (mobileMenuButton) {
        mobileMenuButton.setAttribute("aria-expanded", open ? "true" : "false");
        mobileMenuButton.setAttribute("aria-label", open ? "Close menu" : "Open menu");
        mobileMenuButton.innerHTML = open ? <?= json_encode(icon("x")) ?> : <?= json_encode(icon("menu")) ?>;
    }

    paintNav();
}

function toggleMenu() {
    setMenu(!navLinks.classList.contains("show"));
}

navLinks.querySelectorAll("a").forEach(link => {
    link.addEventListener("click", () => setMenu(false));
});

document.addEventListener("keydown", event => {
    if (event.key === "Escape") {
        setMenu(false);
    }
});

window.addEventListener("resize", () => {
    if (window.innerWidth > 767) {
        setMenu(false);
    }
});

// the menu and the availability bar both follow the scroll position; once a frame is enough
let painting = false;

window.addEventListener("scroll", () => {
    if (!painting) {
        painting = true;

        requestAnimationFrame(() => {
            painting = false;
            paintNav();
            paintFinder();
        });
    }
}, { passive: true });

paintNav();


// ======================================================
// CHECK-IN AND CHECK-OUT
// ======================================================

const homeCheckIn = document.getElementById("home_check_in");
const homeCheckOut = document.getElementById("home_check_out");

function formatDateLocal(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");
    return `${year}-${month}-${day}`;
}

function updateHomeCheckout() {
    if (!homeCheckIn.value) {
        return;
    }

    const date = new Date(homeCheckIn.value + "T00:00:00");
    date.setDate(date.getDate() + 1);

    const minDate = formatDateLocal(date);
    homeCheckOut.min = minDate;

    if (homeCheckOut.value && homeCheckOut.value < minDate) {
        homeCheckOut.value = "";
    }
}

homeCheckIn.addEventListener("change", updateHomeCheckout);
updateHomeCheckout();

// On large screens the bar stays under the menu once it gets there (position: sticky), and
// then gets a darker glass, because the rooms slide under it instead of the picture.
const finder = document.querySelector("[data-finder]");
const finderWrap = document.querySelector("[data-finder-wrap]");
let finderTop = null;

function measureFinder() {
    const style = getComputedStyle(finderWrap);
    finderTop = style.position === "sticky" ? parseFloat(style.top) : null;
}

function paintFinder() {
    finder.toggleAttribute("data-stuck", finderTop !== null && finderWrap.getBoundingClientRect().top <= finderTop + 1);
}

window.addEventListener("resize", () => {
    measureFinder();
    paintFinder();
});

measureFinder();
paintFinder();


// ======================================================
// THE LARGE PICTURES
// One replaces the other every few seconds. They stop while the tab is hidden, while the
// hero is out of view, when the visitor presses pause, and for people who ask for less motion.
// ======================================================

(function () {
    const slides = Array.from(document.querySelectorAll("[data-slide]"));
    const hero = document.querySelector("[data-hero]");
    const controls = document.querySelector("[data-slide-controls]");

    if (slides.length < 2 || !controls) {
        return;
    }

    const SHOW = 7000;      // how long a picture stays
    const CHANGE = 1750;    // how long the change takes (the longest transition in site.css)

    const dots = Array.from(controls.querySelectorAll("[data-slide-to]"));
    const pause = controls.querySelector("[data-slide-pause]");

    let current = 0;
    let timer = 0;
    let changing = false;
    let paused = lessMotion.matches;
    let inView = true;

    controls.style.setProperty("--slide-time", SHOW + "ms");

    // a picture is fetched a moment before its turn, never all at the start
    function fetchPicture(index) {
        const image = slides[index].querySelector("img[data-src]");

        if (image) {
            image.src = image.dataset.src;
            image.removeAttribute("data-src");
        }
    }

    function running() {
        return !paused && inView && !document.hidden;
    }

    function plan() {
        clearTimeout(timer);

        controls.toggleAttribute("data-paused", !running());

        if (running()) {
            fetchPicture((current + 1) % slides.length);
            timer = setTimeout(() => show((current + 1) % slides.length), SHOW);
        }
    }

    // back = true when the visitor chose an earlier picture: that one comes from the other side
    function show(next, back = false) {
        if (changing || next === current) {
            return;
        }

        changing = true;
        clearTimeout(timer);
        fetchPicture(next);

        const from = slides[current];
        const to = slides[next];

        dots.forEach((dot, index) => dot.setAttribute("aria-current", index === next ? "true" : "false"));

        to.classList.toggle("is-back", back);
        to.classList.add("is-entering");

        // two frames, so the browser has drawn the start before it is asked to move
        requestAnimationFrame(() => requestAnimationFrame(() => to.classList.add("is-in")));

        setTimeout(() => {
            from.classList.remove("is-current");
            to.classList.add("is-current");
            to.classList.remove("is-entering", "is-in", "is-back");

            current = next;
            changing = false;
            plan();
        }, lessMotion.matches ? 450 : CHANGE);
    }

    dots.forEach((dot, index) => dot.addEventListener("click", () => show(index, index < current)));

    function setPaused(value) {
        paused = value;
        pause.setAttribute("aria-pressed", String(paused));
        pause.setAttribute("aria-label", paused ? "Change the pictures by themselves" : "Stop changing the pictures");
        pause.querySelector("[data-when-playing]").hidden = paused;
        pause.querySelector("[data-when-paused]").hidden = !paused;
        plan();
    }

    pause.addEventListener("click", () => setPaused(!paused));
    document.addEventListener("visibilitychange", plan);

    if ("IntersectionObserver" in window) {
        new IntersectionObserver(([entry]) => {
            inView = entry.isIntersecting;
            plan();
        }, { threshold: 0.15 }).observe(hero);
    }

    setPaused(paused);
})();


// ======================================================
// THINGS THAT APPEAR WHEN THEY SCROLL INTO VIEW (once)
// ======================================================

(function () {
    const waiting = Array.from(document.querySelectorAll("[data-reveal]"));

    if (!("IntersectionObserver" in window)) {
        waiting.forEach(element => element.classList.add("is-visible"));
        return;
    }

    const watcher = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add("is-visible");
                watcher.unobserve(entry.target);
            }
        });
    }, { rootMargin: "0px 0px -12% 0px", threshold: 0.12 });

    waiting.forEach(element => watcher.observe(element));
})();
</script>

<?php require __DIR__ . "/includes/login-modal.php"; ?>

<?php require __DIR__ . "/includes/inbox-widget.php"; ?>
<?php require __DIR__ . "/includes/terms-modal.php"; ?>
</body>
</html>
