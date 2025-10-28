<?php
session_start();
require 'db.php';

// 假設已登入
$userId = $_SESSION['user_id'] ?? 1;

// === 取得題目 ID ===
if (!isset($_GET['question_id'])) {
    die("❌ 請提供題目 ID，例如：practice_drag.php?question_id=1");
}
$questionId = (int)$_GET['question_id'];

// === 查詢題目 ===
$stmt = $conn->prepare("SELECT * FROM questions WHERE id=?");
$stmt->bind_param("i", $questionId);
$stmt->execute();
$question = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$question) die("❌ 找不到此題目 (ID: $questionId)");

$chapterId = $question['chapter'];
$codeLines = json_decode($question['code_lines'], true) ?? [];
$mindmapJson = $question['mindmap_json'] ?? null;
$flowchartJson = $question['flowchart_json'] ?? null;

// === 模擬週次 ===
$week = 1; // ✅ week=1 顯示人類提示；week≥2 啟動 AI 提示

// === 下一題 ===
$stmt = $conn->prepare("SELECT id FROM questions WHERE chapter=? AND id>? ORDER BY id ASC LIMIT 1");
$stmt->bind_param("ii", $chapterId, $questionId);
$stmt->execute();
$nextRow = $stmt->get_result()->fetch_assoc();
$nextId = $nextRow['id'] ?? null;
$stmt->close();

$chapterFinished = !$nextId;
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($question['title']) ?> | 練習題</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jsmind@0.5.0/style/jsmind.css" />
<script src="https://cdn.jsdelivr.net/npm/jsmind@0.5.0/js/jsmind.js"></script>
<style>
  body { background-color: #f8f9fa; }
  #codeList li { cursor: grab; font-family: 'Consolas', monospace; white-space: pre; }
  #mindmapArea, #flowchartArea { min-height: 300px; border: 1px solid #ccc; border-radius: 8px; background:#fff; }
</style>
</head>
<body>
<div class="container mt-4">
  <h4>💡 <?= htmlspecialchars($question['title']) ?></h4>
  <p><?= nl2br(htmlspecialchars($question['description'])) ?></p>

  <div class="row mt-4">
    <div class="col-md-6">
      <h5>🧠 心智圖</h5>
      <div id="mindmapArea"></div>
    </div>
    <div class="col-md-6">
      <h5>📊 流程圖</h5>
      <div id="flowchartArea" class="p-2"></div>
    </div>
  </div>

  <hr>

  <h5>🧩 拖曳程式碼排序</h5>
  <ul id="codeList" class="list-group mb-3">
    <?php foreach ($codeLines as $line): ?>
      <li class="list-group-item" data-indent="0"><?= htmlspecialchars($line) ?></li>
    <?php endforeach; ?>
  </ul>

  <div class="d-flex gap-2 mb-3">
    <button id="indentBtn" class="btn btn-outline-secondary">➡ 縮排</button>
    <button id="outdentBtn" class="btn btn-outline-secondary">⬅ 反縮排</button>
  </div>

  <button id="submitOrder" class="btn btn-primary w-100">提交答案 ✅</button>

  <audio id="soundCorrect" src="sounds/correct.mp3"></audio>
  <audio id="soundError" src="sounds/error.mp3"></audio>
  <audio id="soundSelect" src="sounds/select.mp3"></audio>
  <audio id="soundClick2" src="sounds/click2.mp3"></audio>
</div>

<script>
let startTime = Date.now();
let mindmapClicks = 0, flowchartClicks = 0;
let viewedTypes = [];
const codeList = document.getElementById("codeList");

// --- 拖曳排序 ---
new Sortable(codeList, { animation: 150 });

// --- 縮排控制 ---
document.getElementById("indentBtn").addEventListener("click", () => {
  const selected = document.querySelector(".list-group-item.active");
  if (!selected) return;
  let indent = parseInt(selected.getAttribute("data-indent"));
  selected.setAttribute("data-indent", indent + 1);
  selected.style.paddingLeft = (indent + 1) * 40 + "px";
});
document.getElementById("outdentBtn").addEventListener("click", () => {
  const selected = document.querySelector(".list-group-item.active");
  if (!selected) return;
  let indent = parseInt(selected.getAttribute("data-indent"));
  if (indent > 0) {
    selected.setAttribute("data-indent", indent - 1);
    selected.style.paddingLeft = (indent - 1) * 40 + "px";
  }
});
document.querySelectorAll("#codeList li").forEach(li=>{
  li.addEventListener("click",()=>document.querySelectorAll("#codeList li").forEach(e=>e.classList.remove("active"))||li.classList.add("active"));
});

// --- 提交 ---
const submitBtn = document.getElementById("submitOrder");
if(submitBtn){
  submitBtn.addEventListener("click",()=>{
    let checkResult = compareCodeOrder();
    let isCorrect = checkResult.result;
    let timeSpent = Math.floor((Date.now() - startTime) / 1000);
    const studentCode = Array.from(codeList.children)
      .map(li => " ".repeat((parseInt(li.getAttribute("data-indent")) || 0)*4) + li.innerText.trim())
      .join("\n");

    let payload = {
      question_id: <?= $questionId ?>,
      is_correct: isCorrect ? 1 : 0,
      time_spent: timeSpent,
      code: studentCode,
      mindmap_clicks: mindmapClicks,
      flowchart_clicks: flowchartClicks,
      viewed_types: viewedTypes
    };

    fetch("save_answer.php", {
      method:"POST",
      headers:{ "Content-Type":"application/json" },
      body: JSON.stringify(payload)
    })
    .then(r=>r.json())
    .then(data=>{
      console.log("✅ 作答紀錄已儲存",data);
      if(isCorrect){
        playSound("soundCorrect",1);
        Swal.fire({
          icon:"success",
          title:"✅ 正確",
          text: checkResult.message,
          timer:1500,
          showConfirmButton:false,
          willClose:()=>{
            <?php if(!$chapterFinished && $nextId): ?>
              window.location.href="practice_drag.php?question_id=<?= $nextId ?>";
            <?php else: ?>
              Swal.fire({
                icon:"success",
                title:"🎉 本章完成！",
                text:"是否前往下一章？",
                showDenyButton:true,
                confirmButtonText:"留在這",
                denyButtonText:"➡ 下一章"
              }).then(res=>{
                if(res.isDenied && nextChapterFirstQId){
                  window.location.href="practice_drag.php?question_id="+nextChapterFirstQId;
                }
              });
            <?php endif; ?>
          }
        });
      }else{
        playSound("soundError",1);
        const isWeek1 = <?= ($week==1 ? 'true':'false') ?>;
        if(isWeek1){
          Swal.fire({
            icon:"error",
            title:"❌ 錯誤",
            html: checkResult.hintHtml || checkResult.message,
            width:700
          });
        }else{
          Swal.fire({
            icon:"error",
            title:"❌ 錯誤",
            text: checkResult.message
          });
        }
      }
    });
  });
}

// --- 比對函式 ---
function compareCodeOrder(){
  const currentLines = Array.from(codeList.children).map(li=>({
    text: li.innerText.trim(),
    indent: parseInt(li.getAttribute("data-indent"))||0
  }));
  const correctLines = <?= json_encode($codeLines) ?>.map(line=>{
    const space = line.match(/^\s*/)[0].length;
    const indent = Math.floor(space/4);
    return { text: line.trim(), indent };
  });
  const curTexts = currentLines.map(l=>l.text);
  const corTexts = correctLines.map(l=>l.text);
  const orderCorrect = JSON.stringify(curTexts)===JSON.stringify(corTexts);

  const curIndents = currentLines.map(l=>l.indent);
  const corIndents = correctLines.map(l=>l.indent);
  const indentCorrect = JSON.stringify(curIndents)===JSON.stringify(corIndents);

  if(orderCorrect && indentCorrect)
    return {result:true,message:"✅ 排序與縮排都正確！"};

  // 找出差異
  let firstOrderDiff=-1;
  for(let i=0;i<Math.max(curTexts.length,corTexts.length);i++){
    if(curTexts[i]!==corTexts[i]){firstOrderDiff=i;break;}
  }
  let indentDiffs=[];
  for(let i=0;i<curIndents.length;i++){
    if(curIndents[i]!==corIndents[i]){
      indentDiffs.push({line:i+1,mine:curIndents[i],expect:corIndents[i]});
      if(indentDiffs.length>=3)break;
    }
  }

  let hintHtml="";
  if(!orderCorrect){
    hintHtml+=`<div style='text-align:left'>
      <p>⚠️ <b>程式順序</b>有錯。</p>
      ${firstOrderDiff>=0?`<p>第 <b>${firstOrderDiff+1}</b> 行開始不同：</p>
      <pre>你：${curTexts[firstOrderDiff]??'(無)'}</pre>
      <pre>應：${corTexts[firstOrderDiff]??'(無)'}</pre>`:""}
    </div>`;
  }
  if(!indentCorrect){
    hintHtml+=`<div style='text-align:left;margin-top:8px'>
      <p>⚠️ <b>縮排</b>錯誤：</p>
      <ul>${indentDiffs.map(d=>`<li>第 <b>${d.line}</b> 行應為 <b>${d.expect}</b> 層，你是 <b>${d.mine}</b> 層。</li>`).join("")}</ul>
    </div>`;
  }

  let baseMsg="";
  if(!orderCorrect&&indentCorrect) baseMsg="⚠️ 程式順序錯了幾行，再檢查一下吧！";
  else if(orderCorrect&&!indentCorrect) baseMsg="⚠️ 順序正確，但縮排層級不對喔！";
  else baseMsg="💡 程式順序與縮排都有錯誤，請再試一次！";

  return {result:false,message:baseMsg,hintHtml};
}

// --- 音效 ---
function playSound(id,volume=1){
  const el=document.getElementById(id);
  if(el){ el.volume=volume; el.currentTime=0; el.play(); }
}

// --- 渲染心智圖 ---
<?php if($mindmapJson): ?>
try{
  const mindData = JSON.parse(`<?= addslashes($mindmapJson) ?>`);
  const jm = new jsMind({container:'mindmapArea',editable:false,theme:'primary'});
  jm.show(mindData);
}catch(e){ document.getElementById("mindmapArea").innerHTML="⚠️ 心智圖資料錯誤"; }
<?php endif; ?>

// --- 渲染流程圖（簡化文字） ---
<?php if($flowchartJson): ?>
try{
  const flow = JSON.parse(`<?= addslashes($flowchartJson) ?>`);
  document.getElementById("flowchartArea").innerHTML = 
    flow.nodes.map(n=>`<div>• ${n.text}</div>`).join("");
}catch(e){
  document.getElementById("flowchartArea").innerHTML="⚠️ 流程圖資料錯誤";
}
<?php endif; ?>
</script>
</body>
</html>
