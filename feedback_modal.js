// feedback_modal.js
// === 📝 顯示問卷模組 ===
// 傳入 toolType, questionId，會顯示問卷並自動儲存
async function showFeedbackModal(toolType, questionId) {
  const toolName = toolType === "mindmap" ? "🧠 心智圖" : "🔄 流程圖";

  const resQ = await Swal.fire({
    title: `📝 請評估 ${toolName}`,
    html: `
      <div class="text-start" style="font-size:16px;">
        <p class="mb-3">請以 <b>1（非常不同意）～5（非常同意）</b> 評分。</p>

        <!-- PU：知覺有用性 -->
        <div class="rounded-3 border p-3 bg-light mb-3">
          <h6 class="fw-bold mb-3 text-primary">🟦 知覺有用性（Perceived Usefulness, PU）</h6>

          ${[
            { id: "PU1", text: `AI 生成的${toolName}有助於我更快理解題目的內容。` },
            { id: "PU2", text: `AI 生成的${toolName}能幫助我抓出題目的重點與結構。` },
            { id: "PU3", text: `AI 生成的${toolName}能提升我思考解題方法的效率。` }
          ].map(q => `
            <div class="mb-3">
              <label class="form-label fw-semibold">${q.text}</label>
              <div class="d-flex align-items-center gap-2">
                <span class="small text-muted">1</span>
                <input type="range" min="1" max="5" value="3" id="${q.id}" class="form-range flex-grow-1 stylish-range">
                <span id="${q.id}_val" class="fw-bold text-primary">3</span>
                <span class="small text-muted">5</span>
              </div>
            </div>
          `).join("")}
        </div>

        <!-- PEOU：知覺易用性 -->
        <div class="rounded-3 border p-3 bg-light mb-3">
          <h6 class="fw-bold mb-3 text-success">🟩 知覺易用性（Perceived Ease of Use, PEOU）</h6>

          ${[
            { id: "PE1", text: `AI 生成的${toolName}呈現方式清楚、容易理解。` },
            { id: "PE2", text: `我覺得閱讀 AI 生成的${toolName}不需要花費太多心力。` },
            { id: "PE3", text: `我能輕鬆從 AI 生成的${toolName}中找到我需要的資訊。` }
          ].map(q => `
            <div class="mb-3">
              <label class="form-label fw-semibold">${q.text}</label>
              <div class="d-flex align-items-center gap-2">
                <span class="small text-muted">1</span>
                <input type="range" min="1" max="5" value="3" id="${q.id}" class="form-range flex-grow-1 stylish-range">
                <span id="${q.id}_val" class="fw-bold text-success">3</span>
                <span class="small text-muted">5</span>
              </div>
            </div>
          `).join("")}
        </div>

        <!-- Usability -->
        <div class="rounded-3 border p-3 bg-light mb-3">
          <h6 class="fw-bold mb-3 text-warning">🟧 可用性（Usability）</h6>

          ${[
            { id: "US1", text: `AI 生成的${toolName}內容與題目需求高度相關。` },
            { id: "US2", text: `AI 生成的${toolName}的結構與分類是合理且有條理的。` },
            { id: "US3", text: `整體而言，AI 生成的${toolName}品質良好，能協助我順利完成作答。` }
          ].map(q => `
            <div class="mb-3">
              <label class="form-label fw-semibold">${q.text}</label>
              <div class="d-flex align-items-center gap-2">
                <span class="small text-muted">1</span>
                <input type="range" min="1" max="5" value="3" id="${q.id}" class="form-range flex-grow-1 stylish-range">
                <span id="${q.id}_val" class="fw-bold text-warning">3</span>
                <span class="small text-muted">5</span>
              </div>
            </div>
          `).join("")}
        </div>

        <!-- Feedback -->
        <div>
          <label class="form-label fw-semibold text-secondary">✍️ 其他想法或建議（可留空）</label>
          <textarea id="feedbackText" class="form-control" rows="3" style="border-radius:10px;"></textarea>
        </div>

      </div>
    `,
    confirmButtonText: "💾 送出問卷",
    width: 760,
    confirmButtonColor: "#3085d6",
    allowOutsideClick: false,

    didOpen: () => {
      const box = Swal.getHtmlContainer();
      const sliders = box.querySelectorAll(".form-range");

      sliders.forEach(r => {
        const label = box.querySelector(`#${r.id}_val`);
        const updateUI = val => {
          const hue = 20 + (val - 1) * 30;
          const color = `hsl(${hue}, 80%, 45%)`;
          r.style.background = `linear-gradient(to right, ${color} ${(val-1)*25}%, #e0e0e0 ${(val-1)*25}%)`;
          label.textContent = val;
          label.style.color = color;
        };
        r.addEventListener("input", e => updateUI(e.target.value));
        updateUI(r.value);
      });
    },

    preConfirm: () => {
      const box = Swal.getHtmlContainer();
      const get = id => Number(box.querySelector(`#${id}`)?.value || 3);
      return {
        tool_type: toolType,
        usefulness: { PU1: get("PU1"), PU2: get("PU2"), PU3: get("PU3") },
        ease_of_use: { PE1: get("PE1"), PE2: get("PE2"), PE3: get("PE3") },
        usability: { US1: get("US1"), US2: get("US2"), US3: get("US3") },
        comment: box.querySelector("#feedbackText")?.value || ""
      };
    }
  });

  if (resQ.isConfirmed) {
    await fetch("save_feedback.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        question_id: questionId,
        tool_type: resQ.value.tool_type,
        usefulness: resQ.value.usefulness,
        ease_of_use: resQ.value.ease_of_use,
        usability: resQ.value.usability,
        comment: resQ.value.comment
      })
    });

    await Swal.fire({
      icon: "success",
      title: `感謝你對 ${toolName} 的回饋！`,
      timer: 1000,
      showConfirmButton: false
    });
  }
}
