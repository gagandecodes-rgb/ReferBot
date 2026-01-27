<?php
// =====================================================
// FAST index.php (Telegram Webhook Bot) + Supabase Postgres
// Web verify files: verify.php + verify_api.php
//
// /start -> join channels msg + ✅ Verify (only)
// ✅ Verify -> check channels -> send web verify buttons
// ✅ Check Verification -> if verified => show full menu (no verify button)
//
// ADMIN BUTTONS:
// ➕ Add Coupons (type -> paste codes)
// ➖ Remove Coupons (type -> paste codes)
// 📦 Stock
// 📜 Withdrawals Log
// 👥 Users Stats
// 📣 Broadcast
// ⚙️ Set Redeem Cost (points)
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

// ---------- Required channels ----------
$CHANNELS_REQUIRED = [];
foreach (explode(",", $FORCE_JOIN_RAW) as $c) {
  $c = trim($c);
  if ($c !== "") $CHANNELS_REQUIRED[] = $c;
}
if (count($CHANNELS_REQUIRED) === 0) {
  $CHANNELS_REQUIRED = ["@channel1","@channel2","@channel3","@channel4"];
}

// ---------- Default redeem cost (admin can change) ----------
$DEFAULT_REDEEM_COST = ["500"=>3, "1000"=>10, "2000"=>20, "4000"=>40];

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
    CURLOPT_TIMEOUT => 20
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
  return $s["_config"] ?? ["redeem_cost"=>[]];
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

// ---------- Force Join (only on ✅ Verify) ----------
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

// ---------- Stock (FAST: one query) ----------
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

// ---------- Redeem (stock check first, no cut on stockout) ----------
function redeem_coupon($tg_id, $ctype) {
  $cost = redeem_cost($ctype);
  $pdo = db();
  $pdo->beginTransaction();
  try {
    $u = $pdo->prepare("select * from public.users where tg_id=:id for update");
    $u->execute([":id"=>$tg_id]);
    $user = $u->fetch(PDO::FETCH_ASSOC);
    if (!$user) { $pdo->rollBack(); return ["ok"=>false,"msg"=>"User not found"]; }
    if (!($user["verified"] ?? false)) { $pdo->rollBack(); return ["ok"=>false,"msg"=>"Verify first"]; }

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

// ---------- Broadcast (still heavy, but fast loop) ----------
function broadcast_all($text) {
  $pdo = db();
  $st = $pdo->query("select tg_id from public.users");
  while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    tg("sendMessage", ["chat_id"=>(int)$row["tg_id"], "text"=>$text, "parse_mode"=>"HTML"]);
    // keep small delay to avoid flood-limit
    usleep(15000);
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
  return [
    "inline_keyboard"=>[
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
  return [
    "inline_keyboard"=>[
      [["text"=>"500", "callback_data"=>$prefix."500"], ["text"=>"1000", "callback_data"=>$prefix."1000"]],
      [["text"=>"2000", "callback_data"=>$prefix."2000"], ["text"=>"4000", "callback_data"=>$prefix."4000"]],
    ]
  ];
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

  $user = ensure_user($chat_id, $username, $first_name);

  // Always make UI feel instant
  tg("answerCallbackQuery", ["callback_query_id"=>$cq["id"]]);

  // ✅ Check Verification
  if ($data === "check_verify") {
    $u = get_user($chat_id);
    if ($u && ($u["verified"] ?? false)) {
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"✅ Verified successfully!"]);
      send_menu($chat_id, $u);
    } else {
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Not verified yet. Please verify on website then tap Check Verification."]);
    }
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

  // Admin Panel actions
  if (is_admin($chat_id) && strpos($data, "admin:") === 0) {
    if ($data === "admin:add") {
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"➕ Select type to ADD:", "reply_markup"=>type_buttons("admin:addtype:")]);
      exit;
    }
    if ($data === "admin:remove") {
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"➖ Select type to REMOVE:", "reply_markup"=>type_buttons("admin:removetype:")]);
      exit;
    }
    if ($data === "admin:stock") {
      $s = stock_all();
      $msg = "📦 <b>Stock</b>\n\n500: <b>{$s["500"]}</b>\n1000: <b>{$s["1000"]}</b>\n2000: <b>{$s["2000"]}</b>\n4000: <b>{$s["4000"]}</b>";
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>$msg, "parse_mode"=>"HTML"]);
      exit;
    }
    if ($data === "admin:logs") {
      $pdo = db();
      $st = $pdo->query("select tg_id, ctype, code, created_at from public.withdrawals order by id desc limit 10");
      $rows = $st->fetchAll(PDO::FETCH_ASSOC);
      if (!$rows) { tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"📜 No withdrawals yet."]); exit; }

      $msg = "📜 <b>Last 10 Withdrawals</b>\n\n";
      foreach ($rows as $r) {
        $msg .= "• ".$r["created_at"]." | ".$r["ctype"]." | ".$r["tg_id"]."\n";
      }
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>$msg, "parse_mode"=>"HTML"]);
      exit;
    }
    if ($data === "admin:users") {
      $pdo = db();
      $total = (int)$pdo->query("select count(*) from public.users")->fetchColumn();
      $verified = (int)$pdo->query("select count(*) from public.users where verified=true")->fetchColumn();
      $sumPoints = (int)$pdo->query("select coalesce(sum(points),0) from public.users")->fetchColumn();
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"👥 <b>Users Stats</b>\n\nUsers: <b>{$total}</b>\nVerified: <b>{$verified}</b>\nTotal Points: <b>{$sumPoints}</b>", "parse_mode"=>"HTML"]);
      exit;
    }
    if ($data === "admin:broadcast") {
      set_admin_state($chat_id, "await_broadcast", []);
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"📣 Send broadcast message text now (it will go to all users)."]);
      exit;
    }
    if ($data === "admin:setcost") {
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"⚙️ Select type to set points cost:", "reply_markup"=>type_buttons("admin:setcosttype:")]);
      exit;
    }

    if (strpos($data, "admin:addtype:") === 0) {
      $ctype = explode(":", $data)[2];
      set_admin_state($chat_id, "await_add_codes", ["ctype"=>$ctype]);
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"➕ Send coupon codes for <b>{$ctype}</b> (1 per line).", "parse_mode"=>"HTML"]);
      exit;
    }

    if (strpos($data, "admin:removetype:") === 0) {
      $ctype = explode(":", $data)[2];
      set_admin_state($chat_id, "await_remove_codes", ["ctype"=>$ctype]);
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"➖ Send coupon codes to REMOVE for <b>{$ctype}</b> (1 per line).", "parse_mode"=>"HTML"]);
      exit;
    }

    if (strpos($data, "admin:setcosttype:") === 0) {
      $ctype = explode(":", $data)[2];
      set_admin_state($chat_id, "await_set_cost_value", ["ctype"=>$ctype]);
      $current = redeem_cost($ctype);
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"⚙️ Current cost for {$ctype} is {$current} points.\nSend new points number (example: 3)."]);
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

$user = ensure_user($chat_id, $username, $first_name);

// /start referral
if (strpos($text, "/start") === 0) {
  $parts = explode(" ", $text);
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
          $pdo->prepare("update public.users set referrer_id=:rid where tg_id=:id")
              ->execute([":rid"=>$ref_id, ":id"=>$chat_id]);

          ensure_user($ref_id);
          $pdo->prepare("update public.users set points=points+1, refs=refs+1 where tg_id=:rid")
              ->execute([":rid"=>$ref_id]);

          tg("sendMessage", ["chat_id"=>$ref_id, "text"=>"✅ New referral joined!\n+1 point added."]);
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

// Admin state handling (fast)
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

      broadcast_all("📢 <b>New Coupon Added!</b>\n\n✅ {$ctype} off {$ctype} coupons are now available.\n📦 Stock: <b>{$s[$ctype]}</b>");
      echo "OK"; exit;
    }

    if ($mode === "await_remove_codes") {
      $ctype = $data["ctype"] ?? "";
      $lines = preg_split("/\r\n|\n|\r/", $text);
      $codes = [];
      foreach ($lines as $l) { $l = trim($l); if ($l !== "") $codes[] = $l; }

      if (!$ctype || count($codes) === 0) {
        tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Send codes to remove (1 per line)."]);
        echo "OK"; exit;
      }

      $removed = 0;
      foreach ($codes as $code) {
        $del = $pdo->prepare("delete from public.coupons where ctype=:t and code=:c and is_used=false");
        $del->execute([":t"=>$ctype, ":c"=>$code]);
        $removed += (int)$del->rowCount();
      }

      clear_admin_state($chat_id);
      $s = stock_all();
      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"✅ Removed <b>{$removed}</b> unused coupons from {$ctype}.\n📦 Stock: <b>{$s[$ctype]}</b>", "parse_mode"=>"HTML"]);
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

    if ($mode === "await_set_cost_value") {
      $ctype = $data["ctype"] ?? "";
      if (!$ctype || !ctype_digit($text)) {
        tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Send only number (example: 3)."]);
        echo "OK"; exit;
      }
      $cfg = get_config();
      if (!isset($cfg["redeem_cost"])) $cfg["redeem_cost"] = [];
      $cfg["redeem_cost"][$ctype] = (int)$text;
      save_config($cfg);
      clear_admin_state($chat_id);

      tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"✅ Redeem cost updated: {$ctype} => {$text} points"]);
      echo "OK"; exit;
    }
  }
}

// ✅ Verify (only not-verified users will have this button)
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
  tg("sendMessage", ["chat_id"=>$chat_id, "text"=>"🛠 <b>Admin Panel</b>", "parse_mode"=>"HTML", "reply_markup"=>admin_panel_buttons()]);
  echo "OK"; exit;
}

// Default
send_menu($chat_id, $u);
echo "OK";
