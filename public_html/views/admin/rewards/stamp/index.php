<?php
// /views/admin/rewards/stamp/index.php
// Stamp Rewards main page - Programs management only
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

/* Get current parameters */
$pagePrograms = max(1, (int)($_GET['p_programs'] ?? 1));
$limit = 12;

/* Get Filter Parameters - UI ONLY */
$statusFilter = $_GET['status'] ?? 'all';
$branchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$dateFrom = $_GET['date_from'] ?? date('Y-01-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

/* Load data - UNCHANGED */
$programs = [];
$products = [];
$branches = []; // For UI filter dropdown

if ($pdo instanceof PDO) {
    $programs = load_stamp_programs($pdo, $tenantId);
    $products = load_products($pdo, $tenantId);
    
    // Load branches for filter dropdown
    try {
        $stmt = $pdo->prepare("SELECT id, name FROM branches WHERE tenant_id = ? AND is_active = 1 ORDER BY name");
        $stmt->execute([$tenantId]);
        $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e) {
        // Continue without branches
    }
}

/* Set Active Navigation State - FIXED */
$active = 'rewards_stamps_view';  // Changed from 'rewards' to specific value
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Stamp Rewards · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
<!-- FIXED: Absolute path with cache buster -->
<link rel="stylesheet" href="/views/admin/rewards/stamp/_shared/styles.css?v=<?= time() ?>">
<style>
/* Filter bar styling - Points style */
.filters-bar {
  display: flex;
  gap: 16px;
  padding: 20px;
  background: white;
  border-radius: var(--ms-radius-lg);
  box-shadow: var(--ms-shadow-2);
  margin-bottom: 24px;
  align-items: end;
  flex-wrap: nowrap;
  overflow-x: auto;
}

@media (max-width: 1200px) {
  .filters-bar {
    flex-wrap: wrap;
  }
}

.filter-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
  flex-shrink: 0;
}

.filter-group label {
  font-size: 12px;
  font-weight: 600;
  color: var(--ms-gray-130);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.filter-group select,
.filter-group input {
  padding: 8px 12px;
  border: 1px solid var(--ms-gray-60);
  border-radius: var(--ms-radius);
  font-size: 14px;
  background: white;
  min-width: 140px;
  transition: all 0.2s ease;
}

.filter-group select:hover,
.filter-group input:hover {
  border-color: var(--ms-gray-110);
}

.filter-group select:focus,
.filter-group input:focus {
  outline: none;
  border-color: var(--ms-blue);
  box-shadow: 0 0 0 2px rgba(0, 120, 212, 0.25);
}

.apply-filters-btn {
  margin-left: auto;
}

/* Text badge style */
.text-badge {
  display: inline-block;
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  margin-right: 6px;
}

.text-badge.active {
  color: var(--ms-green);
}

.text-badge.paused {
  color: var(--ms-orange);
}

.text-badge.inactive {
  color: var(--ms-gray-110);
}

/* Points-style navigation */
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

@media (max-width: 768px) {
  .filters-bar {
    flex-direction: column;
  }
  
  .apply-filters-btn {
    margin-left: 0;
    width: 100%;
  }
}
</style>
</head>
<body>

<?php 
// FIXED: Using the specific value for stamps
$nav_included = include_admin_nav('rewards_stamps_view');
if (!$nav_included) {
    echo '<div class="notice alert-error">Navigation component not found.</div>';
}
?>

<div class="container">
  <div class="h1">Stamp Rewards</div>
  <p class="sub">Manage stamp programs, view customer balances, track stamp transactions, and configure auto-redeem rewards.</p>

  <?php if ($bootstrap_warning): ?>
    <div class="notice alert-error"><?= h($bootstrap_warning) ?></div>
  <?php endif; ?>

  <!-- Navigation Tabs -->
  <div class="points-nav">
    <a href="index.php" class="points-nav-tab active">Programs</a>
    <a href="create.php" class="points-nav-tab">Create Program</a>
    <a href="reports.php" class="points-nav-tab">Reports</a>
  </div>

  <!-- Dynamic Filters - UI ONLY -->
  <form method="GET" action="index.php" id="filterForm">
    <div class="filters-bar">
      <div class="filter-group">
        <label for="status">Status</label>
        <select id="status" name="status" onchange="this.form.submit()">
          <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Status</option>
          <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="paused" <?= $statusFilter === 'paused' ? 'selected' : '' ?>>Paused</option>
          <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
      
      <div class="filter-group">
        <label for="date_from">From Date</label>
        <input type="date" id="date_from" name="date_from" value="<?= h($dateFrom) ?>" onchange="this.form.submit()">
      </div>
      
      <div class="filter-group">
        <label for="date_to">To Date</label>
        <input type="date" id="date_to" name="date_to" value="<?= h($dateTo) ?>" onchange="this.form.submit()">
      </div>
      
      <div class="filter-group">
        <label for="branch_id">Branch</label>
        <select id="branch_id" name="branch_id" onchange="this.form.submit()">
          <option value="0">All Branches</option>
          <?php foreach($branches as $branch): ?>
            <option value="<?= (int)$branch['id'] ?>" <?= $branchId === (int)$branch['id'] ? 'selected' : '' ?>>
              <?= h($branch['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      
      <div class="apply-filters-btn">
        <button type="button" class="btn" onclick="clearFilters()">Clear Filters</button>
      </div>
    </div>
  </form>

  <!-- Programs Section -->
  <div class="card">
    <div class="card-header" style="display: flex !important; justify-content: space-between !important; align-items: center !important; flex-wrap: nowrap !important;">
      <h2 class="card-title" style="margin: 0 !important; flex: 0 1 auto;">Programs List</h2>
      <a href="create.php" class="btn primary" style="flex: 0 0 auto; margin-left: auto !important;">+ Create Program</a>
    </div>
    
    <div class="scroll-body">
      <table class="table">
        <thead>
          <tr>
            <th>Status</th>
            <th>Name</th>
            <th>Branch</th>
            <th>Period</th>
            <th>Stamps Required</th>
            <th>Reward Product</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$programs): ?>
            <tr><td colspan="7" class="helper" style="padding: 40px; text-align: center;">
              <div style="margin-bottom: 16px; color: var(--ms-gray-110);">No stamp programs created yet</div>
              <a href="create.php" class="btn primary">Create Your First Program</a>
            </td></tr>
          <?php else: foreach ($programs as $p):
            $status = (string)($p['status'] ?? '');
            
            // Status badge - Points style
            $statusText = '';
            if ($status === 'active') {
              $statusText = '<span class="text-badge active">ACTIVE</span>';
            } elseif ($status === 'paused') {
              $statusText = '<span class="text-badge paused">PAUSED</span>';
            } else {
              $statusText = '<span class="text-badge inactive">INACTIVE</span>';
            }
            
            $rewardName = '—';
            if (!empty($p['reward_item_id'])) {
              try {
                $rr = $pdo->prepare("SELECT name_en FROM products WHERE id=? AND tenant_id=?");
                $rr->execute([(int)$p['reward_item_id'], $tenantId]);
                $rewardName = (string)($rr->fetchColumn() ?: 'Product #'.(int)$p['reward_item_id']);
              } catch (Throwable $e) { /* ignore */ }
            }
            
            // Format period - Points style with arrow
            $startDate = !empty($p['start_at']) ? date('M j, Y', strtotime($p['start_at'])) : 'No start';
            $endDate = !empty($p['end_at']) ? date('M j, Y', strtotime($p['end_at'])) : 'No end';
            $period = $startDate . ' → ' . $endDate;
          ?>
            <tr data-prog='<?= h(json_encode($p, JSON_UNESCAPED_UNICODE)) ?>' data-prog-id="<?= (int)$p['id'] ?>">
              <td><?= $statusText ?></td>
              <td style="font-weight: 600;"><?= h($p['name']) ?></td>
              <td>All Branches</td>
              <td style="font-size: 12px;"><?= h($period) ?></td>
              <td style="text-align: center; font-weight: 600; font-size: 16px;">
                <?= (int)($p['stamps_required'] ?? 0) ?>
              </td>
              <td><?= h($rewardName) ?></td>
              <td>
                <a class="btn small" href="create.php?edit=<?= (int)$p['id'] ?>">Edit</a>
                <button class="btn small js-duplicate" type="button">Copy</button>
                <button class="btn small danger js-delete" type="button">Delete</button>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
      <?= pagelinks(count($programs), $pagePrograms, $limit, 'p_programs'); ?>
    </div>
  </div>
</div>

<script>
// Set configuration for JavaScript
window.PRODUCTS = <?php echo json_encode($products, JSON_UNESCAPED_UNICODE); ?>;
window.STAMP_CONFIG = {
    currency: 'EGP'
};

// Clear filters function - UI only
function clearFilters() {
    window.location.href = 'index.php';
}
</script>
<!-- FIXED: Absolute path with cache buster -->
<script src="/views/admin/rewards/stamp/_shared/scripts.js?v=<?= time() ?>"></script>
</body>
</html>