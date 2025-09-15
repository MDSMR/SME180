<?php
// /views/admin/rewards/cashback/create.php
// Complete Cashback Program Create/Edit Page - Final Version
declare(strict_types=1);

require_once __DIR__ . '/_shared/common.php';

if (!$bootstrap_ok) {
    http_response_code(500);
    echo '<h1>Bootstrap Failed</h1><p>' . htmlspecialchars($bootstrap_warning) . '</p>';
    exit;
}

// Initialize preselected branch IDs early
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
            
            $award_timing = in_array(($_POST['new_award_timing'] ?? 'on_payment'), ['on_payment','on_order_complete','on_close'], true) 
                ? $_POST['new_award_timing'] : 'on_payment';
            
            $rounding = $_POST['new_rounding'] ?? 'floor';
            $max_redeem_percent = ($_POST['new_max_redeem_percent'] === '' ? null : (float)$_POST['new_max_redeem_percent']);
            $min_redeem_amount = ($_POST['new_min_redeem_amount'] === '' ? null : (float)$_POST['new_min_redeem_amount']);
            $min_visit_redeem = (int)($_POST['min_visit_redeem'] ?? 2);
            $wallet_expiry_days = (int)($_POST['wallet_expiry_days'] ?? 0);
            
            // Process branch IDs
            $branch_ids = isset($_POST['branch_ids']) && is_array($_POST['branch_ids']) 
                ? array_map('intval', $_POST['branch_ids']) : [0];
            
            // Process visit ladder
            $ladder = [];
            if (isset($_POST['ladder']) && is_array($_POST['ladder'])) {
                foreach ($_POST['ladder'] as $step) {
                    if (!empty($step['visit']) && isset($step['rate_pct']) && isset($step['valid_days'])) {
                        $ladder[] = [
                            'visit' => trim($step['visit']),
                            'percent' => (float)$step['rate_pct'],
                            'expires_days' => (int)$step['valid_days']
                        ];
                    }
                }
            }
            
            // Sort ladder by visit number
            usort($ladder, function($a, $b) {
                $aNum = intval($a['visit']);
                $bNum = intval($b['visit']);
                return $aNum - $bNum;
            });
            
            // Process channels and exclusions
            $channels_in = $_POST['channels'] ?? ['pos', 'online'];
            if (!is_array($channels_in)) $channels_in = ['pos', 'online'];
            $channels_in = array_values(array_intersect(array_map('strval', $channels_in), ['pos', 'online', 'aggregator']));
            if (!$channels_in) $channels_in = ['pos', 'online'];
            
            $exclude_aggregators = isset($_POST['excl_aggregators']) && $_POST['excl_aggregators'] === '1';
            $exclude_discounted = isset($_POST['excl_discounted']) && $_POST['excl_discounted'] === '1';
            $desc = trim((string)($_POST['desc'] ?? ''));

            // Build earn_rule with branches and ladder
            $earn_rule = [
                'basis' => 'subtotal_excl_tax_service',
                'ladder' => $ladder,
                'min_visit_to_redeem' => $min_visit_redeem,
                'expiry' => ['days' => $wallet_expiry_days],
                'eligible_branches' => in_array(0, $branch_ids) ? 'all' : $branch_ids,
                'eligible_channels' => $channels_in,
                'exclude_aggregators' => $exclude_aggregators,
                'exclude_discounted_orders' => $exclude_discounted
            ];
            if ($desc !== '') $earn_rule['description'] = $desc;
            
            $redeem_rule = [
                'max_percent' => $max_redeem_percent,
                'min_amount' => $min_redeem_amount
            ];

            $pdo->beginTransaction();
            try {
                if ($act === 'update_version' && isset($_POST['program_id'])) {
                    // Update existing program
                    $programId = (int)$_POST['program_id'];
                    
                    $upd = $pdo->prepare("UPDATE loyalty_programs SET 
                        name = ?, status = ?, start_at = ?, end_at = ?,
                        max_redeem_percent = ?, min_redeem_points = ?,
                        award_timing = ?, rounding = ?,
                        earn_rule_json = ?, redeem_rule_json = ?, updated_at = NOW()
                        WHERE id = ? AND tenant_id = ? AND program_type = 'cashback'");
                    $upd->execute([
                        $name, $status, $start_at_in, $end_at_in,
                        $max_redeem_percent, $min_redeem_amount,
                        $award_timing, $rounding,
                        json_encode($earn_rule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        json_encode($redeem_rule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        $programId, $tenantId
                    ]);
                    
                    $action_msg = 'Program updated successfully.';
                } else {
                    // Create new program
                    $ins = $pdo->prepare("INSERT INTO loyalty_programs
                        (tenant_id, program_type, name, status,
                         start_at, end_at,
                         max_redeem_percent, min_redeem_points,
                         award_timing, rounding,
                         earn_rule_json, redeem_rule_json, created_at, updated_at)
                        VALUES
                        (?, 'cashback', ?, ?,
                         ?, ?,
                         ?, ?,
                         ?, ?,
                         ?, ?, NOW(), NOW())");
                    $ins->execute([
                        $tenantId, $name, $status,
                        $start_at_in, $end_at_in,
                        $max_redeem_percent, $min_redeem_amount,
                        $award_timing, $rounding,
                        json_encode($earn_rule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        json_encode($redeem_rule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    ]);
                    
                    $programId = $pdo->lastInsertId();
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
        $st = $pdo->prepare("SELECT * FROM loyalty_programs WHERE tenant_id = ? AND id = ? AND program_type = 'cashback'");
        $st->execute([$tenantId, $editId]);
        $editProgram = $st->fetch(PDO::FETCH_ASSOC);
        
        if ($editProgram) {
            // Load branch IDs and ladder from earn_rule_json
            if (!empty($editProgram['earn_rule_json'])) {
                $earnRule = json_decode($editProgram['earn_rule_json'], true);
                
                // Load branch IDs
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
$pageTitle = $editProgram ? ($isDuplicate ? 'Copy Cashback Program' : 'Edit Cashback Program') : 'Create Cashback Program';
$formAction = ($editProgram && !$isDuplicate) ? 'update_version' : 'create_version';

// Prepare default ladder if not editing
$defaultLadder = [
    ['visit' => '1', 'rate_pct' => 10.0, 'valid_days' => 14],
    ['visit' => '2', 'rate_pct' => 15.0, 'valid_days' => 20],
    ['visit' => '3', 'rate_pct' => 20.0, 'valid_days' => 25],
    ['visit' => '4+', 'rate_pct' => 25.0, 'valid_days' => 30]
];

$prefLadder = $defaultLadder;
if ($editProgram && !empty($editProgram['earn_rule_json'])) {
    $earnRule = json_decode($editProgram['earn_rule_json'], true);
    if (isset($earnRule['ladder']) && is_array($earnRule['ladder'])) {
        $prefLadder = [];
        foreach ($earnRule['ladder'] as $step) {
            $prefLadder[] = [
                'visit' => $step['visit'] ?? '',
                'rate_pct' => isset($step['percent']) ? (float)$step['percent'] : 0,
                'valid_days' => isset($step['expires_days']) ? (int)$step['expires_days'] : 14
            ];
        }
    }
}

// Prepare JavaScript variables
$js_branches = json_encode($branches, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
$js_pref_branches = json_encode(array_values(array_unique($prefBranchIds)), JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?php echo htmlspecialchars($pageTitle); ?> · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="../points/_shared/styles.css?v=<?= time() ?>">
<style>
/* Cashback-specific styles */
.ladder-wrap {
    border: 1px solid var(--ms-gray-30);
    border-radius: var(--ms-radius-lg);
    overflow: hidden;
    background: var(--ms-white);
    box-shadow: var(--ms-shadow-1);
}

.ladder-table {
    width: 100%;
    border-collapse: collapse;
}

.ladder-table th {
    background: linear-gradient(135deg, var(--ms-gray-20) 0%, var(--ms-gray-30) 100%);
    padding: 16px 12px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--ms-gray-130);
    border-bottom: 1px solid var(--ms-gray-30);
    letter-spacing: 0.5px;
    text-align: center; /* Center align headers */
}

.ladder-table td {
    padding: 12px;
    border-bottom: 1px solid var(--ms-gray-20);
    vertical-align: middle;
    text-align: center; /* Center align content */
}

.ladder-table tbody tr:last-child td {
    border-bottom: none;
}

.ladder-table tbody tr:hover {
    background: var(--ms-gray-10);
}

.ladder-table input {
    margin: 0 auto;
    min-height: 32px;
    text-align: center;
}

.ladder-actions {
    padding: 16px;
    background: linear-gradient(135deg, var(--ms-gray-10) 0%, var(--ms-gray-20) 100%);
    border-top: 1px solid var(--ms-gray-30);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

/* Tag Dropdown */
.tagbox {
    position: relative;
    margin-bottom: 0;
}

.tagbox .control {
    display: flex;
    gap: 8px;
    margin-bottom: 8px;
}

.tagbox .tag-search {
    flex: 1;
}

.tagbox .dropdown {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    background: var(--ms-white);
    border: 1px solid var(--ms-gray-60);
    border-radius: var(--ms-radius);
    box-shadow: var(--ms-shadow-2);
    max-height: 300px;
    overflow-y: auto;
    z-index: 100;
    display: none;
}

.tagbox .dropdown.open {
    display: block;
}

.tagbox .dropdown .opt {
    padding: 10px 12px;
    cursor: pointer;
    transition: all 0.1s ease;
}

.tagbox .dropdown .opt:hover {
    background: var(--ms-blue-lighter);
    color: var(--ms-blue);
}

.tagbox .tags {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.tagbox .tag {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 10px;
    background: var(--ms-blue-lighter);
    border: 1px solid var(--ms-blue);
    color: var(--ms-blue);
    border-radius: var(--ms-radius);
    font-size: 13px;
    font-weight: 600;
}

.tagbox .tag.tag-all {
    background: var(--ms-green-light);
    border-color: var(--ms-green);
    color: var(--ms-green);
}

.tagbox .tag button {
    background: none;
    border: none;
    color: inherit;
    font-size: 16px;
    cursor: pointer;
    padding: 0;
    width: 16px;
    height: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Optimized form layout */
.form-row-3 {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-bottom: 20px;
}

/* Two-column rows */
.form-row-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

/* Center only the Ladder section title */
.info-box h4.center {
    text-align: center;
}

/* Checkbox layout improvements */
.checkbox-group {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-top: 8px;
}

/* NEW helper to place options on the same line */
.checkbox-row {
    display: flex;
    gap: 24px;
    align-items: center;
    flex-wrap: wrap; /* allows wrap on very small screens */
}

.checkbox-option {
    display: flex;
    align-items: center;
    gap: 8px;
}

.checkbox-option input[type="checkbox"] {
    width: 18px;
    height: 18px;
    margin: 0;
}

.checkbox-option label {
    margin: 0;
    font-size: 14px;
    cursor: pointer;
}

@media (max-width: 768px) {
    .form-row-3 {
        grid-template-columns: 1fr;
    }
    .form-row-2 {
        grid-template-columns: 1fr; /* Stack on small screens */
    }
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
    <p class="sub">Configure cashback rates based on customer visit frequency.</p>

    <?php if ($bootstrap_warning): ?>
        <div class="notice alert-error"><?php echo htmlspecialchars($bootstrap_warning); ?></div>
    <?php endif; ?>
    
    <?php if ($action_msg): ?>
        <div class="notice <?php echo $action_ok ? 'alert-ok' : 'alert-error'; ?>"><?php echo htmlspecialchars($action_msg); ?></div>
    <?php endif; ?>

    <!-- Navigation Tabs - Fixed: Create Program should be active -->
    <div class="points-nav">
        <a href="index.php" class="points-nav-tab">Programs</a>
        <a href="create.php" class="points-nav-tab active">Create Program</a>
        <a href="reports.php" class="points-nav-tab">Reports</a>
    </div>

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
                <p>Define your cashback program's basic settings and schedule.</p>
            </div>
            
            <!-- Program Name, Status and Branches on same line -->
            <div class="form-row-3">
                <div>
                    <label for="nv_name">Program Name *</label>
                    <input id="nv_name" name="new_name" class="ms-input" placeholder="e.g., Summer Cashback 2025" required
                           value="<?php echo $editProgram ? htmlspecialchars($editProgram['name'] . ($isDuplicate ? ' (Copy)' : '')) : ''; ?>">
                    <div class="hint">Choose a memorable name</div>
                </div>
                
                <div>
                    <label for="nv_status">Program Status</label>
                    <select id="nv_status" name="new_status" class="ms-input">
                        <option value="active" <?php echo (!$editProgram || $editProgram['status'] === 'active') ? 'selected' : ''; ?>>Active (Live immediately)</option>
                        <option value="inactive" <?php echo ($editProgram && $editProgram['status'] === 'inactive') ? 'selected' : ''; ?>>Draft (Save for later)</option>
                    </select>
                    <div class="hint">Active programs start immediately</div>
                </div>

                <div>
                    <label>Branches *</label>
                    <div class="tagbox" data-name="branch_ids[]" data-type="branches">
                        <div class="control">
                            <input type="text" class="tag-search ms-input" placeholder="Search branches...">
                            <button type="button" class="btn small js-clear">Clear</button>
                            <button type="button" class="btn small primary js-add-all">All</button>
                        </div>
                        <div class="dropdown"></div>
                        <div class="tags"></div>
                    </div>
                    <div class="hint">Select active branches</div>
                </div>
            </div>
            
            <!-- Start and End dates on same line -->
            <div class="form-row-2">
                <div>
                    <label for="start_dmy">Start Date *</label>
                    <input id="start_dmy" type="date" class="ms-input" required
                           value="<?php echo $editProgram ? date('Y-m-d', strtotime($editProgram['start_at'] ?? 'today')) : date('Y-m-d'); ?>">
                    <div class="hint">When customers can start earning cashback</div>
                </div>
                
                <div>
                    <label for="end_dmy">End Date (Optional)</label>
                    <input id="end_dmy" type="date" class="ms-input"
                           value="<?php echo $editProgram && $editProgram['end_at'] ? date('Y-m-d', strtotime($editProgram['end_at'])) : ''; ?>">
                    <div class="hint">Leave blank for ongoing program</div>
                </div>
            </div>
        </div>

        <!-- Visit Ladder Section -->
        <div class="form-section">
            <div class="info-box">
                <h4 class="left">Visit-Based Cashback Ladder</h4>
                <p>Set progressive cashback rates based on customer visit frequency. Use "N+" for the final tier to continue indefinitely.</p>
            </div>
            
            <div class="ladder-wrap">
                <table class="ladder-table">
                    <thead>
                        <tr>
                            <th style="width: 25%">Visit Number</th>
                            <th style="width: 25%">Cashback %</th>
                            <th style="width: 25%">Valid Days</th>
                            <th style="width: 25%">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="ladderTbody">
                        <?php foreach ($prefLadder as $i => $step): ?>
                        <tr>
                            <td>
                                <input type="text" name="ladder[<?php echo $i; ?>][visit]" class="ms-input" 
                                       value="<?php echo htmlspecialchars($step['visit']); ?>" 
                                       placeholder="e.g., 1 or 4+" required>
                            </td>
                            <td>
                                <input type="number" name="ladder[<?php echo $i; ?>][rate_pct]" class="ms-input" 
                                       value="<?php echo number_format($step['rate_pct'], 2); ?>" 
                                       step="0.01" min="0" max="100" required>
                            </td>
                            <td>
                                <input type="number" name="ladder[<?php echo $i; ?>][valid_days]" class="ms-input" 
                                       value="<?php echo (int)$step['valid_days']; ?>" 
                                       min="1" step="1" required>
                            </td>
                            <td>
                                <?php if ($i > 0): ?>
                                    <button class="btn small danger js-ladder-del" type="button">Remove</button>
                                <?php else: ?>
                                    <span class="helper">First step</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="ladder-actions">
                    <button class="btn small primary" type="button" id="js-ladder-add">Add Visit Step</button>
                    <span class="helper">Maximum 8 visit steps. Use format like "1", "2", "3", "4+" for the last tier</span>
                </div>
            </div>
        </div>

        <!-- Redemption & Settings Section -->
        <div class="form-section">
            <div class="info-box">
                <h4>Redemption Settings</h4>
                <p>Configure how customers can use their earned cashback.</p>
            </div>
            
            <!-- Max Coverage, Min Amount, Auto-Redeem on same line -->
            <div class="form-row-3">
                <div>
                    <label for="nv_max_redeem">Max Order Coverage (%)</label>
                    <input id="nv_max_redeem" type="number" step="1" min="0" max="100" name="new_max_redeem_percent" class="ms-input"
                           value="<?php echo $editProgram ? ($editProgram['max_redeem_percent'] ?? 50) : 50; ?>">
                    <div class="hint">Max % payable with cashback</div>
                </div>
                
                <div>
                    <label for="nv_min_redeem">Min Redeem Amount</label>
                    <input id="nv_min_redeem" type="number" step="0.01" min="0" name="new_min_redeem_amount" class="ms-input"
                           value="<?php echo $editProgram ? ($editProgram['min_redeem_points'] ?? 0) : 0; ?>">
                    <div class="hint">Min wallet balance required</div>
                </div>
                
                <div>
                    <label for="min_visit_redeem">Auto-Redeem From Visit</label>
                    <input id="min_visit_redeem" type="number" min="1" step="1" name="min_visit_redeem" class="ms-input"
                           value="<?php 
                             $minVisit = 2;
                             if ($editProgram && !empty($editProgram['earn_rule_json'])) {
                               $earnRule = json_decode($editProgram['earn_rule_json'], true);
                               $minVisit = $earnRule['min_visit_to_redeem'] ?? 2;
                             }
                             echo $minVisit;
                           ?>">
                    <div class="hint">Auto-apply from this visit</div>
                </div>
            </div>
            
            <!-- Wallet Expiry, Award Timing, and Rounding on same line -->
            <div class="form-row-3" style="margin-top: 20px;">
                <div>
                    <label for="wallet_expiry_days">Wallet Expiry (Days)</label>
                    <input id="wallet_expiry_days" type="number" min="0" step="1" name="wallet_expiry_days" class="ms-input"
                           value="<?php 
                             $expiryDays = 0;
                             if ($editProgram && !empty($editProgram['earn_rule_json'])) {
                               $earnRule = json_decode($editProgram['earn_rule_json'], true);
                               $expiryDays = $earnRule['expiry']['days'] ?? 0;
                             }
                             echo $expiryDays;
                           ?>">
                    <div class="hint">0 = no expiry</div>
                </div>

                <div>
                    <label for="nv_award">Award Timing</label>
                    <select id="nv_award" name="new_award_timing" class="ms-input">
                        <?php $aw = $editProgram ? ($editProgram['award_timing'] ?? 'on_payment') : 'on_payment'; ?>
                        <option value="on_payment" <?php echo $aw === 'on_payment' ? 'selected' : ''; ?>>On Payment</option>
                        <option value="on_order_complete" <?php echo $aw === 'on_order_complete' ? 'selected' : ''; ?>>On Order Complete</option>
                        <option value="on_close" <?php echo $aw === 'on_close' ? 'selected' : ''; ?>>On Close</option>
                    </select>
                    <div class="hint">When cashback is credited</div>
                </div>
                
                <div>
                    <label for="nv_rounding">Rounding Method</label>
                    <select id="nv_rounding" name="new_rounding" class="ms-input">
                        <?php $rnd = $editProgram ? ($editProgram['rounding'] ?? 'floor') : 'floor'; ?>
                        <option value="floor" <?php echo $rnd === 'floor' ? 'selected' : ''; ?>>Floor (Round Down)</option>
                        <option value="nearest" <?php echo $rnd === 'nearest' ? 'selected' : ''; ?>>Nearest</option>
                        <option value="ceil" <?php echo $rnd === 'ceil' ? 'selected' : ''; ?>>Ceil (Round Up)</option>
                    </select>
                    <div class="hint">How to round calculations</div>
                </div>
            </div>
            
            <!-- Channels and Exclusions on same line -->
            <div class="form-row-2" style="margin-top: 20px;">
                <div>
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
                    <div class="checkbox-group">
                        <!-- POS & Online on the same line -->
                        <div class="checkbox-row">
                            <div class="checkbox-option">
                                <input type="checkbox" id="ch_pos" name="channels[]" value="pos" <?php echo $channels['pos'] ? 'checked' : ''; ?>>
                                <label for="ch_pos">In-Store (POS)</label>
                            </div>
                            <div class="checkbox-option">
                                <input type="checkbox" id="ch_online" name="channels[]" value="online" <?php echo $channels['online'] ? 'checked' : ''; ?>>
                                <label for="ch_online">Online Orders</label>
                            </div>
                        </div>
                        <!-- Delivery Apps on the next line -->
                        <div class="checkbox-row">
                            <div class="checkbox-option">
                                <input type="checkbox" id="ch_agg" name="channels[]" value="aggregator" <?php echo $channels['aggregator'] ? 'checked' : ''; ?>>
                                <label for="ch_agg">Delivery Apps</label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div>
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
                    <div class="checkbox-group">
                        <!-- Both exclusions on the same line -->
                        <div class="checkbox-row">
                            <div class="checkbox-option">
                                <input type="checkbox" id="excl_agg" name="excl_aggregators" value="1" <?php echo $excl_agg ? 'checked' : ''; ?>>
                                <label for="excl_agg">Exclude aggregator orders</label>
                            </div>
                            <div class="checkbox-option">
                                <input type="checkbox" id="excl_disc" name="excl_discounted" value="1" <?php echo $excl_disc ? 'checked' : ''; ?>>
                                <label for="excl_disc">Exclude discounted orders</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Notes -->
            <div style="margin-top: 20px;">
                <label for="nv_notes">Internal Notes (Optional)</label>
                <textarea id="nv_notes" name="desc" class="ms-input" rows="3" placeholder="Any notes for your team about this program..."><?php
                  if ($editProgram && !empty($editProgram['earn_rule_json'])) {
                    $rule = json_decode($editProgram['earn_rule_json'], true);
                    echo htmlspecialchars($rule['description'] ?? '');
                  }
                ?></textarea>
            </div>
        </div>

        <!-- Form Footer -->
        <div class="form-footer">
            <a href="index.php" class="btn">Cancel</a>
            <button class="btn primary" type="submit"><?php echo $editProgram && !$isDuplicate ? 'Update Program' : 'Save Program'; ?></button>
        </div>
    </form>
</div>

<script src="_shared/scripts.js"></script>
<script>
(function() {
    'use strict';
    
    // Initialize global configuration
    window.CASHBACK_CONFIG = {
        maxLadderSteps: 8
    };
    
    window.BRANCHES = <?php echo $js_branches; ?>;
    const PREF_BRANCHES = <?php echo $js_pref_branches; ?>;
    
    // TagDropdown class
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
                    this.clearAll(false);
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
                // Add "All Branches" option
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
        console.log('Cashback Program System - Initializing...');
        
        // Initialize branch tagbox
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
                // Default to all branches
                tagDropdown.addItem(0, 'All Branches');
            }
        }
        
        // Initialize Add Ladder Step Button
        const addButton = document.getElementById('js-ladder-add');
        const ladderTbody = document.getElementById('ladderTbody');
        
        if (addButton && ladderTbody) {
            addButton.addEventListener('click', function() {
                const currentSteps = ladderTbody.querySelectorAll('tr').length;
                
                if (currentSteps >= 8) {
                    alert('Maximum 8 visit steps allowed.');
                    return;
                }
                
                const nextVisit = currentSteps + 1;
                const isLast = currentSteps === 7;
                const visitValue = isLast ? nextVisit + '+' : nextVisit.toString();
                
                const tr = document.createElement('tr');
                const idx = Date.now(); // Unique index
                tr.innerHTML = 
                    '<td><input type="text" name="ladder[' + idx + '][visit]" class="ms-input" required placeholder="Visit number" value="' + visitValue + '"></td>' +
                    '<td><input type="number" name="ladder[' + idx + '][rate_pct]" class="ms-input" value="0.00" step="0.01" min="0" max="100" required></td>' +
                    '<td><input type="number" name="ladder[' + idx + '][valid_days]" class="ms-input" value="14" min="1" step="1" required></td>' +
                    '<td><button class="btn small danger js-ladder-del" type="button">Remove</button></td>';
                
                ladderTbody.appendChild(tr);
                
                // Focus on the new visit input
                const newVisitInput = tr.querySelector('input[name*="[visit]"]');
                if (newVisitInput) {
                    newVisitInput.focus();
                    newVisitInput.select();
                }
            });
        }
        
        // Initialize Delete Ladder Step Buttons
        document.addEventListener('click', function(e) {
            if (e.target && e.target.classList && e.target.classList.contains('js-ladder-del')) {
                const tr = e.target.closest('tr');
                const ladderTbody = document.getElementById('ladderTbody');
                
                if (!tr || !ladderTbody) return;
                
                if (ladderTbody.querySelectorAll('tr').length <= 1) {
                    alert('At least one visit step is required.');
                    return;
                }
                
                const visitInput = tr.querySelector('input[name*="[visit]"]');
                const visit = visitInput ? visitInput.value : 'this step';
                
                if (confirm('Remove visit ' + visit + '?')) {
                    tr.remove();
                }
            }
        });
        
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
                
                // Validate ladder steps
                const ladderSteps = ladderTbody.querySelectorAll('tr');
                if (ladderSteps.length === 0) {
                    e.preventDefault();
                    alert('Please add at least one visit step.');
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
        
        console.log('Cashback Program System - Ready!');
    });
})();
</script>
</body>
</html>