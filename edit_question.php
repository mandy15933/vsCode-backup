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

// 取得題目 ID
$questionId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$questionId) {
    die('❌ 未提供題目 ID');
}

// 取得章節清單
$chapters = $conn->query("SELECT id, title FROM chapters ORDER BY id")->fetch_all(MYSQLI_ASSOC);

// 讀取題目
$stmt = $conn->prepare("SELECT * FROM questions WHERE id=?");
$stmt->bind_param("i", $questionId);
$stmt->execute();
$question = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$question) die('❌ 題目不存在');

// 更新題目
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['title'])) {
    $title = $_POST['title'] ?? '';
    $chapter = (int)($_POST['chapter'] ?? 1);
    $difficulty = $_POST['difficulty'] ?? '簡單';
    $description = $_POST['description'] ?? '';
    $test_cases = $_POST['test_cases'] ?? '';
    $mindmap_json = $_POST['mindmap_json'] ?? '';
    $flowchart_json = $_POST['flowchart_json'] ?? '';
    $code_lines_raw = $_POST['code_lines'] ?? '';
    $code_lines_arr = preg_split('/\r\n|\r|\n/', trim($code_lines_raw));
    $code_lines = json_encode($code_lines_arr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);


    $test_cases_arr = json_decode($test_cases, true);
    if (!$test_cases_arr || count($test_cases_arr) < 2) {
        $error = '❌ 測資至少需要兩組，且必須是 JSON 格式';
    } else {
        $stmt = $conn->prepare("
            UPDATE questions
            SET title=?, chapter=?, difficulty=?, description=?, test_cases=?, mindmap_json=?, flowchart_json=?, updated_at=NOW(), code_lines=?
            WHERE id=?
        ");
        $stmt->bind_param("sissssssi",
            $title, $chapter, $difficulty, $description, $test_cases, $mindmap_json, $flowchart_json, $code_lines, $questionId
        );

        $stmt->execute();
        $stmt->close();

        header("Location: Admin_question.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<?php include 'Navbar.php'; ?>
<meta charset="UTF-8">
<title>編輯 Python 題目</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


<!-- jsMind -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jsmind/style/jsmind.css" />
<script src="https://cdn.jsdelivr.net/npm/jsmind/es6/jsmind.js"></script>

<!-- flowchart.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/raphael/2.3.0/raphael.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/flowchart/1.18.0/flowchart.min.js"></script>

<style>
body { background:#f8f9fa; }
.card { box-shadow: 0 2px 6px rgba(0,0,0,.1); border-radius:10px; }
#mindmapEditor{width:100%;height:400px;border:2px solid #ddd;border-radius:8px;background:#fff;}
#flowchartArea{width:100%;min-height:400px;border:2px solid #ddd;border-radius:8px;background:#fff;padding:10px;}
#jsonArea, #flowchartEditor{font-size:12px;height:220px;font-family:monospace;resize:vertical}
.badge-tip{font-size:.75rem}
#mindmapEditor.loading, 
#flowchartArea.loading {
  display: flex;
  justify-content: center;
  align-items: center;
  font-size: 1.25rem;
  color: #555;
}
.spinner-border {
  width: 3rem;
  height: 3rem;
}
/* 高亮選取樣式 */
#flowchartPreview .highlight rect {
  stroke: #f00 !important;
  stroke-width: 3px !important;
}
#flowchartArea, #flowchartPreview {
  width: 100%;
  min-height: 600px;   /* 加高一點 */
  overflow: auto;      /* 超出就出現捲軸 */
}


</style>
</head>
<body class="container py-4">


<h2 class="mb-4">✏️ 編輯題目</h2>

<?php if(!empty($error)): ?>
<div class="alert alert-danger"><?=htmlspecialchars($error)?></div>
<?php endif; ?>

<form method="POST">
<div class="row g-4">
    <!-- 左半：表單 -->
    <div class="col-lg-6">
        <div class="card p-4">
            <div class="mb-3">
                <label class="form-label fw-bold">題目標題</label>
                <input type="text" name="title" class="form-control"
                       value="<?=htmlspecialchars($question['title'])?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold">章節</label>
                <select name="chapter" class="form-select" required>
                <?php foreach($chapters as $chapterItem): ?>
                    <option value="<?=$chapterItem['id']?>"
                        <?=$chapterItem['id']==$question['chapter']?'selected':''?>>
                        第<?=$chapterItem['id']?>章：<?=htmlspecialchars($chapterItem['title'])?>
                    </option>
                <?php endforeach;?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold">難度</label>
                <select name="difficulty" class="form-select">
                    <option value="簡單" <?=$question['difficulty']=='簡單'?'selected':''?>>簡單</option>
                    <option value="中等" <?=$question['difficulty']=='中等'?'selected':''?>>中等</option>
                    <option value="困難" <?=$question['difficulty']=='困難'?'selected':''?>>困難</option>
                </select>
            </div>

            <div class="mb-2">
                <label class="form-label fw-bold">題目描述</label>
                <textarea name="description" id="descInput" class="form-control" rows="3"><?=htmlspecialchars($question['description'])?></textarea>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold">標準解答程式碼</label>
                <textarea name="code_lines" id="codeLinesInput" class="form-control" rows="6"><?=htmlspecialchars(implode("\n", json_decode($question['code_lines'], true) ?? []))?></textarea>
            </div>
            <div class="d-flex gap-2 mb-3">
                <button type="button" class="btn btn-outline-primary" id="generateMindmap">🧠 AI 生成心智圖</button>
                <button type="button" class="btn btn-outline-success" id="generateFlowchart">🔄 AI 生成流程圖</button>
            </div>


            <div class="mb-3">
              <label class="form-label fw-bold">測資（至少兩組）</label>
              <table class="table table-bordered align-middle" id="testcaseTable">
                <thead class="table-light">
                  <tr>
                    <th style="width:40%">輸入</th>
                    <th style="width:40%">輸出</th>
                    <th style="width:20%">操作</th>
                  </tr>
                </thead>
                <tbody></tbody>
              </table>
              <button type="button" class="btn btn-sm btn-outline-success" onclick="addTestcaseRow()">➕ 新增一組</button>
              <!-- 隱藏的 JSON 欄位，實際送出表單用 -->
              <input type="hidden" name="test_cases" id="test_cases_input" value="<?=htmlspecialchars($question['test_cases'])?>">
            </div>


            <!-- 隱藏欄位 -->
            <input type="hidden" name="mindmap_json" id="mindmap_json_input" value="<?=htmlspecialchars($question['mindmap_json'])?>">
            <input type="hidden" name="flowchart_json" id="flowchart_json_input" value="<?=htmlspecialchars($question['flowchart_json'])?>">

            <div class="d-flex justify-content-between mt-2">
                <button type="submit" class="btn btn-primary px-4">💾 更新題目</button>
                <a href="Admin_question.php" class="btn btn-secondary">返回題目列表</a>
            </div>
        </div>
    </div>

<div class="col-lg-6">
        <!-- 心智圖 -->
    <div class="card p-3 mb-4">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0">🌐 心智圖</h5>
            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#mindmapModal">✏️ 編輯</button>
        </div>
        <div id="mindmapEditor" class="mt-2"></div>
    </div>

        <!-- 流程圖 -->
    <div class="card p-3">
      <div class="d-flex justify-content-between align-items-center">
        <h5 class="mb-0">🔄 流程圖</h5>
        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#flowchartModal">✏️ 編輯</button>
      </div>
      <div id="flowchartArea" class="mt-2"></div>
    </div>
</div>
</div>
</form>
<!-- Modal: 心智圖 -->
<div class="modal fade" id="mindmapModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">編輯心智圖</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body row">
        <div class="d-flex gap-2 mb-2">
            <button type="button" class="btn btn-sm btn-outline-success" onclick="addChildNode()">➕ 新增支點</button>
          <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeNode()">🗑 刪除節點</button>
        </div>
        <!-- 左邊：即時預覽 -->
        <div class="col-md-6 border-end">
          <h6 class="fw-bold mb-2">即時預覽</h6>
          <div id="mindmapPreview" style="height:400px; background:#fafafa; border:1px solid #ddd"></div>
        </div>
        <!-- 右邊：JSON 編輯 -->
        <div class="col-md-6">
          <h6 class="fw-bold mb-2">JSON 編輯</h6>
          <textarea id="jsonArea" class="form-control json-editor" rows="15"></textarea>
          <div class="d-flex gap-2 mt-2">
            <button type="button" class="btn btn-success" onclick="saveMindmap()">💾 儲存</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal: 流程圖 -->
<div class="modal fade" id="flowchartModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">編輯流程圖</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body row">
        <!-- 左邊：即時預覽 -->
        <div class="col-md-8 border-end">
          <h6 class="fw-bold mb-2">即時預覽</h6>
          <div id="flowchartPreview" style="height:500px; background:#fafafa; border:1px solid #ddd; overflow:auto"></div>
        </div>
        <!-- 右邊：JSON 編輯 -->
        <div class="col-md-4">
          <h6 class="fw-bold mb-2">JSON 編輯</h6>
          <textarea id="flowchartEditor" class="form-control json-editor" rows="18"></textarea>
          <div class="d-flex gap-2 mt-2">
            <button type="button" class="btn btn-success" onclick="saveFlowchart()">💾 儲存</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="position-fixed top-0 end-0 p-3" style="z-index: 9999">
  <div id="liveToast" class="toast align-items-center text-bg-primary border-0" role="alert">
    <div class="d-flex">
      <div class="toast-body" id="toastBody">提示訊息</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>


<script>
const mindmapJsonInput = document.getElementById('mindmap_json_input');
const flowchartJsonInput = document.getElementById('flowchart_json_input');

let jm;
// 初始化測資表格
window.addEventListener('DOMContentLoaded', () => {
  try {
    const raw = document.getElementById('test_cases_input').value;
    const cases = JSON.parse(raw || "[]");
    cases.forEach(c => addTestcaseRow(c.input, c.output));
  } catch(e) {
    addTestcaseRow(); // 如果 JSON 壞掉，至少要有一列
  }
});

// 新增一列
function addTestcaseRow(inputVal="", outputVal=""){
  const tbody = document.querySelector("#testcaseTable tbody");
  const tr = document.createElement("tr");

  tr.innerHTML = `
    <td><textarea class="form-control input-cell" rows="2">${inputVal}</textarea></td>
    <td><textarea class="form-control output-cell" rows="2">${outputVal}</textarea></td>
    <td class="text-center">
      <button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove(); syncTestcases()">🗑 刪除</button>
    </td>
  `;

  tbody.appendChild(tr);
  syncTestcases();
}

// 同步表格 → 隱藏 JSON 欄位
function syncTestcases(){
  const rows = document.querySelectorAll("#testcaseTable tbody tr");
  const cases = [];
  rows.forEach(r=>{
    const input = r.querySelector(".input-cell").value;
    const output = r.querySelector(".output-cell").value;
    if(input.trim() || output.trim()){
      cases.push({input, output});
    }
  });
  document.getElementById("test_cases_input").value = JSON.stringify(cases, null, 2);
}

// 當輸入變動時，立即同步
document.addEventListener("input", e=>{
  if(e.target.classList.contains("input-cell") || e.target.classList.contains("output-cell")){
    syncTestcases();
  }
});


// ---------- 初始化 ----------
window.addEventListener('DOMContentLoaded', ()=> {
  // jsMind
  const options = { container:'mindmapEditor', editable:true, theme:'primary' };
  jm = new jsMind(options);

  // 載入 DB 心智圖 or 預設
  let mindmapData;
  try { mindmapData = JSON.parse(mindmapJsonInput.value); } catch(e){}
  if(!mindmapData){
    mindmapData = {
      meta:{name:"Mindmap",author:"system",version:"1.0"},
      format:"node_tree",
      data:{id:"root",topic:"題目理解"}
    };
  }
  jm.show(mindmapData);

  // 載入流程圖
  let flowchartData;
  try { flowchartData = JSON.parse(flowchartJsonInput.value); } catch(e){}
  if(flowchartData){
    const normalized = normalizeFlowchart(flowchartData);
    if(normalized){
      document.getElementById('flowchartEditor').value = JSON.stringify(normalized,null,2);
      updateFlowchart(normalized);
    }
  }
});

// ---------- Modal 開啟時自動填入 ----------
// 心智圖 Modal
let preview; // 全域變數保存預覽 jsMind 實例

// Modal 開啟時
mindmapModal.addEventListener('shown.bs.modal', () => {
  try {
    const parsed = JSON.parse(mindmapJsonInput.value);
    document.getElementById('jsonArea').value = JSON.stringify(parsed, null, 2);

    const previewContainer = document.getElementById('mindmapPreview');
    previewContainer.innerHTML = "";

    preview = new jsMind({container:'mindmapPreview', editable:true, theme:'primary'});
    preview.show(parsed);

    preview.add_event_listener(function(type, data){
      syncJsonFromMindmap();
    });

  } catch (e) {
    document.getElementById('jsonArea').value = mindmapJsonInput.value || '';
  }
});



// JSON 編輯 → 即時更新圖
document.getElementById('jsonArea').addEventListener('input', () => {
  try {
    const parsed = JSON.parse(document.getElementById('jsonArea').value);
    if(preview){
      preview.show(parsed);
    }
  } catch(e){
    // 忽略 JSON 錯誤
  }
});

// 圖 → 更新 JSON
function syncJsonFromMindmap(){
  if(preview){
    const data = preview.get_data();
    document.getElementById('jsonArea').value = JSON.stringify(data, null, 2);
  }
}

// 儲存：更新 hidden input + 主畫面
function saveMindmap(){
  try {
    const data = JSON.parse(document.getElementById('jsonArea').value);
    jm.show(data); // 更新主畫面
    mindmapJsonInput.value = JSON.stringify(data,null,2);
    showToast("✅ 心智圖已更新", "success");
    bootstrap.Modal.getInstance(mindmapModal).hide();
  } catch(e){
    showToast("❌ JSON 格式錯誤：" + e.message, "danger");
  }
}

// 取得選中的節點
function getSelectedNode(){
  if(preview){
    return preview.get_selected_node();
  }
  return null;
}

// 新增子節點（自動選中 + 編輯）
function addChildNode(){
  const selected = getSelectedNode();
  if(!selected){
    showToast("⚠️ 請先選取一個節點！", "warning");
    return;
  }
  const newId = "node_" + Date.now();
  const newNode = preview.add_node(selected, newId, "新支點");
  preview.select_node(newId);
  preview.begin_edit(newId); // 自動進入編輯模式
  syncJsonFromMindmap();
}

// 刪除節點
function removeNode(){
  const selected = getSelectedNode();
  if(!selected){
    showToast("⚠️ 請先選取要刪除的節點！", "warning");
    return;
  }
  if(selected.isroot){
    showToast("❌ 根節點不能刪除！", "danger");
    return;
  }
  preview.remove_node(selected);
  syncJsonFromMindmap();
}


// 快捷鍵：Enter 新增子節點、Delete 刪除節點
document.addEventListener("keydown", (e)=>{
  if(!preview) return;
  if(e.key === "Enter"){
    addChildNode();
    e.preventDefault();
  }
  if(e.key === "Delete"){
    removeNode();
    e.preventDefault();
  }
});






// 流程圖modal

// ---------- 即時更新 + 高亮 ----------
let lastFlowchartData = null;

document.getElementById('flowchartEditor').addEventListener('input', () => {
  try {
    const newData = JSON.parse(document.getElementById('flowchartEditor').value);
    const normalized = normalizeFlowchart(newData);
    if(!normalized) return;

    // 找出差異
    const changed = diffFlowchart(lastFlowchartData, normalized);

    // 更新預覽
    updateFlowchart("flowchartPreview", normalized);

    // 高亮顯示差異
    if(changed.nodes.length || changed.edges.length){
      highlightChanges("flowchartPreview", changed);
    }

    lastFlowchartData = normalized;
  } catch(e){
    // JSON 格式錯誤就忽略
  }
});

// ---------- 找出差異 ----------
function diffFlowchart(oldData, newData){
  if(!oldData) return {nodes:newData.nodes, edges:newData.edges};

  const oldNodes = oldData.nodes.map(n=>n.id);
  const oldEdges = oldData.edges.map(e=>`${e.from}->${e.to}`);

  const newNodes = newData.nodes.map(n=>n.id);
  const newEdges = newData.edges.map(e=>`${e.from}->${e.to}`);

  const addedNodes = newData.nodes.filter(n=>!oldNodes.includes(n.id));
  const addedEdges = newData.edges.filter(e=>!oldEdges.includes(`${e.from}->${e.to}`));

  return {nodes:addedNodes, edges:addedEdges};
}

// ---------- 高亮顯示 ----------
function highlightChanges(targetId, changed){
  const svg = document.querySelector(`#${targetId} svg`);
  if(!svg) return;

  // 高亮節點
  changed.nodes.forEach(n=>{
    const el = svg.querySelector(`#${n.id} rect, #${n.id} path`);
    if(el){
      el.setAttribute("stroke", "red");
      el.setAttribute("stroke-width", "3");
    }
  });

  // 高亮邊
  changed.edges.forEach(e=>{
    const selector = `path[data-from="${e.from}"][data-to="${e.to}"]`;
    const el = svg.querySelector(selector);
    if(el){
      el.setAttribute("stroke", "orange");
      el.setAttribute("stroke-width", "3");
    }
  });
}

// Modal 開啟時 → 載入 JSON & 預覽
flowchartModal.addEventListener('shown.bs.modal', () => {
  try {
    const parsed = JSON.parse(flowchartJsonInput.value || '{"nodes":[],"edges":[]}');
    document.getElementById('flowchartEditor').value = JSON.stringify(parsed, null, 2);
    updateFlowchart("flowchartPreview", parsed);
  } catch(e) {
    document.getElementById('flowchartEditor').value = flowchartJsonInput.value || '';
  }
});


// ---------- Toast ----------
function showToast(msg, type="primary"){
  const toastEl = document.getElementById('liveToast');
  const toastBody = document.getElementById('toastBody');
  toastBody.textContent = msg;
  toastEl.className = `toast align-items-center text-bg-${type} border-0`;
  const toast = new bootstrap.Toast(toastEl);
  toast.show();
}

// 頁面載入 → 主畫面渲染
window.addEventListener('DOMContentLoaded', () => {
  try {
    const parsed = JSON.parse(flowchartJsonInput.value || '{"nodes":[],"edges":[]}');
    updateFlowchart("flowchartArea", parsed);
  } catch(e) {}
});







// ---------- 儲存心智圖 ----------
function saveMindmap(){
  try {
    const newData = JSON.parse(document.getElementById('jsonArea').value);
    jm.show(newData);
    mindmapJsonInput.value = JSON.stringify(newData, null, 2);
    showToast("✅ 心智圖已更新", "success");
    bootstrap.Modal.getInstance(mindmapModal).hide();
  } catch (e) {
    showToast("❌ JSON 格式錯誤：" + e.message, "danger");
  }
}

// ---------- 儲存流程圖 ----------
function normalizeFlowchart(payload){
  if(!payload) return null;
  let fc = payload.flowchart ? payload.flowchart : payload;
  if(fc && Array.isArray(fc.nodes) && Array.isArray(fc.edges)) return fc;
  return null;
}

// ---------- 儲存流程圖 ----------
function saveFlowchart(){
  try{
    const newData = JSON.parse(document.getElementById('flowchartEditor').value);
    const normalized = normalizeFlowchart(newData);
    if(!normalized) throw new Error("流程圖 JSON 結構不正確（需含 nodes/edges）");

    flowchartJsonInput.value = JSON.stringify(normalized,null,2);
    updateFlowchart("flowchartArea", normalized); // 更新主畫面
    showToast("✅ 流程圖已更新","success");
    bootstrap.Modal.getInstance(flowchartModal).hide();
  }catch(e){
    showToast("❌ 流程圖 JSON 格式錯誤："+e.message,"danger");
  }
}

// ---------- Flowchart.js 渲染 ----------
// ---------- 流程圖渲染 ----------
function updateFlowchart(targetId, flowchartData){
  if(!flowchartData || !flowchartData.nodes) return;

  let def = "";
  flowchartData.nodes.forEach(n=>{
    const t = (n.type || "").toLowerCase();
    if(t==="start") def += `${n.id}=>start: ${n.text}\n`;
    else if(t==="end") def += `${n.id}=>end: ${n.text}\n`;
    else if(t==="io") def += `${n.id}=>inputoutput: ${n.text}\n`;
    else if(t==="decision") def += `${n.id}=>condition: ${n.text}\n`;
    else def += `${n.id}=>operation: ${n.text}\n`;
  });

  flowchartData.edges.forEach(e=>{
    const lbl = (e.label || "").toLowerCase();
    if(lbl==="yes"||lbl==="是") def += `${e.from}(yes)->${e.to}\n`;
    else if(lbl==="no"||lbl==="否") def += `${e.from}(no)->${e.to}\n`;
    else def += `${e.from}->${e.to}\n`;
  });

  document.getElementById(targetId).innerHTML = "";
  try{
    const chart = flowchart.parse(def);
    chart.drawSVG(targetId, {
      'line-width': 2,
      'font-size': 12,
      'line-color': 'black',
      'element-color': '#2196F3',
      'fill': '#fff',
      'yes-text': '是',
      'no-text': '否',
      'arrow-end': 'block',
      'symbols': {
        'start': { 'fill': '#5cb85c' },
        'end': { 'fill': '#d9534f' }
      }
    });
  }catch(err){
    console.error("流程圖解析失敗:", err, def);
  }
}

// ---------- Toast ----------
function showToast(msg, type="primary"){
  const toastEl = document.getElementById('liveToast');
  const toastBody = document.getElementById('toastBody');
  toastBody.textContent = msg;
  toastEl.className = `toast align-items-center text-bg-${type} border-0`;
  const toast = new bootstrap.Toast(toastEl);
  toast.show();
}

// === 🧠 AI 生成心智圖 ===
// === 🧠 AI 生成心智圖 ===
document.getElementById('generateMindmap').addEventListener('click', async () => {
  const description = document.getElementById('descInput').value.trim();
  const test_cases = document.getElementById('test_cases_input').value.trim();

  if (!description) {
    showToast("⚠️ 請先輸入題目描述！", "warning");
    return;
  }

  const btn = document.getElementById('generateMindmap');
  const original = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '⏳ 生成中…';

  const mindmapEditor = document.getElementById('mindmapEditor');
  mindmapEditor.classList.add('loading');
  mindmapEditor.innerHTML = '<div class="spinner-border text-primary" role="status"></div>';

  try {
    const res = await fetch('generate_mindmap.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ description, test_cases })
    });

    const data = await res.json();

    if (data.error) {
      showToast('❌ 生成失敗：' + data.error, "danger");
      return;
    }

    // ✅ 清空舊內容
    mindmapEditor.classList.remove('loading');
    mindmapEditor.innerHTML = '';

    // ✅ 若有舊實例則清除
    if (window.jm && typeof jm.clear === 'function') {
      jm.clear();
    }

    // ✅ 修正重複 ID
    function ensureUniqueIds(node, used = new Set()) {
      if (used.has(node.id)) {
        node.id = node.id + "_" + Math.floor(Math.random() * 10000);
      }
      used.add(node.id);
      if (node.children) {
        node.children.forEach(child => ensureUniqueIds(child, used));
      }
    }
    if (data?.data) ensureUniqueIds(data.data);

    // ✅ 顯示心智圖
    jm = new jsMind({ container: 'mindmapEditor', editable: true, theme: 'primary' });
    jm.show(data);

    // ✅ 同步 JSON 編輯區
    mindmapJsonInput.value = JSON.stringify(data, null, 2);
    const jsonArea = document.getElementById('jsonArea');
    if (jsonArea) jsonArea.value = mindmapJsonInput.value;

    showToast("✅ 心智圖生成完成", "success");
  } catch (err) {
    console.error(err);
    showToast('伺服器錯誤，請稍後再試', "danger");
  } finally {
    btn.disabled = false;
    btn.innerHTML = original;
    mindmapEditor.classList.remove('loading');
    mindmapEditor.querySelectorAll('.spinner-border').forEach(el => el.remove());
  }
});



// === 🔄 AI 生成流程圖 ===
document.getElementById('generateFlowchart').addEventListener('click', async () => {
  const code_lines = document.getElementById('codeLinesInput').value.trim();

  if (!code_lines) {
    showToast("⚠️ 請先輸入標準解答程式碼！", "warning");
    return;
  }

  const btn = document.getElementById('generateFlowchart');
  const original = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '⏳ 生成中…';

  const flowchartArea = document.getElementById('flowchartArea');
  flowchartArea.classList.add('loading');
  flowchartArea.innerHTML = '<div class="spinner-border text-success" role="status"></div>';

  try {
    const res = await fetch('generate_flowchart.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ code_lines })
    });
    const data = await res.json();

    if (data.error) {
      showToast('❌ 生成失敗：' + data.error, "danger");
      return;
    }

    // ✅ 顯示流程圖
    const normalized = normalizeFlowchart(data);
    if (normalized) {
      flowchartJsonInput.value = JSON.stringify(normalized, null, 2);
      document.getElementById('flowchartEditor').value = flowchartJsonInput.value;
      updateFlowchart("flowchartArea", normalized);
      showToast("✅ 流程圖生成完成", "success");
    } else {
      showToast('⚠️ AI 回傳的流程圖格式不正確（需包含 nodes / edges）', "warning");
    }
  } catch (err) {
    console.error(err);
    showToast('伺服器錯誤，請稍後再試', "danger");
  } finally {
    btn.disabled = false;
    btn.innerHTML = original;
    flowchartArea.classList.remove('loading');
  }
});


</script>
</body>
</html>

