<?php
require 'db.php';
session_start();

$setId = $_GET['set'] ?? null;
if (!$setId) die("❌ 未指定題組 ID");

$stmt = $conn->prepare("SELECT * FROM test_groups WHERE id=?");
$stmt->bind_param("i", $setId);
$stmt->execute();
$testGroup = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$testGroup) die("❌ 找不到該題組");

$questionIds = json_decode($testGroup['question_ids'], true);
$inClause = implode(',', array_fill(0, count($questionIds), '?'));

$sql = "SELECT * FROM questions WHERE id IN ($inClause)";
$stmt = $conn->prepare($sql);
$stmt->bind_param(str_repeat('i', count($questionIds)), ...$questionIds);
$stmt->execute();
$questions = $stmt->get_result();
?>

<h3>📘 <?= htmlspecialchars($testGroup['name']) ?></h3>
<p><?= htmlspecialchars($testGroup['description']) ?></p>
<hr>

<?php while ($q = $questions->fetch_assoc()): ?>
  <div class="mb-4 p-3 border rounded bg-white shadow-sm">
    <h5>題目：<?= htmlspecialchars($q['title']) ?></h5>
    <p><?= nl2br(htmlspecialchars($q['description'])) ?></p>
    <a href="practice.php?question_id=<?= $q['id'] ?>" class="btn btn-outline-primary btn-sm">作答</a>
  </div>
<?php endwhile; ?>
