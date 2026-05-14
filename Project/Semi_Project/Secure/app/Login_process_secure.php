<?php
/**
 * login_process_secure.php
 * 
 * [보안 적용 버전]
 * 취약점: SQL Injection (HTML Entity 인코딩 우회)
 * 
 * 적용한 대응 방안:
 *   1. 입력값 디코딩(정규화)을 검증보다 먼저 수행
 *   2. Prepared Statement 적용으로 SQL Injection 근본 차단
 *   3. 서버 측 화이트리스트 검증 적용
 * 
 * 비교 대상: vulnerable/app/login_process.php
 */

session_start();
include "dbconn.php";

$raw_id = $_POST['id'] ?? '';
$raw_pw = $_POST['pw'] ?? '';

// ✅ 대응 1: 검증 전에 디코딩(정규화) 먼저 수행
//    취약 버전은 디코딩 전에 필터링 → &#x27; 같은 우회 가능
//    보안 버전은 디코딩 후 필터링 → 실제 문자 기준으로 검증
$id = html_entity_decode($raw_id, ENT_QUOTES, 'UTF-8');
$pw = html_entity_decode($raw_pw, ENT_QUOTES, 'UTF-8');

// ✅ 대응 2: 화이트리스트 기반 입력값 검증
//    허용할 문자만 정의 (영문, 숫자, 일부 특수문자)
if (!preg_match('/^[a-zA-Z0-9_]{4,20}$/', $id)) {
    echo "<script>
            alert('아이디는 영문, 숫자, 언더바(_)만 사용 가능합니다. (4~20자)');
            window.history.back();
          </script>";
    exit;
}

// ✅ 대응 3: Prepared Statement 적용
//    사용자 입력값이 SQL 구문에 직접 삽입되지 않음
//    → SQL Injection 근본적으로 차단
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND pw = ?");
$stmt->bind_param("ss", $id, $pw);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $_SESSION['user'] = $row['id'];

    echo "<script>
            alert('" . htmlspecialchars($row['name'], ENT_QUOTES) . "님 환영합니다');
            location.href='/public/board.php';
          </script>";
    exit;

} else {
    echo "<script>
            alert('아이디 또는 패스워드가 일치하지 않습니다.');
            window.history.back();
          </script>";
}

$stmt->close();
?>
