<?php
session_start();
require 'db.php';// ✅ 引入你的導覽列

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

// 🔹 取得該章節的題目
$sql = "SELECT id, title, difficulty, passed, last_ai_comment 
        FROM questions 
        WHERE chapter = ? 
        ORDER BY id ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $chapterId);
$stmt->execute();
$questions = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($chapter['ChapterName']) ?> - 程式練習</title>
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
</style>
</head>
<body>
<?php include 'Navbar.php'; ?>
<!-- 🔹 章節標題 -->
<div class="container my-4">
  <h2 class="fw-bold text-center text-dark mb-3">
    📘 <?= htmlspecialchars($chapter['title']) ?>
  </h2>
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

                        <?php if ($q['passed']): ?>
                            <p class="text-success small mb-1">✅ 已通過</p>
                        <?php else: ?>
                            <p class="text-danger small mb-1">❌ 尚未通過</p>
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
