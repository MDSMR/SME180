<?php
// /views/admin/rewards/stamp/create.php
// Create/Edit Stamp Program - Separate page for program creation/editing
declare(strict_types=1);

// Clear any stale output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Force no-cache headers BEFORE any output
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/_shared/common.php';

if (!$bootstrap_ok) {
    http_response_code(500);
    echo '<h1>Bootstrap Failed</h1><p>' . h($bootstrap_warning) . '</p>';
    exit;
}

/* Load existing program data if editing */
$editProgram = null;
$editId = $_GET['edit'] ?? $_GET['duplicate'] ?? null;
$isDuplicate = isset($_GET['duplicate']);

if ($editId && ($pdo instanceof PDO)) {
    try {
        // Try multiple query variations like in load_stamp_programs
        $queries = [
            "SELECT * FROM loyalty_programs WHERE tenant_id = ? AND id = ? AND type = 'stamp'",
            "SELECT * FROM loyalty_programs WHERE tenant_id = ? AND id = ? AND program_type = 'stamp'",
            "SELECT * FROM loyalty_programs WHERE tenant_id = ? AND id = ?"
        ];
        
        foreach ($queries as $sql) {
            try {
                $st = $pdo->prepare($sql);
                $st->execute([$tenantId, $editId]);
                $result = $st->fetch(PDO::FETCH_ASSOC);
                
                if ($result) {
                    $editProgram = $result;
                    break;
                }
            } catch(Throwable $e) {
                continue;
            }
        }
    } catch(Throwable $e) {
        $editProgram = null;
    }
}

/* Handle POST: Create/Update Program */
$action_msg = ''; 
$action_ok = false;

if (($pdo instanceof PDO) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $act = $_POST['action'] ?? '';
    try {
        if ($act === 'create_stamp_program' || $act === 'update_stamp_program') {
            $name = trim((string)($_POST['program_name'] ?? ''));
            $stamps_required = max(1, (int)($_POST['stamps_required'] ?? 10));
            $per_visit_cap = max(1, (int)($_POST['per_visit_cap'] ?? 1));
            $carry_over = isset($_POST['carry_over']) && $_POST['carry_over'] === '1' ? 1 : 0;
            $status = in_array($_POST['status'] ?? 'active', ['active', 'paused', 'inactive']) ? $_POST['status'] : 'active';
            
            // Process branch IDs
            $branch_ids = isset($_POST['branch_ids']) && is_array($_POST['branch_ids']) 
                ? array_map('intval', $_POST['branch_ids']) : [0];
            $branch_ids_json = json_encode($branch_ids);
            
            // Process dates
            $start_at = null;
            $end_at = null;
            if (!empty($_POST['start_at']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['start_at'])) {
                $start_at = $_POST['start_at'] . ' 00:00:00';
            }
            if (!empty($_POST['end_at']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['end_at'])) {
                $end_at = $_POST['end_at'] . ' 23:59:59';
            }
            
            // Process reward and earn items
            $reward_item_ids = isset($_POST['reward_item_ids']) && is_array($_POST['reward_item_ids']) 
                ? array_map('intval', $_POST['reward_item_ids']) : [];
            $earn_item_ids = isset($_POST['earn_item_ids']) && is_array($_POST['earn_item_ids']) 
                ? array_map('intval', $_POST['earn_item_ids']) : [];
            
            // Legacy support - store first reward item in reward_item_id field
            $reward_item_id = $reward_item_ids ? $reward_item_ids[0] : null;
            
            // Create earn scope JSON
            $description = trim((string)($_POST['description'] ?? ''));
            $earn_scope_json = json_encode([
                'products' => $earn_item_ids,
                'description' => $description
            ]);
            
            $pdo->beginTransaction();
            try {
                if ($act === 'update_stamp_program' && $editProgram) {
                    // Update existing program - try multiple approaches
                    $updateQueries = [
                        "UPDATE loyalty_programs SET 
                            name = ?, status = ?, start_at = ?, end_at = ?, 
                            stamps_required = ?, per_visit_cap = ?, carry_over = ?, 
                            reward_item_id = ?, earn_scope_json = ?, branch_ids = ?, updated_at = NOW()
                            WHERE id = ? AND tenant_id = ? AND type = 'stamp'",
                        "UPDATE loyalty_programs SET 
                            name = ?, status = ?, start_at = ?, end_at = ?, 
                            stamps_required = ?, per_visit_cap = ?, carry_over = ?, 
                            reward_item_id = ?, earn_scope_json = ?, updated_at = NOW()
                            WHERE id = ? AND tenant_id = ? AND program_type = 'stamp'",
                        "UPDATE loyalty_programs SET 
                            name = ?, status = ?, start_at = ?, end_at = ?, 
                            stamps_required = ?, per_visit_cap = ?, carry_over = ?, 
                            reward_item_id = ?, earn_scope_json = ?, updated_at = NOW()
                            WHERE id = ? AND tenant_id = ?"
                    ];
                    
                    $updated = false;
                    foreach ($updateQueries as $index => $sql) {
                        try {
                            $upd = $pdo->prepare($sql);
                            if ($index === 0) {
                                // First query includes branch_ids
                                $upd->execute([
                                    $name, $status, $start_at, $end_at,
                                    $stamps_required, $per_visit_cap, $carry_over, 
                                    $reward_item_id, $earn_scope_json, $branch_ids_json,
                                    $editProgram['id'], $tenantId
                                ]);
                            } else {
                                // Other queries don't include branch_ids
                                $upd->execute([
                                    $name, $status, $start_at, $end_at,
                                    $stamps_required, $per_visit_cap, $carry_over, 
                                    $reward_item_id, $earn_scope_json,
                                    $editProgram['id'], $tenantId
                                ]);
                            }
                            
                            if ($upd->rowCount() > 0) {
                                $updated = true;
                                break;
                            }
                        } catch(Throwable $e) {
                            continue;
                        }
                    }
                    
                    if ($updated) {
                        $action_ok = true;
                        $action_msg = 'Program updated successfully.';
                        $targetId = $editProgram['id'];
                    } else {
                        throw new Exception('Failed to update program');
                    }
                } else {
                    // Create new program - try with and without branch_ids
                    $insertQueries = [
                        "INSERT INTO loyalty_programs
                            (tenant_id, type, name, status, start_at, end_at, 
                             stamps_required, per_visit_cap, carry_over, reward_item_id, earn_scope_json, branch_ids,
                             created_at, updated_at)
                            VALUES
                            (?, 'stamp', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                        "INSERT INTO loyalty_programs
                            (tenant_id, program_type, name, status, start_at, end_at, 
                             stamps_required, per_visit_cap, carry_over, reward_item_id, earn_scope_json,
                             created_at, updated_at)
                            VALUES
                            (?, 'stamp', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
                    ];
                    
                    $created = false;
                    foreach ($insertQueries as $index => $sql) {
                        try {
                            $ins = $pdo->prepare($sql);
                            if ($index === 0) {
                                // First query includes branch_ids
                                $ins->execute([
                                    $tenantId, $name, $status, $start_at, $end_at,
                                    $stamps_required, $per_visit_cap, $carry_over, $reward_item_id, 
                                    $earn_scope_json, $branch_ids_json
                                ]);
                            } else {
                                // Second query uses program_type instead of type
                                $ins->execute([
                                    $tenantId, $name, $status, $start_at, $end_at,
                                    $stamps_required, $per_visit_cap, $carry_over, $reward_item_id, $earn_scope_json
                                ]);
                            }
                            
                            $created = true;
                            $action_ok = true;
                            $action_msg = 'New stamp program created successfully.';
                            $targetId = $pdo->lastInsertId();
                            break;
                        } catch(Throwable $e) {
                            if ($index === 0) {
                                continue; // Try next query
                            } else {
                                throw $e; // Last attempt failed
                            }
                        }
                    }
                    
                    if (!$created) {
                        throw new Exception('Failed to create program');
                    }
                }
                
                $pdo->commit();
                
                // Redirect to index with the program selected
                header("Location: index.php?program_id={$targetId}");
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

/* Load branches and products */
$branches = [];
$products = [];
if ($pdo instanceof PDO) {
    $branches = load_branches($pdo, $tenantId);
    $products = load_products($pdo, $tenantId); // Load all products initially
}

/* Default values */
$todayYmd = (new DateTime('today'))->format('Y-m-d');
$prefName = $editProgram ? ((string)$editProgram['name'] . ($isDuplicate ? ' (Copy)' : '')) : '';
$prefStamps = $editProgram ? (int)$editProgram['stamps_required'] : 10;
$prefCap = $editProgram ? (int)($editProgram['per_visit_cap'] ?? 1) : 1;
$prefCarry = $editProgram ? (int)($editProgram['carry_over'] ?? 1) : 1;
$prefStart = $editProgram ? (string)($editProgram['start_at'] ? date('Y-m-d', strtotime($editProgram['start_at'])) : $todayYmd) : $todayYmd;
$prefEnd = $editProgram ? (string)($editProgram['end_at'] ? date('Y-m-d', strtotime($editProgram['end_at'])) : '') : '';
$prefStatus = $editProgram ? (string)($editProgram['status'] ?? 'active') : 'active';

/* Preselected arrays */
$prefRewardIds = [];
$prefEarnIds = [];
$prefBranchIds = [];
$prefDescription = '';

if ($editProgram) {
    // Load reward items
    if (!empty($editProgram['reward_item_id'])) { 
        $prefRewardIds[] = (int)$editProgram['reward_item_id']; 
    }
    
    // Load earn items and description
    if (!empty($editProgram['earn_scope_json'])) {
        $json = json_decode((string)$editProgram['earn_scope_json'], true);
        if (is_array($json)) {
            foreach ((array)($json['products'] ?? []) as $pid) {
                $prefEarnIds[] = (int)$pid;
            }
            $prefDescription = (string)($json['description'] ?? '');
        }
    }
    
    // Load branch IDs
    if (!empty($editProgram['branch_ids'])) {
        $branchJson = json_decode((string)$editProgram['branch_ids'], true);
        if (is_array($branchJson)) {
            $prefBranchIds = array_map('intval', $branchJson);
        }
    }
    
    // If no branches are set, default to all branches
    if (empty($prefBranchIds)) {
        $prefBranchIds = [0];
    }
} else {
    // Default to all branches for new programs
    $prefBranchIds = [0];
}

$active = 'rewards_stamps_view';
$pageTitle = $editProgram ? ($isDuplicate ? 'Copy Program' : 'Edit Program') : 'Create Program';
$formAction = $editProgram && !$isDuplicate ? 'update_stamp_program' : 'create_stamp_program';
$buttonText = $editProgram && !$isDuplicate ? 'Update Program' : 'Create Program';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= h($pageTitle) ?> · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
<!-- FIXED: Absolute path with cache buster -->
<link rel="stylesheet" href="/views/admin/rewards/stamp/_shared/styles.css?v=<?= time() ?>">
<style>
/* Additional styles for create page */
.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 40px;
    margin-bottom: 30px;
}

@media (max-width: 1024px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
}

.stack {
    margin-bottom: 24px;
}

.stack label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    font-size: 14px;
    color: var(--ms-gray-160);
}

.stack input,
.stack select,
.stack textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--ms-gray-60);
    border-radius: var(--ms-radius);
    font-size: 14px;
    font-family: inherit;
    transition: all 0.2s;
}

.stack input:focus,
.stack select:focus,
.stack textarea:focus {
    outline: none;
    border-color: var(--ms-blue);
    box-shadow: 0 0 0 2px rgba(0, 120, 212, 0.25);
}

.hint {
    margin-top: 6px;
    font-size: 12px;
    color: var(--ms-gray-110);
}

.row2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

@media (max-width: 640px) {
    .row2 {
        grid-template-columns: 1fr;
    }
}

.form-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 24px;
    border-top: 1px solid var(--ms-gray-30);
}

/* Tagbox styles */
.tagbox {
    position: relative;
    background: white;
    border: 1px solid var(--ms-gray-60);
    border-radius: var(--ms-radius);
    padding: 8px;
}

.tagbox .control {
    display: flex;
    gap: 8px;
    margin-bottom: 8px;
}

.tagbox .tag-search {
    flex: 1;
    padding: 6px 10px;
    border: 1px solid var(--ms-gray-40);
    border-radius: 4px;
    font-size: 14px;
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
    box-shadow: var(--ms-shadow-2);
    max-height: 200px;
    overflow-y: auto;
    z-index: 1000;
    margin-top: 4px;
}

.tagbox .dropdown.open {
    display: block;
}

.tagbox .dropdown .opt {
    padding: 8px 12px;
    cursor: pointer;
    transition: background 0.1s;
}

.tagbox .dropdown .opt:hover {
    background: var(--ms-gray-10);
}

.tagbox .tags {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.tagbox .tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    background: var(--ms-blue-lighter);
    color: var(--ms-blue);
    border-radius: 12px;
    font-size: 13px;
    font-weight: 500;
}

.tagbox .tag button {
    background: none;
    border: none;
    color: var(--ms-blue);
    cursor: pointer;
    font-size: 16px;
    line-height: 1;
    padding: 0;
}

.tagbox .tag.tag-all {
    background: linear-gradient(135deg, var(--ms-purple-light) 0%, var(--ms-blue-light) 100%);
    border: 1px solid var(--ms-purple);
    color: var(--ms-purple);
}

/* Navigation tabs */
.points-nav {
  display: flex;
  gap: 0;
  background: white;
  border-radius: var(--ms-radius-lg);
  box-shadow: var(--ms-shadow-2);
  margin-bottom: 24px;
  overflow: hidden;
}

.points-nav-tab {
  flex: 1;
  padding: 16px 24px;
  background: white;
  border: none;
  border-right: 1px solid var(--ms-gray-30);
  font-size: 14px;
  font-weight: 600;
  color: var(--ms-gray-110);
  cursor: pointer;
  transition: all 0.2s ease;
  position: relative;
  text-decoration: none;
  text-align: center;
}

.points-nav-tab:last-child {
  border-right: none;
}

.points-nav-tab:hover {
  background: var(--ms-gray-10);
  color: var(--ms-gray-130);
}

.points-nav-tab.active {
  background: var(--ms-blue-lighter);
  color: var(--ms-blue);
}

.points-nav-tab.active::after {
  content: '';
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  height: 3px;
  background: var(--ms-blue);
}
</style>
</head>
<body>

<?php 
$nav_included = include_admin_nav('rewards_stamps_view');
if (!$nav_included) {
    echo '<div class="notice alert-error">Navigation component not found.</div>';
}
?>

<div class="container">
  <div class="h1"><?= h($pageTitle) ?></div>
  <p class="sub">Configure stamp card requirements, rewards, and earning rules for your loyalty program.</p>

  <?php if ($bootstrap_warning): ?>
    <div class="notice alert-error"><?= h($bootstrap_warning) ?></div>
  <?php endif; ?>
  
  <?php if ($action_msg): ?>
    <div class="notice <?= $action_ok ? 'alert-ok' : 'alert-error' ?>"><?= h($action_msg) ?></div>
  <?php endif; ?>

  <!-- Navigation Tabs -->
  <div class="points-nav">
    <a href="index.php" class="points-nav-tab">Programs</a>
    <a href="create.php" class="points-nav-tab active">Create Program</a>
    <a href="reports.php" class="points-nav-tab">Reports</a>
  </div>

  <form method="post" id="programForm" class="card">
    <input type="hidden" name="action" value="<?= h($formAction) ?>">

    <div class="form-grid">
      <!-- LEFT Column: Basic Settings -->
      <div>
        <div class="stack">
          <label for="program_name">Program Name *</label>
          <input id="program_name" name="program_name" type="text" placeholder="e.g., Coffee Loyalty Card" 
                 value="<?= h($prefName) ?>" required>
          <div class="hint">Choose a memorable name that customers will recognize</div>
        </div>

        <div class="stack">
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
          <div class="hint">Search and select branches for this program. Use "All Branches" for company-wide programs.</div>
        </div>

        <div class="row2">
          <div class="stack">
            <label for="stamps_required">Stamps Required *</label>
            <input id="stamps_required" name="stamps_required" type="number" min="1" step="1" 
                   value="<?= $prefStamps ?>" required>
            <div class="hint">Number of stamps needed for reward</div>
          </div>
          <div class="stack">
            <label for="per_visit_cap">Per Visit Cap</label>
            <input id="per_visit_cap" name="per_visit_cap" type="number" min="1" step="1" 
                   value="<?= $prefCap ?>">
            <div class="hint">Maximum stamps per visit/order</div>
          </div>
        </div>

        <div class="row2">
          <div class="stack">
            <label for="start_at">Goes Live Date *</label>
            <input id="start_at" name="start_at" type="date" value="<?= h($prefStart) ?>" required>
            <div class="hint">When customers can start earning stamps</div>
          </div>
          <div class="stack">
            <label for="end_at">Ends Date (Optional)</label>
            <input id="end_at" name="end_at" type="date" value="<?= h($prefEnd) ?>">
            <div class="hint">Leave empty for ongoing program</div>
          </div>
        </div>

        <div class="row2">
          <div class="stack">
            <label for="carry_over">Carry Over Stamps</label>
            <select id="carry_over" name="carry_over">
              <option value="1" <?= $prefCarry ? 'selected' : '' ?>>Yes (keep partial stamps)</option>
              <option value="0" <?= !$prefCarry ? 'selected' : '' ?>>No (reset on redeem)</option>
            </select>
            <div class="hint">What happens to leftover stamps after redemption</div>
          </div>
          <div class="stack">
            <label for="status">Program Status</label>
            <select id="status" name="status">
              <option value="active" <?= $prefStatus==='active' ? 'selected' : '' ?>>Active</option>
              <option value="paused" <?= $prefStatus==='paused' ? 'selected' : '' ?>>Paused</option>
              <option value="inactive" <?= $prefStatus==='inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
            <div class="hint">Current program state</div>
          </div>
        </div>
      </div>

      <!-- RIGHT Column: Items Configuration -->
      <div>
        <div class="stack">
          <label>Reward Items *</label>
          <div class="tagbox" data-name="reward_item_ids[]">
            <div class="control">
              <input type="text" class="tag-search" placeholder="Search rewards to add...">
              <button type="button" class="btn small js-clear">Clear</button>
            </div>
            <div class="dropdown"></div>
            <div class="tags"></div>
          </div>
          <div class="hint">Products customers can redeem with completed stamp cards</div>
        </div>

        <div class="stack">
          <label>Earning Items (Optional)</label>
          <div class="tagbox" data-name="earn_item_ids[]">
            <div class="control">
              <input type="text" class="tag-search" placeholder="Search products to add...">
              <button type="button" class="btn small js-clear">Clear</button>
            </div>
            <div class="dropdown"></div>
            <div class="tags"></div>
          </div>
          <div class="hint">Leave empty to earn stamps on ALL purchases, or specify which products earn stamps</div>
        </div>

        <div class="stack">
          <label for="description">Program Description (Optional)</label>
          <textarea id="description" name="description" rows="4" 
                    placeholder="Internal notes about this program for staff reference..."><?= h($prefDescription) ?></textarea>
          <div class="hint">Internal notes - not shown to customers</div>
        </div>
      </div>
    </div>

    <div class="form-footer">
      <a href="index.php" class="btn">Cancel</a>
      <button class="btn primary" type="submit"><?= h($buttonText) ?></button>
    </div>
  </form>
</div>

<script>
// Set configuration for JavaScript  
window.PRODUCTS = <?php echo json_encode($products, JSON_UNESCAPED_UNICODE); ?>;
window.BRANCHES = <?php echo json_encode($branches, JSON_UNESCAPED_UNICODE); ?>;
window.STAMP_CONFIG = { 
    currency: 'EGP',
    tenantId: <?= $tenantId ?>
};

// Preselected items
const PREF_REWARD = <?php echo json_encode(array_values(array_unique($prefRewardIds))); ?>;
const PREF_EARN = <?php echo json_encode(array_values(array_unique($prefEarnIds))); ?>;
const PREF_BRANCHES = <?php echo json_encode(array_values(array_unique($prefBranchIds))); ?>;

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tag boxes with preselected values
    document.querySelectorAll('.tagbox').forEach((el) => {
        const tagType = el.dataset.type || 'products';
        
        if (tagType === 'branches') {
            // Handle branch tagbox
            const preselected = PREF_BRANCHES;
            
            preselected.forEach(id => {
                if (id === 0) {
                    // Add "All Branches" tag
                    addTagToElement(el, 0, 'All Branches');
                } else {
                    const branch = window.BRANCHES.find(b => b.id === id);
                    if (branch) {
                        addTagToElement(el, id, branch.name);
                    }
                }
            });
            
            // Add "All Branches" button handler
            const addAllBtn = el.querySelector('.js-add-all');
            if (addAllBtn) {
                addAllBtn.addEventListener('click', function() {
                    // Clear existing branches
                    clearTagBox(el);
                    // Add "All Branches"
                    addTagToElement(el, 0, 'All Branches');
                    refreshProductDropdowns();
                });
            }
        } else {
            // Handle product tagboxes
            const isReward = el.dataset.name === 'reward_item_ids[]';
            const preselected = isReward ? PREF_REWARD : PREF_EARN;
            
            preselected.forEach(id => {
                const product = window.PRODUCTS.find(p => p.id === id);
                if (product) {
                    addTagToElement(el, id, product.name_en);
                }
            });
        }
    });
    
    // Form validation
    const form = document.getElementById('programForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            const name = document.getElementById('program_name');
            const stamps = document.getElementById('stamps_required');
            const start = document.getElementById('start_at');
            const end = document.getElementById('end_at');
            
            // Check if branches are selected (check for hidden inputs with name="branch_ids[]")
            const branchInputs = form.querySelectorAll('input[name="branch_ids[]"]');
            
            if (!name || !name.value.trim()) {
                e.preventDefault();
                alert('Program name is required.');
                if (name) name.focus();
                return;
            }
            
            if (!stamps || !stamps.value || parseInt(stamps.value) < 1) {
                e.preventDefault();
                alert('Stamps required must be at least 1.');
                if (stamps) stamps.focus();
                return;
            }
            
            if (branchInputs.length === 0) {
                e.preventDefault();
                alert('Please select at least one branch.');
                return;
            }
            
            if (start && end && start.value && end.value) {
                if (new Date(end.value) <= new Date(start.value)) {
                    e.preventDefault();
                    alert('End date must be after start date.');
                    if (end) end.focus();
                    return;
                }
            }
            
            // Show loading state
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Saving...';
            }
        });
    }
    
    // Initialize dropdowns
    initTagDropdowns();
});

function refreshProductDropdowns() {
    const selectedBranches = getSelectedBranches();
    
    // Filter products based on selected branches
    let filteredProducts = window.PRODUCTS;
    if (!selectedBranches.includes(0) && selectedBranches.length > 0) {
        filteredProducts = window.PRODUCTS.filter(p => 
            !p.branch_id || selectedBranches.includes(parseInt(p.branch_id))
        );
    }
    
    // Update the global products list for dropdowns
    window.FILTERED_PRODUCTS = filteredProducts;
}

function getSelectedBranches() {
    // Get branch IDs from the branch tagbox
    const branchTagbox = document.querySelector('.tagbox[data-type="branches"]');
    if (!branchTagbox) return [0];
    
    const branchInputs = branchTagbox.querySelectorAll('input[name="branch_ids[]"]');
    return Array.from(branchInputs).map(input => parseInt(input.value));
}

function clearTagBox(tagbox) {
    // Remove all tags and hidden inputs
    const tagsContainer = tagbox.querySelector('.tags');
    const hiddenInputs = tagbox.querySelectorAll('input[type="hidden"][data-tag]');
    
    if (tagsContainer) {
        tagsContainer.innerHTML = '';
    }
    
    hiddenInputs.forEach(input => input.remove());
}

function addTagToElement(el, id, name) {
    // Add hidden input
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = el.dataset.name;
    input.value = id.toString();
    input.setAttribute('data-tag', '1');
    el.appendChild(input);
    
    // Add visual tag
    const tag = document.createElement('span');
    tag.className = (el.dataset.type === 'branches' && id === 0) ? 'tag tag-all' : 'tag';
    tag.innerHTML = `<span>${escapeHtml(name)}</span> <button type="button" data-id="${id}">×</button>`;
    el.querySelector('.tags').appendChild(tag);
    
    // Bind remove handler
    tag.querySelector('button').addEventListener('click', function() {
        tag.remove();
        el.querySelector(`input[value="${id}"]`).remove();
        
        // For branches, if we removed the last branch, add "All Branches"
        if (el.dataset.type === 'branches') {
            const remainingInputs = el.querySelectorAll('input[name="branch_ids[]"]');
            if (remainingInputs.length === 0) {
                addTagToElement(el, 0, 'All Branches');
            }
            refreshProductDropdowns();
        }
    });
}

function initTagDropdowns() {
    document.querySelectorAll('.tagbox').forEach(tagbox => {
        new TagDropdown(tagbox);
    });
}

// Enhanced TagDropdown class with branch and product support
class TagDropdown {
    constructor(container) {
        this.container = container;
        this.name = container.dataset.name;
        this.type = container.dataset.type || 'products';
        this.input = container.querySelector('.tag-search');
        this.dropdown = container.querySelector('.dropdown');
        this.tagsContainer = container.querySelector('.tags');
        this.clearBtn = container.querySelector('.js-clear');
        this.selected = new Map();
        
        this.init();
    }
    
    init() {
        if (!this.input || !this.dropdown || !this.tagsContainer) return;
        
        this.input.addEventListener('focus', () => this.openDropdown());
        this.input.addEventListener('input', () => this.filterOptions());
        
        this.clearBtn?.addEventListener('click', () => this.clearAll());
        
        document.addEventListener('click', (e) => {
            if (!this.container.contains(e.target)) {
                this.closeDropdown();
            }
        });
        
        // Add change handler for branches to update products
        if (this.type === 'branches') {
            this.container.addEventListener('tagChanged', () => {
                refreshProductDropdowns();
            });
        }
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
        let items = [];
        let filtered = [];
        
        if (this.type === 'branches') {
            items = window.BRANCHES || [];
            
            // Add "All Branches" option if searching for it or if no query
            if (!query || 'all branches'.includes(query)) {
                filtered.push({ id: 0, name: 'All Branches' });
            }
            
            // Add regular branches
            const branchFiltered = items.filter(b => 
                b.id !== 0 && 
                b.name.toLowerCase().includes(query) && 
                !this.selected.has(b.id)
            );
            filtered = filtered.concat(branchFiltered);
        } else {
            items = window.FILTERED_PRODUCTS || window.PRODUCTS || [];
            filtered = items.filter(p => 
                p.name_en.toLowerCase().includes(query) && 
                !this.selected.has(p.id)
            );
        }
        
        if (filtered.length === 0) {
            this.dropdown.innerHTML = '<div class="opt" style="cursor:default">No matches found</div>';
        } else {
            this.dropdown.innerHTML = filtered.map(item => {
                const displayName = this.type === 'branches' ? item.name : item.name_en;
                return `<div class="opt" data-id="${item.id}" data-name="${this.escapeHtml(displayName)}">
                    ${this.escapeHtml(displayName)}
                </div>`;
            }).join('');
            
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
            this.clearAll();
        } else if (this.type === 'branches' && this.selected.has(0)) {
            // If adding a specific branch but "All Branches" is selected, remove "All Branches"
            this.selected.delete(0);
        }
        
        this.selected.set(id, { id, name });
        this.input.value = '';
        this.closeDropdown();
        this.renderTags();
        this.syncHiddenInputs();
        
        // Trigger change event for branches
        if (this.type === 'branches') {
            const event = new CustomEvent('tagChanged', { detail: { type: 'add', id, name } });
            this.container.dispatchEvent(event);
        }
    }
    
    removeItem(id) {
        this.selected.delete(id);
        this.renderTags();
        this.syncHiddenInputs();
        
        // If no branches selected, default to "All Branches"
        if (this.type === 'branches' && this.selected.size === 0) {
            this.addItem(0, 'All Branches');
        } else if (this.type === 'branches') {
            const event = new CustomEvent('tagChanged', { detail: { type: 'remove', id } });
            this.container.dispatchEvent(event);
        }
    }
    
    clearAll() {
        this.selected.clear();
        this.input.value = '';
        this.renderTags();
        this.syncHiddenInputs();
        
        // For branches, add "All Branches" after clearing
        if (this.type === 'branches') {
            setTimeout(() => {
                this.addItem(0, 'All Branches');
            }, 0);
        }
    }
    
    renderTags() {
        const tags = Array.from(this.selected.values()).map(item => {
            const tagClass = this.type === 'branches' && item.id === 0 ? 'tag tag-all' : 'tag';
            return `
                <span class="${tagClass}">
                    <span>${this.escapeHtml(item.name)}</span>
                    <button type="button" data-id="${item.id}" aria-label="Remove">×</button>
                </span>
            `;
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

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
<!-- FIXED: Absolute path with cache buster -->
<script src="/views/admin/rewards/stamp/_shared/scripts.js?v=<?= time() ?>"></script>
</body>
</html>