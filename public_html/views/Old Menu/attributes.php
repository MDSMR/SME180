<?php require_once __DIR__ . '/../../../config/db.php'; ?>
<?php
ini_set('display_errors',1); ini_set('display_startup_errors',1); error_reporting(E_ALL);
require_once __DIR__ . '/../../../config/db.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if(!isset($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));

function csrf(){ return $_SESSION['csrf']; }
function flash($m,$t='ok'){ $_SESSION['flash'][]=['m'=>$m,'t'=>$t]; }
function flashes(){ $f=$_SESSION['flash']??[]; unset($_SESSION['flash']); return $f; }
function csrf_ok($t){ return hash_equals($_SESSION['csrf']??'', $t??''); }

$status = $_GET['status'] ?? 'active';
$attrId = (int)($_GET['attribute_id'] ?? 0);

// POST (unchanged from your working logic) ...
if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST'){
  if (!csrf_ok($_POST['csrf']??'')){ flash('Invalid request','err'); header('Location: attributes.php'); exit; }
  $action=$_POST['action']??'';

  if ($action==='create_attr'){
    $en=trim($_POST['name_en']??''); $ar=trim($_POST['name_ar']??''); $req=isset($_POST['is_required'])?1:0; $act=isset($_POST['is_active'])?1:0;
    if ($en==='' && $ar===''){ flash('Name EN or AR required','err'); }
    else { $pdo->prepare("INSERT INTO attributes (name_en,name_ar,is_required,is_active,archived_at) VALUES (?,?,?,?,NULL)")
              ->execute([$en,$ar,$req,$act]); flash('Attribute added','ok'); }
    header('Location: attributes.php'); exit;
  }
  if ($action==='toggle_attr'){
    $id=(int)($_POST['id']??0); $to=(int)($_POST['to']??0);
    if($id>0){ $pdo->prepare("UPDATE attributes SET is_active=? WHERE id=?")->execute([$to,$id]); flash('Attribute updated','ok'); }
    header('Location: attributes.php?status='.$status); exit;
  }
  if ($action==='archive_attr'){
    $id=(int)($_POST['id']??0);
    if ($id){ $pdo->prepare("UPDATE attributes SET archived_at=NOW(), is_active=0 WHERE id=?")->execute([$id]); flash('Attribute archived','ok'); }
    header('Location: attributes.php?status='.$status); exit;
  }
  if ($action==='unarchive_attr'){
    $id=(int)($_POST['id']??0);
    if ($id){ $pdo->prepare("UPDATE attributes SET archived_at=NULL, is_active=1 WHERE id=?")->execute([$id]); flash('Attribute restored & enabled','ok'); }
    header('Location: attributes.php?status='.$status); exit;
  }
  if ($action==='delete_attr'){
    $id=(int)($_POST['id']??0);
    $used=0;
    $s=$pdo->prepare("SELECT COUNT(*) FROM attribute_values WHERE attribute_id=?"); $s->execute([$id]); $used+=(int)$s->fetchColumn();
    if ($used>0){ flash('Cannot delete: attribute has values. Archive instead.','err'); }
    else { $pdo->prepare("DELETE FROM attributes WHERE id=?")->execute([$id]); flash('Deleted','ok'); }
    header('Location: attributes.php?status='.$status); exit;
  }

  if ($action==='create_value'){
    $attr=(int)($_POST['attribute_id']??0); $ve=trim($_POST['value_en']??''); $va=trim($_POST['value_ar']??''); 
    $delta=(float)($_POST['price_delta']??0); $sort=(int)($_POST['sort_order']??0);
    if ($attr<=0 || ($ve==='' && $va==='')){ flash('Value and attribute required','err'); }
    else { $pdo->prepare("INSERT INTO attribute_values (attribute_id,value_en,value_ar,price_delta,sort_order,is_active,archived_at) VALUES (?,?,?,?,?,1,NULL)")
              ->execute([$attr,$ve,$va,$delta,$sort]); flash('Value added','ok'); }
    header('Location: attributes.php?attribute_id='.$attr); exit;
  }
  if ($action==='toggle_value'){
    $id=(int)($_POST['id']??0); $to=(int)($_POST['to']??0); $attr=(int)($_POST['attribute_id']??0);
    if($id>0){ $pdo->prepare("UPDATE attribute_values SET is_active=? WHERE id=?")->execute([$to,$id]); flash('Value updated','ok'); }
    header('Location: attributes.php?attribute_id='.$attr.'&status='.$status); exit;
  }
  if ($action==='archive_value'){
    $id=(int)($_POST['id']??0); $attr=(int)($_POST['attribute_id']??0);
    if ($id){ $pdo->prepare("UPDATE attribute_values SET archived_at=NOW(), is_active=0 WHERE id=?")->execute([$id]); flash('Value archived','ok'); }
    header('Location: attributes.php?attribute_id='.$attr.'&status='.$status); exit;
  }
  if ($action==='unarchive_value'){
    $id=(int)($_POST['id']??0); $attr=(int)($_POST['attribute_id']??0);
    if ($id){ $pdo->prepare("UPDATE attribute_values SET archived_at=NULL, is_active=1 WHERE id=?")->execute([$id]); flash('Value restored & enabled','ok'); }
    header('Location: attributes.php?attribute_id='.$attr.'&status='.$status); exit;
  }
  if ($action==='delete_value'){
    $id=(int)($_POST['id']??0); $attr=(int)($_POST['attribute_id']??0);
    $used=0;
    $s=$pdo->prepare("SELECT COUNT(*) FROM product_attribute_values WHERE attribute_value_id=?"); $s->execute([$id]); $used+=(int)$s->fetchColumn();
    if ($used>0){ flash('Cannot delete: value used by products. Archive instead.','err'); }
    else { $pdo->prepare("DELETE FROM attribute_values WHERE id=?")->execute([$id]); flash('Deleted','ok'); }
    header('Location: attributes.php?attribute_id='.$attr.'&status='.$status); exit;
  }
}

// lists
$w=[]; $sql="SELECT id,name_en,name_ar,is_required,is_active,archived_at FROM attributes";
if ($status==='active'){ $w[]="archived_at IS NULL"; }
elseif ($status==='archived'){ $w[]="archived_at IS NOT NULL"; }
if ($w) $sql.=" WHERE ".implode(' AND ',$w);
$sql.=" ORDER BY archived_at IS NOT NULL, name_en ASC";
$attrs=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
if ($attrId===0 && $attrs){ $attrId = (int)$attrs[0]['id']; }

$vals=[];
if ($attrId>0){
  $w2=[]; $a2=[$attrId];
  $sql2="SELECT id,value_en,value_ar,price_delta,sort_order,is_active,archived_at FROM attribute_values WHERE attribute_id=?";
  if ($status==='active'){ $w2[]="archived_at IS NULL"; }
  elseif ($status==='archived'){ $w2[]="archived_at IS NOT NULL"; }
  if ($w2) $sql2.=" AND ".implode(' AND ',$w2);
  $sql2.=" ORDER BY archived_at IS NOT NULL, sort_order ASC, value_en ASC";
  $st=$pdo->prepare($sql2); $st->execute($a2); $vals=$st->fetchAll(PDO::FETCH_ASSOC);
}
?>
<div class="container">

  <?php foreach (flashes() as $f): ?>
    <div class="card" style="margin-bottom:12px;border-left:4px solid <?= $f['t']==='err'?'#ef4444':'#16a34a' ?>"><?= htmlspecialchars($f['m']) ?></div>
  <?php endforeach; ?>

  <div class="row">
    <div class="col">
      <div class="card">
        <div class="toolbar">
          <strong>Attributes</strong>
          <form method="get" class="btnrow">
            <select class="input" name="status" onchange="this.form.submit()">
              <option value="active"   <?= $status==='active'?'selected':'' ?>>Show Active</option>
              <option value="archived" <?= $status==='archived'?'selected':'' ?>>Show Archived</option>
              <option value="all"      <?= $status==='all'?'selected':'' ?>>Show All</option>
            </select>
          </form>
        </div>
        <table class="table">
          <thead><tr><th>ID</th><th>Name (EN / AR)</th><th>Required</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
          <tbody>
          <?php foreach($attrs as $a): ?>
            <tr class="tr" style="<?= $a['archived_at']?'opacity:.6;':'' ?>">
              <td><?= (int)$a['id'] ?></td>
              <td><a href="attributes.php?attribute_id=<?= (int)$a['id'] ?>&status=<?= htmlspecialchars($status) ?>"><?= htmlspecialchars($a['name_en'] ?: $a['name_ar']) ?></a><?php if($a['name_ar']): ?><span class="help"> / <?= htmlspecialchars($a['name_ar']) ?></span><?php endif; ?></td>
              <td><?= $a['is_required']?'<span class="badge blue">Required</span>':'<span class="badge">Optional</span>' ?></td>
              <td><?= $a['archived_at']?'<span class="badge">Archived</span>':($a['is_active']?'<span class="badge green">Active</span>':'<span class="badge red">Disabled</span>') ?></td>
              <td style="text-align:right;white-space:nowrap">
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= csrf() ?>">
                  <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                  <?php if (!$a['archived_at']): ?>
                    <input type="hidden" name="action" value="archive_attr">
                    <button class="btn sm warning" onclick="return confirm('Archive this attribute?')" type="submit">Archive</button>
                  <?php else: ?>
                    <input type="hidden" name="action" value="unarchive_attr">
                    <button class="btn sm success" type="submit">Restore</button>
                  <?php endif; ?>
                </form>
                <?php
                  $count = $pdo->prepare("SELECT COUNT(*) FROM attribute_values WHERE attribute_id=?");
                  $count->execute([$a['id']]);
                  $canDelete = ((int)$count->fetchColumn()===0);
                ?>
                <form method="post" style="display:inline" <?= $canDelete?'onsubmit="return confirm(\'Delete this attribute?\')"' : '' ?>>
                  <input type="hidden" name="csrf" value="<?= csrf() ?>">
                  <input type="hidden" name="action" value="delete_attr">
                  <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                  <button class="btn sm danger" <?= $canDelete?'':'disabled title="Has values; archive instead"' ?>>Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; if(!$attrs): ?>
            <tr class="tr"><td colspan="5">No attributes yet.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="col" style="max-width:520px">
      <div class="card">
        <strong>New Attribute</strong>
        <div class="hr"></div>
        <form method="post" class="form-grid">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input type="hidden" name="action" value="create_attr">
          <label>Name (EN)<input class="input" name="name_en"></label>
          <label>Name (AR)<input class="input" name="name_ar"></label>
          <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="is_required"> Required</label>
          <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="is_active" checked> Active</label>
          <div style="grid-column:1/-1"><button class="btn sm primary">Create</button></div>
        </form>
      </div>

      <?php if ($attrId): ?>
      <div class="card" style="margin-top:12px">
        <strong>Add Value</strong>
        <div class="hr"></div>
        <form method="post" class="form-grid">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input type="hidden" name="action" value="create_value">
          <input type="hidden" name="attribute_id" value="<?= (int)$attrId ?>">
          <label>Value (EN)<input class="input" name="value_en"></label>
          <label>Value (AR)<input class="input" name="value_ar"></label>
          <label>Price Δ<input class="input" type="number" step="0.01" name="price_delta" value="0.00"></label>
          <label>Sort<input class="input" type="number" name="sort_order" value="0"></label>
          <div style="grid-column:1/-1"><button class="btn sm primary">Add</button></div>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($attrId): ?>
  <div class="card" style="margin-top:12px">
    <strong>Values</strong>
    <div class="hr"></div>
    <table class="table">
      <thead><tr><th>ID</th><th>Value (EN / AR)</th><th>Δ</th><th>Sort</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
        <?php foreach($vals as $v): ?>
        <tr class="tr" style="<?= $v['archived_at']?'opacity:.6;':'' ?>">
          <td><?= (int)$v['id'] ?></td>
          <td><?= htmlspecialchars($v['value_en'] ?: $v['value_ar']) ?><?php if($v['value_ar']): ?><span class="help"> / <?= htmlspecialchars($v['value_ar']) ?></span><?php endif; ?></td>
          <td><?= number_format((float)$v['price_delta'],2) ?></td>
          <td><?= (int)$v['sort_order'] ?></td>
          <td><?= $v['archived_at']?'<span class="badge">Archived</span>':($v['is_active']?'<span class="badge green">Active</span>':'<span class="badge red">Disabled</span>') ?></td>
          <td style="text-align:right;white-space:nowrap">
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?= csrf() ?>">
              <input type="hidden" name="attribute_id" value="<?= (int)$attrId ?>">
              <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
              <?php if (!$v['archived_at']): ?>
                <input type="hidden" name="action" value="archive_value">
                <button class="btn sm warning" onclick="return confirm('Archive this value?')" type="submit">Archive</button>
              <?php else: ?>
                <input type="hidden" name="action" value="unarchive_value">
                <button class="btn sm success" type="submit">Restore</button>
              <?php endif; ?>
            </form>
            <?php
              $used=0;
              $c=$pdo->prepare("SELECT COUNT(*) FROM product_attribute_values WHERE attribute_value_id=?");
              $c->execute([$v['id']]);
              $canDelete = ((int)$c->fetchColumn()===0);
            ?>
            <form method="post" style="display:inline" <?= $canDelete?'onsubmit="return confirm(\'Delete this value?\')"' : '' ?>>
              <input type="hidden" name="csrf" value="<?= csrf() ?>">
              <input type="hidden" name="action" value="delete_value">
              <input type="hidden" name="attribute_id" value="<?= (int)$attrId ?>">
              <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
              <button class="btn sm danger" <?= $canDelete?'':'disabled title="In use by products; archive instead"' ?>>Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; if(!$vals): ?>
          <tr class="tr"><td colspan="6">No values.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

</div>
