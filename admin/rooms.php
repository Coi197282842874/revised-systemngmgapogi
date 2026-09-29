<?php

session_start();

require_once __DIR__ . "/../config/database.php";

// Only admins can access this page
if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "admin") {
    header("Location: login.php");
    exit;
}

$message = "";
$messageType = "";

// ADD ROOM
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_room"])) {

    $roomName = trim($_POST["room_name"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $capacity = (int) ($_POST["capacity"] ?? 0);
    $price = (float) ($_POST["price"] ?? 0);
    $status = $_POST["status"] ?? "available";

    $allowedStatuses = [
        "available",
        "maintenance",
        "inactive"
    ];

    if (
        empty($roomName) ||
        $capacity < 1 ||
        $price < 0 ||
        !in_array($status, $allowedStatuses, true)
    ) {

        $message = "Please enter valid room information.";
        $messageType = "error";

    } else {

        $stmt = $pdo->prepare(
            "INSERT INTO rooms
            (room_name, description, capacity, price, status)
            VALUES (?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $roomName,
            $description,
            $capacity,
            $price,
            $status
        ]);

        $message = "Room added successfully.";
        $messageType = "success";
    }
}


// DELETE ROOM
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_room"])) {

    $roomId = (int) $_POST["room_id"];

    try {

        $stmt = $pdo->prepare(
            "DELETE FROM rooms WHERE id = ?"
        );

        $stmt->execute([$roomId]);

        $message = "Room deleted successfully.";
        $messageType = "success";

    } catch (PDOException $e) {

        $message = "This room cannot be deleted because it has reservation records.";
        $messageType = "error";
    }
}


// GET ROOMS
$rooms = $pdo
    ->query(
        "SELECT *
         FROM rooms
         ORDER BY id DESC"
    )
    ->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0, viewport-fit=cover"
    >

    <meta
        name="theme-color"
        content="#211914"
    >

    <title>Manage Rooms | ARVE'S House</title>

    <style>

        :root {
            /* motion tokens (Emil Kowalski curves) */
            --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            --ease-in-out: cubic-bezier(0.77, 0, 0.175, 1);
            --ease-drawer: cubic-bezier(0.32, 0.72, 0, 1);
        }

        * {
            box-sizing: border-box;
        }

        html {
            -webkit-tap-highlight-color: transparent;

            -webkit-text-size-adjust: 100%;

            text-size-adjust: 100%;
        }

        /* controls: no double-tap zoom delay, no long-press text selection */
        button,
        .button,
        .delete-button {
            touch-action: manipulation;

            -webkit-user-select: none;

            user-select: none;
        }

        body {
            margin: 0;

            font-family: Arial, sans-serif;

            background: #f5f2ed;

            color: #333;
        }

        .navbar {
            min-height: 70px;

            min-height: calc(70px + env(safe-area-inset-top, 0px));

            background: #211914;

            color: white;

            display: flex;

            align-items: center;

            justify-content: space-between;

            padding: 0 30px;

            /* viewport-fit=cover: keep bar content clear of the notch / status bar */
            padding-top: env(safe-area-inset-top, 0px);

            padding-left: max(30px, env(safe-area-inset-left, 0px));

            padding-right: max(30px, env(safe-area-inset-right, 0px));
        }

        .brand {
            font-size: 21px;
            font-weight: bold;
        }

        .nav-links {
            display: flex;
            gap: 10px;
        }

        .nav-links a {
            color: white;

            text-decoration: none;

            padding: 9px 14px;

            border-radius: 7px;

            touch-action: manipulation;

            transition:
                transform 140ms var(--ease-out),
                background-color 180ms ease;
        }

        @media (hover: hover) and (pointer: fine) {

            .nav-links a:hover {
                background: rgba(255,255,255,.1);
            }

        }

        .nav-links a:focus-visible {
            background: rgba(255,255,255,.1);
        }

        .nav-links a:active {
            transform: scale(0.97);
        }

        .container {
            max-width: 1200px;

            margin: 35px auto;

            padding: 0 20px;

            padding-left: max(20px, env(safe-area-inset-left, 0px));

            padding-right: max(20px, env(safe-area-inset-right, 0px));
        }

        .header {
            margin-bottom: 25px;
        }

        .header h1 {
            margin-bottom: 5px;
        }

        .header p {
            color: #777;
        }

        .form-card,
        .rooms-card {
            background: white;

            border-radius: 14px;

            padding: 25px;

            margin-bottom: 25px;

            box-shadow:
                0 5px 20px rgba(0,0,0,.07);
        }

        .form-card h2,
        .rooms-card h2 {
            margin-top: 0;

            color: #4b3025;
        }

        .form-grid {
            display: grid;

            grid-template-columns:
                repeat(2, 1fr);

            gap: 18px;
        }

        .form-group {
            margin-bottom: 5px;
        }

        .full {
            grid-column: 1 / -1;
        }

        label {
            display: block;

            margin-bottom: 7px;

            font-weight: bold;
        }

        input,
        textarea,
        select {
            width: 100%;

            padding: 12px;

            border: 1px solid #ccc;

            border-radius: 8px;

            font-size: 15px;

            outline: none;
        }

        /* touch screens: 16px stops iOS zooming into the field (desktop keeps 15px) */
        @media (pointer: coarse) {

            input,
            textarea,
            select {
                font-size: 16px;
            }

        }

        textarea {
            min-height: 100px;

            resize: vertical;
        }

        input:focus,
        textarea:focus,
        select:focus {
            border-color: #8b5e3c;
        }

        .button {
            border: none;

            padding: 12px 20px;

            border-radius: 8px;

            background: #6f4e37;

            color: white;

            font-size: 15px;

            font-weight: bold;

            cursor: pointer;

            transition:
                transform 140ms var(--ease-out),
                background-color 180ms ease;
        }

        @media (hover: hover) and (pointer: fine) {

            .button:hover {
                background: #563a29;
            }

        }

        .button:focus-visible {
            background: #563a29;
        }

        .button:active:not(:disabled) {
            transform: scale(0.97);
        }

        .message {
            padding: 13px;

            border-radius: 8px;

            margin-bottom: 20px;

            /* one-time entrance: result of the add/delete POST draws the eye */
            animation: message-in 240ms var(--ease-out) both;
        }

        @keyframes message-in {
            from {
                opacity: 0;

                transform: translateY(-4px);
            }
        }

        @keyframes message-fade {
            from {
                opacity: 0;
            }
        }

        .message.success {
            background: #e4f6e8;

            color: #167532;
        }

        .message.error {
            background: #ffe5e5;

            color: #b00020;
        }

        .table-wrapper {
            overflow-x: auto;

            /* sideways table swipe must not trigger browser back/forward */
            overscroll-behavior-x: contain;
        }

        table {
            width: 100%;

            border-collapse: collapse;
        }

        th,
        td {
            padding: 14px;

            border-bottom: 1px solid #eee;

            text-align: left;
        }

        th {
            background: #f8f5f1;

            color: #4b3025;
        }

        .status {
            display: inline-block;

            padding: 5px 10px;

            border-radius: 20px;

            font-size: 12px;

            font-weight: bold;
        }

        .available {
            background: #e4f6e8;

            color: #167532;
        }

        .maintenance {
            background: #fff2cc;

            color: #8a6500;
        }

        .inactive {
            background: #ffe5e5;

            color: #b00020;
        }

        .delete-button {
            border: none;

            padding: 7px 11px;

            border-radius: 6px;

            background: #b00020;

            color: white;

            cursor: pointer;

            transition:
                transform 140ms var(--ease-out),
                background-color 180ms ease;
        }

        @media (hover: hover) and (pointer: fine) {

            .delete-button:hover {
                background: #870018;
            }

        }

        .delete-button:focus-visible {
            background: #870018;
        }

        .delete-button:active:not(:disabled) {
            transform: scale(0.97);
        }

        .empty {
            text-align: center;

            padding: 30px;

            color: #777;
        }

        @media (max-width: 700px) {

            .form-grid {
                grid-template-columns: 1fr;
            }

            .full {
                grid-column: auto;
            }

            .navbar {
                padding: 15px;

                padding-top: calc(15px + env(safe-area-inset-top, 0px));

                padding-left: max(15px, env(safe-area-inset-left, 0px));

                padding-right: max(15px, env(safe-area-inset-right, 0px));

                flex-direction: column;

                gap: 12px;
            }

        }

        /* reduced motion: fewer/gentler, not zero - keep fades and color, drop movement */
        @media (prefers-reduced-motion: reduce) {

            html {
                scroll-behavior: auto;
            }

            .nav-links a,
            .button,
            .delete-button {
                transform: none !important;
            }

            *,
            *::before,
            *::after {
                animation-duration: 1ms !important;

                animation-iteration-count: 1 !important;
            }

            /* flash message: opacity fade only, no slide */
            .message {
                animation-name: message-fade;

                animation-duration: 200ms !important;
            }

        }

    </style>

<?php $glassTheme = "admin"; require __DIR__ . "/../includes/glass.php"; ?>
</head>

<body>

<nav class="navbar">

    <div class="brand">
        ARVE'S House — Admin
    </div>

    <div class="nav-links">

        <a href="dashboard.php">
            Dashboard
        </a>

        <a href="../logout.php">
            Logout
        </a>

    </div>

</nav>


<main class="container">

    <div class="header">

        <h1>Manage Rooms</h1>

        <p>
            Add and manage the rooms available for reservation.
        </p>

    </div>


    <?php if (!empty($message)): ?>

        <div class="message <?= $messageType ?>">

            <?= htmlspecialchars($message) ?>

        </div>

    <?php endif; ?>


    <!-- ADD ROOM -->

    <div class="form-card">

        <h2>Add New Room</h2>

        <form method="POST">

            <div class="form-grid">

                <div class="form-group">

                    <label for="room_name">
                        Room Name
                    </label>

                    <input
                        type="text"
                        id="room_name"
                        name="room_name"
                        placeholder="Example: Family Room"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="capacity">
                        Guest Capacity
                    </label>

                    <input
                        type="number"
                        id="capacity"
                        name="capacity"
                        inputmode="numeric"
                        min="1"
                        placeholder="Example: 4"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="price">
                        Price Per Night (₱)
                    </label>

                    <input
                        type="number"
                        id="price"
                        name="price"
                        inputmode="decimal"
                        min="0"
                        step="0.01"
                        placeholder="Example: 1500"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="status">
                        Status
                    </label>

                    <select
                        id="status"
                        name="status"
                    >

                        <option value="available">
                            Available
                        </option>

                        <option value="maintenance">
                            Maintenance
                        </option>

                        <option value="inactive">
                            Inactive
                        </option>

                    </select>

                </div>


                <div class="form-group full">

                    <label for="description">
                        Description
                    </label>

                    <textarea
                        id="description"
                        name="description"
                        placeholder="Describe the room, amenities, beds, etc."
                    ></textarea>

                </div>


                <div class="form-group full">

                    <button
                        class="button"
                        type="submit"
                        name="add_room"
                    >
                        + Add Room
                    </button>

                </div>

            </div>

        </form>

    </div>


    <!-- ROOM LIST -->

    <div class="rooms-card">

        <h2>Rooms</h2>

        <?php if (count($rooms) === 0): ?>

            <div class="empty">

                No rooms have been added yet.

            </div>

        <?php else: ?>

            <div class="table-wrapper">

                <table>

                    <thead>

                        <tr>

                            <th>ID</th>

                            <th>Room</th>

                            <th>Capacity</th>

                            <th>Price/Night</th>

                            <th>Status</th>

                            <th>Action</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($rooms as $room): ?>

                            <tr>

                                <td>
                                    <?= (int) $room["id"] ?>
                                </td>

                                <td>

                                    <strong>
                                        <?= htmlspecialchars($room["room_name"]) ?>
                                    </strong>

                                    <br>

                                    <small>
                                        <?= htmlspecialchars($room["description"]) ?>
                                    </small>

                                </td>

                                <td>
                                    <?= (int) $room["capacity"] ?> guests
                                </td>

                                <td>
                                    ₱<?= number_format((float) $room["price"], 2) ?>
                                </td>

                                <td>

                                    <span
                                        class="status <?= htmlspecialchars($room["status"]) ?>"
                                    >
                                        <?= ucfirst(htmlspecialchars($room["status"])) ?>
                                    </span>

                                </td>

                                <td>

                                    <form
                                        method="POST"
                                        onsubmit="return confirm('Are you sure you want to delete this room?');"
                                    >

                                        <input
                                            type="hidden"
                                            name="room_id"
                                            value="<?= (int) $room["id"] ?>"
                                        >

                                        <button
                                            type="submit"
                                            name="delete_room"
                                            class="delete-button"
                                        >
                                            Delete
                                        </button>

                                    </form>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>

</main>

</body>

</html> 