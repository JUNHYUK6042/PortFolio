<?php
$host = "localhost";
$user = "webuser";
$password = "********";
$dbname = "websec";

$conn = mysqli_connect($host, $user, $password, $dbname);

if (!$conn) {
    die("DB Connection Failed: " . mysqli_connect_error());
}
?>
