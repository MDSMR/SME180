<?php
// /views/partials/logout_link.php - Logout link component
// Include this in your navigation or header
?>

<!-- Basic Logout Link -->
<a href="/views/auth/logout.php" class="logout-link">Logout</a>

<!-- Or with user info and styling -->
<div class="user-menu">
    <?php if (isset($_SESSION['user'])): ?>
    <div class="user-info">
        <span class="user-name"><?= htmlspecialchars($_SESSION['user']['name'] ?? $_SESSION['user']['username']) ?></span>
        <span class="tenant-branch">
            <?= htmlspecialchars($_SESSION['tenant_name'] ?? '') ?> - 
            <?= htmlspecialchars($_SESSION['branch_name'] ?? '') ?>
        </span>
    </div>
    <?php endif; ?>
    
    <div class="user-actions">
        <!-- Switch Context (if multiple tenants/branches) -->
        <?php if (isset($_SESSION['available_branches']) && count($_SESSION['available_branches']) > 1): ?>
        <a href="/views/auth/context_selection.php" class="action-link">
            <svg class="icon" width="16" height="16" viewBox="0 0 20 20" fill="currentColor">
                <path d="M8 5a1 1 0 100 2h5.586l-1.293 1.293a1 1 0 001.414 1.414l3-3a1 1 0 000-1.414l-3-3a1 1 0 10-1.414 1.414L13.586 5H8zM12 15a1 1 0 100-2H6.414l1.293-1.293a1 1 0 10-1.414-1.414l-3 3a1 1 0 000 1.414l3 3a1 1 0 001.414-1.414L6.414 15H12z"/>
            </svg>
            Switch Branch
        </a>
        <?php endif; ?>
        
        <!-- Logout -->
        <a href="/views/auth/logout.php" class="action-link logout">
            <svg class="icon" width="16" height="16" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M3 3a1 1 0 00-1 1v12a1 1 0 102 0V4a1 1 0 00-1-1zm10.293 9.293a1 1 0 001.414 1.414l3-3a1 1 0 000-1.414l-3-3a1 1 0 10-1.414 1.414L14.586 9H7a1 1 0 100 2h7.586l-1.293 1.293z" clip-rule="evenodd"/>
            </svg>
            Logout
        </a>
    </div>
</div>

<style>
.user-menu {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 8px;
}

.user-info {
    display: flex;
    flex-direction: column;
}

.user-name {
    font-weight: 600;
    color: #333;
    font-size: 14px;
}

.tenant-branch {
    font-size: 12px;
    color: #666;
    margin-top: 2px;
}

.user-actions {
    display: flex;
    gap: 10px;
}

.action-link {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    color: #666;
    text-decoration: none;
    border-radius: 6px;
    font-size: 14px;
    transition: all 0.2s;
}

.action-link:hover {
    background: #e9ecef;
    color: #333;
}

.action-link.logout {
    color: #dc3545;
}

.action-link.logout:hover {
    background: #fee;
    color: #c82333;
}

.icon {
    flex-shrink: 0;
}
</style>

<!-- Alternative: Simple logout button -->
<form method="POST" action="/views/auth/logout.php" style="display: inline;">
    <button type="submit" class="btn-logout">
        Sign Out
    </button>
</form>

<style>
.btn-logout {
    padding: 8px 16px;
    background: #dc3545;
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 14px;
    cursor: pointer;
    transition: background 0.2s;
}

.btn-logout:hover {
    background: #c82333;
}
</style>