<?php
// =====================================================
// index.php (Telegram Webhook Bot) + Supabase (Postgres)
// Uses separate files: verify.php + verify_api.php
//
// FLOW:
// /start -> Join channels msg + ✅ Verify (only)
// ✅ Verify -> checks join -> sends web verify buttons (verify.php)
// ✅ Check Verification -> if verified => show full menu (NO verify button)
//
// ADMIN PANEL (ALL BUTTONS):
// - Add Coupons (type -> send codes)
// - Remove Coupons (type -> send codes to remove)
// - Stock
// - Withdrawals Log
// - Users Stats
// - Broadcast
// - Set Redeem Cost (points)
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
  // fallback (change in env)
  $CHANNELS_REQUIRED = ["@channel1","@channel2","@channel3","@channel4"];
}

// Default points costs (admin can change from panel)
$DEFAULT_REDEEM_COST = ["500"=>3, "1000"=>10, "2000"=>20, "4000"=>40];
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
    CURLOPT_TIMEOUT => 25
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
  file_put_contents($STATE_FILE, json_encode($s, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
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
  return $s["_config"] ?? ["redeem_cost" => []];
}
function save_config($cfg) {
  $s = state_load();
  $s["_config"] = $cfg;
  state_save($s);
}
function redeem_cost($ctype) {
  global $DEFAULT_REDEEM_COST;
  $cfg = get_config();
  $rc = $cfg["redeem_cost"] ?? [];
  if (isset($rc[$ctype]) && is_numeric($rc[$ctype])) return (int)$rc[$ctype];
  return (int)($DEFAULT_REDEEM_COST[$ctype] ?? 999999);
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
  $cost = redeem_cost($ctype);

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

    // withdrawals log
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

  tg("sendMessage", [
    "chat_id"=>$chat_id,
    "text"=>"✅ <b>Verified!</b>\n\nWelcome to the bot menu 👇",
    "parse_mode"=>"HTML",
    "reply_markup"=>["keyboard"=>$keyboard, "resize_keyboard"=>true]
  ]);
}

function verify_buttons($tg_id) {
  global $BASE_URL;
  $sig = hmac_sig($tg_id);
  $url = $BASE_URL . "/verify.php?tg_id={$tg_id}&sig={$sig}";
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
      [
        ["text"=>"➕ Add Coupons", "callback_data"=>"admin:add"],
        ["text"=>"➖ Remove Coupon", "callback_data"=>"admin:remove"]
      ],
      [
        ["text"=>"📦 Stock", "callback_data"=>"admin:stock"],
        ["text"=>"📜 Withdrawals", "callback_data"=>"admin:logs"]
      ],
      [
        ["text"=>"👥 Users Stats", "callback_data"=>"admin:users"],
        ["text"=>"📣 Broadcast", "callback_data"=>"admin:broadcast"]
      ],
      [
        ["text"=>"⚙️ Set Redeem Cost", "callback_data"=>"admin:setcost"]
      ]
    ]
  ];
}

function type_buttons($prefix) {
  // $prefix examples: admin:addtype:, admin:removetype:, admin:setcosttype:
  return [
    "inline_keyboard" => [
      [["text"=>"500",  "callback_data"=>$prefix."500"],  ["text"=>"1000", "callback_data"=>$prefix."1000"]],
      [["text"=>"2000", "callback_data"=>$prefix."2000"], ["text"=>"4000", "callback_data"=>$prefix."4000"]],
    ]
  ];
}

// ---------- Health check ----------
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($path === "/" && $_SERVER["REQUEST_METHOD"] === "GET") {
  echo "OK";
  exit;
}

// ---------- TELEGRAM WEBHOOK ----------
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

  // Check Verification
  if ($data === "check_verify") {
    $u = get_user($chat_id);
    $ok = ($u && ($u["verified"] ?? false));

    tg("answerCallbackQuery", [
      "callback_query_id"=>$cq["id"],
      "text"=>$ok ? "Verified ✅" : "Not verified yet",
      "show_alert"=>true
    ]);

    if ($ok) send_menu($chat_id);
    exit;
  }

  // Redeem
  if (strpos($data, "rede
