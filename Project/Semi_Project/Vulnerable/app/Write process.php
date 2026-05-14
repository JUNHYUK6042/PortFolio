<?php
session_start();
include __DIR__ . "/dbconn.php";

if (!isset($_SESSION['user'])) {
    http_response_code(403);
    exit("Login required.");
}

$title = isset($_POST['title']) ? $_POST['title'] : "";
$writer = $_SESSION['user'];

$filename_to_save = "";

if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {

    $original_name = $_FILES['file']['name'];
    $tmp = $_FILES['file']['tmp_name'];

    // [취약점] 디코딩 전에 확장자 검사 → HTML Entity 인코딩으로 우회 가능
    $base_raw = basename($original_name);
    $ext_raw = strtolower(pathinfo($base_raw, PATHINFO_EXTENSION));

    if (
        $ext_raw === "php"  ||
        $ext_raw === "php3" ||
        $ext_raw === "php4" ||
        $ext_raw === "php5" ||
        $ext_raw === "phtml"||
        $ext_raw === "phar" ||
        $ext_raw === "html" ||
        $ext_raw === "htm"  ||
        $ext_raw === "sh"   ||
        $ext_raw === "bash" ||
        $ext_raw === "exe"  ||
        $ext_raw === "asp"  ||
        $ext_raw === "aspx" ||
        $ext_raw === "jsp"  ||
        $ext_raw === "cgi"  ||
        $ext_raw === "pl"   ||
        $ext_raw === "py"   ||
        $ext_raw === "rb"
    ) {
        echo "<script>alert('허용되지 않는 확장자입니다.'); window.history.back();</script>";
        exit;
    }

    // [취약점 발생 지점] 검사 이후에 디코딩 → &#x70;&#x68;&#x70; 형태로 필터 우회 가능
    $decoded_name = html_entity_decode($original_name, ENT_QUOTES, 'UTF-8');
    $base = basename($decoded_name);
    $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));

    $safe_name = bin2hex(random_bytes(16)) . "." . $ext;
    $dest = __DIR__ . "/../public/upload/" . $safe_name;

    if (!move_uploaded_file($tmp, $dest)) {
        http_response_code(500);
        exit("Upload failed.");
    }

    $filename_to_save = $safe_name;
}

$title_esc = mysqli_real_escape_string($conn, $title);
$writer_esc = mysqli_real_escape_string($conn, $writer);
$file_esc = mysqli_real_escape_string($conn, $filename_to_save);

$sql = "INSERT INTO board (title, filename, writer) 
        VALUES ('$title_esc', '$file_esc', '$writer_esc')";

mysqli_query($conn, $sql);

header("Location: ../public/board.php");
exit;
?>
