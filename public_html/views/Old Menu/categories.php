<?php $current='categories'; require_once __DIR__ . '/../_header.php'; ?>
<?php
// Useful errors, suppress deprecation notices
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

require_once __DIR__ . '/../../../config/db.php';
// auth_check removed; using JWT header

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function csrf() { return $_SESSION['csrf']; }
function flash($m,$t='ok'){ $_SESSION['flash'][]=['m'=>$m,'t'=>$t]; }
function flashes(){ $f=$_SESSION['flash']??[]; unset($_SESSION['flash']); return $f; }
function csrf_ok($t){ return is_string($t) && hash_equals($_SESSION['csrf'] ?? '', $t); }

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* -------- Detect columns to tolerate schema differences -------- */
$cols = [];
try {
  $qCols = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories'");
  $qCols->execute();
  foreach ($qCols->fetchAll(PDO::FETCH_COLUMN, 0) as $c) { $cols[strtolower($c)] = true; }
} catch (Throwable $e) {
  // Fallback: probe common columns
  foreach (['id','name','name_en','name_ar','sequence','is_active','archived_at'] as $cand) {
    try {
      $st = $pdo->prepare("SHOW COLUMNS FROM `categories` LIKE ?");
      $st->execute([$cand]);
      if ($st->fetch(PDO::FETCH_ASSOC)) $cols[strtolower($cand)] = true;
    } catch (Throwable $e2) {}
  }
}
$has = fn($c) => isset($cols[strtolower($c)]);

/* -------- Filters -------- */
$status = $_GET['status'] ?? 'active';
if (!in_array($status,['active','archived','all'],true)) $status='active';
$q = trim((string)($_GET['q'] ?? ''));

/* -------- POST actions -------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try {
    if (!csrf_ok($_POST['csrf'] ?? '')) throw new Exception('Invalid request.');
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
      $id       = (int)($_POST['id'] ?? 0);
      $name_en  = trim((string)($_POST['name_en'] ?? ''));
      $name_ar  = trim((string)($_POST['name_ar'] ?? ''));
      $fallback = $name_en ?: $name_ar;

      $payload = [];
      if ($has('name'))      $payload['name'] = $fallback;
      if ($has('name_en'))   $payload['name_en'] = $name_en;
      if ($has('name_ar'))   $payload['name_ar'] = $name_ar;
      if ($has('sequence'))  $payload['sequence'] = (int)($_POST['sequence'] ?? 0);
      if ($has('is_active')) $payload['is_active'] = isset($_POST['is_active']) ? 1 : 0;

      if (($payload['name'] ?? '')==='' && ($payload['name_en'] ?? '')==='' && ($payload['name_ar'] ?? '')==='') {
        throw new Exception('Name EN or AR is required');
      }

      if ($action === 'create') {
        $colsSql = array_keys($payload);
        if ($has('archived_at')) $colsSql[] = 'archived_at';
        $place  = implode(',', array_fill(0, count($payload), '?')) . ($has('archived_at') ? ',NULL' : '');
        $sql    = "INSERT INTO categories (".implode(',', $colsSql).") VALUES ($place)";
        $stmt   = $pdo->prepare($sql);
        $stmt->execute(array_values($payload));
        flash('Category created','ok');
      } else {
        if ($id <= 0) throw new Exception('Missing category ID for update');
        $set = []; $vals=[];
        foreach ($payload as $c=>$v){ $set[]="$c=?"; $vals[]=$v; }
        if (!$set) throw new Exception('Nothing to update');
        $sql  = "UPDATE categories SET ".implode(',', $set)." WHERE id = ?";
        $vals[] = $id;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($vals);
        flash('Category updated','ok');
      }
      header('Location: categories.php?status='.$status.'&q='.urlencode($q)); exit;
    }

    if ($action === 'archive') {
      $id=(int)$_POST['id'];
      if ($id){
        if ($has('archived_at')) $pdo->prepare("UPDATE categories SET archived_at=NOW() WHERE id=?")->execute([$id]);
        elseif ($has('is_active')) $pdo->prepare("UPDATE categories SET is_active=0 WHERE id=?")->execute([$id]);
      }
      flash('Archived','ok');
      header('Location: categories.php?status='.$status.'&q='.urlencode($q)); exit;
    }

    if ($action === 'unarchive') {
      $id=(int)$_POST['id'];
      if ($id){
        if ($has('archived_at')) $pdo->prepare("UPDATE categories SET archived_at=NULL WHERE id=?")->execute([$id]);
        elseif ($has('is_active')) $pdo->prepare("UPDATE categories SET is_active=1 WHERE id=?")->execute([$id]);
      }
      flash('Restored & enabled','ok');
      header('Location: categories.php?status='.$status.'&q='.urlencode($q)); exit;
    }

    if ($action === 'delete') {
      $id=(int)$_POST['id'];
      if ($id){ $pdo->prepare("DELETE FROM categories WHERE id=?")->execute([$id]); }
      flash('Deleted','ok');
      header('Location: categories.php?status='.$status.'&q='.urlencode($q)); exit;
    }

  } catch (Throwable $e) {
    flash('Error: '.$e->getMessage(),'err');
    header('Location: categories.php?status='.$status.'&q='.urlencode($q)); exit;
  }
}

/* -------- LIST query (Name shown once; AR below) -------- */
$select = ['id'];
if ($has('name_en'))   $select[] = 'name_en';
if ($has('name_ar'))   $select[] = 'name_ar';
if ($has('name'))      $select[] = 'name';
if ($has('sequence'))  $select[] = 'sequence';
if ($has('is_active')) $select[] = 'is_active';
if ($has('archived_at')) $select[] = 'archived_at';

$select[] = ($has('name_en') ? 'COALESCE(name_en' : 'COALESCE(NULL');
$select[count($select)-1] .= ($has('name') ? ',name' : ',NULL');
$select[count($select)-1] .= ($has('name_ar') ? ',name_ar)' : ',NULL)');
$select[count($select)-1] .= ' AS disp_name';

$sql = "SELECT ".implode(',', $select)." FROM categories";
$args=[]; $w=[];
if ($q!==''){
  if ($has('name_en') || $has('name') || $has('name_ar')) {
    $w[] = "COALESCE(".($has('name_en')?'name_en':'NULL').",".($has('name')?'name':'NULL').",".($has('name_ar')?'name_ar':'NULL').") LIKE ?";
    $args[]='%'.$q.'%';
  }
}
if ($status==='active'){
  if ($has('archived_at')) $w[]="archived_at IS NULL";
  elseif ($has('is_active')) $w[]="is_active = 1";
} elseif ($status==='archived'){
  if ($has('archived_at')) $w[]="archived_at IS NOT NULL";
  elseif ($has('is_active')) $w[]="is_active = 0";
}
if ($w) $sql .= " WHERE ".implode(' AND ', $w);

$order = [];
if ($has('archived_at')) $order[] = "archived_at IS NOT NULL";
if ($has('sequence'))     $order[] = "sequence ASC";
$order[] = "disp_name ASC";
$sql .= " ORDER BY ".implode(', ', $order);

$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

/* -------- EDIT context -------- */
$edit = null;
if (isset($_GET['edit'])) {
  $editId = (int)$_GET['edit'];
  if ($editId > 0) {
    $s=$pdo->prepare("SELECT * FROM categories WHERE id=?");
    $s->execute([$editId]);
    $edit=$s->fetch(PDO::FETCH_ASSOC);
    if (!$edit) $edit=['id'=>0];
  } else {
    $edit=['id'=>0];
  }
}
?>
<?php $current = 'categories'; require_once __DIR__ . '/../../_header.php'; ?>

<div class="container full">

  <?php foreach (flashes() as $f): ?>
    <div class="card" style="margin-bottom:12px;border-left:4px solid <?= $f['t']==='err'?'#ef4444':'#16a34a' ?>"><?= e($f['m']) ?></div>
  <?php endforeach; ?>

  <!-- New/Edit form (hidden by default). Appears above the list -->
  <div class="card" id="categoryForm" style="display:none; margin-bottom:16px;">
    <div class="toolbar">
      <strong id="formTitle"><?= ($edit && (int)($edit['id'] ?? 0)>0) ? 'Edit Category' : 'New Category' ?></strong>
    </div>

    <form method="post" class="form-grid" id="categoryFormEl">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="<?= ($edit && (int)($edit['id'] ?? 0)>0) ? 'update' : 'create' ?>">
      <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

      <!-- One logical field for Category Name (shows both languages). Keep two inputs for data integrity. -->
      <label style="grid-column:1/-1">
        <span>Category Name (EN &amp; AR)</span>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
          <?php if ($has('name_en')): ?>
            <input class="input" type="text" name="name_en" placeholder="Name (English)" value="<?= e($edit['name_en'] ?? '') ?>">
          <?php endif; ?>
          <?php if ($has('name_ar')): ?>
            <input class="input" dir="rtl" type="text" name="name_ar" placeholder="الاسم (عربي)" value="<?= e($edit['name_ar'] ?? '') ?>">
          <?php endif; ?>
          <?php if (!$has('name_en') && !$has('name_ar') && $has('name')): ?>
            <input class="input" type="text" name="name_en" placeholder="Name" value="<?= e($edit['name'] ?? '') ?>">
          <?php endif; ?>
        </div>
      </label>

      <?php if ($has('sequence')): ?>
      <label>
        <span>Sequence</span>
        <input class="input" type="number" name="sequence" value="<?= e($edit['sequence'] ?? 0) ?>">
      </label>
      <?php endif; ?>

      <?php if ($has('is_active')): ?>
      <label style="grid-column:1/-1;display:flex;gap:8px;align-items:center">
        <input type="checkbox" name="is_active" <?= ((int)($edit['is_active'] ?? 1) ? 'checked' : '') ?>> Active
      </label>
      <?php endif; ?>

      <div style="grid-column:1/-1;display:flex;gap:8px">
        <button class="btn sm primary" type="submit"><?= ($edit && (int)($edit['id'] ?? 0)>0) ? 'Save' : 'Create' ?></button>
        <a class="btn sm ghost" href="categories.php" id="cancelBtn">Cancel</a>
      </div>
    </form>
  </div>

  <!-- LIST (full width; hidden while form is visible) -->
  <div class="card" id="categoryList">
    <div class="toolbar">
      <strong>Categories</strong>
      <form method="get" class="searchbar">
        <select class="input" name="status" onchange="this.form.submit()">
          <option value="active"   <?= $status==='active'?'selected':'' ?>>Show Active</option>
          <option value="archived" <?= $status==='archived'?'selected':'' ?>>Show Archived</option>
          <option value="all"      <?= $status==='all'?'selected':'' ?>>Show All</option>
        </select>
        <input class="input" type="text" name="q" value="<?= e($q) ?>" placeholder="Search...">
        <button class="btn sm" type="submit">Filter</button>
        <a class="btn sm primary" href="categories.php?edit=0" id="newBtn">New</a>
      </form>
    </div>

    <div class="table-wrap center">
      <table class="table">
        <!-- Guide widths so Name breathes and Actions stay on one row -->
        <colgroup>
          <col style="width:80px"><!-- ID -->
          <col class="col-name"><!-- Category Name -->
          <?php if ($has('sequence')): ?><col style="width:100px"><?php endif; ?><!-- Seq -->
          <?php if ($has('archived_at') || $has('is_active')): ?><col style="width:120px"><?php endif; ?><!-- Status -->
          <col class="col-actions"><!-- Actions -->
        </colgroup>

        <thead>
          <tr>
            <th>ID</th>
            <th>Category Name</th>
            <?php if ($has('sequence')): ?><th>Seq</th><?php endif; ?>
            <?php if ($has('archived_at') || $has('is_active')): ?><th>Status</th><?php endif; ?>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td>
              <div class="name-cell">
                <?= e($r['disp_name'] ?? $r['name'] ?? $r['name_en'] ?? $r['name_ar'] ?? '') ?>
              </div>
              <?php if ($has('name_ar') && !empty($r['name_ar'])): ?>
                <div dir="rtl" style="font-size:12px;color:#6b7280"><?= e($r['name_ar']) ?></div>
              <?php endif; ?>
            </td>
            <?php if ($has('sequence')): ?>
              <td><?= (int)($r['sequence'] ?? 0) ?></td>
            <?php endif; ?>
            <?php if ($has('archived_at') || $has('is_active')): ?>
              <td>
                <?php
                  $arch = $r['archived_at'] ?? null;
                  $act  = isset($r['is_active']) ? (int)$r['is_active'] : 1;
                  if ($has('archived_at') && $arch)      echo '<span class="badge">Archived</span>';
                  elseif ($has('is_active') && !$act)    echo '<span class="badge">Disabled</span>';
                  else                                    echo '<span class="badge blue">Active</span>';
                ?>
              </td>
            <?php endif; ?>
            <td>
              <div class="actions">
                <?php if (!($has('archived_at') && ($r['archived_at'] ?? null)) && !($has('is_active') && isset($r['is_active']) && (int)$r['is_active']===0)): ?>
                  <form method="post">
                    <input type="hidden" name="csrf" value="<?= csrf() ?>">
                    <input type="hidden" name="action" value="archive">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn sm" type="submit" onclick="return confirm('Archive this category?');">Archive</button>
                  </form>
                <?php else: ?>
                  <form method="post">
                    <input type="hidden" name="csrf" value="<?= csrf() ?>">
                    <input type="hidden" name="action" value="unarchive">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn sm" type="submit">Restore</button>
                  </form>
                <?php endif; ?>

                <a
                  href="#"
                  class="btn sm ghost edit-btn"
                  data-id="<?= (int)$r['id'] ?>"
                  data-name-en="<?= e($r['name_en'] ?? ($r['name'] ?? '')) ?>"
                  data-name-ar="<?= e($r['name_ar'] ?? '') ?>"
                  data-sequence="<?= $has('sequence') ? (int)($r['sequence'] ?? 0) : '' ?>"
                  data-is-active="<?= $has('is_active') ? (int)($r['is_active'] ?? 1) : 1 ?>"
                >Edit</a>

                <form method="post" onsubmit="return confirm('Permanently delete this category?')">
                  <input type="hidden" name="csrf" value="<?= csrf() ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn sm danger" type="submit">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
(function(){
  // Show/hide form; table width unchanged; prefill on Edit
  const url        = new URL(window.location.href);
  const hasEdit    = url.searchParams.has('edit');
  const formCard   = document.getElementById('categoryForm');
  const formEl     = document.getElementById('categoryFormEl');
  const listCard   = document.getElementById('categoryList');
  const newBtn     = document.getElementById('newBtn');
  const cancelBtn  = document.getElementById('cancelBtn');
  const titleEl    = document.getElementById('formTitle');

  function showForm(){ if(formCard) formCard.style.display='block'; if(listCard) listCard.style.display='none'; }
  function hideForm(){ if(formCard) formCard.style.display='none';  if(listCard) listCard.style.display='block'; }

  if (hasEdit) { showForm(); } else { hideForm(); }

  if (newBtn) newBtn.addEventListener('click', function(e){
    e.preventDefault();
    url.searchParams.set('edit','0'); history.replaceState(null,'', url.toString());
    if (formEl){
      formEl.reset();
      // default toggles
      const act = formEl.querySelector('input[name="is_active"]'); if (act) act.checked = true;
      const action = formEl.querySelector('input[name="action"]'); if (action) action.value='create';
      const idfld = formEl.querySelector('input[name="id"]'); if (idfld) idfld.value = 0;
    }
    if (titleEl) titleEl.textContent = 'New Category';
    showForm();
  });

  document.querySelectorAll('.edit-btn').forEach(function(btn){
    btn.addEventListener('click', function(e){
      e.preventDefault();
      if (!formEl) return;
      const d = this.dataset;

      const en  = formEl.querySelector('input[name="name_en"]');
      const ar  = formEl.querySelector('input[name="name_ar"]');
      const seq = formEl.querySelector('input[name="sequence"]');
      const act = formEl.querySelector('input[name="is_active"]');
      const idf = formEl.querySelector('input[name="id"]');
      const actn= formEl.querySelector('input[name="action"]');

      if (en)  en.value  = d.nameEn || '';
      if (ar)  ar.value  = d.nameAr || '';
      if (seq && typeof d.sequence !== 'undefined') seq.value = d.sequence || 0;
      if (act && typeof d.isActive !== 'undefined') act.checked = (d.isActive === '1' || d.isActive === 1);
      if (idf) idf.value = d.id || 0;
      if (actn) actn.value = 'update';

      if (titleEl) titleEl.textContent = 'Edit Category';
      url.searchParams.set('edit', d.id || '0');
      history.replaceState(null,'', url.toString());
      showForm();
    });
  });

  if (cancelBtn) cancelBtn.addEventListener('click', function(e){
    e.preventDefault();
    url.searchParams.delete('edit'); history.replaceState(null,'', url.toString());
    hideForm();
  });
})();
</script>

<!-- Final inline overrides to keep Actions inline and Name readable -->
<style>
  .table .col-actions { min-width: 420px !important; }
  .table .col-name    { width: auto !important; }
  .name-cell { min-width: 28ch !important; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .actions { display:flex !important; align-items:center !important; gap:10px !important; flex-wrap:nowrap !important; white-space:nowrap !important; }
  .actions > * { flex:0 0 auto !important; }
  .actions form { display:inline-flex !important; width:auto !important; margin:0 !important; padding:0 !important; }
  .actions .btn, .actions button, .actions a { display:inline-flex !important; width:auto !important; max-width:none !important; white-space:nowrap !important; }
  .actions i, .actions svg,
  .actions .fa, .actions .fas, .actions .far, .actions .fal, .actions .fab,
  .actions .material-icons, .actions .material-symbols-outlined {
    position: static !important; display:inline-block !important; width:16px !important; height:16px !important; vertical-align:middle !important;
  }
</style>
