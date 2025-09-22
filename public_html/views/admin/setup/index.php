<?php
declare(strict_types=1);
$current='setup';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../_header.php';
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup</title>
<style>
  body{margin:0;font-family:system-ui,Segoe UI,Arial,sans-serif}
  main{max-width:900px;margin:0 auto;padding:24px}
  .cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px}
  .card{border:1px solid #eee;border-radius:12px;padding:16px}
  .card a{display:block;text-decoration:none;color:#111;font-weight:600;margin-bottom:6px}
  .muted{color:#666;font-size:13px}
</style>
<main>
  <h2>Setup</h2>
  <div class="cards">
    <div class="card"><a href="/views/admin/setup/company.php">Company</a><div class="muted">Company profile</div></div>
    <div class="card"><a href="/views/admin/setup/branches.php">Branches</a><div class="muted">Create multiple branches per company</div></div>
    <div class="card"><a href="/views/admin/setup/printers.php">Printers</a><div class="muted">Routing & kitchen printers</div></div>
    <div class="card"><a href="/views/admin/setup/users.php">Users</a><div class="muted">Access control</div></div>
    <div class="card"><a href="/views/admin/setup/tax.php">Tax</a><div class="muted">VAT settings</div></div>
    <div class="card"><a href="/views/admin/setup/service_charge.php">Service Charge</a><div class="muted">Service %</div></div>
    <div class="card"><a href="/views/admin/setup/aggregators.php">Aggregators</a><div class="muted">Talabat/Jahez/etc.</div></div>
  </div>
</main>