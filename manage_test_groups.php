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

// ✅ 讀取所有題組
$groups = $conn->query("SELECT * FROM test_groups ORDER BY id DESC");

// ✅ 讀取所有題目（for 新增/編輯 題組時選擇）
$questions = $conn->query("SELECT id, title, chapter, difficulty FROM questions ORDER BY chapter, id")->fetch_all(MYSQLI_ASSOC);

// ✅ 新增題組
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $name = $_POST['name'];
    $description = $_POST['description'] ?? '';
    $chapter_range = $_POST['chapter_range'] ?? '';
    $selected = $_POST['question_ids'] ?? [];
    $time_limit = $_POST['time_limit'] ?? null; // ✅ 取限時

    if (!empty($selected)) {
        $json = json_encode($selected, JSON_UNESCAPED_UNICODE); // ✅ 你漏掉這行！
        $stmt = $conn->prepare("INSERT INTO test_groups (name, description, chapter_range, question_ids, time_limit) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssi", $name, $description, $chapter_range, $json, $time_limit);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: manage_test_groups.php");
    exit;
}

// ✅ 刪除題組
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $conn->query("DELETE FROM test_groups WHERE id=$id");
    header("Location: manage_test_groups.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<title>🧩 題組管理 | Python學習平台</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>

<?php include 'Navbar.php'; ?>

<div class="container my-5">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h2>🧩 題組管理</h2>
    <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#addGroupModal">➕ 新增題組</button>
  </div>

  <table class="table table-hover align-middle">
    <thead class="table-warning">
      <tr>
        <th>題組名稱</th>
        <th>章節範圍</th>
        <th>限時（分鐘）</th>
        <th>題目數量</th>
        <th>建立日期</th>
        <th>操作</th>
      </tr>
    </thead>
    <tbody>
      <?php while($row = $groups->fetch_assoc()): ?>
        <?php $count = count(json_decode($row['question_ids'], true) ?? []); ?>
        <tr>
          <td><?= htmlspecialchars($row['name']) ?></td>
          <td><?= htmlspecialchars($row['chapter_range'] ?? '-') ?></td>
          <td><?= htmlspecialchars($row['time_limit'] ?? '-') ?></td>
          <td><?= $count ?> 題</td>
          <td><?= date('Y-m-d', strtotime($row['created_at'])) ?></td>
          <td>
            <button class="btn btn-sm btn-outline-primary" onclick='editGroup(<?= json_encode($row, JSON_UNESCAPED_UNICODE) ?>)'>✏️ 編輯</button>
            <a href="quiz_select.php?set=<?= $row['id'] ?>" class="btn btn-sm btn-outline-success">📖 檢視</a>
            <a href="?delete=<?= $row['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('確定要刪除這個題組嗎？')">🗑 刪除</a>
          </td>
        </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</div>

<!-- 🟡 新增題組 Modal -->
<div class="modal fade" id="addGroupModal" tabindex="-1" aria-labelledby="addGroupModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="add">
        <div class="modal-header bg-warning">
          <h5 class="modal-title">新增題組</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">題組名稱</label>
            <input type="text" name="name" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">答題限時（分鐘）</label>
            <input type="number" name="time_limit" class="form-control" min="1" placeholder="例如：30">
          </div>
          <div class="mb-3">
            <label class="form-label">題組說明</label>
            <textarea name="description" class="form-control" rows="2"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">章節範圍</label>
            <input type="text" name="chapter_range" class="form-control" placeholder="例如：1-3">
          </div>

          <div class="mb-3">
            <label class="form-label">選擇包含的題目</label>
            <div class="border rounded p-3" style="max-height: 300px; overflow-y: auto;">
              <?php foreach ($questions as $q): ?>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="question_ids[]" value="<?= $q['id'] ?>" id="q<?= $q['id'] ?>">
                  <label class="form-check-label" for="q<?= $q['id'] ?>">
                    [第 <?= $q['chapter'] ?> 章] <?= htmlspecialchars($q['title']) ?> 
                    <span class="badge bg-secondary"><?= $q['difficulty'] ?></span>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
          <button type="submit" class="btn btn-warning">儲存題組</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ✏️ 編輯題組 Modal -->
<div class="modal fade" id="editGroupModal" tabindex="-1" aria-labelledby="editGroupModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form id="editGroupForm">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title">編輯題組</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="editGroupId">
          <div class="mb-3">
            <label class="form-label">題組名稱</label>
            <input type="text" name="name" id="editGroupName" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">答題限時（分鐘）</label>
            <input type="number" name="time_limit" id="editGroupTime" class="form-control" min="1">
          </div>
          <div class="mb-3">
            <label class="form-label">題組說明</label>
            <textarea name="description" id="editGroupDesc" class="form-control" rows="2"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">章節範圍</label>
            <input type="text" name="chapter_range" id="editGroupRange" class="form-control">
          </div>
          <div class="mb-3">
            <label class="form-label">選擇包含的題目</label>
            <div id="editQuestionList" class="border rounded p-3" style="max-height: 300px; overflow-y: auto;">
              <?php foreach ($questions as $q): ?>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" value="<?= $q['id'] ?>" id="editQ<?= $q['id'] ?>">
                  <label class="form-check-label" for="editQ<?= $q['id'] ?>">
                    [第 <?= $q['chapter'] ?> 章] <?= htmlspecialchars($q['title']) ?> 
                    <span class="badge bg-secondary"><?= $q['difficulty'] ?></span>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
          <button type="submit" class="btn btn-primary">💾 更新題組</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// 🔹 打開編輯題組 Modal
function editGroup(group) {
  const modal = new bootstrap.Modal(document.getElementById('editGroupModal'));
  document.getElementById("editGroupId").value = group.id;
  document.getElementById("editGroupTime").value = group.time_limit || "";
  document.getElementById("editGroupName").value = group.name;
  document.getElementById("editGroupDesc").value = group.description || "";
  document.getElementById("editGroupRange").value = group.chapter_range || "";

  // 取消所有勾選
  document.querySelectorAll("#editQuestionList input[type='checkbox']").forEach(cb => cb.checked = false);

  // 勾選原本的題目
  try {
    const ids = JSON.parse(group.question_ids || "[]");
    ids.forEach(id => {
      const checkbox = document.getElementById("editQ" + id);
      if (checkbox) checkbox.checked = true;
    });
  } catch (e) { console.error("❌ 無法解析題組題目 JSON", e); }

  modal.show();
}

// 🔹 編輯題組送出
document.getElementById("editGroupForm").addEventListener("submit", function(e) {
  e.preventDefault();
  const id = document.getElementById("editGroupId").value;
  const time_limit = document.getElementById("editGroupTime").value.trim();
  const name = document.getElementById("editGroupName").value.trim();
  const description = document.getElementById("editGroupDesc").value.trim();
  const chapter_range = document.getElementById("editGroupRange").value.trim();
  const question_ids = Array.from(document.querySelectorAll("#editQuestionList input:checked")).map(cb => cb.value);

  fetch("update_test_group.php", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify({ id, name, description, chapter_range, question_ids, time_limit })
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      Swal.fire("✅ 已更新", "題組資料已成功修改", "success").then(() => location.reload());
    } else {
      Swal.fire("❌ 更新失敗", data.message || "未知錯誤", "error");
    }
  })
  .catch(err => Swal.fire("⚠️ 系統錯誤", String(err), "error"));
});
</script>
</body>
</html>
