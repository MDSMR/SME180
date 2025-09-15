<?php
// /views/admin/rewards/discounts/create_program.php
// Unified discount program creation with proper alignment
declare(strict_types=1);

require_once __DIR__ . '/_shared/common.php';

if (!$bootstrap_ok) {
    http_response_code(500);
    die('<h1>Bootstrap Failed</h1><p>' . h($bootstrap_warning) . '</p>');
}

if (!isset($user) || !isset($tenantId)) {
    header('Location: /views/auth/login.php');
    exit;
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

// Load data
$branches = [];
$customers = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM branches WHERE tenant_id = ? AND is_active = 1 ORDER BY name");
    $stmt->execute([$tenantId]);
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("SELECT id, name, phone, email, classification FROM customers WHERE tenant_id = ? ORDER BY name");
    $stmt->execute([$tenantId]);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {
    error_log("Data loading error: " . $e->getMessage());
}

// Calculate default valid until date (180 days from today)
$defaultValidUntil = date('Y-m-d', strtotime('+180 days'));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Discount Program · Smorll POS</title>
    <link rel="stylesheet" href="_shared/styles.css">
    <style>
    /* Navigation tabs styling */
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
    
    /* Form alignment fixes */
    .form-row, .form-row-2, .form-row-3, .form-row-aligned {
        display: grid;
        gap: 20px;
        align-items: start;
    }
    
    .form-row-2 {
        grid-template-columns: 1fr 1fr;
    }
    
    .form-row-3 {
        grid-template-columns: 1fr 1fr 2fr;
    }
    
    .form-row-aligned {
        grid-template-columns: 1fr 1fr;
    }
    
    /* Ensure all form groups have consistent structure */
    .form-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    
    .form-group label {
        font-weight: 600;
        font-size: 13px;
        color: var(--ms-gray-160);
        line-height: 1.4;
        min-height: 18px;
        display: flex;
        align-items: center;
    }
    
    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid var(--ms-gray-60);
        border-radius: var(--ms-radius);
        font-size: 14px;
        line-height: 1.4;
        height: 42px;
        background: white;
    }
    
    .form-group textarea {
        height: auto;
        min-height: 42px;
    }
    
    .form-group .hint {
        font-size: 12px;
        color: var(--ms-gray-110);
        line-height: 1.4;
        margin: 0;
    }
    
    /* Multi-select styling */
    .multi-select-container {
        position: relative;
        flex: 1;
    }
    
    .multi-select-display {
        min-height: 42px;
        max-height: 42px;
        padding: 10px 12px;
        border: 1px solid var(--ms-gray-60);
        border-radius: var(--ms-radius);
        background: white;
        cursor: pointer;
        display: flex;
        flex-wrap: nowrap;
        gap: 6px;
        align-items: center;
        overflow: hidden;
    }
    
    .multi-select-display:hover {
        border-color: var(--ms-blue);
    }
    
    .multi-select-display.focused {
        border-color: var(--ms-blue);
        box-shadow: 0 0 0 2px rgba(0,120,212,0.1);
    }
    
    .selected-tag {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 8px;
        background: var(--ms-blue);
        color: white;
        border-radius: 4px;
        font-size: 12px;
        white-space: nowrap;
    }
    
    .selected-tag .remove {
        cursor: pointer;
        font-weight: bold;
        margin-left: 2px;
        font-size: 14px;
    }
    
    .multi-select-placeholder {
        color: var(--ms-gray-110);
        font-size: 14px;
    }
    
    .multi-select-dropdown {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        max-height: 300px;
        overflow-y: auto;
        background: white;
        border: 1px solid var(--ms-gray-60);
        border-radius: var(--ms-radius);
        box-shadow: var(--ms-shadow-3);
        z-index: 1000;
        margin-top: 4px;
        display: none;
    }
    
    .multi-select-dropdown.open {
        display: block;
    }
    
    .multi-select-search {
        padding: 12px;
        border-bottom: 1px solid var(--ms-gray-30);
        position: sticky;
        top: 0;
        background: white;
        z-index: 1;
    }
    
    .multi-select-search input {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid var(--ms-gray-60);
        border-radius: var(--ms-radius);
        font-size: 14px;
    }
    
    .multi-select-option {
        padding: 10px 12px;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: background 0.1s;
    }
    
    .multi-select-option:hover {
        background: var(--ms-gray-10);
    }
    
    .multi-select-option.selected {
        background: var(--ms-blue-lighter);
    }
    
    .multi-select-option-label {
        flex: 1;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .multi-select-option-info {
        font-size: 11px;
        color: var(--ms-gray-110);
    }
    
    /* Time fields special alignment */
    .time-fields-row {
        display: grid;
        grid-template-columns: 200px 200px 1fr;
        gap: 20px;
        align-items: start;
    }
    
    /* Days selector alignment */
    .days-selector {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
        min-height: 42px;
        align-items: center;
    }
    
    .day-checkbox {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 8px 10px;
        height: 38px;
        border: 1px solid var(--ms-gray-60);
        border-radius: var(--ms-radius);
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .day-checkbox:hover {
        border-color: var(--ms-blue);
        background: var(--ms-gray-10);
    }
    
    .day-checkbox input:checked + span {
        color: var(--ms-blue);
        font-weight: 600;
    }
    
    .day-checkbox input:checked {
        accent-color: var(--ms-blue);
    }
    
    /* Conditional fields */
    .conditional-field {
        display: none;
        animation: fadeIn 0.3s ease-out;
    }
    
    .conditional-field.active {
        display: block;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .info-box {
        background: var(--ms-blue-lighter);
        border-left: 3px solid var(--ms-blue);
        padding: 12px 16px;
        margin-bottom: 16px;
        margin-top: 20px;
        font-size: 13px;
        color: var(--ms-blue-darker);
    }
    
    /* Card spacing */
    .card-body > .form-row,
    .card-body > .form-row-2,
    .card-body > .form-row-3,
    .card-body > .form-row-aligned {
        margin-bottom: 20px;
    }
    
    .card-body > .form-row:last-child,
    .card-body > .form-row-2:last-child,
    .card-body > .form-row-3:last-child,
    .card-body > .form-row-aligned:last-child {
        margin-bottom: 0;
    }
    </style>
</head>
<body>

<?php 
$active = 'rewards';
include_admin_nav($active);
?>

<div class="container">
    <div class="h1">Discount Programs</div>
    <p class="sub">Create and manage discount schemes for your business.</p>

    <!-- Navigation Tabs -->
    <div class="discount-nav">
        <a href="index.php" class="discount-nav-tab">Programs</a>
        <a href="create_program.php" class="discount-nav-tab active">Create Program</a>
        <a href="reports.php" class="discount-nav-tab">Reports</a>
    </div>

    <form method="post" action="save_unified_discount.php" id="discountForm">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="tenant_id" value="<?= (int)$tenantId ?>">
        
        <!-- Main Configuration -->
        <div class="card">
            <h2 class="card-title" style="padding: 20px; margin: 0; border-bottom: 1px solid var(--ms-gray-30);">
                Program Configuration
            </h2>
            <div class="card-body" style="padding: 20px;">
                <!-- First Row -->
                <div class="form-row-2">
                    <div class="form-group">
                        <label for="program_type">Program Type *</label>
                        <select id="program_type" name="program_type" required onchange="handleTypeChange(this.value)">
                            <option value="">Select type...</option>
                            <option value="scheme">Customer Scheme (VIP/Corporate)</option>
                            <option value="manual">Manual Discount</option>
                            <option value="time">Time-Based Automatic</option>
                        </select>
                        <div class="hint">Choose how this discount will be applied</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="code">Program Code *</label>
                        <input type="text" id="code" name="code" required 
                               placeholder="e.g., VIP10" maxlength="32"
                               pattern="[A-Z0-9_-]+" style="text-transform:uppercase;">
                        <div class="hint">Unique identifier for this program</div>
                    </div>
                </div>

                <!-- Second Row -->
                <div class="form-row-2">
                    <div class="form-group">
                        <label for="name">Program Name *</label>
                        <input type="text" id="name" name="name" required 
                               placeholder="e.g., VIP Gold Discount" maxlength="100">
                        <div class="hint">Display name for staff and reports</div>
                    </div>

                    <div class="form-group">
                        <label for="discount_type">Discount Type *</label>
                        <select id="discount_type" name="discount_type" required>
                            <option value="percent">Percentage (%)</option>
                            <option value="fixed">Fixed Amount (<?= h($currency) ?>)</option>
                        </select>
                        <div class="hint">Percentage or fixed amount</div>
                    </div>
                </div>

                <!-- Third Row -->
                <div class="form-row-2">
                    <div class="form-group">
                        <label for="discount_value">Discount Value *</label>
                        <input type="number" id="discount_value" name="discount_value" required 
                               step="0.01" min="0" placeholder="10">
                        <div class="hint">Enter percentage (0-100) or fixed amount</div>
                    </div>

                    <div class="form-group">
                        <label for="is_stackable">Stackable</label>
                        <select id="is_stackable" name="is_stackable">
                            <option value="0">No - Exclusive discount</option>
                            <option value="1">Yes - Can combine</option>
                        </select>
                        <div class="hint">Can combine with other discounts</div>
                    </div>
                </div>

                <!-- Time-Based Fields (conditional) -->
                <div id="timeFields" class="conditional-field">
                    <div class="info-box">
                        ⏰ This discount applies automatically when orders are created during the specified time periods.
                    </div>
                    <div class="time-fields-row">
                        <div class="form-group">
                            <label for="start_time">Start Time *</label>
                            <input type="time" id="start_time" name="start_time">
                            <div class="hint">Daily start</div>
                        </div>
                        <div class="form-group">
                            <label for="end_time">End Time *</label>
                            <input type="time" id="end_time" name="end_time">
                            <div class="hint">Daily end</div>
                        </div>
                        <div class="form-group">
                            <label>Active Days *</label>
                            <div class="days-selector">
                                <?php 
                                $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                                foreach($days as $i => $day): 
                                ?>
                                <label class="day-checkbox">
                                    <input type="checkbox" name="active_days[]" value="<?= $i ?>">
                                    <span><?= $day ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Assignment Rules -->
        <div class="card">
            <h2 class="card-title" style="padding: 20px; margin: 0; border-bottom: 1px solid var(--ms-gray-30);">
                Assignment Rules
            </h2>
            <div class="card-body" style="padding: 20px;">
                <div class="form-row-aligned">
                    <!-- Branch Assignment -->
                    <div class="form-group">
                        <label>Branch Assignment</label>
                        <div class="multi-select-container">
                            <div class="multi-select-display" onclick="toggleBranchDropdown()" id="branchDisplay">
                                <span class="multi-select-placeholder">All Branches</span>
                            </div>
                            <div class="multi-select-dropdown" id="branchDropdown">
                                <div class="multi-select-search">
                                    <input type="text" placeholder="Search branches..." 
                                           onkeyup="filterBranchOptions(this.value)">
                                </div>
                                <div class="multi-select-option" onclick="toggleAllBranches()">
                                    <input type="checkbox" id="all_branches" checked>
                                    <div class="multi-select-option-label">
                                        <strong>All Branches</strong>
                                    </div>
                                </div>
                                <?php foreach($branches as $branch): ?>
                                <div class="multi-select-option branch-option" 
                                     data-search="<?= strtolower($branch['name']) ?>"
                                     onclick="toggleBranchSelection(<?= $branch['id'] ?>)">
                                    <input type="checkbox" id="branch_<?= $branch['id'] ?>" 
                                           name="branches[]" value="<?= $branch['id'] ?>">
                                    <div class="multi-select-option-label">
                                        <span><?= h($branch['name']) ?></span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="hint">Select branches where this discount applies</div>
                    </div>

                    <!-- Customer Assignment -->
                    <div class="form-group">
                        <label>Customer Assignment <span id="customerRequired" style="display:none; color: var(--ms-red);">*</span></label>
                        <div class="multi-select-container">
                            <div class="multi-select-display" onclick="toggleCustomerDropdown()" id="customerDisplay">
                                <span class="multi-select-placeholder">Select customers...</span>
                            </div>
                            <div class="multi-select-dropdown" id="customerDropdown">
                                <div class="multi-select-search">
                                    <input type="text" placeholder="Search customers..." 
                                           onkeyup="filterCustomerOptions(this.value)">
                                </div>
                                <?php foreach($customers as $customer): ?>
                                <div class="multi-select-option customer-option" 
                                     data-search="<?= strtolower($customer['name'] . ' ' . $customer['email'] . ' ' . $customer['phone']) ?>"
                                     onclick="toggleCustomerSelection(<?= $customer['id'] ?>)">
                                    <input type="checkbox" id="customer_<?= $customer['id'] ?>" 
                                           name="customers[]" value="<?= $customer['id'] ?>">
                                    <div class="multi-select-option-label">
                                        <span><?= h($customer['name']) ?></span>
                                        <span class="multi-select-option-info">
                                            <?= h($customer['classification']) ?>
                                        </span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="hint" id="customerHint">Optional for manual/time-based discounts</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Status and Validity -->
        <div class="card">
            <h2 class="card-title" style="padding: 20px; margin: 0; border-bottom: 1px solid var(--ms-gray-30);">
                Status & Validity
            </h2>
            <div class="card-body" style="padding: 20px;">
                <div class="form-row-2">
                    <div class="form-group">
                        <label for="is_active">Status</label>
                        <select id="is_active" name="is_active">
                            <option value="1">Active - Ready to use</option>
                            <option value="0">Inactive - Draft</option>
                        </select>
                        <div class="hint">Program availability status</div>
                    </div>
                    <div class="form-group">
                        <label for="valid_until">Valid Until</label>
                        <input type="date" id="valid_until" name="valid_until" value="<?= $defaultValidUntil ?>">
                        <div class="hint">Default: 180 days from creation</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Form Actions -->
        <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px;">
            <a href="index.php" class="btn">Cancel</a>
            <button type="submit" class="btn primary">Create Program</button>
        </div>
    </form>
</div>

<script>
// Multi-select state management
let selectedBranches = new Set();
let selectedCustomers = new Set();
let allBranchesSelected = true;

// Handle program type change
function handleTypeChange(type) {
    // Show/hide conditional fields
    document.getElementById('timeFields').classList.toggle('active', type === 'time');
    
    // Update customer field requirement
    const isScheme = type === 'scheme';
    document.getElementById('customerRequired').style.display = isScheme ? 'inline' : 'none';
    document.getElementById('customerHint').textContent = isScheme 
        ? 'Required - Select customers for this scheme' 
        : 'Optional for manual/time-based discounts';
}

// Branch selection
function toggleBranchDropdown() {
    const dropdown = document.getElementById('branchDropdown');
    const display = document.getElementById('branchDisplay');
    
    dropdown.classList.toggle('open');
    display.classList.toggle('focused');
    
    if (dropdown.classList.contains('open')) {
        setTimeout(() => {
            document.addEventListener('click', closeBranchDropdown);
        }, 100);
    }
}

function closeBranchDropdown(e) {
    if (!e.target.closest('#branchDropdown') && !e.target.closest('#branchDisplay')) {
        document.getElementById('branchDropdown').classList.remove('open');
        document.getElementById('branchDisplay').classList.remove('focused');
        document.removeEventListener('click', closeBranchDropdown);
    }
}

function toggleAllBranches() {
    allBranchesSelected = !allBranchesSelected;
    document.getElementById('all_branches').checked = allBranchesSelected;
    
    document.querySelectorAll('.branch-option input').forEach(checkbox => {
        checkbox.checked = false;
        checkbox.disabled = allBranchesSelected;
    });
    
    selectedBranches.clear();
    updateBranchDisplay();
    event.stopPropagation();
}

function toggleBranchSelection(branchId) {
    if (allBranchesSelected) {
        allBranchesSelected = false;
        document.getElementById('all_branches').checked = false;
        document.querySelectorAll('.branch-option input').forEach(cb => cb.disabled = false);
    }
    
    const checkbox = document.getElementById('branch_' + branchId);
    checkbox.checked = !checkbox.checked;
    
    if (checkbox.checked) {
        selectedBranches.add(branchId);
    } else {
        selectedBranches.delete(branchId);
    }
    
    updateBranchDisplay();
    event.stopPropagation();
}

function updateBranchDisplay() {
    const display = document.getElementById('branchDisplay');
    
    if (allBranchesSelected) {
        display.innerHTML = '<span class="selected-tag">All Branches</span>';
    } else if (selectedBranches.size === 0) {
        display.innerHTML = '<span class="multi-select-placeholder">Select branches...</span>';
    } else {
        const tags = Array.from(selectedBranches).slice(0, 3).map(id => {
            const checkbox = document.getElementById('branch_' + id);
            const option = checkbox.closest('.multi-select-option');
            const name = option.querySelector('span').textContent;
            return `<span class="selected-tag">${name} <span class="remove" onclick="removeBranch(${id}); event.stopPropagation();">×</span></span>`;
        });
        
        if (selectedBranches.size > 3) {
            tags.push(`<span style="color: var(--ms-gray-110); font-size: 12px;">+${selectedBranches.size - 3} more</span>`);
        }
        
        display.innerHTML = tags.join('');
    }
}

function removeBranch(branchId) {
    selectedBranches.delete(branchId);
    document.getElementById('branch_' + branchId).checked = false;
    updateBranchDisplay();
}

function filterBranchOptions(query) {
    const q = query.toLowerCase();
    document.querySelectorAll('.branch-option').forEach(option => {
        option.style.display = option.dataset.search.includes(q) ? 'flex' : 'none';
    });
}

// Customer selection
function toggleCustomerDropdown() {
    const dropdown = document.getElementById('customerDropdown');
    const display = document.getElementById('customerDisplay');
    
    dropdown.classList.toggle('open');
    display.classList.toggle('focused');
    
    if (dropdown.classList.contains('open')) {
        setTimeout(() => {
            document.addEventListener('click', closeCustomerDropdown);
        }, 100);
    }
}

function closeCustomerDropdown(e) {
    if (!e.target.closest('#customerDropdown') && !e.target.closest('#customerDisplay')) {
        document.getElementById('customerDropdown').classList.remove('open');
        document.getElementById('customerDisplay').classList.remove('focused');
        document.removeEventListener('click', closeCustomerDropdown);
    }
}

function toggleCustomerSelection(customerId) {
    const checkbox = document.getElementById('customer_' + customerId);
    checkbox.checked = !checkbox.checked;
    
    if (checkbox.checked) {
        selectedCustomers.add(customerId);
    } else {
        selectedCustomers.delete(customerId);
    }
    
    updateCustomerDisplay();
    event.stopPropagation();
}

function updateCustomerDisplay() {
    const display = document.getElementById('customerDisplay');
    
    if (selectedCustomers.size === 0) {
        display.innerHTML = '<span class="multi-select-placeholder">Select customers...</span>';
    } else {
        const tags = Array.from(selectedCustomers).slice(0, 3).map(id => {
            const checkbox = document.getElementById('customer_' + id);
            const option = checkbox.closest('.multi-select-option');
            const name = option.querySelector('span').textContent;
            return `<span class="selected-tag">${name} <span class="remove" onclick="removeCustomer(${id}); event.stopPropagation();">×</span></span>`;
        });
        
        if (selectedCustomers.size > 3) {
            tags.push(`<span style="color: var(--ms-gray-110); font-size: 12px;">+${selectedCustomers.size - 3} more</span>`);
        }
        
        display.innerHTML = tags.join('');
    }
}

function removeCustomer(customerId) {
    selectedCustomers.delete(customerId);
    document.getElementById('customer_' + customerId).checked = false;
    updateCustomerDisplay();
}

function filterCustomerOptions(query) {
    const q = query.toLowerCase();
    document.querySelectorAll('.customer-option').forEach(option => {
        option.style.display = option.dataset.search.includes(q) ? 'flex' : 'none';
    });
}

// Auto-uppercase code field
document.getElementById('code').addEventListener('input', function(e) {
    e.target.value = e.target.value.toUpperCase().replace(/[^A-Z0-9_-]/g, '');
});

// Form validation
document.getElementById('discountForm').addEventListener('submit', function(e) {
    const type = document.getElementById('discount_type').value;
    const value = parseFloat(document.getElementById('discount_value').value);
    
    if (type === 'percent' && (value <= 0 || value > 100)) {
        e.preventDefault();
        alert('Percentage must be between 0.01 and 100');
        return false;
    }
    
    if (type === 'fixed' && value <= 0) {
        e.preventDefault();
        alert('Fixed amount must be greater than 0');
        return false;
    }
    
    // Check if scheme type has customers selected
    const programType = document.getElementById('program_type').value;
    if (programType === 'scheme' && selectedCustomers.size === 0) {
        e.preventDefault();
        alert('Please select at least one customer for the scheme');
        return false;
    }
    
    // Check time-based fields
    if (programType === 'time') {
        const startTime = document.getElementById('start_time').value;
        const endTime = document.getElementById('end_time').value;
        const activeDays = document.querySelectorAll('input[name="active_days[]"]:checked');
        
        if (!startTime || !endTime) {
            e.preventDefault();
            alert('Please set both start and end times for time-based discount');
            return false;
        }
        
        if (activeDays.length === 0) {
            e.preventDefault();
            alert('Please select at least one active day');
            return false;
        }
    }
});

// Initialize displays
document.addEventListener('DOMContentLoaded', function() {
    updateBranchDisplay();
    updateCustomerDisplay();
});
</script>

</body>
</html>