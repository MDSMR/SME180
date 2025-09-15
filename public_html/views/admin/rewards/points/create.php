<?php
// File: /views/admin/rewards/points/create.php
// Complete Points Program Create/Edit Page with Branch Selection - FINAL FIXED VERSION
declare(strict_types=1);

require_once __DIR__ . '/_shared/common.php';

if (!$bootstrap_ok) {
    http_response_code(500);
    echo '<h1>Bootstrap Failed</h1><p>' . htmlspecialchars($bootstrap_warning) . '</p>';
    exit;
}

// Set default currency if not set
if (!isset($currency) || empty($currency)) {
    $currency = 'EGP';
}

// Initialize preselected branch IDs early - MUST be before any usage
$prefBranchIds = [0]; // Default to all branches

/* Load branches for selection */
$branches = [];
if ($pdo instanceof PDO) {
    try {
        $branches = load_branches($pdo, $tenantId);
    } catch (Throwable $e) {
        $branches = [];
        error_log('Failed to load branches: ' . $e->getMessage());
    }
}

// Ensure branches is always an array
if (!is_array($branches)) {
    $branches = [];
}

/* Handle POST: Create/Update Program */
$action_msg = ''; 
$action_ok = false;

if ($pdo instanceof PDO && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $act = $_POST['action'] ?? '';
    try {
        if ($act === 'create_version' || $act === 'update_version') {
            $name = trim((string)($_POST['new_name'] ?? ''));
            $status = (($_POST['new_status'] ?? 'active') === 'inactive') ? 'inactive' : 'active';
            $start_at_in = normalize_dt($_POST['new_start_at'] ?? null);
            $end_at_in = normalize_dt($_POST['new_end_at'] ?? null);
            
            $award_timing = in_array(($_POST['new_award_timing'] ?? 'on_payment'), ['on_payment','on_order_complete'], true) 
                ? $_POST['new_award_timing'] : 'on_payment';
            
            $earn_rate = number_format((float)($_POST['new_earn_rate'] ?? 1.00), 2, '.', '');
            $redeem_rate = number_format((float)($_POST['new_redeem_rate'] ?? 10.00), 2, '.', '');
            $min_redeem_points = ($_POST['new_min_redeem_points'] === '' ? null : (int)$_POST['new_min_redeem_points']);
            $max_redeem_percent = ($_POST['new_max_redeem_percent'] === '' ? null : number_format((float)$_POST['new_max_redeem_percent'], 2, '.', ''));
            
            $rounding = 'floor';
            $welcome_bonus_points = (int)($_POST['welcome_bonus_points'] ?? 0);

            // Process branch IDs
            $branch_ids = isset($_POST['branch_ids']) && is_array($_POST['branch_ids']) 
                ? array_map('intval', $_POST['branch_ids']) : [0];
            
            // Process tier multipliers with advancement conditions
            $tiers_names = isset($_POST['tiers_name']) && is_array($_POST['tiers_name']) ? array_values($_POST['tiers_name']) : [];
            $tiers_mults = isset($_POST['tiers_mult']) && is_array($_POST['tiers_mult']) ? array_values($_POST['tiers_mult']) : [];
            $tiers_threshold = isset($_POST['tiers_threshold']) && is_array($_POST['tiers_threshold']) ? array_values($_POST['tiers_threshold']) : [];
            $tiers_period_days = isset($_POST['tiers_period_days']) && is_array($_POST['tiers_period_days']) ? array_values($_POST['tiers_period_days']) : [];
            
            $tier_multiplier = [];
            for ($i = 0; $i < count($tiers_names); $i++) {
                $n = trim((string)$tiers_names[$i]);
                $m = (float)$tiers_mults[$i];
                if ($n !== '' && $m > 0) { 
                    $tier_multiplier[$n] = [
                        'multiplier' => $m,
                        'threshold' => isset($tiers_threshold[$i]) ? (float)$tiers_threshold[$i] : 0,
                        'period_days' => isset($tiers_period_days[$i]) ? (int)$tiers_period_days[$i] : 365
                    ];
                }
            }
            if (!$tier_multiplier) {
                $tier_multiplier = [
                    'Bronze' => ['multiplier' => 1.00, 'threshold' => 0, 'period_days' => 365],
                    'Silver' => ['multiplier' => 1.25, 'threshold' => 500, 'period_days' => 365],
                    'Gold' => ['multiplier' => 1.50, 'threshold' => 2000, 'period_days' => 365]
                ];
            }

            $tier_advancement = $_POST['tier_advancement'] ?? 'spending';
            $expiry_policy = 'bucket_days';
            $expiry_days = 365;

            $channels_in = $_POST['channels'] ?? ['pos', 'online'];
            if (!is_array($channels_in)) $channels_in = ['pos', 'online'];
            $channels_in = array_values(array_intersect(array_map('strval', $channels_in), ['pos', 'online', 'aggregator']));
            if (!$channels_in) $channels_in = ['pos', 'online'];
            
            $exclude_aggregators = isset($_POST['excl_aggregators']) && $_POST['excl_aggregators'] === '1';
            $exclude_discounted = isset($_POST['excl_discounted']) && $_POST['excl_discounted'] === '1';
            $desc = trim((string)($_POST['desc'] ?? ''));

            // Build earn_rule with branches
            $earn_rule = [
                'basis' => 'subtotal_excl_tax_service',
                'eligible_branches' => in_array(0, $branch_ids) ? 'all' : $branch_ids,
                'eligible_channels' => $channels_in,
                'exclude_aggregators' => $exclude_aggregators,
                'exclude_discounted_orders' => $exclude_discounted,
                'tier_multiplier' => $tier_multiplier,
                'tier_advancement' => $tier_advancement
            ];
            if ($desc !== '') $earn_rule['description'] = $desc;
            $redeem_rule = new stdClass();

            $pdo->beginTransaction();
            try {
                if ($act === 'update_version' && isset($_POST['program_id'])) {
                    // Update existing program
                    $programId = (int)$_POST['program_id'];
                    
                    $upd = $pdo->prepare("UPDATE loyalty_programs SET 
                        name = ?, status = ?, start_at = ?, end_at = ?,
                        earn_mode = 'per_currency', earn_rate = ?, redeem_rate = ?,
                        min_redeem_points = ?, max_redeem_percent = ?,
                        award_timing = ?, expiry_policy = ?, expiry_days = ?, rounding = ?,
                        welcome_bonus_points = ?,
                        earn_rule_json = ?, redeem_rule_json = ?, updated_at = NOW()
                        WHERE id = ? AND tenant_id = ? AND program_type = 'points'");
                    $upd->execute([
                        $name, $status, $start_at_in, $end_at_in,
                        $earn_rate, $redeem_rate,
                        $min_redeem_points, $max_redeem_percent,
                        $award_timing, $expiry_policy, $expiry_days, $rounding,
                        $welcome_bonus_points,
                        json_encode($earn_rule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        json_encode($redeem_rule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        $programId, $tenantId
                    ]);
                    
                    // Delete existing tier conditions and re-insert
                    $delTiers = $pdo->prepare("DELETE FROM tier_conditions WHERE program_id = ?");
                    $delTiers->execute([$programId]);
                    
                    // Insert tier conditions with period_days
                    if ($tier_advancement && $tier_multiplier) {
                        $tierIns = $pdo->prepare("INSERT INTO tier_conditions 
                            (program_id, tier_name, condition_type, threshold_value, period_days) 
                            VALUES (?, ?, ?, ?, ?)");
                        
                        foreach ($tier_multiplier as $tierName => $tierData) {
                            $tierIns->execute([
                                $programId,
                                $tierName,
                                $tier_advancement,
                                $tierData['threshold'],
                                $tierData['period_days']
                            ]);
                        }
                    }
                    
                    $action_msg = 'Program updated successfully.';
                } else {
                    // Create new program
                    if ($start_at_in) {
                        $upd = $pdo->prepare("UPDATE loyalty_programs
                                            SET end_at = DATE_SUB(?, INTERVAL 1 SECOND)
                                            WHERE tenant_id = ? AND program_type = 'points' AND status = 'active'
                                              AND (end_at IS NULL OR end_at > ?)");
                        $upd->execute([$start_at_in, $tenantId, $start_at_in]);
                    }

                    $ins = $pdo->prepare("INSERT INTO loyalty_programs
                        (tenant_id, program_type, name, status,
                         start_at, end_at,
                         earn_mode, earn_rate, redeem_rate,
                         min_redeem_points, max_redeem_percent,
                         award_timing, expiry_policy, expiry_days, rounding,
                         welcome_bonus_points,
                         earn_rule_json, redeem_rule_json, created_at, updated_at)
                        VALUES
                        (?, 'points', ?, ?,
                         ?, ?,
                         'per_currency', ?, ?,
                         ?, ?,
                         ?, ?, ?, ?,
                         ?,
                         ?, ?, NOW(), NOW())");
                    $ins->execute([
                        $tenantId, $name, $status,
                        $start_at_in, $end_at_in,
                        $earn_rate, $redeem_rate,
                        $min_redeem_points, $max_redeem_percent,
                        $award_timing, $expiry_policy, $expiry_days, $rounding,
                        $welcome_bonus_points,
                        json_encode($earn_rule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        json_encode($redeem_rule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    ]);
                    
                    // Insert tier conditions with period_days
                    $programId = $pdo->lastInsertId();
                    if ($programId && $tier_advancement && $tier_multiplier) {
                        $tierIns = $pdo->prepare("INSERT INTO tier_conditions 
                            (program_id, tier_name, condition_type, threshold_value, period_days) 
                            VALUES (?, ?, ?, ?, ?)");
                        
                        foreach ($tier_multiplier as $tierName => $tierData) {
                            $tierIns->execute([
                                $programId,
                                $tierName,
                                $tier_advancement,
                                $tierData['threshold'],
                                $tierData['period_days']
                            ]);
                        }
                    }
                    
                    $action_msg = 'New program created successfully.';
                }
                
                $pdo->commit();
                $action_ok = true;
                
                // Redirect to index after success
                header("Location: index.php?program_id={$programId}");
                exit;
                
            } catch (Throwable $e) { 
                $pdo->rollBack(); 
                throw $e; 
            }
        }
    } catch (Throwable $e) { 
        $action_ok = false; 
        $action_msg = 'Action error: ' . $e->getMessage(); 
    }
}

/* Load existing program data if editing */
$editProgram = null;
$editId = $_GET['edit'] ?? $_GET['duplicate'] ?? null;
$isDuplicate = isset($_GET['duplicate']);

if ($editId && $pdo instanceof PDO) {
    try {
        $st = $pdo->prepare("SELECT * FROM loyalty_programs WHERE tenant_id = ? AND id = ? AND program_type = 'points'");
        $st->execute([$tenantId, $editId]);
        $editProgram = $st->fetch(PDO::FETCH_ASSOC);
        
        if ($editProgram) {
            // Load tier conditions
            $tierSt = $pdo->prepare("SELECT tier_name, condition_type, threshold_value, period_days 
                                   FROM tier_conditions WHERE program_id = ? ORDER BY threshold_value");
            $tierSt->execute([$editId]);
            $editProgram['tier_conditions'] = $tierSt->fetchAll(PDO::FETCH_ASSOC);
            
            // Load branch IDs from earn_rule_json
            if (!empty($editProgram['earn_rule_json'])) {
                $earnRule = json_decode($editProgram['earn_rule_json'], true);
                if (isset($earnRule['eligible_branches'])) {
                    if ($earnRule['eligible_branches'] === 'all') {
                        $prefBranchIds = [0];
                    } elseif (is_array($earnRule['eligible_branches'])) {
                        $prefBranchIds = array_map('intval', $earnRule['eligible_branches']);
                        if (empty($prefBranchIds)) {
                            $prefBranchIds = [0];
                        }
                    }
                }
            }
        }
    } catch(Throwable $e) {
        $editProgram = null;
        error_log('Failed to load program: ' . $e->getMessage());
    }
}

$active = 'rewards';
$pageTitle = $editProgram ? ($isDuplicate ? 'Copy Program' : 'Edit Program') : 'Create Program';
$formAction = ($editProgram && !$isDuplicate) ? 'update_version' : 'create_version';

// Prepare JavaScript variables BEFORE outputting HTML
$js_currency = json_encode($currency, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
$js_branches = json_encode($branches, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
$js_pref_branches = json_encode(array_values(array_unique($prefBranchIds)), JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?php echo htmlspecialchars($pageTitle); ?> · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="_shared/styles.css">
<style>
/* Tagbox styles for branch selection */
.tagbox {
    position: relative;
    background: white;
    border: 1px solid var(--ms-gray-60);
    border-radius: var(--ms-radius);
    padding: 8px;
    min-height: 40px;
}
.tagbox .control {
    display: flex;
    gap: 8px;
    margin-bottom: 8px;
}
.tagbox .tag-search {
    flex: 1;
    padding: 6px 8px;
    border: 1px solid var(--ms-gray-40);
    border-radius: 4px;
    font-size: 14px;
}
.tagbox .tags {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    min-height: 24px;
}
.tagbox .tag {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 8px;
    background: var(--ms-blue-lighter);
    color: var(--ms-blue);
    border-radius: 4px;
    font-size: 13px;
    font-weight: 500;
}
.tagbox .tag.tag-all {
    background: var(--ms-green-light);
    color: var(--ms-green);
}
.tagbox .tag button {
    background: none;
    border: none;
    color: inherit;
    opacity: 0.7;
    cursor: pointer;
    font-size: 16px;
    line-height: 1;
    padding: 0;
    margin-left: 4px;
}
.tagbox .tag button:hover {
    opacity: 1;
}
.tagbox .dropdown {
    display: none;
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid var(--ms-gray-60);
    border-radius: var(--ms-radius);
    margin-top: 4px;
    max-height: 200px;
    overflow-y: auto;
    z-index: 1000;
    box-shadow: var(--ms-shadow-2);
}
.tagbox .dropdown.open {
    display: block;
}
.tagbox .dropdown .opt {
    padding: 8px 12px;
    cursor: pointer;
    font-size: 14px;
}
.tagbox .dropdown .opt:hover {
    background: var(--ms-gray-10);
}
.btn.small {
    padding: 4px 12px;
    font-size: 12px;
}
.tier-table-enhanced {
    width: 100%;
    border-collapse: collapse;
    margin-top: 12px;
}
.tier-table-enhanced th {
    background: var(--ms-gray-20);
    padding: 8px;
    text-align: left;
    font-size: 13px;
    font-weight: 600;
    color: var(--ms-gray-130);
    border-bottom: 2px solid var(--ms-gray-60);
}
.tier-table-enhanced td {
    padding: 8px;
    border-bottom: 1px solid var(--ms-gray-30);
}
.tier-table-enhanced input {
    width: 100%;
    padding: 6px 8px;
    border: 1px solid var(--ms-gray-60);
    border-radius: 4px;
    font-size: 14px;
}
.tier-actions {
    margin-top: 12px;
    display: flex;
    align-items: center;
    gap: 16px;
}
.ms-input {
    padding: 8px 12px;
    border: 1px solid var(--ms-gray-60);
    border-radius: var(--ms-radius);
    font-size: 14px;
    width: 100%;
    background: var(--ms-white);
    transition: all 0.2s ease;
    font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
    color: var(--ms-gray-160);
    box-sizing: border-box;
}
.ms-input:focus {
    border-color: var(--ms-blue);
    outline: none;
    box-shadow: 0 0 0 3px var(--ms-blue-lighter);
}
.hint {
    font-size: 12px;
    color: var(--ms-gray-130);
    margin-top: 4px;
}
.form-section {
    margin-bottom: 32px;
    padding-bottom: 32px;
    border-bottom: 1px solid var(--ms-gray-30);
}
.form-section:last-child {
    border-bottom: none;
}
.info-box {
    background: var(--ms-blue-lighter);
    border-left: 4px solid var(--ms-blue);
    padding: 16px;
    margin-bottom: 24px;
    border-radius: 4px;
}
.info-box h4 {
    margin: 0 0 8px 0;
    color: var(--ms-blue);
    font-size: 16px;
}
.info-box p {
    margin: 0;
    color: var(--ms-gray-130);
    font-size: 14px;
}
.helper {
    font-size: 12px;
    color: var(--ms-gray-100);
}
.btn.small.danger {
    background: var(--ms-red);
    color: white;
}
.btn.small.danger:hover {
    background: var(--ms-red-dark);
}
</style>
</head>
<body>

<?php 
$nav_included = include_admin_nav('rewards');
if (!$nav_included) {
    echo '<div class="notice alert-error">Navigation component not found.</div>';
}
?>

<div class="container">
  <div class="h1"><?php echo htmlspecialchars($pageTitle); ?></div>
  <p class="sub">Configure loyalty program settings and tier structure with our intuitive setup wizard.</p>

  <?php if ($bootstrap_warning): ?>
    <div class="notice alert-error"><?php echo htmlspecialchars($bootstrap_warning); ?></div>
  <?php endif; ?>
  
  <?php if ($action_msg): ?>
    <div class="notice <?php echo $action_ok ? 'alert-ok' : 'alert-error'; ?>"><?php echo htmlspecialchars($action_msg); ?></div>
  <?php endif; ?>

  <?php render_points_nav($editProgram && !$isDuplicate ? 'edit' : 'create'); ?>

  <form method="post" id="form-new" class="card">
    <input type="hidden" name="action" value="<?php echo htmlspecialchars($formAction); ?>">
    <?php if ($editProgram && !$isDuplicate): ?>
      <input type="hidden" name="program_id" value="<?php echo (int)$editProgram['id']; ?>">
    <?php endif; ?>
    <input type="hidden" id="new_start_at" name="new_start_at">
    <input type="hidden" id="new_end_at" name="new_end_at">

    <!-- Basic Setup Section -->
    <div class="form-section">
      <div class="info-box">
        <h4>Program Foundation</h4>
        <p>Start with the basics - give your program a name and set when it should be active.</p>
      </div>
      
      <!-- Program Name and Status on same line -->
      <div style="display: flex; gap: 20px; margin-bottom: 20px;">
        <div style="flex: 1;">
          <label for="nv_name" style="display: block; margin-bottom: 6px; font-weight: 600; font-size: 14px; color: var(--ms-gray-160);">Program Name *</label>
          <input id="nv_name" name="new_name" class="ms-input" placeholder="e.g., VIP Rewards 2025" required
                 value="<?php echo $editProgram ? htmlspecialchars($editProgram['name'] . ($isDuplicate ? ' (Copy)' : '')) : ''; ?>">
          <div class="hint">Choose a memorable name that customers will recognize</div>
        </div>
        
        <div style="flex: 1;">
          <label for="nv_status">Program Status</label>
          <select id="nv_status" name="new_status" class="ms-input">
            <option value="active" <?php echo (!$editProgram || $editProgram['status'] === 'active') ? 'selected' : ''; ?>>Active (Live immediately)</option>
            <option value="inactive" <?php echo ($editProgram && $editProgram['status'] === 'inactive') ? 'selected' : ''; ?>>Draft (Save for later)</option>
          </select>
          <div class="hint">Active programs start earning points right away</div>
        </div>
      </div>

      <!-- Branch Selection -->
      <div style="margin-bottom: 20px;">
        <label>Branches *</label>
        <div class="tagbox" data-name="branch_ids[]" data-type="branches">
          <div class="control">
            <input type="text" class="tag-search" placeholder="Search branches or type 'All Branches'...">
            <button type="button" class="btn small js-clear">Clear</button>
            <button type="button" class="btn small js-add-all">All Branches</button>
          </div>
          <div class="dropdown"></div>
          <div class="tags"></div>
        </div>
        <div class="hint">Select branches where this program will be active. Use "All Branches" for company-wide programs.</div>
      </div>
      
      <!-- Duration Settings on same line -->
      <div style="display: flex; gap: 20px; margin-bottom: 20px;">
        <div style="flex: 1;">
          <label for="start_dmy">Start Date *</label>
          <input id="start_dmy" type="date" class="ms-input" required
                 value="<?php echo $editProgram ? date('Y-m-d', strtotime($editProgram['start_at'] ?? 'today')) : date('Y-m-d'); ?>">
          <div class="hint">When customers can start earning points</div>
        </div>
        
        <div style="flex: 1;">
          <label for="end_dmy">End Date (Optional)</label>
          <input id="end_dmy" type="date" class="ms-input date-calculated"
                 value="<?php echo $editProgram && $editProgram['end_at'] ? date('Y-m-d', strtotime($editProgram['end_at'])) : date('Y-m-d', strtotime('+90 days')); ?>">
          <div class="hint">Auto-set to 90 days from start, leave blank for no end</div>
        </div>
      </div>
      
      <!-- Notes -->
      <div style="margin-bottom: 20px;">
        <label for="nv_notes">Internal Notes (Optional)</label>
        <textarea id="nv_notes" name="desc" class="ms-input" rows="3" placeholder="Any notes for your team about this program..."><?php
          if ($editProgram && !empty($editProgram['earn_rule_json'])) {
            $rule = json_decode($editProgram['earn_rule_json'], true);
            echo htmlspecialchars($rule['description'] ?? '');
          }
        ?></textarea>
        <div class="hint">These notes are only visible to staff, not customers</div>
      </div>
    </div>

    <!-- Earning Rules Section -->
    <div class="form-section">
      <div class="info-box">
        <h4>Points Earning System</h4>
        <p>Configure how customers accumulate points through their purchases and activity.</p>
      </div>
      
      <!-- Welcome Bonus and Earn Rate on same line -->
      <div style="display: flex; gap: 20px; margin-bottom: 20px;">
        <div style="flex: 1;">
          <label for="welcome_bonus_points">Welcome Bonus Points</label>
          <input id="welcome_bonus_points" name="welcome_bonus_points" type="number" min="0" step="1" class="ms-input"
                 value="<?php echo $editProgram ? (int)($editProgram['welcome_bonus_points'] ?? 0) : 0; ?>">
          <div class="hint">One-time bonus when customers first join</div>
        </div>

        <div style="flex: 1;">
          <label for="nv_earn">Points Per Unit Spent *</label>
          <input id="nv_earn" type="number" step="1" min="1" name="new_earn_rate" class="ms-input" required
                 value="<?php echo $editProgram ? (int)($editProgram['earn_rate'] ?? 1) : 1; ?>">
          <div class="hint">Base earning rate (e.g., 1 = 1 point per dollar)</div>
        </div>
      </div>
      
      <!-- Award Timing - radio buttons on same line -->
      <div style="margin-bottom: 20px;">
        <label>When Points Are Awarded</label>
        <?php $aw = $editProgram ? ($editProgram['award_timing'] ?? 'on_payment') : 'on_payment'; ?>
        <div style="display: flex; gap: 30px; margin-top: 8px;">
          <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: normal;">
            <input type="radio" name="new_award_timing" value="on_payment" 
                   <?php echo $aw === 'on_payment' ? 'checked' : ''; ?>>
            <span>On Payment</span>
          </label>
          <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: normal;">
            <input type="radio" name="new_award_timing" value="on_order_complete" 
                   <?php echo ($aw === 'on_order_complete' || $aw === 'on_close') ? 'checked' : ''; ?>>
            <span>On Order Complete</span>
          </label>
        </div>
        <div class="hint">Choose when customers receive their points</div>
      </div>
      
      <!-- Channel and Exclusion Settings on same line -->
      <div style="display: flex; gap: 20px; margin-bottom: 20px;">
        <div style="flex: 1;">
          <label>Eligible Purchase Channels</label>
          <?php 
          $channels = ['pos' => true, 'online' => true, 'aggregator' => false];
          if ($editProgram && !empty($editProgram['earn_rule_json'])) {
            $rule = json_decode($editProgram['earn_rule_json'], true);
            if (isset($rule['eligible_channels']) && is_array($rule['eligible_channels'])) {
              $channels = ['pos' => false, 'online' => false, 'aggregator' => false];
              foreach ($rule['eligible_channels'] as $ch) {
                $channels[$ch] = true;
              }
            }
          }
          ?>
          <div style="display: flex; gap: 15px; margin-top: 8px; flex-wrap: wrap;">
            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: normal;">
              <input type="checkbox" name="channels[]" value="pos" <?php echo $channels['pos'] ? 'checked' : ''; ?>>
              <span>In-Store (POS)</span>
            </label>
            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: normal;">
              <input type="checkbox" name="channels[]" value="online" <?php echo $channels['online'] ? 'checked' : ''; ?>>
              <span>Online Orders</span>
            </label>
            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: normal;">
              <input type="checkbox" name="channels[]" value="aggregator" <?php echo $channels['aggregator'] ? 'checked' : ''; ?>>
              <span>Delivery Apps</span>
            </label>
          </div>
        </div>
        
        <div style="flex: 1;">
          <label>Earning Exclusions</label>
          <?php 
          $excl_agg = false;
          $excl_disc = false;
          if ($editProgram && !empty($editProgram['earn_rule_json'])) {
            $rule = json_decode($editProgram['earn_rule_json'], true);
            $excl_agg = $rule['exclude_aggregators'] ?? false;
            $excl_disc = $rule['exclude_discounted_orders'] ?? false;
          }
          ?>
          <div style="display: flex; gap: 15px; margin-top: 8px; flex-wrap: wrap;">
            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: normal;">
              <input type="checkbox" name="excl_aggregators" value="1" <?php echo $excl_agg ? 'checked' : ''; ?>>
              <span>Exclude aggregators</span>
            </label>
            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: normal;">
              <input type="checkbox" name="excl_discounted" value="1" <?php echo $excl_disc ? 'checked' : ''; ?>>
              <span>Exclude discounted</span>
            </label>
          </div>
          <div class="hint">Control which types of orders don't earn points</div>
        </div>
      </div>
    </div>

    <!-- Tiers & Redemption Section -->
    <div class="form-section">
      <div class="info-box">
        <h4>Redemption & Tier Settings</h4>
        <p>Set up how customers can spend their points and create membership tiers with special benefits.</p>
      </div>
      
      <!-- All 4 redemption settings on same line -->
      <div style="display: flex; gap: 15px; margin-bottom: 20px;">
        <div style="flex: 1;">
          <label for="nv_redeem">Point Value *</label>
          <input id="nv_redeem" type="number" step="1" min="1" name="new_redeem_rate" class="ms-input" required
                 value="<?php echo $editProgram ? (int)($editProgram['redeem_rate'] ?? 10) : 10; ?>">
          <div class="hint">Points = 1 unit discount</div>
        </div>
        
        <div style="flex: 1;">
          <label for="nv_minp">Min Points to Redeem</label>
          <input id="nv_minp" type="number" min="0" step="1" name="new_min_redeem_points" class="ms-input" placeholder="e.g., 100"
                 value="<?php echo $editProgram ? ($editProgram['min_redeem_points'] ?? 100) : 100; ?>">
          <div class="hint">Minimum threshold</div>
        </div>
        
        <div style="flex: 1;">
          <label for="nv_maxp">Max Order Coverage (%)</label>
          <input id="nv_maxp" type="number" step="1" min="0" max="100" name="new_max_redeem_percent" class="ms-input" placeholder="e.g., 50"
                 value="<?php echo $editProgram ? ($editProgram['max_redeem_percent'] ?? 50) : 50; ?>">
          <div class="hint">Max % payable with points</div>
        </div>
        
        <div style="flex: 1;">
          <label for="tier_advancement">Tier Advancement Based On</label>
          <?php 
          $tier_advancement_type = 'spending';
          if ($editProgram && !empty($editProgram['earn_rule_json'])) {
            $rule = json_decode($editProgram['earn_rule_json'], true);
            $tier_advancement_type = $rule['tier_advancement'] ?? 'spending';
          }
          ?>
          <select id="tier_advancement" name="tier_advancement" class="ms-input">
            <option value="spending" <?php echo $tier_advancement_type === 'spending' ? 'selected' : ''; ?>>Total Spending</option>
            <option value="points" <?php echo $tier_advancement_type === 'points' ? 'selected' : ''; ?>>Points Earned</option>
            <option value="visits" <?php echo $tier_advancement_type === 'visits' ? 'selected' : ''; ?>>Purchase Count</option>
          </select>
          <div class="hint">How customers advance tiers</div>
        </div>
      </div>
      
      <!-- Tier Configuration -->
      <div style="margin-top: 32px;">
        <label>Membership Tiers Configuration</label>
        
        <div class="tier-wrap">
          <table class="tier-table-enhanced">
            <thead>
              <tr>
                <th style="width: 30%">Tier Name</th>
                <th style="width: 25%">Multiplier</th>
                <th style="width: 25%">Threshold</th>
                <th style="width: 15%">Window (Days)</th>
                <th style="width: 5%"></th>
              </tr>
            </thead>
            <tbody id="tierTbody">
              <?php
              // Load existing tiers or defaults
              $tiers = [];
              if ($editProgram && !empty($editProgram['tier_conditions'])) {
                foreach ($editProgram['tier_conditions'] as $tc) {
                  $mult = 1.0;
                  if (!empty($editProgram['earn_rule_json'])) {
                    $rule = json_decode($editProgram['earn_rule_json'], true);
                    if (isset($rule['tier_multiplier'][$tc['tier_name']])) {
                      $mult = $rule['tier_multiplier'][$tc['tier_name']]['multiplier'] ?? 1.0;
                    }
                  }
                  $tiers[] = [
                    'name' => $tc['tier_name'],
                    'multiplier' => $mult,
                    'threshold' => $tc['threshold_value'],
                    'period_days' => $tc['period_days']
                  ];
                }
              }
              
              if (empty($tiers)) {
                $tiers = [
                  ['name' => 'Bronze', 'multiplier' => 1.00, 'threshold' => 0, 'period_days' => 365],
                  ['name' => 'Silver', 'multiplier' => 1.25, 'threshold' => 500, 'period_days' => 365],
                  ['name' => 'Gold', 'multiplier' => 1.50, 'threshold' => 2000, 'period_days' => 365]
                ];
              }
              
              foreach ($tiers as $index => $tier):
              ?>
              <tr>
                <td>
                  <input type="text" name="tiers_name[]" class="ms-input" 
                         value="<?php echo htmlspecialchars($tier['name']); ?>" 
                         required placeholder="e.g., Bronze">
                </td>
                <td>
                  <input type="number" name="tiers_mult[]" class="ms-input" 
                         value="<?php echo number_format($tier['multiplier'], 2); ?>" 
                         step="0.1" min="0.1" required>
                </td>
                <td>
                  <input type="number" name="tiers_threshold[]" class="ms-input" 
                         value="<?php echo (int)$tier['threshold']; ?>" 
                         min="0" step="1" placeholder="0">
                </td>
                <td>
                  <input type="number" name="tiers_period_days[]" class="ms-input" 
                         value="<?php echo (int)$tier['period_days']; ?>" 
                         min="1" step="1" placeholder="365">
                </td>
                <td>
                  <?php if ($index > 0): ?>
                    <button class="btn small danger js-tier-del" type="button" title="Remove this tier" style="padding: 4px 8px;">×</button>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <div class="tier-actions">
            <button class="btn small primary" type="button" id="js-tier-add">Add New Tier</button>
            <span class="helper">Maximum 6 tiers recommended for best user experience</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Form Footer -->
    <div style="padding: 24px 0; text-align: right; display: flex; gap: 12px; justify-content: flex-end; margin-top: 32px;">
      <a href="index.php" class="btn">Cancel</a>
      <button class="btn primary" type="submit"><?php echo $editProgram && !$isDuplicate ? 'Update Program' : 'Save Program'; ?></button>
    </div>
  </form>
</div>

<script>
(function() {
    'use strict';
    
    // Initialize global configuration using pre-encoded JSON
    window.LOYALTY_CONFIG = {
        currency: <?php echo $js_currency; ?>,
        maxTiers: 6,
        defaultEndDateDays: 90
    };
    
    window.BRANCHES = <?php echo $js_branches; ?>;
    const PREF_BRANCHES = <?php echo $js_pref_branches; ?>;
    
    // Debug logging
    console.log('Loyalty Program System - Starting initialization...');
    console.log('Currency:', window.LOYALTY_CONFIG.currency);
    console.log('Branches loaded:', window.BRANCHES.length);
    console.log('Preselected branches:', PREF_BRANCHES);
    
    // TagDropdown class definition
    class TagDropdown {
        constructor(container) {
            this.container = container;
            this.name = container.dataset.name;
            this.type = container.dataset.type || 'default';
            this.input = container.querySelector('.tag-search');
            this.dropdown = container.querySelector('.dropdown');
            this.tagsContainer = container.querySelector('.tags');
            this.clearBtn = container.querySelector('.js-clear');
            this.addAllBtn = container.querySelector('.js-add-all');
            this.selected = new Map();
            
            this.init();
        }
        
        init() {
            if (!this.input || !this.dropdown || !this.tagsContainer) return;
            
            this.input.addEventListener('focus', () => this.openDropdown());
            this.input.addEventListener('input', () => this.filterOptions());
            
            if (this.clearBtn) {
                this.clearBtn.addEventListener('click', () => this.clearAll());
            }
            
            if (this.addAllBtn) {
                this.addAllBtn.addEventListener('click', () => {
                    this.clearAll();
                    this.addItem(0, 'All Branches');
                });
            }
            
            document.addEventListener('click', (e) => {
                if (!this.container.contains(e.target)) {
                    this.closeDropdown();
                }
            });
        }
        
        openDropdown() {
            this.dropdown.classList.add('open');
            this.filterOptions();
        }
        
        closeDropdown() {
            this.dropdown.classList.remove('open');
        }
        
        filterOptions() {
            const query = this.input.value.trim().toLowerCase();
            let filtered = [];
            
            if (this.type === 'branches') {
                // Add "All Branches" option if searching for it
                if (!query || 'all branches'.includes(query)) {
                    if (!this.selected.has(0)) {
                        filtered.push({ id: 0, name: 'All Branches' });
                    }
                }
                
                // Add regular branches
                const branches = window.BRANCHES || [];
                const branchFiltered = branches.filter(b => 
                    b.id !== 0 && 
                    b.name.toLowerCase().includes(query) && 
                    !this.selected.has(b.id)
                );
                filtered = filtered.concat(branchFiltered);
            }
            
            if (filtered.length === 0) {
                this.dropdown.innerHTML = '<div class="opt" style="cursor:default">No matches found</div>';
            } else {
                this.dropdown.innerHTML = filtered.map(item => 
                    '<div class="opt" data-id="' + item.id + '" data-name="' + this.escapeHtml(item.name) + '">' +
                        this.escapeHtml(item.name) +
                    '</div>'
                ).join('');
                
                // Bind click handlers
                this.dropdown.querySelectorAll('.opt[data-id]').forEach(opt => {
                    opt.addEventListener('click', () => {
                        const id = parseInt(opt.getAttribute('data-id'));
                        const name = opt.getAttribute('data-name');
                        this.addItem(id, name);
                    });
                });
            }
        }
        
        addItem(id, name) {
            // For branches: if "All Branches" is selected, clear others
            if (this.type === 'branches' && id === 0) {
                this.clearAll(false);
            } else if (this.type === 'branches' && this.selected.has(0)) {
                // If adding a specific branch but "All Branches" is selected, remove "All Branches"
                this.selected.delete(0);
            }
            
            this.selected.set(id, { id, name });
            this.input.value = '';
            this.closeDropdown();
            this.renderTags();
            this.syncHiddenInputs();
        }
        
        removeItem(id) {
            this.selected.delete(id);
            this.renderTags();
            this.syncHiddenInputs();
            
            // If no branches selected, default to "All Branches"
            if (this.type === 'branches' && this.selected.size === 0) {
                this.addItem(0, 'All Branches');
            }
        }
        
        clearAll(addDefault = true) {
            this.selected.clear();
            this.input.value = '';
            this.renderTags();
            this.syncHiddenInputs();
            
            // For branches, add "All Branches" after clearing
            if (this.type === 'branches' && addDefault) {
                setTimeout(() => {
                    this.addItem(0, 'All Branches');
                }, 0);
            }
        }
        
        renderTags() {
            const tags = Array.from(this.selected.values()).map(item => {
                const tagClass = this.type === 'branches' && item.id === 0 ? 'tag tag-all' : 'tag';
                return '<span class="' + tagClass + '">' +
                        '<span>' + this.escapeHtml(item.name) + '</span>' +
                        '<button type="button" data-id="' + item.id + '" aria-label="Remove">×</button>' +
                    '</span>';
            }).join('');
            
            this.tagsContainer.innerHTML = tags;
            
            // Bind remove handlers
            this.tagsContainer.querySelectorAll('button').forEach(btn => {
                btn.addEventListener('click', () => {
                    const id = parseInt(btn.getAttribute('data-id'));
                    this.removeItem(id);
                });
            });
        }
        
        syncHiddenInputs() {
            // Remove old hidden inputs
            this.container.querySelectorAll('input[type="hidden"][data-tag]').forEach(input => {
                input.remove();
            });
            
            // Add new hidden inputs
            this.selected.forEach(item => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = this.name;
                input.value = item.id.toString();
                input.setAttribute('data-tag', '1');
                this.container.appendChild(input);
            });
        }
        
        escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }
    }
    
    // Initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        console.log('DOM Loaded - Initializing components...');
        
        // Initialize branch tagbox with preselected values
        const branchTagbox = document.querySelector('.tagbox[data-type="branches"]');
        if (branchTagbox) {
            const tagDropdown = new TagDropdown(branchTagbox);
            
            // Add preselected branches
            if (PREF_BRANCHES && PREF_BRANCHES.length > 0) {
                PREF_BRANCHES.forEach(id => {
                    if (id === 0) {
                        tagDropdown.addItem(0, 'All Branches');
                    } else {
                        const branch = window.BRANCHES.find(b => b.id === id);
                        if (branch) {
                            tagDropdown.addItem(branch.id, branch.name);
                        }
                    }
                });
            } else {
                // Default to all branches if nothing selected
                tagDropdown.addItem(0, 'All Branches');
            }
        }
        
        // Initialize Add Tier Button
        const addButton = document.getElementById('js-tier-add');
        const tierTbody = document.getElementById('tierTbody');
        
        if (addButton && tierTbody) {
            addButton.addEventListener('click', function() {
                const currentTiers = tierTbody.querySelectorAll('tr').length;
                
                if (currentTiers >= 6) {
                    alert('Maximum 6 tiers recommended for simplicity.');
                    return;
                }
                
                const defaultNames = ['Bronze', 'Silver', 'Gold', 'Platinum', 'Diamond', 'Elite'];
                const defaultName = defaultNames[currentTiers] || 'Tier ' + (currentTiers + 1);
                const defaultMult = 1 + (currentTiers * 0.25);
                const defaultThreshold = currentTiers * 1000;
                
                const tr = document.createElement('tr');
                tr.innerHTML = 
                    '<td><input type="text" name="tiers_name[]" class="ms-input" required placeholder="Tier name" value="' + defaultName + '"></td>' +
                    '<td><input type="number" name="tiers_mult[]" class="ms-input" value="' + defaultMult.toFixed(2) + '" step="0.1" min="0.1" required></td>' +
                    '<td><input type="number" name="tiers_threshold[]" class="ms-input" value="' + defaultThreshold + '" min="0" step="1" placeholder="Threshold"></td>' +
                    '<td><input type="number" name="tiers_period_days[]" class="ms-input" value="365" min="1" step="1" placeholder="365"></td>' +
                    '<td><button class="btn small danger js-tier-del" type="button" title="Remove this tier" style="padding: 4px 8px;">×</button></td>';
                
                tierTbody.appendChild(tr);
                
                // Focus on the new tier name input
                const newNameInput = tr.querySelector('input[name="tiers_name[]"]');
                if (newNameInput) {
                    newNameInput.focus();
                    newNameInput.select();
                }
            });
        }
        
        // Initialize Delete Tier Buttons
        document.addEventListener('click', function(e) {
            if (e.target && e.target.classList && e.target.classList.contains('js-tier-del')) {
                const tr = e.target.closest('tr');
                const tierTbody = document.getElementById('tierTbody');
                
                if (!tr || !tierTbody) return;
                
                if (tierTbody.querySelectorAll('tr').length <= 1) {
                    alert('At least one tier is required.');
                    return;
                }
                
                const tierName = tr.querySelector('input[name="tiers_name[]"]');
                const name = tierName ? tierName.value : 'this tier';
                
                if (confirm('Remove "' + name + '"?\n\nThis action cannot be undone.')) {
                    tr.remove();
                }
            }
        });
        
        // Auto-calculate end date (90 days from start)
        const startDateInput = document.getElementById('start_dmy');
        const endDateInput = document.getElementById('end_dmy');
        
        if (startDateInput && endDateInput) {
            startDateInput.addEventListener('change', function() {
                const startDate = new Date(this.value);
                if (!isNaN(startDate.getTime())) {
                    const endDate = new Date(startDate);
                    endDate.setDate(endDate.getDate() + 90);
                    endDateInput.value = endDate.toISOString().split('T')[0];
                    endDateInput.classList.add('date-calculated');
                }
            });
        }
        
        // Form submission handler
        const form = document.getElementById('form-new');
        if (form) {
            form.addEventListener('submit', function(e) {
                // Validate branch selection
                const branchInputs = form.querySelectorAll('input[name="branch_ids[]"]');
                if (branchInputs.length === 0) {
                    e.preventDefault();
                    alert('Please select at least one branch.');
                    return;
                }
                
                // Set normalized dates
                const startPicker = document.getElementById('start_dmy');
                const endPicker = document.getElementById('end_dmy');
                
                if (startPicker) {
                    const v = startPicker.value.trim();
                    document.getElementById('new_start_at').value = v ? v + ' 00:00:00' : '';
                }
                if (endPicker) {
                    const v = endPicker.value.trim();
                    document.getElementById('new_end_at').value = v ? v + ' 00:00:00' : '';
                }
                
                // Show loading state
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.textContent = 'Saving...';
                    submitBtn.disabled = true;
                }
            });
        }
        
        console.log('Loyalty Program System - Ready!');
    });
})();
</script>
</body>
</html>