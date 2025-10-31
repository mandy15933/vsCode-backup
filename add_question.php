<?php
session_start();
require 'db.php';

// 取得章節資料
$chapters = $conn->query("SELECT id, title FROM chapters ORDER BY id")->fetch_all(MYSQLI_ASSOC);

// 儲存題目
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['title'])) {
    $title = $_POST['title'] ?? '';
    $chapter = (int)($_POST['chapter'] ?? 1);
    $difficulty = $_POST['difficulty'] ?? '簡單';
    $description = $_POST['description'] ?? '';
    $test_cases = $_POST['test_cases'] ?? '';
    $mindmap_json = $_POST['mindmap_json'] ?? '';
    $flowchart_json = $_POST['flowchart_json'] ?? '';
    $code_lines_raw = $_POST['code_lines'] ?? '[]';

    // ✅ 確保 code_lines 是合法 JSON
    $code_lines_arr = json_decode($code_lines_raw, true);
    if (is_array($code_lines_arr)) {
        // 存入資料庫前壓縮 JSON（乾淨存放）
        $code_lines = json_encode($code_lines_arr, JSON_UNESCAPED_UNICODE);
    } else {
        $code_lines = '[]';
    }
    
    function normalize_testcases($cases) {
        $fixed = [];
        foreach ((array)$cases as $tc) {
            $in  = isset($tc['input'])  ? (string)$tc['input']  : '';
            $out = isset($tc['output']) ? (string)$tc['output'] : '';
            // 換行標準化
            $in  = str_replace(["\r\n", "\r"], "\n", $in);
            $out = str_replace(["\r\n", "\r"], "\n", $out);
            // 確保輸出以換行結尾
            if ($out !== '' && substr($out, -1) !== "\n") $out .= "\n";
            if ($in !== '' && $out !== '') $fixed[] = ['input' => $in, 'output' => $out];
        }
        return $fixed;
    }

    // 正規化測資
    $test_cases_arr = normalize_testcases(json_decode($test_cases, true));
    $test_cases = json_encode($test_cases_arr, JSON_UNESCAPED_UNICODE);


    // 驗證測資
    $test_cases_arr = json_decode($test_cases, true);
    if (!$test_cases_arr || count($test_cases_arr) < 2) {
        $error = '❌ 測資至少需要兩組，且必須是 JSON 格式';
    } else {
        $stmt = $conn->prepare("
            INSERT INTO questions 
            (title, chapter, difficulty, description, test_cases, mindmap_json, flowchart_json, created_at, code_lines)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)
        ");
        $stmt->bind_param("sissssss", 
            $title, $chapter, $difficulty, $description, 
            $test_cases, $mindmap_json, $flowchart_json, $code_lines
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
<meta charset="UTF-8">
<title>新增 Python 題目</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- jsMind -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jsmind/style/jsmind.css" />
<script src="https://cdn.jsdelivr.net/npm/jsmind/es6/jsmind.js"></script>

<!-- flowchart.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/raphael/2.3.0/raphael.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/flowchart/1.18.0/flowchart.min.js"></script>

<style>
#mindmapArea { width:100%; height:400px; border:1px solid #ccc; border-radius:6px; background:#fff; }
#flowchartArea { width:100%; min-height:400px; border:1px solid #ccc; border-radius:6px; background:#fff; overflow:auto; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; }
</style>
</head>
<body class="bg-light">
  <?php include 'Navbar.php'; ?>
  <div class="container-fluid px-4">
    <h2>➕ 新增題目</h2>
    <?php if(!empty($error)): ?>
      <div class="alert alert-danger"><?=htmlspecialchars($error)?></div>
    <?php endif; ?>

<!-- AI 生成區 -->
<div class="mb-4">
  <h5>🤖 AI 生成題目</h5>
  <div class="d-flex flex-wrap gap-2 mb-2">
    <select id="aiChapter" class="form-select" style="width:auto">
      <option value="">請選擇章節</option>
      <?php foreach($chapters as $chapterItem): ?>
      <option value="<?=$chapterItem['id']?>">第<?=$chapterItem['id']?>章：<?=htmlspecialchars($chapterItem['title'])?></option>
      <?php endforeach;?>
    </select>
    <select id="aiDifficulty" class="form-select" style="width:auto">
      <option value="簡單">簡單</option>
      <option value="中等">中等</option>
      <option value="困難">困難</option>
    </select>
    <button type="button" class="btn btn-secondary" id="btnGenerateAI">AI 生成</button>
  </div>
  <div id="loadingSpinner" class="text-primary mt-2" style="display:none;">
    <div class="spinner-border spinner-border-sm" role="status"></div>
    <span> 正在生成中…</span>
  </div>
</div>

<hr>

<!-- 表單 -->
<form method="POST">
<div class="row">
<div class="col-lg-6">
  <div class="mb-3">
    <label class="form-label">題目標題</label>
    <input type="text" name="title" id="titleInput" class="form-control" required>
  </div>

  <div class="mb-3">
    <label class="form-label">章節</label>
    <select name="chapter" id="chapterInput" class="form-select" required>
      <?php foreach($chapters as $chapterItem): ?>
      <option value="<?=$chapterItem['id']?>">第<?=$chapterItem['id']?>章：<?=htmlspecialchars($chapterItem['title'])?></option>
      <?php endforeach;?>
    </select>
  </div>

  <div class="mb-3">
    <label class="form-label">難度</label>
    <select name="difficulty" id="difficultyInput" class="form-select">
      <option value="簡單">簡單</option>
      <option value="中等">中等</option>
      <option value="困難">困難</option>
    </select>
  </div>

  <div class="mb-3">
    <label class="form-label">題目描述</label>
    <textarea name="description" id="descInput" class="form-control" rows="3"></textarea>
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
    <tbody>
      <!-- 預設一組空白 -->
      <tr>
        <td><input type="text" class="form-control" placeholder="例：5 10"></td>
        <td><input type="text" class="form-control" placeholder="例：50"></td>
        <td>
          <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)">刪除</button>
        </td>
      </tr>
    </tbody>
  </table>
  <button type="button" class="btn btn-sm btn-outline-success" onclick="addTestcaseRow()">➕ 新增一組</button>
  <!-- 隱藏的 JSON 欄位 -->
  <input type="hidden" name="test_cases" id="test_cases_input">
</div>



  <div class="mb-3">
    <label class="form-label fw-bold">程式碼解答（每行一行）</label>
    <textarea id="codeLinesInput" class="form-control" rows="6"
      placeholder="請輸入標準解答程式碼，每行一行"></textarea>
    <input type="hidden" name="code_lines" id="code_lines_hidden">
  </div>

  <!-- 隱藏欄位 -->
  <input type="hidden" name="mindmap_json" id="mindmap_json_input">
  <input type="hidden" name="flowchart_json" id="flowchart_json_input">

  <!-- 儲存按鈕 -->
  <button type="submit" class="btn btn-primary">💾 儲存題目</button>
  <a href="Admin_question.php" class="btn btn-secondary ms-2">返回題目列表</a>
</div>

<div class="col-lg-6">
  <div class="card p-3 mb-3">
    <h5>🌐 心智圖</h5>
    <div id="mindmapArea"></div>
  </div>
  <div class="card p-3">
    <h5>🔄 流程圖</h5>
    <div id="flowchartArea"></div>
  </div>
</div>
</div>
</form>


<script>
let jm = null;
document.querySelector("form").addEventListener("submit", function(e) {
  const rows = document.querySelectorAll("#testcaseTable tbody tr");
  const testCases = [];

  rows.forEach(row => {
    const inputs = row.querySelectorAll("textarea");
    let inputVal  = (inputs[0].value || "").replace(/\r\n?/g, "\n");
    let outputVal = (inputs[1].value || "").replace(/\r\n?/g, "\n");

    // 確保輸出以換行結尾（評測器標準格式）
    if (outputVal && !/\n$/.test(outputVal)) outputVal += "\n";

    if (inputVal && outputVal) testCases.push({ input: inputVal, output: outputVal });
  });

  document.getElementById("test_cases_input").value = JSON.stringify(testCases, null, 2);

  if (testCases.length < 2) {
    e.preventDefault();
    alert("⚠️ 請至少新增兩組測資！");
  }
});


function addTestcaseRow(inputVal = "", outputVal = "") {
  const tbody = document.querySelector("#testcaseTable tbody");
  const tr = document.createElement("tr");
  tr.innerHTML = `
    <td>
      <textarea class="form-control mono" rows="2" placeholder="例：5\\n3">${inputVal}</textarea>
    </td>
    <td>
      <textarea class="form-control mono" rows="2" placeholder="例：15\\n">${outputVal}</textarea>
    </td>
    <td>
      <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)">刪除</button>
    </td>
  `;
  tbody.appendChild(tr);
}

// ✅ 讓函式變成全域作用域（fetch 回傳後也能呼叫）
window.addTestcaseRow = addTestcaseRow;


function removeRow(btn) {
  btn.closest("tr").remove();
}

// ✅ 表單送出前，組成 JSON
document.querySelector("form").addEventListener("submit", function(e) {
  const rows = document.querySelectorAll("#testcaseTable tbody tr");
  const testCases = [];

  rows.forEach(row => {
    const inputs = row.querySelectorAll("input");
    const inputVal = inputs[0].value.trim();
    const outputVal = inputs[1].value.trim();
    if (inputVal && outputVal) {
      testCases.push({input: inputVal, output: outputVal});
    }
  });

  document.getElementById("test_cases_input").value = JSON.stringify(testCases, null, 2);

  if (testCases.length < 2) {
    e.preventDefault();
    alert("⚠️ 請至少新增兩組測資！");
  }
});



function updateFlowchart(containerId, flowchartData) {
  console.log("🧩 Flowchart Data:", flowchartData); // 可觀察結構
  const container = document.getElementById(containerId);
  container.innerHTML = "";

  if (!flowchartData || !flowchartData.nodes || !flowchartData.edges) {
    container.innerHTML = "<div class='text-danger p-2'>⚠️ 流程圖資料格式錯誤或為 null</div>";
    return;
  }

  let def = "";
  flowchartData.nodes.forEach(n => {
    const t = (n.type || "").toLowerCase();
    if (t === "start") def += `${n.id}=>start: ${n.text}\n`;
    else if (t === "end") def += `${n.id}=>end: ${n.text}\n`;
    else if (t === "io") def += `${n.id}=>inputoutput: ${n.text}\n`;
    else if (t === "decision") def += `${n.id}=>condition: ${n.text}\n`;
    else def += `${n.id}=>operation: ${n.text}\n`;
  });

  flowchartData.edges.forEach(e => {
    const lbl = (e.label || "").toLowerCase();
    if (lbl === "yes" || lbl === "是") def += `${e.from}(yes)->${e.to}\n`;
    else if (lbl === "no" || lbl === "否") def += `${e.from}(no)->${e.to}\n`;
    else def += `${e.from}->${e.to}\n`;
  });

  try {
    const chart = flowchart.parse(def);
    chart.drawSVG(containerId, {
      "line-width": 2,
      "font-size": 12,
      "line-color": "black",
      "element-color": "#2196F3",
      "fill": "#fff",
      "yes-text": "是",
      "no-text": "否",
      "arrow-end": "block",
      "symbols": {
        start: { fill: "#5cb85c" },
        end: { fill: "#d9534f" }
      }
    });
  } catch (err) {
    console.error("流程圖解析失敗:", err, def);
    container.innerHTML = "<div class='text-danger p-2'>⚠️ 無法繪製流程圖</div>";
  }
}



document.getElementById("btnGenerateAI").addEventListener("click", () => {
  const chapter = document.getElementById("aiChapter").value;
  const difficulty = document.getElementById("aiDifficulty").value;
  if (!chapter || !difficulty) {
    alert("⚠️ 請先選擇章節與難度！");
    return;
  }

  document.getElementById("btnGenerateAI").disabled = true;
  document.getElementById("loadingSpinner").style.display = "block";

  fetch("generate_question.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({ chapter, difficulty })
  })
  .then(res => res.json())
  .then(data => {
    if (data.error) {
      alert("❌ 失敗：" + data.error);
      return;
    }

    // 填入表單
    document.getElementById("titleInput").value = data.title || "";
    document.getElementById("descInput").value = data.description || "";
    document.getElementById("difficultyInput").value = difficulty;
    document.getElementById("chapterInput").value = chapter;

    // ✅ 填充測資表格
    const tbody = document.querySelector("#testcaseTable tbody");
    tbody.innerHTML = "";
    (data.test_cases || []).forEach(tc => addTestcaseRow(tc.input, tc.output));
    document.getElementById("test_cases_input").value = JSON.stringify(data.test_cases, null, 2);

    // 填充程式碼
    document.getElementById("codeLinesInput").value = (data.code_lines || []).join("\n");

    // 儲存 JSON
    document.getElementById("mindmap_json_input").value = JSON.stringify(data.mindmap, null, 2);
    document.getElementById("flowchart_json_input").value = JSON.stringify(data.flowchart, null, 2);

    // 🌐 心智圖
    if (data.mindmap) {
      const mindmapContainer = document.getElementById('mindmapArea');
      mindmapContainer.innerHTML = "";
      try {
        jm = new jsMind({ container: 'mindmapArea', editable: false, theme: 'primary' });
        jm.show(data.mindmap);
      } catch (e) {
        console.error("心智圖解析失敗:", e);
        mindmapContainer.innerHTML = "<div class='text-danger p-2'>⚠️ 心智圖資料格式錯誤</div>";
      }
    }

    // 🔄 流程圖
    if (data.flowchart || data.flowchart_json) {
      const flowchartContainer = document.getElementById("flowchartArea");
      flowchartContainer.innerHTML = "";

      let flowData = data.flowchart || data.flowchart_json;

      // 🔍 若是字串，先嘗試轉 JSON
      if (typeof flowData === "string") {
        try { flowData = JSON.parse(flowData); } catch (e) {
          console.warn("流程圖字串轉換失敗:", e);
        }
      }

      // 🔍 若內層還有 flowchart_json，取出真正節點資料
      if (flowData && flowData.flowchart_json) {
        flowData = flowData.flowchart_json;
      }

      if (!flowData || !flowData.nodes) {
        console.warn("⚠️ 找不到流程圖節點資料:", flowData);
        flowchartContainer.innerHTML = "<div class='text-danger p-2'>⚠️ 流程圖資料為 null 或結構錯誤</div>";
        return;
      }

      try {
        updateFlowchart("flowchartArea", flowData);
      } catch (err) {
        console.error("流程圖渲染失敗:", err, flowData);
        flowchartContainer.innerHTML = "<div class='text-danger p-2'>⚠️ 流程圖渲染失敗</div>";
      }
    }
  })
  .catch(err => {
    alert("伺服器錯誤：" + err);
  })
  .finally(() => {
    document.getElementById("btnGenerateAI").disabled = false;
    document.getElementById("loadingSpinner").style.display = "none";
  });
});

</script>
</body>
</html>
