<?php

// ======================================================
// START "CONTINUE WITH GOOGLE": SEND THE BROWSER TO GOOGLE
// ======================================================

session_start();

require_once __DIR__ . "/includes/auth.php";

if (!google_enabled()) {
    header("Location: login.php");
    exit;
}

// already signed in: nothing to do
if (isset($_SESSION["user_id"])) {
    header("Location: " . (($_SESSION["role"] ?? "") === "admin" ? "admin/dashboard.php" : "customer/dashboard.php"));
    exit;
}

header("Location: " . google_authorization_url($_GET["redirect"] ?? ""));
exit;
