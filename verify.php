<?php
$BASE_URL = rtrim(getenv("BASE_URL"), "/");
$VERIFY_SECRET = getenv("VERIFY_SECRET");
$BOT_USERNAME = ltrim(getenv("BOT_USERNAME"), "@");

if (!$BASE_URL || !$VERIFY_SECRET || !$BOT_USERNAME) {
  http_response_code(500);
  echo "Missing env: BASE_URL/VERIFY_SECRET/BOT_USERNAME";
  exit;
}

function hmac_sig($tg_id) {
  global $VERIFY_SECRET;
  return hash_hmac("sha256", (string)$tg_id, $VERIFY_SECRET);
}
function sig_ok($tg_id, $sig) {
  return hash_equals(hmac_sig($tg_id), (string)$sig);
}

$tg_id = $_GET["tg_id"] ?? "";
$sig   = $_GET["sig"] ?? "";

if (!ctype_digit($tg_id) || !sig_ok((int)$tg_id, $sig)) {
  http_response_code(403);
  echo "Invalid verify link";
  exit;
}

$tg_safe = htmlspecialchars($tg_id, ENT_QUOTES);
$sig_safe = htmlspecialchars($sig, ENT_QUOTES);
$base = htmlspecialchars($BASE_URL, ENT_QUOTES);
$botu = htmlspecialchars($BOT_USERNAME, ENT_QUOTES);

header("Content-Type: text/html; charset=utf-8");
echo <<<HTML
<!doctype html>
<html>
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Verify</title>
<style>
body{font-family:Arial;background:#0b1220;color:#fff;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.card{width:min(520px,92vw);background:#131c2f;border-radius:16px;padding:18px;box-shadow:0 10px 30px rgba(0,0,0,.4)}
button{width:100%;padding:14px;border:0;border-radius:12px;background:#22c55e;color:#04120a;font-weight:700;font-size:16px}
.muted{opacity:.85;font-size:13px;line-height:1.4}
.box{background:#0f172a;border:1px solid rgba(255,255,255,.1);padding:10px;border-radius:12px;margin:12px 0}
</style>
</head>
<body>
<div class="card">
  <h2>✅ Verify Your Device</h2>
  <div class="box muted">1 device can verify only 1 Telegram account.</div>
  <button onclick="doVerify()">✅ Verify Now</button>
  <p id="msg" class="muted"></p>
</div>

<script>
function getDeviceId(){
  let id = localStorage.getItem("device_id");
  if(!id){
    id = "dev_" + Math.random().toString(16).slice(2) + "_" + Date.now();
    localStorage.setItem("device_id", id);
  }
  return id;
}
async function doVerify(){
  const device_id = getDeviceId();
  document.getElementById("msg").innerText = "Verifying...";
  const res = await fetch("{$base}/verify_api.php", {
    method:"POST",
    headers:{"Content-Type":"application/json"},
    body: JSON.stringify({tg_id:"{$tg_safe}", sig:"{$sig_safe}", device_id})
  });
  const data = await res.json();
  document.getElementById("msg").innerText = data.message || "Done";
  if(data.ok){
    setTimeout(()=>{ window.location.href="https://t.me/{$botu}"; }, 900);
  }
}
</script>
</body>
</html>
HTML;
