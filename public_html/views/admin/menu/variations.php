<?php
declare(strict_types=1);
$current = 'variations';
require_once __DIR__ . '/../_header.php';
require_once __DIR__ . '/../../../config/db.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function csrf_input(){ echo '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">'; }
function csrf_check($t){ return is_string($t ?? null) && hash_equals($_SESSION['csrf'], (string)$t); }

$err=null; $ok=null;

// Create attribute
if ($_SERVER['REQUEST_METHOD']==='POST') {
  try {
    if (!csrf_check($_POST['csrf']??'')) throw new Exception('Invalid CSRF');
    $action = $_POST['action'] ?? '';
    if ($action==='create_attr') {
      $name = trim($_POST['name'] ?? '');
      $is_required = isset($_POST['is_required']) ? 1 : 0;
      $is_active   = isset($_POST['is_active']) ? 1 : 0;
      $pdo->prepare("INSERT INTO attributes (name_en, name_ar, is_required, is_active) VALUES (?,?,?,?)")->execute([$name, $_POST['name_ar'] ?? null, $is_required, $is_active]);
      header("Location: /views/admin/menu/variations.php?ok=1"); exit;
    } elseif ($action==='create_value') {
      $attr_id = (int)$_POST['attribute_id'];
      $value = trim($_POST['value_en'] ?? '');
      $price_delta = (float)($_POST['price_delta'] ?? 0);
      $pdo->prepare("INSERT INTO attribute_values (attribute_id, value_en, value_ar, price_delta, is_active, sort_order) VALUES (?,?,?,?,?,?)")->execute([$attr_id, $value, $_POST['value_ar'] ?? null, $price_delta, 1, (int)($_POST['sort_order'] ?? 0)]);
      header("Location: /views/admin/menu/variations.php?ok=1&attr=".$attr_id); exit;
    } elseif ($action==='delete_attr') {
      $id=(int)$_POST['id']; $pdo->prepare("DELETE FROM attributes WHERE id=?")->execute([$id]);
      header("Location: /views/admin/menu/variations.php?ok=1"); exit;
    } elseif ($action==='delete_value') {
      $id=(int)$_POST['id']; $pdo->prepare("DELETE FROM attribute_values WHERE id=?")->execute([$id]);
      $aid=(int)($_POST['attribute_id'] ?? 0);
      header("Location: /views/admin/menu/variations.php?ok=1&attr=".$aid); exit;
    }
  } catch (Throwable $ex) { $err = $ex->getMessage(); }
}

$attr_id = isset($_GET['attr']) ? (int)$_GET['attr'] : 0;
$attrs = $pdo->query("SELECT * FROM attributes ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$values = [];
if ($attr_id) {
  $st = $pdo->prepare("SELECT * FROM attribute_values WHERE attribute_id=? ORDER BY sort_order, id");
  $st->execute([$attr_id]); $values = $st->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Variation</title>
<main style="padding:20px;max-width:1200px;margin:0 auto;font-family:system-ui">
  <h2 style="margin:0 0 12px">Variation</h2>
  <?php if ($err): ?><div style="background:#fee;border:1px solid #fbb;padding:10px;border-radius:10px;color:#900;margin:0 0 12px"><?= e($err) ?></div><?php endif; ?>
  <?php if (isset($_GET['ok'])): ?><div style="background:#eefbea;border:1px solid #b2e3b2;padding:10px;border-radius:10px;color:#08660b;margin:0 0 12px">Saved.</div><?php endif; ?>

  <section style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
    <div style="background:#fff;border:1px solid #eee;border-radius:12px;padding:12px">
      <h3 style="margin:0 0 10px">Attributes</h3>
      <form method="post" style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:0 0 12px">
        <?php csrf_input(); ?><input type="hidden" name="action" value="create_attr">
        <label>Name <input name="name" required></label>
        <label>Name (Arabic) <input name="name_ar"></label>
        <label>Required <input type="checkbox" name="is_required" value="1"></label>
        <label>Active <input type="checkbox" name="is_active" value="1" checked></label>
        <div style="grid-column:1/-1"><button style="padding:8px 12px;border:0;border-radius:10px;background:#0d6efd;color:#fff">Create Attribute</button></div>
      </form>

      <div style="overflow:auto">
        <table style="width:100%;border-collapse:collapse;min-width:600px">
          <thead><tr style="background:#f7f7fb"><th style="text-align:left;padding:8px;border-bottom:1px solid #eee">ID</th><th style="text-align:left;padding:8px;border-bottom:1px solid #eee">Name</th><th style="text-align:left;padding:8px;border-bottom:1px solid #eee">Required</th><th style="text-align:left;padding:8px;border-bottom:1px solid #eee">Active</th><th style="text-align:left;padding:8px;border-bottom:1px solid #eee">Actions</th></tr></thead>
          <tbody>
          <?php foreach ($attrs as $a): ?>
            <tr>
              <td style="padding:8px;border-bottom:1px solid #f0f0f0"><?= (int)$a['id'] ?></td>
              <td style="padding:8px;border-bottom:1px solid #f0f0f0"><a href="/views/admin/menu/variations.php?attr=<?= (int)$a['id'] ?>"><?= e($a['name_en']) ?></a></td>
              <td style="padding:8px;border-bottom:1px solid #f0f0f0"><?= ((int)$a['is_required'] ? 'Yes':'No') ?></td>
              <td style="padding:8px;border-bottom:1px solid #f0f0f0"><?= ((int)$a['is_active'] ? 'Yes':'No') ?></td>
              <td style="padding:8px;border-bottom:1px solid #f0f0f0">
                <form method="post" style="display:inline"><?php csrf_input(); ?><input type="hidden" name="action" value="delete_attr"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button onclick="return confirm('Delete attribute #<?= (int)$a['id'] ?>?')" style="padding:6px 10px;border:1px solid #eee;border-radius:8px;background:#fff">Delete</button></form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div style="background:#fff;border:1px solid #eee;border-radius:12px;padding:12px">
      <h3 style="margin:0 0 10px">Values <?= $attr_id ? 'for attribute #'.(int)$attr_id : '' ?></h3>
      <?php if ($attr_id): ?>
      <form method="post" style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:0 0 12px">
        <?php csrf_input(); ?><input type="hidden" name="action" value="create_value">
        <input type="hidden" name="attribute_id" value="<?= (int)$attr_id ?>">
        <label>Value <input name="value_en" required></label>
        <label>Value (Arabic) <input name="value_ar"></label>
        <label>Price Δ <input type="number" step="0.01" name="price_delta" value="0"></label>
        <label>Sort <input type="number" name="sort_order" value="0"></label>
        <div style="grid-column:1/-1"><button style="padding:8px 12px;border:0;border-radius:10px;background:#0d6efd;color:#fff">Add Value</button></div>
      </form>
      <?php else: ?>
      <p style="color:#666">Select an attribute on the left to manage its values.</p>
      <?php endif; ?>

      <div style="overflow:auto">
        <table style="width:100%;border-collapse:collapse;min-width:600px">
          <thead><tr style="background:#f7f7fb"><th style="text-align:left;padding:8px;border-bottom:1px solid #eee">ID</th><th style="text-align:left;padding:8px;border-bottom:1px solid #eee">Value</th><th style="text-align:left;padding:8px;border-bottom:1px solid #eee">Price Δ</th><th style="text-align:left;padding:8px;border-bottom:1px solid #eee">Active</th><th style="text-align:left;padding:8px;border-bottom:1px solid #eee">Actions</th></tr></thead>
          <tbody>
          <?php foreach ($values as $v): ?>
            <tr>
              <td style="padding:8px;border-bottom:1px solid #f0f0f0"><?= (int)$v['id'] ?></td>
              <td style="padding:8px;border-bottom:1px solid #f0f0f0"><?= e($v['value_en']) ?></td>
              <td style="padding:8px;border-bottom:1px solid #f0f0f0"><?= number_format((float)$v['price_delta'],2) ?></td>
              <td style="padding:8px;border-bottom:1px solid #f0f0f0"><?= ((int)$v['is_active'] ? 'Yes':'No') ?></td>
              <td style="padding:8px;border-bottom:1px solid #f0f0f0">
                <form method="post" style="display:inline"><?php csrf_input(); ?><input type="hidden" name="action" value="delete_value"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><input type="hidden" name="attribute_id" value="<?= (int)$attr_id ?>"><button onclick="return confirm('Delete value #<?= (int)$v['id'] ?>?')" style="padding:6px 10px;border:1px solid #eee;border-radius:8px;background:#fff">Delete</button></form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</main>
