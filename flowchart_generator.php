<?php
session_start();
require 'session_protect.php';
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI 流程圖產生器</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/raphael/2.3.0/raphael.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/flowchart/1.18.0/flowchart.min.js"></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Chiron+GoRound+TC:wght@200..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="flowchart_generator_color.css?v=1.0">

</head>
<body>
<?php include 'Navbar.php'; ?>

<div class="container-fluid py-4 page-wrap">
    <div class="row g-4 main-area">

        <!-- 左側輸入區 -->
        <div class="col-12 col-lg-5">
            <div class="card h-100">
                <div class="card-header">
                    <h4 class="mb-0">🧠 AI 流程圖產生器</h4>
                </div>
                <div class="card-body d-flex flex-column gap-3">

                    <div class="hint-box">
                        你可以輸入：
                        <br>1. 題目描述
                        <br>2. 程式碼
                        <br>3. 或兩者都輸入
                        <br><br>
                        若兩者都有，系統會優先依照程式碼邏輯產生流程圖。
                    </div>

                    <div>
                        <label for="language" class="form-label">程式語言</label>
                        <select id="language" class="form-select">
                            <option value="python" selected>Python</option>
                            <option value="c">C</option>
                            <option value="cpp">C++</option>
                            <option value="java">Java</option>
                            <option value="javascript">JavaScript</option>
                            <option value="php">PHP</option>
                        </select>
                    </div>

                    <div>
                        <label for="problemText" class="form-label">題目描述</label>
                        <textarea id="problemText" class="form-control" placeholder="例如：請輸入一個整數，判斷它是否為偶數，並輸出結果。"></textarea>
                    </div>



                    <div>
                        <label for="answerCode" class="form-label">正確解答程式碼</label>
                        <textarea id="answerCode" class="form-control" placeholder="請貼上你的程式碼..."></textarea>
                    </div>
                    <div>
                        <label for="studentCode" class="form-label">學生程式碼</label>
                        <textarea id="studentCode" class="form-control" placeholder="請貼上學生輸入的程式碼..."></textarea>
                    </div>


                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <button id="generateAnswerBtn" class="btn btn-green">✅ 產生解答流程圖</button>
                        <button id="generateStudentBtn" class="btn btn-primary">🧑‍🎓 產生學生流程圖</button>
                        <button id="generateBothBtn" class="btn btn-success">🚀 產生全部流程圖</button>
                        <button id="clearBtn" class="btn btn-soft">🧹 清空</button>
                        <button id="sampleBtn" class="btn btn-gold">📘 載入範例</button>
                        
                    </div>
                    

                    <div id="statusBox" class="mt-2"></div>
                </div>
            </div>
        </div>

        <!-- 右側流程圖區 -->
        <div class="col-12 col-lg-7">
            <div class="row g-4">
                <!-- 解答流程圖 -->
                <div class="col-12">
                    <div class="card preview-panel">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <h4 class="mb-0">✅ 解答程式碼流程圖</h4>
                            <div class="flowchart-toolbar">
                                <button id="answerZoomOutBtn" class="btn btn-soft btn-sm">➖ 縮小</button>
                                <button id="answerZoomInBtn" class="btn btn-soft btn-sm">➕ 放大</button>
                                <button id="answerZoomResetBtn" class="btn btn-soft btn-sm">🔄 重設</button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="flowchart-canvas-wrap">
                                <div id="answerFlowchartArea"></div>
                                <div id="answerEmptyState" class="empty-state">
                                    尚未產生解答流程圖
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 學生流程圖 -->
                <div class="col-12">
                    <div class="card preview-panel">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <h4 class="mb-0">🧑‍🎓 學生程式碼流程圖</h4>
                            <div class="flowchart-toolbar">
                                <button id="studentZoomOutBtn" class="btn btn-soft btn-sm">➖ 縮小</button>
                                <button id="studentZoomInBtn" class="btn btn-soft btn-sm">➕ 放大</button>
                                <button id="studentZoomResetBtn" class="btn btn-soft btn-sm">🔄 重設</button>
                            </div>
                        </div>
                        <div id="studentErrorBox" class="alert alert-warning mt-3" style="display:none;"></div>
                        <div class="card-body">
                            <div class="flowchart-canvas-wrap">
                                <div id="studentFlowchartArea"></div>
                                <div id="studentEmptyState" class="empty-state">
                                    尚未產生學生流程圖
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>   
    </div> 
</div>  

<script>
const generateAnswerBtn = document.getElementById("generateAnswerBtn");
const generateStudentBtn = document.getElementById("generateStudentBtn");
const generateBothBtn = document.getElementById("generateBothBtn");
const clearBtn = document.getElementById("clearBtn");
const sampleBtn = document.getElementById("sampleBtn");

const languageEl = document.getElementById("language");
const problemTextEl = document.getElementById("problemText");
const answerCodeEl = document.getElementById("answerCode");
const studentCodeEl = document.getElementById("studentCode");
const statusBox = document.getElementById("statusBox");

const studentErrorBox = document.getElementById("studentErrorBox");

const chartStates = {
    answer: {
        area: document.getElementById("answerFlowchartArea"),
        empty: document.getElementById("answerEmptyState"),
        svg: null,
        chart: null,
        scale: 1,
        offsetX: 20,
        offsetY: 20,
        isPanning: false,
        startX: 0,
        startY: 0
    },
    student: {
        area: document.getElementById("studentFlowchartArea"),
        empty: document.getElementById("studentEmptyState"),
        svg: null,
        chart: null,
        scale: 1,
        offsetX: 20,
        offsetY: 20,
        isPanning: false,
        startX: 0,
        startY: 0
    }
};

// =============================
// Session 過期檢查
// =============================
function handleSessionExpired(json) {
    if (json && json.session_expired) {
        Swal.fire({
            icon: "warning",
            title: "登入已失效",
            text: "請重新登入後再繼續使用。",
            allowOutsideClick: false
        }).then(() => {
            window.location.href = "index.php";
        });
        return true;
    }
    return false;
}

// =============================
// 狀態顯示
// =============================
function setStatus(message = "", type = "info") {
    if (!message) {
        statusBox.innerHTML = "";
        return;
    }

    let className = "alert alert-secondary";
    if (type === "success") className = "alert alert-success";
    if (type === "error") className = "alert alert-danger";
    if (type === "warning") className = "alert alert-warning";
    if (type === "info") className = "alert alert-info";

    statusBox.innerHTML = `<div class="${className} py-2 px-3 mb-0">${message}</div>`;
}

// =============================
// 清空畫布
// =============================
function clearSingleFlowchart(targetKey) {
    const state = chartStates[targetKey];
    state.area.innerHTML = "";
    state.empty.style.display = "flex";
    state.chart = null;
    state.svg = null;
    state.scale = 1;
    state.offsetX = 20;
    state.offsetY = 20;
}
function clearAllFlowcharts() {
    clearSingleFlowchart("answer");
    clearSingleFlowchart("student");

    if (studentErrorBox) {
        studentErrorBox.style.display = "none";
        studentErrorBox.innerHTML = "";
    }
}
// =============================
// 套用 SVG transform
// =============================
function applyTransform(targetKey) {
    const state = chartStates[targetKey];
    if (!state.svg) return;
    state.svg.style.transform = `translate(${state.offsetX}px, ${state.offsetY}px) scale(${state.scale})`;
    state.svg.style.transformOrigin = "0 0";
}

// =============================
// 將 flowchart JSON 轉成 flowchart.js 定義
// =============================


function convertFlowchartJsonToDefinition(data) {
    if (!data || !Array.isArray(data.nodes) || !Array.isArray(data.edges)) {
        throw new Error("流程圖資料格式錯誤");
    }

    let def = "";

    data.nodes.forEach(node => {
        const rawType = String(node.type || "").toLowerCase();
        let text = String(node.text || "").replace(/\n/g, " ").trim();

        let flowType = "operation";
        if (rawType === "start") flowType = "start";
        else if (rawType === "end") flowType = "end";
        else if (rawType === "io") flowType = "inputoutput";
        else if (rawType === "decision") {
            flowType = "condition";
            text = text.replace(/[？?]\s*$/, ""); // 去掉尾端問號
        }

        def += `${node.id}=>${flowType}: ${text}\n`;
    });

    data.edges.forEach(edge => {
        let branch = "";
        const label = String(edge.label || "").toLowerCase();

        if (label === "yes" || label === "是") branch = "(yes)";
        else if (label === "no" || label === "否") branch = "(no)";

        def += `${edge.from}${branch}->${edge.to}\n`;
    });

    return def;
}

function normalizeNodeText(text) {
    return String(text || "")
        .replace(/\s*\[ID:[^\]]+\]/g, "")
        .replace(/[？?]\s*$/, "")
        .replace(/\s+/g, "")
        .trim();
}

function isShapeContainingText(shape, textBBox) {
    try {
        const box = shape.getBBox();

        // 文字中心點
        const cx = textBBox.x + textBBox.width / 2;
        const cy = textBBox.y + textBBox.height / 2;

        // 先用 bounding box 粗略判斷
        return (
            cx >= box.x &&
            cx <= box.x + box.width &&
            cy >= box.y &&
            cy <= box.y + box.height
        );
    } catch (e) {
        return false;
    }
}

function highlightErrorNodes(targetKey, flowchartData, feedback) {
    const state = chartStates[targetKey];
    if (!state || !state.svg || !feedback || !Array.isArray(feedback.error_nodes)) return;

    const errorTexts = feedback.error_nodes
        .map(err => {
            const node = (flowchartData.nodes || []).find(n => String(n.id) === String(err.node_id));
            return node ? normalizeNodeText(node.text) : null;
        })
        .filter(Boolean);

    if (errorTexts.length === 0) {
        console.warn("沒有可高亮的錯誤節點文字");
        return;
    }

    console.log("要高亮的節點文字:", errorTexts);

    const textEls = state.svg.querySelectorAll("text");
    const shapes = state.svg.querySelectorAll("rect, polygon, ellipse, path");
    let matchedCount = 0;

    textEls.forEach(textEl => {
        const currentText = normalizeNodeText(textEl.textContent || "");
        if (!currentText) return;

        const matched = errorTexts.some(errText =>
            currentText.includes(errText) || errText.includes(currentText)
        );
        if (!matched) return;

        matchedCount++;
        console.log("命中文字:", currentText);

        let textBBox;
        try {
            textBBox = textEl.getBBox();
        } catch (e) {
            return;
        }

        let targetShape = null;
        let bestArea = Infinity;

        shapes.forEach(shape => {
            if (!isShapeContainingText(shape, textBBox)) return;

            try {
                const box = shape.getBBox();
                const area = box.width * box.height;

                // 找最小但能包住文字的形狀，通常就是節點本體
                if (area < bestArea && area > 100) {
                    bestArea = area;
                    targetShape = shape;
                }
            } catch (e) {}
        });

        if (targetShape) {
            targetShape.style.stroke = "#e53935";
            targetShape.style.strokeWidth = "4";
            targetShape.style.fill = "#ffebee";
            targetShape.style.filter = "drop-shadow(0 0 10px rgba(229,57,53,0.45))";

            // 文字也一起變色
            textEl.style.fill = "#b71c1c";
            textEl.style.fontWeight = "700";

            // 同層 tspan 一起變色
            textEl.querySelectorAll("tspan").forEach(t => {
                t.style.fill = "#b71c1c";
                t.style.fontWeight = "700";
            });
        } else {
            console.warn("找到文字，但找不到包住它的節點圖形:", currentText);
        }
    });

    if (matchedCount === 0) {
        console.warn("沒有在 SVG 中找到對應的錯誤節點文字");
    }
}

// =============================
// 渲染流程圖
// =============================
function renderFlowchart(targetKey, data, feedback = null) {
    const state = chartStates[targetKey];
    clearSingleFlowchart(targetKey);

    if (!data || !Array.isArray(data.nodes) || data.nodes.length === 0) {
        return;
    }

    let definition = "";
    try {
        definition = convertFlowchartJsonToDefinition(data);
    } catch (err) {
        console.error(err);
        return;
    }

    try {
        state.chart = flowchart.parse(definition);
        state.area.innerHTML = "";
        state.empty.style.display = "none";

        state.chart.drawSVG(state.area, {
            "line-width": 2,
            "font-size": 16,
            "line-color": "#444",
            "element-color": "#d6a400",
            "fill": "#fffef8",
            "arrow-end": "block",
            "symbols": {
                "start": { "fill": "#81c784", "font-color": "#fff" },
                "end": { "fill": "#e57373", "font-color": "#fff" },
                "condition": { "fill": "#FFECB3" },
                "inputoutput": { "fill": "#BBDEFB" },
                "operation": { "fill": "#FFF8E1" }
            }
        });

        state.svg = state.area.querySelector("svg");
        if (state.svg) {
            state.scale = 1;
            state.offsetX = 20;
            state.offsetY = 20;
            applyTransform(targetKey);
        }

        setTimeout(() => {
            const anchors = state.area.querySelectorAll("svg a");
            anchors.forEach(a => {
                a.removeAttribute("href");
                a.onclick = (e) => e.preventDefault();
            });
        }, 50);


        setTimeout(() => {
            if (targetKey === "student" && feedback && feedback.has_error) {
                highlightErrorNodes(targetKey, data, feedback);
            }
        }, 250);

    } catch (err) {
        console.error(`render ${targetKey} error:`, err);
        clearSingleFlowchart(targetKey);
    }
}

// =============================
// 產生流程圖
// =============================

async function requestFlowchart(problemText, answerCode, studentCode, language, mode) {
    const response = await fetch("generate_flowchart_ai.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/json"
        },
        body: JSON.stringify({
            problem_text: problemText,
            answer_code: answerCode,
            student_code: studentCode,
            language: language,
            mode: mode
        })
    });

    return await response.json();
}

async function generateAnswerFlowchart() {
    const problemText = problemTextEl.value.trim();
    const answerCode = answerCodeEl.value.trim();
    const language = languageEl.value;

    if (!answerCode) {
        Swal.fire({
            icon: "warning",
            title: "請先輸入解答程式碼",
            text: "請輸入正確解答程式碼後再產生流程圖。"
        });
        return;
    }

    setStatus("⏳ 正在產生解答流程圖...", "info");
    generateAnswerBtn.disabled = true;

    try {
        clearSingleFlowchart("answer");

        const data = await requestFlowchart(
            problemText,
            answerCode,
            "",
            language,
            "answer"
        );

        if (handleSessionExpired(data)) return;

        if (!data.success) {
            throw new Error(data.error || "解答流程圖產生失敗");
        }

        renderFlowchart("answer", data.flowchart, null);
        setStatus("✅ 解答流程圖產生完成", "success");

    } catch (err) {
        console.error(err);
        setStatus("❌ " + err.message, "error");

        Swal.fire({
            icon: "error",
            title: "解答流程圖產生失敗",
            text: err.message
        });
    } finally {
        generateAnswerBtn.disabled = false;
    }
}

async function generateStudentFlowchart() {
    console.log("有進入 generateStudentFlowchart");
    const problemText = problemTextEl.value.trim();
    const studentCode = studentCodeEl.value.trim();
    const language = languageEl.value;
    const answerCode = answerCodeEl.value.trim();
    console.log("學生程式碼：", studentCode);
    console.log("問題正解:", answerCode);
    console.log("問題描述：", problemText);
    console.log("程式語言：", language);

    if (!studentCode) {
        Swal.fire({
            icon: "warning",
            title: "請先輸入學生程式碼",
            text: "請輸入學生程式碼後再產生流程圖。"
        });
        return;
    }

    setStatus("⏳ 正在產生學生流程圖...", "info");
    generateStudentBtn.disabled = true;

    try {
        clearSingleFlowchart("student");
        if (studentErrorBox) {
            studentErrorBox.style.display = "none";
            studentErrorBox.innerHTML = "";
        }

        const data = await requestFlowchart(
            problemText,
            "",
            studentCode,
            language,
            "student"
        );
        console.log("=== student API 回傳 ===");
        console.log(data);
        console.log("=== student feedback ===");
        console.log(data.feedback);
        console.log("=== student error_nodes ===");
        console.log(data.feedback?.error_nodes);
        console.log("=== student flowchart nodes ===");
        console.log(data.flowchart?.nodes);
        if (handleSessionExpired(data)) return;

        if (!data.success) {
            throw new Error(data.error || "學生流程圖產生失敗");
        }

        renderFlowchart("student", data.flowchart, data.feedback);
        showStudentFeedback(data.feedback);
        setStatus("✅ 學生流程圖產生完成", "success");

    } catch (err) {
        console.error(err);
        setStatus("❌ " + err.message, "error");

        Swal.fire({
            icon: "error",
            title: "學生流程圖產生失敗",
            text: err.message
        });
    } finally {
        generateStudentBtn.disabled = false;
    }
}

async function generateBothFlowcharts() {
    const problemText = problemTextEl.value.trim();
    const answerCode = answerCodeEl.value.trim();
    const studentCode = studentCodeEl.value.trim();
    const language = languageEl.value;

    if (!answerCode && !studentCode) {
        Swal.fire({
            icon: "warning",
            title: "請先輸入程式碼",
            text: "請至少輸入解答程式碼或學生程式碼。"
        });
        return;
    }

    setStatus("⏳ 正在產生流程圖...", "info");
    generateBothBtn.disabled = true;

    try {
        if (answerCode) {
            clearSingleFlowchart("answer");
            const answerData = await requestFlowchart(
                problemText,
                answerCode,
                "",
                language,
                "answer"
            );

            if (handleSessionExpired(answerData)) return;
            if (!answerData.success) {
                throw new Error("解答流程圖產生失敗：" + (answerData.error || "未知錯誤"));
            }

            renderFlowchart("answer", answerData.flowchart, null);
        }

        if (studentCode) {
            clearSingleFlowchart("student");
            if (studentErrorBox) {
                studentErrorBox.style.display = "none";
                studentErrorBox.innerHTML = "";
            }

            const studentData = await requestFlowchart(
                problemText,
                "",
                studentCode,
                language,
                "student"
            );

            if (handleSessionExpired(studentData)) return;
            if (!studentData.success) {
                throw new Error("學生流程圖產生失敗：" + (studentData.error || "未知錯誤"));
            }

            renderFlowchart("student", studentData.flowchart, studentData.feedback);
            showStudentFeedback(studentData.feedback);
        }

        if (answerCode && studentCode) {
            setStatus("✅ 兩個流程圖產生完成", "success");
        } else if (answerCode) {
            setStatus("✅ 解答流程圖產生完成", "success");
        } else if (studentCode) {
            setStatus("✅ 學生流程圖產生完成", "success");
        }

    } catch (err) {
        console.error(err);
        setStatus("❌ " + err.message, "error");

        Swal.fire({
            icon: "error",
            title: "流程圖產生失敗",
            text: err.message
        });
    } finally {
        generateBothBtn.disabled = false;
    }
}

// =============================
// 範例載入
// =============================
function loadSample() {
    languageEl.value = "python";

    problemTextEl.value = "請輸入一個整數 n，判斷它是否為偶數，若是則輸出「偶數」，否則輸出「奇數」。";

    answerCodeEl.value =
`n = int(input())
if n % 2 == 0:
    print("偶數")
else:
    print("奇數")`;

    studentCodeEl.value =
`n = int(input())
if n % 2 == 1:
    print("偶數")
else:
    print("奇數")`;

    setStatus("📘 已載入範例，可直接按下「產生流程圖」", "info");
}

function showStudentFeedback(feedback) {
    if (!studentErrorBox) return;

    if (!feedback || !feedback.has_error || !Array.isArray(feedback.error_nodes) || feedback.error_nodes.length === 0) {
        studentErrorBox.style.display = "none";
        studentErrorBox.innerHTML = "";
        return;
    }

    let html = "⚠️ 偵測到學生程式邏輯問題：<br><br>";

    feedback.error_nodes.forEach(err => {
        html += `
            <div style="margin-bottom:12px;">
                <b>📍 第 ${err.line ?? "?"} 行</b><br>
                問題：${err.message ?? "未提供"}<br>
                建議：${err.suggestion ?? "未提供"}
            </div>
        `;
    });

    studentErrorBox.innerHTML = html;
    studentErrorBox.style.display = "block";
}

// =============================
// 清空輸入
// =============================
function clearAllInputs() {
    problemTextEl.value = "";
    answerCodeEl.value = "";
    studentCodeEl.value = "";
    languageEl.value = "python";
    clearAllFlowcharts();
    setStatus("", "info");
}

// =============================
// 縮放按鈕
// =============================
function bindZoomControls(targetKey, zoomInId, zoomOutId, zoomResetId) {
    document.getElementById(zoomInId).addEventListener("click", () => {
        const state = chartStates[targetKey];
        if (!state.svg) return;
        state.scale = Math.min(state.scale + 0.1, 3);
        applyTransform(targetKey);
    });

    document.getElementById(zoomOutId).addEventListener("click", () => {
        const state = chartStates[targetKey];
        if (!state.svg) return;
        state.scale = Math.max(state.scale - 0.1, 0.2);
        applyTransform(targetKey);
    });

    document.getElementById(zoomResetId).addEventListener("click", () => {
        const state = chartStates[targetKey];
        if (!state.svg) return;
        state.scale = 1;
        state.offsetX = 20;
        state.offsetY = 20;
        applyTransform(targetKey);
    });
}

// =============================
// 拖曳平移（滑鼠）
// =============================
function bindCanvasInteraction(targetKey) {
    const state = chartStates[targetKey];
    const area = state.area;

    area.addEventListener("mousedown", (e) => {
        if (!state.svg) return;
        state.isPanning = true;
        area.style.cursor = "grabbing";
        state.startX = e.clientX - state.offsetX;
        state.startY = e.clientY - state.offsetY;
    });

    window.addEventListener("mousemove", (e) => {
        if (!state.isPanning || !state.svg) return;
        state.offsetX = e.clientX - state.startX;
        state.offsetY = e.clientY - state.startY;
        applyTransform(targetKey);
    });

    window.addEventListener("mouseup", () => {
        state.isPanning = false;
        area.style.cursor = "grab";
    });

    area.addEventListener("wheel", (e) => {
        if (!state.svg) return;
        e.preventDefault();

        const delta = e.deltaY < 0 ? 0.1 : -0.1;
        state.scale = Math.max(0.2, Math.min(3, state.scale + delta));
        applyTransform(targetKey);
    }, { passive: false });
}

// =============================
// 事件綁定
// =============================
bindZoomControls("answer", "answerZoomInBtn", "answerZoomOutBtn", "answerZoomResetBtn");
bindZoomControls("student", "studentZoomInBtn", "studentZoomOutBtn", "studentZoomResetBtn");

bindCanvasInteraction("answer");
bindCanvasInteraction("student");

generateAnswerBtn.addEventListener("click", generateAnswerFlowchart);
generateStudentBtn.addEventListener("click", generateStudentFlowchart);
generateBothBtn.addEventListener("click", generateBothFlowcharts);
clearBtn.addEventListener("click", clearAllInputs);
sampleBtn.addEventListener("click", loadSample);

async function judgeStudentCode(studentCode, testCases) {
    const res = await fetch("judge_python.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/json"
        },
        body: JSON.stringify({
            student_code: studentCode,
            test_cases: testCases
        })
    });

    return await res.json();
}
</script>

</body>
</html>