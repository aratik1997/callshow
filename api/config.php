<?php
// TEMP DEBUG — remove after diagnosing the 500 error.
ini_set('display_errors', '1');
error_reporting(E_ALL);

// Database connection settings.
// LOCAL (XAMPP) defaults are pre-filled below.
// ON CPANEL: create a MySQL database + user in "MySQL Databases", then replace
// these four values with the ones cPanel gives you (db name/user are usually
// prefixed with your cPanel username, e.g. "myuser_yaniv").
return [
    'db_host' => 'sdb-77.hosting.stackcp.net',
    'db_name' => 'yanivgame-353037350006',
    'db_user' => 'yanivgame',
    'db_pass' => 'YanivAuto97',
];
