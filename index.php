<?php
session_start();
require 'db.php';

$isLoggedIn = isset($_SESSION['user_id']);
$username = $_SESSION['username'] ?? '訪客';
$className = $_SESSION['class_name'] ?? '';

$sql = "SELECT ChapterID, ChapterName, Description, ImagePath FROM Chapters ORDER BY ChapterID";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<title>🐍 Python 學習平台</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<link rel="stylesheet" href="anime-yellow-theme.css">


</head>
<body>

<!-- 🔹 導覽列 -->
<nav class="navbar navbar-expand-lg navbar-light shadow-sm mb-4">
    <div class="container">
        <a class="navbar-brand fw-bold" href="#">🐍 Python 學習平台</a>
        <div class="d-flex" id="navArea">
            <?php if ($isLoggedIn): ?>
                <span class="me-3">👩‍💻 歡迎，<?= htmlspecialchars($username) ?>（<?= htmlspecialchars($className) ?>）</span>
                <a href="logout.php" class="btn btn-outline-dark btn-sm" id="logoutBtn">登出</a>
            <?php else: ?>
                <button class="btn btn-outline-dark btn-sm" data-bs-toggle="modal" data-bs-target="#loginModal">登入</button>
                <button class="btn btn-dark btn-sm ms-2" data-bs-toggle="modal" data-bs-target="#registerModal">註冊</button>
            <?php endif; ?>
        </div>
    </div>
</nav>

<!-- 🔹 主內容 -->
<div class="container">
    <h3 class="mb-4 fw-bold text-center">📘 學習章節</h3>
    <div class="row g-4">
        <?php while ($row = $result->fetch_assoc()): ?>
            <div class="col-md-4">
                <div class="card chapter-card">
                    <img src="<?= htmlspecialchars($row['ImagePath'] ?: 'images/default.jpg') ?>" 
                         class="card-img-top chapter-img" 
                         alt="<?= htmlspecialchars($row['ChapterName']) ?>">
                    <div class="card-body">
                        <h5 class="card-title"><?= htmlspecialchars($row['ChapterName']) ?></h5>
                        <p class="card-text text-muted small"><?= htmlspecialchars($row['Description'] ?? '') ?></p>
                        <?php if ($isLoggedIn): ?>
                            <a href="chapter.php?id=<?= $row['ChapterID'] ?>" class="btn btn-warning w-100">進入學習</a>
                        <?php else: ?>
                            <button class="btn btn-secondary w-100" data-bs-toggle="modal" data-bs-target="#loginModal">請先登入</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
</div>

<footer class="text-center mt-5 py-3 text-muted small">
    &copy; <?= date('Y') ?> Python 學習平台 | AI 輔助視覺化學習系統
</footer>

<!-- 🪟 登入 Modal -->
<div class="modal fade" id="loginModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content rounded-4">
            <div class="modal-header bg-warning">
                <h5 class="modal-title">登入帳號</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="loginForm">
                    <div class="mb-3">
                        <label class="form-label">學號或帳號</label>
                        <input type="text" class="form-control" name="login_id" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">密碼</label>
                        <input type="password" class="form-control" name="password" required>
                    </div>
                    <div id="loginMsg" class="text-danger small mb-2"></div>
                    <button type="submit" class="btn btn-warning w-100">登入</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- 🪟 註冊 Modal -->
<div class="modal fade" id="registerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content rounded-4">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">註冊新帳號</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="registerForm">
                    <div class="mb-3">
                        <label class="form-label">學號</label>
                        <input type="text" class="form-control" name="student_id" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">姓名</label>
                        <input type="text" class="form-control" name="username" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">密碼</label>
                        <input type="password" class="form-control" name="password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">班級</label>
                        <input type="text" class="form-control" name="class_name" required>
                    </div>
                    <div id="registerMsg" class="text-danger small mb-2"></div>
                    <button type="submit" class="btn btn-dark w-100">註冊</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// ✅ 登入 AJAX
$('#loginForm').on('submit', function(e){
    e.preventDefault();
    $.post('login.php', $(this).serialize(), function(res){
        if(res.success){
            $('#loginMsg').text('登入成功！');
            setTimeout(() => {
                $('#loginModal').modal('hide');
                // 即時更新導覽列
                $('#navArea').html(`
                    <span class="me-3">👩‍💻 歡迎，${res.username}（${res.class_name}）</span>
                    <a href="logout.php" class="btn btn-outline-dark btn-sm" id="logoutBtn">登出</a>
                `);
                // 將「請先登入」改為「進入學習」
                $('.btn-secondary').replaceWith('<a href="chapter.php" class="btn btn-warning w-100">進入學習</a>');
            }, 600);
        } else {
            $('#loginMsg').text(res.message);
        }
    }, 'json');
});

// ✅ 註冊 AJAX
$('#registerForm').on('submit', function(e){
    e.preventDefault();
    $.post('register.php', $(this).serialize(), function(res){
        if(res.success){
            $('#registerMsg').text('註冊成功！請登入。');
            setTimeout(()=>{
                $('#registerModal').modal('hide');
                $('#loginModal').modal('show');
            }, 800);
        }else{
            $('#registerMsg').text(res.message);
        }
    }, 'json');
});

// ✅ 登出 AJAX
$(document).on('click', '#logoutBtn', function(e){
    e.preventDefault();
    $.post('logout.php', {}, function(res){
        if(res.success){
            $('#navArea').html(`
                <button class="btn btn-outline-dark btn-sm" data-bs-toggle="modal" data-bs-target="#loginModal">登入</button>
                <button class="btn btn-dark btn-sm ms-2" data-bs-toggle="modal" data-bs-target="#registerModal">註冊</button>
            `);
            $('.btn-warning').replaceWith('<button class="btn btn-secondary w-100" data-bs-toggle="modal" data-bs-target="#loginModal">請先登入</button>');
        }
    }, 'json');
});
</script>

</body>
</html>
