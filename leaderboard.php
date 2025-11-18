<?php
require 'db.php';
session_start();
date_default_timezone_set('Asia/Taipei');

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) die("❌ 尚未登入");

/* 取得登入者班級 */
$stmt = $conn->prepare("SELECT ClassName FROM users WHERE UserID = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$userClass = $row['ClassName'] ?? null;
$stmt->close();

if (!$userClass) die("❌ 找不到使用者班級");

$todayStart = date('Y-m-d 00:00:00');
$todayEnd   = date('Y-m-d 23:59:59');

/* 📊 查詢同班每日排行榜 */
$sql = "
    SELECT 
        sa.user_id,
        u.Username AS name,
        u.ClassName AS class_name,

        COUNT(sa.id) AS attempts,
        SUM(sa.is_correct) AS correct_count,
        MAX(CASE WHEN sa.is_correct = 1 THEN sa.answered_at END) AS finish_time

    FROM student_answers sa
    JOIN users u ON sa.user_id = u.UserID

    WHERE u.role = 'student'
      AND u.ClassName = ?
      AND sa.answered_at BETWEEN ? AND ?

    GROUP BY sa.user_id

    ORDER BY 
        correct_count DESC,
        finish_time ASC,
        attempts ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("sss", $userClass, $todayStart, $todayEnd);
$stmt->execute();
$result = $stmt->get_result();

$leaderboard = [];
while ($row = $result->fetch_assoc()) {
    $leaderboard[] = $row;
}
$stmt->close();

/* 找出登入者名次 */
$userRank = null;
foreach ($leaderboard as $i => $row) {
    if ($row['user_id'] == $userId) {
        $userRank = $i + 1;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<title>🏆 每日練習排行榜（班級）</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://unpkg.com/@lottiefiles/lottie-player@latest/dist/lottie-player.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="anime-yellow-theme.css">

<style>
body {
  background: #fff8e1;
  font-family: 'Chiron GoRound TC', sans-serif;
}
.table thead th {
  background: #ffecb4ff;
  color: #000;
}
tr:nth-child(1) td { background: #fff3cd; font-weight: bold; }
tr:nth-child(2) td { background: #e9ecef; }
tr:nth-child(3) td { background: #f8f9fa; }

/* 動畫區 */
/* 動畫區 */
#overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.6);
  backdrop-filter: blur(2px);
  z-index: 9998;
  pointer-events: auto;
}

#overlay.hidden {
  display: none !important;
  pointer-events: none !important;
}


#rankAnimation {
  display: none;
  position: fixed;
  inset: 0;
  z-index: 9999;
  justify-content: center;
  align-items: center;
  flex-direction: column;
  opacity: 0;
  transition: opacity 0.8s ease;
  pointer-events: none;
}

#rankAnimation.show {
  display: flex;
  opacity: 1;
  pointer-events: auto;
}

#rankAnimation.hidden {
  display: none !important;
  opacity: 0 !important;
  pointer-events: none !important;
}

#rankText {
  font-size: 3rem;
  font-weight: 900;
}

#confetti {
  position: fixed;
  inset: 0;
  z-index: 9000;
  pointer-events: none;
}

</style>
</head>

<body>
<?php include 'Navbar.php'; ?>

<div class="container mt-5">
  <h3 class="fw-bold mb-4 text-center">🏆 今日班級練習排行榜</h3>

  <table class="table table-bordered table-striped text-center align-middle">
    <thead>
      <tr>
        <th>名次</th>
        <th>學生姓名</th>
        <th>答對題數</th>
        <th>完成時間</th>
      </tr>
    </thead>
    <tbody>
      <?php $rank = 1; foreach ($leaderboard as $row): ?>
      <tr <?= ($row['user_id'] == $userId) ? "style='background:#ffe082;font-weight:bold;'" : "" ?>>
        <td><?= $rank ?></td>
        <td><?= htmlspecialchars($row['name']) ?></td>
        <td><?= (int)$row['correct_count'] ?></td>
        <td><?= $row['finish_time'] ?: "-" ?></td>
      </tr>
      <?php $rank++; endforeach; ?>
    </tbody>
  </table>

  <div class="text-center mt-4">
    <a href="index.php" class="btn btn-secondary">🏠 返回首頁</a>
  </div>
</div>

<!-- 動畫區 -->
<div id="overlay"></div>

<div id="rankAnimation">
  <lottie-player id="trophyAnim"
      background="transparent"
      speed="1.5"
      style="width:250px;height:250px;"
      autoplay>
  </lottie-player>

  <div id="rankText"></div>
</div>

<canvas id="confetti" style="pointer-events:none"></canvas>

<script>
document.addEventListener("DOMContentLoaded", () => {

  const userRank = <?= json_encode($userRank) ?>;
  const leaderboardUsers = <?= json_encode(array_column($leaderboard, 'user_id')) ?>;
  const userId = <?= $userId ?>;
  const hasRecord = leaderboardUsers.includes(userId);

  const overlay = document.getElementById("overlay");
  const anim = document.getElementById("rankAnimation");
  const text = document.getElementById("rankText");
  const lottie = document.getElementById("trophyAnim");
  const confettiCanvas = document.getElementById("confetti");

  // 無作答
  if (!hasRecord) {
    Swal.fire({
      title: "您今天還沒有作答！",
      text: "趕快爭取今日的獎盃吧！",
      icon: "warning",
      confirmButtonText: "前往練習",
      confirmButtonColor: "#fbc02d",
      allowOutsideClick: false
    }).then(() => {
      window.location.href = "courses.php";
    });
    return;
  }

  // 無排名
  if (!userRank) return;

  // 設定動畫主題
  const themeMap = {
      1: {text:"#FFD700", trophy:"animations/trophy_gold.json"},
      2: {text:"#C0C0C0", trophy:"animations/trophy_silver.json"},
      3: {text:"#CD7F32", trophy:"animations/trophy_bronze.json"}
  };

  const theme = themeMap[userRank] ?? {
      text:"#29B6F6",
      trophy:"animations/trophy_blue.json"
  };

  // 切換 Lottie 動畫
  setTimeout(() => {
      lottie.load(theme.trophy);
  }, 200);

  text.style.color = theme.text;
  text.innerText = `🎉 你今天排名第 ${userRank} 名！`;

  overlay.style.display = "block";
  anim.style.display = "flex";
  requestAnimationFrame(() => anim.classList.add("show"));

  // 彩帶
  const ctx = confettiCanvas.getContext("2d");
  confettiCanvas.width = window.innerWidth;
  confettiCanvas.height = window.innerHeight;

  const pieces = Array.from({ length: 120 }).map(() => ({
    x: Math.random() * confettiCanvas.width,
    y: Math.random() * -confettiCanvas.height,
    r: Math.random() * 6 + 4,
    c: `hsl(${Math.random()*360},100%,60%)`,
    s: Math.random() * 2 + 4
  }));

  (function draw() {
    ctx.clearRect(0,0,confettiCanvas.width,confettiCanvas.height);
    pieces.forEach(p=>{
      ctx.beginPath();
      ctx.arc(p.x,p.y,p.r,0,Math.PI*2);
      ctx.fillStyle = p.c;
      ctx.fill();
      p.y += p.s;
      if (p.y > confettiCanvas.height) p.y = -10;
    });
    requestAnimationFrame(draw);
  })();

  // 自動結束動畫
  setTimeout(() => {

    overlay.style.transition = "opacity 1s";
    anim.style.transition = "opacity 1s";

    overlay.style.opacity = 0;
    anim.style.opacity = 0;

    // 安全 remove
    setTimeout(() => {
      overlay?.remove();
      anim?.remove();
      confettiCanvas?.remove();
    }, 1000);

  }, 2500);

});


</script>


</body>
</html>
