<?php
session_start();
include "dbconn.php";

$raw_id = $_POST['id'];
$raw_pw = $_POST['pw'];

// 1. 1차 필터링: 실제 홑따옴표(')가 입력값에 존재하는지 검사
if (strpos($raw_id, "'") !== false || strpos($raw_pw, "'") !== false) {
    echo "<script>
            alert('허용되지 않는 문자가 포함되어있습니다.');
            window.history.back();
          </script>";
    exit;
}

// 2. 디코딩: HTML 엔티티(예: &#39; 또는 &apos;)를 실제 문자로 변환
$id = html_entity_decode($raw_id, ENT_QUOTES, 'UTF-8');
$pw = html_entity_decode($raw_pw, ENT_QUOTES, 'UTF-8');

// 3. 쿼리 실행 (디코딩된 값이 들어가므로 우회된 홑따옴표가 SQL 구문으로 작동함)
// [취약점] 검증 순서 오류 → 디코딩 전에 필터링하여 HTML Entity 인코딩으로 우회 가능
$sql = "SELECT * FROM users WHERE id='$id' AND pw='$pw'";
$result = mysqli_query($conn, $sql);

if(mysqli_num_rows($result) > 0){

    $row = mysqli_fetch_assoc($result);
    $_SESSION['user'] = $row['id'];

    echo "<script>
            alert('".$row['name']."님 환영합니다');
            location.href='/public/board.php';
          </script>";
    exit;

} else {

    echo "<script>
            alert('아이디 또는 패스워드가 일치하지 않습니다.');
            window.history.back();
          </script>";

}
?>
