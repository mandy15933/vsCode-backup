// feedback_modal.js
// === 🧠 顯示問卷模組 ===
// 傳入 toolType, questionId，會顯示問卷並自動儲存
async function showFeedbackModal(toolType, questionId) {
  const toolName = toolType === "mindmap" ? "🌐 心智圖" : "🔄 流程圖";

  const resQ = await Swal.fire({
    title: `🧠 請評估 ${toolName}`,
    html: `
      <div class="text-start" style="font-size:16px;">
        <p class="mb-3">請以 <b>1（非常不同意）～5（非常同意）</b> 評分。</p>

        <div class="rounded-3 border p-3 bg-light mb-3">
          <h6 class="fw-bold mb-3 text-primary">🧠 有用性（Usefulness）</h6>
          ${["U1","U2","U3","U4"].map(id => `
            <div class="mb-3">
              <label class="form-label fw-semibold">${
                id === "U1" ? `使用這個${toolName}有助於我理解題目的內容。` :
                id === "U2" ? `這個${toolName}幫助我更快地找出程式邏輯或答案。` :
                id === "U3" ? `使用這個${toolName}能幫助我更有效地學習。` :
                               `若沒有這個${toolName}，我會更難完成題目。`
              }</label>
              <div class="d-flex align-items-center gap-2">
                <span class="small text-muted">1</span>
                <input type="range" min="1" max="5" value="3" id="${id}" class="form-range flex-grow-1 stylish-range">
                <span id="${id}_val" class="fw-bold text-primary">3</span>
                <span class="small text-muted">5</span>
              </div>
            </div>
          `).join("")}
        </div>

        <div class="rounded-3 border p-3 bg-light mb-3">
          <h6 class="fw-bold mb-3 text-success">💻 易用性（Usability）</h6>
          ${["E1","E2","E3","E4"].map(id => `
            <div class="mb-3">
              <label class="form-label fw-semibold">${
                id === "E1" ? `這個${toolName}的操作方式很容易理解。` :
                id === "E2" ? `我能輕鬆找到我想使用的功能。` :
                id === "E3" ? `在操作過程中，我幾乎不需要額外的說明或幫助。` :
                               `這個${toolName}的介面設計讓我感覺使用起來很自然。`
              }</label>
              <div class="d-flex align-items-center gap-2">
                <span class="small text-muted">1</span>
                <input type="range" min="1" max="5" value="3" id="${id}" class="form-range flex-grow-1 stylish-range">
                <span id="${id}_val" class="fw-bold text-success">3</span>
                <span class="small text-muted">5</span>
              </div>
            </div>
          `).join("")}
        </div>

        <div>
          <label class="form-label fw-semibold text-secondary">✍️ 其他想法或建議（可留空）</label>
          <textarea id="feedbackText" class="form-control" rows="3" style="border-radius:10px;"></textarea>
        </div>
      </div>
    `,
    confirmButtonText: "💾 送出問卷",
    width: 750,
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
        usefulness: { U1: get("U1"), U2: get("U2"), U3: get("U3"), U4: get("U4") },
        usability: { E1: get("E1"), E2: get("E2"), E3: get("E3"), E4: get("E4") },
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
        usability: resQ.value.usability,
        comment: resQ.value.comment
      })
    });

    await Swal.fire({
      icon: "success",
      title: `感謝你對${toolName}的回饋！`,
      timer: 1000,
      showConfirmButton: false
    });
  }
}
