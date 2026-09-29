<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/icons.php";

// The default house emoji shows as the site's line icon (same as the homepage)
function hero_icon_html(string $value): string
{
    return in_array(trim($value), ["🏡", "🏠", "⌂", ""], true) ? icon("home") : htmlspecialchars($value);
}

if (!isset($_SESSION["user_id"]) || ($_SESSION["role"] ?? "") !== "admin") {
    header("Location: ../login.php");
    exit;
}

$defaults = [
    "hero_card_icon" => "🏡",
    "hero_card_title" => "ARVE'S House",
    "hero_card_subtitle" => "Simple stay. Greater memories.",
    "hero_card_bg_start" => "#c9a27f",
    "hero_card_bg_end" => "#7b5841"
];

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS site_settings (
        setting_key VARCHAR(100) NOT NULL,
        setting_value TEXT NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$settings = $defaults;
$stmt = $pdo->query("SELECT setting_key, setting_value FROM site_settings");
foreach ($stmt->fetchAll() as $setting) {
    if (array_key_exists($setting["setting_key"], $settings)) {
        $settings[$setting["setting_key"]] = $setting["setting_value"];
    }
}

$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
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

    if ($submitted["hero_card_icon"] === "" || $submitted["hero_card_title"] === "" || $submitted["hero_card_subtitle"] === "") {
        $error = "Icon, title, and subtitle are required.";
    } elseif (!$validColors($submitted["hero_card_bg_start"]) || !$validColors($submitted["hero_card_bg_end"])) {
        $error = "Please select valid six-digit hex colors.";
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO site_settings (setting_key, setting_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );

        foreach ($submitted as $key => $value) {
            $stmt->execute([$key, $value]);
        }

        $settings = $submitted;
        $message = "Homepage card settings saved.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#f3f4f6">
    <title>Homepage Settings | ARVE'S House</title>
    <style>
        :root { --ease-out: cubic-bezier(0.23, 1, 0.32, 1); --ease-in-out: cubic-bezier(0.77, 0, 0.175, 1); --ease-drawer: cubic-bezier(0.32, 0.72, 0, 1); }
        * { box-sizing: border-box; }
        html { -webkit-tap-highlight-color: transparent; -webkit-text-size-adjust: 100%; text-size-adjust: 100%; }
        body { margin: 0; padding: 35px 20px; padding: max(35px, env(safe-area-inset-top, 0px)) max(20px, env(safe-area-inset-right, 0px)) max(35px, env(safe-area-inset-bottom, 0px)) max(20px, env(safe-area-inset-left, 0px)); font-family: Arial, sans-serif; background: #f3f4f6; color: #1f2937; }
        .page { max-width: 900px; margin: 0 auto; }
        .topbar { display: flex; justify-content: space-between; align-items: center; gap: 15px; margin-bottom: 25px; }
        .topbar a { color: #2563eb; text-decoration: none; touch-action: manipulation; }
        .layout { display: grid; grid-template-columns: 1fr 1fr; gap: 25px; align-items: start; }
        .panel { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,.08); }
        label { display: block; margin: 16px 0 7px; font-weight: bold; }
        input { width: 100%; padding: 11px; border: 1px solid #d1d5db; border-radius: 7px; font-size: 15px; }
        input[type="color"] { height: 46px; padding: 4px; cursor: pointer; }
        button { margin-top: 22px; border: 0; border-radius: 7px; padding: 12px 18px; background: #2563eb; color: white; font-weight: bold; cursor: pointer; touch-action: manipulation; -webkit-user-select: none; user-select: none; transition: transform 140ms var(--ease-out); }
        button:active:not(:disabled) { transform: scale(0.97); }
        .message { padding: 12px; border-radius: 7px; margin-bottom: 15px; background: #d1fae5; color: #065f46; animation: alert-in 240ms var(--ease-out) both; }
        .error { padding: 12px; border-radius: 7px; margin-bottom: 15px; background: #fee2e2; color: #991b1b; animation: alert-in 240ms var(--ease-out) both; }
        @keyframes alert-in { from { opacity: 0; transform: translateY(-4px); } }
        .preview-card { min-height: 280px; display: grid; place-items: center; border-radius: 20px; padding: 25px; color: white; text-align: center; background: linear-gradient(145deg, <?= htmlspecialchars($settings["hero_card_bg_start"]) ?>, <?= htmlspecialchars($settings["hero_card_bg_end"]) ?>); }
        .preview-icon { font-size: 72px; }
        .preview-card h2 { margin: 12px 0 8px; font-family: Georgia, serif; }
        .preview-card p { margin: 0; }
        @media (max-width: 700px) { .layout { grid-template-columns: 1fr; } .topbar { align-items: flex-start; flex-direction: column; } }
        @media (pointer: coarse) { input, select, textarea { font-size: 16px; } }
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            /* no press scale, no alert slide */
            button, .message, .error { transform: none !important; }
            *, *::before, *::after { animation-duration: 1ms !important; animation-iteration-count: 1 !important; }
        }
    </style>
<?php $glassTheme = "admin"; require __DIR__ . "/../includes/glass.php"; ?>
</head>
<body>
    <main class="page">
        <div class="topbar">
            <div>
                <h1>Homepage Settings</h1>
                <p>Edit the homepage hero card.</p>
            </div>
            <a href="dashboard.php">Back to Dashboard</a>
        </div>

        <div class="layout">
            <section class="panel">
                <?php if ($message !== ""): ?><div class="message"><?= htmlspecialchars($message) ?></div><?php endif; ?>
                <?php if ($error !== ""): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

                <form method="post">
                    <label for="hero_card_icon">House icon or emoji</label>
                    <input id="hero_card_icon" name="hero_card_icon" value="<?= htmlspecialchars($settings["hero_card_icon"]) ?>" required>

                    <label for="hero_card_title">Title</label>
                    <input id="hero_card_title" name="hero_card_title" value="<?= htmlspecialchars($settings["hero_card_title"]) ?>" required>

                    <label for="hero_card_subtitle">Subtitle</label>
                    <input id="hero_card_subtitle" name="hero_card_subtitle" value="<?= htmlspecialchars($settings["hero_card_subtitle"]) ?>" required>

                    <label for="hero_card_bg_start">Gradient start color</label>
                    <input type="color" id="hero_card_bg_start" name="hero_card_bg_start" value="<?= htmlspecialchars($settings["hero_card_bg_start"]) ?>" required>

                    <label for="hero_card_bg_end">Gradient end color</label>
                    <input type="color" id="hero_card_bg_end" name="hero_card_bg_end" value="<?= htmlspecialchars($settings["hero_card_bg_end"]) ?>" required>

                    <button type="submit">Save Homepage Card</button>
                </form>
            </section>

            <section class="panel">
                <h2>Live Preview</h2>
                <div class="preview-card" id="preview-card">
                    <div>
                        <div class="preview-icon" id="preview-icon"><?= hero_icon_html($settings["hero_card_icon"]) ?></div>
                        <h2 id="preview-title"><?= htmlspecialchars($settings["hero_card_title"]) ?></h2>
                        <p id="preview-subtitle"><?= htmlspecialchars($settings["hero_card_subtitle"]) ?></p>
                    </div>
                </div>
            </section>
        </div>
    </main>

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
</body>
</html>
