<?php
// db.php
$host     = "sql112.thsite.top";      // host
$dbname   = "thsi_39822781_project_data";  // db name
$username = "thsi_39822781";               // grant db user
$password = "AX0t1JZ3";       // grant password

$mysqli = new mysqli($host, $username, $password, $dbname);

if ($mysqli->connect_error) {
    die("Database connection failed: " . $mysqli->connect_error);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
