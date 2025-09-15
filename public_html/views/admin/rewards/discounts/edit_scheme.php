<?php
// Edit Program - Modern Clean Design
// Path: /public_html/views/admin/rewards/discounts/edit_scheme.php
declare(strict_types=1);

require_once __DIR__ . '/_shared/common.php';

// Set active navigation
$active = 'rewards_discounts_view';

$id = (int)($_GET['id'] ?? 0);
$creating = $id === 0;

// Load program data if editing
$program = [
    'name' => '',
    'status' => 'inactive',
    'start_at' => date('Y-m-d'),
    'end_at' => '',
    'description' => '',
    'earn_rate' => '1.0',
    'redeem_rate' => '0',
    'channels' => ['POS', 'Online']
];

if ($id && $pdo instanceof PDO) {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM discount_schemes 
            WHERE tenant_id = :tid AND id = :id 
            LIMIT 1
        ");
        $stmt->execute([':tid' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            $program = array_merge($program, $row);
            
            // Parse channels if stored as string
            if (!empty($row['channels']) && is_string($row['channels'])) {
                $decoded = json_decode($row['channels'], true);
                if (is_array($decoded)) {
                    $program['channels'] = $decoded;
                } else {
                    $program['channels'] = array_map('trim', explode(',', $row['channels']));
                }
            }
        }
    } catch (Throwable $e) { /* ignore */ }
}

$pageTitle = $creating ? 'Create New Program' : 'Edit Program';

// Include admin navigation (this opens the layout)
include_admin_nav();
?>

<!-- Additional CSS for this page -->
<link rel="stylesheet" href="<?= h(asset_url('styles.css')) ?>">
<style>
/* Override admin nav styles if needed */
.admin-content {
    background: var(--bg-primary, #f8f9fa) !important;
}
</style>

<div class="discount-container">
    <div class="page-wrapper">
        <div class="tab-content">
            <div class="page-header">
                <h1 class="page-title"><?= h($pageTitle) ?></h1>
                <a href="/views/admin/rewards/discounts/index.php?tab=programs" class="btn btn-secondary">
                    ← Back to Programs
                </a>
            </div>

            <form method="post" action="/controllers/admin/rewards/discounts/save_scheme.php" class="program-form">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="tenant_id" value="<?= (int)$tenantId ?>">
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                
                <div class="card">
                    <h2 class="card-title">Program Details</h2>
                    
                    <div class="form-grid form-grid-2">
                        <div class="form-group">
                            <label class="form-label" for="program_name">Program Name</label>
                            <input type="text" id="program_name" name="name" class="form-input" 
                                   value="<?= h($program['name']) ?>"
                                   placeholder="e.g., VIP Gold Program" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="program_status">Status</label>
                            <select id="program_status" name="status" class="form-select">
                                <option value="active" <?= $program['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $program['status'] !== 'active' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="program_description">Description</label>
                        <textarea id="program_description" name="description" class="form-textarea" 
                                  placeholder="Enter program description..."><?= h($program['description']) ?></textarea>
                    </div>
                </div>
                
                <div style="display: flex; justify-content: space-between; margin-top: 24px;">
                    <div>
                        <?php if (!$creating): ?>
                            <button type="button" class="btn btn-danger-text" 
                                    onclick="if(confirm('Delete this program?')) { window.location.href='/controllers/admin/rewards/discounts/delete_scheme.php?id=<?= $id ?>'; }">
                                Delete Program
                            </button>
                        <?php endif; ?>
                    </div>
                    <div style="display: flex; gap: 12px;">
                        <button type="button" class="btn btn-secondary" 
                                onclick="window.location.href='/views/admin/rewards/discounts/index.php?tab=programs'">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <?= $creating ? 'Create' : 'Save' ?> Program
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php 
// Close the admin layout (closes divs opened by admin_nav.php)
close_admin_layout();
?>