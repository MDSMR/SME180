<?php
declare(strict_types=1);
$current='setup'; $setup_tab='branches';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['csrf'])) { $_SESSION['csrf']=bin2hex(random_bytes(16)); }
$CSRF=$_SESSION['csrf'];

require_once __DIR__ . '/../_header.php';
require_once __DIR__ . '/../../config/db.php';
$pdo = db();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function post($k,$d=null){ return $_POST[$k] ?? $d; }
function to_bool($v):int{ if(is_string($v)) $v=strtolower(trim($v)); return in_array($v,[1,'1','true','on','yes'],true)?1:0; }

$err=null;

/* ensure tables exist (soft check) */
$hasCompanies = true;
try{ $pdo->query("SELECT 1 FROM companies LIMIT 1"); }catch(Throwable){ $hasCompanies=false; }
$hasBranches  = true;
try{ $pdo->query("SELECT 1 FROM branches LIMIT 1"); }catch(Throwable){ $hasBranches=false; }

if (!$hasCompanies || !$hasBranches){
  $err = 'Companies/Branches tables are missing. Please run the provided SQL migration first.';
}

/* actions */
if (!$err && $_SERVER['REQUEST_METHOD']==='POST'){
  try{
    if (!hash_equals($CSRF,(string)post('csrf'))) throw new RuntimeException('Invalid CSRF token.');
    $action=(string)post('action','');

    if ($action==='create'){
      $company_id=(int)post('company_id',0); if ($company_id<=0) throw new RuntimeException('Company is required.');
      $name=trim((string)post('name','')); if($name==='') throw new RuntimeException('Branch name is required.');
      $is_active=to_bool(post('is_active','1'));
      $st=$pdo->prepare("INSERT INTO branches (company_id,name,is_active) VALUES (:c,:n,:a)");
      $st->execute([':c'=>$company_id, ':n'=>$name, ':a'=>$is_active]);
      header('Location: '.$_SERVER['REQUEST_URI'].'?ok=1'); exit;
    }
    if ($action==='archive'){
      $id=(int)post('id',0); if($id<=0) throw new RuntimeException('Invalid branch.');
      $pdo->prepare("UPDATE branches SET is_active=0, archived_at=NOW(), updated_at=NOW() WHERE id=:id")->execute([':id'=>$id]);
      header('Location: '.$_SERVER['REQUEST_URI'].'?ok=1'); exit;
    }
    if ($action==='unarchive'){
      $id=(int)post('id',0); if($id<=0) throw new RuntimeException('Invalid branch.');
      $pdo->prepare("UPDATE branches SET archived_at=NULL, is_active=1, updated_at=NOW() WHERE id=:id")->execute([':id'=>$id]);
      header('Location: '.$_SERVER['REQUEST_URI'].'?ok=1'); exit;
    }
    if ($action==='update'){
      $id=(int)post('id',0); if($id<=0) throw new RuntimeException('Invalid branch.');
      $company_id=(int)post('company_id',0); if ($company_id<=0) throw new RuntimeException('Company is required.');
      $name=trim((string)post('name','')); if($name==='') throw new RuntimeException('Branch name is required.');
      $is_active=to_bool(post('is_active','1'));
      $st=$pdo->prepare("UPDATE branches SET company_id=:c, name=:n, is_active=:a, updated_at=NOW() WHERE id=:id");
      $st->execute([':c'=>$company_id, ':n'=>$name, ':a'=>$is_active, ':id'=>$id]);
      header('Location: '.$_SERVER['REQUEST_URI'].'?ok=1'); exit;
    }

    throw new RuntimeException('Unknown action.');
  }catch(Throwable $e){ $err=$e->getMessage(); }
}

/* data */
$companies = $hasCompanies ? $pdo->query("SELECT id,name FROM companies WHERE archived_at IS NULL AND is_active=1 ORDER BY name,id")->fetchAll(PDO::FETCH_ASSOC) : [];
$branches  = $hasBranches  ? $pdo->query("SELECT b.id,b.name,b.is_active,b.archived_at,b.company_id,c.name AS company
                                          FROM branches b JOIN companies c ON c.id=b.company_id
                                          ORDER BY c.name,b.name")->fetchAll(PDO::FETCH_ASSOC) : [];

?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup — Branches</title>
<style>
  :root{--brand:#0d6efd;--bg:#f7f7fb;--line:#eee}
  body{margin:0;font-family:system-ui,Segoe UI,Arial,sans-serif;background:#fff}
  main{padding:20px;max-width:1100px;margin:0 auto}
  .tabs{display:flex;gap:10px;margin-bottom:16px}
  .tabs a{padding:8px 12px;border:1px solid #ddd;border-radius:10px;text-decoration:none;color:#111}
  .tabs a.active{background:var(--brand);color:#fff;border-color:var(--brand)}

  .row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  .card{border:1px solid var(--line);border-radius:12px}
  .card .hd{padding:10px 12px;background:#fafafa;border-bottom:1px solid #f1f1f1;font-weight:600}
  .card .bd{padding:12px}
  label{display:block;font-size:13px;margin:6px 0 4px}
  input, select{width:100%;padding:10px;border:1px solid #ddd;border-radius:10px}
  .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:1px solid #e5e7eb;background:#fff;padding:8px 12px;border-radius:10px;cursor:pointer;min-width:110px}
  .btn.primary{background:var(--brand);color:#fff;border-color:var(--brand)}
  .btn.danger{background:#b00020;color:#fff;border-color:#b00020}
  .btn.warning{background:#ffb020;color:#222;border-color:#ffb020}
  .pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px}
  .pill.on{background:#e8f4ff;color:#0753b6}
  .pill.off{background:#fbeaea;color:#8b0000}
  table{width:100%;border-collapse:separate;border-spacing:0;border:1px solid #eee;border-radius:12px;overflow:hidden}
  th,td{padding:10px 12px;border-bottom:1px solid #f5f5f5;text-align:left;font-size:14px}
  th{background:#fafafa}
  .msg{margin:8px 0 12px;font-size:13px}
  .msg.err{color:#b00020}
  .msg.ok{color:#08780d}
</style>

<main>
  <nav class="tabs">
    <a href="/views/admin/setup/index.php">Setup</a>
    <a href="/views/admin/setup/company.php">Company</a>
    <a href="/views/admin/setup/branches.php" class="active">Branches</a>
    <a href="/views/admin/setup/printers.php">Printers</a>
    <a href="/views/admin/setup/users.php">Users</a>
    <a href="/views/admin/setup/tax.php">Tax</a>
    <a href="/views/admin/setup/service_charge.php">Service Charge</a>
    <a href="/views/admin/setup/aggregators.php">Aggregators</a>
  </nav>

  <?php if ($err): ?><div class="msg err">❌ <?= h($err) ?></div>
  <?php elseif (isset($_GET['ok'])): ?><div class="msg ok">✅ Saved.</div><?php endif; ?>

  <div class="row">
    <!-- Create -->
    <div class="card">
      <div class="hd">Create Branch</div>
      <div class="bd">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
          <input type="hidden" name="action" value="create">
          <label>Company</label>
          <select name="company_id" required>
            <option value="">— Select company —</option>
            <?php foreach ($companies as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
            <?php endforeach; ?>
          </select>

          <label style="margin-top:8px">Branch name</label>
          <input name="name" required placeholder="e.g. Salmiya">

          <label class="inline" style="display:flex;align-items:center;gap:8px;margin-top:8px">
            <input type="checkbox" name="is_active" value="1" checked> Active
          </label>

          <div style="margin-top:12px">
            <button class="btn primary" type="submit">Create</button>
          </div>
        </form>
      </div>
    </div>

    <!-- List -->
    <div class="card">
      <div class="hd">Branches</div>
      <div class="bd">
        <table>
          <thead>
            <tr>
              <th style="width:60px">ID</th>
              <th>Branch</th>
              <th>Company</th>
              <th style="width:120px">Status</th>
              <th style="width:320px">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$branches): ?>
            <tr><td colspan="5" class="muted">No branches yet.</td></tr>
          <?php else: foreach ($branches as $b): $arch=($b['archived_at']!==null); ?>
            <tr>
              <td>#<?= (int)$b['id'] ?></td>
              <td><?= h($b['name']) ?></td>
              <td><?= h($b['company']) ?></td>
              <td><?= $arch ? '<span class="pill off">Archived</span>' : ((int)$b['is_active']===1 ? '<span class="pill on">Active</span>' : '<span class="pill off">Inactive</span>') ?></td>
              <td>
                <!-- inline edit form -->
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                  <select name="company_id" required>
                    <?php foreach ($companies as $c): ?>
                      <option value="<?= (int)$c['id'] ?>" <?= ((int)$b['company_id']===(int)$c['id'])?'selected':'' ?>><?= h($c['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <input name="name" value="<?= h($b['name']) ?>" required style="width:160px">
                  <label style="display:inline-flex;align-items:center;gap:6px;margin-left:6px">
                    <input type="checkbox" name="is_active" value="1" <?= ((int)$b['is_active']===1)?'checked':'' ?>> Active
                  </label>
                  <button class="btn secondary" type="submit">Save</button>
                </form>

                <?php if ($arch): ?>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                    <input type="hidden" name="action" value="unarchive">
                    <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                    <button class="btn warning" type="submit">Unarchive</button>
                  </form>
                <?php else: ?>
                  <form method="post" style="display:inline" onsubmit="return confirm('Archive this branch?');">
                    <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                    <input type="hidden" name="action" value="archive">
                    <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                    <button class="btn danger" type="submit">Archive</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>