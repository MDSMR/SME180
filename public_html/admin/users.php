<?php
// admin/users.php - Users admin UI (touch friendly)
require_once __DIR__ . '/../lib/request.php';
require_once __DIR__ . '/../config/db.php';
?><!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Users - Admin</title>
<link rel="stylesheet" href="/admin/users.css">
</head>
<body>
  <div class="wrap">
    <header><h1>Users</h1><button id="newUserBtn">New User</button></header>
    <main>
      <input id="search" placeholder="Search users..." />
      <div id="usersList" class="usersList"></div>
    </main>
  </div>

  <div id="userModal" class="modal" style="display:none">
    <div class="modalInner">
      <h2 id="modalTitle">New User</h2>
      <form id="userForm">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <label>Username<input name="username"></label>
        <label>Full name<input name="full_name"></label>
        <label>Password<input type="password" name="password"></label>
        <label>Role<select name="role_id"><option value="1">Admin</option><option value="2">Cashier</option></select></label>
        <div class="actions"><button type="button" id="saveUserBtn">Save</button><button type="button" id="cancelUserBtn">Cancel</button></div>
      </form>
    </div>
  </div>

<script src="/admin/users.js"></script>
</body>
</html>
