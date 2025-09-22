<?php
echo "<!-- CANARY items.php LIVE @ ".gmdate('c')." -->";
if (isset($_GET['who'])) {
  $p = __FILE__;
  $m = @filemtime(__FILE__);
  $md5 = @md5_file(__FILE__);
  $op = function_exists('opcache_get_status') ? opcache_get_status(false) : null;
  header('Content-Type: text/plain; charset=UTF-8');
  echo "RUNNING FILE: $p\n";
  echo "MTIME: " . ($m ? date('c', $m) : 'n/a') . "\n";
  echo "MD5 : $md5\n";
  echo "OPCACHE ENABLED: " . ($op && !empty($op['opcache_enabled']) ? 'yes' : 'no') . "\n";
  exit;
}
// views/admin/menu/items.php — Items list + Add/Edit (v5, 2025-08-14)
// Changes in this version:
// - VIEW TABLE:
//   * Shows Category and Branch columns with actual names (supports many-to-many + legacy single FKs).
//   * Wider table + increased spacing between Price / Standard Cost / Status.
//   * "+ New" button made slightly taller and narrower.
//   * Table container width aligned to typical header container (max ~1160px) with safe side padding.
// - EDIT/NEW FORM:
//   * “Item Groups (Categories)” renamed to “Category”.
//   * Category is MULTI-SELECT but styled to the SAME height/shape as Company (single-line box).
//   * When Open Price is enabled, Price and Standard Cost are CLEARED and DISABLED immediately;
//     when disabled, both fields are re-enabled for instant input.
// - DATA PATHS remain schema-adaptive and safe (works with product_categories OR legacy products.category_id).

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/../../../config/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function csrf(){ return $_SESSION['csrf']; }
function flash($m,$t='ok'){ $_SESSION['flash'][]=['m'=>$m,'t'=>$t]; }
function flashes(){ $f=$_SESSION['flash']??[]; unset($_SESSION['flash']); return $f; }
function csrf_ok($t){ return is_string($t) && hash_equals($_SESSION['csrf'] ?? '', $t); }

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------- Schema helpers ---------- */
$prodCols = [];
try {
  $s = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='products'");
  $s->execute();
  foreach ($s->fetchAll(PDO::FETCH_COLUMN,0) as $c){ $prodCols[strtolower($c)]=true; }
} catch (Throwable $e) {}
$hasProd = fn($c)=>isset($prodCols[strtolower($c)]);

$hasTable = function(string $t) use ($pdo): bool {
  try { $s=$pdo->prepare("SHOW TABLES LIKE ?"); $s->execute([$t]); return (bool)$s->fetchColumn(); }
  catch(Throwable $e){ return false; }
};
$colsOf = function(string $t) use ($pdo): array {
  try { $s=$pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
        $s->execute([$t]); $m=[]; foreach($s->fetchAll(PDO::FETCH_COLUMN,0) as $c){ $m[strtolower($c)]=true; } return $m; }
  catch(Throwable $e){ return []; }
};
$colNullable = function(string $table, string $column) use ($pdo): bool {
  try { $q=$pdo->prepare("SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
        $q->execute([$table, $column]); return $q->fetchColumn() !== 'NO'; }
  catch (Throwable $e){ return true; }
};

/* Names */
$nameEnExpr = $hasProd('prod_name_en') ? 'p.prod_name_en'
           : ($hasProd('name_en') ? 'p.name_en'
           : ($hasProd('name') ? 'p.name'
           : "JSON_UNQUOTE(JSON_EXTRACT(p.name_i18n,'$.en'))"));
$nameArExpr = $hasProd('prod_name_ar') ? 'p.prod_name_ar'
           : ($hasProd('name_ar') ? 'p.name_ar'
           : "JSON_UNQUOTE(JSON_EXTRACT(p.name_i18n,'$.ar'))");

/* Price & cost */
$costCol = null;
foreach (['standard_cost','cost','cost_price','purchase_price','unit_cost','item_cost'] as $c){ if($hasProd($c)){ $costCol=$c; break; } }

/* Optional */
$calCol = null; foreach (['calories','kcal','energy_kcal'] as $c){ if($hasProd($c)){ $calCol=$c; break; } }
$posVisCol = null; foreach (['pos_visible','is_pos_visible','visible_in_pos','pos_enabled','show_in_pos'] as $c){ if($hasProd($c)){ $posVisCol=$c; break; } }
$openPriceCol = null; foreach (['is_open_price','open_price','allow_open_price','price_open'] as $c){ if($hasProd($c)){ $openPriceCol=$c; break; } }

$prodCompanyCol = null; foreach (['company_id','comp_id','tenant_id','org_id'] as $c){ if($hasProd($c)){ $prodCompanyCol=$c; break; } }
$prodBranchCol  = null; foreach (['branch_id','location_id','store_id','outlet_id'] as $c){ if($hasProd($c)){ $prodBranchCol=$c; break; } }

$companyNullable = $prodCompanyCol ? $colNullable('products',$prodCompanyCol) : true;
$branchNullable  = $prodBranchCol  ? $colNullable('products',$prodBranchCol)  : true;

/* Category + link */
$categoryTable=null; $linkTable=null;
foreach (['categories','category','menu_categories','item_groups'] as $t){ if($hasTable($t)){ $categoryTable=$t; break; } }
foreach (['product_categories','products_categories','product_category','category_product','categories_products','product_to_category'] as $t){ if($hasTable($t)){ $linkTable=$t; break; } }

$categoryIdCol=null; $categoryLabelExpr=null; $linkProdCol=null; $linkCatCol=null;
if ($categoryTable){
  $catc=$colsOf($categoryTable);
  foreach (['id','category_id','cat_id'] as $c){ if(isset($catc[$c])){ $categoryIdCol=$c; break; } }
  if (isset($catc['cat_name_en']))       $categoryLabelExpr="$categoryTable.cat_name_en";
  elseif (isset($catc['name_en']))       $categoryLabelExpr="$categoryTable.name_en";
  elseif (isset($catc['name']))          $categoryLabelExpr="$categoryTable.name";
  elseif (isset($catc['name_i18n']))     $categoryLabelExpr="JSON_UNQUOTE(JSON_EXTRACT($categoryTable.name_i18n,'$.en'))";
  else                                   $categoryLabelExpr="CAST($categoryTable.".($categoryIdCol?:'id')." AS CHAR)";
}
if ($linkTable){
  $lnk=$colsOf($linkTable);
  foreach (['product_id','prod_id','item_id'] as $c){ if(isset($lnk[$c])){ $linkProdCol=$c; break; } }
  foreach (['category_id','cat_id'] as $c){ if(isset($lnk[$c])){ $linkCatCol=$c; break; } }
}
$hasManyToMany = ($categoryTable && $categoryIdCol && $categoryLabelExpr && $linkTable && $linkProdCol && $linkCatCol);
$hasLegacyCat  = ($categoryTable && $categoryIdCol && $hasProd('category_id'));

/* Companies — prefer companies(id, name) */
$companyTable = $hasTable('companies') ? 'companies' : null;
$companies = [];
if ($companyTable){
  $companyQueries = [
    "SELECT id, name AS label FROM $companyTable WHERE COALESCE(is_active,1)=1 ORDER BY name ASC",
    "SELECT id, company_name AS label FROM $companyTable WHERE COALESCE(is_active,1)=1 ORDER BY company_name ASC",
    "SELECT id, display_name AS label FROM $companyTable WHERE COALESCE(is_active,1)=1 ORDER BY display_name ASC",
    "SELECT id, title AS label FROM $companyTable WHERE COALESCE(is_active,1)=1 ORDER BY title ASC",
    "SELECT id, label AS label FROM $companyTable WHERE COALESCE(is_active,1)=1 ORDER BY label ASC",
    "SELECT id, JSON_UNQUOTE(JSON_EXTRACT(name_i18n,'$.en')) AS label FROM $companyTable WHERE COALESCE(is_active,1)=1 ORDER BY label ASC",
    "SELECT id, CAST(id AS CHAR) AS label FROM $companyTable ORDER BY id ASC"
  ];
  foreach ($companyQueries as $sql){
    try { $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); if ($rows){ $companies = $rows; break; } }
    catch (Throwable $e){ /* try next */ }
  }
}

/* Branch master + link */
$branchTable=null; foreach(['branches','branch','locations','stores','outlets'] as $t){ if($hasTable($t)){ $branchTable=$t; break; } }
$branches=[]; $branchIdCol=null; $branchLabelExpr=null;
if ($branchTable){
  $bcols=$colsOf($branchTable);
  foreach (['id','branch_id','location_id','store_id','outlet_id'] as $c){ if(isset($bcols[$c])){ $branchIdCol=$c; break; } }
  if (isset($bcols['name']))        $branchLabelExpr="$branchTable.name";
  elseif (isset($bcols['name_en'])) $branchLabelExpr="$branchTable.name_en";
  elseif (isset($bcols['branch_name'])) $branchLabelExpr="$branchTable.branch_name";
  elseif (isset($bcols['title']))   $branchLabelExpr="$branchTable.title";
  elseif (isset($bcols['label']))   $branchLabelExpr="$branchTable.label";
  elseif (isset($bcols['name_i18n'])) $branchLabelExpr="JSON_UNQUOTE(JSON_EXTRACT($branchTable.name_i18n,'$.en'))";
  else $branchLabelExpr="CAST($branchTable.".($branchIdCol?:'id')." AS CHAR)";

  $branchQueries = [];
  if (isset($bcols['name']))        $branchQueries[]="SELECT $branchIdCol AS id, name AS label FROM $branchTable WHERE COALESCE(is_active,1)=1 ORDER BY name ASC";
  if (isset($bcols['name_en']))     $branchQueries[]="SELECT $branchIdCol AS id, name_en AS label FROM $branchTable WHERE COALESCE(is_active,1)=1 ORDER BY name_en ASC";
  if (isset($bcols['branch_name'])) $branchQueries[]="SELECT $branchIdCol AS id, branch_name AS label FROM $branchTable WHERE COALESCE(is_active,1)=1 ORDER BY branch_name ASC";
  if (isset($bcols['title']))       $branchQueries[]="SELECT $branchIdCol AS id, title AS label FROM $branchTable WHERE COALESCE(is_active,1)=1 ORDER BY title ASC";
  if (isset($bcols['label']))       $branchQueries[]="SELECT $branchIdCol AS id, label AS label FROM $branchTable WHERE COALESCE(is_active,1)=1 ORDER BY label ASC";
  if (isset($bcols['name_i18n']))   $branchQueries[]="SELECT $branchIdCol AS id, JSON_UNQUOTE(JSON_EXTRACT(name_i18n,'$.en')) AS label FROM $branchTable WHERE COALESCE(is_active,1)=1 ORDER BY label ASC";
  $branchQueries[]="SELECT $branchIdCol AS id, CAST($branchIdCol AS CHAR) AS label FROM $branchTable ORDER BY $branchIdCol ASC";
  foreach ($branchQueries as $sql){
    try { $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); if ($rows){ $branches = $rows; break; } }
    catch (Throwable $e){ /* try next */ }
  }
}

/* Branch link table (multi) */
$branchLinkTable=null; $branchLinkProdCol=null; $branchLinkBrCol=null;
foreach (['product_branches','products_branches','product_branch','branch_product','branches_products','product_to_branch'] as $t){ if($hasTable($t)){ $branchLinkTable=$t; break; } }
if ($branchLinkTable){
  $l2=$colsOf($branchLinkTable);
  foreach (['product_id','prod_id','item_id'] as $c){ if(isset($l2[$c])){ $branchLinkProdCol=$c; break; } }
  foreach (['branch_id','location_id','store_id','outlet_id'] as $c){ if(isset($l2[$c])){ $branchLinkBrCol=$c; break; } }
}

/* Defaults (company/branch) */
$defaultCompanyId = (int)($_SESSION['default_company_id'] ?? $_SESSION['company_id'] ?? 0) ?: null;
$defaultBranchId  = (int)($_SESSION['default_branch_id']  ?? $_SESSION['branch_id']  ?? 0) ?: null;
if ($companyTable && !$defaultCompanyId){
  try{ $cnt=(int)$pdo->query("SELECT COUNT(*) FROM $companyTable")->fetchColumn();
       if ($cnt===1){ $defaultCompanyId=(int)$pdo->query("SELECT id FROM $companyTable LIMIT 1")->fetchColumn(); } } catch(Throwable $e){}
}
if ($branchTable && !$defaultBranchId && $branchIdCol){
  try{ $cnt=(int)$pdo->query("SELECT COUNT(*) FROM $branchTable")->fetchColumn();
       if ($cnt===1){ $defaultBranchId=(int)$pdo->query("SELECT $branchIdCol FROM $branchTable LIMIT 1")->fetchColumn(); } } catch(Throwable $e){}
}

/* ---------- Filters ---------- */
$status = $_GET['status'] ?? 'active';
if (!in_array($status,['active','archived','all'],true)) $status='active';
$q = trim((string)($_GET['q'] ?? ''));

/* Category options */
$categories=[]; if ($categoryTable && $categoryIdCol && $categoryLabelExpr){
  try{
    $categories=$pdo->query("SELECT $categoryTable.$categoryIdCol AS id, $categoryLabelExpr AS label FROM $categoryTable WHERE COALESCE(is_active,1)=1 ORDER BY label ASC")->fetchAll(PDO::FETCH_ASSOC);
  }catch(Throwable $e){}
}

/* ---------- POST actions ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try {
    if (!csrf_ok($_POST['csrf'] ?? '')) throw new Exception('Invalid request.');
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($action==='create' || $action==='update'){
      $name_en = trim((string)($_POST['name_en'] ?? ''));
      $name_ar = trim((string)($_POST['name_ar'] ?? ''));
      if ($name_en==='' && $name_ar==='') throw new Exception('Name (EN or AR) is required.');

      $inPrice = isset($_POST['price']) ? (float)$_POST['price'] : 0.0;
      $inCost  = isset($_POST['standard_cost']) ? (float)$_POST['standard_cost'] : 0.0;
      $inCals  = isset($_POST['calories']) ? (int)$_POST['calories'] : 0;
      $inOpen  = isset($_POST['is_open_price']) ? 1 : 0;

      // Open Price rules (server-side)
      if ($inOpen === 1) {
        if (($inPrice ?? 0) > 0 || ($inCost ?? 0) > 0) throw new Exception('For Open Price items, Price and Standard Cost must be 0.');
      } else {
        if ($hasProd('price') && $inPrice <= 0) throw new Exception('Price is required and must be greater than 0.');
        if ($costCol && $inCost <= 0)          throw new Exception('Standard Cost is required and must be greater than 0.');
      }

      $payload=[];
      if ($hasProd('name_i18n')) { $payload['name_i18n']=json_encode(['en'=>$name_en,'ar'=>$name_ar], JSON_UNESCAPED_UNICODE); }
      else {
        if ($hasProd('name'))    $payload['name']    = $name_en ?: $name_ar;
        if ($hasProd('name_en')) $payload['name_en'] = $name_en;
        if ($hasProd('name_ar')) $payload['name_ar'] = $name_ar;
      }
      if ($hasProd('price'))     $payload['price']= ($inOpen===1 ? 0.00 : (float)$inPrice);
      if ($costCol)              $payload[$costCol]= ($inOpen===1 ? 0.00 : (float)$inCost);
      if ($calCol)               $payload[$calCol] = $inCals;
      if ($posVisCol)            $payload[$posVisCol] = isset($_POST['pos_visible']) ? 1 : 0;
      if ($openPriceCol)         $payload[$openPriceCol] = $inOpen;
      if ($hasProd('sequence'))  $payload['sequence']=(int)($_POST['sequence'] ?? 0);
      if ($hasProd('is_active')) $payload['is_active']=isset($_POST['is_active'])?1:0;

      // Company FK
      if ($prodCompanyCol){
        $cidRaw = trim((string)($_POST['company_id'] ?? ''));
        if ($action==='create'){
          if ($cidRaw===''){ if(!$companyNullable) throw new Exception('Company is required.'); $payload[$prodCompanyCol]=null; }
          else { $payload[$prodCompanyCol]=(int)$cidRaw; }
        } else {
          if ($cidRaw===''){ if($companyNullable) $payload[$prodCompanyCol]=null; }
          else { $payload[$prodCompanyCol]=(int)$cidRaw; }
        }
      }

      // Branch FK (single)
      if ($prodBranchCol){
        $bidRaw = trim((string)($_POST['branch_id'] ?? ''));
        if ($action==='create'){
          if ($bidRaw===''){ if(!$branchNullable) throw new Exception('Branch is required.'); $payload[$prodBranchCol]=null; }
          else { $payload[$prodBranchCol]=(int)$bidRaw; }
        } else {
          if ($bidRaw===''){ if($branchNullable) $payload[$prodBranchCol]=null; }
          else { $payload[$prodBranchCol]=(int)$bidRaw; }
        }
      }

      if ($action==='create'){
        $cols=array_keys($payload); $ph=implode(',',array_fill(0,count($cols),'?'));
        $pdo->prepare("INSERT INTO products (".implode(',',$cols).") VALUES ($ph)")->execute(array_values($payload));
        $id=(int)$pdo->lastInsertId(); flash('Item created','ok');
      } else {
        if ($id<=0) throw new Exception('Missing item ID.');
        $set=[]; $vals=[]; foreach($payload as $c=>$v){ $set[]="$c=?"; $vals[]=$v; }
        if (!$set) throw new Exception('Nothing to update.');
        $vals[]=$id;
        $pdo->prepare("UPDATE products SET ".implode(',',$set)." WHERE id=?")->execute($vals);
        flash('Item updated','ok');
      }

      // Category mapping (MULTI)
      if (isset($_POST['category_ids'])){
        $catIds = is_array($_POST['category_ids']) ? array_unique(array_map('intval', $_POST['category_ids'])) : [];
        $catIds = array_values(array_filter($catIds, fn($v)=>$v>0));
        if ($id>0){
          if ($hasManyToMany){
            $pdo->prepare("DELETE FROM $linkTable WHERE $linkProdCol=?")->execute([$id]);
            foreach ($catIds as $cid){ $pdo->prepare("INSERT INTO $linkTable ($linkProdCol,$linkCatCol) VALUES (?,?)")->execute([$id,$cid]); }
          } elseif ($hasLegacyCat){
            if (!empty($catIds)){ $pdo->prepare("UPDATE products SET category_id=? WHERE id=?")->execute([reset($catIds),$id]); }
            else { $legacyNullable = $colNullable('products','category_id'); if ($legacyNullable){ $pdo->prepare("UPDATE products SET category_id=NULL WHERE id=?")->execute([$id]); } }
          }
        }
      }

      // Branch mapping via link (multi)
      if ($branchLinkTable){
        $chosen = (isset($_POST['branch_ids']) && is_array($_POST['branch_ids'])) ? array_filter($_POST['branch_ids'],'strlen') : [];
        $pdo->prepare("DELETE FROM $branchLinkTable WHERE $branchLinkProdCol=?")->execute([$id]);
        foreach ($chosen as $bidRaw){ $bid=(int)$bidRaw; if($bid>0){ $pdo->prepare("INSERT INTO $branchLinkTable ($branchLinkProdCol,$branchLinkBrCol) VALUES (?,?)")->execute([$id,$bid]); } }
      }

      header('Location: items.php?status='.$status.'&q='.urlencode($q)); exit;
    }

    if ($action==='archive'){
      $id=(int)$_POST['id'];
      if ($id){
        if ($hasProd('archived_at')) $pdo->prepare("UPDATE products SET archived_at=NOW() WHERE id=?")->execute([$id]);
        elseif ($hasProd('is_active')) $pdo->prepare("UPDATE products SET is_active=0 WHERE id=?")->execute([$id]);
      }
      flash('Archived','ok'); header('Location: items.php?status='.$status.'&q='.urlencode($q)); exit;
    }

    if ($action==='unarchive'){
      $id=(int)$_POST['id'];
      if ($id){
        if ($hasProd('archived_at')) $pdo->prepare("UPDATE products SET archived_at=NULL WHERE id=?")->execute([$id]);
        elseif ($hasProd('is_active')) $pdo->prepare("UPDATE products SET is_active=1 WHERE id=?")->execute([$id]);
      }
      flash('Restored & enabled','ok'); header('Location: items.php?status='.$status.'&q='.urlencode($q)); exit;
    }

    if ($action==='delete'){
      $id=(int)$_POST['id'];
      if ($id){ $pdo->prepare("DELETE FROM products WHERE id=?")->execute([$id]); }
      flash('Deleted','ok'); header('Location: items.php?status='.$status.'&q='.urlencode($q)); exit;
    }

  } catch (Throwable $e){
    flash('Error: '.$e->getMessage(),'err');
    header('Location: items.php?status='.$status.'&q='.urlencode($q)); exit;
  }
}

/* ---------- LIST query ---------- */
$select=[
  'p.id',
  "$nameEnExpr AS name_en",
  "$nameArExpr AS name_ar",
  "COALESCE($nameEnExpr,$nameArExpr) AS disp_en"
];
if ($hasProd('price'))       $select[]='p.price';
if ($costCol)                $select[]="p.`$costCol` AS standard_cost";
if ($hasProd('sequence'))    $select[]='p.sequence';
if ($hasProd('is_active'))   $select[]='p.is_active';
if ($hasProd('archived_at')) $select[]='p.archived_at';
if ($calCol)                 $select[]="p.`$calCol` AS calories";
if ($posVisCol)              $select[]="p.`$posVisCol` AS pos_visible";
if ($openPriceCol)           $select[]="p.`$openPriceCol` AS is_open_price";
if ($prodCompanyCol)         $select[]="p.`$prodCompanyCol` AS company_id";
if ($prodBranchCol)          $select[]="p.`$prodBranchCol` AS branch_id";

$joins=[]; $groupBits=[]; $showGroupCol=false;
if ($hasManyToMany){
  $joins[]="
    LEFT JOIN (
      SELECT $linkTable.$linkProdCol AS pid,
             GROUP_CONCAT(DISTINCT $categoryLabelExpr ORDER BY $categoryTable.$categoryIdCol SEPARATOR ', ') AS grp_names
      FROM $linkTable
      JOIN $categoryTable ON $categoryTable.$categoryIdCol = $linkTable.$linkCatCol
      GROUP BY $linkTable.$linkProdCol
    ) grp ON grp.pid = p.id";
  $joins[]="
    LEFT JOIN (
      SELECT $linkTable.$linkProdCol AS pid,
             GROUP_CONCAT(DISTINCT $linkTable.$linkCatCol ORDER BY $linkTable.$linkCatCol ASC SEPARATOR ',') AS cat_ids
      FROM $linkTable GROUP BY $linkTable.$linkProdCol
    ) catmap ON catmap.pid = p.id";
  $groupBits[]='grp.grp_names'; $select[]='catmap.cat_ids AS category_ids'; $showGroupCol=true;
}
if ($categoryTable && $hasProd('category_id')){
  $joins[]="LEFT JOIN $categoryTable lc ON lc.$categoryIdCol = p.category_id";
  $groupBits[]=str_replace("$categoryTable.","lc.",$categoryLabelExpr);
  $select[]='p.category_id AS category_id'; $showGroupCol=true;
}
if ($showGroupCol) $select[]='COALESCE('.implode(',', $groupBits).') AS item_group';

/* Branch names for VIEW */
$showBranchCol=false;
if ($branchTable && $branchIdCol && $branchLabelExpr){
  if ($branchLinkTable && $branchLinkProdCol && $branchLinkBrCol){
    $joins[]="
      LEFT JOIN (
        SELECT l.$branchLinkProdCol AS pid,
               GROUP_CONCAT(DISTINCT $branchLabelExpr ORDER BY b.$branchIdCol SEPARATOR ', ') AS br_names
        FROM $branchLinkTable l
        JOIN $branchTable b ON b.$branchIdCol = l.$branchLinkBrCol
        GROUP BY l.$branchLinkProdCol
      ) brn ON brn.pid = p.id";
    $select[]='brn.br_names AS branch_names';
    $showBranchCol=true;
  } elseif ($prodBranchCol){
    $joins[]="LEFT JOIN $branchTable b1 ON b1.$branchIdCol = p.$prodBranchCol";
    $select[]=str_replace("$branchTable.","b1.",$branchLabelExpr)." AS branch_name";
    $showBranchCol=true;
  }
}

$sql="SELECT ".implode(',', $select)." FROM products p ".implode(' ', $joins);
$where=[]; $args=[];
if ($q!==''){ $where[]="(COALESCE($nameEnExpr,'') LIKE ? OR COALESCE($nameArExpr,'') LIKE ?)"; $args[]='%'.$q.'%'; $args[]='%'.$q.'%'; }
if ($status==='active'){ if ($hasProd('archived_at')) $where[]="p.archived_at IS NULL"; elseif ($hasProd('is_active')) $where[]="p.is_active=1"; }
elseif ($status==='archived'){ if ($hasProd('archived_at')) $where[]="p.archived_at IS NOT NULL"; elseif ($hasProd('is_active')) $where[]="p.is_active=0"; }
if ($where) $sql.=" WHERE ".implode(' AND ',$where);
$order=[]; if ($hasProd('archived_at')) $order[]="(p.archived_at IS NOT NULL)"; if ($hasProd('sequence')) $order[]="p.sequence ASC"; $order[]="disp_en ASC";
$sql.=" ORDER BY ".implode(', ',$order);

$st=$pdo->prepare($sql); $st->execute($args); $rows=$st->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Edit context ---------- */
$edit=null; $selectedCatIds=[]; $selectedCompanyId=null; $selectedBranchIds=[];
if (isset($_GET['edit'])){
  $eid=(int)$_GET['edit'];
  if ($eid>0){
    $s=$pdo->prepare("SELECT * FROM products WHERE id=?"); $s->execute([$eid]); $edit=$s->fetch(PDO::FETCH_ASSOC) ?: ['id'=>0];

    if ($linkTable && $linkProdCol && $linkCatCol){
      $q1=$pdo->prepare("SELECT $linkCatCol FROM $linkTable WHERE $linkProdCol=? ORDER BY $linkCatCol ASC");
      $q1->execute([$eid]); $selectedCatIds=array_map('intval',$q1->fetchAll(PDO::FETCH_COLUMN,0));
    } elseif ($hasProd('category_id')) { $cid=$edit['category_id'] ?? null; if ($cid) $selectedCatIds=[(int)$cid]; }

    if ($prodCompanyCol) { $selectedCompanyId = $edit[$prodCompanyCol] ?? null; }

    if ($branchLinkTable){
      $q3=$pdo->prepare("SELECT $branchLinkBrCol FROM $branchLinkTable WHERE $branchLinkProdCol=? ORDER BY $branchLinkBrCol ASC");
      $q3->execute([$eid]); $selectedBranchIds=array_map('intval',$q3->fetchAll(PDO::FETCH_COLUMN,0));
    } elseif ($prodBranchCol) {
      if (!empty($edit[$prodBranchCol])) $selectedBranchIds=[(int)$edit[$prodBranchCol]];
    }
  } else { $edit=['id'=>0]; }
}

/* ---------- Header ---------- */
$current='items';
require_once __DIR__ . '/../_header.php';
?>

<div class="container full shell" style="max-width:1160px; margin:0 auto; padding:0 16px;">

  <?php foreach (flashes() as $f): ?>
    <div class="card" style="margin-bottom:12px;border-left:4px solid <?= $f['t']==='err'?'#ef4444':'#16a34a' ?>"><?= e($f['m']) ?></div>
  <?php endforeach; ?>

  <!-- Add/Edit -->
  <div class="card" id="itemForm" style="display:none; margin-bottom:16px;">
    <div class="toolbar" style="min-height:18px; visibility:hidden;">&nbsp;</div>

    <form method="post" id="itemFormEl" class="edit-form" style="max-width:1080px; margin:0 auto;"
          data-default-company-id="<?= $defaultCompanyId ? (int)$defaultCompanyId : '' ?>"
          data-default-branch-id="<?= $defaultBranchId ? (int)$defaultBranchId : '' ?>"
          data-has-cost="<?= $costCol ? '1':'0' ?>"
          data-has-branch-link="<?= $branchLinkTable ? '1':'0' ?>">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="<?= ($edit && (int)($edit['id'] ?? 0)>0) ? 'update' : 'create' ?>">
      <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

      <!-- Row 1: Names -->
      <div class="form-row row-2">
        <label class="field">
          <span class="flabel">Item Name (EN)</span>
          <input class="input" type="text" name="name_en" value="<?= e($edit['prod_name_en'] ?? $edit['name_en'] ?? $edit['name'] ?? '') ?>">
        </label>
        <label class="field">
          <span class="flabel">Item Name (AR)</span>
          <input class="input" dir="rtl" style="text-align:center" type="text" name="name_ar" value="<?= e($edit['prod_name_ar'] ?? $edit['name_ar'] ?? '') ?>">
        </label>
      </div>

      <!-- Row 2: Category (multi-looking-like-single) + Price -->
      <div class="form-row row-2">
        <?php if ($categoryTable): ?>
        <label class="field">
          <span class="flabel">Category</span>
          <select class="input select-like-single" name="category_ids[]" id="categorySel" multiple size="1">
            <?php
              $selSet = array_flip($selectedCatIds ?: []);
              foreach ($categories as $c){ $cid=(int)$c['id']; $lab=(string)$c['label']; $sel = isset($selSet[$cid]) ? 'selected' : ''; ?>
                <option value="<?= $cid ?>" <?= $sel ?>><?= e($lab) ?></option>
            <?php } ?>
          </select>
        </label>
        <?php endif; ?>
        <?php if ($hasProd('price')): ?>
        <label class="field">
          <span class="flabel">Price</span>
          <input class="input" type="number" step="0.01" name="price" value="<?= e($edit['price'] ?? '') ?>" placeholder="0.00">
        </label>
        <?php endif; ?>
      </div>

      <!-- Row 3: Standard Cost + Calories -->
      <div class="form-row row-2">
        <?php if ($costCol): ?>
        <label class="field">
          <span class="flabel">Standard Cost</span>
          <input class="input" type="number" step="0.01" name="standard_cost" value="<?= e($edit[$costCol] ?? '') ?>" placeholder="0.00">
        </label>
        <?php endif; ?>
        <?php if ($calCol): ?>
        <label class="field">
          <span class="flabel">Calories</span>
          <input class="input" type="number" step="1" name="calories" value="<?= e($edit[$calCol] ?? 0) ?>">
        </label>
        <?php endif; ?>
      </div>

      <!-- Row 4: Company + Branches -->
      <div class="form-row row-2">
        <label class="field">
          <span class="flabel">Company</span>
          <select class="input" name="company_id" id="companySel">
            <option value="">— None —</option>
            <?php foreach ($companies as $co): $coid=(int)$co['id']; $lab=(string)($co['label'] ?? ("Company #".$coid)); ?>
              <option value="<?= $coid ?>" <?= (
                (isset($edit[$prodCompanyCol]) && (int)$edit[$prodCompanyCol]===$coid) ||
                ($selectedCompanyId!==null && (int)$selectedCompanyId===$coid) ||
                ((int)($edit['id'] ?? 0)===0 && $defaultCompanyId && (int)$defaultCompanyId===$coid)
              ) ? 'selected' : '' ?>><?= e($lab) ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <?php if ($branchLinkTable): ?>
        <label class="field">
          <span class="flabel">Branches</span>
          <select class="input select-like-single" name="branch_ids[]" id="branchesSel" multiple size="1">
            <?php $selSet = array_flip(array_map('intval',$selectedBranchIds?:[]));
              foreach ($branches as $br){ $brid=(int)$br['id']; $sel = isset($selSet[$brid]) ? 'selected' : ''; ?>
              <option value="<?= $brid ?>" <?= $sel ?>><?= e($br['label']) ?></option>
            <?php } ?>
          </select>
        </label>
        <?php elseif ($prodBranchCol): ?>
        <label class="field">
          <span class="flabel">Branch</span>
          <select class="input" name="branch_id" id="branchSel">
            <option value="">— None —</option>
            <?php foreach ($branches as $br): $brid=(int)$br['id']; ?>
              <option value="<?= $brid ?>" <?= (
                (!empty($selectedBranchIds) && (int)$selectedBranchIds[0]===$brid) ||
                ((int)($edit['id'] ?? 0)===0 && $defaultBranchId && (int)$defaultBranchId===$brid)
              ) ? 'selected' : '' ?>><?= e($br['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php endif; ?>
      </div>

      <!-- Row 5: Toggles -->
      <div class="form-row toggles-row" style="grid-column:1/-1;">
        <?php if ($openPriceCol): ?>
        <label class="switch">
          <input id="isOpenPrice" type="checkbox" name="is_open_price" <?= ((int)($edit[$openPriceCol] ?? 0) ? 'checked' : '') ?>>
          <span>Open Price</span>
        </label>
        <?php endif; ?>
        <?php if ($posVisCol): ?>
        <label class="switch">
          <input id="posVisible" type="checkbox" name="pos_visible" <?= ((int)($edit[$posVisCol] ?? 1) ? 'checked' : '') ?>>
          <span>POS Visible</span>
        </label>
        <?php endif; ?>
        <?php if ($hasProd('is_active')): ?>
        <label class="switch">
          <input id="isActive" type="checkbox" name="is_active" <?= ((int)($edit['is_active'] ?? 1) ? 'checked' : '') ?>>
          <span>Active</span>
        </label>
        <?php endif; ?>
      </div>

      <div class="form-actions">
        <button class="btn sm primary" type="submit" style="min-width:96px">Save</button>
        <a class="btn sm ghost" href="items.php" id="cancelBtn" style="min-width:96px;text-align:center">Cancel</a>
      </div>
    </form>
  </div>

  <!-- LIST -->
  <div class="card" id="itemList">
    <div class="toolbar toolbar-filters" style="display:flex; align-items:center; flex-wrap:nowrap; gap:12px; margin-top:22px;">
      <form method="get" id="filterForm" class="filterbar clean" style="display:flex; align-items:center; gap:12px; flex:1 1 auto;">
        <div class="filter-group">
          <span class="filter-label">Status</span>
          <select id="statusSel" name="status" class="input select">
            <option value="active"   <?= $status==='active'?'selected':'' ?>>Show Active</option>
            <option value="archived" <?= $status==='archived'?'selected':'' ?>>Show Archived</option>
            <option value="all"      <?= $status==='all'?'selected':'' ?>>Show All</option>
          </select>
        </div>
        <div class="filter-group search-group" style="margin-left:14px;">
          <span class="filter-label">Search</span>
          <div class="search-wrap">
            <input id="qInp" name="q" class="input search" type="text" value="<?= e($q) ?>" placeholder="Search...">
            <button type="button" class="clear-x" aria-label="Clear" title="Clear"
              onclick="const i=this.previousElementSibling; i.value=''; i.dispatchEvent(new Event('input')); i.focus();">×</button>
          </div>
        </div>
      </form>
      <a class="btn sm primary new-btn" href="items.php?edit=0" id="newBtn">+ New</a>
    </div>

    <div style="height:14px"></div>

    <div class="table-wrap center">
      <table class="table">
        <colgroup>
          <col style="width:80px"><!-- ID -->
          <col class="col-name">
          <?php if ($showGroupCol): ?><col class="col-group"><?php endif; ?>
          <?php if ($showBranchCol): ?><col class="col-branch"><?php endif; ?>
          <?php if ($hasProd('price')): ?><col class="col-price"><?php endif; ?>
          <?php if ($costCol): ?><col class="col-stdcost"><?php endif; ?>
          <?php if ($hasProd('sequence')): ?><col style="width:100px"><?php endif; ?>
          <?php if ($hasProd('archived_at') || $hasProd('is_active')): ?><col class="col-status"><?php endif; ?>
          <col class="col-actions">
        </colgroup>
        <thead>
          <tr>
            <th>ID</th>
            <th>Name</th>
            <?php if ($showGroupCol): ?><th>Category</th><?php endif; ?>
            <?php if ($showBranchCol): ?><th>Branch</th><?php endif; ?>
            <?php if ($hasProd('price')): ?><th>Price</th><?php endif; ?>
            <?php if ($costCol): ?><th class="th-stdcost">Standard Cost</th><?php endif; ?>
            <?php if ($hasProd('sequence')): ?><th>Seq</th><?php endif; ?>
            <?php if ($hasProd('archived_at') || $hasProd('is_active')): ?><th>Status</th><?php endif; ?>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td style="text-align:center"><?= (int)$r['id'] ?></td>
            <td style="text-align:center">
              <div class="name-stack">
                <div class="en"><?= e($r['name_en'] ?? $r['disp_en'] ?? '') ?></div>
                <?php if (!empty($r['name_ar'])): ?>
                  <div class="ar" dir="rtl"><?= e($r['name_ar']) ?></div>
                <?php endif; ?>
              </div>
            </td>
            <?php if ($showGroupCol): ?>
              <td style="text-align:center"><?= e($r['item_group'] ?? '') ?></td>
            <?php endif; ?>
            <?php if ($showBranchCol): ?>
              <td style="text-align:center"><?= e($r['branch_names'] ?? $r['branch_name'] ?? '') ?></td>
            <?php endif; ?>
            <?php if ($hasProd('price')): ?>
              <td style="text-align:center"><?= number_format((float)($r['price'] ?? 0), 2) ?></td>
            <?php endif; ?>
            <?php if ($costCol): ?>
              <td class="td-stdcost" style="text-align:center"><?= number_format((float)($r['standard_cost'] ?? 0), 2) ?></td>
            <?php endif; ?>
            <?php if ($hasProd('sequence')): ?>
              <td style="text-align:center"><?= (int)($r['sequence'] ?? 0) ?></td>
            <?php endif; ?>
            <?php if ($hasProd('archived_at') || $hasProd('is_active')): ?>
              <td style="text-align:center">
                <?php
                  $arch = $r['archived_at'] ?? null;
                  $act  = isset($r['is_active']) ? (int)$r['is_active'] : 1;
                  if ($hasProd('archived_at') && $arch)      echo '<span class="badge">Archived</span>';
                  elseif ($hasProd('is_active') && !$act)    echo '<span class="badge">Disabled</span>';
                  else                                       echo '<span class="badge blue">Active</span>';
                ?>
              </td>
            <?php endif; ?>
            <td style="text-align:center">
              <div class="actions">
                <?php if (!($hasProd('archived_at') && ($r['archived_at'] ?? null)) && !($hasProd('is_active') && isset($r['is_active']) && (int)$r['is_active']===0)): ?>
                  <form method="post">
                    <input type="hidden" name="csrf" value="<?= csrf() ?>">
                    <input type="hidden" name="action" value="archive">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn sm" type="submit" onclick="return confirm('Archive this item? It will be hidden until restored.');">Archive</button>
                  </form>
                <?php else: ?>
                  <form method="post">
                    <input type="hidden" name="csrf" value="<?= csrf() ?>">
                    <input type="hidden" name="action" value="unarchive">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn sm" type="submit">Restore</button>
                  </form>
                <?php endif; ?>

                <!-- EDIT dataset passes EN/AR, categories (CSV), company, branches, calories -->
                <a
                  href="#"
                  class="btn sm ghost edit-btn"
                  data-id="<?= (int)$r['id'] ?>"
                  data-name-en="<?= e($r['name_en'] ?? '') ?>"
                  data-name-ar="<?= e($r['name_ar'] ?? '') ?>"
                  <?php if (!empty($r['category_ids'])): ?>data-category-ids="<?= e($r['category_ids']) ?>"<?php endif; ?>
                  data-price="<?= e($r['price'] ?? '') ?>"
                  data-standard-cost="<?= e($r['standard_cost'] ?? '') ?>"
                  data-sequence="<?= e($r['sequence'] ?? 0) ?>"
                  data-is-active="<?= isset($r['is_active']) ? (int)$r['is_active'] : 1 ?>"
                  <?php if ($calCol): ?>data-calories="<?= e($r['calories'] ?? 0) ?>"<?php endif; ?>
                  <?php if ($posVisCol): ?>data-pos-visible="<?= isset($r['pos_visible']) ? (int)$r['pos_visible'] : 1 ?>"<?php endif; ?>
                  <?php if ($openPriceCol): ?>data-open-price="<?= isset($r['is_open_price']) ? (int)$r['is_open_price'] : 0 ?>"<?php endif; ?>
                  <?php if ($prodCompanyCol): ?>data-company-id="<?= e($r['company_id'] ?? '') ?>"<?php endif; ?>
                  <?php if ($branchLinkTable): ?>data-branch-ids="<?= e($r['branch_ids'] ?? '') ?>"<?php elseif ($prodBranchCol): ?>data-branch-id="<?= e($r['branch_id'] ?? '') ?>"<?php endif; ?>
                >Edit</a>

                <form method="post" onsubmit="return confirm('Permanently delete this item?')">
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
  const url = new URL(window.location.href);
  const hasEdit = url.searchParams.has('edit');
  const formCard = document.getElementById('itemForm');
  const formEl = document.getElementById('itemFormEl');
  const listCard = document.getElementById('itemList');
  const newBtn = document.getElementById('newBtn');
  const cancelBtn = document.getElementById('cancelBtn');

  function showForm(){ if(formCard) formCard.style.display='block'; if(listCard) listCard.style.display='none'; }
  function hideForm(){ if(formCard) formCard.style.display='none';  if(listCard) listCard.style.display='block'; }
  if (hasEdit) { showForm(); } else { hideForm(); }

  // Open Price: clear & disable on enable; re-enable on disable
  function syncOpenPriceUI(){
    if (!formEl) return;
    const op = formEl.querySelector('input[name="is_open_price"]');
    const price = formEl.querySelector('input[name="price"]');
    const cost  = formEl.querySelector('input[name="standard_cost"]');
    const hasCost = formEl.getAttribute('data-has-cost') === '1';
    if (!op) return;
    if (op.checked){
      if (price){ price.value = ''; price.setAttribute('disabled','disabled'); }
      if (hasCost && cost){ cost.value = ''; cost.setAttribute('disabled','disabled'); }
    } else {
      if (price){ price.removeAttribute('disabled'); }
      if (hasCost && cost){ cost.removeAttribute('disabled'); }
    }
  }

  function setDefaultCompanyBranch(){
    if (!formEl) return;
    const defCo = formEl.getAttribute('data-default-company-id') || '';
    const defBr = formEl.getAttribute('data-default-branch-id') || '';
    const coSel = formEl.querySelector('select[name="company_id"]');
    const hasBranchLink = formEl.getAttribute('data-has-branch-link') === '1';
    const brMulti = formEl.querySelector('select[name="branch_ids[]"]');
    const brSingle= formEl.querySelector('select[name="branch_id"]');
    if (coSel && defCo && (url.searchParams.get('edit')==='0')) coSel.value = defCo;
    if (hasBranchLink && brMulti && defBr && (url.searchParams.get('edit')==='0')){
      Array.from(brMulti.options).forEach(opt => { opt.selected = (String(opt.value) === String(defBr)); });
    } else if (!hasBranchLink && brSingle && defBr && (url.searchParams.get('edit')==='0')){
      brSingle.value = defBr;
    }
  }

  if (newBtn) newBtn.addEventListener('click', function(e){
    e.preventDefault();
    url.searchParams.set('edit','0'); history.replaceState(null,'', url.toString());
    if (formEl){
      formEl.reset();
      setDefaultCompanyBranch();
      ['is_active','pos_visible'].forEach(n=>{
        const chk=formEl.querySelector(`input[name="${n}"]`); if (chk) chk.checked=true;
      });
      const op=formEl.querySelector('input[name="is_open_price"]'); if (op) { op.checked=false; }
      ['standard_cost','price','calories','sequence'].forEach(n=>{
        const el=formEl.querySelector(`[name="${n}"]`); if (el) el.value = (n==='calories'?'0':'');
      });
      const cat=formEl.querySelector('select[name="category_ids[]"]'); if (cat) Array.from(cat.options).forEach(o=>o.selected=false);
      const actionEl=formEl.querySelector('input[name="action"]'); if (actionEl) actionEl.value='create';
      const idfld=formEl.querySelector('input[name="id"]'); if (idfld) idfld.value=0;
    }
    showForm();
    syncOpenPriceUI();
  });

  if (cancelBtn) cancelBtn.addEventListener('click', function(e){
    e.preventDefault();
    url.searchParams.delete('edit'); history.replaceState(null,'', url.toString());
    hideForm();
  });

  // Prefill via Edit button
  document.querySelectorAll('.edit-btn').forEach(function(btn){
    btn.addEventListener('click', function(e){
      e.preventDefault();
      if (!formEl) return;
      const d = this.dataset;

      const en  = formEl.querySelector('input[name="name_en"]');
      const ar  = formEl.querySelector('input[name="name_ar"]');
      const catMulti = formEl.querySelector('select[name="category_ids[]"]');
      const pr  = formEl.querySelector('input[name="price"]');
      const sc  = formEl.querySelector('input[name="standard_cost"]');
      const seq = formEl.querySelector('input[name="sequence"]');
      const act = formEl.querySelector('input[name="is_active"]');
      const pos = formEl.querySelector('input[name="pos_visible"]');
      const opn = formEl.querySelector('input[name="is_open_price"]');
      const cal = formEl.querySelector('input[name="calories"]');
      const comp= formEl.querySelector('select[name="company_id"]');
      const brMulti = formEl.querySelector('select[name="branch_ids[]"]');
      const brSingle= formEl.querySelector('select[name="branch_id"]');
      const idf = formEl.querySelector('input[name="id"]');
      const actn= formEl.querySelector('input[name="action"]');

      if (en)  en.value  = d.nameEn || '';
      if (ar)  ar.value  = d.nameAr || '';

      if (catMulti){
        Array.from(catMulti.options).forEach(o=>o.selected=false);
        if (typeof d.categoryIds !== 'undefined' && d.categoryIds){
          const ids = String(d.categoryIds).split(',').map(s=>s.trim()).filter(Boolean);
          Array.from(catMulti.options).forEach(opt => { opt.selected = ids.includes(String(opt.value)); });
        }
      }

      if (pr) pr.value = (typeof d.price !== 'undefined' && d.price !== '') ? d.price : '';
      if (sc) sc.value = (typeof d.standardCost !== 'undefined' && d.standardCost !== '') ? d.standardCost : '';
      if (seq) seq.value = d.sequence || 0;
      if (act && typeof d.isActive !== 'undefined')   act.checked = (d.isActive === '1' || d.isActive === 1);
      if (pos && typeof d.posVisible !== 'undefined') pos.checked = (d.posVisible === '1' || d.posVisible === 1);
      if (opn && typeof d.openPrice !== 'undefined')  opn.checked = (d.openPrice === '1' || d.openPrice === 1);
      if (cal && typeof d.calories !== 'undefined')   cal.value = d.calories || 0;
      if (comp && typeof d.companyId !== 'undefined') comp.value = d.companyId || '';

      if (brMulti && typeof d.branchIds !== 'undefined'){
        const ids = (d.branchIds||'').split(',').map(s=>s.trim()).filter(Boolean);
        Array.from(brMulti.options).forEach(opt => { opt.selected = ids.includes(String(opt.value)); });
      } else if (brSingle && typeof d.branchId !== 'undefined'){
        brSingle.value = d.branchId || '';
      }

      if (idf) idf.value = d.id || 0;
      if (actn) actn.value = 'update';

      url.searchParams.set('edit', d.id || '0');
      history.replaceState(null,'', url.toString());
      showForm();
      syncOpenPriceUI();
    });
  });

  // React to Open Price toggle immediately
  if (formEl){
    const op = formEl.querySelector('input[name="is_open_price"]');
    if (op) op.addEventListener('change', syncOpenPriceUI);
    // Initial state
    syncOpenPriceUI();
  }

  // Auto-filter
  const filterForm = document.getElementById('filterForm');
  if (filterForm) {
    const statusSel = filterForm.querySelector('select[name="status"]');
    const searchInp = filterForm.querySelector('input[name="q"]');

    if (statusSel) statusSel.addEventListener('change', () => filterForm.submit());

    if (searchInp) {
      let t=null; const submitNow = () => filterForm.submit();
      searchInp.addEventListener('keydown', (ev)=>{ if (ev.key==='Enter') { ev.preventDefault(); submitNow(); }});
      searchInp.addEventListener('input', ()=>{ if (t) clearTimeout(t); t=setTimeout(submitNow, 350); });
    }
  }
})();
</script>

<!-- Layout-only tweaks -->
<style>
  /* Filters */
  .filter-group { display:flex; align-items:center; gap:8px; white-space:nowrap; }
  .filter-label { font-size:13px; opacity:.9; }
  .filterbar.clean .input,
  .edit-form .input { height:34px !important; line-height:32px !important; border-radius:8px !important; padding:0 12px !important; border-width:1px !important; }
  .filterbar.clean .select { min-width:160px !important; }
  .search-wrap { position:relative; display:inline-flex; align-items:center; }
  .search-wrap .search { width:200px !important; border-radius:8px !important; padding-left:12px !important; }
  .search-wrap .clear-x { position:absolute; right:8px; top:50%; transform:translateY(-50%); border:none; background:transparent; font-size:18px; line-height:1; opacity:.55; cursor:pointer; height:20px; width:20px; }
  .search-wrap .clear-x:hover { opacity:.9; }
  .toolbar-filters { margin-top:22px !important; overflow-x:auto; }
  .filterbar.clean { flex:1 1 auto; }

  /* +New: narrower width, a little taller */
  .new-btn { margin-left:auto; height:38px !important; padding:0 10px !important; border-radius:16px !important; min-width:76px !important; }

  /* Table alignment + spacing */
  .table { width:100%; }
  .table thead th { text-align:center !important; }
  .table tbody td { text-align:center !important; vertical-align:middle !important; }
  .table th, .table td { padding:10px 14px !important; } /* more breathing space */

  /* Column widths */
  .col-name  { width: 36% !important; min-width: 300px !important; }
  .col-group { width: 18% !important; min-width: 200px !important; }
  .col-branch{ width: 16% !important; min-width: 180px !important; }
  .col-price   { width: 160px !important; }
  .col-stdcost { width: 200px !important; }
  .col-status  { width: 160px !important; }
  .th-stdcost, .td-stdcost { white-space: nowrap !important; }
  .name-stack .en { font-weight:500; }
  .name-stack .ar { font-size:12px; color:#6b7280; margin-top:2px; }

  .table .col-actions { min-width: 380px !important; }
  .actions { display:flex !important; gap:10px !important; flex-wrap:nowrap !important; white-space:nowrap !important; justify-content:center !important; }
  .actions form { display:inline-flex !important; width:auto !important; margin:0 !important; }
  .btn.sm, .btn.sm.ghost { font-size:13px !important; line-height:32px !important; font-family:inherit !important; height:34px !important; }

  /* Edit form rows (TWO per row) */
  .edit-form .form-actions { display:flex; gap:8px; justify-content:center; margin-top:12px; }
  .edit-form .form-row { display:grid; gap:16px; margin-bottom:16px; }
  .edit-form .row-2 { grid-template-columns: repeat(2, minmax(260px, 1fr)); }
  .edit-form .field { display:flex; align-items:center; gap:10px; }
  .edit-form .flabel { font-size:13px; min-width:120px; text-align:left; opacity:.9; }
  .edit-form .field .input { flex:1 1 auto; }

  /* Make multi-select look like single-select height/shape */
  select.select-like-single[multiple] {
    height:34px !important;
    overflow:hidden !important;
    background-repeat:no-repeat !important;
    background-position:right 10px center !important;
  }
  /* Optional hint cursor */
  select[multiple] { cursor:pointer; }

  /* Toggles row (one line, centered) */
  .toggles-row { display:flex !important; justify-content:center !important; align-items:center !important; gap:22px !important; margin-top:4px; }
  .edit-form .switch { display:flex; align-items:center; gap:10px; }
  .edit-form .switch input[type="checkbox"]{
    appearance:none; width:44px; height:24px; border-radius:9999px; position:relative; outline:0; border:1px solid currentColor; opacity:.8;
  }
  .edit-form .switch input[type="checkbox"]::after{
    content:''; position:absolute; top:2px; left:2px; width:20px; height:20px; border-radius:50%;
    background: currentColor; opacity:.45; transform:translateX(0); transition: transform .18s ease, opacity .18s ease, background-color .18s ease;
  }
  .edit-form .switch input[type="checkbox"]:checked::after{
    transform:translateX(20px);
    opacity:1;
    background-color:#16a34a; /* green thumb when ON */
  }

  /* Multi-select minimum heights (when not styled as single) */
  #branchesSel, #categorySel { min-height:34px; }

  @media (max-width: 980px){
    .edit-form .row-2 { grid-template-columns: 1fr; }
    .edit-form .flabel { text-align:left; min-width:auto; }
  }
</style>