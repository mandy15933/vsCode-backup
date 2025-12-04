<?php
session_start();
require 'db.php';

$isLoggedIn = isset($_SESSION['user_id']);
$username = $_SESSION['username'] ?? '訪客';
$className = $_SESSION['class_name'] ?? '';
$role = $_SESSION['role'] ?? 'student';

// 取得章節資料
$sql = "
    SELECT 
        c.id,
        c.title,
        c.image_path,

        -- 題目總數
        (SELECT COUNT(*) FROM questions q WHERE q.chapter = c.id AND q.is_hidden = 0) AS total_questions,

        -- 使用者已完成數
        (SELECT COUNT(DISTINCT sa.question_id)
         FROM student_answers sa
         JOIN questions q ON q.id = sa.question_id
         WHERE q.chapter = c.id 
           AND sa.user_id = {$_SESSION['user_id']} 
           AND sa.is_correct = 1
        ) AS done_questions
    FROM chapters c
    ORDER BY c.id
";

$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<title>課程章節</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<link rel="stylesheet" href="anime-yellow-theme.css">
<style>
    
/* 🧩 自訂字型 */
@font-face {
  font-family: 'Chiron GoRound TC';
  src: url('fonts/Chiron_GoRound_TC/ChironGoRoundTC-VariableFont_wght.ttf') format('truetype');
  font-weight: 100 900;
  font-style: normal;
}

/* 🌄 全域設定 */
body {
  background: linear-gradient(180deg, #fffde7 0%, #fff8e1 100%);
  font-family: 'Chiron GoRound TC', '微軟正黑體', 'Noto Sans TC', sans-serif;
  color: #4e342e;
}

/* 🏰 卡片外觀 */
.chapter-card {
  border-radius: 20px;
  overflow: hidden;
  background: #fffef5;
  border: 3px solid #f9d45c;
  box-shadow: 0 6px 0 #e0b93d, 0 8px 16px rgba(0,0,0,0.15);
  transition: transform 0.25s ease, box-shadow 0.25s ease, filter 0.25s ease;
  cursor: pointer;
  position: relative;
}

/* ✨ 滑過時閃亮與浮起 */
.chapter-card:hover {
  transform: translateY(-6px);
  box-shadow: 0 10px 0 #d4a934, 0 15px 25px rgba(0,0,0,0.2);
  filter: brightness(1.05);
}

/* 🖼️ 圖片封面區 */
.chapter-card .image-container {
  height: 200px;
  overflow: hidden;
  background: #fff8e1;
  position: relative;
  border-bottom: 3px solid #fdd835;
}

.chapter-card img.chapter-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: 50% 35%;  /* 保留上方章節文字 */
  transition: transform 0.3s ease, filter 0.3s ease;
  border-top-left-radius: 16px;
  border-top-right-radius: 16px;
}

.chapter-card:hover img.chapter-img {
  transform: scale(1.05);
  filter: brightness(1.08);
}

/* 📜 內容區文字 */
.chapter-card .card-body {
  background: linear-gradient(180deg, #fffefc 0%, #fff7e0 100%);
  border-top: 2px solid #fce375;
}

.chapter-card .card-title {
  color: #4e342e;
  font-weight: 800;
  font-size: 1.1rem;
  text-shadow: 1px 1px 0 #fff;
}

.chapter-card .card-text {
  color: #6d4c41;
  font-size: 0.9rem;
}

/* ⚔️ 按鈕：立體 RPG 風格 */
.btn-warning {
  background: linear-gradient(to bottom, #ffd54f 0%, #ffca28 100%);
  border: 2px solid #f9a825;
  color: #5d4037;
  font-weight: 700;
  border-radius: 12px;
  box-shadow: 0 3px 0 #f57f17;
  transition: all 0.2s ease;
}
.btn-warning:hover {
  background: linear-gradient(to bottom, #ffe082 0%, #ffca28 100%);
  transform: translateY(1px);
  box-shadow: 0 1px 0 #f57f17;
}

.btn-outline-dark {
  border: 2px solid #795548;
  color: #4e342e;
  font-weight: 700;
  border-radius: 12px;
  background: linear-gradient(to bottom, #fffef9 0%, #fff8e1 100%);
  box-shadow: 0 3px 0 #bca27f;
  transition: all 0.2s ease;
}
.btn-outline-dark:hover {
  background: #fff3c0;
  transform: translateY(1px);
}

/* 🏷️ 左上角「任務章節」標籤 */
.chapter-card::before {
  content: "任務章節";
  position: absolute;
  top: 10px;
  left: -25px;
  background: #ffeb3b;
  color: #5d4037;
  font-weight: 700;
  padding: 4px 28px;
  border-radius: 0 12px 12px 0;
  transform: rotate(-10deg);
  box-shadow: 0 2px 0 #d4a934;
}

/* ✅ 已完成徽章 */
.chapter-completed::after {
  content: "✅ 已完成";
  position: absolute;
  top: 12px;
  right: 12px;
  background: linear-gradient(to bottom, #81c784, #66bb6a);
  color: #fff;
  font-weight: 700;
  font-size: 0.85rem;
  padding: 4px 10px;
  border-radius: 10px;
  box-shadow: 0 3px 0 #388e3c;
  text-shadow: 1px 1px 0 rgba(0,0,0,0.2);
}


</style>
</head>
<body class="bg-light">

<?php include 'Navbar.php'; ?>

<div class="container py-4">
  <h3 class="mb-4 fw-bold text-center">🐍 Python 課程章節</h3>

  <div class="row g-4">
  <?php while ($row = $result->fetch_assoc()): ?>
    <div class="col-md-4">

      <!-- ✅ 加入判斷是否完成章節 -->
      <div class="card chapter-card shadow-sm border-0 text-center <?= !empty($row['completed']) && $row['completed'] ? 'chapter-completed' : '' ?>">

        <!-- 🖼️ 圖片封面 -->
        <div class="image-container">
          <img src="<?= htmlspecialchars($row['image_path'] ?: 'images/default.jpg') ?>" 
               class="chapter-img"
               alt="<?= htmlspecialchars($row['title']) ?>">
        </div>

        <!-- 📜 章節內容 -->
        <div class="card-body text-center">
          <h5 class="card-title fw-bold text-dark"><?= htmlspecialchars($row['title']) ?></h5>
          <!-- 📊 章節進度條 -->
          <?php
          $done = (int)($row['done_questions'] ?? 0);
          $total = (int)($row['total_questions'] ?? 0);
          $percent = $total > 0 ? round(($done / $total) * 100) : 0;
          ?>
          <div class="mt-2 mb-3 px-2">
            <div class="progress" style="height: 22px; border-radius: 12px;">
              <div class="progress-bar 
                <?= $percent >= 100 ? 'bg-success' : 'bg-warning text-dark' ?>"
                role="progressbar"
                style="width: <?= $percent ?>%;"
                aria-valuenow="<?= $percent ?>"
                aria-valuemin="0" 
                aria-valuemax="100">
                <?= $percent ?>%（<?= $done ?>/<?= $total ?>）
              </div>
            </div>
          </div>
              

          <!-- 🧭 登入後顯示教材／練習 -->
          <?php if ($isLoggedIn): ?>
            <div class="d-flex justify-content-between gap-2">
              <!-- <a href="material.php?chapter=<?= $row['id'] ?>" class="btn btn-outline-dark flex-fill">
                📖 學習教材
              </a> -->
              <a href="practice_list.php?chapter=<?= $row['id'] ?>" class="btn btn-warning flex-fill">
                💻 程式練習
              </a>
            </div>
          <?php else: ?>
            <button class="btn btn-secondary w-100" data-bs-toggle="modal" data-bs-target="#loginModal">
              🔒 請先登入
            </button>
          <?php endif; ?>
        </div>

      </div>
    </div>
  <?php endwhile; ?>
</div>




</body>
</html>
