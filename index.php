<?php
session_start();
require 'db.php';

$isLoggedIn = isset($_SESSION['user_id']);
$username = $_SESSION['username'] ?? '訪客';
$className = $_SESSION['class_name'] ?? '';
$role = $_SESSION['role'] ?? 'student';
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<title>🐍 Python 學習平台首頁</title>
<!-- Bootstrap CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<!-- Bootstrap JS Bundle (含 Popper.js) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<link rel="stylesheet" href="anime-yellow-theme.css">

<style>
body {
  background: linear-gradient(to bottom right, #fff8e1, #fffde7);
  font-family: "Noto Sans TC", sans-serif;
}
.hero {
  padding: 80px 20px;
  text-align: center;
}
.hero h1 {
  font-size: 2.8rem;
  font-weight: 700;
  color: #ff9800;
}
.hero p {
  color: #555;
  font-size: 1.1rem;
}
.hero img {
  max-width: 360px;
  margin-top: 20px;
  animation: float 3s ease-in-out infinite;
}
@keyframes float {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-10px); }
}
.btn-main {
  background-color: #ffc107;
  color: #000;
  font-weight: 600;
  border-radius: 30px;
  padding: 12px 36px;
  font-size: 1.1rem;
}
.btn-main:hover {
  background-color: #e0a800;
}
.section {
  padding: 60px 20px;
}
.feature-icon {
  font-size: 40px;
  color: #ffb300;
}
</style>
</head>
<body>

<?php include 'Navbar.php'; ?>

<!-- 🔸 Hero 區 -->
<div class="hero">
  <h1>🐍 歡迎來到 Python 學習平台</h1>



  <?php if ($isLoggedIn): ?>
    <a href="courses.php" class="btn btn-main mt-4">🎯 進入課程</a>
  <?php else: ?>
    <button class="btn btn-main mt-4" data-bs-toggle="modal" data-bs-target="#loginModal">🚀 開始學習</button>
  <?php endif; ?>

  <br>
  <img src="images/python_hero.png" alt="Python Hero" class="mt-3">
</div>

<!-- 🔸 平台特色區 -->
<div class="section bg-white">
  <div class="container text-center">
    <h3 class="fw-bold mb-4 text-warning">✨ 平台特色</h3>
    <div class="row g-4">
      <div class="col-md-4">
        <div class="feature-icon mb-3">💡</div>
        <h5>AI 智能助教</h5>
        <p class="text-muted">提供程式批改、提示與學習回饋，打造個人化學習體驗。</p>
      </div>
      <div class="col-md-4">
        <div class="feature-icon mb-3">🧠</div>
        <h5>心智圖與流程圖輔助</h5>
        <p class="text-muted">可視化理解程式邏輯，幫助學生從概念到實作完整掌握。</p>
      </div>
      <div class="col-md-4">
        <div class="feature-icon mb-3">🎮</div>
        <h5>遊戲化學習設計</h5>
        <p class="text-muted">每章挑戰任務與獎勵成就，激發學習動機與持續投入。</p>
      </div>
    </div>
  </div>
</div>

<!-- 🔹 登入 Modal -->
<!-- 登入 Modal -->
<div class="modal fade" id="loginModal" tabindex="-1" aria-labelledby="loginModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content shadow-lg border-warning border-3">
      <div class="modal-header bg-warning">
        <h5 class="modal-title fw-bold" id="loginModalLabel">🔑 登入系統</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button>
      </div>
      <div class="modal-body">
        <form id="loginForm">
          <div class="mb-3">
            <label class="form-label">帳號（學號）</label>
            <input type="text" class="form-control" name="student_id" required>
          </div>
          <div class="mb-3">
            <label class="form-label">密碼</label>
            <input type="password" class="form-control" name="password" required>
          </div>
          <button type="submit" class="btn btn-warning w-100 fw-bold">登入</button>
        </form>
      </div>
      <div class="modal-footer">
        <small class="text-muted">還沒有帳號？
          <a href="#" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#registerModal" class="text-dark fw-bold text-decoration-none">立即註冊</a>
        </small>
      </div>
    </div>
  </div>
</div>

<!-- 註冊 Modal -->
<div class="modal fade" id="registerModal" tabindex="-1" aria-labelledby="registerModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content shadow-lg border-warning border-3">
      <div class="modal-header bg-warning">
        <h5 class="modal-title fw-bold" id="registerModalLabel">📝 註冊新帳號</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button>
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
            <input type="text" class="form-control" name="class_name">
          </div>
          <button type="submit" class="btn btn-warning w-100 fw-bold">註冊</button>
        </form>
      </div>
    </div>
  </div>
</div>


<!-- 🔸 底部 -->
<footer class="text-center mt-5 py-3 text-muted small">
  &copy; <?= date('Y') ?> Python 學習平台 | AI 輔助視覺化學習系統
</footer>

</body>
</html>
<script>
document.getElementById('loginForm')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const formData = new FormData(e.target);
  const res = await fetch('login.php', { method: 'POST', body: formData });
  const result = await res.json();

  if (result.success) {
    alert('登入成功');
    location.reload(); // 重新整理顯示使用者名稱
  } else {
    alert(result.message || '登入失敗，請確認帳號或密碼');
  }
});

document.getElementById('registerForm')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const formData = new FormData(e.target);
  const res = await fetch('register.php', { method: 'POST', body: formData });
  const result = await res.json();

  if (result.success) {
    alert('註冊成功，請登入！');
    const registerModal = bootstrap.Modal.getInstance(document.getElementById('registerModal'));
    registerModal.hide();
    const loginModal = new bootstrap.Modal(document.getElementById('loginModal'));
    loginModal.show();
  } else {
    alert(result.message || '註冊失敗');
  }
});
</script>
