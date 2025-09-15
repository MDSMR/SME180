<?php
// Simplified version that directly queries the database
require_once __DIR__ . '/../../config/db.php';
use_backend_session();

if (!is_logged_in()) {
    redirect('/views/auth/login.php');
    exit;
}

$tenant_id = get_tenant_id();
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

try {
    $pdo = db();
    
    // Get total count
    $count_stmt = $pdo->prepare("SELECT COUNT(*) as total FROM audit_logs WHERE tenant_id = :tenant_id");
    $count_stmt->execute([':tenant_id' => $tenant_id]);
    $total = $count_stmt->fetch()['total'];
    $total_pages = ceil($total / $limit);
    
    // Get logs - check which column name branches table uses
    $branch_col = 'branch_name';
    $check = $pdo->query("SHOW COLUMNS FROM branches LIKE 'name'")->fetch();
    if ($check) {
        $branch_col = 'name';
    }
    
    $sql = "SELECT 
                al.*,
                u.username,
                b.{$branch_col} as branch_name
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            LEFT JOIN branches b ON al.branch_id = b.id
            WHERE al.tenant_id = :tenant_id
            ORDER BY al.created_at DESC
            LIMIT :limit OFFSET :offset";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':tenant_id', $tenant_id, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $logs = $stmt->fetchAll();
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs - POS System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Inter', sans-serif; 
            background: #f5f7fa; 
            padding: 20px; 
        }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { 
            background: white; 
            padding: 24px; 
            border-radius: 8px; 
            margin-bottom: 24px; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1); 
        }
        .header h1 { 
            font-size: 28px; 
            color: #2c3e50; 
            margin-bottom: 8px; 
        }
        .breadcrumb { 
            color: #7f8c8d; 
            font-size: 14px; 
        }
        .breadcrumb a { 
            color: #3498db; 
            text-decoration: none; 
        }
        .card { 
            background: white; 
            border-radius: 8px; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1); 
            overflow: hidden; 
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
        }
        th { 
            background: #f8f9fa; 
            padding: 12px; 
            text-align: left; 
            font-weight: 600; 
            color: #2c3e50; 
            font-size: 13px; 
            text-transform: uppercase; 
            letter-spacing: 0.5px; 
            border-bottom: 2px solid #e9ecef; 
        }
        td { 
            padding: 14px 12px; 
            border-bottom: 1px solid #f0f0f0; 
            font-size: 14px; 
        }
        tr:hover { background: #f8f9fa; }
        .badge { 
            display: inline-block; 
            padding: 4px 10px; 
            border-radius: 12px; 
            font-size: 12px; 
            font-weight: 500; 
            text-transform: capitalize; 
        }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-primary { background: #cce5ff; color: #004085; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        .pagination { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            padding: 20px; 
            background: white; 
            border-radius: 8px; 
            margin-top: 20px; 
        }
        .pagination-info { color: #666; font-size: 14px; }
        .pagination-controls { display: flex; gap: 8px; }
        .page-link { 
            padding: 8px 12px; 
            border: 1px solid #ddd; 
            background: white; 
            color: #333; 
            text-decoration: none; 
            border-radius: 4px; 
            font-size: 13px; 
            transition: all 0.2s; 
        }
        .page-link:hover { 
            background: #f8f9fa; 
            border-color: #3498db; 
        }
        .page-link.active { 
            background: #3498db; 
            color: white; 
            border-color: #3498db; 
        }
        .page-link.disabled { 
            opacity: 0.5; 
            cursor: not-allowed; 
            pointer-events: none; 
        }
        .details { 
            font-size: 12px; 
            color: #666; 
            max-width: 300px; 
            overflow: hidden; 
            text-overflow: ellipsis; 
            white-space: nowrap; 
        }
        .stats-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: white;
            padding: 16px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #2c3e50;
        }
        .stat-label {
            font-size: 13px;
            color: #7f8c8d;
            margin-top: 4px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Audit Logs</h1>
            <div class="breadcrumb">
                <a href="/views/admin/dashboard.php">Dashboard</a> / Audit Logs
            </div>
        </div>
        
        <div class="stats-bar">
            <div class="stat-card">
                <div class="stat-value"><?= $total ?></div>
                <div class="stat-label">Total Logs</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= count($logs) ?></div>
                <div class="stat-label">Showing</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $total_pages ?></div>
                <div class="stat-label">Total Pages</div>
            </div>
        </div>
        
        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Date/Time</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Branch</th>
                        <th>Details</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <?php
                        $details = json_decode($log['details'], true);
                        $badge_class = 'badge';
                        
                        if (strpos($log['action'], 'login') !== false && strpos($log['action'], 'failed') === false) {
                            $badge_class .= ' badge-success';
                        } elseif (strpos($log['action'], 'logout') !== false) {
                            $badge_class .= ' badge-primary';
                        } elseif (strpos($log['action'], 'failed') !== false) {
                            $badge_class .= ' badge-danger';
                        } else {
                            $badge_class .= ' badge-info';
                        }
                        ?>
                        <tr>
                            <td><?= date('M j, Y g:i A', strtotime($log['created_at'])) ?></td>
                            <td><?= htmlspecialchars($log['username'] ?? 'System') ?></td>
                            <td>
                                <span class="<?= $badge_class ?>">
                                    <?= htmlspecialchars($log['action']) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($log['branch_name'] ?? '-') ?></td>
                            <td class="details">
                                <?php
                                if ($details) {
                                    $parts = [];
                                    foreach ($details as $k => $v) {
                                        if ($k !== 'timestamp' && !is_array($v)) {
                                            $parts[] = "$k: $v";
                                        }
                                    }
                                    echo htmlspecialchars(implode(', ', $parts));
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                            <td><?= htmlspecialchars($log['ip_address'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <div class="pagination-info">
                Page <?= $page ?> of <?= $total_pages ?> (<?= $total ?> total records)
            </div>
            <div class="pagination-controls">
                <a href="?page=1" class="page-link <?= $page == 1 ? 'disabled' : '' ?>">First</a>
                <a href="?page=<?= max(1, $page - 1) ?>" class="page-link <?= $page == 1 ? 'disabled' : '' ?>">Previous</a>
                
                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                    <a href="?page=<?= $i ?>" class="page-link <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>
                
                <a href="?page=<?= min($total_pages, $page + 1) ?>" class="page-link <?= $page == $total_pages ? 'disabled' : '' ?>">Next</a>
                <a href="?page=<?= $total_pages ?>" class="page-link <?= $page == $total_pages ? 'disabled' : '' ?>">Last</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>