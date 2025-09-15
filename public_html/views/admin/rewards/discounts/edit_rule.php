<?php
// Create/Update a Discount Rule — Microsoft 365 style
// Path: /public_html/views/admin/rewards/discounts/edit_rule.php
declare(strict_types=1);

require_once __DIR__ . '/_shared/common.php';

$id = (int)($_GET['id'] ?? 0);
$creating = $id === 0;

/* Load rule if editing */
$rule = [
  'name' => '',
  'status' => 'inactive',
  'rule_type' => 'percent',   // percent|amount|buyget
  'value' => '',
  'priority' => 100,
  'start_at' => date('Y-m-d'),
  'end_at' => '',
  'channels' => [],           // pos|online|aggregator
  'exclude_discounted' => 0,
  'exclude_aggregator' => 0,
  'notes' => '',
];
if ($id && $pdo instanceof PDO) {
  try {
    $stmt = $pdo->prepare("SELECT * FROM discount_rules WHERE tenant_id = :tid AND id = :id LIMIT 1");
    $stmt->execute([':tid'=>$tenantId, ':id'=>$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $rule = array_merge($rule, $row);
      if (!empty($row['channels']) && is_string($row['channels'])) {
        $rule['channels'] = array_filter(explode(',', $row['channels']));
      }
    }
  } catch (Throwable $e) { /* ignore if table missing */ }
}

page_start($creating ? 'New Rule' : 'Edit Rule', [
  ['label' => 'Back',  'href' => '/views/admin/rewards/discounts/index.php', 'type'=>'subtle'],
  ['label' => 'Save',  'href' => '#', 'type'=>'primary'],
  ['label' => 'Cancel','href' => '/views/admin/rewards/discounts/index.php', 'type'=>'default'],
]);
?>
<div class="ms-card">
  <form class="ms-form" method="post" action="/controllers/admin/rewards/discounts/save_rule.php" data-dirty-guard>
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"/>
    <input type="hidden" name="tenant_id" value="<?= (int)$tenantId ?>"/>
    <input type="hidden" name="id" value="<?= (int)$id ?>"/>

    <div class="ms-grid-3">
      <div class="ms-field">
        <label class="ms-label" for="name">Rule name</label>
        <input id="name" name="name" type="text" required value="<?= h($rule['name']) ?>" placeholder="e.g., Weekday Lunch -10%"/>
      </div>
      <div class="ms-field">
        <label class="ms-label" for="status">Status</label>
        <select id="status" name="status">
          <?php $status = (string)$rule['status']; ?>
          <option value="active"   <?= $status==='active'?'selected':'' ?>>Active</option>
          <option value="inactive" <?= $status!=='active'?'selected':'' ?>>Inactive</option>
        </select>
      </div>
      <div class="ms-field">
        <label class="ms-label" for="priority">Priority</label>
        <input id="priority" name="priority" type="number" min="0" step="1" value="<?= (int)$rule['priority'] ?>"/>
        <div class="ms-help">Lower number = higher priority.</div>
      </div>
    </div>

    <div class="ms-grid-3">
      <div class="ms-field">
        <label class="ms-label" for="rule_type">Discount type</label>
        <select id="rule_type" name="rule_type">
          <?php $type = (string)$rule['rule_type']; ?>
          <option value="percent"  <?= $type==='percent'?'selected':'' ?>>Percentage (%)</option>
          <option value="amount"   <?= $type==='amount'?'selected':'' ?>>Fixed amount</option>
          <option value="buyget"   <?= $type==='buyget'?'selected':'' ?>>Buy X Get Y</option>
        </select>
      </div>
      <div class="ms-field">
        <label class="ms-label" for="value">Value</label>
        <input id="value" name="value" type="text" value="<?= h((string)$rule['value']) ?>" placeholder="e.g., 10 (for 10%) or 25 (for 25 EGP)"/>
      </div>
      <div class="ms-field">
        <label class="ms-label">Eligible purchase channels</label>
        <div class="ms-row" role="group" aria-label="Eligible channels">
          <?php
            $ch = array_flip((array)$rule['channels']);
            $checked = fn($k)=> isset($ch[$k]) ? 'checked' : '';
          ?>
          <label><input type="checkbox" name="channels[]" value="pos"        <?= $checked('pos') ?>> In-Store (POS)</label>
          <label><input type="checkbox" name="channels[]" value="online"     <?= $checked('online') ?>> Online Orders</label>
          <label><input type="checkbox" name="channels[]" value="aggregator" <?= $checked('aggregator') ?>> Delivery Apps</label>
        </div>
      </div>
    </div>

    <div class="ms-grid-2">
      <div class="ms-field">
        <label class="ms-label" for="start_at">Goes live (DD-MM-YYYY)</label>
        <input id="start_at" name="start_at" type="date" value="<?= h($rule['start_at']) ?>"/>
      </div>
      <div class="ms-field">
        <label class="ms-label" for="end_at">Ends (DD-MM-YYYY) <span class="text-muted">(optional)</span></label>
        <input id="end_at" name="end_at" type="date" value="<?= h($rule['end_at']) ?>"/>
      </div>
    </div>

    <div class="ms-grid-2">
      <div class="ms-field">
        <label class="ms-label">Earning exclusions</label>
        <div class="ms-row" role="group" aria-label="Exclusions">
          <label><input type="checkbox" name="exclude_aggregator" value="1" <?= ((int)$rule['exclude_aggregator'])===1?'checked':'' ?>> Exclude aggregator orders</label>
          <label><input type="checkbox" name="exclude_discounted" value="1" <?= ((int)$rule['exclude_discounted'])===1?'checked':'' ?>> Exclude discounted orders</label>
        </div>
      </div>
      <div class="ms-field">
        <label class="ms-label" for="notes">Notes (optional)</label>
        <input id="notes" name="notes" type="text" value="<?= h((string)$rule['notes']) ?>" placeholder="Internal note"/>
      </div>
    </div>

    <div class="ms-form-actions">
      <a class="ms-btn" href="/views/admin/rewards/discounts/index.php" data-back>Back</a>
      <button class="ms-btn ms-btn-primary js-save" type="submit">Save</button>
      <a class="ms-btn" href="/views/admin/rewards/discounts/index.php" data-cancel>Cancel</a>
    </div>
  </form>
</div>
<?php page_end(); ?>