<?php
require 'db.php';
session_start();

$setId = $_GET['set'] ?? null;
if (!$setId) die("❌ 未指定題組 ID");

// 🔹 登入者
$userId = $_SESSION['user_id'] ?? 1;

// 🔹 讀取題組資料
$stmt = $conn->prepare("SELECT * FROM test_groups WHERE id=?");
$stmt->bind_param("i", $setId);
$stmt->execute();
$testGroup = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$testGroup) die("❌ 找不到該題組");

$timeLimit = $testGroup['time_limit'] ?? null; // ✅ 加這行

// 題目 ID 列表
$questionIds = json_decode($testGroup['question_ids'], true);
if (empty($questionIds)) {
    echo "<div class='alert alert-warning m-4'>⚠️ 這個題組目前沒有包含任何題目。</div>";
    exit;
}

// ===============================
// 🧠 篩出題目與通過狀態
// ===============================
$placeholders = implode(',', array_fill(0, count($questionIds), '?'));
$sql = "SELECT question_id, MAX(is_correct) AS passed
        FROM student_answers
        WHERE user_id = ? AND answer_mode='exam' AND test_group_id=? 
          AND question_id IN ($placeholders)
        GROUP BY question_id";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii' . str_repeat('i', count($questionIds)), $userId, $setId, ...$questionIds);
$stmt->execute();
$result = $stmt->get_result();

$passStatus = [];
while ($row = $result->fetch_assoc()) {
    $passStatus[$row['question_id']] = (int)$row['passed'];
}
$stmt->close();

// 讀取題目
$sql = "SELECT * FROM questions WHERE id IN ($placeholders)";
$stmt = $conn->prepare($sql);
$stmt->bind_param(str_repeat('i', count($questionIds)), ...$questionIds);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

$questionMap = [];
while ($row = $result->fetch_assoc()) {
    $questionMap[$row['id']] = $row;
}

$orderedQuestions = [];
foreach ($questionIds as $id) {
    if (isset($questionMap[$id])) $orderedQuestions[] = $questionMap[$id];
}

$filteredQuestions = [];
foreach ($orderedQuestions as $q) {
    $qid = $q['id'];
    $status = $passStatus[$qid] ?? null;
    if ($status !== 1) $filteredQuestions[] = $q;
}

$totalInGroup = count($questionIds);
$placeholders = implode(',', array_fill(0, $totalInGroup, '?'));
$sql = "SELECT COUNT(DISTINCT question_id) AS passed_count
        FROM student_answers
        WHERE user_id=? AND is_correct=1 AND answer_mode='exam'
          AND test_group_id=? AND question_id IN ($placeholders)";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii' . str_repeat('i', $totalInGroup), $userId, $setId, ...$questionIds);
$stmt->execute();
$passData = $stmt->get_result()->fetch_assoc();
$stmt->close();

$passedCount = (int)($passData['passed_count'] ?? 0);
$percent = $totalInGroup > 0 ? round(($passedCount / $totalInGroup) * 100, 1) : 0;
$allPassed = empty($filteredQuestions);
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<title>🧩 <?= htmlspecialchars($testGroup['name']) ?> 題組</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="font.css">
<link rel="stylesheet" href="anime-yellow-theme.css">
</head>
<body>

<?php include 'Navbar.php'; ?>

<div class="container my-5">
  <div class="card shadow-sm mb-4 border-0"
     style="background: linear-gradient(180deg, #fffde7 0%, #fff8e1 100%);
            border-radius: 20px; box-shadow: 0 6px 12px rgba(255, 213, 79, 0.3);">
    <div class="card-body">
      <h4 class="fw-bold mb-3" style="color:#4e342e;">
        🧠 測驗模式：<?= htmlspecialchars($testGroup['name']) ?>
      </h4>
      <div class="progress" style="height: 26px; border-radius: 12px; background:#fff9c4; border:2px solid #ffe082;">
        <div class="progress-bar fw-bold text-dark"
             role="progressbar"
             style="width: <?= $percent ?>%;
                    background: linear-gradient(to right, #ffca28, #ffd54f);
                    border-radius: 10px; transition: width 0.6s ease;"
             aria-valuenow="<?= $passedCount ?>" aria-valuemin="0" aria-valuemax="<?= $totalInGroup ?>">
             <?= $passedCount ?> / <?= $totalInGroup ?> 題已通過
        </div>
      </div>
    </div>
  </div>

  <!-- ✅ 倒數區 -->
  <?php if (!empty($timeLimit)): ?>
  <div id="timerBox" class="text-center mb-3 fs-5 fw-bold text-danger"></div>
  <?php endif; ?>

  <div class="text-center mb-5">
      <h3 class="fw-bold text-dark">🧩 題組：<?= htmlspecialchars($testGroup['name']) ?></h3>
      <p class="text-muted"><?= nl2br(htmlspecialchars($testGroup['description'] ?? '')) ?></p>
      <p class="text-secondary small">章節範圍：<?= htmlspecialchars($testGroup['chapter_range'] ?? '-') ?></p>
      <hr style="border-top: 2px dashed #ffecb3;">
  </div>

  <?php if ($allPassed): ?>
      <div class="alert alert-success text-center p-4 fs-5 rounded-4 shadow-sm" style="background:#e8f5e9; border:2px solid #a5d6a7;">
          🎉 恭喜！你已完成此題組的所有題目！
      </div>
  <?php else: ?>
      <div class="row g-4">
          <?php foreach ($filteredQuestions as $q): ?>
              <div class="col-md-6 col-lg-4">
                  <div class="card shadow-sm border-0 h-100" style="background:#fffef3; border-radius:18px;">
                      <div class="card-body d-flex flex-column justify-content-between">
                          <div>
                              <h5 class="fw-bold text-dark mb-2">
                                  <?= htmlspecialchars($q['title']) ?>
                              </h5>
                              <p class="text-muted small"><?= nl2br(htmlspecialchars($q['description'])) ?></p>
                          </div>
                          <a href="practice_drag.php?question_id=<?= $q['id'] ?>&test_group_id=<?= $testGroup['id'] ?>" 
                             class="btn btn-submitting w-100 mt-3">▶ 開始作答</a>
                      </div>
                  </div>
              </div>
          <?php endforeach; ?>
      </div>
  <?php endif; ?>

  <div class="mt-5 text-center">
      <a href="quiz_select.php" class="btn btn-secondary btn-lg px-4">🏠 返回題組選單</a>
  </div>
</div>

<!-- ✅ 倒數計時 -->
<!-- ✅ 倒數計時（含自動偵測限時變更 + 超時禁用按鈕） -->
<?php if (!empty($timeLimit)): ?>
<script>
const storageKey = "quiz_timer_<?= $setId ?>";
const limitKey = "quiz_limit_<?= $setId ?>";
const currentLimit = <?= (int)$timeLimit ?> * 60;
const savedLimit = parseInt(localStorage.getItem(limitKey) || 0);
let timeLeft = currentLimit;

// 🧠 若題組限時有變動，就重置計時器
if (savedLimit !== currentLimit) {
  localStorage.removeItem(storageKey);
  localStorage.setItem(limitKey, currentLimit);
  timeLeft = currentLimit;
} else if (localStorage.getItem(storageKey)) {
  timeLeft = parseInt(localStorage.getItem(storageKey));
}

const timerBox = document.getElementById("timerBox");

// 🚫 禁用作答按鈕
function disableButtons() {
  document.querySelectorAll(".btn-submitting").forEach(btn => {
    btn.disabled = true;
    btn.classList.add("disabled");
    btn.textContent = "⏳ 時間結束";
    btn.style.cursor = "not-allowed";
    btn.style.background = "#ccc";
    btn.style.color = "#666";
  });
}

// ⏱ 更新倒數
function updateTimer() {
  const min = Math.floor(timeLeft / 60);
  const sec = timeLeft % 60;
  timerBox.textContent = `⏰ 剩餘時間：${min}:${sec.toString().padStart(2, "0")}`;
  localStorage.setItem(storageKey, timeLeft);

  if (timeLeft <= 0) {
    // 到期時
    clearInterval(timer);
    localStorage.setItem("<?= "quiz_timer_$setId" ?>", 0);           // 設 0（不要刪）
    localStorage.setItem("<?= "quiz_over_$setId" ?>", "1");          // 打上超時旗標

    Swal.fire({
    icon: "warning",
    title: "時間到！",
    text: "測驗時間已結束，系統將自動結束作答。"
    }).then(() => {
    window.location.href = "quiz_select.php";
    });
  }
  timeLeft--;
}

updateTimer();
const timer = setInterval(updateTimer, 1000);
</script>
<?php endif; ?>

</body>
</html>
