<!DOCTYPE html>
<html lang="zh-Hant">
<head>
  <meta charset="UTF-8">
  <title>題組測驗 | Python學習平台</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background-color: #f8fafc; }
    .card:hover { transform: scale(1.02); transition: 0.3s; }
  </style>
</head>
<body>

<?php include 'navbar.php'; ?> <!-- 如果你有共用導覽列檔案，可這樣引入 -->

<div class="container my-5">
  <h2 class="text-center mb-4">🧩 Python 題組測驗列表</h2>
  <p class="text-center text-muted">選擇一個題組進行測驗，挑戰你的學習成果！</p>

  <div class="row g-4 mt-4">

    <!-- 題組 1 -->
    <div class="col-md-4">
      <div class="card shadow-sm border-warning">
        <div class="card-body">
          <h5 class="card-title text-warning">題組 1：輸入與輸出</h5>
          <p class="card-text">章節範圍：第1章<br>題目數量：10 題<br>難度：★☆☆</p>
          <a href="quiz.php?set=1" class="btn btn-warning w-100">開始測驗</a>
        </div>
      </div>
    </div>

    <!-- 題組 2 -->
    <div class="col-md-4">
      <div class="card shadow-sm border-primary">
        <div class="card-body">
          <h5 class="card-title text-primary">題組 2：條件判斷</h5>
          <p class="card-text">章節範圍：第2章<br>題目數量：8 題<br>難度：★★☆</p>
          <a href="quiz.php?set=2" class="btn btn-primary w-100">開始測驗</a>
        </div>
      </div>
    </div>

    <!-- 題組 3 -->
    <div class="col-md-4">
      <div class="card shadow-sm border-success">
        <div class="card-body">
          <h5 class="card-title text-success">題組 3：For + While 綜合</h5>
          <p class="card-text">章節範圍：第3～第4章<br>題目數量：12 題<br>難度：★★★</p>
          <a href="quiz.php?set=3" class="btn btn-success w-100">開始測驗</a>
        </div>
      </div>
    </div>

  </div>
</div>

<footer class="text-center mt-5 mb-3 text-muted">
  © 2025 Python學習平台｜AI 輔助程式學習系統
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
