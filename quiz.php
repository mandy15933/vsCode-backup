<?php
require 'db.php';
session_start();

$setId = $_GET['set'] ?? null;
if (!$setId) die("❌ 未指定題組 ID");

// 目前登入者
$userId = $_SESSION['user_id'] ?? 1;

// 讀取題組資料
$stmt = $conn->prepare("SELECT * FROM test_groups WHERE id=?");
$stmt->bind_param("i", $setId);
$stmt->execute();
$testGroup = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$testGroup) die("❌ 找不到該題組");

// 解析題目 ID
$questionIds = json_decode($testGroup['question_ids'], true);
if (empty($questionIds)) {
    echo "<div class='alert alert-warning m-4'>⚠️ 這個題組目前沒有包含任何題目。</div>";
    exit;
}

// 查詢學生是否通過每題
$placeholders = implode(',', array_fill(0, count($questionIds), '?'));
$sql = "SELECT question_id, MAX(is_correct) AS passed 
        FROM student_answers 
        WHERE user_id=? AND question_id IN ($placeholders)
        GROUP BY question_id";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i' . str_repeat('i', count($questionIds)), $userId, ...$questionIds);
$stmt->execute();
$result = $stmt->get_result();

$passStatus = [];
while ($row = $result->fetch_assoc()) {
    $passStatus[$row['question_id']] = (int)$row['passed'];
}
$stmt->close();

// 查詢題目詳細資料
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

// 按 JSON 順序排列
$orderedQuestions = [];
foreach ($questionIds as $id) {
    if (isset($questionMap[$id])) {
        $orderedQuestions[] = $questionMap[$id];
    }
}

// 🔹 過濾掉已通過的題目
$filteredQuestions = [];
foreach ($orderedQuestions as $q) {
    $qid = $q['id'];
    $status = $passStatus[$qid] ?? null;
    if ($status !== 1) {  // ✅ 只顯示未通過或未作答
        $filteredQuestions[] = $q;
    }
}

// 讀取測驗題組名稱與題目數量
$stmt = $conn->prepare("SELECT name, question_ids FROM test_groups WHERE id=?");
$stmt->bind_param("i", $setId);
$stmt->execute();
$groupData = $stmt->get_result()->fetch_assoc();
$stmt->close();

$testGroupName = $groupData['name'] ?? '未命名題組';
$questionIds = json_decode($groupData['question_ids'], true) ?? [];
$totalInGroup = count($questionIds);

// 🔹 計算學生已通過題數
$placeholders = implode(',', array_fill(0, $totalInGroup, '?'));
$sql = "SELECT COUNT(DISTINCT question_id) AS passed_count
      FROM student_answers
      WHERE user_id=? AND is_correct=1 AND question_id IN ($placeholders)";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i' . str_repeat('i', $totalInGroup), $userId, ...$questionIds);
$stmt->execute();
$passData = $stmt->get_result()->fetch_assoc();
$stmt->close();

$passedCount = (int)($passData['passed_count'] ?? 0);
$percent = $totalInGroup > 0 ? round(($passedCount / $totalInGroup) * 100, 1) : 0;

$allPassed = empty($filteredQuestions); // 若全數題目皆通過
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<title>🧩 <?= htmlspecialchars($testGroup['name']) ?> 題組</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<style>
body { background: #fff8e1; font-family: 'Chiron GoRound TC', sans-serif; }
.card:hover { transform: translateY(-3px); transition: 0.2s; }
.status-badge {
    font-size: 0.9rem;
    border-radius: 8px;
    padding: 4px 8px;
}
</style>
</head>
<body>

<?php include 'Navbar.php'; ?>
<div class="container my-4">
  <div class="card shadow-sm mb-4 border-warning">
      <div class="card-body">
          <h5 class="mb-3 text-dark">
              🧩 測驗模式：<?= htmlspecialchars($testGroupName) ?>
          </h5>

          <div class="progress" style="height: 25px; border-radius: 8px;">
              <div class="progress-bar <?= $passedCount >= $totalInGroup ? 'bg-success' : 'bg-info' ?>" 
                  role="progressbar" 
                  style="width: <?= $percent ?>%;" 
                  aria-valuenow="<?= $passedCount ?>" 
                  aria-valuemin="0" 
                  aria-valuemax="<?= $totalInGroup ?>">
                  <?= $passedCount ?> / <?= $totalInGroup ?> 題已通過
              </div>
          </div>
      </div>
  </div>
</div>


<div class="container mt-4 mb-5">
    <div class="text-center mb-4">
        <h3 class="fw-bold text-dark">🧩 題組：<?= htmlspecialchars($testGroup['name']) ?></h3>
        <p class="text-muted"><?= nl2br(htmlspecialchars($testGroup['description'] ?? '')) ?></p>
        <p class="text-secondary small">章節範圍：<?= htmlspecialchars($testGroup['chapter_range'] ?? '-') ?></p>
        <hr>
    </div>

    <?php if ($allPassed): ?>
        <div class="alert alert-success text-center p-4 fs-5">
            🎉 恭喜！你已完成此題組的所有題目！
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($filteredQuestions as $q): 
                $qid = $q['id'];
                $status = $passStatus[$qid] ?? null;
                if ($status === 1) {
                    $badge = "<span class='badge bg-success status-badge'>✅ 已通過</span>";
                } elseif ($status === 0) {
                    $badge = "<span class='badge bg-danger status-badge'>❌ 未通過</span>";
                } else {
                    $badge = "<span class='badge bg-secondary status-badge'>⏳ 未作答</span>";
                }
            ?>
                <div class="col-md-6">
                    <div class="card border-warning shadow-sm h-100">
                        <div class="card-body d-flex flex-column justify-content-between">
                            <div>
                                <h5 class="fw-bold text-dark mb-2">
                                    <?= htmlspecialchars($q['title']) ?> <?= $badge ?>
                                </h5>
                                <p class="text-muted small"><?= nl2br(htmlspecialchars($q['description'])) ?></p>
                            </div>
                            <a href="practice_drag.php?question_id=<?= $qid ?>&test_group_id=<?= $testGroup['id'] ?>" 
                               class="btn btn-outline-warning mt-2">▶ 開始作答</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="mt-5 text-center">
        <a href="quiz_select.php" class="btn btn-secondary">🏠 返回題組選單</a>
    </div>
</div>
</body>
</html>
