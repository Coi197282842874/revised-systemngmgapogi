<?php

// ======================================================
// MESSAGES API (JSON) for the floating Messages window
//
// Customers only. Called when the window opens, when the customer sends, when they press
// refresh, and once when they come back to the tab. Never polled on a timer.
//   GET  ?action=unread → number of unread replies
//   GET  ?action=list  → the conversation (marks replies as read)
//   POST ?action=send  → body, csrf_token
// ======================================================

session_start();

require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/includes/chat.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

function inbox_json(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int) ($_SESSION["user_id"] ?? 0);

if ($userId <= 0 || ($_SESSION["role"] ?? "") !== "customer") {
    inbox_json(["ok" => false, "error" => "Please log in to send us a message."], 401);
}

$me = $pdo->prepare("SELECT status FROM users WHERE id = ? AND role = 'customer'");
$me->execute([$userId]);

if ($me->fetchColumn() !== "active") {
    inbox_json(["ok" => false, "error" => "Please log in to send us a message."], 401);
}

ensure_chat_schema($pdo);

$action = $_GET["action"] ?? "";

// cheap check for the button's badge when the customer comes back to the page
if ($action === "unread" && $_SERVER["REQUEST_METHOD"] === "GET") {
    inbox_json(["ok" => true, "unread" => chat_unread_for_customer($pdo, $userId)]);
}

if ($action === "list" && $_SERVER["REQUEST_METHOD"] === "GET") {
    chat_mark_read($pdo, $userId, "customer");
    inbox_json(["ok" => true, "messages" => chat_fetch($pdo, $userId, 0)]);
}

if ($action === "send" && $_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_valid($_POST["csrf_token"] ?? null)) {
        inbox_json(["ok" => false, "error" => "Your session expired. Please reload the page."], 403);
    }

    $result = chat_send($pdo, $userId, "customer", $userId, (string) ($_POST["body"] ?? ""));
    inbox_json($result, $result["ok"] ? 200 : 422);
}

inbox_json(["ok" => false, "error" => "Unknown request."], 400);
