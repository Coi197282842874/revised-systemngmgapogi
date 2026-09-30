<?php
/*
 * The payment page moved into the customer area: customer/pay.php.
 * This address stays for old links and bookmarks, and sends there with the same ?details.
 */

$query = (string) ($_SERVER["QUERY_STRING"] ?? "");

header("Location: customer/pay.php" . (preg_match('/^[A-Za-z0-9_\-=&%.]+$/', $query) ? "?" . $query : ""));
exit;
