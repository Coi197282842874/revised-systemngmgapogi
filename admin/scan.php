<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/admin-shell.php";
require_once __DIR__ . "/../includes/tickets.php";

/*
 * Scan Ticket: the front desk points a camera at a guest's QR code (customer/ticket.php) and sees
 * the booking at once. The QR code holds this page's address with the booking code, so a phone's
 * own camera app opens it too. A code can also be typed, or read from a photo.
 *
 *     scan.php?code=ARV-000032-7F3A9C1B     the booking, drawn by the server
 *     scan.php?api=lookup&code=...          the same, as HTML in JSON, for the camera scanner
 */

const SCAN_LOG_EVERY = 600;   // the same ticket scanned again within 10 minutes is logged once

function scan_verdict(string $tone, string $iconName, string $title, string $text): string
{
    return '<div class="scan-verdict tone-' . $tone . '">'
        . '<span class="scan-verdict-icon">' . icon($iconName) . '</span>'
        . '<div><h2 id="scan-title" tabindex="-1">' . h($title) . '</h2><p>' . h($text) . '</p></div>'
        . '</div>';
}

// What a scanned or typed code says, as the result card's HTML.
function scan_result(PDO $pdo, string $text, bool $log = true): string
{
    $text = mb_substr(trim($text), 0, 300);
    $id = ticket_reservation_id($pdo, $text);
    $again = '<div class="scan-actions"><button class="btn" type="button" data-scan-again>' . icon("scan") . ' Scan another</button></div>';

    if ($id === 0) {
        return scan_verdict("rose", "x-circle", "Not a valid ticket",
            "This code is not from an ARVE'S House ticket, or it was typed wrong. Codes look like ARV-000032-7F3A9C1B.")
            . $again;
    }

    $stmt = $pdo->prepare("
        SELECT r.*, rooms.room_name, u.id AS customer_id, u.full_name, u.email, u.phone, u.profile_image,
               lp.status AS payment_status, lp.payment_method, lp.amount AS payment_amount
        FROM reservations r
        INNER JOIN rooms ON rooms.id = r.room_id
        INNER JOIN users u ON u.id = r.user_id
        LEFT JOIN payments lp ON lp.id = (SELECT MAX(p.id) FROM payments p WHERE p.reservation_id = r.id)
        WHERE r.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $r = $stmt->fetch();

    if (!$r) {
        return scan_verdict("rose", "x-circle", "Reservation not found",
            "The code is real, but reservation #" . $id . " no longer exists.") . $again;
    }

    // ---- is this ticket good today? ----
    $today = date("Y-m-d");
    $checkIn = admin_date($r["check_in"], "D, M j");
    $checkOut = admin_date($r["check_out"], "D, M j");

    if ($r["status"] === "confirmed") {
        if ($today < $r["check_in"]) {
            $days = (int) round((strtotime($r["check_in"]) - strtotime($today)) / 86400);
            $verdict = scan_verdict("green", "check-circle", "Valid ticket",
                "Confirmed. Arrives " . ($days === 1 ? "tomorrow" : "in " . $days . " days") . ", " . $checkIn . ".");
        } elseif ($today < $r["check_out"]) {
            $verdict = scan_verdict("green", "check-circle", "Valid ticket",
                $today === $r["check_in"] ? "Confirmed. Checks in today." : "Confirmed. Staying now, until " . $checkOut . ".");
        } else {
            $verdict = scan_verdict("gray", "clock", "The stay has ended",
                "The stay ended on " . $checkOut . ". Mark it as completed in Reservations.");
        }
    } elseif ($r["status"] === "completed") {
        $verdict = scan_verdict("gray", "check-circle", "Stay completed", "This guest has already stayed and checked out on " . $checkOut . ".");
    } elseif ($r["status"] === "pending") {
        $verdict = scan_verdict("amber", "clock", "Not confirmed yet", "This booking is not paid, or its payment is not verified yet.");
    } else {
        $verdict = scan_verdict("rose", "x-circle", "Not valid", "This reservation is " . $r["status"] . ".");
    }

    // ---- the log (not on every camera frame: once per ticket every 10 minutes) ----
    $seen = $_SESSION["ticket_scans"][$id] ?? 0;

    if ($log && time() - $seen > SCAN_LOG_EVERY) {
        $_SESSION["ticket_scans"][$id] = time();

        log_activity($pdo, "ticket.scanned",
            "Scanned the ticket of " . $r["full_name"] . " (reservation #" . $id . ", " . $r["room_name"] . ", "
                . admin_stay($r["check_in"], $r["check_out"]) . ")",
            ["entity_type" => "reservation", "entity_id" => $id, "link" => "reservations.php?q=" . $id]
        );
    }

    $nights = (int) $r["total_nights"];

    $html = $verdict
        . '<div class="scan-guest">'
        . admin_avatar(["full_name" => $r["full_name"], "profile_image" => $r["profile_image"]], 52)
        . '<div class="scan-guest-text"><strong>' . h($r["full_name"]) . '</strong>'
        . '<span>' . h($r["email"]) . '</span>'
        . ($r["phone"] ? '<a href="tel:' . h(preg_replace('/[^0-9+]/', "", $r["phone"])) . '">' . h($r["phone"]) . '</a>' : "")
        . '</div></div>'
        . '<dl class="scan-facts">'
        . '<div class="is-wide"><dt>Room</dt><dd>' . h($r["room_name"]) . '</dd></div>'
        . '<div><dt>Check-in</dt><dd>' . h(admin_date($r["check_in"], "D, M j, Y")) . '</dd></div>'
        . '<div><dt>Check-out</dt><dd>' . h(admin_date($r["check_out"], "D, M j, Y")) . '</dd></div>'
        . '<div><dt>Nights</dt><dd>' . $nights . '</dd></div>'
        . '<div><dt>Guests</dt><dd>' . (int) $r["guests"] . '</dd></div>'
        . '<div><dt>Total</dt><dd>' . h(peso($r["total_amount"], 2)) . '</dd></div>'
        . '<div><dt>Payment</dt><dd>' . ($r["payment_status"]
            ? status_pill($r["payment_status"]) . ' <span class="soft">' . h(payment_method_label((string) $r["payment_method"])) . '</span>'
            : status_pill("not set up", "Not paid")) . '</dd></div>'
        . '<div><dt>Status</dt><dd>' . status_pill($r["status"]) . '</dd></div>'
        . '<div><dt>Booked</dt><dd>' . h(admin_date($r["created_at"], "M j, Y")) . '</dd></div>'
        . '<div class="is-wide"><dt>Booking code</dt><dd class="mono">' . h(ticket_code($pdo, $id)) . '</dd></div>'
        . '</dl>'
        . '<div class="scan-actions">'
        . '<a class="btn btn-primary" href="reservations.php?q=' . $id . '">' . icon("clipboard") . ' Open in Reservations</a>'
        . (admin_can("messages") ? '<a class="btn" href="messages.php?customer=' . (int) $r["customer_id"] . '">' . icon("message") . ' Message guest</a>' : "")
        . '<button class="btn btn-ghost" type="button" data-scan-again>' . icon("scan") . ' Scan another</button>'
        . '</div>';

    return $html;
}


// ======================================================
// THE ANSWER FOR THE CAMERA SCANNER
// ======================================================

if (($_GET["api"] ?? "") === "lookup") {
    admin_boot_json($pdo, "reservations");

    echo json_encode(["ok" => true, "html" => scan_result($pdo, (string) ($_GET["code"] ?? ""))]);
    exit;
}

$admin = admin_boot($pdo, "reservations");
$code = mb_substr(trim((string) ($_GET["code"] ?? "")), 0, 300);

admin_shell_head([
    "title" => "Scan Ticket",
    "subtitle" => "Check a guest's booking from the QR code on their ticket",
    "active" => "scan",
]);
?>
<style>
    .scan-grid {
        align-items: start;
    }

    .scan-view {
        position: relative;
        overflow: hidden;
        aspect-ratio: 4 / 3;
        margin: 16px 0 12px;
        border-radius: var(--radius-lg);
        background: #0b0f19;
    }

    .scan-view video {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    /* the square to aim at */
    .scan-frame {
        position: absolute;
        top: 50%;
        left: 50%;
        width: min(62%, 260px);
        aspect-ratio: 1;
        border-radius: 18px;
        box-shadow: 0 0 0 999px rgba(0, 0, 0, 0.35);
        transform: translate(-50%, -50%);
        pointer-events: none;
    }

    .scan-frame::before {
        content: "";
        position: absolute;
        inset: 0;
        border: 3px solid rgba(255, 255, 255, 0.92);
        border-radius: inherit;
        -webkit-mask: linear-gradient(#000 0 0) top left / 28% 28% no-repeat, linear-gradient(#000 0 0) top right / 28% 28% no-repeat,
            linear-gradient(#000 0 0) bottom left / 28% 28% no-repeat, linear-gradient(#000 0 0) bottom right / 28% 28% no-repeat;
        mask: linear-gradient(#000 0 0) top left / 28% 28% no-repeat, linear-gradient(#000 0 0) top right / 28% 28% no-repeat,
            linear-gradient(#000 0 0) bottom left / 28% 28% no-repeat, linear-gradient(#000 0 0) bottom right / 28% 28% no-repeat;
    }

    .scan-idle {
        position: absolute;
        inset: 0;
        display: grid;
        place-content: center;
        justify-items: center;
        gap: 10px;
        padding: 20px;
        color: rgba(255, 255, 255, 0.78);
        font-size: 13px;
        text-align: center;
        background: #0b0f19;
    }

    .scan-idle .i {
        font-size: 40px;
    }

    .scan-view.is-live .scan-idle {
        display: none;
    }

    .scan-view:not(.is-live) .scan-frame {
        display: none;
    }

    .scan-buttons {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .scan-buttons label:focus-within {
        outline: 2px solid var(--ring);
        outline-offset: 2px;
    }

    .scan-note {
        min-height: 20px;
        margin: 10px 0 0;
    }

    .scan-note.is-error {
        color: var(--danger);
    }

    .scan-type {
        margin-top: 16px;
        padding-top: 16px;
        border-top: 1px solid var(--line);
    }

    .scan-type form {
        display: flex;
        gap: 8px;
    }

    .scan-type .input {
        flex: 1;
        min-width: 0;
        font-family: var(--mono);
        letter-spacing: 0.03em;
        text-transform: uppercase;
    }

    .scan-type .input::placeholder {
        text-transform: none;
    }

    .scan-result {
        padding: 20px;
    }

    .scan-verdict {
        display: flex;
        align-items: flex-start;
        gap: 14px;
        padding: 16px;
        border-radius: var(--radius);
        background: rgb(from var(--tone) r g b / var(--tone-alpha));
    }

    @supports not (color: rgb(from red r g b / 0.5)) {
        .scan-verdict {
            background: var(--surface-2);
        }
    }

    .scan-verdict-icon {
        display: grid;
        flex: none;
        place-items: center;
        width: 44px;
        height: 44px;
        border-radius: 50%;
        background: var(--tone);
        color: #fff;
        font-size: 22px;
    }

    .scan-verdict h2 {
        margin: 0 0 2px;
        font-size: 19px;
        outline: none;
    }

    .scan-verdict p {
        margin: 0;
        color: var(--text-2);
    }

    .scan-guest {
        display: flex;
        align-items: center;
        gap: 14px;
        margin: 18px 0 14px;
    }

    .scan-guest-text {
        display: grid;
        min-width: 0;
    }

    .scan-guest-text strong {
        font-size: 16px;
    }

    .scan-guest-text span,
    .scan-guest-text a {
        overflow: hidden;
        color: var(--text-3);
        font-size: 13px;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    .scan-facts {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
        margin: 0;
    }

    .scan-facts div {
        padding: 10px 12px;
        border: 1px solid var(--line);
        border-radius: var(--radius);
        background: var(--surface-2);
    }

    .scan-facts dt {
        color: var(--text-3);
        font-size: 11.5px;
        font-weight: 600;
    }

    .scan-facts dd {
        margin: 2px 0 0;
        font-weight: 600;
        overflow-wrap: anywhere;
    }

    .scan-facts .is-wide {
        grid-column: 1 / -1;
    }

    .scan-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 16px;
    }

    @media (max-width: 767px) {
        .scan-type form {
            flex-direction: column;
        }

        .scan-actions .btn {
            flex: 1 1 auto;
        }
    }
</style>
<?php admin_shell_body(); ?>

<div class="grid grid-2 scan-grid">

    <!-- THE SCANNER -->

    <section class="card card-pad">
        <h2 class="card-title">Scan the guest's QR code</h2>
        <p class="card-sub">Guests find it on their ticket once the booking is confirmed.</p>

        <div class="scan-view" data-scan-view>
            <video playsinline muted autoplay data-scan-video></video>
            <div class="scan-frame" aria-hidden="true"></div>
            <div class="scan-idle">
                <?= icon("qr-code") ?>
                <span>Start the camera and hold the QR code inside the square.</span>
            </div>
        </div>

        <div class="scan-buttons">
            <button class="btn btn-primary" type="button" data-scan-start><?= icon("camera") ?> Start camera</button>
            <button class="btn" type="button" data-scan-stop hidden><?= icon("x") ?> Stop camera</button>
            <label class="btn btn-ghost">
                <?= icon("image") ?> Scan a photo
                <input class="sr-only" type="file" accept="image/*" data-scan-photo>
            </label>
        </div>

        <p class="hint scan-note" data-scan-note role="status"></p>

        <div class="scan-type">
            <form method="get" action="scan.php" data-scan-form>
                <label class="sr-only" for="scan-code">Booking code</label>
                <input class="input" id="scan-code" name="code" value="<?= h($code) ?>" placeholder="Type a code, e.g. ARV-000032-7F3A9C1B" autocomplete="off" autocapitalize="characters" spellcheck="false" enterkeyhint="search">
                <button class="btn" type="submit"><?= icon("search") ?> Check</button>
            </form>
        </div>
    </section>


    <!-- WHAT THE TICKET SAYS -->

    <template data-scan-empty>
        <div class="empty">
            <div class="empty-icon"><?= icon("ticket", 22) ?></div>
            <h3>No ticket scanned yet</h3>
            <p>The booking shows here: the guest, the dates, the payment and whether the ticket is valid today.</p>
        </div>
    </template>

    <section class="card scan-result" data-scan-result aria-live="polite">
        <?php if ($code !== ""): ?>
            <?= scan_result($pdo, $code) ?>
        <?php else: ?>
            <div class="empty">
                <div class="empty-icon"><?= icon("ticket", 22) ?></div>
                <h3>No ticket scanned yet</h3>
                <p>The booking shows here: the guest, the dates, the payment and whether the ticket is valid today.</p>
            </div>
        <?php endif; ?>
    </section>

</div>

<script src="<?= h(admin_asset("vendor/jsQR.js")) ?>" defer></script>
<script>
    (function () {
        "use strict";

        var view = document.querySelector("[data-scan-view]");
        var video = document.querySelector("[data-scan-video]");
        var startButton = document.querySelector("[data-scan-start]");
        var stopButton = document.querySelector("[data-scan-stop]");
        var photo = document.querySelector("[data-scan-photo]");
        var note = document.querySelector("[data-scan-note]");
        var form = document.querySelector("[data-scan-form]");
        var result = document.querySelector("[data-scan-result]");
        var emptyHtml = document.querySelector("[data-scan-empty]").innerHTML;

        var canvas = document.createElement("canvas");
        var context = canvas.getContext("2d", { willReadFrequently: true });
        var stream = null;
        var timer = 0;
        var busy = false;
        var detector = null;

        // the browser's own QR reader where there is one (Chrome and Edge on phones); jsQR elsewhere
        if ("BarcodeDetector" in window && BarcodeDetector.getSupportedFormats) {
            BarcodeDetector.getSupportedFormats().then(function (formats) {
                if (formats.indexOf("qr_code") !== -1) {
                    detector = new BarcodeDetector({ formats: ["qr_code"] });
                }
            }).catch(function () {});
        }

        function say(text, isError) {
            note.textContent = text;
            note.classList.toggle("is-error", !!isError);
        }

        function stop() {
            clearTimeout(timer);

            if (stream) {
                stream.getTracks().forEach(function (track) { track.stop(); });
                stream = null;
            }

            video.srcObject = null;
            view.classList.remove("is-live");
            startButton.hidden = false;
            stopButton.hidden = true;
        }

        function start() {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                say("This browser can't use the camera here. Scan a photo, or type the code below.", true);
                return;
            }

            say("Starting the camera…");

            navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: "environment" } }, audio: false })
                .then(function (media) {
                    stream = media;
                    video.srcObject = media;
                    return video.play();
                })
                .then(function () {
                    view.classList.add("is-live");
                    startButton.hidden = true;
                    stopButton.hidden = false;
                    say("Hold the QR code inside the square.");
                    busy = false;
                    look();
                })
                .catch(function (error) {
                    stop();
                    say(error && error.name === "NotAllowedError"
                        ? "The camera was not allowed. Allow it in the browser, or type the code below."
                        : "No camera could be opened. Scan a photo, or type the code below.", true);
                });
        }

        // a few times a second: is there a QR code in the picture?
        function look() {
            if (!stream || busy) return;

            if (video.readyState < 2) {
                timer = setTimeout(look, 150);
                return;
            }

            if (detector) {
                detector.detect(video).then(function (codes) {
                    if (codes.length) {
                        found(codes[0].rawValue);
                    } else {
                        timer = setTimeout(look, 120);
                    }
                }).catch(function () {
                    detector = null;
                    timer = setTimeout(look, 120);
                });
                return;
            }

            var text = readImage(video, video.videoWidth, video.videoHeight, "dontInvert");

            if (text) {
                found(text);
            } else {
                timer = setTimeout(look, 120);
            }
        }

        function readImage(source, width, height, inversion) {
            if (typeof jsQR !== "function" || !width || !height) return "";

            var scale = Math.min(1, 800 / Math.max(width, height));
            canvas.width = Math.round(width * scale);
            canvas.height = Math.round(height * scale);
            context.drawImage(source, 0, 0, canvas.width, canvas.height);

            var code = jsQR(context.getImageData(0, 0, canvas.width, canvas.height).data, canvas.width, canvas.height, {
                inversionAttempts: inversion,
            });

            return code && code.data ? code.data : "";
        }

        function found(text) {
            busy = true;
            stop();

            if (navigator.vibrate) navigator.vibrate(60);

            lookup(text);
        }

        function lookup(text) {
            text = String(text || "").trim();

            if (!text) return;

            say("Checking the ticket…");

            fetch("scan.php?api=lookup&code=" + encodeURIComponent(text), {
                credentials: "same-origin",
                headers: { Accept: "application/json" },
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data.ok) throw new Error(data.error || "failed");

                    result.innerHTML = data.html;
                    say("");

                    var match = text.match(/ARV-?\d{1,9}-?[0-9A-F]{8}/i);
                    history.replaceState(null, "", "scan.php" + (match ? "?code=" + encodeURIComponent(match[0].toUpperCase()) : ""));

                    var title = document.getElementById("scan-title");

                    if (title) {
                        title.focus({ preventScroll: true });
                    }

                    if (window.matchMedia("(max-width: 1023px)").matches) {
                        result.scrollIntoView({ behavior: "smooth", block: "start" });
                    }
                })
                .catch(function () {
                    say("The ticket could not be checked. Check the connection and try again.", true);
                });
        }

        startButton.addEventListener("click", start);
        stopButton.addEventListener("click", function () { stop(); say(""); });

        // a photo of the QR code (from the gallery, or taken now)
        photo.addEventListener("change", function () {
            var file = photo.files && photo.files[0];
            if (!file) return;

            var image = new Image();
            var address = URL.createObjectURL(file);

            image.onload = function () {
                var text = readImage(image, image.naturalWidth, image.naturalHeight, "attemptBoth");
                URL.revokeObjectURL(address);
                photo.value = "";

                if (text) {
                    lookup(text);
                } else {
                    say("No QR code was found in that photo. Try a closer, sharper one, or type the code.", true);
                }
            };

            image.onerror = function () {
                URL.revokeObjectURL(address);
                say("That file could not be opened as a photo.", true);
            };

            image.src = address;
        });

        // a typed code: checked here without leaving the page
        form.addEventListener("submit", function (event) {
            event.preventDefault();
            lookup(form.elements.code.value);
        });

        // "Scan another"
        result.addEventListener("click", function (event) {
            if (!event.target.closest("[data-scan-again]")) return;

            result.innerHTML = emptyHtml;
            form.elements.code.value = "";
            history.replaceState(null, "", "scan.php");
            start();
        });

        // the camera is let go when the page is not looked at
        document.addEventListener("visibilitychange", function () {
            if (document.hidden && stream) {
                stop();
                say("The camera stopped while the page was hidden.");
            }
        });

        window.addEventListener("pagehide", stop);
    })();
</script>

<?php admin_shell_end(); ?>
