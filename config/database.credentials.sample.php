<?php
/*
 * ARVE'S House — database logins (template).
 * Copy this file to database.credentials.php and fill it in. Keep that copy private.
 */

return [

    // XAMPP on this computer
    "local" => [
        "host" => "localhost",
        "dbname" => "arves_house",
        "username" => "root",
        "password" => "",
    ],

    // Live site (hosting control panel → MySQL Databases)
    "live" => [
        "host" => "",       // MySQL hostname
        "dbname" => "",     // MySQL database name
        "username" => "",   // MySQL username
        "password" => "",   // MySQL password
    ],

];
