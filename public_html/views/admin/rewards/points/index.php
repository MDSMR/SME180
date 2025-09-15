<?php
// /views/admin/rewards/points/index.php
// Programs List - Main landing page with dynamic filters
declare(strict_types=1);

require_once __DIR__ . '/_shared/common.php';

if (!$bootstrap_ok) {
    http_response_code(500);
    echo '<h1>Bootstrap Failed</h1><p>' . h($bootstrap_warning) . '</p>';
    exit;
}

// Disable caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

/* Get Filter Parameters with DEFAULTS */
$statusFilter = $_GET['status'] ?? 'all';
$branchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$dateFrom = $_GET['date_from'] ?? date('Y-01-01'); // Default: Beginning of current year
$dateTo = $_GET['date_to'] ?? date('Y-m-d'); // Default: Today

/* Load Branches for filter */
$branches = [];
if ($pdo instanceof PDO) {
    try {
        $stmt = $pdo->prepare("SELECT id, name FROM branches WHERE tenant_id = ? AND is_active = 1 ORDER BY name");
        $stmt->execute([$tenantId]);
        $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e) {
        // Continue without branches
    }
}

/* Load Programs Data with filters */
$programs = [];
$loadError = '';

if ($pdo instanceof PDO) {
    try {
        $sql = "SELECT * FROM loyalty_programs WHERE tenant_id = ? AND program_type = 'points'";
        $params = [$tenantId];
        
        // Add status filter
        if ($statusFilter !== 'all') {
            $sql .= " AND status = ?";
            $params[] = $statusFilter;
        }
        
        // Date range filter - filter programs based on their start_at and end_at dates
        // This shows programs that were/are/will be active during the selected date range
        $sql .= " AND (
            (start_at IS NULL AND end_at IS NULL) OR
            (start_at IS NULL AND end_at >= ?) OR
            (start_at <= ? AND end_at IS NULL) OR
            (start_at <= ? AND end_at >= ?)
        )";
        $params[] = $dateFrom; // For end_at check
        $params[] = $dateTo;   // For start_at check
        $params[] = $dateTo;   // For full range start check
        $params[] = $dateFrom; // For full range end check
        
        $sql .= " ORDER BY id DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($programs)) {
            // Check if there are ANY programs at all
            $checkSt = $pdo->prepare("SELECT COUNT(*) as total FROM loyalty_programs WHERE tenant_id = ? AND program_type = 'points'");
            $checkSt->execute([$tenantId]);
            $totalRes = $checkSt->fetch(PDO::FETCH_ASSOC);
            $totalPrograms = (int)($totalRes['total'] ?? 0);
            
            if ($totalPrograms === 0) {
                $loadError = 'No loyalty programs found. Create your first program to get started.';
            } else {
                $loadError = "No programs match the current filters.";
            }
        }
    } catch(Throwable $e) {
        $loadError = 'Database error: ' . $e->getMessage();
    }
} else {
    $loadError = 'Database connection failed.';
}

/* Set Active Navigation State - FIXED */
$active = 'rewards_points_view';  // Changed from 'rewards' to specific value
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Points Programs · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="_shared/styles.css?v=<?= time() ?>">
<style>
/* Filter bar styling */
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

/* Text badge style for status and channels */
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

.text-badge.inactive {
  color: var(--ms-gray-110);
}

.text-badge.channel {
  color: var(--ms-blue);
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
// FIXED: Set active before including nav
$nav_included = include_admin_nav('rewards_points_view');  // Pass the specific value
if (!$nav_included) {
    echo '<div class="notice alert-error">Navigation component not found.</div>';
}
?>

<div class="container">
  <div class="h1">Points Rewards</div>
  <p class="sub">Configure and manage loyalty programs for your business.</p>

  <?php if ($bootstrap_warning): ?>
    <div class="notice alert-error"><?= h($bootstrap_warning) ?></div>
  <?php endif; ?>

  <?php if ($loadError): ?>
    <div class="notice alert-error">Data Loading Issue: <?= h($loadError) ?></div>
  <?php endif; ?>

  <?php render_points_nav('index'); ?>

  <!-- Dynamic Filters -->
  <form method="GET" action="index.php" id="filterForm">
    <div class="filters-bar">
      <div class="filter-group">
        <label for="status">Status</label>
        <select id="status" name="status" onchange="this.form.submit()">
          <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Status</option>
          <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
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
            <th>Channels</th>
            <th>Period</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php
          if (empty($programs)) {
            echo '<tr><td colspan="6" class="helper" style="padding: 20px; text-align: center;">';
            if ($statusFilter !== 'all' || $dateFrom !== date('Y-01-01') || $dateTo !== date('Y-m-d') || $branchId > 0) {
              echo 'No programs found matching the selected filters. ';
              echo '<a href="index.php" style="text-decoration: underline;">Clear filters</a> or ';
              echo '<a href="create.php" class="btn primary" style="margin-left: 10px;">Create new program</a>';
            } else {
              echo 'No loyalty programs found. <a href="create.php" class="btn primary" style="margin-left: 10px;">Create your first program</a>';
            }
            echo '</td></tr>';
          } else {
            foreach($programs as $r) {
              // Determine program status
              $status = $r['status'] ?? 'inactive';
              $isActive = ($status === 'active');
              $statusText = $isActive 
                ? '<span class="text-badge active">ACTIVE</span>' 
                : '<span class="text-badge inactive">INACTIVE</span>';

              // Determine program period
              $startDate = !empty($r['start_at']) ? date('M j, Y', strtotime($r['start_at'])) : 'No start';
              $endDate = !empty($r['end_at']) ? date('M j, Y', strtotime($r['end_at'])) : 'No end';
              $period = $startDate . ' → ' . $endDate;

              // Get branch info
              $branchDisplay = 'All Branches';
              if (!empty($r['earn_rule_json'])) {
                $er = json_decode((string)$r['earn_rule_json'], true);
                if (is_array($er) && isset($er['eligible_branches'])) {
                  if ($er['eligible_branches'] === 'all') {
                    $branchDisplay = 'All Branches';
                  } else if (is_array($er['eligible_branches'])) {
                    $branchDisplay = count($er['eligible_branches']) . ' Branches';
                  }
                }
              }

              // Parse channels from earn_rule_json
              $channels = '—';
              if (!empty($r['earn_rule_json'])) {
                $er = json_decode((string)$r['earn_rule_json'], true);
                if (is_array($er) && !empty($er['eligible_channels']) && is_array($er['eligible_channels'])) {
                  $channelTexts = [];
                  foreach ($er['eligible_channels'] as $ch) {
                    $label = $ch === 'pos' ? 'POS' : ($ch === 'online' ? 'ONLINE' : strtoupper($ch));
                    $channelTexts[] = '<span class="text-badge channel">' . h($label) . '</span>';
                  }
                  if ($channelTexts) $channels = implode(' ', $channelTexts);
                }
              }

              echo '<tr data-prog-id="' . h($r['id']) . '">';
              echo '<td>' . $statusText . '</td>';
              echo '<td style="font-weight: 600">' . h($r['name']) . '</td>'; // Just the name, no ID
              echo '<td>' . h($branchDisplay) . '</td>';
              echo '<td>' . $channels . '</td>';
              echo '<td style="font-size: 12px;">' . h($period) . '</td>';
              echo '<td>';
              echo '<a href="create.php?edit=' . h($r['id']) . '" class="btn small">Edit</a> ';
              echo '<button class="btn small js-duplicate" type="button">Copy</button> ';
              echo '<button class="btn small danger js-delete" type="button">Delete</button>';
              echo '</td>';
              echo '</tr>';
            }
          }
          ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
// Clear filters function
function clearFilters() {
    window.location.href = 'index.php';
}

// Delete functionality
document.querySelectorAll('.js-delete').forEach(btn => {
    btn.addEventListener('click', function() {
        const row = this.closest('tr');
        const id = row.dataset.progId;
        const name = row.querySelector('td:nth-child(2)').textContent;
        
        if (confirm(`Delete program "${name}"? This action cannot be undone.`)) {
            window.location.href = 'delete.php?id=' + id;
        }
    });
});

// Duplicate functionality
document.querySelectorAll('.js-duplicate').forEach(btn => {
    btn.addEventListener('click', function() {
        const row = this.closest('tr');
        const id = row.dataset.progId;
        const name = row.querySelector('td:nth-child(2)').textContent;
        
        if (confirm(`Create a copy of "${name}"?`)) {
            window.location.href = 'duplicate.php?id=' + id;
        }
    });
});
</script>

</body>
</html>