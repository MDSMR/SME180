<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// public_html/views/admin/rewards/cashback/ledger.php
declare(strict_types=1);

/* Bootstrap */
$bootstrap_warning=''; $bootstrap_ok=false;
$bootstrap_path=dirname(__DIR__,4).'/config/db.php';
if(!is_file($bootstrap_path)){ $bootstrap_warning='Configuration file not found: /config/db.php'; }
else{ $prev=set_error_handler(function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
  try{ require_once $bootstrap_path; if(function_exists('db')&&function_exists('use_backend_session')){ $bootstrap_ok=true; use_backend_session(); }
  else{ $bootstrap_warning='Required functions missing in config/db.php (db(), use_backend_session()).';}}
  catch(Throwable $e){ $bootstrap_warning='Bootstrap error: '.$e->getMessage(); } finally{ if($prev) set_error_handler($prev); } }
if(!$bootstrap_ok){ echo "<h1>Cashback – Ledger</h1><div style='color:red;'>".htmlspecialchars($bootstrap_warning)."</div>"; exit; }

/* Auth */
$user=$_SESSION['user']??null; if(!$user){ header('Location:/views/auth/login.php'); exit; }
$tenantId=(int)$user['tenant_id']; $userId=(int)$user['id'];

/* DB */
try { $db = db(); } catch (Throwable $e) { http_response_code(500); echo 'DB error'; exit; }

/* Filters & paging */
$q      = trim((string)($_GET['q'] ?? ''));
$type   = trim((string)($_GET['type'] ?? ''));
$from   = trim((string)($_GET['from'] ?? ''));
$to     = trim((string)($_GET['to'] ?? ''));
$page   = max(1, (int)($_GET['p'] ?? 1));
$limit  = 20;
$offset = ($page-1)*$limit;

/* Build SQL */
$where = ["l.tenant_id = :t"];
$params = [':t'=>$tenantId];

if ($type !== '') {
  $where[] = "l.type = :tp"; $params[':tp'] = $type;
}
if ($from !== '') { $where[] = "l.created_at >= :f"; $params[':f'] = $from.' 00:00:00'; }
if ($to   !== '') { $where[] = "l.created_at <= :to"; $params[':to'] = $to.' 23:59:59'; }
if ($q !== '') {
  $where[] = "(c.name LIKE :q OR c.phone LIKE :q OR c.id = :qid)";
  $params[':q'] = '%'.$q.'%';
  $params[':qid'] = ctype_digit($q) ? (int)$q : 0;
}

/* Fetch rows */
$rows = []; $total = 0;
try {
  $sql = "
    SELECT SQL_CALC_FOUND_ROWS
           l.id, l.created_at, l.type, l.cash_delta, l.order_id, l.user_id, l.reason,
           c.id AS customer_id, c.name AS customer_name
    FROM loyalty_ledger l
    LEFT JOIN customers c ON c.id = l.customer_id
    WHERE ".implode(' AND ', $where)."
    ORDER BY l.id DESC
    LIMIT :o,:l
  ";
  $st = $db->prepare($sql);
  foreach ($params as $k=>$v) $st->bindValue($k, $v);
  $st->bindValue(':o', $offset, PDO::PARAM_INT);
  $st->bindValue(':l', $limit, PDO::PARAM_INT);
  $st->execute();
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $total = (int)$db->query("SELECT FOUND_ROWS()")->fetchColumn();
} catch (Throwable $e) {}

/* Pager */
$pages = max(1, (int)ceil($total / $limit));
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$page_title="Rewards · Cashback · Ledger";
include dirname(__DIR__,3).'/partials/admin_header.php';

function cashback_tabs(string $active): void { $b='/views/admin/rewards/cashback';
  $t=['overview'=>['Overview',"$b/overview.php"],'rules'=>['Rules',"$b/rules.php"],'ledger'=>['Ledger',"$b/ledger.php"],'wallets'=>['Wallets',"$b/wallets.php"],'adjust'=>['Adjustments',"$b/adjustments.php"],'reports'=>['Reports',"$b/reports.php"]];
  echo '<ul class="nav nav-tabs mb-3">'; foreach($t as $k=>[$l,$h]){ $a=$k===$active?'active':''; echo "<li class='nav-item'><a class='nav-link $a' href='$h'>$l</a></li>"; } echo '</ul>';
}
?>
<div class="container mt-4">
  <nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="/views/admin/rewards/index.php">Rewards</a></li>
    <li class="breadcrumb-item"><a href="/views/admin/rewards/cashback/overview.php">Cashback</a></li>
    <li class="breadcrumb-item active" aria-current="page">Ledger</li>
  </ol></nav>

  <h1 class="mb-2">Cashback · Ledger</h1>
  <p class="text-muted">All wallet transactions (issue, redeem, adjust, expire).</p>

  <?php cashback_tabs('ledger'); ?>

  <div class="card shadow-sm"><div class="card-body">
    <form class="row g-3 mb-3" method="get">
      <div class="col-md-3"><label class="form-label">Member / Phone / ID</label><input type="text" name="q" class="form-control" value="<?= h($q) ?>" placeholder="Name, phone or ID"></div>
      <div class="col-md-2"><label class="form-label">Type</label>
        <select name="type" class="form-select">
          <option value="">Any</option>
          <?php foreach (['cashback_earn','cashback_redeem','adjust','expire'] as $opt): ?>
            <option value="<?= $opt ?>" <?= $opt===$type?'selected':''; ?>><?= $opt ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= h($from) ?>"></div>
      <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= h($to) ?>"></div>
      <div class="col-md-1 d-flex align-items-end"><button class="btn btn-outline-secondary w-100">Filter</button></div>
    </form>

    <div class="table-responsive">
      <table class="table table-sm table-striped align-middle">
        <thead><tr><th>Date/Time</th><th>Member</th><th>Type</th><th class="text-end">Amount</th><th>Reason / Notes</th><th>Order</th><th>By</th></tr></thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="7">No transactions found.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr>
              <td><?= h($r['created_at']) ?></td>
              <td><?= h($r['customer_name'] ?: ('ID #'.(int)$r['customer_id'])) ?></td>
              <td><?= h($r['type']) ?></td>
              <td class="text-end"><?= number_format((float)$r['cash_delta'], 2, '.', '') ?></td>
              <td><?= h($r['reason'] ?? '') ?></td>
              <td><?= $r['order_id'] ? ('#'.(int)$r['order_id']) : '—' ?></td>
              <td><?= $r['user_id'] ? ('#'.(int)$r['user_id']) : '—' ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <nav>
      <ul class="pagination pagination-sm mb-0">
        <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="<?= $page<=1?'#':'?'.http_build_query(array_merge($_GET,['p'=>$page-1])) ?>">Prev</a></li>
        <li class="page-item active"><span class="page-link"><?= $page ?></span></li>
        <li class="page-item <?= $page>=$pages?'disabled':'' ?>"><a class="page-link" href="<?= $page>=$pages?'#':'?'.http_build_query(array_merge($_GET,['p'=>$page+1])) ?>">Next</a></li>
      </ul>
    </nav>
  </div></div>
</div>
<?php include dirname(__DIR__,3).'/partials/admin_footer.php'; ?>