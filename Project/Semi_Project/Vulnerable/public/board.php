<?php
session_start();
include "../app/dbconn.php";

mysqli_set_charset($conn, "utf8");

// 게시글 + 작성자 이름 JOIN
$result = mysqli_query($conn, "
    SELECT board.*, users.name 
    FROM board 
    JOIN users ON board.writer = users.id 
    ORDER BY board.idx DESC
");
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="css/style.css">
    <title>게시판</title>
</head>
<body>

<div class="board-container">
    <h2>게시판</h2>

    <div class="board-actions">
        <a href="write.php" class="main-btn">게시글 작성</a>
    </div>

    <?php if(isset($_SESSION['user'])): ?>
        <div class="login-info">
            현재 로그인 사용자 :
            <?php
                $uid = mysqli_real_escape_string($conn, $_SESSION['user']);
                $uquery = mysqli_query($conn, "SELECT name FROM users WHERE id='$uid'");
                $urow = mysqli_fetch_assoc($uquery);
                echo htmlspecialchars($urow['name']);
            ?>
        </div>
    <?php endif; ?>

    <table class="board-table">
        <thead>
            <tr>
                <th style="width:35%;">제목</th>
                <th style="width:30%;">파일</th>
                <th style="width:10%;">작성자</th>
                <th style="width:25%;">작성날짜</th>
            </tr>
        </thead>
        <tbody>
        <?php while($row = mysqli_fetch_assoc($result)): ?>
            <tr>
                <td>
                    <?php echo htmlspecialchars($row['title']); ?>
                </td>

                <td>
                    <?php if(!empty($row['filename'])): ?>
                        <a href="upload/<?php echo htmlspecialchars($row['filename']); ?>" class="file-link">
                            <?php echo htmlspecialchars($row['filename']); ?>
                        </a>
                    <?php endif; ?>
                </td>

                <td style="white-space:nowrap;">
                    <?php echo htmlspecialchars($row['name']); ?>
                </td>

                <td style="white-space:nowrap;">
                    <?php
                        // 공백을 줄바꿈 불가능 공백으로 변경
                        echo str_replace(' ', '&nbsp;', htmlspecialchars($row['created_at']));
                    ?>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>

</body>
</html>
