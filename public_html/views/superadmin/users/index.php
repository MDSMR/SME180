<?php
/**
 * SME 180 - User Management Index
 * Path: /views/superadmin/users/index.php
 * 
 * Lists all system users with filtering and search capabilities
 */
declare(strict_types=1);

// Include configuration
require_once dirname(__DIR__, 3) . '/config/db.php';

// Start session and verify super admin access
use_backend_session();
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'super_admin') {
    redirect('/views/auth/login.php');
    exit;
}

// Include the sidebar (which opens the layout)
require_once __DIR__ . '/../includes/sidebar.php';

// Get super admin info from session
$admin_name = $_SESSION['super_admin_name'] ?? 'Super Admin';
$pdo = db();

// Get filter parameters
$search = $_GET['search'] ?? '';
$filter_tenant = $_GET['tenant'] ?? '';
$filter_role = $_GET['role'] ?? '';
$filter_type = $_GET['type'] ?? '';
$filter_status = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build query with filters
$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "(u.name LIKE :search OR u.username LIKE :search_user OR u.email LIKE :search_email)";
    $params['search'] = "%$search%";
    $params['search_user'] = "%$search%";
    $params['search_email'] = "%$search%";
}

if ($filter_tenant) {
    $where_conditions[] = "ut.tenant_id = :tenant_id";
    $params['tenant_id'] = $filter_tenant;
}

if ($filter_role) {
    $where_conditions[] = "u.role_key = :role";
    $params['role'] = $filter_role;
}

if ($filter_type) {
    $where_conditions[] = "u.user_type = :type";
    $params['type'] = $filter_type;
}

if ($filter_status === 'active') {
    $where_conditions[] = "u.disabled_at IS NULL";
} elseif ($filter_status === 'disabled') {
    $where_conditions[] = "u.disabled_at IS NOT NULL";
}

$where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count
$count_query = "
    SELECT COUNT(DISTINCT u.id) 
    FROM users u
    LEFT JOIN user_tenants ut ON u.id = ut.user_id
    LEFT JOIN tenants t ON ut.tenant_id = t.id
    $where_clause
";
$total_users = $pdo->prepare($count_query);
$total_users->execute($params);
$total = (int)$total_users->fetchColumn();
$total_pages = ceil($total / $per_page);

// Get users with pagination
$query = "
    SELECT 
        u.id,
        u.name,
        u.username,
        u.email,
        u.user_type,
        u.role_key,
        u.last_login,
        u.disabled_at,
        u.created_at,
        r.name as role_name,
        GROUP_CONCAT(DISTINCT t.name ORDER BY ut.is_primary DESC SEPARATOR ', ') as tenant_names,
        GROUP_CONCAT(DISTINCT CONCAT(t.id, ':', ut.is_primary) ORDER BY ut.is_primary DESC SEPARATOR '|') as tenant_info,
        COUNT(DISTINCT ut.tenant_id) as tenant_count,
        COUNT(DISTINCT ub.branch_id) as branch_count
    FROM users u
    LEFT JOIN roles r ON u.role_key = r.role_key
    LEFT JOIN user_tenants ut ON u.id = ut.user_id
    LEFT JOIN tenants t ON ut.tenant_id = t.id
    LEFT JOIN user_branches ub ON u.id = ub.user_id
    $where_clause
    GROUP BY u.id
    ORDER BY u.created_at DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all tenants for filter
$tenants = $pdo->query("SELECT id, name FROM tenants WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get all roles for filter
$roles = $pdo->query("SELECT DISTINCT role_key, name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Handle success message from create/edit pages
$success_message = '';
if (isset($_GET['created'])) {
    $success_message = 'User created successfully';
} elseif (isset($_GET['updated'])) {
    $success_message = 'User updated successfully';
} elseif (isset($_GET['reset'])) {
    $success_message = 'Credentials reset successfully';
}
?>

<style>
    .users-container {
        padding: 24px;
        max-width: 1600px;
        margin: 0 auto;
    }
    
    .page-header {
        margin-bottom: 24px;
    }
    
    .page-title {
        font-size: 26px;
        font-weight: 700;
        color: #111827;
        margin-bottom: 6px;
    }
    
    .page-subtitle {
        font-size: 14px;
        color: #6B7280;
    }
    
    /* Success Alert */
    .alert-success {
        background: #D1FAE5;
        color: #065F46;
        padding: 12px 16px;
        border-radius: 8px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        border: 1px solid #A7F3D0;
    }
    
    /* Filter Section */
    .filter-card {
        background: white;
        border: 1px solid #E5E7EB;
        border-radius: 10px;
        padding: 16px;
        margin-bottom: 20px;
    }
    
    .filter-grid {
        display: grid;
        grid-template-columns: 2fr repeat(4, 1fr) auto;
        gap: 12px;
        align-items: end;
    }
    
    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    
    .filter-label {
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #6B7280;
    }
    
    .filter-input, .filter-select {
        padding: 8px 12px;
        border: 1px solid #E5E7EB;
        border-radius: 6px;
        font-size: 13px;
        background: white;
        transition: all 0.2s;
    }
    
    .filter-input:focus, .filter-select:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }
    
    .search-input {
        padding-left: 36px;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%236B7280'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: 10px center;
        background-size: 16px;
    }
    
    /* Action Buttons */
    .action-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    
    .btn {
        padding: 8px 16px;
        border: none;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
    }
    
    .btn-primary:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
    }
    
    .btn-secondary {
        background: white;
        color: #374151;
        border: 1px solid #E5E7EB;
    }
    
    .btn-secondary:hover {
        background: #F9FAFB;
        border-color: #D1D5DB;
    }
    
    /* Table Card */
    .table-card {
        background: white;
        border: 1px solid #E5E7EB;
        border-radius: 10px;
        overflow: hidden;
    }
    
    .table-wrapper {
        overflow-x: auto;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
    }
    
    th {
        background: #F9FAFB;
        padding: 12px;
        text-align: left;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #6B7280;
        border-bottom: 1px solid #E5E7EB;
    }
    
    td {
        padding: 12px;
        border-bottom: 1px solid #F3F4F6;
        font-size: 13px;
        color: #374151;
    }
    
    tbody tr:hover {
        background: #FAFBFC;
    }
    
    tbody tr:last-child td {
        border-bottom: none;
    }
    
    /* User Cell */
    .user-cell {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .user-avatar {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: linear-gradient(135deg, #667eea, #764ba2);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 12px;
        flex-shrink: 0;
    }
    
    .user-details {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }
    
    .user-name {
        font-weight: 600;
        color: #111827;
        font-size: 13px;
    }
    
    .user-username {
        font-size: 11px;
        color: #6B7280;
    }
    
    /* Badges */
    .badge {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 12px;
        font-size: 10px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    
    .badge-backend {
        background: rgba(139, 92, 246, 0.1);
        color: #7C3AED;
    }
    
    .badge-pos {
        background: rgba(59, 130, 246, 0.1);
        color: #2563EB;
    }
    
    .badge-both {
        background: rgba(16, 185, 129, 0.1);
        color: #059669;
    }
    
    .badge-active {
        background: #D1FAE5;
        color: #065F46;
    }
    
    .badge-disabled {
        background: #FEE2E2;
        color: #991B1B;
    }
    
    /* Tenant Pills */
    .tenant-pills {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
    }
    
    .tenant-pill {
        display: inline-block;
        padding: 2px 6px;
        background: #F3F4F6;
        border-radius: 4px;
        font-size: 11px;
        color: #4B5563;
    }
    
    .tenant-pill.primary {
        background: rgba(102, 126, 234, 0.1);
        color: #5B21B6;
        font-weight: 600;
    }
    
    /* Action Buttons */
    .action-buttons {
        display: flex;
        gap: 6px;
    }
    
    .btn-action {
        padding: 4px 8px;
        background: white;
        color: #6B7280;
        border: 1px solid #E5E7EB;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    
    .btn-action:hover {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        border-color: #667eea;
    }
    
    .btn-action.danger:hover {
        background: #EF4444;
        border-color: #EF4444;
    }
    
    /* Pagination */
    .pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 8px;
        padding: 16px;
        background: #F9FAFB;
        border-top: 1px solid #E5E7EB;
    }
    
    .page-link {
        padding: 6px 12px;
        border: 1px solid #E5E7EB;
        background: white;
        color: #374151;
        text-decoration: none;
        border-radius: 6px;
        font-size: 13px;
        transition: all 0.2s;
        min-width: 32px;
        text-align: center;
    }
    
    .page-link:hover:not(.disabled):not(.active) {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        border-color: #667eea;
    }
    
    .page-link.active {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        border: none;
    }
    
    .page-link.disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }
    
    .page-info {
        color: #6B7280;
        font-size: 13px;
        margin: 0 12px;
    }
    
    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 48px 24px;
    }
    
    .empty-icon {
        font-size: 48px;
        margin-bottom: 16px;
        opacity: 0.5;
    }
    
    .empty-title {
        font-size: 18px;
        font-weight: 600;
        color: #111827;
        margin-bottom: 8px;
    }
    
    .empty-text {
        font-size: 14px;
        color: #6B7280;
    }
    
    @media (max-width: 1200px) {
        .filter-grid {
            grid-template-columns: 1fr 1fr 1fr;
            gap: 10px;
        }
    }
    
    @media (max-width: 768px) {
        .filter-grid {
            grid-template-columns: 1fr;
        }
        
        .action-bar {
            flex-direction: column;
            gap: 10px;
        }
        
        .users-container {
            padding: 16px;
        }
    }
</style>

<div class="users-container">
    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title">User Management</h1>
        <p class="page-subtitle">Manage system users across all tenants and branches</p>
    </div>
    
    <?php if ($success_message): ?>
    <div class="alert-success">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <?= htmlspecialchars($success_message) ?>
    </div>
    <?php endif; ?>
    
    <!-- Filters -->
    <div class="filter-card">
        <form method="get" id="filterForm" class="filter-grid">
            <div class="filter-group">
                <label class="filter-label">Search</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="Name, username or email..." 
                       class="filter-input search-input">
            </div>
            <div class="filter-group">
                <label class="filter-label">Tenant</label>
                <select name="tenant" class="filter-select">
                    <option value="">All Tenants</option>
                    <?php foreach ($tenants as $tenant): ?>
                    <option value="<?= $tenant['id'] ?>" <?= $filter_tenant == $tenant['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($tenant['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label class="filter-label">Role</label>
                <select name="role" class="filter-select">
                    <option value="">All Roles</option>
                    <?php foreach ($roles as $role): ?>
                    <option value="<?= $role['role_key'] ?>" <?= $filter_role == $role['role_key'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($role['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label class="filter-label">Type</label>
                <select name="type" class="filter-select">
                    <option value="">All Types</option>
                    <option value="backend" <?= $filter_type == 'backend' ? 'selected' : '' ?>>Backend</option>
                    <option value="pos" <?= $filter_type == 'pos' ? 'selected' : '' ?>>POS</option>
                    <option value="both" <?= $filter_type == 'both' ? 'selected' : '' ?>>Both</option>
                </select>
            </div>
            <div class="filter-group">
                <label class="filter-label">Status</label>
                <select name="status" class="filter-select">
                    <option value="">All Status</option>
                    <option value="active" <?= $filter_status == 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="disabled" <?= $filter_status == 'disabled' ? 'selected' : '' ?>>Disabled</option>
                </select>
            </div>
            <?php if ($search || $filter_tenant || $filter_role || $filter_type || $filter_status): ?>
            <a href="/views/superadmin/users/index.php" class="btn btn-secondary">Clear</a>
            <?php endif; ?>
        </form>
    </div>
    
    <!-- Action Bar -->
    <div class="action-bar">
        <div style="font-size: 14px; color: #6B7280;">
            Showing <?= number_format($total) ?> users
        </div>
        <a href="/views/superadmin/users/create.php" class="btn btn-primary">
            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Create User
        </a>
    </div>
    
    <!-- Users Table -->
    <div class="table-card">
        <?php if (empty($users)): ?>
        <div class="empty-state">
            <div class="empty-icon">👥</div>
            <div class="empty-title">No Users Found</div>
            <div class="empty-text">
                <?php if ($search || $filter_tenant || $filter_role || $filter_type || $filter_status): ?>
                    Try adjusting your filters or search terms.
                <?php else: ?>
                    Start by creating your first user.
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th width="5%">ID</th>
                        <th width="20%">User</th>
                        <th width="10%">Type</th>
                        <th width="12%">Role</th>
                        <th width="18%">Tenants</th>
                        <th width="8%">Branches</th>
                        <th width="12%">Last Login</th>
                        <th width="8%">Status</th>
                        <th width="15%">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td>#<?= $user['id'] ?></td>
                        <td>
                            <div class="user-cell">
                                <div class="user-avatar">
                                    <?= strtoupper(substr($user['name'], 0, 1)) ?>
                                </div>
                                <div class="user-details">
                                    <div class="user-name"><?= htmlspecialchars($user['name']) ?></div>
                                    <div class="user-username">@<?= htmlspecialchars($user['username']) ?></div>
                                    <?php if ($user['email']): ?>
                                    <div class="user-username"><?= htmlspecialchars($user['email']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="badge badge-<?= $user['user_type'] ?>">
                                <?= strtoupper($user['user_type']) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($user['role_name'] ?? $user['role_key']) ?></td>
                        <td>
                            <div class="tenant-pills">
                                <?php 
                                if ($user['tenant_names']) {
                                    $tenant_list = explode(', ', $user['tenant_names']);
                                    $tenant_infos = explode('|', $user['tenant_info']);
                                    foreach ($tenant_list as $index => $tenant_name) {
                                        $info_parts = explode(':', $tenant_infos[$index] ?? '');
                                        $is_primary = ($info_parts[1] ?? 0) == 1;
                                        ?>
                                        <span class="tenant-pill <?= $is_primary ? 'primary' : '' ?>">
                                            <?= htmlspecialchars($tenant_name) ?>
                                        </span>
                                    <?php }
                                } else {
                                    echo '<span style="color: #9CA3AF; font-size: 12px;">No tenants</span>';
                                }
                                ?>
                            </div>
                        </td>
                        <td>
                            <span style="font-weight: 600;"><?= $user['branch_count'] ?></span> branches
                        </td>
                        <td>
                            <?php if ($user['last_login']): ?>
                                <div style="font-size: 11px;">
                                    <?= date('M j, Y', strtotime($user['last_login'])) ?><br>
                                    <span style="color: #9CA3AF;"><?= date('g:i A', strtotime($user['last_login'])) ?></span>
                                </div>
                            <?php else: ?>
                                <span style="color: #9CA3AF; font-size: 12px;">Never</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-<?= $user['disabled_at'] ? 'disabled' : 'active' ?>">
                                <?= $user['disabled_at'] ? 'Disabled' : 'Active' ?>
                            </span>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <a href="/views/superadmin/users/edit.php?id=<?= $user['id'] ?>" class="btn-action">
                                    <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                    Edit
                                </a>
                                <a href="/views/superadmin/users/reset_credentials.php?id=<?= $user['id'] ?>" class="btn-action">
                                    <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                                    </svg>
                                    Reset
                                </a>
                                <?php if ($user['disabled_at']): ?>
                                <button onclick="toggleUserStatus(<?= $user['id'] ?>, 'enable')" class="btn-action">
                                    Enable
                                </button>
                                <?php else: ?>
                                <button onclick="toggleUserStatus(<?= $user['id'] ?>, 'disable')" class="btn-action danger">
                                    Disable
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="page-link">
                ← Previous
            </a>
            <?php else: ?>
            <span class="page-link disabled">← Previous</span>
            <?php endif; ?>
            
            <?php
            $start_page = max(1, $page - 2);
            $end_page = min($total_pages, $page + 2);
            
            if ($start_page > 1): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>" class="page-link">1</a>
                <?php if ($start_page > 2): ?>
                    <span class="page-link disabled">...</span>
                <?php endif;
            endif;
            
            for ($i = $start_page; $i <= $end_page; $i++): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" 
                   class="page-link <?= $i == $page ? 'active' : '' ?>">
                    <?= $i ?>
                </a>
            <?php endfor;
            
            if ($end_page < $total_pages): ?>
                <?php if ($end_page < $total_pages - 1): ?>
                    <span class="page-link disabled">...</span>
                <?php endif; ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>" class="page-link">
                    <?= $total_pages ?>
                </a>
            <?php endif; ?>
            
            <span class="page-info">
                Page <?= $page ?> of <?= $total_pages ?>
            </span>
            
            <?php if ($page < $total_pages): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="page-link">
                Next →
            </a>
            <?php else: ?>
            <span class="page-link disabled">Next →</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
    // Dynamic filter submission
    const filterInputs = document.querySelectorAll('.filter-input, .filter-select');
    let filterTimeout;
    
    filterInputs.forEach(input => {
        input.addEventListener('change', function() {
            clearTimeout(filterTimeout);
            filterTimeout = setTimeout(() => {
                document.getElementById('filterForm').submit();
            }, 300);
        });
        
        if (input.classList.contains('filter-input')) {
            input.addEventListener('keyup', function() {
                clearTimeout(filterTimeout);
                filterTimeout = setTimeout(() => {
                    document.getElementById('filterForm').submit();
                }, 500);
            });
        }
    });
    
    // Toggle user status
    function toggleUserStatus(userId, action) {
        if (!confirm(`Are you sure you want to ${action} this user?`)) {
            return;
        }
        
        fetch(`/api/superadmin/users/toggle_status.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                user_id: userId,
                action: action
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message || 'Operation failed');
            }
        })
        .catch(error => {
            alert('An error occurred: ' + error.message);
        });
    }
</script>

<?php
// Include the footer (closes the layout)
require_once __DIR__ . '/../includes/footer.php';
?>