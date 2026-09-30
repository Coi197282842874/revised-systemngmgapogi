<?php
/*
 * The booking page moved into the customer area: customer/book.php.
 * This address stays for old links and bookmarks, and sends there with the same ?details.
 */

$query = (string) ($_SERVER["QUERY_STRING"] ?? "");

header("Location: customer/book.php" . (preg_match('/^[A-Za-z0-9_\-=&%.]+$/', $query) ? "?" . $query : ""));
exit;
