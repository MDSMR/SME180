<?php
// /public_html/controllers/admin/customers/customer_export.php
declare(strict_types=1);

@ini_set('display_errors','0');

try {
  $root = dirname(__DIR__, 4);
  $configPath = $root . '/config/db.php';
  if (!is_file($configPath)) throw new RuntimeException('Configuration not found');
  require_once $configPath;

  if (function_exists('use_backend_session')) { use_backend_session(); }
  else { if (session_status() !== PHP_SESSION_ACTIVE) session_start(); }

  $authPath = $root . '/middleware/auth_login.php';
  if (!is_file($authPath)) throw new RuntimeException('Auth middleware not found');
  require_once $authPath;
  auth_require_login();

  if (!function_exists('db')) throw new RuntimeException('db() not available');

  $tenantId = (int)($_SESSION['user']['tenant_id'] ?? 0);
  if ($tenantId <= 0) throw new RuntimeException('Invalid tenant');

  // ----- Match filters from index -----
  $q              = trim((string)($_GET['q'] ?? ''));
  $classification = (string)($_GET['class'] ?? 'all');
  $rewards        = (string)($_GET['rewards'] ?? 'all');
  $datePreset     = (string)($_GET['d'] ?? 'month');
  $from           = (string)($_GET['from'] ?? '');
  $to             = (string)($_GET['to'] ?? '');

  $today = new DateTimeImmutable('today');
  $firstOfMonth = $today->modify('first day of this month')->format('Y-m-d');
  $lastOfMonth  = $today->modify('last day of this month')->format('Y-m-d');

  $start = null; $end = null;
  switch ($datePreset) {
    case 'week':
      $ws = $today->modify('monday this week'); $we = $ws->modify('+6 days');
      $start = $ws->format('Y-m-d 00:00:00'); $end = $we->format('Y-m-d 23:59:59'); break;
    case 'month':
      $ms = $today->modify('first day of this month'); $me = $today->modify('last day of this month');
      $start = $ms->format('Y-m-d 00:00:00'); $end = $me->format('Y-m-d 23:59:59'); break;
    case 'year':
      $ys = new DateTimeImmutable(date('Y').'-01-01'); $ye = new DateTimeImmutable(date('Y').'-12-31');
      $start = $ys->format('Y-m-d 00:00:00'); $end = $ye->format('Y-m-d 23:59:59'); break;
    case 'custom':
      $fd = $from !== '' ? DateTimeImmutable::createFromFormat('Y-m-d', $from) : DateTimeImmutable::createFromFormat('Y-m-d', $firstOfMonth);
      $td = $to   !== '' ? DateTimeImmutable::createFromFormat('Y-m-d', $to)   : DateTimeImmutable::createFromFormat('Y-m-d', $lastOfMonth);
      if(!$fd) $fd = DateTimeImmutable::createFromFormat('Y-m-d', $firstOfMonth);
      if(!$td) $td = DateTimeImmutable::createFromFormat('Y-m-d', $lastOfMonth);
      if($fd > $td){ [$fd,$td] = [$td,$fd]; }
      $start = $fd->format('Y-m-d 00:00:00'); $end = $td->format('Y-m-d 23:59:59'); break;
    case 'day':
    default:
      $ms = $today->modify('first day of this month'); $me = $today->modify('last day of this month');
      $start = $ms->format('Y-m-d 00:00:00'); $end = $me->format('Y-m-d 23:59:59');
  }

  $pdo = db();
  $where = ["c.tenant_id = :t"];
  $args = [':t'=>$tenantId];

  if ($q !== '') {
    $where[] = "(c.id = :idq OR c.name LIKE :q OR c.phone LIKE :q2 OR c.email LIKE :q3 OR c.rewards_member_no LIKE :q4)";
    $args[':idq'] = ctype_digit($q) ? (int)$q : -1;
    $like = "%$q%";
    $args[':q'] = $like; $args[':q2'] = $like; $args[':q3'] = $like; $args[':q4'] = $like;
  }
  if (in_array($classification, ['regular','vip','corporate','blocked'], true)) { $where[] = "c.classification = :cl"; $args[':cl'] = $classification; }
  if ($rewards === 'enrolled') { $where[] = "c.rewards_enrolled = 1"; }
  elseif ($rewards === 'not') { $where[] = "(c.rewards_enrolled = 0 OR c.rewards_enrolled IS NULL)"; }
  if ($start && $end) { $where[] = "(c.created_at BETWEEN :ds AND :de)"; $args[':ds'] = $start; $args[':de'] = $end; }

  $whereSql = 'WHERE '.implode(' AND ', $where);

  $sql = "
    SELECT
      c.id,
      c.name,
      c.phone,
      c.email,
      c.classification,
      COALESCE(c.rewards_enrolled, 0) AS rewards_enrolled,
      COALESCE(c.points_balance, 0) AS points_balance,
      c.rewards_member_no,
      c.created_at
    FROM customers c
    $whereSql
    ORDER BY c.id DESC
    LIMIT 5000
  ";
  $st = $pdo->prepare($sql);
  $st->execute($args);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // Output CSV
  $filename = 'customers_export_'.date('Ymd_His').'.csv';
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="'.$filename.'"');
  header('Pragma: no-cache');
  header('Expires: 0');

  $out = fopen('php://output', 'w');
  // UTF-8 BOM for Excel
  fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

  fputcsv($out, ['ID','Name','Phone','Email','Classification','Rewards','Points','MemberNo','Created At']);

  foreach ($rows as $r) {
    fputcsv($out, [
      (int)$r['id'],
      (string)($r['name'] ?? ''),
      (string)($r['phone'] ?? ''),
      (string)($r['email'] ?? ''),
      (string)($r['classification'] ?? ''),
      ((int)$r['rewards_enrolled'] === 1 ? 'Enrolled' : 'Not enrolled'),
      (string)($r['points_balance'] ?? '0'),
      (string)($r['rewards_member_no'] ?? ''),
      (string)($r['created_at'] ?? ''),
    ]);
  }

  fclose($out);
  exit;

} catch (Throwable $e) {
  // Fallback: redirect with flash
  if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
  $_SESSION['flash'] = 'Export failed: '.$e->getMessage();
  header('Location: /views/admin/customers/index.php'); exit;
}