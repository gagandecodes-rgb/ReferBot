<?php
$VERIFY_SECRET = getenv("VERIFY_SECRET");
if (!$VERIFY_SECRET) {
  http_response_code(500);
  header("Content-Type: application/json");
  echo json_encode(["ok"=>false,"message"=>"Missing VERIFY_SECRET"]);
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
  if (!$host || !$user || !$pass) throw new Exception("DB env missing");
  $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$name", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
  ]);
  return $pdo;
}

function hmac_sig($tg_id) {
  global $VERIFY_SECRET;
  return hash_hmac("sha256", (string)$tg_id, $VERIFY_SECRET);
}
function sig_ok($tg_id, $sig) {
  return hash_equals(hmac_sig($tg_id), (string)$sig);
}

function ensure_user($tg_id) {
  $pdo = db();
  $st = $pdo->prepare("
    insert into public.users (tg_id)
    values (:tg_id)
    on conflict (tg_id) do update set last_seen=now()
  ");
  $st->execute([":tg_id"=>$tg_id]);
}

header("Content-Type: application/json");

$raw = file_get_contents("php://input");
$p = json_decode($raw, true) ?: [];

$tg_id = $p["tg_id"] ?? "";
$sig = $p["sig"] ?? "";
$device_id = trim((string)($p["device_id"] ?? ""));

if (!ctype_digit((string)$tg_id) || $device_id === "" || !sig_ok((int)$tg_id, $sig)) {
  http_response_code(400);
  echo json_encode(["ok"=>false,"message"=>"Bad request"]);
  exit;
}

$tg_id_int = (int)$tg_id;

try {
  ensure_user($tg_id_int);
  $pdo = db();
  $pdo->beginTransaction();

  // 1) device -> tg check
  $st = $pdo->prepare("select tg_id from public.device_map where device_id=:d");
  $st->execute([":d"=>$device_id]);
  $existing = $st->fetchColumn();
  if ($existing && (int)$existing !== $tg_id_int) {
    $pdo->rollBack();
    http_response_code(403);
    echo json_encode(["ok"=>false,"message"=>"❌ This device is already linked with another Telegram account."]);
    exit;
  }

  // 2) tg -> device check (1 TG only 1 device)
  $st2 = $pdo->prepare("select device_id from public.device_map where tg_id=:id");
  $st2->execute([":id"=>$tg_id_int]);
  $existingDev = $st2->fetchColumn();
  if ($existingDev && $existingDev !== $device_id) {
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
} catch (Exception $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(["ok"=>false,"message"=>"Server error"]);
}
