<?php

$serverName = strtolower($_SERVER["HTTP_HOST"] ?? "localhost");
$isLocal = PHP_SAPI === "cli"
	|| preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/', $serverName);

// The property is in the Philippines: dates and times everywhere on the site are Philippine time.
date_default_timezone_set("Asia/Manila");

// Logins live in database.credentials.php (private, not in git)
$credentials = require __DIR__ . "/database.credentials.php";
$database = $credentials[$isLocal ? "local" : "live"];

$host = $database["host"];
$dbname = $database["dbname"];
$username = $database["username"];
$password = $database["password"];

unset($credentials, $database);

if (!$isLocal) {
	ini_set("display_errors", "0");
}

try {
	$pdo = new PDO(
		"mysql:host=$host;dbname=$dbname;charset=utf8mb4",
		$username,
		$password
	);

	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

	// NOW(), CURDATE() and TIMESTAMP columns (created_at) in Philippine time, on any host
	$pdo->exec("SET time_zone = '+08:00'");
} catch (PDOException $e) {
	if ($isLocal) {
		die("Database connection failed: " . $e->getMessage());
	}
	error_log("Database connection failed: " . $e->getMessage());
	http_response_code(503);
	die("The site is temporarily unavailable. Please try again in a few minutes.");
}

$pdo->exec(
	"CREATE TABLE IF NOT EXISTS users (
		id INT UNSIGNED NOT NULL AUTO_INCREMENT,
		full_name VARCHAR(150) NOT NULL,
		email VARCHAR(255) NOT NULL,
		phone VARCHAR(30) NOT NULL,
		password VARCHAR(255) NOT NULL,
		profile_image VARCHAR(255) NULL,
		role VARCHAR(20) NOT NULL DEFAULT 'customer',
		status VARCHAR(20) NOT NULL DEFAULT 'active',
		created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY users_email_unique (email)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$profileImageColumn = $pdo->query(
	"SELECT COUNT(*)
	 FROM INFORMATION_SCHEMA.COLUMNS
	 WHERE TABLE_SCHEMA = DATABASE()
	 AND TABLE_NAME = 'users'
	 AND COLUMN_NAME = 'profile_image'"
)->fetchColumn();

if (!$profileImageColumn) {
	$pdo->exec("ALTER TABLE users ADD COLUMN profile_image VARCHAR(255) NULL AFTER password");
}

$pdo->exec(
	"CREATE TABLE IF NOT EXISTS rooms (
		id INT UNSIGNED NOT NULL AUTO_INCREMENT,
		room_name VARCHAR(150) NOT NULL,
		description TEXT NULL,
		capacity INT UNSIGNED NOT NULL DEFAULT 1,
		price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
		status VARCHAR(20) NOT NULL DEFAULT 'available',
		created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$roomNameColumn = $pdo->query(
	"SELECT COUNT(*)
	 FROM INFORMATION_SCHEMA.COLUMNS
	 WHERE TABLE_SCHEMA = DATABASE()
	 AND TABLE_NAME = 'rooms'
	 AND COLUMN_NAME = 'room_name'"
)->fetchColumn();

$legacyRoomNameColumn = $pdo->query(
	"SELECT COUNT(*)
	 FROM INFORMATION_SCHEMA.COLUMNS
	 WHERE TABLE_SCHEMA = DATABASE()
	 AND TABLE_NAME = 'rooms'
	 AND COLUMN_NAME = 'name'"
)->fetchColumn();

if (!$roomNameColumn && $legacyRoomNameColumn) {
	$pdo->exec("ALTER TABLE rooms CHANGE name room_name VARCHAR(150) NOT NULL");
}

$pdo->exec(
	"CREATE TABLE IF NOT EXISTS reservations (
		id INT UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id INT UNSIGNED NOT NULL,
		room_id INT UNSIGNED NOT NULL,
		check_in DATE NOT NULL,
		check_out DATE NOT NULL,
		guests INT UNSIGNED NOT NULL DEFAULT 1,
		price_per_night DECIMAL(10,2) NOT NULL DEFAULT 0.00,
		total_nights INT UNSIGNED NOT NULL DEFAULT 1,
		total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
		status VARCHAR(20) NOT NULL DEFAULT 'pending',
		created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		CONSTRAINT reservations_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
		CONSTRAINT reservations_room_fk FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE RESTRICT
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$reservationColumns = [
	"guests" => "INT UNSIGNED NOT NULL DEFAULT 1",
	"price_per_night" => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
	"total_nights" => "INT UNSIGNED NOT NULL DEFAULT 1",
	"total_amount" => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
];

foreach ($reservationColumns as $columnName => $columnDefinition) {
	$columnExists = $pdo->prepare(
		"SELECT COUNT(*)
		 FROM INFORMATION_SCHEMA.COLUMNS
		 WHERE TABLE_SCHEMA = DATABASE()
		 AND TABLE_NAME = 'reservations'
		 AND COLUMN_NAME = ?"
	);
	$columnExists->execute([$columnName]);

	if (!$columnExists->fetchColumn()) {
		$pdo->exec(
			"ALTER TABLE reservations ADD COLUMN {$columnName} {$columnDefinition}"
		);
	}
}

$pdo->exec(
	"CREATE TABLE IF NOT EXISTS payments (
		id INT UNSIGNED NOT NULL AUTO_INCREMENT,
		reservation_id INT UNSIGNED NOT NULL,
		user_id INT UNSIGNED NOT NULL,
		payment_method VARCHAR(30) NOT NULL,
		amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
		reference_number VARCHAR(100) NULL,
		status VARCHAR(20) NOT NULL DEFAULT 'pending',
		created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		CONSTRAINT payments_reservation_fk FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
		CONSTRAINT payments_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);