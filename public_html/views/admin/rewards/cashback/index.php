<?php
// /views/admin/rewards/cashback/index.php
// Cashback Programs List - Main landing page
declare(strict_types=1);

require_once __DIR__ . '/_shared/common.php';

if (!$bootstrap_ok) {
    http_response_code(500);
    echo '<h1>Bootstrap Failed</h1><p>' . h($bootstrap_warning) . '</p>';
    exit;
}

/* Get Filter Parameters - UI ONLY */
$prog_tab = $_GET['tab'] ?? 'live';
$statusFilter = $_GET['status'] ?? 'all';
$branchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$dateFrom = $_GET['date_from'] ?? date('Y-01-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

/* Load Programs Data */
$programs = [];
$loadError = '';
$branches = []; // For UI filter dropdown

if ($pdo instanceof PDO) {
    $programs = load_cashback_programs($pdo, $tenantId);
    
    // Load branches for filter dropdown
    try {
        $stmt = $pdo->prepare("SELECT id, name FROM branches WHERE tenant_id = ? AND is_active = 1 ORDER BY name");
        $stmt->execute([$tenantId]);
        $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e) {
        // Continue without branches
    }
}

$now = new DateTimeImmutable('now');

// Count live programs for stats
$liveCount = 0;
$scheduledCount = 0;
$pastCount = 0;
foreach ($programs as $p) {
    $classification = classify_program($p, $now);
    if ($classification === 'live') $liveCount++;
    elseif ($classification === 'scheduled') $scheduledCount++;
    else $pastCount++;
}

/* Load cashback statistics */
$stats = load_cashback_stats($pdo, $tenantId);

/* Set Active Navigation State */
$active = 'rewards_cashback_view';  // This specific value for cashback view
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Cashback Programs · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="../points/_shared/styles.css?v=<?= time() ?>">
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

.text-badge.live {
  color: var(--ms-green);
}

.text-badge.scheduled {
  color: var(--ms-blue);
}

.text-badge.past {
  color: var(--ms-gray-110);
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
// FIXED: Changed from 'rewards' to specific 'rewards_cashback_view'
$nav_included = include_admin_nav('rewards_cashback_view');
if (!$nav_included) {
    echo '<div class="notice alert-error">Navigation component not found.</div>';
}
?>

<div class="container">
    <div class="h1">Cashback Rewards</div>
    <p class="sub">Configure visit-based cashback programs for customer retention.</p>

    <?php if ($bootstrap_warning): ?>
        <div class="notice alert-error"><?= h($bootstrap_warning) ?></div>
    <?php endif; ?>

    <?php if ($loadError): ?>
        <div class="notice alert-error">Data Loading Issue: <?= h($loadError) ?></div>
    <?php endif; ?>

    <!-- Navigation Tabs - Removed Members -->
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
                    <option value="live" <?= $statusFilter === 'live' ? 'selected' : '' ?>>Live</option>
                    <option value="scheduled" <?= $statusFilter === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                    <option value="past" <?= $statusFilter === 'past' ? 'selected' : '' ?>>Past</option>
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

    <!-- Programs List Card -->
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
                        <th>Visit Ladder</th>
                        <th>Max Coverage</th>
                        <th>Period</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $any = false;

                    if (!empty($programs)) {
                        foreach ($programs as $r) {
                            $cls = classify_program($r, $now);

                            if ($prog_tab !== 'all' && $cls !== $prog_tab) continue;
                            $any = true;

                            // Status badge - Points style
                            $statusText = '';
                            if ($cls === 'live') {
                                $statusText = '<span class="text-badge live">LIVE</span>';
                            } elseif ($cls === 'scheduled') {
                                $statusText = '<span class="text-badge scheduled">SCHEDULED</span>';
                            } else {
                                $statusText = '<span class="text-badge past">PAST</span>';
                            }

                            // Date range with arrow format
                            $startDate = !empty($r['start_at']) ? date('M j, Y', strtotime($r['start_at'])) : 'No start';
                            $endDate = !empty($r['end_at']) ? date('M j, Y', strtotime($r['end_at'])) : 'No end';
                            $period = $startDate . ' → ' . $endDate;

                            // Extract ladder info
                            $ladderInfo = '—';
                            if (!empty($r['earn_rule_json'])) {
                                $er = json_decode((string)$r['earn_rule_json'], true);
                                if (isset($er['ladder']) && is_array($er['ladder'])) {
                                    $visitCount = count($er['ladder']);
                                    $maxRate = 0;
                                    foreach ($er['ladder'] as $step) {
                                        if (isset($step['percent']) && $step['percent'] > $maxRate) {
                                            $maxRate = $step['percent'];
                                        }
                                    }
                                    $ladderInfo = $visitCount . ' steps, up to ' . number_format((float)$maxRate, 1) . '%';
                                }
                            }

                            $maxCoverage = isset($r['max_redeem_percent']) 
                                ? number_format((float)$r['max_redeem_percent'], 0) . '%' 
                                : 'Unlimited';

                            echo '<tr data-prog-id="' . h($r['id']) . '">';
                            echo '<td>' . $statusText . '</td>';
                            echo '<td style="font-weight: 600;">' . h($r['name']) . '</td>';
                            echo '<td>All Branches</td>';
                            echo '<td>' . h($ladderInfo) . '</td>';
                            echo '<td>' . h($maxCoverage) . '</td>';
                            echo '<td style="font-size: 12px;">' . $period . '</td>';
                            echo '<td>';
                            echo '<a href="create.php?edit=' . h($r['id']) . '" class="btn small">Edit</a> ';
                            echo '<button class="btn small" onclick="duplicateProgram(' . h($r['id']) . ')">Copy</button> ';
                            if ($cls !== 'live') {
                                echo '<button class="btn small danger" onclick="deleteProgram(' . h($r['id']) . ', \'' . h(addslashes($r['name'])) . '\')">Delete</button>';
                            }
                            echo '</td>';
                            echo '</tr>';
                        }
                    }

                    if (!$any) {
                        $emptyMessage = empty($programs)
                            ? 'No cashback programs found.'
                            : 'No programs found matching the selected filters.';

                        echo '<tr>';
                        echo '<td colspan="7" style="text-align: center; padding: 40px;">';
                        echo '<div style="margin-bottom: 16px; color: var(--ms-gray-110);">' . $emptyMessage . '</div>';
                        if (empty($programs)) {
                            echo '<a href="create.php" class="btn primary">Create Your First Program</a>';
                        } else {
                            echo '<a href="index.php" style="text-decoration: underline;">Clear filters</a> or ';
                            echo '<a href="create.php" class="btn primary" style="margin-left: 10px;">Create new program</a>';
                        }
                        echo '</td>';
                        echo '</tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="_shared/scripts.js"></script>
<script>
function clearFilters() {
    window.location.href = 'index.php';
}

function changeFilter(value) {
    const url = new URL(window.location);
    url.searchParams.set('tab', value);
    window.location = url.toString();
}

function duplicateProgram(id) {
    window.location = 'create.php?duplicate=' + id;
}

function deleteProgram(id, name) {
    if (confirm(`Delete cashback program "${name}"?\n\nThis action cannot be undone.`)) {
        // In production, make AJAX call to delete
        fetch(window.location.pathname, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=delete&program_id=${id}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.error || 'Failed to delete program');
            }
        })
        .catch(error => {
            alert('Error deleting program');
            console.error(error);
        });
    }
}
</script>

</body>
</html>