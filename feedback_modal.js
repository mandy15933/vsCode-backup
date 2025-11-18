// feedback_modal.js — 最強不可逃脫問卷 🚫ESC🚫關閉🚫外點

const FEEDBACK_LOCK_KEY = "feedback_lock_question";

// === 1️⃣ 全域強制攔截 ESC（3 層防禦）===
function blockESC(e) {
    if (e.key === "Escape") {
        e.stopImmediatePropagation();
        e.preventDefault();
        return false;
    }
}

// === 啟動 ESC 封鎖 ===
function enableESCBlock() {
    document.addEventListener("keydown", blockESC, true);
    window.addEventListener("keydown", blockESC, true);
    window.addEventListener("keyup", blockESC, true);
}

// === 解除 ESC 封鎖 ===
function disableESCBlock() {
    document.removeEventListener("keydown", blockESC, true);
    window.removeEventListener("keydown", blockESC, true);
    window.removeEventListener("keyup", blockESC, true);
}



// === 🧠 問卷主函式 ===
async function showFeedbackModal(toolType, questionId) {

    const toolName = toolType === "mindmap" ? "🧠 心智圖" : "🔄 流程圖";

    window._currentSurveyType = toolType;
    window._currentSurveyQid = questionId;
    window._surveyAllowedToClose = false;

    // 🔒 本地鎖
    localStorage.setItem(FEEDBACK_LOCK_KEY, String(questionId));

    // 🛑 開始封鎖 ESC
    enableESCBlock();

    // 🛡️ 防止使用者用 DOM 手段刪掉 swal element
    if (window._swalObserver) window._swalObserver.disconnect();
    window._swalObserver = new MutationObserver(() => {
        const gone = !document.querySelector(".swal2-container");
        if (gone && !window._surveyAllowedToClose) {
            showFeedbackModal(window._currentSurveyType, window._currentSurveyQid);
        }
    });
    window._swalObserver.observe(document.body, { childList: true });



    // === 🧾 問卷 ===
    const resQ = await Swal.fire({
        title: `📝 請評估 ${toolName}`,
        html: `
        <div class="text-start" style="font-size:16px;">
            <p class="mb-3">請以 <b>1～5</b> 評分。</p>

            <!-- PU -->
            <div class="rounded-3 border p-3 bg-light mb-3">
            <h6 class="fw-bold mb-3 text-primary">🟦 知覺有用性（PU）</h6>
            ${[
                { id: "PU1", text: `AI 生成的${toolName}有助於我更快理解題目的內容。` },
                { id: "PU2", text: `AI 生成的${toolName}能幫助我抓出題目的重點與結構。` },
                { id: "PU3", text: `AI 生成的${toolName}能提升我思考解題方法的效率。` }
            ].map(q => `
                <div class="mb-3">
                <label class="form-label fw-semibold">${q.text}</label>
                <div class="d-flex align-items-center gap-2">
                    <span>1</span>
                    <input type="range" min="1" max="5" value="3" id="${q.id}" class="form-range flex-grow-1">
                    <span id="${q.id}_val" class="fw-bold text-primary">3</span>
                    <span>5</span>
                </div>
                </div>
            `).join("")}
            </div>

            <!-- PEOU -->
            <div class="rounded-3 border p-3 bg-light mb-3">
            <h6 class="fw-bold mb-3 text-success">🟩 知覺易用性（PEOU）</h6>
            ${[
                { id: "PE1", text: `AI 生成的${toolName}呈現方式清楚、容易理解。` },
                { id: "PE2", text: `閱讀 ${toolName} 不需要花費太多心力。` },
                { id: "PE3", text: `我能輕鬆從 ${toolName} 中找到需要的資訊。` }
            ].map(q => `
                <div class="mb-3">
                <label class="form-label fw-semibold">${q.text}</label>
                <div class="d-flex align-items-center gap-2">
                    <span>1</span>
                    <input type="range" min="1" max="5" value="3" id="${q.id}" class="form-range flex-grow-1">
                    <span id="${q.id}_val" class="fw-bold text-success">3</span>
                    <span>5</span>
                </div>
                </div>
            `).join("")}
            </div>

            <!-- Usability -->
            <div class="rounded-3 border p-3 bg-light mb-3">
            <h6 class="fw-bold mb-3 text-warning">🟧 可用性（Usability）</h6>
            ${[
                { id: "US1", text: `AI 生成的${toolName}內容與題目需求高度相關。` },
                { id: "US2", text: `${toolName} 的結構與分類合理且清楚。` },
                { id: "US3", text: `${toolName} 的品質良好，能協助我作答。` }
            ].map(q => `
                <div class="mb-3">
                <label class="form-label fw-semibold">${q.text}</label>
                <div class="d-flex align-items-center gap-2">
                    <span>1</span>
                    <input type="range" min="1" max="5" value="3" id="${q.id}" class="form-range flex-grow-1">
                    <span id="${q.id}_val" class="fw-bold text-warning">3</span>
                    <span>5</span>
                </div>
                </div>
            `).join("")}
            </div>

            <!-- Feedback -->
            <div>
                <label class="form-label fw-semibold text-secondary">✍️ 其他建議（可留空）</label>
                <textarea id="feedbackText" class="form-control" rows="3"></textarea>
            </div>
        </div>`,
        
        confirmButtonText: "💾 送出問卷",
        width: 760,
        backdrop: true,
        allowEscapeKey: false,
        allowOutsideClick: false,
        allowEnterKey: false,
        didOpen: () => {
            const box = Swal.getHtmlContainer();
            const sliders = box.querySelectorAll(".form-range");

            sliders.forEach(r => {
                const label = box.querySelector(`#${r.id}_val`);

                const updateUI = val => {
                    const hue = 20 + (val - 1) * 30;
                    const color = `hsl(${hue}, 80%, 45%)`;
                    r.style.background =
                        `linear-gradient(to right, ${color} ${(val-1)*25}%, #e0e0e0 ${(val-1)*25}%)`;
                    label.textContent = val;
                    label.style.color = color;
                };

                r.addEventListener("input", e => updateUI(e.target.value));
                updateUI(r.value);
            });
        },

        willClose: () => {
            if (!window._surveyAllowedToClose) return false;
        },

        preConfirm: () => {
            const get = id => Number(document.getElementById(id)?.value || 3);
            return {
                tool_type: toolType,
                usefulness: { PU1: get("PU1"), PU2: get("PU2"), PU3: get("PU3") },
                ease_of_use: { PE1: get("PE1"), PE2: get("PE2"), PE3: get("PE3") },
                usability: { US1: get("US1"), US2: get("US2"), US3: get("US3") },
                comment: document.getElementById("feedbackText").value || ""
            };
        }
    });



    // === 🟢 成功送出 → 解鎖 & 移除封鎖 ===
    if (resQ.isConfirmed) {

        window._surveyAllowedToClose = true;
        localStorage.removeItem(FEEDBACK_LOCK_KEY);

        disableESCBlock();
        window._swalObserver?.disconnect();

        await fetch("save_feedback.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                question_id: questionId,
                ...resQ.value
            })
        });

        Swal.fire({
            icon: "success",
            title: "感謝你的回饋！",
            timer: 1000,
            showConfirmButton: false
        });
    }
}
