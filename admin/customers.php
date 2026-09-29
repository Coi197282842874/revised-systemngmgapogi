<?php
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/icons.php";

// Check admin login
if (
    !isset($_SESSION["user_id"]) ||
    ($_SESSION["role"] ?? "") !== "admin"
) {
    header("Location: ../login.php");
    exit;
}

// Get customers with reservation count
$stmt = $pdo->query("
    SELECT
        users.id,
            users.full_name,
        users.email,
        users.profile_image,
        COUNT(reservations.id) AS total_reservations
    FROM users

    LEFT JOIN reservations
        ON users.id = reservations.user_id

    WHERE users.role = 'customer'

    GROUP BY
        users.id,
            users.full_name,
        users.email,
        users.profile_image

    ORDER BY users.id DESC
");

$customers = $stmt->fetchAll();
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
        content="#111827"
    >

    <title>Customers | ARVE'S House Admin</title>

    <style>
        :root {
            --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            --ease-in-out: cubic-bezier(0.77, 0, 0.175, 1);
            --ease-drawer: cubic-bezier(0.32, 0.72, 0, 1);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html {
            -webkit-tap-highlight-color: transparent;
            -webkit-text-size-adjust: 100%;
            text-size-adjust: 100%;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f3f4f6;
            color: #1f2937;
        }

        .navbar {
            background: #111827;
            padding: 18px 7%;
            padding-top: max(18px, env(safe-area-inset-top, 0px));
            padding-right: max(7%, env(safe-area-inset-right, 0px));
            padding-left: max(7%, env(safe-area-inset-left, 0px));
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .navbar h2 {
            color: white;
        }

        .navbar a {
            color: white;
            text-decoration: none;
            margin-left: 16px;
            touch-action: manipulation;
            transition: color 180ms ease;
        }

        .navbar a:hover,
        .navbar a:focus-visible {
            color: #f59e0b;
        }

        .container {
            width: 92%;
            max-width: 1200px;
            margin: 35px auto;
            padding-left: env(safe-area-inset-left, 0px);
            padding-right: env(safe-area-inset-right, 0px);
        }

        .header {
            margin-bottom: 25px;
        }

        .header h1 {
            margin-bottom: 8px;
        }

        .customers-grid {
            display: grid;
            grid-template-columns:
                repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
        }

        .customer-card {
            background: white;
            padding: 24px;
            border-radius: 14px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            text-align: center;
        }

        .profile-image {
            width: 95px;
            height: 95px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #e5e7eb;
            margin-bottom: 15px;
        }

        .profile-placeholder {
            width: 95px;
            height: 95px;
            border-radius: 50%;
            background: #e5e7eb;
            margin: 0 auto 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
        }

        .customer-card h3 {
            margin-bottom: 7px;
        }

        .email {
            color: #6b7280;
            margin-bottom: 15px;
        }

        .stats {
            background: #f9fafb;
            padding: 12px;
            border-radius: 8px;
            margin-top: 15px;
        }

        .stats strong {
            font-size: 22px;
            color: #2563eb;
        }

        .empty {
            background: white;
            padding: 40px;
            text-align: center;
            border-radius: 12px;
        }

        @media (max-width: 768px) {
            .navbar {
                flex-direction: column;
                gap: 12px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            html {
                scroll-behavior: auto;
            }

            /* this page has no hover lifts, press scales or slide entrances;
               the nav link colour fade is kept */

            *,
            *::before,
            *::after {
                animation-duration: 1ms !important;
                animation-iteration-count: 1 !important;
            }
        }
    </style>
<?php $glassTheme = "admin"; require __DIR__ . "/../includes/glass.php"; ?>
</head>

<body>

<nav class="navbar">

    <h2>ARVE'S House Admin</h2>

    <div>
        <a href="dashboard.php">Dashboard</a>
        <a href="reservations.php">Reservations</a>
        <a href="payment.php">Payments</a>
        <a href="customers.php">Customers</a>
        <a href="../logout.php">Logout</a>
    </div>

</nav>

<div class="container">

    <div class="header">
        <h1>Customers</h1>
        <p>View all registered customer accounts.</p>
    </div>

    <?php if (count($customers) > 0): ?>

        <div class="customers-grid">

            <?php foreach ($customers as $customer): ?>

                <div class="customer-card">

                    <?php if (!empty($customer["profile_image"])): ?>

                        <img
                            src="../<?= htmlspecialchars(
                                $customer["profile_image"]
                            ) ?>"
                            alt="Customer Profile"
                            class="profile-image"
                        >

                    <?php else: ?>

                        <div class="profile-placeholder">
                            <?= icon("user") ?>
                        </div>

                    <?php endif; ?>

                    <h3>
                            <?= htmlspecialchars(
                                $customer["full_name"]
                            ?? "Customer"
                        ) ?>
                    </h3>

                    <p class="email">
                        <?= htmlspecialchars(
                            $customer["email"]
                            ?? ""
                        ) ?>
                    </p>

                    <div class="stats">

                        <p>Total Reservations</p>

                        <strong>
                            <?= (int) $customer["total_reservations"] ?>
                        </strong>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php else: ?>

        <div class="empty">
            <h3>No customers found</h3>
            <p>Registered customers will appear here.</p>
        </div>

    <?php endif; ?>

</div>

</body>
</html>