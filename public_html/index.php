<?php
// Minimal front controller
http_response_code(302);
header('Location: /admin/login.php');
exit;
