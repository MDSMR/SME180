<?php
/**
 * SME 180 - Super Admin Sidebar Navigation
 * Unified sidebar component for all super admin pages
 * Path: /views/superadmin/includes/sidebar.php
 */

// Get current page for active state detection
$current_page = basename($_SERVER['PHP_SELF'], '.php');
$current_dir = basename(dirname($_SERVER['PHP_SELF']));
$script_path = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';

// Determine active page
$isDashboard = ($current_page === 'dashboard' && str_contains($script_path, '/superadmin/'));
$isTenants = ($current_dir === 'tenants' || str_contains($script_path, '/superadmin/tenants/'));
$isPlans = ($current_dir === 'plans' || str_contains($script_path, '/superadmin/plans/'));
$isUsers = ($current_dir === 'users' || str_contains($script_path, '/superadmin/users/'));

// Get super admin info from session
$super_admin_username = $_SESSION['super_admin_username'] ?? 'superadmin';
$initials = strtoupper(substr($super_admin_username, 0, 2));
?>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

:root {
    /* Clean Color Palette */
    --sa-bg-primary: #f8f9fa;
    --sa-bg-secondary: #f3f4f6;
    --sa-card-bg: #ffffff;
    --sa-text-primary: #1f2937;
    --sa-text-secondary: #6b7280;
    --sa-text-tertiary: #9ca3af;
    
    /* Brand Colors - Blue Gradient */
    --sa-primary: #667eea;
    --sa-primary-hover: #5a67d8;
    --sa-primary-light: #e0e7ff;
    --sa-primary-lighter: #f0f4ff;
    --sa-gradient-start: #667eea;
    --sa-gradient-end: #818cf8;
    
    /* Header - Blue gradient for both headers */
    --sa-header-bg: linear-gradient(135deg, #667eea 0%, #818cf8 100%);
    --sa-header-text: #ffffff;
    
    --sa-border: #e5e7eb;
    --sa-border-light: #f3f4f6;
    --sa-hover: #f9fafb;
    
    --sa-shadow-sm: 0 1px 2px rgba(0,0,0,.05);
    --sa-shadow-md: 0 4px 6px rgba(0,0,0,.07);
    --sa-shadow-lg: 0 10px 15px rgba(0,0,0,.1);
    
    --sa-transition: all .15s cubic-bezier(.4,0,.2,1);
    --sa-radius: 6px;
    --sa-radius-lg: 10px;
}

/* Reset body margins */
body {
    margin: 0;
    padding: 0;
}

/* Layout Structure */
.sa-layout {
    display: flex;
    min-height: 100vh;
    background: var(--sa-bg-primary);
    position: relative;
}

/* Sidebar */
.sa-sidebar {
    width: 280px;
    background: var(--sa-card-bg);
    position: fixed;
    left: 0;
    top: 0; /* Ensure it starts at the very top */
    height: 100vh;
    z-index: 100;
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    overflow: hidden;
    border-right: 1px solid var(--sa-border);
    box-shadow: var(--sa-shadow-sm);
    display: flex;
    flex-direction: column;
}

.sa-sidebar.collapsed {
    transform: translateX(-280px);
}

/* Brand Header - Exactly at top with no margin */
.sa-brand {
    padding: 0 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    height: 64px;
    min-height: 64px;
    max-height: 64px;
    box-sizing: border-box;
    background: var(--sa-header-bg);
    flex-shrink: 0;
    position: relative;
    top: 0;
    margin: 0;
}

.sa-brand-logo {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 1;
}

.sa-brand-icon {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.sa-brand-text {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    font-size: 20px;
    font-weight: 600;
    color: white;
    letter-spacing: -0.3px;
}

/* Navigation */
.sa-nav {
    padding: 12px 0;
    flex: 1;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: var(--sa-border) transparent;
}

.sa-nav::-webkit-scrollbar {
    width: 6px;
}

.sa-nav::-webkit-scrollbar-track {
    background: transparent;
}

.sa-nav::-webkit-scrollbar-thumb {
    background: var(--sa-border);
    border-radius: 3px;
}

.sa-nav::-webkit-scrollbar-thumb:hover {
    background: var(--sa-text-tertiary);
}

.sa-nav-section {
    margin-bottom: 4px;
}

/* Navigation Links */
.sa-nav-link {
    display: flex;
    align-items: center;
    padding: 10px 20px;
    color: var(--sa-text-secondary);
    text-decoration: none;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    font-size: 14px;
    font-weight: 500;
    transition: var(--sa-transition);
    position: relative;
    border-left: 3px solid transparent;
    margin: 0 8px;
    border-radius: var(--sa-radius);
}

.sa-nav-link:hover {
    background: var(--sa-hover);
    color: var(--sa-text-primary);
}

.sa-nav-link.active {
    background: var(--sa-primary-lighter);
    color: var(--sa-primary);
    font-weight: 600;
    border-left-color: var(--sa-primary);
}

.sa-nav-icon {
    width: 18px;
    height: 18px;
    margin-right: 12px;
    flex-shrink: 0;
}

/* Main Content Area */
.sa-content {
    flex: 1;
    margin-left: 280px;
    transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    min-height: 100vh;
    background: var(--sa-bg-primary);
    display: flex;
    flex-direction: column;
    position: relative;
}

.sa-content.expanded {
    margin-left: 0;
}

/* Top Header - Aligned exactly at top */
.sa-header {
    position: sticky;
    top: 0;
    left: 0;
    right: 0;
    z-index: 90;
    background: var(--sa-header-bg);
    color: var(--sa-header-text);
    box-shadow: var(--sa-shadow-md);
    padding: 0 24px;
    height: 64px;
    min-height: 64px;
    max-height: 64px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-sizing: border-box;
    margin: 0;
}

/* Sidebar Toggle */
.sa-sidebar-toggle {
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: white;
    cursor: pointer;
    padding: 8px;
    border-radius: var(--sa-radius);
    transition: var(--sa-transition);
    display: flex;
    align-items: center;
    justify-content: center;
}

.sa-sidebar-toggle:hover {
    background: rgba(255, 255, 255, 0.2);
    border-color: rgba(255, 255, 255, 0.3);
}

.sa-sidebar-toggle svg {
    width: 18px;
    height: 18px;
}

/* Header User Info */
.sa-header-user {
    display: flex;
    align-items: center;
    gap: 10px;
    color: white;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    padding: 8px 12px;
    border-radius: var(--sa-radius);
    transition: var(--sa-transition);
    position: relative;
    background: rgba(255, 255, 255, 0.1);
}

.sa-header-user:hover {
    background: rgba(255, 255, 255, 0.2);
}

.sa-header-user.active {
    background: rgba(255, 255, 255, 0.25);
}

.sa-header-user-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.25);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 12px;
    border: 2px solid rgba(255, 255, 255, 0.3);
}

.sa-header-user-chevron {
    width: 14px;
    height: 14px;
    color: rgba(255, 255, 255, 0.9);
    transition: transform 0.2s ease;
    margin-left: 4px;
}

.sa-header-user.active .sa-header-user-chevron {
    transform: rotate(180deg);
}

/* User Dropdown */
.sa-header-dropdown {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    background: var(--sa-card-bg);
    border: 1px solid var(--sa-border);
    border-radius: var(--sa-radius-lg);
    box-shadow: var(--sa-shadow-lg);
    min-width: 140px;
    z-index: 1000;
    display: none;
    overflow: hidden;
}

.sa-header-dropdown.show {
    display: block;
    animation: dropdownFadeIn 0.15s ease-out;
}

@keyframes dropdownFadeIn {
    from { opacity: 0; transform: translateY(-4px); }
    to { opacity: 1; transform: translateY(0); }
}

.sa-dropdown-item {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 10px 14px;
    font-size: 14px;
    font-weight: 500;
    color: var(--sa-text-secondary);
    text-decoration: none;
    border: none;
    background: none;
    text-align: left;
    cursor: pointer;
    transition: var(--sa-transition);
}

.sa-dropdown-item:hover {
    background: var(--sa-hover);
    color: var(--sa-text-primary);
}

.sa-dropdown-item.danger {
    color: #dc2626;
}

.sa-dropdown-item.danger:hover {
    background: #fef2f2;
    color: #b91c1c;
}

.sa-dropdown-icon {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
}

/* Page Content */
.sa-page-content {
    flex: 1;
    padding-bottom: 80px;
    position: relative;
}

/* Footer */
.sa-footer {
    background: #ffffff;
    border-top: 1px solid var(--sa-border);
    padding: 16px 24px;
    margin-top: auto;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    font-size: 12px;
    color: var(--sa-text-tertiary);
    position: relative;
    clear: both;
}

.sa-footer-container {
    max-width: 1400px;
    margin: 0 auto;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}

.sa-footer-copyright {
    color: var(--sa-text-secondary);
}

.sa-footer-links {
    display: flex;
    gap: 16px;
    align-items: center;
    font-size: 11px;
}

.sa-footer-version {
    color: var(--sa-text-tertiary);
}

/* Mobile Overlay */
.sa-sidebar-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.3);
    z-index: 99;
    display: none;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.sa-sidebar-overlay.show {
    display: block;
    opacity: 1;
}

/* Responsive */
@media (max-width: 768px) {
    .sa-sidebar {
        transform: translateX(-280px);
    }
    
    .sa-sidebar.mobile-open {
        transform: translateX(0);
    }
    
    .sa-content {
        margin-left: 0;
    }
    
    .sa-header {
        padding: 0 16px;
    }
    
    .sa-brand {
        padding: 0 20px;
    }
    
    .sa-nav-link {
        padding: 12px 20px;
    }
}

@media (max-width: 640px) {
    .sa-header {
        height: 60px;
        min-height: 60px;
        max-height: 60px;
        padding: 0 12px;
    }
    
    .sa-brand {
        height: 60px;
        min-height: 60px;
        max-height: 60px;
        padding: 0 20px;
    }
    
    .sa-header-user {
        gap: 8px;
        font-size: 13px;
    }
    
    .sa-header-user-avatar {
        width: 28px;
        height: 28px;
        font-size: 11px;
    }
    
    .sa-footer {
        text-align: center;
    }
    
    .sa-footer-container {
        flex-direction: column;
        gap: 8px !important;
    }
    
    .sa-footer-links {
        font-size: 10px !important;
    }
}
</style>

<div class="sa-layout">
    <!-- Sidebar -->
    <aside class="sa-sidebar" id="saSidebar">
        <div class="sa-brand">
            <div class="sa-brand-logo">
                <div class="sa-brand-icon">
                    <svg width="32" height="32" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
                        <defs>
                            <linearGradient id="saGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#ffffff" stop-opacity="0.9"/>
                                <stop offset="100%" stop-color="#ffffff" stop-opacity="0.7"/>
                            </linearGradient>
                        </defs>
                        <circle cx="100" cy="100" r="75" fill="none" stroke="url(#saGrad)" stroke-width="8" stroke-linecap="round" stroke-dasharray="235 235" transform="rotate(-45 100 100)"/>
                        <circle cx="100" cy="100" r="52" fill="none" stroke="white" stroke-width="8" stroke-linecap="round" stroke-dasharray="163 163" transform="rotate(135 100 100)" opacity="0.8"/>
                        <circle cx="100" cy="100" r="30" fill="white" opacity="0.9"/>
                    </svg>
                </div>
                <span class="sa-brand-text">SME 180</span>
            </div>
        </div>

        <nav class="sa-nav" aria-label="Super Admin navigation">
            <!-- Dashboard -->
            <div class="sa-nav-section">
                <a href="/views/superadmin/dashboard.php" class="sa-nav-link <?= $isDashboard ? 'active' : '' ?>">
                    <svg class="sa-nav-icon" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/>
                    </svg>
                    Dashboard
                </a>
            </div>

            <!-- Tenant Management -->
            <div class="sa-nav-section">
                <a href="/views/superadmin/tenants/index.php" class="sa-nav-link <?= $isTenants ? 'active' : '' ?>">
                    <svg class="sa-nav-icon" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2l-5.5 9h11z"/><circle cx="17.5" cy="17.5" r="4.5"/><path d="M3 13.5h8v8H3z"/>
                    </svg>
                    Tenant Management
                </a>
            </div>

            <!-- Subscriptions -->
            <div class="sa-nav-section">
                <a href="/views/superadmin/plans/index.php" class="sa-nav-link <?= $isPlans ? 'active' : '' ?>">
                    <svg class="sa-nav-icon" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1.41 16.09V20h-2.67v-1.93c-1.71-.36-3.16-1.46-3.27-3.4h1.96c.1 1.05.82 1.87 2.65 1.87 1.96 0 2.4-.98 2.4-1.59 0-.83-.44-1.61-2.67-2.14-2.48-.6-4.18-1.62-4.18-3.67 0-1.72 1.39-2.84 3.11-3.21V4h2.67v1.95c1.86.45 2.79 1.86 2.85 3.39H14.3c-.05-1.11-.64-1.87-2.22-1.87-1.5 0-2.4.68-2.4 1.64 0 .84.65 1.39 2.67 1.91s4.18 1.39 4.18 3.91c-.01 1.83-1.38 2.83-3.12 3.16z"/>
                    </svg>
                    Subscriptions
                </a>
            </div>

            <!-- User Management -->
            <div class="sa-nav-section">
                <a href="/views/superadmin/users/index.php" class="sa-nav-link <?= $isUsers ? 'active' : '' ?>">
                    <svg class="sa-nav-icon" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/>
                    </svg>
                    User Management
                </a>
            </div>
        </nav>
    </aside>

    <!-- Mobile Overlay -->
    <div class="sa-sidebar-overlay" id="saSidebarOverlay"></div>

    <!-- Main Content Area -->
    <div class="sa-content" id="saContent">
        <!-- Top Header -->
        <header class="sa-header">
            <button class="sa-sidebar-toggle" id="saSidebarToggle" aria-label="Toggle sidebar">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>

            <div class="sa-header-user" id="saHeaderUser">
                <div class="sa-header-user-avatar">
                    <?= htmlspecialchars($initials) ?>
                </div>
                <svg class="sa-header-user-chevron" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
                
                <div class="sa-header-dropdown" id="saHeaderDropdown">
                    <a href="/views/auth/logout.php" class="sa-dropdown-item danger">
                        <svg class="sa-dropdown-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                        </svg>
                        Logout
                    </a>
                </div>
            </div>
        </header>

        <!-- Page Content Area - OPEN FOR CONTENT INJECTION -->
        <div class="sa-page-content">
        <!-- Individual pages should place their content here -->

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('saSidebar');
    const sidebarToggle = document.getElementById('saSidebarToggle');
    const sidebarOverlay = document.getElementById('saSidebarOverlay');
    const content = document.getElementById('saContent');
    const headerUser = document.getElementById('saHeaderUser');
    const headerDropdown = document.getElementById('saHeaderDropdown');
    
    let isMobile = window.innerWidth <= 768;
    
    // Handle responsive changes
    function handleResize() {
        const wasMobile = isMobile;
        isMobile = window.innerWidth <= 768;
        
        if (wasMobile !== isMobile) {
            sidebar.classList.remove('collapsed', 'mobile-open');
            content.classList.remove('expanded');
            sidebarOverlay.classList.remove('show');
        }
    }
    
    // Sidebar toggle
    function toggleSidebar() {
        if (isMobile) {
            sidebar.classList.toggle('mobile-open');
            sidebarOverlay.classList.toggle('show');
            document.body.style.overflow = sidebar.classList.contains('mobile-open') ? 'hidden' : '';
        } else {
            sidebar.classList.toggle('collapsed');
            content.classList.toggle('expanded');
        }
    }
    
    // Close mobile sidebar
    function closeMobileSidebar() {
        if (isMobile) {
            sidebar.classList.remove('mobile-open');
            sidebarOverlay.classList.remove('show');
            document.body.style.overflow = '';
        }
    }
    
    // Event listeners
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', toggleSidebar);
    }
    
    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', closeMobileSidebar);
    }
    
    // Header user dropdown
    if (headerUser && headerDropdown) {
        headerUser.addEventListener('click', function(e) {
            e.stopPropagation();
            headerDropdown.classList.toggle('show');
            headerUser.classList.toggle('active');
        });
        
        document.addEventListener('click', function() {
            headerDropdown.classList.remove('show');
            headerUser.classList.remove('active');
        });
        
        headerDropdown.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    }
    
    // Close mobile sidebar on navigation
    document.querySelectorAll('.sa-nav-link').forEach(link => {
        link.addEventListener('click', closeMobileSidebar);
    });
    
    // Handle window resize
    window.addEventListener('resize', handleResize);
    
    // Initialize
    handleResize();
    
    // Persist sidebar state in localStorage
    const sidebarState = localStorage.getItem('sa-sidebar-collapsed');
    if (sidebarState === 'true' && !isMobile) {
        sidebar.classList.add('collapsed');
        content.classList.add('expanded');
    }
    
    // Save sidebar state
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            if (!isMobile) {
                localStorage.setItem('sa-sidebar-collapsed', sidebar.classList.contains('collapsed'));
            }
        });
    }
});
</script>

<!-- LAYOUT REMAINS OPEN FOR PAGE CONTENT INJECTION -->