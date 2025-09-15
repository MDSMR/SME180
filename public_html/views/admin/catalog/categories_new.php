<?php
declare(strict_types=1);

// Bootstrap
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/auth_login.php';
auth_require_login();

$bootstrap_warning = '';
$bootstrap_ok = false;
$bootstrap_path = __DIR__ . '/../../../config/db.php';

if (!is_file($bootstrap_path)) {
    $bootstrap_warning = 'Configuration file not found: /config/db.php';
} else {
    try {
        require_once $bootstrap_path;
        if (!function_exists('db') || !function_exists('use_backend_session')) {
            $bootstrap_warning = 'Required functions missing in config/db.php';
        } else {
            $bootstrap_ok = true;
            use_backend_session();
        }
    } catch (Throwable $e) {
        $bootstrap_warning = 'Bootstrap error: ' . $e->getMessage();
    }
}

// Auth
$user = $_SESSION['user'] ?? null;
if (!$user) {
    header('Location: /views/auth/login.php');
    exit;
}

// CSRF token
if (empty($_SESSION['csrf_categories'])) {
    $_SESSION['csrf_categories'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_categories'];

// Flash message
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$active = 'catalog';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Add Category · Smorll POS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            /* Microsoft 365 Color Palette - matching sidebar and dashboard */
            --bg-primary: #faf9f8;
            --bg-secondary: #f3f2f1;
            --card-bg: #ffffff;
            --text-primary: #323130;
            --text-secondary: #605e5c;
            --text-tertiary: #8a8886;
            --primary: #0078d4;
            --primary-hover: #106ebe;
            --primary-light: #deecf9;
            --primary-lighter: #f3f9fd;
            --border: #edebe9;
            --border-light: #f8f6f4;
            --hover: #f3f2f1;
            --success: #107c10;
            --success-light: #dff6dd;
            --warning: #ff8c00;
            --warning-light: #fff4ce;
            --danger: #d13438;
            --danger-light: #fdf2f2;
            --shadow-sm: 0 1px 2px rgba(0,0,0,.04), 0 1px 1px rgba(0,0,0,.06);
            --shadow-md: 0 4px 8px rgba(0,0,0,.04), 0 1px 3px rgba(0,0,0,.06);
            --shadow-lg: 0 8px 16px rgba(0,0,0,.06), 0 2px 4px rgba(0,0,0,.08);
            --transition: all .1s cubic-bezier(.1,.9,.2,1);
            --radius: 4px;
            --radius-lg: 8px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--bg-primary);
            font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, BlinkMacSystemFont, Roboto, 'Helvetica Neue', sans-serif;
            color: var(--text-primary);
            font-size: 14px;
            line-height: 1.5;
        }

        /* Reset default margins */
        h1, h2, h3, h4, h5, h6, p {
            margin: 0;
        }

        /* Page Container */
        .create-category-container {
            padding: 16px;
            max-width: 900px;
            margin: 0 auto;
        }

        @media (max-width: 768px) {
            .create-category-container {
                padding: 12px;
            }
        }

        /* Breadcrumb */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 16px;
            font-size: 14px;
        }

        .breadcrumb-link {
            color: var(--text-secondary);
            text-decoration: none;
            transition: var(--transition);
        }

        .breadcrumb-link:hover {
            color: var(--primary);
        }

        .breadcrumb-separator {
            color: var(--text-tertiary);
        }

        .breadcrumb-current {
            color: var(--text-primary);
            font-weight: 500;
        }

        /* Page Header */
        .page-header {
            margin-bottom: 18px;
        }

        .page-title {
            font-size: 24px;
            font-weight: 600;
            color: var(--text-primary);
            margin: 0 0 4px 0;
        }

        .page-subtitle {
            font-size: 14px;
            color: var(--text-secondary);
            margin: 0;
        }

        /* Form Card */
        .form-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        /* Form Sections */
        .form-section {
            padding: 20px;
            border-bottom: 1px solid var(--border);
        }

        .form-section:last-child {
            border-bottom: none;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }

        .section-icon {
            width: 32px;
            height: 32px;
            background: var(--primary-light);
            color: var(--primary);
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .section-subtitle {
            font-size: 14px;
            color: var(--text-secondary);
            margin-top: 2px;
        }

        /* Form Grid */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        /* Labels */
        .form-label {
            font-size: 14px;
            font-weight: 500;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .required {
            color: var(--danger);
            font-weight: 400;
        }

        .optional {
            color: var(--text-secondary);
            font-size: 12px;
            font-weight: 400;
            margin-left: auto;
        }

        /* Form Controls */
        .form-input,
        .form-textarea,
        .form-select {
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            font-size: 14px;
            font-family: inherit;
            background: var(--card-bg);
            color: var(--text-primary);
            transition: var(--transition);
            outline: none;
        }

        .form-input:hover,
        .form-textarea:hover,
        .form-select:hover {
            border-color: var(--text-secondary);
        }

        .form-input:focus,
        .form-textarea:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0, 120, 212, 0.1);
        }

        .form-input::placeholder,
        .form-textarea::placeholder {
            color: var(--text-tertiary);
        }

        .form-input[type="number"] {
            font-family: 'SF Mono', Monaco, 'Courier New', monospace;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            border: 1px solid transparent;
            border-radius: var(--radius);
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: var(--transition);
            outline: none;
            justify-content: center;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .btn-primary:hover {
            background: var(--primary-hover);
            border-color: var(--primary-hover);
            color: white;
            text-decoration: none;
        }

        .btn-ghost {
            background: transparent;
            color: var(--text-secondary);
            border-color: transparent;
        }

        .btn-ghost:hover {
            background: var(--hover);
            color: var(--text-primary);
            text-decoration: none;
            box-shadow: none;
        }

        /* Form Footer */
        .form-footer {
            padding: 20px;
            background: var(--bg-secondary);
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
        }

        .form-actions {
            display: flex;
            gap: 12px;
        }

        /* Help Text */
        .help-text {
            font-size: 12px;
            color: var(--text-secondary);
            margin-top: 4px;
        }

        /* Flash Messages */
        .alert {
            padding: 16px 20px;
            border-radius: var(--radius-lg);
            margin-bottom: 16px;
            font-size: 14px;
            border: 1px solid;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert.success {
            background: var(--success-light);
            border-color: #a7f3d0;
            color: var(--success);
        }

        .alert.error {
            background: var(--danger-light);
            border-color: #fca5a5;
            color: var(--danger);
        }

        .alert.warning {
            background: var(--warning-light);
            border-color: #fcd34d;
            color: var(--warning);
        }

        /* Bootstrap Warning */
        .bootstrap-warning {
            background: var(--warning-light);
            border: 1px solid #fcd34d;
            color: var(--warning);
            padding: 16px 20px;
            border-radius: var(--radius-lg);
            margin-bottom: 16px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .form-footer {
                flex-direction: column-reverse;
                gap: 12px;
            }
            
            .form-actions {
                width: 100%;
                flex-direction: column;
            }
        }
    </style>
</head>
<body>

<?php 
$active = 'catalog'; 
try {
    require __DIR__ . '/../../partials/admin_nav.php';
} catch (Throwable $e) {
    echo "<div class='alert error'>Navigation error: " . h($e->getMessage()) . "</div>";
}
?>

<!-- Create Category Page Content -->
<div class="create-category-container">
    <?php if ($bootstrap_warning): ?>
        <div class="bootstrap-warning">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.314 16.5c-.77.833.192 2.5 1.732 2.5z"/>
            </svg>
            <?= h($bootstrap_warning) ?>
        </div>
    <?php endif; ?>

    <?php if ($flash): ?>
        <div class="alert success">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <?= h($flash) ?>
        </div>
    <?php endif; ?>

    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <a href="/views/admin/catalog/categories.php" class="breadcrumb-link">Categories</a>
        <span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">Add Category</span>
    </div>

    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title">Add Category</h1>
        <p class="page-subtitle">Create a new product category</p>
    </div>

    <!-- Form -->
    <form method="post" action="/controllers/admin/categories_save.php" id="categoryForm">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        
        <div class="form-card">
            <!-- Basic Information -->
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">🏷️</div>
                    <div>
                        <div class="section-title">Basic Information</div>
                        <div class="section-subtitle">Essential category details</div>
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">
                            Name (English)
                            <span class="required">*</span>
                        </label>
                        <input type="text" name="name_en" class="form-input" required 
                               maxlength="200" placeholder="e.g., Salads" autofocus>
                        <div class="help-text">Primary category name displayed in English</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            Name (Arabic)
                            <span class="optional">Optional</span>
                        </label>
                        <input type="text" name="name_ar" class="form-input" 
                               maxlength="200" dir="rtl" placeholder="سلطات">
                        <div class="help-text">Arabic translation of the category name</div>
                    </div>
                </div>
            </div>

            <!-- Category Settings -->
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">⚙️</div>
                    <div>
                        <div class="section-title">Category Settings</div>
                        <div class="section-subtitle">Configure visibility and status</div>
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="is_active" class="form-select">
                            <option value="1" selected>Active</option>
                            <option value="0">Inactive</option>
                        </select>
                        <div class="help-text">Active categories can be assigned to products</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">POS Visibility</label>
                        <select name="pos_visible" class="form-select">
                            <option value="1" selected>Visible</option>
                            <option value="0">Hidden</option>
                        </select>
                        <div class="help-text">Show category in point of sale system</div>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">
                            Sort Order
                            <span class="optional">Optional</span>
                        </label>
                        <input type="number" name="sort_order" class="form-input" 
                               value="999" min="0" placeholder="999">
                        <div class="help-text">Lower numbers appear first in listings</div>
                    </div>
                    <div class="form-group">
                        <!-- Empty for grid alignment -->
                    </div>
                </div>
            </div>
        </div>

        <!-- Form Footer -->
        <div class="form-footer">
            <a href="/views/admin/catalog/categories.php" class="btn btn-ghost">Cancel</a>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M5 12l5 5L20 7"/>
                    </svg>
                    Create Category
                </button>
            </div>
        </div>
    </form>
</div>

<?php
require __DIR__ . '/../../partials/admin_nav_close.php';
?>

<script>
// Auto-focus on first input
document.addEventListener('DOMContentLoaded', function() {
    const firstInput = document.querySelector('input[autofocus]');
    if (firstInput) {
        setTimeout(() => firstInput.focus(), 100);
    }
});

// Form validation feedback
document.getElementById('categoryForm').addEventListener('submit', function(e) {
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    
    submitBtn.innerHTML = '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 12a9 9 0 11-6.219-8.56"/></svg>Creating...';
    submitBtn.disabled = true;
    
    // Re-enable button if form validation fails
    setTimeout(() => {
        if (!this.checkValidity()) {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    }, 100);
});
</script>
</body>
</html>