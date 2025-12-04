<?php
session_start();
require 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo "<script>
        alert('您沒有權限進入此頁面');
        window.location.href = 'index.php';
    </script>";
    exit;
}

// 🔍 取得章節清單
$chapters = $conn->query("SELECT id, title FROM chapters ORDER BY id")->fetch_all(MYSQLI_ASSOC);

// 🔍 接收篩選條件
$filterChapter = $_GET['chapter'] ?? '';
$filterDifficulty = $_GET['difficulty'] ?? '';

// 🔹 基本查詢
$sql = "
  SELECT q.id, q.title, q.difficulty, q.created_at, q.is_hidden, c.title AS chapter_title
  FROM questions q
  LEFT JOIN chapters c ON q.chapter = c.id
  WHERE 1
";

// 🔹 根據篩選條件動態增加 WHERE 條件
$params = [];
$types = '';

if ($filterChapter !== '') {
  $sql .= " AND q.chapter = ? ";
  $params[] = $filterChapter;
  $types .= 'i';
}

if ($filterDifficulty !== '') {
  $sql .= " AND q.difficulty = ? ";
  $params[] = $filterDifficulty;
  $types .= 's';
}

$sql .= " ORDER BY q.id DESC";
$stmt = $conn->prepare($sql);
if ($params) {
  $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<title>題庫管理</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="anime-yellow-theme.css">
<style>
body {
  background-color: #fffef8;
  font-family: "Noto Sans TC", sans-serif;
}
.table thead th {
  background-color: #f8f9fa;
}
.btn-action {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.filter-bar {
  background: #fff;
  border-radius: 10px;
  box-shadow: 0 2px 6px rgba(0,0,0,0.1);
  padding: 15px 20px;
}
</style>
</head>
<body>
<?php include 'Navbar.php'; ?>

<div class="container mt-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2>📘 題庫管理</h2>
    <a href="add_question.php" class="btn btn-primary">
      <i class="fa-solid fa-plus"></i> 新增題目
    </a>
  </div>

  <!-- 🔍 篩選列 -->
  <form method="get" class="filter-bar mb-4">
    <div class="row g-2 align-items-center">
      <div class="col-md-4">
        <label class="form-label mb-1">章節</label>
        <select name="chapter" class="form-select">
          <option value="">全部章節</option>
          <?php foreach ($chapters as $c): ?>
            <option value="<?= $c['id'] ?>" <?= ($filterChapter == $c['id']) ? 'selected' : '' ?>>
              第 <?= $c['id'] ?> 章：<?= htmlspecialchars($c['title']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label mb-1">難度</label>
        <select name="difficulty" class="form-select">
          <option value="">全部難度</option>
          <option value="簡單" <?= ($filterDifficulty == '簡單') ? 'selected' : '' ?>>簡單</option>
          <option value="中等" <?= ($filterDifficulty == '中等') ? 'selected' : '' ?>>中等</option>
          <option value="困難" <?= ($filterDifficulty == '困難') ? 'selected' : '' ?>>困難</option>
        </select>
      </div>
      <div class="col-md-3 d-flex align-items-end">
        <button type="submit" class="btn btn-outline-primary w-100">
          <i class="fa-solid fa-magnifying-glass"></i> 搜尋
        </button>
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <a href="Admin_question.php" class="btn btn-outline-secondary w-100">
          <i class="fa-solid fa-rotate-left"></i> 重置
        </a>
      </div>
    </div>
  </form>

  <div class="card shadow-sm">
    <div class="card-body p-0">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr class="text-center">
            <th style="width:5%">ID</th>
            <th style="width:25%">題目標題</th>
            <th style="width:20%">章節</th>
            <th style="width:10%">難度</th>
            <th style="width:10%">是否隱藏</th>
            <th style="width:20%">建立時間</th>
            <th style="width:20%">操作</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($result->num_rows > 0): ?>
            <?php while ($row = $result->fetch_assoc()): ?>
              <tr class="text-center">
                <td><?= $row['id'] ?></td>
                <td class="text-start"><?= htmlspecialchars($row['title']) ?></td>
                <td><?= htmlspecialchars($row['chapter_title'] ?? '未分類') ?></td>
                <td>
                  <?php
                    $color = [
                      '簡單' => 'success',
                      '中等' => 'warning',
                      '困難' => 'danger'
                    ][$row['difficulty']] ?? 'secondary';
                  ?>
                  <span class="badge bg-<?= $color ?>"><?= $row['difficulty'] ?></span>
                </td>
                <td>
                  <?php if ($row['is_hidden']): ?>
                    <span class="badge bg-secondary">已隱藏</span>
                  <?php else: ?>
                    <span class="badge bg-success">顯示中</span>
                  <?php endif; ?>
                </td>
                <td><?= date('Y-m-d H:i', strtotime($row['created_at'])) ?></td>
                <td>
                  <a href="edit_question.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-primary btn-action">
                    <i class="fa-solid fa-pen"></i> 編輯
                  </a>
                  <a href="practice_drag.php?question_id=<?= $row['id'] ?>" target="_blank" class="btn btn-sm btn-outline-success btn-action">
                    <i class="fa-solid fa-play"></i> 練習
                  </a>
                  <button class="btn btn-sm btn-outline-danger btn-action" onclick="deleteQuestion(<?= $row['id'] ?>)">
                    <i class="fa-solid fa-trash"></i> 刪除
                    </button>
                  <button class="btn btn-sm btn-outline-warning btn-action"
                        onclick="toggleHidden(<?= $row['id'] ?>, <?= $row['is_hidden'] ?>)">
                  <i class="fa-solid fa-eye-slash"></i> <?= $row['is_hidden'] ? '顯示' : '隱藏' ?>
                </button>

                </td>
              </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr><td colspan="6" class="text-center text-muted py-4">目前沒有符合條件的題目。</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
function toggleHidden(id, currentStatus) {
  const actionText = currentStatus ? "恢復顯示" : "隱藏";

  Swal.fire({
    title: `確定要${actionText}這題嗎？`,
    icon: "question",
    showCancelButton: true,
    confirmButtonText: `是的，${actionText}`,
    cancelButtonText: "取消"
  }).then((result) => {
    if (result.isConfirmed) {
      fetch("toggle_hidden.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded"
        },
        body: new URLSearchParams({
          id: id,
          is_hidden: currentStatus ? 0 : 1
        })
      })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          Swal.fire("完成！", `題目已${actionText}`, "success").then(() => {
            location.reload();
          });
        } else {
          Swal.fire("錯誤", data.message || "操作失敗", "error");
        }
      })
      .catch(err => Swal.fire("錯誤", String(err), "error"));
    }
  });
}

function deleteQuestion(id) {
  Swal.fire({
    title: "確定要刪除此題目嗎？",
    text: "刪除後無法復原！",
    icon: "warning",
    showCancelButton: true,
    confirmButtonText: "是的，刪除",
    cancelButtonText: "取消",
    confirmButtonColor: "#d33",
    cancelButtonColor: "#3085d6"
  }).then((result) => {
    if (result.isConfirmed) {
      fetch("delete_question.php", {
        method: "POST",
        headers: {"Content-Type": "application/x-www-form-urlencoded"},
        body: new URLSearchParams({ id })
      })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          Swal.fire("✅ 已刪除！", "題目已成功刪除。", "success").then(() => {
            location.reload();
          });
        } else {
          Swal.fire("❌ 錯誤", data.message || "刪除失敗", "error");
        }
      })
      .catch(err => {
        Swal.fire("❌ 系統錯誤", String(err), "error");
      });
    }
  });
}
</script>
</body>
</html>
