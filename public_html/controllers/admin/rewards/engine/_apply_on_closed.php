<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// /controllers/admin/rewards/engine/_apply_on_closed.php
declare(strict_types=1);

/**
 * Core Rewards application logic when an order is closed.
 * This file exposes a FUNCTION (no headers/output).
 *
 * Usage:
 *   require_once __DIR__ . '/_apply_on_closed.php';
 *   $out = rewards_apply_on_order_closed($pdo, $TENANT_ID, $orderId);
 *   // $out = ['applied'=>['cashback'=>bool,'points'=>bool,'stamp'=>bool], 'notes'=>[...] ]
 */

function rewards_apply_on_order_closed(PDO $pdo, int $tenantId, int $orderId): array
{
  // 1) Load order and ensure closed
  $st = $pdo->prepare("
    SELECT o.*, c.id AS customer_id, c.phone AS customer_phone
    FROM orders o
    LEFT JOIN customers c ON c.id = o.customer_id
    WHERE o.id = :id AND o.tenant_id = :t
    LIMIT 1
  ");
  $st->execute([':id'=>$orderId, ':t'=>$tenantId]);
  $order = $st->fetch(PDO::FETCH_ASSOC);
  if (!$order) { return ['applied'=>['cashback'=>false,'points'=>false,'stamp'=>false], 'notes'=>['Order not found']]; }
  if ((string)$order['status'] !== 'closed') { return ['applied'=>['cashback'=>false,'points'=>false,'stamp'=>false], 'notes'=>['Order is not closed']]; }

  // basis = subtotal excluding tax & service
  $subtotal = (float)($order['subtotal_amount'] ?? 0);
  $tax      = (float)($order['tax_amount'] ?? 0);
  $service  = (float)($order['service_amount'] ?? 0);
  $basis    = max(0.0, $subtotal - $tax - $service);

  // member by phone
  $customerId = (int)($order['customer_id'] ?? 0);
  $phone      = trim((string)($order['customer_phone'] ?? ''));
  if ($customerId <= 0 || $phone === '') {
    return ['applied'=>['cashback'=>false,'points'=>false,'stamp'=>false], 'notes'=>['No member phone found']];
  }

  // 2) Load active programs
  $pgm = $pdo->prepare("SELECT * FROM loyalty_programs WHERE tenant_id=:t AND is_active=1");
  $pgm->execute([':t'=>$tenantId]);
  $programs = $pgm->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $applied = ['cashback'=>false,'points'=>false,'stamp'=>false];
  $notes = [];

  // ---------- CASHBACK (ladder) ----------
  foreach ($programs as $p) {
    if ((string)$p['program_type'] !== 'cashback') continue;
    $earnRule = json_decode((string)($p['earn_rule_json'] ?? '{}'), true) ?: [];
    $windowDays = (int)($earnRule['visit_window_days'] ?? 15);
    $ladder = $earnRule['ladder'] ?? [];

    // enrollment
    $en = $pdo->prepare("
      SELECT * FROM loyalty_program_enrollments
      WHERE tenant_id=:t AND program_id=:pid AND customer_id=:cid
      LIMIT 1
    ");
    $en->execute([':t'=>$tenantId, ':pid'=>$p['id'], ':cid'=>$customerId]);
    $enroll = $en->fetch(PDO::FETCH_ASSOC);
    if (!$enroll) {
      $pdo->prepare("
        INSERT INTO loyalty_program_enrollments (tenant_id, program_id, customer_id, is_active, created_at, updated_at)
        VALUES (:t,:pid,:cid,1,NOW(),NOW())
      ")->execute([':t'=>$tenantId, ':pid'=>$p['id'], ':cid'=>$customerId]);
      $enroll = ['qualifying_visit_count'=>0,'last_qualifying_at'=>null,'id'=>(int)$pdo->lastInsertId()];
    }

    // redeem previous earned cashback if within window
    $prev = $pdo->prepare("
      SELECT id, cash_delta, created_at
      FROM loyalty_ledger
      WHERE tenant_id=:t AND program_id=:pid AND customer_id=:cid
        AND entry_type='cashback_earn'
      ORDER BY id DESC
      LIMIT 1
    ");
    $prev->execute([':t'=>$tenantId, ':pid'=>$p['id'], ':cid'=>$customerId]);
    $prevEarn = $prev->fetch(PDO::FETCH_ASSOC);

    if ($prevEarn) {
      $within = true;
      if ($windowDays > 0) {
        $limitTs = strtotime('+'.$windowDays.' days', strtotime((string)$prevEarn['created_at']));
        $within = (time() <= $limitTs);
      }
      if ($within) {
        $redeemAmt = min((float)$prevEarn['cash_delta'], $basis);
        if ($redeemAmt > 0) {
          $pdo->prepare("
            INSERT INTO loyalty_ledger
              (tenant_id, program_id, customer_id, order_id, entry_type, cash_delta, meta_json, created_at)
            VALUES (:t,:pid,:cid,:oid,'cashback_redeem',:amt,:meta,NOW())
          ")->execute([
            ':t'=>$tenantId, ':pid'=>$p['id'], ':cid'=>$customerId,
            ':oid'=>$order['id'], ':amt'=> -1 * $redeemAmt,
            ':meta'=> json_encode(['redeem_of'=>$prevEarn['id']], JSON_UNESCAPED_UNICODE),
          ]);
          $applied['cashback'] = true;
          $basis = max(0.0, $basis - $redeemAmt);
          $notes[] = "Cashback redeemed: {$redeemAmt}";
        }
      } else {
        $pdo->prepare("
          INSERT INTO loyalty_ledger
            (tenant_id, program_id, customer_id, order_id, entry_type, cash_delta, meta_json, created_at)
          VALUES (:t,:pid,:cid,:oid,'expire',0,:meta,NOW())
        ")->execute([
          ':t'=>$tenantId, ':pid'=>$p['id'], ':cid'=>$customerId,
          ':oid'=>$order['id'],
          ':meta'=> json_encode(['expired_entry'=>$prevEarn['id']], JSON_UNESCAPED_UNICODE),
        ]);
        $notes[] = "Previous cashback expired";
      }
    }

    // determine current visit
    $visitCount = (int)($enroll['qualifying_visit_count'] ?? 0);
    $currentVisit = $visitCount + 1;

    // earn current ladder %
    $earnPercent = 0.0;
    if ($ladder && is_array($ladder)) {
      $rule = null;
      foreach ($ladder as $row) {
        if ((int)($row['visit'] ?? 0) === $currentVisit) { $rule = $row; break; }
      }
      if (!$rule) { $rule = end($ladder); }
      $earnPercent = (float)($rule['earn_percent'] ?? 0);
    }
    if ($earnPercent > 0 && $basis > 0) {
      $earnAmt = round(($basis * $earnPercent) / 100, 3);
      if ($earnAmt > 0) {
        $pdo->prepare("
          INSERT INTO loyalty_ledger
            (tenant_id, program_id, customer_id, order_id, entry_type, cash_delta, meta_json, created_at)
          VALUES (:t,:pid,:cid,:oid,'cashback_earn',:amt,:meta,NOW())
        ")->execute([
          ':t'=>$tenantId, ':pid'=>$p['id'], ':cid'=>$customerId,
          ':oid'=>$order['id'], ':amt'=> $earnAmt,
          ':meta'=> json_encode(['visit'=>$currentVisit,'percent'=>$earnPercent], JSON_UNESCAPED_UNICODE),
        ]);
        $applied['cashback'] = true;
        $notes[] = "Cashback earned ({$earnPercent}%): {$earnAmt}";
      }
    }

    // update enrollment
    $pdo->prepare("
      UPDATE loyalty_program_enrollments
      SET qualifying_visit_count = qualifying_visit_count + 1,
          last_qualifying_at = NOW(), updated_at = NOW()
      WHERE tenant_id=:t AND program_id=:pid AND customer_id=:cid
    ")->execute([':t'=>$tenantId, ':pid'=>$p['id'], ':cid'=>$customerId]);

    break; // one cashback program expected
  }

  // ---------- POINTS ----------
  foreach ($programs as $p) {
    if ((string)$p['program_type'] !== 'points') continue;
    $earnRule = json_decode((string)($p['earn_rule_json'] ?? '{}'), true) ?: [];
    $percent = (float)($earnRule['earn_percent'] ?? 0.0);

    if ($percent > 0 && $basis > 0) {
      $points = (int)floor(($basis * $percent) / 100.0);
      if ($points > 0) {
        $pdo->prepare("
          INSERT INTO loyalty_ledger
            (tenant_id, program_id, customer_id, order_id, entry_type, points_delta, meta_json, created_at)
          VALUES (:t,:pid,:cid,:oid,'points_earn',:pts,:meta,NOW())
        ")->execute([
          ':t'=>$tenantId, ':pid'=>$p['id'], ':cid'=>$customerId,
          ':oid'=>$order['id'], ':pts'=>$points,
          ':meta'=> json_encode(['percent'=>$percent,'basis'=>$basis], JSON_UNESCAPED_UNICODE),
        ]);
        // upsert account
        $pdo->prepare("
          INSERT INTO loyalty_accounts (tenant_id, customer_id, points_balance, cash_balance, updated_at, created_at)
          VALUES (:t,:cid,:pts,0,NOW(),NOW())
          ON DUPLICATE KEY UPDATE points_balance = points_balance + VALUES(points_balance), updated_at=NOW()
        ")->execute([':t'=>$tenantId, ':cid'=>$customerId, ':pts'=>$points]);

        $applied['points'] = true;
        $notes[] = "Points earned ({$percent}%): {$points}";
      }
    }
    break;
  }

  // ---------- STAMP ----------
  foreach ($programs as $p) {
    if ((string)$p['program_type'] !== 'stamp') continue;
    $rule = json_decode((string)($p['earn_rule_json'] ?? '{}'), true) ?: [];
    $target = max(1, (int)($rule['target_stamps'] ?? 10));
    $reward = $rule['reward'] ?? ['type'=>'amount','value'=>0];

    // upsert card
    $card = $pdo->prepare("
      SELECT * FROM loyalty_stamp_cards
      WHERE tenant_id=:t AND program_id=:pid AND customer_id=:cid AND is_active=1
      LIMIT 1
    ");
    $card->execute([':t'=>$tenantId, ':pid'=>$p['id'], ':cid'=>$customerId]);
    $cur = $card->fetch(PDO::FETCH_ASSOC);

    if (!$cur) {
      $pdo->prepare("
        INSERT INTO loyalty_stamp_cards
          (tenant_id, program_id, customer_id, current_stamps, target_stamps, is_active, created_at, updated_at)
        VALUES (:t,:pid,:cid,0,:target,1,NOW(),NOW())
      ")->execute([':t'=>$tenantId, ':pid'=>$p['id'], ':cid'=>$customerId, ':target'=>$target]);
      $cur = ['id'=>(int)$pdo->lastInsertId(), 'current_stamps'=>0, 'target_stamps'=>$target];
    }

    // +1 stamp
    $pdo->prepare("
      INSERT INTO loyalty_stamp_ledger
        (tenant_id, program_id, customer_id, order_id, stamps_delta, reason, created_at)
      VALUES (:t,:pid,:cid,:oid,1,'earn',NOW())
    ")->execute([':t'=>$tenantId, ':pid'=>$p['id'], ':cid'=>$customerId, ':oid'=>$order['id']]);

    $pdo->prepare("
      UPDATE loyalty_stamp_cards
      SET current_stamps = current_stamps + 1, updated_at = NOW()
      WHERE id = :id
    ")->execute([':id'=>$cur['id']]);

    // check threshold
    $r2 = $pdo->prepare("SELECT current_stamps, target_stamps FROM loyalty_stamp_cards WHERE id=:id LIMIT 1");
    $r2->execute([':id'=>$cur['id']]);
    $nowCard = $r2->fetch(PDO::FETCH_ASSOC);
    if ($nowCard && (int)$nowCard['current_stamps'] >= (int)$nowCard['target_stamps']) {
      if (($reward['type'] ?? 'amount') === 'amount' && (float)($reward['value'] ?? 0) > 0) {
        $pdo->prepare("
          INSERT INTO loyalty_ledger
            (tenant_id, program_id, customer_id, order_id, entry_type, cash_delta, meta_json, created_at)
          VALUES (:t,:pid,:cid,:oid,'stamp_reward_cash',:amt,:meta,NOW())
        ")->execute([
          ':t'=>$tenantId, ':pid'=>$p['id'], ':cid'=>$customerId,
          ':oid'=>$order['id'], ':amt'=>(float)$reward['value'],
          ':meta'=> json_encode(['reason'=>'stamps_complete','target'=>$nowCard['target_stamps']], JSON_UNESCAPED_UNICODE),
        ]);
      }
      // reset card
      $pdo->prepare("UPDATE loyalty_stamp_cards SET current_stamps = 0, updated_at = NOW() WHERE id=:id")
          ->execute([':id'=>$cur['id']]);

      $applied['stamp'] = true;
      $notes[] = "Stamp reward issued";
    } else {
      $applied['stamp'] = true;
      $notes[] = "Stamp +1";
    }

    break;
  }

  return ['applied'=>$applied,'notes'=>$notes];
}