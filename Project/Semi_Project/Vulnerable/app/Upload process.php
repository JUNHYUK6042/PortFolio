<?php
session_start();
include "dbconn.php";

$title = $_POST['title'];
$filename = $_FILES['file']['name'];
$tmp = $_FILES['file']['tmp_name'];

// [취약점 1] 확장자 검사 자체가 없음
// → 파일 종류에 관계없이 모든 파일이 그대로 업로드됨
// → .php, .sh, .exe 등 실행 가능한 파일도 아무런 제한 없이 업로드 가능
// → WebShell을 바로 업로드하여 서버 내부 명령 실행 가능

// [취약점 2] 원본 파일명을 그대로 사용하여 저장
// → 공격자가 파일명을 예측하거나 조작할 수 있음
// → 동일한 파일명으로 재업로드 시 기존 파일 덮어쓰기 가능
move_uploaded_file($tmp, "../public/upload/".$filename);

$writer = $_SESSION['user'];

// [취약점 3] 입력값 검증 없이 SQL 쿼리에 직접 삽입
// → $title, $filename, $writer 값이 그대로 쿼리에 들어감
// → SQL Injection 공격 가능
$sql = "INSERT INTO board (title, filename, writer) 
        VALUES ('$title', '$filename', '$writer')";
mysqli_query($conn, $sql);

header("Location: ../public/board.php");
?>
