<?php
session_start();
require __DIR__ . '/db.php';
header('Content-Type: application/json');

$in  = json_decode(file_get_contents('php://input'), true) ?: [];
$act = $_GET['action'] ?? '';
$day = date('Y-m-d');
$now = date('Y-m-d H:i:s');

function out($d, $code = 200) { http_response_code($code); echo json_encode($d); exit; }
function admin() { if (empty($_SESSION['admin'])) out(['error' => 'Admin login required'], 401); }
function q($db, $sql, $p = []) { $s = $db->prepare($sql); $s->execute($p); return $s; }

// current token being served + waiting count for a service
function snapshot($db, $sid, $day) {
  $serving = q($db, "SELECT number FROM tokens WHERE service_id=? AND day=? AND status='serving'", [$sid, $day])->fetch();
  $waiting = q($db, "SELECT COUNT(*) c FROM tokens WHERE service_id=? AND day=? AND status='waiting'", [$sid, $day])->fetch()['c'];
  return ['serving' => $serving ? (int)$serving['number'] : 0, 'waiting' => (int)$waiting];
}

function callNext($db, $sid, $day, $now, $closeAs) {
  $db->beginTransaction();
  q($db, "UPDATE tokens SET status=?, finished_at=? WHERE service_id=? AND day=? AND status='serving'", [$closeAs, $now, $sid, $day]);
  $n = q($db, "SELECT id FROM tokens WHERE service_id=? AND day=? AND status='waiting' ORDER BY number LIMIT 1", [$sid, $day])->fetch();
  if ($n) q($db, "UPDATE tokens SET status='serving', called_at=? WHERE id=?", [$now, $n['id']]);
  $db->commit();
}

switch ($act) {

  case 'services':
    $rows = $db->query("SELECT * FROM services ORDER BY id")->fetchAll();
    foreach ($rows as &$r) $r += snapshot($db, $r['id'], $day);
    out($rows);

  case 'take': // student gets a token
    $sid = (int)($in['service_id'] ?? 0);
    $name = trim(substr($in['name'] ?? '', 0, 60));
    if (!$sid || $name === '') out(['error' => 'Name and service are required'], 400);
    if (!q($db, "SELECT 1 FROM services WHERE id=?", [$sid])->fetch()) out(['error' => 'Unknown service'], 404);
    for ($i = 0; $i < 5; $i++) { // retry if two students collide on the same number
      try {
        $max = q($db, "SELECT COALESCE(MAX(number),0) m FROM tokens WHERE service_id=? AND day=?", [$sid, $day])->fetch()['m'];
        q($db, "INSERT INTO tokens(service_id,day,number,name,created_at) VALUES(?,?,?,?,?)", [$sid, $day, $max + 1, $name, $now]);
        out(['token_id' => (int)$db->lastInsertId()]);
      } catch (PDOException $e) { usleep(50000); }
    }
    out(['error' => 'Busy, please try again'], 503);

  case 'status': // student tracks token
    $t = q($db, "SELECT t.*, s.name sname, s.code, s.avg_minutes FROM tokens t JOIN services s ON s.id=t.service_id WHERE t.id=?", [(int)($_GET['token_id'] ?? 0)])->fetch();
    if (!$t) out(['error' => 'Token not found'], 404);
    $snap = snapshot($db, $t['service_id'], $t['day']);
    $ahead = (int)q($db, "SELECT COUNT(*) c FROM tokens WHERE service_id=? AND day=? AND status='waiting' AND number<?", [$t['service_id'], $t['day'], $t['number']])->fetch()['c'];
    if ($snap['serving'] && $t['status'] === 'waiting') $ahead++; // the one being served now is also ahead
    out(['number' => (int)$t['number'], 'label' => $t['code'] . str_pad($t['number'], 3, '0', STR_PAD_LEFT),
         'service' => $t['sname'], 'status' => $t['status'], 'serving' => $snap['serving'],
         'ahead' => $t['status'] === 'waiting' ? $ahead : 0, 'eta_min' => $ahead * (int)$t['avg_minutes'], 'day' => $t['day']]);

  case 'cancel':
    q($db, "UPDATE tokens SET status='cancelled', finished_at=? WHERE id=? AND status='waiting'", [$now, (int)($in['token_id'] ?? 0)]);
    out(['ok' => true]);

  // ---------- admin ----------
  case 'login':
    if (($in['password'] ?? '') === ADMIN_PASSWORD) { $_SESSION['admin'] = true; out(['ok' => true]); }
    out(['error' => 'Wrong password'], 401);

  case 'logout': session_destroy(); out(['ok' => true]);
  case 'whoami': out(['admin' => !empty($_SESSION['admin'])]);

  case 'admin_queue':
    admin();
    $sid = (int)($_GET['service_id'] ?? 0);
    $cur = q($db, "SELECT number,name FROM tokens WHERE service_id=? AND day=? AND status='serving'", [$sid, $day])->fetch();
    $wait = q($db, "SELECT number,name,created_at FROM tokens WHERE service_id=? AND day=? AND status='waiting' ORDER BY number", [$sid, $day])->fetchAll();
    $stats = q($db, "SELECT status, COUNT(*) c FROM tokens WHERE service_id=? AND day=? GROUP BY status", [$sid, $day])->fetchAll(PDO::FETCH_KEY_PAIR);
    out(['current' => $cur ?: null, 'waiting' => $wait, 'stats' => $stats]);

  case 'admin_next': admin(); callNext($db, (int)$in['service_id'], $day, $now, 'done'); out(['ok' => true]);
  case 'admin_skip': admin(); callNext($db, (int)$in['service_id'], $day, $now, 'skipped'); out(['ok' => true]);
  case 'admin_done':
    admin();
    q($db, "UPDATE tokens SET status='done', finished_at=? WHERE service_id=? AND day=? AND status='serving'", [$now, (int)$in['service_id'], $day]);
    out(['ok' => true]);
  case 'admin_reset':
    admin();
    q($db, "DELETE FROM tokens WHERE service_id=? AND day=?", [(int)$in['service_id'], $day]);
    out(['ok' => true]);
  case 'admin_service_add':
    admin();
    $n = trim($in['name'] ?? ''); $c = strtoupper(substr(trim($in['code'] ?? $n), 0, 1));
    if ($n === '') out(['error' => 'Name required'], 400);
    q($db, "INSERT INTO services(name,code,avg_minutes) VALUES(?,?,?)", [$n, $c, max(1, (int)($in['avg_minutes'] ?? 5))]);
    out(['ok' => true]);

  default: out(['error' => 'Unknown action'], 404);
}
