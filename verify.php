<?php
$tg_id = isset($_GET["tg_id"]) ? (int)$_GET["tg_id"] : 0;
$sig   = $_GET["sig"] ?? "";
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Verify</title>
  <style>
    body{font-family:system-ui,Arial;background:#0b1220;color:#fff;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
    .card{background:#121b2f;border:1px solid #223055;border-radius:16px;padding:18px;max-width:420px;width:92%}
    .btn{display:block;width:100%;padding:12px 14px;border-radius:12px;border:0;background:#2f6bff;color:#fff;font-weight:700;font-size:16px;cursor:pointer}
    .muted{opacity:.8;font-size:13px;margin-top:10px;line-height:1.4}
    .ok{color:#6ee7b7;font-weight:700;margin-top:10px}
    .err{color:#fb7185;font-weight:700;margin-top:10px}
  </style>
</head>
<body>
  <div class="card">
    <h2 style="margin:0 0 10px 0;">✅ Web Verification</h2>
    <p class="muted">Click verify. One device can verify only one Telegram user.</p>

    <button class="btn" id="vbtn">✅ Verify Now</button>
    <div id="msg"></div>

    <p class="muted">After success, go back to Telegram and click <b>✅ Check Verification</b>.</p>
  </div>

<script>
const tg_id = <?= json_encode($tg_id) ?>;
const sig   = <?= json_encode($sig) ?>;

document.getElementById("vbtn").addEventListener("click", async () => {
  const msg = document.getElementById("msg");
  msg.textContent = "Verifying...";
  msg.className = "muted";

  try{
    const r = await fetch("verify_api.php", {
      method: "POST",
      headers: {"Content-Type":"application/json"},
      body: JSON.stringify({tg_id, sig})
    });
    const j = await r.json();
    if(j.ok){
      msg.textContent = "✅ Verified! Now return to Telegram.";
      msg.className = "ok";
      // optional auto-open bot:
      if(j.bot_url){ setTimeout(()=>{ window.location.href = j.bot_url; }, 700); }
    }else{
      msg.textContent = "❌ " + (j.error || "Verification failed");
      msg.className = "err";
    }
  }catch(e){
    msg.textContent = "❌ Network error";
    msg.className = "err";
  }
});
</script>
</body>
</html>
