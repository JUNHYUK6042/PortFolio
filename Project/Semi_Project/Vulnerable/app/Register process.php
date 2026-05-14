<?php
include "dbconn.php";

$name = $_POST['name'];
$email = $_POST['email'];
$id = $_POST['id'];
$pw = $_POST['pw'];
$pw_confirm = $_POST['pw_confirm'];
$phone = $_POST['phone'];
$birth = $_POST['birth'];
$address = $_POST['address'];

if($pw !== $pw_confirm){
    echo "<script>alert('비밀번호가 서로 다릅니다.'); window.history.back();</script>";
    exit;
}

$check_sql = "SELECT id FROM users WHERE id='$id'";
$check_result = mysqli_query($conn, $check_sql);

if(mysqli_num_rows($check_result) > 0){
    echo "<script>
            alert('이미 사용중인 아이디입니다.');
            window.history.back();
          </script>";
    exit;
}

// [취약점] 입력값 검증 없이 SQL 쿼리에 직접 삽입 → SQL Injection 가능
$sql = "INSERT INTO users 
        (id, pw, name, email, phone, address, birth)
        VALUES 
        ('$id', '$pw', '$name', '$email', '$phone', '$address', '$birth')";

$result = mysqli_query($conn, $sql);

if($result){
    echo "<script>
            alert('회원가입이 완료되었습니다.');
            location.href='../public/login.php';
          </script>";
} else {
    echo "<script>
            alert('회원가입에 실패했습니다.');
            window.history.back();
          </script>";
}
?>
