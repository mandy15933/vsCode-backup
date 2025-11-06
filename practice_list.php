<?php
session_start();
require 'db.php';

// 🔹 取得章節 ID
$chapterId = $_GET['chapter'] ?? null;
if (!$chapterId) {
    die("❌ 請提供章節 ID，例如：practice_list.php?chapter=1");
}

// 🔹 登入檢查
$isLoggedIn = isset($_SESSION['user_id']);
$userId = $_SESSION['user_id'] ?? 0;

// 🔹 取得章節資料
$stmt = $conn->prepare("SELECT title, image_path FROM chapters WHERE id = ?");
$stmt->bind_param("i", $chapterId);
$stmt->execute();
$chapter = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$chapter) {
    die("❌ 找不到章節 (ID: $chapterId)");
}

// 🔹 查詢題目總數與完成題數
$totalQuestions = 0;
$doneQuestions = 0;

if ($isLoggedIn) {
    $stmt = $conn->prepare("
        SELECT 
        (SELECT COUNT(*) 
            FROM questions 
            WHERE chapter = ?)                                AS total,
        (SELECT COUNT(DISTINCT q.id)
            FROM questions q
            JOIN student_answers sa
            ON sa.question_id = q.id
            AND sa.user_id = ?
            AND sa.is_correct = 1
            WHERE q.chapter = ?)                              AS done
    ");
    $stmt->bind_param("iii", $chapterId, $userId, $chapterId);
    $stmt->execute();
    $progress = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $totalQuestions = (int)($progress['total'] ?? 0);
    $doneQuestions  = (int)($progress['done'] ?? 0);
}

// 🔹 查詢題目列表（含是否通過）
if ($isLoggedIn) {
    $stmt = $conn->prepare("
        SELECT 
            q.id, q.title, q.difficulty, q.description,
            MAX(CASE WHEN sa.is_correct = 1 THEN 1 ELSE 0 END) AS is_correct
        FROM questions q
        LEFT JOIN student_answers sa 
            ON q.id = sa.question_id AND sa.user_id = ?
        WHERE q.chapter = ?
        GROUP BY q.id, q.title, q.difficulty, q.description
        ORDER BY q.id ASC
    ");
    $stmt->bind_param("ii", $userId, $chapterId);
} else {
    $stmt = $conn->prepare("
        SELECT id, title, difficulty, description 
        FROM questions 
        WHERE chapter = ? 
        ORDER BY id ASC
    ");
    $stmt->bind_param("i", $chapterId);
}

$stmt->execute();
$questions = $stmt->get_result();
$stmt->close();
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($chapter['title']) ?> - 程式練習</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<style>
body {
    background-color: #fff8e1;
    font-family: 'Noto Sans TC', sans-serif;
}
.question-card {
    border-radius: 16px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.1);
    transition: transform 0.2s;
}
.question-card:hover {
    transform: translateY(-5px);
}
.badge-diff {
    font-size: 0.9rem;
}
.progress {
    height: 25px;
    border-radius: 10px;
}
.progress-bar {
    font-weight: bold;
}
</style>
</head>
<body>

<?php include 'Navbar.php'; ?>

<!-- 🔹 章節標題 -->
<div class="container my-4">
  <h2 class="fw-bold text-center text-dark mb-3">
    📘 <?= htmlspecialchars($chapter['title']) ?>
  </h2>

  <?php if ($isLoggedIn && $totalQuestions > 0): ?>
  <?php $percent = round(($doneQuestions / $totalQuestions) * 100, 1); ?>
  <div class="mb-4">
    <div class="progress shadow-sm">
      <div class="progress-bar 
          <?= $doneQuestions >= $totalQuestions ? 'bg-success' : 'bg-warning text-dark' ?>" 
          role="progressbar" 
          style="width: <?= $percent ?>%;" 
          aria-valuenow="<?= $doneQuestions ?>" 
          aria-valuemin="0" 
          aria-valuemax="<?= $totalQuestions ?>">
          <?= $doneQuestions ?>/<?= $totalQuestions ?> 已完成 (<?= $percent ?>%)
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- 🔹 題目列表 -->
<div class="container mb-5">
  <div class="row g-4">
    <?php if ($questions->num_rows > 0): ?>
        <?php while ($q = $questions->fetch_assoc()): ?>
            <div class="col-md-4">
                <div class="card question-card border-0 p-3">
                    <div class="card-body">
                        <h5 class="fw-bold text-dark"><?= htmlspecialchars($q['title']) ?></h5>
                        <p class="text-muted small mb-1">
                            難度：
                            <?php if ($q['difficulty'] === '簡單'): ?>
                                <span class="badge bg-success badge-diff">簡單</span>
                            <?php elseif ($q['difficulty'] === '中等'): ?>
                                <span class="badge bg-warning text-dark badge-diff">中等</span>
                            <?php else: ?>
                                <span class="badge bg-danger badge-diff">困難</span>
                            <?php endif; ?>
                        </p>

                        <?php if ($isLoggedIn): ?>
                            <?php if ($q['is_correct']): ?>
                                <p class="text-success small mb-1">✅ 已通過</p>
                            <?php else: ?>
                                <p class="text-danger small mb-1">❌ 尚未通過</p>
                            <?php endif; ?>
                        <?php endif; ?>

                        <a href="practice_drag.php?question_id=<?= $q['id'] ?>" 
                           class="btn btn-warning w-100 mt-2">💻 開始練習</a>
                    </div>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <p class="text-center text-muted">目前此章節尚無題目。</p>
    <?php endif; ?>
  </div>
</div>

</body>
</html>
