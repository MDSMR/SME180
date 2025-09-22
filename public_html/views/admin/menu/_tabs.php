<?php
$base = basename($_SERVER['PHP_SELF'] ?? '');
function tab($href, $label, $active) {
  $cls = 'tab'.($active ? ' active' : '');
  echo '<a class="'.$cls.'" href="'.$href.'">'.$label.'</a>';
}
?>
<div class="tabs">
  <?php
    tab('/views/admin/menu/categories.php', 'Categories', $base === 'categories.php');
    tab('/views/admin/menu/items.php',      'Items',      $base === 'items.php');
    tab('/views/admin/menu/attributes.php', 'Variations', $base === 'attributes.php' || $base === 'variations.php');
    tab('/views/admin/menu/printers.php',   'Printers',   $base === 'printers.php');
  ?>
</div>