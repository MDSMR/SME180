<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../config/admin_auth.php';
admin_require_auth();

// ... your existing Users page logic (queries, forms, etc.) ...
$embedded = !empty($_GET['embed']);
?>
<?php if (!$embedded): ?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><title>Users</title>
<link rel="stylesheet" href="/assets/css/admin.css"></head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="container">
<?php endif; ?>

<main style="padding:10px;max-width:980px;margin:0 auto;font-family:system-ui">
  <!-- Heading removed -->
  <!-- Keep your users table/form here -->
</main>

<?php if (!$embedded): ?>
</div>
</body></html>
<?php endif; ?>