<?php
// =====================================================
// Single-file Telegram Bot + Web Verify + Supabase (PG)
// FLOW:
// /start -> Join channels msg + ✅ Verify (only)
// ✅ Verify -> checks join -> if ok sends web verify buttons
// ✅ Check Verification -> if verified => show full menu (no verify button)
// =====================================================

// ---------- ENV ----------
$BOT_TOKEN      = getenv("BOT_TOKEN");
$BASE_URL       = rtrim(getenv("BASE_URL"), "/");
$VERIFY_SECRET  = getenv("VERIFY_SECRET");
$BOT_USERNAME   = ltrim(getenv("BOT_USERNAME"), "@");
$ADMIN_IDS_RAW  = getenv("ADMIN_IDS") ?: "";
$FORCE_JOIN_RAW = getenv("FORCE_JOIN_CHANNELS") ?: "";

if (!$BOT_TOKEN || !$BASE_URL || !$VERIFY_SECRET || !$BOT_USERNAME) {
  http_response_code(500);
  echo "Missing env: BOT_TOKEN/BASE_URL/VERIFY_SECRET/BOT_USERNAME";
  exit;
}

// Admin IDs
$ADMIN_IDS = [];
foreach (explode(",", $ADMIN_IDS_RAW) as $x) {
  $x = trim($x);
  if (ctype_digit($x)) $ADMIN_IDS[(int)$x] = true;
}

// Required channels
$CHANNELS_REQUIRED = [];
foreach (explode(",", $FORCE_JOIN_RAW) as $c) {
  $c = trim($c);
  if ($c !== "") $CHANNELS_REQUIRED[] = $c;
}
if (count($CHANNELS_REQUIRED) === 0) {
  // fallback
  $CHANNELS_REQUIRED = ["@channel1","@channel2","@channel3","@channel4"];
}

// Points cost
$REDEEM_COST = ["500"=>3, "1000"=>10, "2000"=>20, "4000"=>40];
$REF_REWARD = 1;

// ---------- DB (Supabase Postgres) ----------
function db() {
  static $pdo = null;
  if ($pdo) return $pdo;

  $dsn = getenv("DATABASE_URL");
  if ($dsn) {
    $parts = parse_url($dsn);
    $user = $parts["user"] ?? "";
    $pass = $parts["pass"] ?? "";
    $host = $parts["host"] ?? "";
    $port = $parts["port"] ?? 5432;
    $dbn  = ltrim($parts["path"] ?? "", "/");
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbn", $user, $pass, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    return $pdo;
  }

  $host = getenv("DB_HOST");
  $port = getenv("DB_PORT") ?: 5432;
  $name = getenv("DB_NAME") ?: "postgres";
  $user = getenv("DB_USER");
  $pass = getenv("DB_PASS");
  if (!$host || !$user || !$pass) throw new Exception("DB env missing");
  $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$name", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
  ]);
  return $pdo;
}

// ---------- Telegram API ----------
function tg($method, $data) {
  global $BOT_TOKEN;
  $url = "https://api.telegram.org/bot{$BOT_TOKEN}/{$method}";
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_TIMEOUT => 20
  ]);
  $res = curl_exec($ch);
  curl_close($ch);
  return $res ? json_decode($res, true) : null;
}

function is_admin($tg_id) {
  global $ADMIN_IDS;
  return isset($ADMIN_IDS[(int)$tg_id]);
}

function hmac_sig($tg_id) {
  global $VERIFY_SECRET;
  return hash_hmac("sha256", (string)$tg_id, $VERIFY_SECRET);
}

function sig_ok($tg_id, $sig) {
  return hash_equals(hmac_sig($tg_id), (string)$sig);
}

// ---------- Users ----------
function ensure_user($tg_id, $username="", $first_name="") {
  $pdo = db();
  $st = $pdo->prepare("
    insert into public.users (tg_id, username, first_name)
    values (:tg_id, :u, :f)
    on conflict (tg_id) do update set
      last_seen = now(),
      username = case when public.users.username is null or public.users.username = '' then excluded.username else public.users.username end,
      first_name = case when public.users.first_name is null or public.users.first_name = '' then excluded.first_name else public.users.first_name end
    returning *
  ");
  $st->execute([":tg_id"=>$tg_id, ":u"=>$username, ":f"=>$first_name]);
  return $st->fetch(PDO::FETCH_ASSOC);
}

function get_user($tg_id) {
  $pdo = db();
  $st = $pdo->prepare("select * from public.users where tg_id=:id");
  $st->execute([":id"=>$tg_id]);
  return $st->fetch(PDO::FETCH_ASSOC);
}

// ---------- Force Join ----------
function check_joined_all($tg_id) {
  global $CHANNELS_REQUIRED;
  $not = [];
  foreach ($CHANNELS_REQUIRED as $ch) {
    $r = tg("getChatMember", ["chat_id"=>$ch, "user_id"=>$tg_id]);
    $status = $r["result"]["status"] ?? "left";
    if (!$r || !($r["ok"] ?? false) || $status === "left" || $status === "kicked") {
      $not[] = $ch;
    }
  }
  return $not; // empty = ok
}

// ---------- Coupon Stock ----------
function coupon_stock($ctype) {
  $pdo = db();
  $st = $pdo->prepare("select count(*) from public.coupons where ctype=:t and is_used=false");
  $st->execute([":t"=>$ctype]);
  return (int)$st->fetchColumn();
}

/**
 * Redeem transaction:
 * - must be verified
 * - must have points
 * - stock check FIRST (lock coupon row)
 * - if stock out => NO point cut
 */
function redeem_coupon($tg_id, $ctype) {
  global $REDEEM_COST;
  $cost = $REDEEM_COST[$ctype] ?? 999999;

  $pdo = db();
  $pdo->beginTransaction();
  try {
    // lock user
    $u = $pdo->prepare("select * from public.users where tg_id=:id for update");
    $u->execute([":id"=>$tg_id]);
    $user = $u->fetch(PDO::FETCH_ASSOC);
    if (!$user) { $pdo->rollBack(); return ["ok"=>false,"msg"=>"User not found"]; }

    if (!($user["verified"] ?? false)) {
      $pdo->rollBack();
      return ["ok"=>false,"msg"=>"Verify first"];
    }

    $points = (int)$user["points"];
    if ($points < $cost) {
      $pdo->rollBack();
      return ["ok"=>false,"msg"=>"Not enough points"];
    }

    // lock one coupon row
    $c = $pdo->prepare("
      select id, code from public.coupons
      where ctype=:t and is_used=false
      order by id asc
      for update skip locked
      limit 1
    ");
    $c->execute([":t"=>$ctype]);
    $coupon = $c->fetch(PDO::FETCH_ASSOC);

    if (!$coupon) {
      // STOCK OUT => no deduction
      $pdo->rollBack();
      return ["ok"=>false,"msg"=>"Stock out"];
    }

    // deduct points
    $upd = $pdo->prepare("update public.users set points = points - :cost, last_seen=now() where tg_id=:id");
    $upd->execute([":cost"=>$cost, ":id"=>$tg_id]);

    // mark coupon used
    $use = $pdo->prepare("update public.coupons set is_used=true, used_by=:id, used_at=now() where id=:cid");
    $use->execute([":id"=>$tg_id, ":cid"=>$coupon["id"]]);

    // withdrawals log (exact code)
    $w = $pdo->prepare("insert into public.withdrawals (tg_id, ctype, code) values (:id,:t,:code)");
    $w->execute([":id"=>$tg_id, ":t"=>$ctype, ":code"=>$coupon["code"]]);

    $pdo->commit();
    return ["ok"=>true,"code"=>$coupon["code"],"cost"=>$cost];
  } catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    return ["ok"=>false,"msg"=>"Redeem failed"];
  }
}

// ---------- Broadcast ----------
function broadcast_all($text) {
  $pdo = db();
  $st = $pdo->query("select tg_id from public.users");
  while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $uid = (int)$row["tg_id"];
    tg("sendMessage", ["chat_id"=>$uid, "text"=>$text, "parse_mode"=>"HTML"]);
    usleep(30000);
  }
}

// ---------- Admin state (file) ----------
$STATE_FILE = __DIR__ . "/state.json";

function state_load() {
  global $STATE_FILE;
  if (!file_exists($STATE_FILE)) return [];
  $j = json_decode(file_get_contents($STATE_FILE), true);
  return is_array($j) ? $j : [];
}
function state_save($s) {
  global $STATE_FILE;
  file_put_contents($STATE_FILE, json_encode($s, JSON_PRETTY_PRINT));
}
function set_admin_state($admin_id, $mode, $ctype=null) {
  $s = state_load();
  $s[(string)$admin_id] = ["mode"=>$mode, "ctype"=>$ctype, "ts"=>time()];
  state_save($s);
}
function get_admin_state($admin_id) {
  $s = state_load();
  return $s[(string)$admin_id] ?? null;
}
function clear_admin_state($admin_id) {
  $s = state_load();
  unset($s[(string)$admin_id]);
  state_save($s);
}

// ---------- UI ----------
function send_join_message($chat_id) {
  global $CHANNELS_REQUIRED;
  $text = "👋 <b>Welcome!</b>\n\n✅ Please join all channels below:\n\n";
  foreach ($CHANNELS_REQUIRED as $ch) $text .= "• {$ch}\n";
  $text .= "\nAfter joining, click ✅ Verify.";

  $kb = [
    "keyboard" => [
      [["text"=>"✅ Verify"]],
    ],
    "resize_keyboard" => true
  ];

  tg("sendMessage", [
    "chat_id" => $chat_id,
    "text" => $text,
    "parse_mode" => "HTML",
    "reply_markup" => $kb
  ]);
}

/**
 * MAIN MENU:
 * - If verified: show coupons/stats/ref link (+ admin panel)
 * - If NOT verified: do NOT show full menu, show only ✅ Verify
 */
function send_menu($chat_id) {
  $u = get_user($chat_id);
  $verified = ($u && ($u["verified"] ?? false));

  if (!$verified) {
    send_join_message($chat_id);
    return;
  }

  $keyboard = [
    [["text"=>"🎟️ Coupons"], ["text"=>"📊 Stats"]],
    [["text"=>"👥 Referral Link"]],
  ];
  if (is_admin($chat_id)) {
    $keyboard[] = [["text"=>"🛠 Admin Panel"]];
  }

  $kb = ["keyboard"=>$keyboard, "resize_keyboard"=>true];
  tg("sendMessage", [
    "chat_id"=>$chat_id,
    "text"=>"✅ <b>Verified!</b>\n\nWelcome to the bot menu 👇",
    "parse_mode"=>"HTML",
    "reply_markup"=>$kb
  ]);
}

function verify_buttons($tg_id) {
  global $BASE_URL;
  $sig = hmac_sig($tg_id);
  $url = $BASE_URL . "/verify?tg_id={$tg_id}&sig={$sig}";
  return [
    "inline_keyboard" => [
      [["text"=>"✅ Verify Now", "url"=>$url]],
      [["text"=>"✅ Check Verification", "callback_data"=>"check_verify"]],
    ]
  ];
}

function coupons_buttons() {
  return [
    "inline_keyboard" => [
      [
        ["text"=>"500 off 500", "callback_data"=>"redeem:500"],
        ["text"=>"1000 off 1000", "callback_data"=>"redeem:1000"]
      ],
      [
        ["text"=>"2000 off 2000", "callback_data"=>"redeem:2000"],
        ["text"=>"4000 off 4000", "callback_data"=>"redeem:4000"]
      ]
    ]
  ];
}

function admin_panel_buttons() {
  return [
    "inline_keyboard" => [
      [["text"=>"➕ Add Coupons", "callback_data"=>"admin:add"]],
      [["text"=>"📦 Stock", "callback_data"=>"admin:stock"]],
      [["text"=>"📜 Withdrawals Log", "callback_data"=>"admin:logs"]],
    ]
  ];
}
function admin_type_buttons() {
  return [
    "inline_keyboard" => [
      [["text"=>"500", "callback_data"=>"admin:addtype:500"]],
      [["text"=>"1000", "callback_data"=>"admin:addtype:1000"]],
      [["text"=>"2000", "callback_data"=>"admin:addtype:2000"]],
      [["text"=>"4000", "callback_data"=>"admin:addtype:4000"]],
    ]
  ];
}

// ---------- ROUTES ----------
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);

// WEB: home
if ($path === "/" && $_SERVER["REQUEST_METHOD"] === "GET") {
  echo "OK";
  exit;
}

// WEB: verify page
if ($path === "/verify" && $_SERVER["REQUEST_METHOD"] === "GET") {
  $tg_id = $_GET["tg_id"] ?? "";
  $sig   = $_GET["sig"] ?? "";
  if (!ctype_digit($tg_id) || !sig_ok((int)$tg_id, $sig)) {
    http_response_code(403);
    echo "Invalid verify link";
    exit;
  }

  $tg_safe = htmlspecialchars($tg_id, ENT_QUOTES);
  $sig_safe = htmlspecialchars($sig, ENT_QUOTES);
  $base = htmlspecialchars($GLOBALS["BASE_URL"], ENT_QUOTES);
  $botu = htmlspecialchars($GLOBALS["BOT_USERNAME"], ENT_QUOTES);

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
  const res = await fetch("{$base}/api/verify", {
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
  exit;
}

// WEB: verify API
if ($path === "/api/verify" && $_SERVER["REQUEST_METHOD"] === "POST") {
  $raw = file_get_contents("php://input");
  $p = json_decode($raw, true) ?: [];

  $tg_id = $p["tg_id"] ?? "";
  $sig = $p["sig"] ?? "";
  $device_id = trim((string)($p["device_id"] ?? ""));

  header("Content-Type: application/json");
  if (!ctype_digit((string)$tg_id) || $device_id === "" || !sig_ok((int)$tg_id, $sig)) {
    http_response_code(400);
    echo json_encode(["ok"=>false,"message"=>"Bad request"]);
    exit;
  }

  $tg_id_int = (int)$tg_id;
  ensure_user($tg_id_int);

  $pdo = db();
  try {
    $pdo->beginTransaction();

    // device already linked to another tg_id -> reject
    $st = $pdo->prepare("select tg_id from public.device_map where device_id=:d");
    $st->execute([":d"=>$device_id]);
    $existing = $st->fetchColumn();
    if ($existing && (int)$existing !== $tg_id_int) {
      $pdo->rollBack();
      http_response_code(403);
      echo json_encode(["ok"=>false,"message"=>"❌ This device is already linked with another Telegram account."]);
      exit;
    }

    // also enforce 1 tg_id -> 1 device (unique tg_id in device_map)
    // if tg already has a device, allow re-verify only if same device? we keep strict:
    $st2 = $pdo->prepare("select device_id from public.device_map where tg_id=:id");
    $st2->execute([":id"=>$tg_id_int]);
    $existingDeviceForUser = $st2->fetchColumn();
    if ($existingDeviceForUser && $existingDeviceForUser !== $device_id) {
      $pdo->rollBack();
      http_response_code(403);
      echo json_encode(["ok"=>false,"message"=>"❌ This Telegram account is already verified on another device."]);
      exit;
    }

    // upsert device map
    $ins = $pdo->prepare("
      insert into public.device_map (device_id, tg_id) values (:d,:id)
      on conflict (device_id) do update set tg_id = excluded.tg_id
    ");
    $ins->execute([":d"=>$device_id, ":id"=>$tg_id_int]);

    // mark verified
    $up = $pdo->prepare("update public.users set verified=true, last_seen=now() where tg_id=:id");
    $up->execute([":id"=>$tg_id_int]);

    $pdo->commit();
    echo json_encode(["ok"=>true,"message"=>"✅ Verified. Now go back to Telegram and tap “Check Verification”."]);
    exit;

  } catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(["ok"=>false,"message"=>"Server error"]);
    exit;
  }
}

// TELEGRAM WEBHOOK
$update = json_decode(file_get_contents("php://input"), true);
if (!$update) { echo "OK"; exit; }

// ----- CALLBACKS -----
if (isset($update["callback_query"])) {
  $cq = $update["callback_query"];
  $data = $cq["data"] ?? "";
  $chat_id = $cq["from"]["id"];
  $username = $cq["from"]["username"] ?? "";
  $first_name = $cq["from"]["first_name"] ?? "";

  ensure_user($chat_id, $username, $first_name);

  // ✅ Check Verification -> if verified show full menu (verify removed)
  if ($data === "check_verify") {
    $u = get_user($chat_id);
    $ok = ($u && ($u["verified"] ?? false));

    tg("answerCallbackQuery", [
      "callback_query_id"=>$cq["id"],
      "text"=>$ok ? "Verified ✅" : "Not verified yet",
      "show_alert"=>true
    ]);

    if ($ok) {
      // show menu without verify button
      send_menu($chat_id);
    }
    exit;
  }

  // redeem
  if (strpos($data, "redeem:") === 0) {
    $ctype = explode(":", $data, 2)[1];

    $res = redeem_coupon($chat_id, $ctype);
    if (!$res["ok"]) {
      $msg = $res["msg"];
      // stock out => no deduction
      if ($msg === "Stock out") {
        tg("answerCallbackQuery", ["callback_query_id"=>$cq["id"], "text"=>"Stock out", "show_alert"=>true]);
        tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ <b>{$ctype} off {$ctype}</b> coupon stock out.\nYour points were not deducted.", "parse_mode"=>"HTML"]);
      } else {
        tg("answerCallbackQuery", ["callback_query_id"=>$cq["id"], "text"=>$msg, "show_alert"=>true]);
      }
      exit;
    }

    tg("answerCallbackQuery", ["callback_query_id"=>$cq["id"], "text"=>"Success ✅", "show_alert"=>false]);
    tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"🎉 <b>Congratulations!</b>\n\nYour Coupon:\n<code>{$res["code"]}</code>", "parse_mode"=>"HTML"]);

    // admin notify
    global $ADMIN_IDS;
    foreach ($ADMIN_IDS as $aid => $_) {
      tg("sendMessage", ["chat_id"=>$aid, "text"=>"✅ Redeemed: {$ctype}\nUser: {$chat_id} (@".($username ?: "NA").")\nCode: {$res["code"]}"]);
    }
    exit;
  }

  // admin panel
  if (is_admin($chat_id) && strpos($data, "admin:") === 0) {
    if ($data === "admin:add") {
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"➕ Select coupon type:", "reply_markup"=>admin_type_buttons()]);
      tg("answerCallbackQuery", ["callback_query_id"=>$cq["id"]]);
      exit;
    }
    if (strpos($data, "admin:addtype:") === 0) {
      $ctype = explode(":", $data)[2];
      set_admin_state($chat_id, "await_codes", $ctype);
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"Send coupon codes for <b>{$ctype}</b> (1 per line).", "parse_mode"=>"HTML"]);
      tg("answerCallbackQuery", ["callback_query_id"=>$cq["id"]]);
      exit;
    }
    if ($data === "admin:stock") {
      $msg = "📦 <b>Stock</b>\n\n";
      foreach (["500","1000","2000","4000"] as $t) $msg .= "{$t}: <b>".coupon_stock($t)."</b>\n";
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>$msg, "parse_mode"=>"HTML"]);
      tg("answerCallbackQuery", ["callback_query_id"=>$cq["id"]]);
      exit;
    }
    if ($data === "admin:logs") {
      $pdo = db();
      $st = $pdo->query("select tg_id, ctype, code, created_at from public.withdrawals order by id desc limit 10");
      $rows = $st->fetchAll(PDO::FETCH_ASSOC);
      if (!$rows) {
        tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"📜 No withdrawals yet."]);
        tg("answerCallbackQuery", ["callback_query_id"=>$cq["id"]]);
        exit;
      }
      $msg = "📜 <b>Last Withdrawals</b>\n\n";
      foreach ($rows as $r) $msg .= "• ".$r["created_at"]." | ".$r["ctype"]." | ".$r["tg_id"]."\n";
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>$msg, "parse_mode"=>"HTML"]);
      tg("answerCallbackQuery", ["callback_query_id"=>$cq["id"]]);
      exit;
    }
  }

  tg("answerCallbackQuery", ["callback_query_id"=>$cq["id"]]);
  exit;
}

// ----- MESSAGES -----
$msg = $update["message"] ?? null;
if (!$msg) { echo "OK"; exit; }

$chat_id = $msg["from"]["id"];
$text = trim($msg["text"] ?? "");
$username = $msg["from"]["username"] ?? "";
$first_name = $msg["from"]["first_name"] ?? "";

ensure_user($chat_id, $username, $first_name);

// /start with referral
if (strpos($text, "/start") === 0) {
  $parts = explode(" ", $text);
  if (count($parts) > 1 && ctype_digit($parts[1])) {
    $ref_id = (int)$parts[1];
    if ($ref_id !== $chat_id) {
      $pdo = db();
      $pdo->beginTransaction();
      try {
        // lock current user
        $st = $pdo->prepare("select referrer_id from public.users where tg_id=:id for update");
        $st->execute([":id"=>$chat_id]);
        $cur = $st->fetchColumn();

        if (!$cur) {
          $up = $pdo->prepare("update public.users set referrer_id=:rid where tg_id=:id");
          $up->execute([":rid"=>$ref_id, ":id"=>$chat_id]);

          // reward referrer
          ensure_user($ref_id);
          $rw = $pdo->prepare("update public.users set points=points+1, refs=refs+1 where tg_id=:rid");
          $rw->execute([":rid"=>$ref_id]);

          tg("sendMessage", ["chat_id"=>$ref_id, "text"=>"✅ New referral joined!\n+1 point added."]);
        }
        $pdo->commit();
      } catch (Exception $e) {
        $pdo->rollBack();
      }
    }
  }

  // ✅ ALWAYS show join + verify first
  send_join_message($chat_id);
  echo "OK"; exit;
}

// Admin adding coupons (state)
if (is_admin($chat_id)) {
  $st = get_admin_state($chat_id);
  if ($st && ($st["mode"] ?? "") === "await_codes") {
    $ctype = $st["ctype"];

    $lines = preg_split("/\r\n|\n|\r/", $text);
    $codes = [];
    foreach ($lines as $l) {
      $l = trim($l);
      if ($l !== "") $codes[] = $l;
    }
    if (count($codes) === 0) {
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ No codes found. Send 1 per line."]);
      echo "OK"; exit;
    }

    $pdo = db();
    $added = 0;
    foreach ($codes as $code) {
      try {
        $ins = $pdo->prepare("insert into public.coupons (ctype, code) values (:t,:c) on conflict (code) do nothing");
        $ins->execute([":t"=>$ctype, ":c"=>$code]);
        $added += ($ins->rowCount() > 0) ? 1 : 0;
      } catch (Exception $e) {}
    }

    clear_admin_state($chat_id);

    $stock = coupon_stock($ctype);
    tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"✅ Added <b>{$added}</b> coupons to {$ctype}.\n📦 New Stock: <b>{$stock}</b>", "parse_mode"=>"HTML"]);

    // ✅ broadcast
    broadcast_all("📢 <b>New Coupon Added!</b>\n\n✅ {$ctype} off {$ctype} coupons are now available.\n📦 Stock: <b>{$stock}</b>");

    echo "OK"; exit;
  }
}

// ✅ Verify button in chat (reply keyboard)
if ($text === "✅ Verify") {
  $not = check_joined_all($chat_id);
  if (count($not) > 0) {
    $msgTxt = "❌ You must join all channels first:\n\n" . implode("\n", $not) . "\n\nThen click ✅ Verify again.";
    $kb = ["keyboard" => [[["text"=>"✅ Verify"]]], "resize_keyboard" => true];
    tg("sendMessage", ["chat_id"=>$chat_id, "text"=>$msgTxt, "reply_markup"=>$kb]);
    echo "OK"; exit;
  }

  // joined -> send web verify buttons
  tg("sendMessage", [
    "chat_id"=>$chat_id,
    "text"=>"✅ Joined all required channels.\n\nNow verify your device:",
    "reply_markup"=>verify_buttons($chat_id)
  ]);
  echo "OK"; exit;
}

// If verified, allow menu actions
$u = get_user($chat_id);
$verified = ($u && ($u["verified"] ?? false));

if ($verified && $text === "📊 Stats") {
  $points = (int)($u["points"] ?? 0);
  $refs = (int)($u["refs"] ?? 0);
  tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"📊 <b>Your Stats</b>\n\n👥 Referrals: <b>{$refs}</b>\n⭐ Points: <b>{$points}</b>\n🔒 Verified: <b>✅ Yes</b>", "parse_mode"=>"HTML"]);
  echo "OK"; exit;
}

if ($verified && $text === "👥 Referral Link") {
  global $BOT_USERNAME;
  $link = "https://t.me/{$BOT_USERNAME}?start={$chat_id}";
  tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"👥 <b>Your Referral Link</b>\n{$link}", "parse_mode"=>"HTML"]);
  echo "OK"; exit;
}

if ($verified && $text === "🎟️ Coupons") {
  $msgTxt = "🎟️ <b>Available Coupons</b>\n\n";
  foreach (["500","1000","2000","4000"] as $t) {
    $msgTxt .= "{$t} off {$t}: <b>".coupon_stock($t)."</b>\n";
  }
  $msgTxt .= "\nTap a button to redeem:";
  tg("sendMessage", ["chat_id"=>$chat_id, "text"=>$msgTxt, "parse_mode"=>"HTML", "reply_markup"=>coupons_buttons()]);
  echo "OK"; exit;
}

if ($verified && $text === "🛠 Admin Panel" && is_admin($chat_id)) {
  tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"🛠 <b>Admin Panel</b>", "parse_mode"=>"HTML", "reply_markup"=>admin_panel_buttons()]);
  echo "OK"; exit;
}

// Default: show correct menu depending on verified
send_menu($chat_id);
echo "OK";
