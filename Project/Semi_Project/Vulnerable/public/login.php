<?php
session_start();

if(isset($_GET['logout'])){
    session_destroy();
}

include __DIR__ . "/../app/dbconn.php";
include __DIR__ . "/html/login.html";
?>