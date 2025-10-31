<?php
require 'db.php';
session_start();

// 讀取題組資料
$sql = "SELECT * FROM test_groups ORDER BY id ASC";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
  <meta charset="UTF-8">
  <title>題組測驗 | Python學習平台</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background-color: #f8fafc; }
    .card:hover { transform: scale(1.02); transition: 0.3s; }
    .badge-difficulty { font-size: 0.85em; }
  </style>
</head>
<body>

<?php include 'navbar.php'; ?> <!-- 共用導覽列 -->

<div class="container my-5">
  <h2 class="text-center mb-4">🧩 Python 題組測驗列表</h2>
  <p class="text-center text-muted">選擇一個題組進行挑戰，檢測你的學習成果！</p>

  <div class="row g-4 mt-4">
    <?php while ($row = $result->fetch_assoc()): ?>
      <?php
        $questionCount = count(json_decode($row['question_ids'], true));
        $chapterRange = htmlspecialchars($row['chapter_range']);
      ?>
      <div class="col-md-4">
        <div class="card shadow-sm border-warning">
          <div class="card-body">
            <h5 class="card-title text-warning"><?= htmlspecialchars($row['name']) ?></h5>
            <p class="card-text">
              章節範圍：<?= $chapterRange ?><br>
              題目數量：<?= $questionCount ?> 題<br>
              建立時間：<?= date('Y-m-d', strtotime($row['created_at'])) ?>
            </p>
            <?php if (!empty($row['description'])): ?>
              <p class="text-muted small"><?= htmlspecialchars($row['description']) ?></p>
            <?php endif; ?>
            <a href="quiz.php?set=<?= $row['id'] ?>" class="btn btn-warning w-100">開始測驗</a>
          </div>
        </div>
      </div>
    <?php endwhile; ?>
  </div>
</div>

<footer class="text-center mt-5 mb-3 text-muted">
  © 2025 Python學習平台｜AI 輔助程式學習系統
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
