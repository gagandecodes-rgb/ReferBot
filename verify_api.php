<?php
$VERIFY_SECRET = getenv("VERIFY_SECRET");
$BOT_USERNAME  = ltrim(getenv("BOT_USERNAME"), "@");
$BOT_TOKEN     = getenv("BOT_TOKEN");

header("Content-Type: application/json; charset=utf-8");

if (!$VERIFY_SECRET || !$BOT_USERNAME) {
  http_response_code(500);
  echo json_encode(["ok"=>false,"error"=>"Server config missing"]);
  exit;
}

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
  $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$name", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
  ]);
  return $pdo;
}

function hmac_sig($tg_id) {
  return hash_hmac("sha256", (string)$tg_id, getenv("VERIFY_SECRET"));
}

$raw = file_get_contents("php://input");
$body = json_decode($raw, true);
$tg_id = (int)($body["tg_id"] ?? 0);
$sig   = (string)($body["sig"] ?? "");

if ($tg_id <= 0 || $sig === "") {
  echo json_encode(["ok"=>false,"error"=>"Bad request"]);
  exit;
}

if (!hash_equals(hmac_sig($tg_id), $sig)) {
  echo json_encode(["ok"=>false,"error"=>"Invalid signature"]);
  exit;
}

// Device token (cookie) => one device only one tg id
$cookieName = "device_token";
$device_token = $_COOKIE[$cookieName] ?? "";

if ($device_token === "" || strlen($device_token) < 20) {
  $device_token = bin2hex(random_bytes(24));
  setcookie($cookieName, $device_token, time()+3600*24*365, "/", "", true, true);
}

$pdo = db();
$pdo->beginTransaction();
try {
  // Ensure user exists
  $pdo->prepare("insert into public.users (tg_id) values (:id) on conflict (tg_id) do nothing")
      ->execute([":id"=>$tg_id]);

  // Check if this device token already bound to another user
  $st = $pdo->prepare("select tg_id from public.users where device_token = :dt limit 1");
  $st->execute([":dt"=>$device_token]);
  $bound = $st->fetchColumn();

  if ($bound && (int)$bound !== $tg_id) {
    $pdo->rollBack();
    echo json_encode(["ok"=>false,"error"=>"This device is already verified with another Telegram ID"]);
    exit;
  }

  // Check if this user already has a different device token
  $st2 = $pdo->prepare("select device_token from public.users where tg_id=:id for update");
  $st2->execute([":id"=>$tg_id]);
  $existing = $st2->fetchColumn();

  if ($existing && $existing !== $device_token) {
    $pdo->rollBack();
    echo json_encode(["ok"=>false,"error"=>"This Telegram ID is already verified on another device"]);
    exit;
  }

  // Save verification
  $pdo->prepare("update public.users set verified=true, device_token=:dt, verified_at=now() where tg_id=:id")
      ->execute([":dt"=>$device_token, ":id"=>$tg_id]);

  $pdo->commit();
  echo json_encode(["ok"=>true, "bot_url"=>"https://t.me/".$BOT_USERNAME]);
} catch (Exception $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  echo json_encode(["ok"=>false,"error"=>"Server error"]);
}
