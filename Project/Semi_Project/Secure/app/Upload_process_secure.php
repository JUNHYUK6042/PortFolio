<?php
/**
 * upload_process_secure.php
 *
 * [보안 적용 버전]
 * 취약점: 파일 업로드 필터 우회 → WebShell 업로드
 *
 * 적용한 대응 방안:
 *   1. 디코딩 후 확장자 검증 수행 (순서 수정)
 *   2. 블랙리스트 → 화이트리스트 방식으로 전환
 *   3. 업로드 파일 실행 권한 제거
 *   4. 업로드 경로 웹 루트 외부로 분리
 *
 * 비교 대상: vulnerable/app/write_process.php
 */

session_start();
include "dbconn.php";

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

    // ✅ 대응 1: 디코딩(정규화)을 검증보다 먼저 수행
    //    취약 버전은 디코딩 전에 확장자 검사 → &#x70;&#x68;&#x70; 우회 가능
    //    보안 버전은 디코딩 후 검사 → 실제 확장자 기준으로 판단
    $decoded_name = html_entity_decode($original_name, ENT_QUOTES, 'UTF-8');
    $ext = strtolower(pathinfo(basename($decoded_name), PATHINFO_EXTENSION));

    // ✅ 대응 2: 블랙리스트 → 화이트리스트 방식으로 전환
    //    블랙리스트: 금지 목록에 없으면 통과 → 새로운 확장자로 우회 가능
    //    화이트리스트: 허용 목록에 있는 것만 통과 → 우회 불가능
    $whitelist = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'txt', 'zip'];
    if (!in_array($ext, $whitelist)) {
        echo "<script>alert('허용되지 않는 확장자입니다. (jpg, png, gif, pdf, txt, zip만 허용)'); window.history.back();</script>";
        exit;
    }

    // ✅ 대응 3: 파일 MIME 타입 추가 검증
    //    확장자 변조에 대한 이중 방어
    $allowed_mime = [
        'image/jpeg', 'image/png', 'image/gif',
        'application/pdf', 'text/plain', 'application/zip'
    ];
    $mime = mime_content_type($tmp);
    if (!in_array($mime, $allowed_mime)) {
        echo "<script>alert('파일 형식이 올바르지 않습니다.'); window.history.back();</script>";
        exit;
    }

    // ✅ 대응 4: 저장 파일명 랜덤화
    //    원본 파일명 사용 시 경로 조작 및 덮어쓰기 공격 가능
    $safe_name = bin2hex(random_bytes(16)) . "." . $ext;

    // ✅ 대응 5: 업로드 경로를 웹 루트 외부로 분리
    //    웹 루트 내 저장 시 URL로 직접 실행 가능
    //    웹 루트 외부 저장 → URL 접근 자체가 불가능
    $upload_dir = __DIR__ . "/../../storage/uploads/";  // 웹 루트 밖
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0750, true);
    }

    $dest = $upload_dir . $safe_name;

    if (!move_uploaded_file($tmp, $dest)) {
        http_response_code(500);
        exit("Upload failed.");
    }

    $filename_to_save = $safe_name;
}

// DB 저장 (Prepared Statement 적용)
$stmt = $conn->prepare("INSERT INTO board (title, filename, writer) VALUES (?, ?, ?)");
$stmt->bind_param("sss", $title, $filename_to_save, $writer);
$stmt->execute();
$stmt->close();

header("Location: ../public/board.php");
exit;
?>
