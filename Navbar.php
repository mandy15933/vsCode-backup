<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isLoggedIn = isset($_SESSION['user_id']);
$username   = $_SESSION['username'] ?? '訪客';
$className  = $_SESSION['class_name'] ?? '';
$role       = $_SESSION['role'] ?? ' '; // 預設學生
?>

<style>
/* 手機版：品牌名稱不要太長爆版 */
@media (max-width: 576px) {
  .navbar-brand {
    font-size: 1rem;
    white-space: nowrap;
  }
}

/* 手機版：讓 navbar 按鈕更大更好按 */
@media (max-width: 768px) {
  .navbar-toggler {
    padding: 0.6rem 0.8rem;
    font-size: 1.25rem;
  }
}

/* 手機版：展開選單變成淺色背景 + 加大字體 + 增加間距 */
@media (max-width: 768px) {
  .navbar-collapse {
    background: rgba(255, 255, 255, 0.9);
    padding: 1rem;
    border-radius: 0.75rem;
    margin-top: 0.5rem;
  }

  .navbar-nav .nav-link {
    padding: 0.75rem 0.5rem;
    font-size: 1.1rem;
  }
}

/* 手機版：登入區塊置中、換行 */
@media (max-width: 768px) {
  #navArea {
    width: 100%;
    justify-content: center !important;
    margin-top: 1rem;
  }
}

/* 讓 navbar 更柔和（可選） */
.navbar {
  transition: background 0.3s ease;
}
</style>


<nav class="navbar navbar-expand-lg navbar-light shadow-sm mb-4" style="background-color:#ffcc00;">
  <div class="container">
    <a class="navbar-brand fw-bold" href="index.php">🐍 Python 視覺化學習平台</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <?php if ($role === 'admin'): ?>
          <li class="nav-item"><a class="nav-link" href="Admin_question.php">📘 題庫管理</a></li>
          <li class="nav-item"><a class="nav-link" href="add_question.php">➕ 新增題目</a></li>
          <li class="nav-item"><a class="nav-link" href="manage_test_groups.php">🗂 測驗題組</a></li>
          <li class="nav-item"><a class="nav-link" href="analysis_dashboard.php">📊 學習分析</a></li>
          <li class="nav-item"><a class="nav-link" href="flowchart_generator.php">🔄 流程圖產生器</a></li>
        <?php elseif ($role === 'student'): ?>
          <li class="nav-item"><a class="nav-link" href="index.php">🏠 首頁</a></li>
          <li class="nav-item"><a class="nav-link" href="courses.php">🧩 單元列表</a></li>
          <!-- <li class="nav-item"><a class="nav-link" href="quiz_select.php">💬 測驗區</a></li> -->
          <li class="nav-item"><a class="nav-link" href="leaderboard.php">🏆 每日排行榜</a></li>
        <?php else: ?>
          <li class="nav-item"><a class="nav-link" href="index.php">🏠 首頁</a></li>
        <?php endif; ?>
      </ul>
      <div id="navArea" class="d-flex align-items-center">
        <?php if ($isLoggedIn): ?>
          <span class="me-3">👩‍💻 <?= htmlspecialchars($username) ?></span>
          <a href="logout.php" class="btn btn-outline-dark btn-sm" id="logoutBtn">登出</a>
        <?php else: ?>
          <button class="btn btn-outline-dark btn-sm" data-bs-toggle="modal" data-bs-target="#loginModal">登入</button>
          <!-- <button class="btn btn-dark btn-sm ms-2" data-bs-toggle="modal" data-bs-target="#registerModal">註冊</button> -->
        <?php endif; ?>
 

      </div>
    </div>
  </div>
</nav>
