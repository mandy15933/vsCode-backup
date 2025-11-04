<?php
session_start();
require 'db.php';

// 取得題目資料（JOIN chapters）
$sql = "
  SELECT q.id, q.title, q.difficulty, q.created_at, c.title AS chapter_title
  FROM questions q
  LEFT JOIN chapters c ON q.chapter = c.id
  ORDER BY q.id DESC
";
$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<title>📚 題庫管理</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

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

  <div class="card shadow-sm">
    <div class="card-body p-0">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr class="text-center">
            <th style="width:5%">ID</th>
            <th style="width:25%">題目標題</th>
            <th style="width:20%">章節</th>
            <th style="width:10%">難度</th>
            <th style="width:20%">建立時間</th>
            <th style="width:20%">操作</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($result->num_rows > 0): ?>
            <?php while ($row = $result->fetch_assoc()): ?>
              <tr class="text-center">
                <td><?=$row['id']?></td>
                <td class="text-start"><?=htmlspecialchars($row['title'])?></td>
                <td><?=htmlspecialchars($row['chapter_title'] ?? '未分類')?></td>
                <td>
                  <?php
                    $color = [
                      '簡單' => 'success',
                      '中等' => 'warning',
                      '困難' => 'danger'
                    ][$row['difficulty']] ?? 'secondary';
                  ?>
                  <span class="badge bg-<?=$color?>"><?=$row['difficulty']?></span>
                </td>
                <td><?=date('Y-m-d H:i', strtotime($row['created_at']))?></td>
                <td>
                  <a href="edit_question.php?id=<?=$row['id']?>" class="btn btn-sm btn-outline-primary btn-action">
                    <i class="fa-solid fa-pen"></i> 編輯
                  </a>
                  <button class="btn btn-sm btn-outline-danger btn-action" onclick="deleteQuestion(<?=$row['id']?>)">
                    <i class="fa-solid fa-trash"></i> 刪除
                  </button>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr><td colspan="6" class="text-center text-muted py-4">目前沒有題目資料。</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
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
        Swal.fire("❌ 系統錯誤", err, "error");
      });
    }
  });
}
</script>
</body>
</html>
