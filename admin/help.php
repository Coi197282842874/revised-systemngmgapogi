<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/admin-shell.php";

// ======================================================
// HELP & SUPPORT: how this panel works, in questions and answers
// Open to every admin. The answers describe what the pages really do: when a page changes,
// change its answer here too.
// ======================================================

$admin = admin_boot($pdo);

/*
 * Each topic: title, icon, tone, the page it is about (its permission decides whether the
 * "Open ..." button shows), and its questions. An answer is a list of paragraphs; a paragraph
 * that is itself a list becomes numbered steps.
 */
$topics = [
    [
        "title" => "Reservations",
        "icon" => "clipboard",
        "tone" => "blue",
        "page" => ["reservations.php", "reservations", "Open Reservations"],
        "questions" => [
            [
                "How does a booking become confirmed?",
                [
                    "A guest picks a room and dates on the website. The reservation starts as Pending and holds those dates.",
                    "It becomes Confirmed by itself as soon as its payment is verified: online payments verify themselves, other payments are verified by you on the Payments page.",
                ],
            ],
            [
                "When can I decline a reservation?",
                [
                    "While it is Pending and has no verified payment. If a payment is waiting to be checked, the row shows \"Check payment\" instead: look at the payment first.",
                    "Declining opens the dates for other guests and cannot be undone. The guest would have to book again.",
                ],
            ],
            [
                "When do I press Complete?",
                [
                    "After the guest has checked out. Completed stays are the ones counted as \"guests who have stayed\", and they stay in your reports.",
                ],
            ],
            [
                "A guest cancelled. What happened to the dates?",
                [
                    "Guests can cancel their own reservation from their account while it is Pending and not paid. You get a notification, and the dates are open again at once.",
                ],
            ],
            [
                "How do I find one reservation quickly?",
                [
                    "Use the search box at the top of any page, or the one on the Reservations page. Type the guest's name or email, the room's name, or the number with a hash, like #12.",
                ],
            ],
        ],
    ],
    [
        "title" => "Payments",
        "icon" => "credit-card",
        "tone" => "green",
        "page" => ["payment.php", "payments", "Open Payments"],
        "questions" => [
            [
                "How do I verify a payment?",
                [
                    [
                        "Open Payments and choose the Pending tab.",
                        "Compare the reference number and the amount with what arrived in your GCash or bank.",
                        "Press Verify. The reservation becomes Confirmed and the guest sees it in their account.",
                    ],
                    "Press Details on a row to copy the reference number.",
                ],
            ],
            [
                "What happens when I reject a payment?",
                [
                    "The payment is marked Rejected and cannot be brought back. The reservation stays Pending, and the guest can send a new payment for it.",
                ],
            ],
            [
                "What about online payments (GCash, Maya, cards through PayMongo)?",
                [
                    "They confirm themselves: when PayMongo says the guest paid, the payment is verified and the reservation confirmed without anyone pressing a button. If the guest leaves before paying, the attempt is cancelled.",
                    "Integrations shows whether PayMongo is in test mode (no real money) or live.",
                ],
            ],
        ],
    ],
    [
        "title" => "Rooms and the calendar",
        "icon" => "bed",
        "tone" => "cyan",
        "page" => ["rooms.php", "rooms", "Open Rooms"],
        "questions" => [
            [
                "How do I add a room and its photos?",
                [
                    [
                        "Open Rooms and press Add room. Fill in the name, how many guests it holds and the price per night.",
                        "On the room's card press Photos and upload up to 6 photos (JPG, PNG or WebP, up to 5 MB each).",
                        "The photo marked First is the one guests see first. Press \"Make first\" on another photo to change it.",
                    ],
                ],
            ],
            [
                "What do the room statuses mean?",
                [
                    "Available: guests see the room and can book it. Maintenance and Inactive: the room is hidden from the website and cannot be booked. Use Maintenance for repairs and Inactive for a room you no longer offer.",
                    "Changing the status never cancels reservations that already exist.",
                ],
            ],
            [
                "Why can't I delete a room?",
                [
                    "A room that has reservations stays, because its bookings and payments point to it. Set it to Inactive instead: guests no longer see it, and your history stays complete.",
                ],
            ],
            [
                "How do I stop bookings on certain dates?",
                [
                    "Open Calendar and use \"Close dates\": choose one room or all rooms, the first and the last date, and a reason if you like (only admins see it).",
                    "Reservations that already exist on those dates are kept, and you are told how many there are. To open the dates again, press Open next to them under \"Upcoming closed dates\".",
                ],
            ],
        ],
    ],
    [
        "title" => "Customers and messages",
        "icon" => "users",
        "tone" => "violet",
        "page" => ["customers.php", "customers", "Open Customers"],
        "questions" => [
            [
                "What does turning off a customer's account do?",
                [
                    "The customer can no longer log in. Their reservations and payments stay exactly as they are, and you can turn the account on again at any time.",
                    "Someone who is logged in at that moment stays in until they log out or their session ends.",
                ],
            ],
            [
                "How do messages work?",
                [
                    "It is an inbox, not a live chat. A customer writes from their account; you get a notification and the conversation shows under Messages. They see your reply the next time they open their messages.",
                    "Several messages in a row make one notification, not one for each.",
                    "To write to a customer first, open Customers and press Message on their row.",
                ],
            ],
            [
                "What do \"Google\", \"Email\" and \"Not verified\" mean next to a customer?",
                [
                    "How the customer signs in: with their Google account, or with an email address and a password. \"Not verified\" means they have not yet typed the 6-digit code that was sent to their email.",
                ],
            ],
        ],
    ],
    [
        "title" => "Admins and roles",
        "icon" => "shield",
        "tone" => "amber",
        "page" => ["admins.php", "admins", "Open Roles & Permissions"],
        "questions" => [
            [
                "What are the three roles?",
                [
                    "Super Admin opens and changes everything. Manager runs the house day to day. Front Desk looks after guests: bookings, payments and messages.",
                    "On Roles & Permissions a Super Admin ticks what Manager and Front Desk can open. The Dashboard, Notifications, this Help page and one's own password are open to every admin.",
                ],
            ],
            [
                "How do I give someone access, or take it away?",
                [
                    "Roles & Permissions → Add admin: their name, email, a password and a role. They log in on the normal login page.",
                    "To take access away, press \"Turn off\" on their row. If they are logged in, they are logged out on their next click. Their past actions stay in the Activity Logs.",
                ],
            ],
            [
                "I forgot my password.",
                [
                    "Ask a Super Admin to add a new admin account for you and to turn off the old one. If the only Super Admin forgot theirs, the person who looks after the website can set a new one in the database.",
                    "That is why a second Super Admin is a good idea.",
                ],
            ],
        ],
    ],
    [
        "title" => "Reports, logs and backups",
        "icon" => "chart",
        "tone" => "rose",
        "page" => ["analytics.php", "analytics", "Open Analytics"],
        "questions" => [
            [
                "How are the numbers in Analytics counted?",
                [
                    "Revenue is the sum of verified payments, on the day each payment was made. A night counts as booked when its reservation is Confirmed or Completed. Occupancy is booked nights divided by the nights your available rooms could have been booked.",
                    "Every period ends today and is compared with the same number of days just before it.",
                ],
            ],
            [
                "What is written in the Activity Logs?",
                [
                    "What guests do (new accounts, bookings, payments, cancellations, messages) and what admins do (logins, verified and rejected payments, declined and completed reservations, rooms, closed dates, roles, settings, backups), each with the time and the person.",
                ],
            ],
            [
                "How do I make a backup?",
                [
                    "System Health → Download a backup. You get one file with the whole database. Keep it private: it holds your customers' details.",
                    "Photos are not in the file. Copy the \"uploads\" folder from the server to keep them too.",
                ],
            ],
        ],
    ],
    [
        "title" => "Using this panel",
        "icon" => "dashboard",
        "tone" => "blue",
        "page" => ["dashboard.php", "", "Open the Dashboard"],
        "questions" => [
            [
                "How do I switch between dark and light?",
                [
                    "Press the sun or moon button at the top right. The choice is remembered on that phone or computer.",
                ],
            ],
            [
                "Are there keyboard shortcuts?",
                [
                    "Press / (slash) or Ctrl + K to jump to the search box, the arrow keys to move through the results, Enter to open one and Esc to close whatever is open.",
                ],
            ],
            [
                "What makes a notification?",
                [
                    "A new booking, a payment that waits for you, an online payment, a cancellation by a guest, a new customer and a new message. Press the bell to see them; opening one marks it as read for every admin.",
                ],
            ],
            [
                "How do I use it on my phone?",
                [
                    "The bar at the bottom holds the pages used most. \"More\" opens the full menu; swipe it to the left or tap beside it to close it.",
                ],
            ],
        ],
    ],
];

$count = array_sum(array_map(fn ($topic) => count($topic["questions"]), $topics));

admin_shell_head([
    "title" => "Help & Support",
    "subtitle" => $count . " answers about how this panel works",
    "active" => "help",
]);
?>
<style>
    .help-search {
        max-width: 560px;
        margin-bottom: 20px;
    }

    .help-search .input {
        min-height: 48px;
        padding-left: 42px;
        background: var(--surface);
        font-size: 15px;
    }

    .help-search .i {
        left: 15px;
        width: 18px;
        height: 18px;
    }

    .topic-head {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 16px 20px;
        border-bottom: 1px solid var(--line);
    }

    .topic-head .tile-icon {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        font-size: 17px;
    }

    .topic-head h2 {
        flex: 1;
        min-width: 0;
        font-size: 15px;
        font-weight: 700;
    }

    .shortcuts {
        display: grid;
        gap: 10px;
        font-size: 13px;
    }

    .shortcuts div {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }

    kbd {
        display: inline-block;
        min-width: 26px;
        padding: 2px 7px;
        border: 1px solid var(--line-2);
        border-bottom-width: 2px;
        border-radius: 6px;
        background: var(--surface-2);
        font-family: var(--font);
        font-size: 12px;
        font-weight: 600;
        text-align: center;
    }

    @media (max-width: 767px) {
        .topic-head {
            padding: 14px 16px;
        }

        .topic-head .btn span {
            display: none;
        }
    }
</style>
<?php admin_shell_body(); ?>

<div class="filter-search help-search">
    <?= icon("search") ?>
    <label class="sr-only" for="help-filter">Search the answers</label>
    <input class="input" type="search" id="help-filter" placeholder="What do you need help with?" autocomplete="off" enterkeyhint="search">
</div>

<div class="grid grid-main">

    <div class="col" id="topics">

        <?php foreach ($topics as $topic): ?>
            <?php [$href, $permission, $label] = $topic["page"]; ?>
            <section class="card" data-topic>
                <div class="topic-head">
                    <span class="tile-icon tone-<?= h($topic["tone"]) ?>"><?= icon($topic["icon"]) ?></span>
                    <h2><?= h($topic["title"]) ?></h2>
                    <?php if (admin_can($permission) && is_file(__DIR__ . "/" . $href)): ?>
                        <a class="btn btn-sm btn-ghost" href="<?= h($href) ?>" aria-label="<?= h($label) ?>"><span><?= h($label) ?></span> <?= icon("arrow-right") ?></a>
                    <?php endif; ?>
                </div>

                <?php foreach ($topic["questions"] as [$question, $answer]): ?>
                    <details class="faq" data-question>
                        <summary><span><?= h($question) ?></span><?= icon("chevron-down", 16) ?></summary>
                        <div class="faq-body">
                            <?php foreach ($answer as $part): ?>
                                <?php if (is_array($part)): ?>
                                    <ol>
                                        <?php foreach ($part as $step): ?>
                                            <li><?= h($step) ?></li>
                                        <?php endforeach; ?>
                                    </ol>
                                <?php else: ?>
                                    <p><?= h($part) ?></p>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>

        <section class="card" id="no-answers" hidden>
            <div class="empty">
                <div class="empty-icon"><?= icon("help", 22) ?></div>
                <h3>No answer has those words</h3>
                <p>Try fewer or other words, like "payment", "photos" or "password".</p>
            </div>
        </section>

    </div>


    <div class="col">

        <section class="card card-pad">
            <h2 class="card-title" style="margin-bottom:14px">Keyboard shortcuts</h2>

            <div class="shortcuts">
                <div><span>Search</span><span><kbd>/</kbd> or <kbd>Ctrl</kbd> <kbd>K</kbd></span></div>
                <div><span>Move through results and menus</span><span><kbd>↑</kbd> <kbd>↓</kbd></span></div>
                <div><span>Open the chosen result</span><kbd>Enter</kbd></div>
                <div><span>Close a menu or a window</span><kbd>Esc</kbd></div>
                <div><span>Read a chart value by value</span><span><kbd>←</kbd> <kbd>→</kbd></span></div>
            </div>
        </section>

        <section class="card card-pad">
            <h2 class="card-title">Something is not working?</h2>
            <p class="card-sub" style="margin-bottom:14px">What helps the person who looks after the website</p>

            <div class="faq-body" style="padding:0">
                <ol>
                    <li>Write down what you pressed and what you expected to happen.</li>
                    <li>Take a screenshot of the page, with the message if there is one.</li>
                    <li>Note the time. The Activity Logs show what happened around it.</li>
                </ol>
            </div>

            <div class="form-actions" style="margin-top:16px">
                <?php if (admin_can("system") && is_file(__DIR__ . "/system_health.php")): ?>
                    <a class="btn btn-sm" href="system_health.php"><?= icon("heart-pulse") ?> System Health</a>
                <?php endif; ?>
                <?php if (admin_can("activity") && is_file(__DIR__ . "/activity.php")): ?>
                    <a class="btn btn-sm" href="activity.php"><?= icon("file-text") ?> Activity Logs</a>
                <?php endif; ?>
                <a class="btn btn-sm btn-ghost" href="../index.php" target="_blank" rel="noopener"><?= icon("external-link") ?> View website</a>
            </div>
        </section>

    </div>

</div>

<script>
    // Typing in the box keeps the questions that have every word, and opens them when few are left.
    (function () {
        var box = document.getElementById("help-filter");
        var topics = Array.prototype.slice.call(document.querySelectorAll("[data-topic]"));
        var none = document.getElementById("no-answers");

        box.addEventListener("input", function () {
            var words = box.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
            var shown = 0;

            topics.forEach(function (topic) {
                var title = topic.querySelector("h2").textContent.toLowerCase();
                var inTopic = 0;

                Array.prototype.forEach.call(topic.querySelectorAll("[data-question]"), function (question) {
                    var text = title + " " + question.textContent.toLowerCase();
                    var match = words.every(function (word) { return text.indexOf(word) !== -1; });

                    question.hidden = !match;
                    if (match) inTopic++;
                });

                topic.hidden = inTopic === 0;
                shown += inTopic;
            });

            Array.prototype.forEach.call(document.querySelectorAll("[data-question]"), function (question) {
                question.open = words.length > 0 && shown <= 3 && !question.hidden;
            });

            none.hidden = shown > 0;
        });
    })();
</script>

<?php admin_shell_end(); ?>
