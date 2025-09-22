<?php
/**
 * Variation page content (used by variations.php)
 * Cleaned for the new stack: no legacy auth_check, no old wrappers, use db()
 */
require_once __DIR__ . '/../../../config/db.php';
$pdo = db();

/* ---- your original attributes/variation page code goes here, unchanged in logic ----
   Keep all queries/UI. Make sure you are using $pdo (from db()) for DB calls.
   Remove any include of _nav.php or auth_check.php and any <!doctype html> wrappers.
*/