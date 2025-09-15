<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// /controllers/admin/rewards/cashback/program_save.php
declare(strict_types=1);

/* Debug */
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { @ini_set('display_errors','1'); @ini_set('display_startup_errors','1'); error_reporting(E_ALL); }

/* Bootstrap db + session */
$bootstrap_path = __DIR__ . '/../../../../config/db.php';
if (!is_file($bootstrap_path)) {
  $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
  if ($docRoot) { $alt = $docRoot . '/config/db.php'; if (is_file($alt)) $bootstrap_path = $alt; }
}
if (!is_file($bootstrap_path)) { http_response_code(500); echo 'db bootstrap missing'; exit; }

require_once $bootstrap_path; // db(), use_backend_session()
use_backend_session();

$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: /views/auth/login.php'); exit; }
$tenantId = (int)($user['tenant_id'] ?? 0);
if ($tenantId <= 0) { http_response_code(403); echo 'Invalid tenant'; exit; }

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  header('Location: /views/admin/rewards/cashback/overview.php'); exit;
}

function hstr(string $k): string { return trim((string)($_POST[$k] ?? '')); }
function hnum(string $k, float $def=0.0): float {
  $v = (string)($_POST[$k] ?? '');
  return ($v === '' ? $def : (float)$v);
}
function hbool(string $k): bool { return isset($_POST[$k]) && (string)$_POST[$k] === '1'; }

try {
  $pdo = db();
  if (!$pdo instanceof PDO) throw new RuntimeException('DB not available');

  // Basic fields
  $name   = hstr('name');
  $status = in_array(hstr('status'), ['active','paused','inactive'], true) ? hstr('status') : 'active';
  $start  = hstr('start_at') ?: null;
  $end    = hstr('end_at')   ?: null;

  $award  = in_array(hstr('award_timing'), ['on_payment','on_close'], true) ? hstr('award_timing') : 'on_payment';
  $round  = in_array(hstr('rounding'), ['floor','nearest','ceil'], true) ? hstr('rounding') : 'floor';

  $maxRedeemPct   = hnum('max_redeem_percent', 0.0);          // %
  $minRedeemAmt   = hnum('min_redeem_amount', 0.0);           // amount, re-using column min_redeem_points
  $minVisitRedeem = (int)($_POST['min_visit_redeem'] ?? 2);    // start auto-redeem from this visit
  if ($minVisitRedeem < 1) $minVisitRedeem = 1;

  $walletExpiryDays = (int)($_POST['wallet_expiry_days'] ?? 0); // 0 = no expiry

  $afterLast = in_array(hstr('after_last'), ['continue','loop','stop'], true) ? hstr('after_last') : 'continue';

  // Channels / Exclusions / Notes
  $channels = $_POST['channels'] ?? ['pos','online'];
  if (!is_array($channels)) $channels = ['pos','online'];
  $channels = array_values(array_intersect(array_map('strval',$channels), ['pos','online']));
  if (!$channels) $channels = ['pos','online'];
  $excl_aggr = hbool('excl_aggregators');
  $excl_disc = hbool('excl_discounted');
  $notes     = hstr('notes');

  // Ladder
  $ladder = [];
  $rows = $_POST['ladder'] ?? [];
  if (!is_array($rows)) $rows = [];
  foreach ($rows as $row) {
    $visitRaw = isset($row['visit']) ? trim((string)$row['visit']) : '';
    $ratePct  = isset($row['rate_pct']) ? (float)$row['rate_pct'] : null; // %
    $valid    = isset($row['valid_days']) ? (int)$row['valid_days'] : null;

    if ($visitRaw === '' || $ratePct === null || $valid === null) continue;

    // Validate visit: number like 1,2,3 or terminal like 3+
    if (!preg_match('/^\d+(\+)?$/', $visitRaw)) continue;

    $isTerminal = str_ends_with($visitRaw, '+');
    $visitNum   = (int)$visitRaw;
    if ($visitNum < 1) continue;

    $rate = max(0.0, min(100.0, (float)$ratePct)) / 100.0; // store as 0..1
    $valid = max(1, (int)$valid);

    $ladder[] = [
      'visit'      => $isTerminal ? ($visitNum . '+') : $visitNum,
      'rate'       => $rate,
      'valid_days' => $valid,
    ];
  }

  if ($name === '') throw new InvalidArgumentException('Program name is required.');
  if (!$start) throw new InvalidArgumentException('Goes live date is required.');
  if ($end && $end < $start) throw new InvalidArgumentException('Ends date must not be before Goes live date.');
  if (count($ladder) === 0) throw new InvalidArgumentException('Please define at least one tier row.');
  if (count($ladder) > 8) throw new InvalidArgumentException('Maximum 8 tiers are allowed.');

  $earn_rule = [
    'type' => 'cashback_visit_ladder',
    'ladder' => $ladder,
    'after_last' => $afterLast,                 // continue | loop | stop
    'min_visit_to_redeem' => $minVisitRedeem,   // from which visit we auto-redeem
    'expiry' => ['days' => max(0, $walletExpiryDays)], // 0 = no expiry
    'channels' => $channels,
    'exclusions' => [
      'exclude_aggregators' => $excl_aggr,
      'exclude_discounted_orders' => $excl_disc,
    ],
  ];
  if ($notes !== '') $earn_rule['notes'] = $notes;

  $redeem_rule = new stdClass(); // reserved for future use

  // OPTIONAL: auto-end overlapping active programs when a new active program starts
  $pdo->beginTransaction();
  try {
    if ($status === 'active' && $start) {
      $upd = $pdo->prepare("UPDATE loyalty_programs
        SET end_at = DATE_SUB(:s, INTERVAL 1 SECOND)
        WHERE tenant_id=:t AND program_type='cashback' AND status='active'
          AND (end_at IS NULL OR end_at > :s)");
      $upd->execute([':t'=>$tenantId, ':s'=>$start]);
    }

    $ins = $pdo->prepare("INSERT INTO loyalty_programs
      (tenant_id, program_type, name, status,
       start_at, end_at,
       award_timing, rounding,
       max_redeem_percent, min_redeem_points,
       earn_rule_json, redeem_rule_json,
       created_at, updated_at)
      VALUES
      (:t, 'cashback', :name, :status,
       :start_at, :end_at,
       :award, :rounding,
       :maxp, :minamt,
       :erj, :rrj,
       NOW(), NOW())");

    $ins->execute([
      ':t'       => $tenantId,
      ':name'    => $name,
      ':status'  => $status,
      ':start_at'=> $start,
      ':end_at'  => ($end ?: null),
      ':award'   => $award,
      ':rounding'=> $round,
      ':maxp'    => $maxRedeemPct,
      ':minamt'  => $minRedeemAmt, // reusing column; interpreted as amount for cashback
      ':erj'     => json_encode($earn_rule, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
      ':rrj'     => json_encode($redeem_rule, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
    ]);

    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }

  // Back to the page
  header('Location: /views/admin/rewards/cashback/overview.php?saved=1#programs');
  exit;

} catch (Throwable $e) {
  if ($DEBUG) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Error: ' . $e->getMessage();
  } else {
    header('Location: /views/admin/rewards/cashback/overview.php?error=1#setup');
  }
}