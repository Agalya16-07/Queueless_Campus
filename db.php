<?php
// SQLite = zero setup (needs the pdo_sqlite extension, enabled by default in XAMPP/WAMP).
define('ADMIN_PASSWORD', 'admin123');   // change this!
date_default_timezone_set('Asia/Kolkata');

$dir = __DIR__ . '/../data';
if (!is_dir($dir)) mkdir($dir, 0775, true);
$db = new PDO('sqlite:' . $dir . '/queueless.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$db->exec("CREATE TABLE IF NOT EXISTS services(
  id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, code TEXT, avg_minutes INTEGER DEFAULT 5)");
$db->exec("CREATE TABLE IF NOT EXISTS tokens(
  id INTEGER PRIMARY KEY AUTOINCREMENT, service_id INTEGER, day TEXT, number INTEGER,
  name TEXT, status TEXT DEFAULT 'waiting', created_at TEXT, called_at TEXT, finished_at TEXT,
  UNIQUE(service_id, day, number))");

if (!$db->query("SELECT COUNT(*) c FROM services")->fetch()['c']) {
  $s = $db->prepare("INSERT INTO services(name,code,avg_minutes) VALUES(?,?,?)");
  foreach ([['Office','O',5],['Library','L',3],['Certificate Section','C',7],['Accounts','A',6],['Placement Cell','P',10]] as $r) $s->execute($r);
}
