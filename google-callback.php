<?php

// ======================================================
// GOOGLE SENDS THE USER BACK HERE AFTER THEY CHOOSE AN ACCOUNT
// ======================================================

session_start();

require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/auth.php";

ensure_auth_schema($pdo);

// one use only: a replayed callback URL finds no attempt
$attempt = $_SESSION["google_oauth"] ?? null;
unset($_SESSION["google_oauth"]);

$result = google_enabled()
    ? google_handle_callback($pdo, $_GET, $attempt, "google_exchange_code")
    : ["ok" => false, "error" => "Google sign-in isn't available right now."];

if (!$result["ok"]) {
    $_SESSION["auth_flash"] = $result["error"];

    $redirect = safe_redirect((string) ($attempt["redirect"] ?? ""));
    header("Location: login.php" . ($redirect !== "" ? "?redirect=" . urlencode($redirect) : ""));
    exit;
}

login_user($result["user"]);

header("Location: " . destination_for($result["user"], $result["redirect"]));
exit;
