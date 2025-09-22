<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/auth_check.php'; // your existing session gate (redirects if not authed)
$pdo = get_pdo();

// CSRF + flashes
if (!isset($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
function flash($m,$t='ok'){ $_SESSION['flash'][]=['m'=>$m,'t'=>$t]; }
function flashes(){ $f=$_SESSION['flash']??[]; unset($_SESSION['flash']); return $f; }
function csrf_ok($t){ return hash_equals($_SESSION['csrf']??'', $t??''); }

if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST'){
  if (!csrf_ok($_POST['csrf']??'')){ flash('Invalid CSRF','err'); header('Location: printers.php?embed=' . ($_GET['embed']??'')); exit; }
  $action = $_POST['action'] ?? '';
  if ($action==='create'){
    $name=trim($_POST['name']??''); $route=trim($_POST['route']??''); $active=isset($_POST['is_active'])?1:0;
    if ($name===''){ flash('Name required','err'); }
    else {
      $s=$pdo->prepare("INSERT INTO printers (name,route,is_active) VALUES (?,?,?)");
      $s->execute([$name,$route,$active]);
      flash('Printer added','ok');
    }
    header('Location: printers.php?embed=' . ($_GET['embed']??'')); exit;
  }
  if ($action==='toggle'){
    $id=(int)($_POST['id']??0); $to=(int)($_POST['to']??0);
    if ($id>0){
      $pdo->prepare("UPDATE printers SET is_active=? WHERE id=?")->execute([$to,$id]);
      flash($to?'Enabled':'Disabled','ok');
    }
    header('Location: printers.php?embed=' . ($_GET['embed']??'')); exit;
  }
}

$rows = $pdo->query("SELECT id,name,route,is_active FROM printers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$embedded = !empty($_GET['embed']);
?>
<?php if (!$embedded): ?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><title>Printers</title>
<link rel="stylesheet" href="/assets/css/admin.css"></head>
<body>
<?php include __DIR__ . '/../../_nav.php'; ?>
<div class="container">
<?php endif; ?>

  <!-- Heading intentionally removed when embedded -->
  <main style="padding:10px;max-width:1100px;margin:0 auto;font-family:system-ui">

    <?php foreach (flashes() as $f): ?>
      <div class="card" style="margin-bottom:12px;border-left:4px solid <?= $f['t']==='err'?'#ef4444':'#16a34a' ?>"><?= htmlspecialchars($f['m']) ?></div>
    <?php endforeach; ?>

    <div class="row">
      <div class="col">
        <div class="card">
          <div class="toolbar"><strong>Printers</strong></div>
          <table class="table">
            <thead><tr><th>ID</th><th>Name</th><th>Route/Queue</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
            <tbody>
            <?php foreach($rows as $r): ?>
              <tr class="tr">
                <td><?= (int)$r['id'] ?></td>
                <td><?= htmlspecialchars($r['name']) ?></td>
                <td><?= htmlspecialchars($r['route'] ?? '') ?></td>
                <td><?= $r['is_active']?'<span class="badge green">Active</span>':'<span class="badge red">Disabled</span>' ?></td>
                <td style="text-align:right">
                  <form method="post" style="display:inline" onsubmit="return confirm('Toggle status?')">
                    <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="to" value="<?= $r['is_active']?0:1 ?>">
                    <button class="btn warning" type="submit"><?= $r['is_active']?'Disable':'Enable' ?></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; if (!$rows): ?>
              <tr class="tr"><td colspan="5">No printers yet.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="col" style="max-width:420px">
        <div class="card">
          <strong>New Printer</strong>
          <div class="hr"></div>
          <form method="post" class="form-grid">
            <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
            <input type="hidden" name="action" value="create">
            <label>Name<input class="input" name="name" required></label>
            <label>Route / IP / Queue<input class="input" name="route" placeholder="e.g. kitchen@192.168.1.50"></label>
            <label style="grid-column:1/-1;display:flex;gap:8px;align-items:center"><input type="checkbox" name="is_active" checked> Active</label>
            <div style="grid-column:1/-1;display:flex;gap:8px"><button class="btn primary" type="submit">Create</button></div>
          </form>
        </div>
      </div>
    </div>
  </main>

<?php if (!$embedded): ?>
</div>
</body></html>
<?php endif; ?>