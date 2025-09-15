<?php
// /views/admin/rewards/discounts/index.php
// Final version with proper styling
declare(strict_types=1);

// Include common file
require_once __DIR__ . '/_shared/common.php';

if (!$bootstrap_ok) {
    http_response_code(500);
    echo '<h1>Bootstrap Failed</h1><p>' . h($bootstrap_warning) . '</p>';
    exit;
}

/* Get Filter Parameters */
$prog_tab = $_GET['tab'] ?? 'all';

/* Load Discount Programs Data */
$programs = [];
$loadError = '';

if ($pdo instanceof PDO) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM discount_schemes WHERE tenant_id = ? ORDER BY is_active DESC, id DESC");
        $stmt->execute([$tenantId]);
        $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($programs)) {
            $checkStmt = $pdo->query("SELECT COUNT(*) FROM discount_schemes");
            $totalInDB = (int)$checkStmt->fetchColumn();
            
            if ($totalInDB > 0) {
                $loadError = "Found $totalInDB schemes but none for tenant #$tenantId";
            } else {
                $loadError = 'No discount programs found. Create your first program to get started.';
            }
        }
    } catch(Exception $e) {
        $loadError = 'Database error: ' . $e->getMessage();
    }
} else {
    $loadError = 'Database connection failed.';
}

/* Helper Functions */
function classify_discount_program(array $p): string {
    return ((int)($p['is_active'] ?? 0) === 1) ? 'active' : 'inactive';
}

/* Set Active Navigation State - FIXED */
$active = 'rewards_discounts_view';  // Added specific value for discounts
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Discount Programs · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="_shared/styles.css">
<style>
/* Ensure navigation tabs have proper styling */
.discount-nav {
  display: flex;
  background: #ffffff;
  border-radius: 8px;
  box-shadow: 0 1.6px 3.6px 0 rgba(0,0,0,.132), 0 0.3px 0.9px 0 rgba(0,0,0,.108);
  margin: 16px 0 24px 0;
  overflow: hidden;
}

.discount-nav-tab {
  flex: 1;
  padding: 12px 20px;
  text-decoration: none;
  color: #605e5c;
  font-weight: 600;
  font-size: 14px;
  text-align: center;
  border-right: 1px solid #edebe9;
  transition: all 0.1s ease;
  position: relative;
  background: transparent;
}

.discount-nav-tab:last-child {
  border-right: none;
}

.discount-nav-tab:hover {
  background: #f3f2f1;
  color: #323130;
}

.discount-nav-tab.active {
  color: #0078d4;
  background: #f3f9fd;
}

.discount-nav-tab.active::after {
  content: '';
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  height: 3px;
  background: #0078d4;
}
</style>
</head>
<body>

<?php 
// FIXED: Set the active page for navigation highlighting to specific value
$nav_included = include_admin_nav('rewards_discounts_view');
if (!$nav_included) {
    // Simple header if nav not included
    echo '<div style="background: #0078d4; color: white; padding: 16px 24px; margin-bottom: 24px;">
            <div style="max-width: 1400px; margin: 0 auto;">
                <h2 style="margin: 0;">Smorll POS - Discount Programs</h2>
            </div>
          </div>';
}
?>

<div class="container">
  <div class="h1">Discount Programs</div>
  <p class="sub">Create and manage discount schemes for your business.</p>

  <?php if ($bootstrap_warning): ?>
    <div class="notice alert-error"><?= h($bootstrap_warning) ?></div>
  <?php endif; ?>

  <?php if ($loadError): ?>
    <div class="notice alert-error"><?= h($loadError) ?></div>
  <?php endif; ?>

  <!-- Navigation Tabs - Removed Members -->
  <div class="discount-nav">
    <a href="index.php" class="discount-nav-tab active">Programs</a>
    <a href="create_program.php" class="discount-nav-tab">Create Program</a>
    <a href="reports.php" class="discount-nav-tab">Reports</a>
  </div>

  <div class="card">
    <div class="card-header" style="display: flex !important; justify-content: space-between !important; align-items: center !important; flex-wrap: nowrap !important;">
      <h2 class="card-title" style="margin: 0 !important; flex: 0 1 auto;">Programs List</h2>
      <a href="create_program.php" class="btn primary" style="flex: 0 0 auto; margin-left: auto !important;">+ Create Program</a>
    </div>
    
    <?php if (!empty($programs)): ?>
    <div class="controls" style="margin-bottom: 20px;">
      <label class="helper" for="prog_view">Filter:</label>
      <select id="prog_view" style="width: auto; padding: 6px 12px; border: 1px solid #c8c6c4; border-radius: 4px;">
        <option value="all" <?= $prog_tab === 'all' ? 'selected' : '' ?>>All Programs</option>
        <option value="active" <?= $prog_tab === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= $prog_tab === 'inactive' ? 'selected' : '' ?>>Inactive</option>
      </select>
    </div>
    <?php endif; ?>
    
    <div class="scroll-body">
      <table class="table">
        <thead>
          <tr>
            <th>Status</th>
            <th>Program</th>
            <th>Code</th>
            <th>Discount</th>
            <th>Stackable</th>
            <th>Created</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $any = false;
          
          if (!empty($programs)) {
            foreach($programs as $r) {
              $status = classify_discount_program($r);
              
              // Apply filter
              if ($prog_tab !== 'all' && $status !== $prog_tab) continue;
              $any = true;

              $isActive = ($status === 'active');
              $statusBadge = $isActive 
                ? '<span class="badge live">Active</span>' 
                : '<span class="badge">Inactive</span>';

              $discountLabel = get_discount_label($r);
              $stackable = ((int)($r['is_stackable'] ?? 0) === 1) ? 'Yes' : 'No';
              $created = !empty($r['created_at']) ? date('M j, Y', strtotime($r['created_at'])) : '—';

              echo '<tr data-prog-id="' . h($r['id']) . '">';
              echo '<td>' . $statusBadge . '</td>';
              echo '<td>'
                  . '<div style="font-weight: 600">' . h($r['name'] ?? 'Unnamed') . '</div>'
                  . '<div class="helper">#' . h($r['id']) . '</div>'
                  . '</td>';
              echo '<td><code style="font-family: monospace; background: #f3f2f1; padding: 2px 6px; border-radius: 3px;">' . h($r['code'] ?? '—') . '</code></td>';
              echo '<td><span style="font-weight: 600; font-size: 15px; color: #0078d4">' . h($discountLabel) . '</span></td>';
              echo '<td>' . h($stackable) . '</td>';
              echo '<td>' . h($created) . '</td>';
              echo '<td>'
                  . '<a href="edit_scheme.php?id=' . h($r['id']) . '" class="btn small">Edit</a> '
                  . '<button class="btn small js-duplicate" type="button">Copy</button> '
                  . '<button class="btn small danger js-delete" type="button">Delete</button>'
                  . '</td>';
              echo '</tr>';
            }
          }
          
          if (!$any) {
            echo '<tr><td colspan="7" class="helper" style="padding: 20px; text-align: center;">';
            if (empty($programs)) {
              echo 'No discount programs found. <a href="edit_scheme.php" class="btn primary" style="margin-left: 10px;">Create your first program</a>';
            } else {
              echo 'No programs match the filter "' . h(ucfirst($prog_tab)) . '". ';
              echo 'Found ' . count($programs) . ' total programs.';
            }
            echo '</td></tr>';
          }
          ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Statistics section removed as requested -->

</div>

<script>
// View switcher
document.getElementById('prog_view')?.addEventListener('change', function(e) {
    window.location.href = '?tab=' + encodeURIComponent(e.target.value);
});

// Delete functionality
document.querySelectorAll('.js-delete').forEach(btn => {
    btn.addEventListener('click', function() {
        const row = this.closest('tr');
        const id = row.dataset.progId;
        const name = row.querySelector('td:nth-child(2) div').textContent;
        
        if (confirm(`Delete "${name}"? This action cannot be undone.`)) {
            window.location.href = 'delete_scheme.php?id=' + id;
        }
    });
});

// Duplicate functionality
document.querySelectorAll('.js-duplicate').forEach(btn => {
    btn.addEventListener('click', function() {
        const row = this.closest('tr');
        const id = row.dataset.progId;
        const name = row.querySelector('td:nth-child(2) div').textContent;
        
        if (confirm(`Create a copy of "${name}"?`)) {
            window.location.href = 'copy_scheme.php?id=' + id;
        }
    });
});
</script>
</body>
</html>