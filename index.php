<?php
// =====================================================
// index.php (Telegram Webhook Bot) + Supabase Postgres
// Web verify files: verify.php + verify_api.php
//
// Flow:
// /start -> join channels msg + ✅ Verify (reply keyboard)
// ✅ Verify -> check channels -> send web verify buttons (inline)
// ✅ Check Verification -> if verified => show full menu (NO verify button)
//
// FAST + Stockout safe:
// - Redeem checks stock first in txn + FOR UPDATE SKIP LOCKED
// - If stock out => NO points cut
//
// Admin panel (mm.py style buttons):
// - Add Coupons (500/1000/2000/4000)
// - Set Rate (points cost) per type
// - Add Points (user / all)
// - Set Referral Reward
// - Broadcast
// - Coupon Stats
// - User List / Top Balances
// - View Refs (by user)
// - User Info (by user)
// - Ban / Unban
// - Channels (view env list only) + (optional add/remove stored in config)
// - Tasks (placeholder)
// - Withdraw Settings (toggle + daily limit)
// - Referral Stats
// - Export Data (CSV as document)
// - Import Data (placeholder)
// - View Point Requests (placeholder)
// - Bot Status
// =====================================================

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

// ---------- Admin IDs ----------
$ADMIN_IDS = [];
foreach (explode(",", $ADMIN_IDS_RAW) as $x) {
  $x = trim($x);
  if (ctype_digit($x)) $ADMIN_IDS[(int)$x] = true;
}

// ---------- Required channels (default from env) ----------
$CHANNELS_REQUIRED = [];
foreach (explode(",", $FORCE_JOIN_RAW) as $c) {
  $c = trim($c);
  if ($c !== "") $CHANNELS_REQUIRED[] = $c;
}
if (count($CHANNELS_REQUIRED) === 0) {
  $CHANNELS_REQUIRED = ["@channel1","@channel2","@channel3","@channel4"];
}

// ---------- Defaults ----------
$DEFAULT_REDEEM_COST = ["500"=>3, "1000"=>10, "2000"=>20, "4000"=>40];
$DEFAULT_REF_REWARD = 1;
$DEFAULT_WITHDRAW_ON = true;
$DEFAULT_DAILY_LIMIT = 999999;

// ---------- DB ----------
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
  $token = $GLOBALS["BOT_TOKEN"];
  $url = "https://api.telegram.org/bot{$token}/{$method}";
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
    CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 15
  ]);
  $res = curl_exec($ch);
  curl_close($ch);
  return $res ? json_decode($res, true) : null;
}

// sendDocument multipart (for export)
function tg_send_document($chat_id, $file_path, $caption="") {
  $token = $GLOBALS["BOT_TOKEN"];
  $url = "https://api.telegram.org/bot{$token}/sendDocument";

  $post = [
    "chat_id" => $chat_id,
    "caption" => $caption,
    "document" => new CURLFile($file_path)
  ];

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => $post,
    CURLOPT_TIMEOUT => 30
  ]);
  $res = curl_exec($ch);
  curl_close($ch);
  return $res ? json_decode($res, true) : null;
}

function is_admin($tg_id) {
  return isset($GLOBALS["ADMIN_IDS"][(int)$tg_id]);
}

function hmac_sig($tg_id) {
  return hash_hmac("sha256", (string)$tg_id, $GLOBALS["VERIFY_SECRET"]);
}

// ---------- State / Config (file) ----------
$STATE_FILE = __DIR__ . "/state.json";

function state_load() {
  $f = $GLOBALS["STATE_FILE"];
  if (!file_exists($f)) return [];
  $j = json_decode(file_get_contents($f), true);
  return is_array($j) ? $j : [];
}
function state_save($s) {
  $f = $GLOBALS["STATE_FILE"];
  file_put_contents($f, json_encode($s, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
}
function set_admin_state($admin_id, $mode, $data=[]) {
  $s = state_load();
  $s[(string)$admin_id] = ["mode"=>$mode, "data"=>$data, "ts"=>time()];
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

function get_config() {
  $s = state_load();
  $cfg = $s["_config"] ?? [];
  if (!isset($cfg["redeem_cost"])) $cfg["redeem_cost"] = [];
  if (!isset($cfg["ref_reward"])) $cfg["ref_reward"] = $GLOBALS["DEFAULT_REF_REWARD"];
  if (!isset($cfg["withdraw_on"])) $cfg["withdraw_on"] = $GLOBALS["DEFAULT_WITHDRAW_ON"];
  if (!isset($cfg["daily_limit"])) $cfg["daily_limit"] = $GLOBALS["DEFAULT_DAILY_LIMIT"];
  return $cfg;
}
function save_config($cfg) {
  $s = state_load();
  $s["_config"] = $cfg;
  state_save($s);
}
function redeem_cost($ctype) {
  $cfg = get_config();
  $rc = $cfg["redeem_cost"] ?? [];
  if (isset($rc[$ctype]) && is_numeric($rc[$ctype])) return (int)$rc[$ctype];
  return (int)($GLOBALS["DEFAULT_REDEEM_COST"][$ctype] ?? 999999);
}
function ref_reward() {
  $cfg = get_config();
  return (int)($cfg["ref_reward"] ?? $GLOBALS["DEFAULT_REF_REWARD"]);
}
function withdraw_on() {
  $cfg = get_config();
  return (bool)($cfg["withdraw_on"] ?? $GLOBALS["DEFAULT_WITHDRAW_ON"]);
}
function daily_limit() {
  $cfg = get_config();
  return (int)($cfg["daily_limit"] ?? $GLOBALS["DEFAULT_DAILY_LIMIT"]);
}

// ---------- Users ----------
function ensure_user($tg_id, $username="", $first_name="") {
  $pdo = db();
  $st = $pdo->prepare("
    insert into public.users (tg_id, username, first_name)
    values (:tg_id, :u, :f)
    on conflict (tg_id) do update set
      last_seen = now(),
      username = excluded.username,
      first_name = excluded.first_name
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

function is_banned($tg_id) {
  $u = get_user($tg_id);
  return $u && ($u["banned"] ?? false);
}

// ---------- Force Join (ONLY on ✅ Verify) ----------
function check_joined_all($tg_id) {
  $not = [];
  foreach ($GLOBALS["CHANNELS_REQUIRED"] as $ch) {
    $r = tg("getChatMember", ["chat_id"=>$ch, "user_id"=>$tg_id]);
    $status = $r["result"]["status"] ?? "left";
    if (!$r || !($r["ok"] ?? false) || $status === "left" || $status === "kicked") {
      $not[] = $ch;
    }
  }
  return $not;
}

// ---------- Stock ----------
function stock_all() {
  $pdo = db();
  $st = $pdo->query("
    select ctype, count(*)::int as c
    from public.coupons
    where is_used=false
    group by ctype
  ");
  $out = ["500"=>0,"1000"=>0,"2000"=>0,"4000"=>0];
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $t = (string)$r["ctype"];
    $out[$t] = (int)$r["c"];
  }
  return $out;
}

function used_counts() {
  $pdo = db();
  $st = $pdo->query("
    select ctype, count(*)::int as c
    from public.coupons
    where is_used=true
    group by ctype
  ");
  $out = ["500"=>0,"1000"=>0,"2000"=>0,"4000"=>0];
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $t = (string)$r["ctype"];
    $out[$t] = (int)$r["c"];
  }
  return $out;
}

// ---------- Redeem (stock check first, daily limit, no cut on stockout) ----------
function redeem_coupon($tg_id, $ctype) {
  if (!withdraw_on()) return ["ok"=>false,"msg"=>"Withdraw is OFF (admin disabled)"];

  $cost = redeem_cost($ctype);
  $pdo = db();
  $pdo->beginTransaction();
  try {
    $u = $pdo->prepare("select * from public.users where tg_id=:id for update");
    $u->execute([":id"=>$tg_id]);
    $user = $u->fetch(PDO::FETCH_ASSOC);
    if (!$user) { $pdo->rollBack(); return ["ok"=>false,"msg"=>"User not found"]; }
    if (!($user["verified"] ?? false)) { $pdo->rollBack(); return ["ok"=>false,"msg"=>"Verify first"]; }
    if (($user["banned"] ?? false)) { $pdo->rollBack(); return ["ok"=>false,"msg"=>"You are banned"]; }

    // daily limit
    $limit = daily_limit();
    if ($limit > 0 && $limit < 999999) {
      $st = $pdo->prepare("select count(*) from public.withdrawals where tg_id=:id and created_at >= date_trunc('day', now())");
      $st->execute([":id"=>$tg_id]);
      $todayCount = (int)$st->fetchColumn();
      if ($todayCount >= $limit) { $pdo->rollBack(); return ["ok"=>false,"msg"=>"Daily limit reached"]; }
    }

    $points = (int)$user["points"];
    if ($points < $cost) { $pdo->rollBack(); return ["ok"=>false,"msg"=>"Not enough points"]; }

    $c = $pdo->prepare("
      select id, code from public.coupons
      where ctype=:t and is_used=false
      order by id asc
      for update skip locked
      limit 1
    ");
    $c->execute([":t"=>$ctype]);
    $coupon = $c->fetch(PDO::FETCH_ASSOC);
    if (!$coupon) { $pdo->rollBack(); return ["ok"=>false,"msg"=>"Stock out"]; }

    $pdo->prepare("update public.users set points=points-:c, last_seen=now() where tg_id=:id")
        ->execute([":c"=>$cost, ":id"=>$tg_id]);

    $pdo->prepare("update public.coupons set is_used=true, used_by=:id, used_at=now() where id=:cid")
        ->execute([":id"=>$tg_id, ":cid"=>$coupon["id"]]);

    $pdo->prepare("insert into public.withdrawals (tg_id, ctype, code) values (:id,:t,:code)")
        ->execute([":id"=>$tg_id, ":t"=>$ctype, ":code"=>$coupon["code"]]);

    $pdo->commit();
    return ["ok"=>true, "code"=>$coupon["code"], "cost"=>$cost];
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
    tg("sendMessage", ["chat_id"=>(int)$row["tg_id"], "text"=>$text, "parse_mode"=>"HTML"]);
    usleep(12000); // small delay (avoid flood)
  }
}

// ---------- UI ----------
function send_join_message($chat_id) {
  $text = "👋 <b>Welcome!</b>\n\n✅ Please join all channels below:\n\n";
  foreach ($GLOBALS["CHANNELS_REQUIRED"] as $ch) $text .= "• {$ch}\n";
  $text .= "\nAfter joining, click ✅ Verify.";

  tg("sendMessage", [
    "chat_id"=>$chat_id,
    "text"=>$text,
    "parse_mode"=>"HTML",
    "reply_markup"=>[
      "keyboard"=>[[["text"=>"✅ Verify"]]],
      "resize_keyboard"=>true
    ]
  ]);
}

function send_menu($chat_id, $user=null) {
  if (!$user) $user = get_user($chat_id);
  $verified = ($user && ($user["verified"] ?? false));

  if (!$verified) { send_join_message($chat_id); return; }
  if (($user["banned"] ?? false)) {
    tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"🚫 You are banned."]);
    return;
  }

  $keyboard = [
    [["text"=>"🎟️ Coupons"], ["text"=>"📊 Stats"]],
    [["text"=>"👥 Referral Link"]],
  ];
  if (is_admin($chat_id)) $keyboard[] = [["text"=>"🛠 Admin Panel"]];

  tg("sendMessage", [
    "chat_id"=>$chat_id,
    "text"=>"✅ <b>Verified!</b>\n\nWelcome to the bot menu 👇",
    "parse_mode"=>"HTML",
    "reply_markup"=>["keyboard"=>$keyboard, "resize_keyboard"=>true]
  ]);
}

function verify_buttons($tg_id) {
  $sig = hmac_sig($tg_id);
  $url = $GLOBALS["BASE_URL"] . "/verify.php?tg_id={$tg_id}&sig={$sig}";
  return [
    "inline_keyboard"=>[
      [["text"=>"✅ Verify Now", "url"=>$url]],
      [["text"=>"✅ Check Verification", "callback_data"=>"check_verify"]],
    ]
  ];
}

function coupons_buttons() {
  return [
    "inline_keyboard"=>[
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
  // EXACT mm.py button labels + callback_data
  return [
    "inline_keyboard"=>[
      [["text"=>"Add ₹500 Codes 📦", "callback_data"=>"admin_add_bb500"]],
      [["text"=>"Add ₹1000 Codes 📦", "callback_data"=>"admin_add_bb1000"]],
      [["text"=>"Add ₹2000 Codes 📦", "callback_data"=>"admin_add_bb2000"]],
      [["text"=>"Add ₹4000 Codes 📦", "callback_data"=>"admin_add_bb4000"]],

      [["text"=>"Set ₹500 Rate ⚙️", "callback_data"=>"admin_set_redeem500"]],
      [["text"=>"Set ₹1000 Rate ⚙️", "callback_data"=>"admin_set_redeem1000"]],
      [["text"=>"Set ₹2000 Rate ⚙️", "callback_data"=>"admin_set_redeem2000"]],
      [["text"=>"Set ₹4000 Rate ⚙️", "callback_data"=>"admin_set_redeem4000"]],

      [["text"=>"Add Points to User ⭐", "callback_data"=>"admin_points_user"]],
      [["text"=>"Add Points to All ⭐", "callback_data"=>"admin_points_all"]],
      [["text"=>"Set Referral Reward 🎁", "callback_data"=>"admin_set_ref_reward"]],
      [["text"=>"Broadcast Message 📢", "callback_data"=>"admin_broadcast"]],
      [["text"=>"Coupon Stats 📊", "callback_data"=>"admin_coupon_stats"]],
      [["text"=>"User List 📋", "callback_data"=>"admin_user_list"]],
      [["text"=>"Top Balances 💎", "callback_data"=>"admin_top_balances"]],
      [["text"=>"View Referrals 📈", "callback_data"=>"admin_view_refs"]],
      [["text"=>"User Full Info 📄", "callback_data"=>"admin_user_info"]],
      [["text"=>"Ban User 🚫", "callback_data"=>"admin_ban"]],
      [["text"=>"Unban User 🔑", "callback_data"=>"admin_unban"]],
      [["text"=>"Channels 📺", "callback_data"=>"admin_channels"]],
      [["text"=>"Tasks ✅", "callback_data"=>"admin_tasks"]],
      [["text"=>"Withdraw Settings 🎁", "callback_data"=>"admin_withdraw_settings"]],
      [["text"=>"Referral Stats 📊", "callback_data"=>"admin_ref_stats"]],
      [["text"=>"📤 Export Data", "callback_data"=>"admin_export"]],
      [["text"=>"📥 Import Data", "callback_data"=>"admin_import"]],
      [["text"=>"View Point Requests 📝", "callback_data"=>"admin_view_requests"]],
      [["text"=>"🤖 Bot Status", "callback_data"=>"admin_bot_status"]],
      [["text"=>"Back to Main 🔙", "callback_data"=>"back_main"]],
    ]
  ];
}

function back_admin_btn() {
  return ["inline_keyboard"=>[[["text"=>"⬅️ Back", "callback_data"=>"admin_back"]]]];
}

// ---------- Health check ----------
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($path === "/" && $_SERVER["REQUEST_METHOD"] === "GET") { echo "OK"; exit; }

// ---------- TELEGRAM WEBHOOK ----------
$update = json_decode(file_get_contents("php://input"), true);
if (!$update) { echo "OK"; exit; }

// =====================================================
// CALLBACK QUERIES
// =====================================================
if (isset($update["callback_query"])) {
  $cq = $update["callback_query"];
  $data = $cq["data"] ?? "";
  $chat_id = (int)$cq["from"]["id"];
  $username = $cq["from"]["username"] ?? "";
  $first_name = $cq["from"]["first_name"] ?? "";

  ensure_user($chat_id, $username, $first_name);

  // instant UI
  tg("answerCallbackQuery", ["callback_query_id"=>$cq["id"]]);

  // Check verification
  if ($data === "check_verify") {
    $u = get_user($chat_id);
    if ($u && ($u["verified"] ?? false)) {
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"✅ Verified successfully!"]);
      send_menu($chat_id, $u);
    } else {
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Not verified yet. Verify on website then tap Check Verification."]);
    }
    exit;
  }

  // Back to main menu (used in mm.py admin panel)
  if ($data === "back_main") {
    $u = get_user($chat_id);
    if ($u && ($u["verified"] ?? false)) send_menu($chat_id, $u);
    else tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Please verify first."]);
    exit;
  }

  // Redeem
  if (strpos($data, "redeem:") === 0) {
    $ctype = explode(":", $data, 2)[1];
    $res = redeem_coupon($chat_id, $ctype);

    if (!$res["ok"]) {
      if ($res["msg"] === "Stock out") {
        tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ <b>{$ctype} off {$ctype}</b> coupon stock out.\nYour points were not deducted.", "parse_mode"=>"HTML"]);
      } else {
        tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ ".$res["msg"]]);
      }
      exit;
    }

    tg("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"🎉 <b>Congratulations!</b>\n\nYour Coupon:\n<code>{$res["code"]}</code>",
      "parse_mode"=>"HTML"
    ]);

    foreach ($GLOBALS["ADMIN_IDS"] as $aid => $_) {
      tg("sendMessage", ["chat_id"=>$aid, "text"=>"✅ Redeemed {$ctype}\nUser: {$chat_id} (@".($username ?: "NA").")\nCode: {$res["code"]}"]);
    }
    exit;
  }

  // Admin Panel callbacks
  if (is_admin($chat_id)) {

    // --- mm.py callback aliases -> PHP handlers ---
    $alias = [
      "admin_add_bb500"      => "admin_add_coupon:500",
      "admin_add_bb1000"     => "admin_add_coupon:1000",
      "admin_add_bb2000"     => "admin_add_coupon:2000",
      "admin_add_bb4000"     => "admin_add_coupon:4000",
      "admin_set_redeem500"  => "admin_set_rate:500",
      "admin_set_redeem1000" => "admin_set_rate:1000",
      "admin_set_redeem2000" => "admin_set_rate:2000",
      "admin_set_redeem4000" => "admin_set_rate:4000",
      "admin_points_user"    => "admin_add_points_user",
      "admin_points_all"     => "admin_add_points_all",
    ];
    if (isset($alias[$data])) $data = $alias[$data];


    if ($data === "admin_back") {
      $cfg = get_config();
      $text =
        "🛠 <b>Admin Menu</b>\n\n".
        "₹500 Rate: <b>".redeem_cost("500")."</b> pts\n".
        "₹1000 Rate: <b>".redeem_cost("1000")."</b> pts\n".
        "₹2000 Rate: <b>".redeem_cost("2000")."</b> pts\n".
        "₹4000 Rate: <b>".redeem_cost("4000")."</b> pts\n".
        "Referral Reward: <b>".ref_reward()."</b> pt\n".
        "Withdraw: <b>".(withdraw_on() ? "ON" : "OFF")."</b>\n".
        "Daily Limit: <b>".daily_limit()."</b>\n";
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>$text, "parse_mode"=>"HTML", "reply_markup"=>admin_panel_buttons()]);
      exit;
    }

    // Add coupons (choose type)
    if (strpos($data, "admin_add_coupon:") === 0) {
      $ctype = explode(":", $data, 2)[1];
      set_admin_state($chat_id, "await_add_codes", ["ctype"=>$ctype]);
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"➕ Send coupon codes for <b>{$ctype}</b> (1 per line).", "parse_mode"=>"HTML", "reply_markup"=>back_admin_btn()]);
      exit;
    }

    // Set rate per type
    if (strpos($data, "admin_set_rate:") === 0) {
      $ctype = explode(":", $data, 2)[1];
      set_admin_state($chat_id, "await_set_cost_value", ["ctype"=>$ctype]);
      $current = redeem_cost($ctype);
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"💰 Current cost for {$ctype} is <b>{$current}</b> points.\nSend new points number (example: 3).", "parse_mode"=>"HTML", "reply_markup"=>back_admin_btn()]);
      exit;
    }

    if ($data === "admin_add_points_user") {
      set_admin_state($chat_id, "await_add_points_user", []);
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"⭐ Send: <b>USER_ID POINTS</b>\nExample: <code>123456789 5</code>", "parse_mode"=>"HTML", "reply_markup"=>back_admin_btn()]);
      exit;
    }

    if ($data === "admin_add_points_all") {
      set_admin_state($chat_id, "await_add_points_all", []);
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"🌟 Send points number to add to ALL users.\nExample: <code>1</code>", "parse_mode"=>"HTML", "reply_markup"=>back_admin_btn()]);
      exit;
    }

    if ($data === "admin_set_ref_reward") {
      set_admin_state($chat_id, "await_set_ref_reward", []);
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"🎉 Current referral reward: <b>".ref_reward()."</b>\nSend new referral reward points (number).", "parse_mode"=>"HTML", "reply_markup"=>back_admin_btn()]);
      exit;
    }

    if ($data === "admin_broadcast") {
      set_admin_state($chat_id, "await_broadcast", []);
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"📣 Send broadcast message text now (goes to all users).", "reply_markup"=>back_admin_btn()]);
      exit;
    }

    if ($data === "admin_coupon_stats") {
      $s = stock_all();
      $used = used_counts();
      $pdo = db();
      $w = (int)$pdo->query("select count(*) from public.withdrawals")->fetchColumn();

      $msg = "📦 <b>Coupon Stats</b>\n\n".
        "Available:\n".
        "• 500: <b>{$s["500"]}</b>\n".
        "• 1000: <b>{$s["1000"]}</b>\n".
        "• 2000: <b>{$s["2000"]}</b>\n".
        "• 4000: <b>{$s["4000"]}</b>\n\n".
        "Used:\n".
        "• 500: <b>{$used["500"]}</b>\n".
        "• 1000: <b>{$used["1000"]}</b>\n".
        "• 2000: <b>{$used["2000"]}</b>\n".
        "• 4000: <b>{$used["4000"]}</b>\n\n".
        "Total Withdrawals: <b>{$w}</b>";
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>$msg, "parse_mode"=>"HTML", "reply_markup"=>back_admin_btn()]);
      exit;
    }

    if ($data === "admin_user_list") {
      $pdo = db();
      $st = $pdo->query("select tg_id, username, points, refs, verified from public.users order by points desc nulls last limit 50");
      $rows = $st->fetchAll(PDO::FETCH_ASSOC);
      if (!$rows) {
        tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"No users.", "reply_markup"=>back_admin_btn()]);
        exit;
      }
      $txt = "📋 <b>User List (Top 50 by points)</b>\n\n";
      foreach ($rows as $r) {
        $txt .= $r["tg_id"]." @".($r["username"] ?: "NA")." | ".$r["points"]." pts | refs ".$r["refs"]." | ".(($r["verified"])? "✅":"❌")."\n";
        if (strlen($txt) > 3500) { $txt .= "\n... truncated"; break; }
      }
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>$txt, "parse_mode"=>"HTML", "reply_markup"=>back_admin_btn()]);
      exit;
    }

    if ($data === "admin_top_balances") {
      $pdo = db();
      $st = $pdo->query("select tg_id, username, points from public.users order by points desc nulls last limit 10");
      $rows = $st->fetchAll(PDO::FETCH_ASSOC);
      $txt = "💎 <b>Top Balances</b>\n\n";
      $i=1;
      foreach ($rows as $r) {
        $txt .= "{$i}. ".$r["tg_id"]." @".($r["username"] ?: "NA")." - <b>".$r["points"]."</b> pts\n";
        $i++;
      }
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>$txt, "parse_mode"=>"HTML", "reply_markup"=>back_admin_btn()]);
      exit;
    }

    if ($data === "admin_view_refs") {
      set_admin_state($chat_id, "await_view_refs", []);
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"📈 Send user id to view refs list.", "reply_markup"=>back_admin_btn()]);
      exit;
    }

    if ($data === "admin_user_info") {
      set_admin_state($chat_id, "await_user_info", []);
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"📄 Send user id for full info.", "reply_markup"=>back_admin_btn()]);
      exit;
    }

    if ($data === "admin_ban") {
      set_admin_state($chat_id, "await_ban", []);
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"🚫 Send user id to ban.", "reply_markup"=>back_admin_btn()]);
      exit;
    }

    if ($data === "admin_unban") {
      set_admin_state($chat_id, "await_unban", []);
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"🔑 Send user id to unban.", "reply_markup"=>back_admin_btn()]);
      exit;
    }

    if ($data === "admin_channels") {
      $txt = "📣 <b>Required Channels (from env FORCE_JOIN_CHANNELS)</b>\n\n";
      foreach ($GLOBALS["CHANNELS_REQUIRED"] as $ch) $txt .= "• {$ch}\n";
      $txt .= "\n(Editing channels from bot is not enabled in this PHP version.)";
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>$txt, "parse_mode"=>"HTML", "reply_markup"=>back_admin_btn()]);
      exit;
    }

    if ($data === "admin_tasks") {
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"🧩 Tasks: (placeholder)\nIf you want same tasks system as mm.py, tell me what exactly tasks should do in PHP.", "reply_markup"=>back_admin_btn()]);
      exit;
    }

    if ($data === "admin_withdraw_settings") {
      $cfg = get_config();
      $txt = "⚙️ <b>Withdraw Settings</b>\n\n".
        "Withdraw: <b>".(withdraw_on() ? "ON":"OFF")."</b>\n".
        "Daily Limit: <b>".daily_limit()."</b>\n\n".
        "Use buttons:";
      $kb = [
        "inline_keyboard"=>[
          [["text" => (withdraw_on() ? "Turn OFF 🔴" : "Turn ON 🟢"), "callback_data"=>"admin_toggle_withdraw"]],
          [["text" => "Set Daily Limit 📅", "callback_data"=>"admin_set_daily_limit"]],
          [["text" => "⬅️ Back", "callback_data"=>"admin_back"]]
        ]
      ];
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>$txt, "parse_mode"=>"HTML", "reply_markup"=>$kb]);
      exit;
    }

    if ($data === "admin_toggle_withdraw") {
      $cfg = get_config();
      $cfg["withdraw_on"] = !withdraw_on();
      save_config($cfg);
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"✅ Withdraw is now: ".(withdraw_on() ? "ON":"OFF"), "reply_markup"=>back_admin_btn()]);
      exit;
    }

    if ($data === "admin_set_daily_limit") {
      set_admin_state($chat_id, "await_set_daily_limit", []);
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"📅 Send new daily limit number (example: 5). Use 999999 for unlimited.", "reply_markup"=>back_admin_btn()]);
      exit;
    }

    if ($data === "admin_ref_stats") {
      $pdo = db();
      $total = (int)$pdo->query("select count(*) from public.users")->fetchColumn();
      $verified = (int)$pdo->query("select count(*) from public.users where verified=true")->fetchColumn();
      $refTotal = (int)$pdo->query("select count(*) from public.referrals")->fetchColumn();
      $refVerified = (int)$pdo->query("select count(*) from public.referrals r join public.users u on u.tg_id=r.referred_id where u.verified=true")->fetchColumn();

      $txt = "📊 <b>Referral Stats</b>\n\n".
        "Users: <b>{$total}</b>\n".
        "Verified Users: <b>{$verified}</b>\n".
        "Total Referrals: <b>{$refTotal}</b>\n".
        "Verified Referrals: <b>{$refVerified}</b>";
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>$txt, "parse_mode"=>"HTML", "reply_markup"=>back_admin_btn()]);
      exit;
    }

    if ($data === "admin_export") {
      $pdo = db();
      $tmp = sys_get_temp_dir()."/export_".time().".csv";
      $fp = fopen($tmp, "w");
      fputcsv($fp, ["tg_id","username","first_name","points","refs","verified","banned","created_at","last_seen"]);
      $st = $pdo->query("select tg_id, username, first_name, points, refs, verified, banned, created_at, last_seen from public.users order by tg_id asc");
      while ($r = $st->fetch(PDO::FETCH_ASSOC)) fputcsv($fp, $r);
      fclose($fp);

      tg_send_document($chat_id, $tmp, "📤 Export users.csv");
      @unlink($tmp);
      exit;
    }

    if ($data === "admin_import") {
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"📥 Import: (placeholder)\nIf you want real import via file upload, tell me format (CSV?) and I’ll add it.", "reply_markup"=>back_admin_btn()]);
      exit;
    }

    if ($data === "admin_view_requests") {
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"📝 Point Requests: (placeholder)\nIf you want point request system, I can add a table + user button.", "reply_markup"=>back_admin_btn()]);
      exit;
    }

    if ($data === "admin_bot_status") {
      // quick status
      $pdo = db();
      $users = (int)$pdo->query("select count(*) from public.users")->fetchColumn();
      $coupons = (int)$pdo->query("select count(*) from public.coupons")->fetchColumn();
      $w = (int)$pdo->query("select count(*) from public.withdrawals")->fetchColumn();
      $txt = "🤖 <b>Bot Status</b>\n\n".
        "DB: <b>OK</b>\n".
        "Users: <b>{$users}</b>\n".
        "Coupons total: <b>{$coupons}</b>\n".
        "Withdrawals: <b>{$w}</b>\n";
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>$txt, "parse_mode"=>"HTML", "reply_markup"=>back_admin_btn()]);
      exit;
    }
  }

  exit;
}

// =====================================================
// MESSAGES
// =====================================================
$msg = $update["message"] ?? null;
if (!$msg) { echo "OK"; exit; }

$chat_id = (int)$msg["from"]["id"];
$text = trim($msg["text"] ?? "");
$username = $msg["from"]["username"] ?? "";
$first_name = $msg["from"]["first_name"] ?? "";

ensure_user($chat_id, $username, $first_name);

if (is_banned($chat_id) && !is_admin($chat_id)) {
  tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"🚫 You are banned."]);
  echo "OK"; exit;
}

// /start referral
if (strpos($text, "/start") === 0) {
  $parts = explode(" ", $text);

  // referral param: /start <refid>
  if (count($parts) > 1 && ctype_digit($parts[1])) {
    $ref_id = (int)$parts[1];
    if ($ref_id !== $chat_id) {
      $pdo = db();
      $pdo->beginTransaction();
      try {
        $st = $pdo->prepare("select referrer_id from public.users where tg_id=:id for update");
        $st->execute([":id"=>$chat_id]);
        $cur = $st->fetchColumn();

        if (!$cur) {
          $pdo->prepare("update public.users set referrer_id=:rid where tg_id=:id")->execute([":rid"=>$ref_id, ":id"=>$chat_id]);

          // log referral
          $pdo->prepare("insert into public.referrals (referrer_id, referred_id) values (:r,:u) on conflict do nothing")
              ->execute([":r"=>$ref_id, ":u"=>$chat_id]);

          // add points to referrer
          $reward = ref_reward();
          $pdo->prepare("update public.users set points=points+:p, refs=refs+1 where tg_id=:rid")
              ->execute([":p"=>$reward, ":rid"=>$ref_id]);

          tg("sendMessage", ["chat_id"=>$ref_id, "text"=>"✅ New referral joined!\n+{$reward} point added."]);
        }
        $pdo->commit();
      } catch (Exception $e) {
        $pdo->rollBack();
      }
    }
  }

  send_join_message($chat_id);
  echo "OK"; exit;
}

// Admin state handling
if (is_admin($chat_id)) {
  $st = get_admin_state($chat_id);
  if ($st) {
    $mode = $st["mode"] ?? "";
    $data = $st["data"] ?? [];
    $pdo = db();

    if ($mode === "await_add_codes") {
      $ctype = $data["ctype"] ?? "";
      $lines = preg_split("/\r\n|\n|\r/", $text);
      $codes = [];
      foreach ($lines as $l) { $l = trim($l); if ($l !== "") $codes[] = $l; }

      if (!$ctype || count($codes) === 0) {
        tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Send codes (1 per line)."]);
        echo "OK"; exit;
      }

      $added = 0;
      foreach ($codes as $code) {
        $ins = $pdo->prepare("insert into public.coupons (ctype, code) values (:t,:c) on conflict (code) do nothing");
        $ins->execute([":t"=>$ctype, ":c"=>$code]);
        $added += ($ins->rowCount() > 0) ? 1 : 0;
      }

      clear_admin_state($chat_id);
      $s = stock_all();

      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"✅ Added <b>{$added}</b> coupons to {$ctype}.\n📦 New Stock: <b>{$s[$ctype]}</b>", "parse_mode"=>"HTML"]);

      // notify users (as you wanted)
      broadcast_all("📢 <b>New Coupon Added!</b>\n\n✅ {$ctype} off {$ctype} coupons are now available.\n📦 Stock: <b>{$s[$ctype]}</b>");
      echo "OK"; exit;
    }

    if ($mode === "await_set_cost_value") {
      $ctype = $data["ctype"] ?? "";
      if (!$ctype || !ctype_digit($text)) {
        tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Send only number (example: 3)."]);
        echo "OK"; exit;
      }
      $cfg = get_config();
      $cfg["redeem_cost"][$ctype] = (int)$text;
      save_config($cfg);
      clear_admin_state($chat_id);

      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"✅ Rate updated: {$ctype} => {$text} points"]);
      echo "OK"; exit;
    }

    if ($mode === "await_add_points_user") {
      $parts = preg_split('/\s+/', trim($text));
      if (count($parts) < 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
        tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Format: USER_ID POINTS\nExample: 123456789 5"]);
        echo "OK"; exit;
      }
      $uid = (int)$parts[0];
      $pts = (int)$parts[1];

      $pdo->prepare("insert into public.users (tg_id) values (:id) on conflict (tg_id) do nothing")->execute([":id"=>$uid]);
      $pdo->prepare("update public.users set points=points+:p where tg_id=:id")->execute([":p"=>$pts, ":id"=>$uid]);

      clear_admin_state($chat_id);
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"✅ Added {$pts} points to {$uid}"]);
      tg("sendMessage", ["chat_id"=>$uid, "text"=>"🎁 Admin added <b>{$pts}</b> points to your account.", "parse_mode"=>"HTML"]);
      echo "OK"; exit;
    }

    if ($mode === "await_add_points_all") {
      if (!ctype_digit($text)) {
        tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Send only number. Example: 1"]);
        echo "OK"; exit;
      }
      $pts = (int)$text;
      $pdo->prepare("update public.users set points=points+:p")->execute([":p"=>$pts]);

      clear_admin_state($chat_id);
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"✅ Added {$pts} points to ALL users"]);
      broadcast_all("🎁 <b>Bonus!</b>\nAdmin added <b>{$pts}</b> points to everyone.");
      echo "OK"; exit;
    }

    if ($mode === "await_set_ref_reward") {
      if (!ctype_digit($text)) {
        tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Send only number. Example: 1"]);
        echo "OK"; exit;
      }
      $cfg = get_config();
      $cfg["ref_reward"] = (int)$text;
      save_config($cfg);
      clear_admin_state($chat_id);
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"✅ Referral reward updated to {$text}"]);
      echo "OK"; exit;
    }

    if ($mode === "await_broadcast") {
      clear_admin_state($chat_id);
      if ($text === "") {
        tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Empty broadcast canceled."]);
        echo "OK"; exit;
      }
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"✅ Sending broadcast..."]);
      broadcast_all("📣 <b>Admin Message</b>\n\n".htmlspecialchars($text, ENT_QUOTES));
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"✅ Broadcast done."]);
      echo "OK"; exit;
    }

    if ($mode === "await_set_daily_limit") {
      if (!ctype_digit($text)) {
        tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Send only number. Example: 5"]);
        echo "OK"; exit;
      }
      $cfg = get_config();
      $cfg["daily_limit"] = (int)$text;
      save_config($cfg);
      clear_admin_state($chat_id);
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"✅ Daily limit updated to {$text}"]);
      echo "OK"; exit;
    }

    if ($mode === "await_user_info") {
      if (!ctype_digit($text)) { tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Send user id only."]); echo "OK"; exit; }
      $uid = (int)$text;
      $u = get_user($uid);
      clear_admin_state($chat_id);
      if (!$u) { tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"User not found."]); echo "OK"; exit; }
      $txt = "📄 <b>User Info</b>\n\n".
        "ID: <b>{$u["tg_id"]}</b>\n".
        "Username: @".($u["username"] ?: "NA")."\n".
        "Name: ".($u["first_name"] ?: "NA")."\n".
        "Points: <b>".((int)$u["points"])."</b>\n".
        "Refs: <b>".((int)$u["refs"])."</b>\n".
        "Verified: <b>".(($u["verified"])? "✅":"❌")."</b>\n".
        "Banned: <b>".(($u["banned"])? "✅":"❌")."</b>\n";
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>$txt, "parse_mode"=>"HTML"]);
      echo "OK"; exit;
    }

    if ($mode === "await_view_refs") {
      if (!ctype_digit($text)) { tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Send user id only."]); echo "OK"; exit; }
      $uid = (int)$text;
      $st2 = $pdo->prepare("select referred_id, created_at from public.referrals where referrer_id=:id order by created_at desc limit 100");
      $st2->execute([":id"=>$uid]);
      $rows = $st2->fetchAll(PDO::FETCH_ASSOC);
      clear_admin_state($chat_id);
      if (!$rows) { tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"No referrals for {$uid}."]); echo "OK"; exit; }
      $txt = "📈 <b>Referrals for {$uid}</b>\n\n";
      foreach ($rows as $r) {
        $txt .= $r["referred_id"]." | ".$r["created_at"]."\n";
        if (strlen($txt) > 3500) { $txt .= "\n... truncated"; break; }
      }
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>$txt, "parse_mode"=>"HTML"]);
      echo "OK"; exit;
    }

    if ($mode === "await_ban" || $mode === "await_unban") {
      if (!ctype_digit($text)) { tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Send user id only."]); echo "OK"; exit; }
      $uid = (int)$text;
      $ban = ($mode === "await_ban");
      $pdo->prepare("insert into public.users (tg_id) values (:id) on conflict (tg_id) do nothing")->execute([":id"=>$uid]);
      $pdo->prepare("update public.users set banned=:b where tg_id=:id")->execute([":b"=>$ban, ":id"=>$uid]);
      clear_admin_state($chat_id);

      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"✅ ".($ban ? "Banned" : "Unbanned")." {$uid}"]);
      tg("sendMessage", ["chat_id"=>$uid, "text"=> $ban ? "🚫 You have been banned by admin." : "✅ You have been unbanned by admin."]);
      echo "OK"; exit;
    }
  }
}

// ✅ Verify button (reply keyboard) only for not-verified users
if ($text === "✅ Verify") {
  $not = check_joined_all($chat_id);
  if (count($not) > 0) {
    $msgTxt = "❌ Join all channels first:\n\n" . implode("\n", $not) . "\n\nThen click ✅ Verify again.";
    tg("sendMessage", ["chat_id"=>$chat_id, "text"=>$msgTxt]);
    echo "OK"; exit;
  }

  tg("sendMessage", [
    "chat_id"=>$chat_id,
    "text"=>"✅ Joined all required channels.\n\nNow verify your device:",
    "reply_markup"=>verify_buttons($chat_id)
  ]);
  echo "OK"; exit;
}

// Verified-only menu actions
$u = get_user($chat_id);
$verified = ($u && ($u["verified"] ?? false));

if ($verified && $text === "📊 Stats") {
  $points = (int)($u["points"] ?? 0);
  $refs = (int)($u["refs"] ?? 0);
  tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"📊 <b>Your Stats</b>\n\n👥 Referrals: <b>{$refs}</b>\n⭐ Points: <b>{$points}</b>\n🔒 Verified: <b>✅ Yes</b>", "parse_mode"=>"HTML"]);
  echo "OK"; exit;
}

if ($verified && $text === "👥 Referral Link") {
  $link = "https://t.me/{$GLOBALS["BOT_USERNAME"]}?start={$chat_id}";
  tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"👥 <b>Your Referral Link</b>\n{$link}", "parse_mode"=>"HTML"]);
  echo "OK"; exit;
}

if ($verified && $text === "🎟️ Coupons") {
  $s = stock_all();
  $msgTxt = "🎟️ <b>Available Coupons</b>\n\n";
  $msgTxt .= "500 off 500: <b>{$s["500"]}</b>\n";
  $msgTxt .= "1000 off 1000: <b>{$s["1000"]}</b>\n";
  $msgTxt .= "2000 off 2000: <b>{$s["2000"]}</b>\n";
  $msgTxt .= "4000 off 4000: <b>{$s["4000"]}</b>\n";
  $msgTxt .= "\nTap a button to redeem:";
  tg("sendMessage", ["chat_id"=>$chat_id, "text"=>$msgTxt, "parse_mode"=>"HTML", "reply_markup"=>coupons_buttons()]);
  echo "OK"; exit;
}

if ($verified && $text === "🛠 Admin Panel" && is_admin($chat_id)) {
  $text2 =
    "🛠 <b>Admin Menu</b>\n\n".
    "₹500 Rate: <b>".redeem_cost("500")."</b> pts\n".
    "₹1000 Rate: <b>".redeem_cost("1000")."</b> pts\n".
    "₹2000 Rate: <b>".redeem_cost("2000")."</b> pts\n".
    "₹4000 Rate: <b>".redeem_cost("4000")."</b> pts\n".
    "Referral Reward: <b>".ref_reward()."</b> pt\n".
    "Withdraw: <b>".(withdraw_on() ? "ON" : "OFF")."</b>\n".
    "Daily Limit: <b>".daily_limit()."</b>\n";
  tg("sendMessage", ["chat_id"=>$chat_id, "text"=>$text2, "parse_mode"=>"HTML", "reply_markup"=>admin_panel_buttons()]);
  echo "OK"; exit;
}

// Default
send_menu($chat_id, $u);
echo "OK";
