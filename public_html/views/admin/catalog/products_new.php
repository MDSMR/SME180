<?php
declare(strict_types=1);
/**
 * /public_html/views/admin/catalog/products_new.php
 * Add new product with improved modifier system - Microsoft 365 Design
 */

// Bootstrap
require_once __DIR__ . '/../../../config/db.php';
use_backend_session();
require_once __DIR__ . '/../../../middleware/auth_login.php';
auth_require_login();

$user = $_SESSION['user'] ?? null;
if (!$user) { 
  header('Location: /views/auth/login.php'); 
  exit; 
}
$tenantId = (int)($user['tenant_id'] ?? 0);

// Generate CSRF token
if (empty($_SESSION['csrf_products'])) {
    $_SESSION['csrf_products'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_products'];

// Load data for dropdowns
$categories = [];
$branches = [];
$variationGroups = [];
$error_msg = '';
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

try {
    $pdo = db();
    
    // Get categories (only active)
    $stmt = $pdo->prepare("
      SELECT id, COALESCE(NULLIF(name_en,''), name_ar, CONCAT('Category #', id)) AS name
      FROM categories 
      WHERE tenant_id = :t AND is_active = 1
      ORDER BY sort_order, name_en
    ");
    $stmt->execute([':t' => $tenantId]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
    // Get branches (only active)
    $stmt = $pdo->prepare("
      SELECT id, COALESCE(NULLIF(name,''), CONCAT('Branch #', id)) AS name
      FROM branches 
      WHERE tenant_id = :t AND is_active = 1
      ORDER BY name
    ");
    $stmt->execute([':t' => $tenantId]);
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
    // Get variation groups with their values
    $stmt = $pdo->prepare("
        SELECT 
            vg.id AS group_id,
            vg.name AS group_name,
            vg.is_required,
            vg.min_select,
            vg.max_select,
            vv.id AS value_id,
            vv.value_en,
            vv.value_ar,
            vv.price_delta
        FROM variation_groups vg
        LEFT JOIN variation_values vv ON vv.group_id = vg.id AND vv.is_active = 1
        WHERE vg.tenant_id = :t AND vg.is_active = 1 AND vg.pos_visible = 1
        ORDER BY vg.sort_order, vg.name, vv.sort_order, vv.value_en
    ");
    $stmt->execute([':t' => $tenantId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group the results
    foreach ($rows as $row) {
        $gid = $row['group_id'];
        if (!isset($variationGroups[$gid])) {
            $variationGroups[$gid] = [
                'id' => $gid,
                'name' => $row['group_name'],
                'is_required' => $row['is_required'],
                'min_select' => $row['min_select'],
                'max_select' => $row['max_select'],
                'values' => []
            ];
        }
        if ($row['value_id']) {
            $variationGroups[$gid]['values'][] = [
                'id' => $row['value_id'],
                'name' => $row['value_en'] ?: $row['value_ar'],
                'price_delta' => $row['price_delta']
            ];
        }
    }
    
} catch (Throwable $e) {
    $error_msg = $e->getMessage();
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$active = 'products';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Add Product · Smorll POS</title>
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
      
      /* Navigation widths for responsive calculations */
      --nav-width-expanded: 280px;
      --nav-width-collapsed: 60px;
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

    /* UPDATED: Responsive main content wrapper */
    .admin-content {
      transition: margin-left 0.3s ease, width 0.3s ease;
    }

    /* UPDATED: Page Container with dynamic width calculation */
    .create-product-container {
      padding: 16px;
      width: 100%;
      max-width: 900px;
      margin: 0 auto;
      transition: all 0.3s ease;
    }

    @media (max-width: 768px) {
      .create-product-container {
        padding: 12px;
      }
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
      transition: var(--transition);
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
      flex-shrink: 0;
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

    /* IMPROVED: Better responsive form grids */
    .form-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }

    .form-grid-3 {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 16px;
    }

    /* Progressive responsive breakpoints for forms */
    @media (max-width: 768px) {
      .form-grid,
      .form-grid-3 {
        grid-template-columns: 1fr;
      }
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

    .form-textarea {
      resize: vertical;
      min-height: 100px;
    }

    .form-input[type="number"] {
      font-family: 'SF Mono', Monaco, 'Courier New', monospace;
    }

    .form-input:disabled,
    .form-textarea:disabled {
      background: var(--bg-secondary);
      color: var(--text-tertiary);
      cursor: not-allowed;
    }

    /* File Upload */
    .file-upload {
      position: relative;
      border: 2px dashed var(--border);
      border-radius: var(--radius);
      padding: 32px 24px;
      text-align: center;
      background: var(--bg-secondary);
      transition: var(--transition);
      cursor: pointer;
    }

    .file-upload:hover {
      border-color: var(--primary);
      background: var(--primary-lighter);
    }

    .file-upload input[type="file"] {
      position: absolute;
      inset: 0;
      opacity: 0;
      cursor: pointer;
    }

    .file-upload-icon {
      width: 48px;
      height: 48px;
      background: var(--card-bg);
      border-radius: var(--radius);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 24px;
      margin: 0 auto 12px;
    }

    .file-upload-text {
      font-size: 14px;
      font-weight: 500;
      color: var(--text-primary);
      margin-bottom: 4px;
    }

    .file-upload-hint {
      font-size: 12px;
      color: var(--text-secondary);
    }

    .image-preview {
      margin-top: 16px;
      max-width: 200px;
      border-radius: var(--radius);
      overflow: hidden;
      box-shadow: var(--shadow-md);
      display: none;
    }

    .image-preview img {
      width: 100%;
      height: auto;
      display: block;
    }

    /* Toggle Switch */
    .toggle-group {
      display: flex;
      align-items: flex-start;
      gap: 16px;
      padding: 16px;
      background: var(--bg-secondary);
      border-radius: var(--radius);
    }

    .toggle-content {
      flex: 1;
      min-width: 0;
    }

    .toggle-title {
      font-size: 14px;
      font-weight: 500;
      color: var(--text-primary);
      margin-bottom: 4px;
    }

    .toggle-description {
      font-size: 12px;
      color: var(--text-secondary);
      line-height: 1.4;
    }

    .toggle-switch {
      width: 44px;
      height: 24px;
      background: #cbd5e1;
      border-radius: 12px;
      position: relative;
      cursor: pointer;
      transition: var(--transition);
      flex-shrink: 0;
    }

    .toggle-switch input {
      opacity: 0;
      width: 0;
      height: 0;
      position: absolute;
    }

    .toggle-slider {
      position: absolute;
      top: 2px;
      left: 2px;
      width: 20px;
      height: 20px;
      background: white;
      border-radius: 50%;
      transition: var(--transition);
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }

    .toggle-switch input:checked ~ .toggle-slider {
      transform: translateX(20px);
    }

    .toggle-switch.checked {
      background: var(--primary);
    }

    /* Selection Grid */
    .selection-grid {
      border: 1px solid var(--border);
      border-radius: var(--radius);
      max-height: 200px;
      overflow-y: auto;
      background: var(--card-bg);
    }

    .selection-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px 16px;
      border-bottom: 1px solid var(--border-light);
      cursor: pointer;
      transition: var(--transition);
    }

    .selection-item:last-child {
      border-bottom: none;
    }

    .selection-item:hover {
      background: var(--hover);
    }

    .selection-item.selected {
      background: var(--primary-lighter);
    }

    .selection-item input[type="checkbox"] {
      width: 16px;
      height: 16px;
      accent-color: var(--primary);
      cursor: pointer;
    }

    .selection-item label {
      flex: 1;
      cursor: pointer;
      font-size: 14px;
      color: var(--text-primary);
    }

    /* Empty State */
    .empty-state {
      padding: 40px 20px;
      text-align: center;
      color: var(--text-secondary);
    }

    .empty-state-text {
      font-size: 14px;
      margin-bottom: 12px;
    }

    .empty-state-link {
      color: var(--primary);
      text-decoration: none;
      font-weight: 500;
    }

    .empty-state-link:hover {
      text-decoration: underline;
    }

    /* Modifiers Section */
    .modifiers-container {
      background: var(--bg-secondary);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 20px;
    }

    .modifier-row {
      display: flex;
      align-items: center;
      gap: 12px;
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 16px;
      margin-bottom: 12px;
    }

    /* IMPROVED: Responsive modifier rows */
    @media (max-width: 768px) {
      .modifier-row {
        flex-direction: column;
        align-items: stretch;
        gap: 12px;
      }
    }

    .modifier-select {
      min-width: 180px;
      padding: 10px 12px;
      border: 1px solid var(--border);
      border-radius: var(--radius);
      font-size: 14px;
      background: var(--card-bg);
      color: var(--text-primary);
    }

    @media (max-width: 768px) {
      .modifier-select {
        min-width: auto;
        width: 100%;
      }
    }

    .tags-container {
      flex: 1;
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      padding: 12px;
      border: 1px solid var(--border);
      border-radius: var(--radius);
      min-height: 44px;
      background: var(--card-bg);
      align-items: center;
    }

    .tag {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 8px;
      background: var(--primary-light);
      color: var(--primary);
      border-radius: 12px;
      font-size: 12px;
      font-weight: 500;
    }

    .tag-remove {
      cursor: pointer;
      width: 16px;
      height: 16px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
      transition: var(--transition);
    }

    .tag-remove:hover {
      background: rgba(255, 255, 255, 0.2);
    }

    .empty-tags {
      color: var(--text-tertiary);
      font-size: 13px;
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
      white-space: nowrap;
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

    .btn-secondary {
      background: var(--card-bg);
      color: var(--text-primary);
      border-color: var(--border);
    }

    .btn-secondary:hover {
      background: var(--hover);
      color: var(--text-primary);
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

    .btn-danger {
      background: var(--card-bg);
      color: var(--danger);
      border-color: var(--border);
    }

    .btn-danger:hover {
      background: var(--danger-light);
      color: var(--danger);
      border-color: #fca5a5;
      text-decoration: none;
    }

    .btn-sm {
      padding: 8px 16px;
      font-size: 13px;
    }

    .btn-add {
      background: transparent;
      color: var(--primary);
      border: 2px dashed var(--primary);
      border-radius: var(--radius);
      padding: 12px 20px;
    }

    .btn-add:hover {
      background: var(--primary-lighter);
      box-shadow: none;
    }

    /* IMPROVED: Responsive form footer */
    .form-footer {
      padding: 20px;
      background: var(--bg-secondary);
      border-top: 1px solid var(--border);
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      flex-wrap: wrap;
    }

    .form-actions {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
    }

    @media (max-width: 768px) {
      .form-footer {
        flex-direction: column-reverse;
        gap: 12px;
      }
      
      .form-actions {
        width: 100%;
        flex-direction: column;
      }
    }

    /* Help Text */
    .help-text {
      font-size: 12px;
      color: var(--text-secondary);
      margin-top: 4px;
      line-height: 1.4;
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
  </style>
</head>
<body>

<?php
// Include the fixed admin navigation
$active = 'products';
try {
    require __DIR__ . '/../../partials/admin_nav.php';
} catch (Throwable $e) {
    echo "<div class='alert error'>Navigation error: " . h($e->getMessage()) . "</div>";
}
?>

<!-- Create Product Page Content -->
<div class="create-product-container">
  <?php if ($flash): ?>
    <div class="alert success">
      <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
      <?= h($flash) ?>
    </div>
  <?php endif; ?>

  <?php if ($error_msg): ?>
    <div class="alert error">
      <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.314 16.5c-.77.833.192 2.5 1.732 2.5z"/>
      </svg>
      <?= h($error_msg) ?>
    </div>
  <?php endif; ?>

  <!-- Page Header (REMOVED BREADCRUMBS) -->
  <div class="page-header">
    <h1 class="page-title">Add Product</h1>
    <p class="page-subtitle">Create a new product for your catalog</p>
  </div>

  <!-- Form -->
  <form method="post" action="/controllers/admin/products_save.php" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    
    <div class="form-card">
      <!-- Basic Information -->
      <div class="form-section">
        <div class="section-header">
          <div class="section-icon">📝</div>
          <div>
            <div class="section-title">Basic Information</div>
            <div class="section-subtitle">Essential product details</div>
          </div>
        </div>
        
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">
              Product Name (English) 
              <span class="required">*</span>
            </label>
            <input type="text" name="name_en" class="form-input" required 
                   placeholder="Enter product name" autofocus>
          </div>
          
          <div class="form-group">
            <label class="form-label">
              Product Name (Arabic)
              <span class="optional">Optional</span>
            </label>
            <input type="text" name="name_ar" class="form-input" 
                   placeholder="اسم المنتج" dir="rtl">
          </div>
          
          <div class="form-group">
            <label class="form-label">
              Description (English)
              <span class="optional">Optional</span>
            </label>
            <textarea name="description" class="form-textarea" 
                      placeholder="Add product description..."></textarea>
          </div>
          
          <div class="form-group">
            <label class="form-label">
              Description (Arabic)
              <span class="optional">Optional</span>
            </label>
            <textarea name="description_ar" class="form-textarea" dir="rtl"
                      placeholder="وصف المنتج..."></textarea>
          </div>
        </div>
      </div>

      <!-- Product Image -->
      <div class="form-section">
        <div class="section-header">
          <div class="section-icon">🖼️</div>
          <div>
            <div class="section-title">Product Image</div>
            <div class="section-subtitle">Upload an image for your product</div>
          </div>
        </div>
        
        <div class="form-group">
          <div class="file-upload">
            <input type="file" name="image" id="productImage" accept="image/jpeg,image/jpg,image/png,image/webp">
            <div class="file-upload-icon">📷</div>
            <div class="file-upload-text">Click to upload or drag and drop</div>
            <div class="file-upload-hint">JPG, PNG or WebP (max 5MB)</div>
          </div>
          <div class="image-preview" id="imagePreview">
            <img src="" alt="Preview" id="previewImg">
          </div>
        </div>
      </div>

      <!-- Pricing -->
      <div class="form-section">
        <div class="section-header">
          <div class="section-icon">💰</div>
          <div>
            <div class="section-title">Pricing</div>
            <div class="section-subtitle">Set pricing information</div>
          </div>
        </div>
        
        <div class="form-group full">
          <div class="toggle-group">
            <div class="toggle-content">
              <div class="toggle-title">Open Price Product</div>
              <div class="toggle-description">Price is entered at the point of sale</div>
            </div>
            <label class="toggle-switch" id="openPriceToggle">
              <input type="checkbox" name="is_open_price" value="1" id="openPriceCheckbox">
              <span class="toggle-slider"></span>
            </label>
          </div>
        </div>
        
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">
              Selling Price
              <span class="required" id="priceRequired">*</span>
            </label>
            <input type="number" name="price" class="form-input" id="priceInput"
                   required step="0.01" min="0" placeholder="0.00">
          </div>
          
          <div class="form-group">
            <label class="form-label">
              Cost Price
              <span class="optional">Optional</span>
            </label>
            <input type="number" name="standard_cost" class="form-input" id="costInput"
                   step="0.01" min="0" placeholder="0.00">
            <div class="help-text">Used for profit calculations</div>
          </div>
        </div>
      </div>

      <!-- Product Details -->
      <div class="form-section">
        <div class="section-header">
          <div class="section-icon">📊</div>
          <div>
            <div class="section-title">Product Details</div>
            <div class="section-subtitle">Additional product specifications</div>
          </div>
        </div>
        
        <div class="form-grid-3">
          <div class="form-group">
            <label class="form-label">
              Weight (kg)
              <span class="optional">Optional</span>
            </label>
            <input type="number" name="weight_kg" class="form-input" 
                   step="0.001" min="0" placeholder="0.000">
          </div>
          
          <div class="form-group">
            <label class="form-label">
              Calories
              <span class="optional">Optional</span>
            </label>
            <input type="number" name="calories" class="form-input" 
                   min="0" placeholder="0">
          </div>
          
          <div class="form-group">
            <label class="form-label">
              Prep Time (minutes)
              <span class="optional">Optional</span>
            </label>
            <input type="number" name="prep_time_min" class="form-input" 
                   min="0" placeholder="0">
          </div>
        </div>
      </div>

      <!-- Categories & Branches -->
      <div class="form-section">
        <div class="section-header">
          <div class="section-icon">🏷️</div>
          <div>
            <div class="section-title">Organization</div>
            <div class="section-subtitle">Categorize and assign to branches</div>
          </div>
        </div>
        
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Categories</label>
            <div class="selection-grid">
              <?php if (empty($categories)): ?>
                <div class="empty-state">
                  <div class="empty-state-text">No categories available</div>
                  <a href="/views/admin/catalog/categories.php" class="empty-state-link">
                    Create a category first →
                  </a>
                </div>
              <?php else: ?>
                <?php foreach ($categories as $cat): ?>
                  <div class="selection-item" data-checkbox="cat_<?= $cat['id'] ?>">
                    <input type="checkbox" id="cat_<?= $cat['id'] ?>" 
                           name="categories[]" value="<?= $cat['id'] ?>">
                    <label for="cat_<?= $cat['id'] ?>"><?= h($cat['name']) ?></label>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
          
          <div class="form-group">
            <label class="form-label">Branches</label>
            <div class="selection-grid">
              <?php if (empty($branches)): ?>
                <div class="empty-state">
                  <div class="empty-state-text">No branches configured</div>
                </div>
              <?php else: ?>
                <?php foreach ($branches as $branch): ?>
                  <div class="selection-item selected" data-checkbox="br_<?= $branch['id'] ?>">
                    <input type="checkbox" id="br_<?= $branch['id'] ?>" 
                           name="branches[]" value="<?= $branch['id'] ?>" checked>
                    <label for="br_<?= $branch['id'] ?>"><?= h($branch['name']) ?></label>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Product Modifiers -->
      <?php if (!empty($variationGroups)): ?>
      <div class="form-section">
        <div class="section-header">
          <div class="section-icon">🔧</div>
          <div>
            <div class="section-title">Product Modifiers</div>
            <div class="section-subtitle">Configure product variations and options</div>
          </div>
        </div>
        
        <div class="modifiers-container">
          <div id="modifiersContainer">
            <!-- Modifier rows will be added here -->
          </div>
          
          <button type="button" class="btn btn-add" onclick="addModifierRow()">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path d="M12 5v14m7-7H5"/>
            </svg>
            Add Modifier
          </button>
        </div>
      </div>
      <?php endif; ?>

      <!-- Inventory Settings -->
      <div class="form-section">
        <div class="section-header">
          <div class="section-icon">📦</div>
          <div>
            <div class="section-title">Inventory Settings</div>
            <div class="section-subtitle">Configure inventory tracking</div>
          </div>
        </div>
        
        <div class="form-grid">
          <div class="form-group">
            <div class="toggle-group">
              <div class="toggle-content">
                <div class="toggle-title">Track Inventory</div>
                <div class="toggle-description">Monitor stock levels for this product</div>
              </div>
              <label class="toggle-switch" id="inventoryToggle">
                <input type="checkbox" name="is_inventory_tracked" value="1">
                <span class="toggle-slider"></span>
              </label>
            </div>
          </div>
          
          <div class="form-group">
            <label class="form-label">Inventory Unit</label>
            <select name="inventory_unit" class="form-select">
              <option value="piece">Piece</option>
              <option value="kg">Kilogram</option>
              <option value="liter">Liter</option>
              <option value="pack">Pack</option>
              <option value="box">Box</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Status Settings -->
      <div class="form-section">
        <div class="section-header">
          <div class="section-icon">⚙️</div>
          <div>
            <div class="section-title">Status Settings</div>
            <div class="section-subtitle">Configure product availability</div>
          </div>
        </div>
        
        <div class="form-grid">
          <div class="form-group">
            <div class="toggle-group">
              <div class="toggle-content">
                <div class="toggle-title">Product Status</div>
                <div class="toggle-description">Active products can be sold</div>
              </div>
              <label class="toggle-switch checked" id="activeToggle">
                <input type="checkbox" name="is_active" value="1" checked>
                <span class="toggle-slider"></span>
              </label>
            </div>
          </div>
          
          <div class="form-group">
            <div class="toggle-group">
              <div class="toggle-content">
                <div class="toggle-title">POS Visibility</div>
                <div class="toggle-description">Show in point of sale system</div>
              </div>
              <label class="toggle-switch checked" id="visToggle">
                <input type="checkbox" name="pos_visible" value="1" checked>
                <span class="toggle-slider"></span>
              </label>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Form Footer -->
    <div class="form-footer">
      <a href="/views/admin/catalog/products.php" class="btn btn-ghost">Cancel</a>
      <div class="form-actions">
        <button type="submit" name="action" value="save_and_new" class="btn btn-secondary">
          Save & Add Another
        </button>
        <button type="submit" name="action" value="save" class="btn btn-primary">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M5 12l5 5L20 7"/>
          </svg>
          Create Product
        </button>
      </div>
    </div>
  </form>
</div><!-- /.create-product-container -->

<?php
// Close the admin layout
require __DIR__ . '/../../partials/admin_nav_close.php';
?>

<script>
// Store variation groups data for modifiers
<?php if (!empty($variationGroups)): ?>
const variationGroups = <?= json_encode($variationGroups) ?>;
let modifierRowIndex = 0;

function addModifierRow(preselectedGroupId = null, preselectedValues = [], defaultValue = null) {
  const container = document.getElementById('modifiersContainer');
  const rowId = 'modifier_row_' + modifierRowIndex;
  
  const row = document.createElement('div');
  row.className = 'modifier-row';
  row.id = rowId;
  
  // Build group options
  let groupOptions = '<option value="">Select a modifier group...</option>';
  Object.keys(variationGroups).forEach(id => {
    const selected = id == preselectedGroupId ? 'selected' : '';
    groupOptions += `<option value="${id}" ${selected}>${variationGroups[id].name}</option>`;
  });
  
  row.innerHTML = `
    <select class="modifier-select" id="group_${modifierRowIndex}" onchange="updateModifierRow(${modifierRowIndex})">
      ${groupOptions}
    </select>
    <div class="tags-container" id="tags_${modifierRowIndex}">
      <span class="empty-tags">Select a modifier group</span>
    </div>
    <select class="form-select" id="default_${modifierRowIndex}" name="modifier_defaults[${modifierRowIndex}]">
      <option value="">No default</option>
    </select>
    <button type="button" class="btn btn-sm btn-danger" onclick="removeModifierRow('${rowId}')">
      Remove
    </button>
  `;
  
  container.appendChild(row);
  
  // If preselected, update the row
  if (preselectedGroupId) {
    updateModifierRow(modifierRowIndex, preselectedValues, defaultValue);
  }
  
  modifierRowIndex++;
}

function updateModifierRow(index, preselectedValues = [], defaultValue = null) {
  const groupSelect = document.getElementById('group_' + index);
  const tagsContainer = document.getElementById('tags_' + index);
  const defaultSelect = document.getElementById('default_' + index);
  
  const groupId = groupSelect.value;
  
  if (!groupId) {
    tagsContainer.innerHTML = '<span class="empty-tags">Select a modifier group</span>';
    defaultSelect.innerHTML = '<option value="">No default</option>';
    defaultSelect.name = `modifier_defaults[${index}]`;
    return;
  }
  
  const group = variationGroups[groupId];
  if (!group) return;
  
  // Clear tags container
  tagsContainer.innerHTML = '';
  tagsContainer.dataset.groupId = groupId;
  
  // Add all values as tags (or preselected ones)
  const valuesToAdd = preselectedValues.length > 0 ? 
    group.values.filter(v => preselectedValues.includes(v.id.toString())) : 
    group.values;
  
  valuesToAdd.forEach(value => {
    addTag(index, groupId, value);
  });
  
  // Update default dropdown
  updateDefaultDropdown(index, defaultValue);
  
  // Update the name attribute for form submission
  defaultSelect.name = `modifier_defaults[${groupId}]`;
}

function addTag(rowIndex, groupId, value) {
  const tagsContainer = document.getElementById('tags_' + rowIndex);
  
  const tag = document.createElement('div');
  tag.className = 'tag';
  tag.dataset.valueId = value.id;
  tag.dataset.valueName = value.name;
  
  const priceText = value.price_delta != 0 ? ` (+${value.price_delta})` : '';
  
  tag.innerHTML = `
    ${value.name}${priceText}
    <span class="tag-remove" onclick="removeTag(${rowIndex}, ${groupId}, ${value.id}, this)">×</span>
    <input type="hidden" name="modifiers[${groupId}][${rowIndex}][]" value="${value.id}">
  `;
  
  tagsContainer.appendChild(tag);
}

function removeTag(rowIndex, groupId, valueId, element) {
  const tag = element.closest('.tag');
  tag.remove();
  
  // Update default dropdown
  updateDefaultDropdown(rowIndex);
}

function updateDefaultDropdown(rowIndex, selectedValue = null) {
  const tagsContainer = document.getElementById('tags_' + rowIndex);
  const defaultSelect = document.getElementById('default_' + rowIndex);
  
  // Get remaining tags
  const tags = tagsContainer.querySelectorAll('.tag');
  
  // Clear and rebuild default options
  defaultSelect.innerHTML = '<option value="">No default</option>';
  
  tags.forEach(tag => {
    const valueId = tag.dataset.valueId;
    const valueName = tag.dataset.valueName;
    const selected = valueId == selectedValue ? 'selected' : '';
    defaultSelect.innerHTML += `<option value="${valueId}" ${selected}>${valueName}</option>`;
  });
}

function removeModifierRow(rowId) {
  const row = document.getElementById(rowId);
  if (row) {
    row.remove();
  }
}
<?php endif; ?>

// Image preview
document.getElementById('productImage').addEventListener('change', function(e) {
  const file = e.target.files[0];
  const preview = document.getElementById('imagePreview');
  const previewImg = document.getElementById('previewImg');
  
  if (file && file.type.match('image.*')) {
    const reader = new FileReader();
    reader.onload = function(e) {
      previewImg.src = e.target.result;
      preview.style.display = 'block';
    };
    reader.readAsDataURL(file);
  } else {
    preview.style.display = 'none';
  }
});

// Open Price toggle
document.getElementById('openPriceCheckbox').addEventListener('change', function() {
  const priceInput = document.getElementById('priceInput');
  const costInput = document.getElementById('costInput');
  const priceRequired = document.getElementById('priceRequired');
  
  if (this.checked) {
    priceInput.value = '0';
    priceInput.disabled = true;
    priceInput.removeAttribute('required');
    priceRequired.style.display = 'none';
    
    costInput.value = '0';
    costInput.disabled = true;
  } else {
    priceInput.disabled = false;
    priceInput.setAttribute('required', '');
    priceRequired.style.display = 'inline';
    
    costInput.disabled = false;
  }
});

// Interactive selection items for categories/branches
document.querySelectorAll('.selection-item').forEach(item => {
  const checkbox = item.querySelector('input[type="checkbox"]');
  
  item.addEventListener('click', (e) => {
    if (e.target.tagName !== 'INPUT') {
      checkbox.checked = !checkbox.checked;
    }
    item.classList.toggle('selected', checkbox.checked);
  });
  
  // Initial state
  if (checkbox.checked) {
    item.classList.add('selected');
  }
});

// Toggle switches
document.querySelectorAll('.toggle-switch').forEach(toggle => {
  const checkbox = toggle.querySelector('input[type="checkbox"]');
  
  checkbox.addEventListener('change', () => {
    toggle.classList.toggle('checked', checkbox.checked);
  });
  
  // Initial state
  if (checkbox.checked) {
    toggle.classList.add('checked');
  }
});

// Auto-format price inputs
document.querySelectorAll('input[type="number"]').forEach(input => {
  input.addEventListener('blur', () => {
    if (input.value && input.step === '0.01') {
      input.value = parseFloat(input.value).toFixed(2);
    }
  });
});

// Handle responsive navigation changes
const handleNavToggle = () => {
  const container = document.querySelector('.create-product-container');
  if (container) {
    // Force a small reflow to ensure proper width calculation
    container.style.transition = 'all 0.3s ease';
  }
};

// Listen for navigation changes if your admin nav dispatches events
document.addEventListener('navToggle', handleNavToggle);
</script>
</body>
</html>