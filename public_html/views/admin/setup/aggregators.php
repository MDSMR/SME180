<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/auth_check.php';
$pdo = get_pdo();

if (!isset($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
function csrf(){ return $_SESSION['csrf']; }
function flash($m,$t='ok'){ $_SESSION['flash'][]=['m'=>$m,'t'=>$t]; }
function flashes(){ $f=$_SESSION['flash']??[]; unset($_SESSION['flash']); return $f; }

if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST' && hash_equals(csrf(), $_POST['csrf'] ?? '')){
  $action = $_POST['action'] ?? '';
  if ($action==='save'){
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['display_name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $pct  = (float)($_POST['commission_percent'] ?? 0);
    $act  = isset($_POST['is_active']) ? 1 : 0;
    if ($id===0){
      $stmt=$pdo->prepare("INSERT INTO aggregators (slug,display_name,commission_percent,is_active,archived_at) VALUES (?,?,?,?,NULL)");
      $stmt->execute([$slug,$name,$pct,$act]);
      flash('Aggregator added');
    }else{
      $stmt=$pdo->prepare("UPDATE aggregators SET slug=?, display_name=?, commission_percent=?, is_active=? WHERE id=?");
      $stmt->execute([$slug,$name,$pct,$act,$id]);
      flash('Aggregator saved');
    }
    header('Location: aggregators.php?embed=' . ($_GET['embed']??'')); exit;
  }
  if ($action==='archive'){
    $id=(int)($_POST['id']??0);
    if ($id){ $pdo->prepare("UPDATE aggregators SET archived_at=NOW(), is_active=0 WHERE id=?")->execute([$id]); flash('Archived'); }
    header('Location: aggregators.php?embed=' . ($_GET['embed']??'')); exit;
  }
  if ($action==='unarchive'){
    $id=(int)($_POST['id']??0);
    if ($id){ $pdo->prepare("UPDATE aggregators SET archived_at=NULL, is_active=1 WHERE id=?")->execute([$id]); flash('Restored'); }
    header('Location: aggregators.php?embed=' . ($_GET['embed']??'')); exit;
  }
}

$status = $_GET['status'] ?? 'active';
$q = trim($_GET['q'] ?? '');
$sql="SELECT id,slug,display_name,commission_percent,is_active,archived_at FROM aggregators";
$w=[]; $a=[];
if ($q!==''){ $w[]="(slug LIKE ? OR display_name LIKE ?)"; $a[]="%$q%"; $a[]="%$q%"; }
if ($status==='active'){ $w[]="archived_at IS NULL"; }
elseif ($status==='archived'){ $w[]="archived_at IS NOT NULL"; }
if ($w) $sql.=" WHERE ".implode(' AND ',$w);
$sql.=" ORDER BY archived_at IS NOT NULL, display_name ASC";
$st=$pdo->prepare($sql); $st->execute($a); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
$editId = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($editId>0){ $s=$pdo->prepare("SELECT * FROM aggregators WHERE id=?"); $s->execute([$editId]); $edit=$s->fetch(PDO::FETCH_ASSOC); }

$embedded = !empty($_GET['embed']);
?>
<?php if (!$embedded): ?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><title>Aggregators</title>
<link rel="stylesheet" href="/assets/css/admin.css"></head>
<body>
<?php include __DIR__ . '/../../_nav.php'; ?>
<div class="container">
<?php endif; ?>

<main style="padding:10px;max-width:1100px;margin:0 auto;font-family:system-ui">
  <!-- Heading removed -->

  <?php foreach (flashes() as $f): ?>
    <div class="card" style="margin-bottom:12px;border-left:4px solid #16a34a"><?= htmlspecialchars($f['m']) ?></div>
  <?php endforeach; ?>

  <div class="row">
    <div class="col">
      <div class="card">
        <div class="toolbar">
          <strong>Aggregators</strong>
          <form method="get" class="btnrow">
            <select class="input" name="status" onchange="this.form.submit()">
              <option value="active"   <?= $status==='active'?'selected':'' ?>>Show Active</option>
              <option value="archived" <?= $status==='archived'?'selected':'' ?>>Show Archived</option>
              <option value="all"      <?= $status==='all'?'selected':'' ?>>Show All</option>
            </select>
            <input class="input" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search...">
            <button class="btn sm ghost">Search</button>
            <a class="btn sm ghost" href="aggregators.php?embed=1">Reset</a>
            <a class="btn sm primary" href="aggregators.php?embed=1&edit=0">+ New</a>
          </form>
        </div>
        <table class="table">
          <thead><tr><th>ID</th><th>Name</th><th>Slug</th><th>Commission %</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
          <tbody>
            <?php foreach($rows as $r): ?>
            <tr class="tr" style="<?= $r['archived_at']?'opacity:.6;':'' ?>">
              <td><?= (int)$r['id'] ?></td>
              <td><?= htmlspecialchars($r['display_name']) ?></td>
              <td><span class="kbd"><?= htmlspecialchars($r['slug']) ?></span></td>
              <td><?= number_format((float)$r['commission_percent'],2) ?></td>
              <td><?= $r['archived_at']?'<span class="badge">Archived</span>':($r['is_active']?'<span class="badge green">Active</span>':'<span class="badge red">Disabled</span>') ?></td>
              <td style="text-align:right;white-space:nowrap">
                <a class="btn sm ghost" href="aggregators.php?embed=1&edit=<?= (int)$r['id'] ?>">Edit</a>
                <?php if (!$r['archived_at']): ?>
                  <form method="post" style="display:inline" onsubmit="return confirm('Archive this aggregator?')">
                    <input type="hidden" name="csrf" value="<?= csrf() ?>">
                    <input type="hidden" name="action" value="archive">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn sm warning" type="submit">Archive</button>
                  </form>
                <?php else: ?>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?= csrf() ?>">
                    <input type="hidden" name="action" value="unarchive">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn sm success" type="submit">Restore</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; if (!$rows): ?>
              <tr class="tr"><td colspan="6">No aggregators.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="col" style="max-width:520px">
      <div class="card">
        <strong><?= $edit?'Edit Aggregator':'New Aggregator' ?></strong>
        <div class="hr"></div>
        <form method="post" class="form-grid">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input type="hidden" name="action" value="save">
          <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
          <label>Name<input class="input" name="display_name" value="<?= htmlspecialchars($edit['display_name'] ?? '') ?>" required></label>
          <label>Slug<input class="input" name="slug" value="<?= htmlspecialchars($edit['slug'] ?? '') ?>" placeholder="e.g. talabat" required></label>
          <label>Commission %<input class="input" type="number" step="0.01" min="0" name="commission_percent" value="<?= htmlspecialchars(isset($edit['commission_percent'])?number_format((float)$edit['commission_percent'],2,'.',''):'0.00') ?>"></label>
          <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="is_active" <?= isset($edit)?($edit['is_active']?'checked':''):'checked' ?>> Active</label>
          <div style="grid-column:1/-1"><button class="btn primary sm">Save</button></div>
        </form>
      </div>
    </div>
  </div>
</main>

<?php if (!$embedded): ?>
</div>
</body></html>
<?php endif; ?>