<?php
require 'db.php';
session_start();
date_default_timezone_set('Asia/Taipei');

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    die("❌ 尚未登入");
}

$todayStart = date('Y-m-d 00:00:00');
$todayEnd   = date('Y-m-d 23:59:59');

// 📊 查詢今日排行榜（只包含學生）
$sql = "
    SELECT 
        sa.user_id,
        u.Username AS name,
        u.ClassName AS class_name,
        COUNT(sa.id) AS attempts,
        SUM(sa.is_correct) AS correct_count,
        ROUND(SUM(sa.is_correct) / NULLIF(COUNT(sa.id), 0) * 100, 1) AS accuracy
    FROM student_answers sa
    JOIN users u ON sa.user_id = u.UserID
    WHERE u.role = 'student'
      AND sa.answered_at BETWEEN ? AND ?
    GROUP BY sa.user_id
    ORDER BY correct_count DESC, accuracy DESC, attempts DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $todayStart, $todayEnd);
$stmt->execute();
$result = $stmt->get_result();

$leaderboard = [];
while ($row = $result->fetch_assoc()) {
    $leaderboard[] = $row;
}
$stmt->close();

// 找出登入者名次
$userRank = null;
$userData = null;
foreach ($leaderboard as $i => $row) {
    if ($row['user_id'] == $userId) {
        $userRank = $i + 1;
        $userData = $row;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<title>🏆 每日練習排行榜</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://unpkg.com/@lottiefiles/lottie-player@latest/dist/lottie-player.js"></script>
<style>
body {
  background: #fff8e1;
  font-family: 'Chiron GoRound TC', sans-serif;
  overflow-x: hidden;
}
.table thead th {
  background: #ffc107;
  color: #000;
}
tr:nth-child(1) td { background: #fff3cd; font-weight: bold; } /* 🥇 */
tr:nth-child(2) td { background: #e9ecef; } /* 🥈 */
tr:nth-child(3) td { background: #f8f9fa; } /* 🥉 */

/* === 背景遮罩與動畫 === */
#overlay {
  display: none;
  position: fixed;
  top: 0; left: 0;
  width: 100%; height: 100%;
  background: rgba(0,0,0,0.6);
  backdrop-filter: blur(2px);
  z-index: 9998;
}
#rankAnimation {
  display: none;
  position: fixed;
  inset: 0;
  z-index: 9999;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  opacity: 0;
  transition: opacity 0.8s ease-in-out;
}
#rankAnimation.show { opacity: 1; }

#rankText {
  font-size: 3rem;
  font-weight: 900;
  letter-spacing: 3px;
  animation: pop 1.2s ease-out 1;
}
@keyframes pop {
  0% { transform: scale(0.5); opacity: 0; }
  60% { transform: scale(1.1); opacity: 1; }
  100% { transform: scale(1); }
}
@keyframes glow {
  0%,100% { text-shadow: 0 0 20px var(--glow1), 0 0 40px var(--glow2); }
  50% { text-shadow: 0 0 30px var(--glow2), 0 0 60px var(--glow1); }
}
#confetti {
  position: fixed;
  width: 100%; height: 100%;
  top: 0; left: 0;
  z-index: 9000;
  pointer-events: none;
}
</style>
</head>
<body>

<?php include 'Navbar.php'; ?>

<div class="container mt-5">
  <h3 class="fw-bold mb-4 text-center">🏆 今日練習排行榜</h3>

  <table class="table table-bordered table-striped text-center align-middle">
    <thead>
      <tr>
        <th>名次</th>
        <th>學生姓名</th>
        <th>班級</th>
        <th>答對題數</th>
        <th>作答次數</th>
        <th>正確率</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $rank = 1;
      foreach ($leaderboard as $row):
          $medal = ($rank == 1 ? "🥇" : ($rank == 2 ? "🥈" : ($rank == 3 ? "🥉" : "")));
      ?>
      <tr<?= ($row['user_id'] == $userId) ? " style='background:#ffe082;font-weight:bold;'" : "" ?>>
        <td><?= $medal ?: $rank ?></td>
        <td><?= htmlspecialchars($row['name']) ?></td>
        <td><?= htmlspecialchars($row['class_name'] ?? '-') ?></td>
        <td><?= (int)$row['correct_count'] ?></td>
        <td><?= (int)$row['attempts'] ?></td>
        <td><?= is_null($row['accuracy']) ? '0' : $row['accuracy'] ?>%</td>
      </tr>
      <?php $rank++; endforeach; ?>
    </tbody>
  </table>

  <div class="text-center mt-4">
    <a href="index.php" class="btn btn-secondary">🏠 返回首頁</a>
  </div>
</div>

<!-- 🎉 動畫元素區 -->
<div id="overlay"></div>
<div id="rankAnimation">
  <lottie-player id="trophyAnim"
    background="transparent"
    speed="1"
    style="width:250px;height:250px;margin:0 auto;"
    autoplay>
  </lottie-player>
  <div id="rankText"></div>
</div>
<canvas id="confetti"></canvas>

<?php if ($userRank): ?>
<script>
document.addEventListener("DOMContentLoaded", () => {
  const rank = <?= $userRank ?>;
  const overlay = document.getElementById("overlay");
  const anim = document.getElementById("rankAnimation");
  const text = document.getElementById("rankText");
  const lottie = document.getElementById("trophyAnim");

  // 💎 根據名次設定樣式與動畫
  let colorTheme = {
    text: "#FFD700", glow1: "#FFF59D", glow2: "#FFEB3B", trophy: "animations/trophy_gold.json"
  };
  if (rank === 2) colorTheme = { text: "#C0C0C0", glow1: "#E0E0E0", glow2: "#B0BEC5", trophy: "animations/trophy_silver.json" };
  else if (rank === 3) colorTheme = { text: "#CD7F32", glow1: "#FFCC80", glow2: "#FFB74D", trophy: "animations/trophy_bronze.json" };
  else if (rank > 3) colorTheme = { text: "#29B6F6", glow1: "#81D4FA", glow2: "#4FC3F7", trophy: "animations/trophy_blue.json" };

  // ✅ 延遲載入確保 lottie ready
  setTimeout(() => {
    if (lottie && typeof lottie.load === "function") {
      lottie.load(colorTheme.trophy);
    }
  }, 200);

  // 設定文字樣式
  text.style.color = colorTheme.text;
  text.style.setProperty("--glow1", colorTheme.glow1);
  text.style.setProperty("--glow2", colorTheme.glow2);
  text.style.animation = "glow 2s ease-in-out infinite, pop 1.2s ease-out 1";
  text.innerText = `🎉 你今天排名第 ${rank} 名！`;

  // 顯示動畫
  overlay.style.display = 'block';
  anim.style.display = 'flex';
  requestAnimationFrame(() => anim.classList.add("show"));

  // 🎆 彩帶效果
  const confetti = document.getElementById("confetti");
  const ctx = confetti.getContext("2d");
  confetti.width = window.innerWidth;
  confetti.height = window.innerHeight;
  const pieces = Array.from({ length: 120 }).map(() => ({
    x: Math.random() * confetti.width,
    y: Math.random() * confetti.height - confetti.height,
    r: Math.random() * 6 + 4,
    c: `hsl(${Math.random() * 360},100%,60%)`,
    s: Math.random() + 2
  }));
  (function drawConfetti() {
    ctx.clearRect(0, 0, confetti.width, confetti.height);
    pieces.forEach(p => {
      ctx.beginPath();
      ctx.arc(p.x, p.y, p.r, 0, 2 * Math.PI);
      ctx.fillStyle = p.c;
      ctx.fill();
      p.y += p.s;
      if (p.y > confetti.height) p.y = -10;
    });
    requestAnimationFrame(drawConfetti);
  })();

  // ⏳ 5 秒後淡出
  setTimeout(() => {
    overlay.style.transition = 'opacity 1.5s';
    anim.style.transition = 'opacity 1.5s';
    overlay.style.opacity = '0';
    anim.style.opacity = '0';
    setTimeout(() => {
      overlay.style.display = 'none';
      anim.style.display = 'none';
      confetti.remove();
    }, 1500);
  }, 5000);
});
</script>
<?php endif; ?>
</body>
</html>
