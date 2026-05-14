<?php
session_start();
include "dbconn.php";

$title = $_POST['title'];
$filename = $_FILES['file']['name'];
$tmp = $_FILES['file']['tmp_name'];

// [취약점] 확장자 검사 없이 파일을 바로 업로드 → WebShell 업로드 가능
move_uploaded_file($tmp, "../public/upload/".$filename);

$writer = $_SESSION['user'];

$sql = "INSERT INTO board (title, filename, writer) 
        VALUES ('$title', '$filename', '$writer')";
mysqli_query($conn, $sql);

header("Location: ../public/board.php");
?>
