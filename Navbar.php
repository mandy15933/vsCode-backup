<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isLoggedIn = isset($_SESSION['user_id']);
$username   = $_SESSION['username'] ?? '訪客';
$className  = $_SESSION['class_name'] ?? '';
$role       = $_SESSION['role'] ?? 'student'; // 預設學生
?>

<nav class="navbar navbar-expand-lg navbar-light shadow-sm mb-4" style="background-color:#ffcc00;">
  <div class="container">
    <a class="navbar-brand fw-bold" href="index.php">🐍 Python 學習平台</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <?php if ($role === 'admin'): ?>
          <li class="nav-item"><a class="nav-link" href="Admin_question.php">📘 題庫管理</a></li>
          <li class="nav-item"><a class="nav-link" href="add_question.php">➕ 新增題目</a></li>
          <li class="nav-item"><a class="nav-link" href="chapters_admin.php">🗂 章節管理</a></li>
          <li class="nav-item"><a class="nav-link" href="analysis_dashboard.php">📊 學習分析</a></li>
        <?php else: ?>
          <li class="nav-item"><a class="nav-link" href="index.php">🏠 首頁</a></li>
          <li class="nav-item"><a class="nav-link" href="courses.php">🧩 單元列表</a></li>
          <li class="nav-item"><a class="nav-link" href="quiz.php">💬 測驗區</a></li>
          <li class="nav-item"><a class="nav-link" href="progress.php">📈 學習進度</a></li>
        <?php endif; ?>
      </ul>

      <div id="navArea" class="d-flex align-items-center">
        <?php if ($isLoggedIn): ?>
          <span class="me-3">👩‍💻 <?= htmlspecialchars($username) ?>（<?= htmlspecialchars($className) ?>）</span>
          <a href="logout.php" class="btn btn-outline-dark btn-sm" id="logoutBtn">登出</a>
        <?php else: ?>
          <button class="btn btn-outline-dark btn-sm" data-bs-toggle="modal" data-bs-target="#loginModal">登入</button>
          <button class="btn btn-dark btn-sm ms-2" data-bs-toggle="modal" data-bs-target="#registerModal">註冊</button>
        <?php endif; ?>
        <!-- 在 Navbar 裡的右側區域加入 -->
        <button id="themeToggle" class="btn btn-outline-dark btn-sm ms-2" type="button">
          🌙 深色
        </button>

      </div>
    </div>
  </div>
</nav>
