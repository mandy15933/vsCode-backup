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
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <link rel="stylesheet" href="font.css">
  <link rel="stylesheet" href="anime-yellow-theme.css">
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
        $setId = $row['id'];
        $timeLimit = (int)($row['time_limit'] ?? 0);
      ?>
      <div class="col-md-4">
        <div class="card shadow-sm border-warning">
          <div class="card-body">
            <h5 class="card-title text-warning"><?= htmlspecialchars($row['name']) ?></h5>
            <p class="card-text">
              章節範圍：<?= $chapterRange ?><br>
              題目數量：<?= $questionCount ?> 題<br>
              限時：<?= $timeLimit ? $timeLimit . ' 分鐘' : '無限制' ?><br>
              建立時間：<?= date('Y-m-d', strtotime($row['created_at'])) ?>
            </p>
            <?php if (!empty($row['description'])): ?>
              <p class="text-muted small"><?= htmlspecialchars($row['description']) ?></p>
            <?php endif; ?>
            <a 
              href="quiz.php?set=<?= $setId ?>" 
              class="btn btn-warning w-100 quiz-btn" 
              data-setid="<?= $setId ?>" 
              data-timelimit="<?= $timeLimit ?>">
              開始測驗
            </a>
          </div>
        </div>
      </div>
    <?php endwhile; ?>
  </div>
</div>

<footer class="text-center mt-5 mb-3 text-muted">
  © 2025 Python學習平台｜AI 輔助程式學習系統
</footer>

<script>
function lockBtn(btn){
  btn.textContent = "⏳ 時間結束";
  btn.classList.remove("btn-warning");
  btn.classList.add("btn-secondary", "disabled");
  btn.setAttribute("aria-disabled","true");
  btn.style.pointerEvents = "none";
  btn.removeAttribute("href");
}

// 安全閥：若仍有 href，被標成 disabled 一律攔截
document.addEventListener("click", (e) => {
  const a = e.target.closest(".quiz-btn");
  if (!a) return;
  if (a.classList.contains("disabled") || a.getAttribute("aria-disabled") === "true") {
    e.preventDefault();
    e.stopPropagation();
  }
});

// 自動檢查是否超時 → 鎖按鈕
document.querySelectorAll(".quiz-btn").forEach(btn => {
  const setId = btn.dataset.setid;
  const timeLimit = parseFloat(btn.dataset.timelimit || 0);
  const storageKey = `quiz_timer_${setId}`;
  const limitKey   = `quiz_limit_${setId}`;
  const overKey    = `quiz_over_${setId}`;
  const savedTime  = parseInt(localStorage.getItem(storageKey) ?? "0", 10);
  const savedLimit = parseInt(localStorage.getItem(limitKey)   ?? "0", 10);
  const currentLimit = Math.round(timeLimit * 60);

  // 老師改限時 → 重置舊狀態（包含超時旗標）
  if (timeLimit > 0 && savedLimit !== currentLimit) {
    localStorage.setItem(limitKey, currentLimit);
    localStorage.removeItem(storageKey);
    localStorage.removeItem(overKey);
  }

  const isOver = localStorage.getItem(overKey) === "1" ||
                 (localStorage.getItem(storageKey) !== null && savedTime <= 0);

  if (timeLimit > 0 && isOver) {
    lockBtn(btn);
  }
});
</script>


</body>
</html>
