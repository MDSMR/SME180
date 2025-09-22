<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2); // Points to /public_html
$adminPath = $root . '/views/admin';

// List of missing files based on your scan
$missingFiles = [
    'orders.php',
    'menu.php',
    'edit_user.php',
    'toggle_user_status.php',
];

// Create each file if it doesn't exist
foreach ($missingFiles as $file) {
    $fullPath = $adminPath . '/' . $file;

    if (file_exists($fullPath)) {
        echo "<p>✅ Already exists: $file</p>";
        continue;
    }

    $label = strtoupper(str_replace('.php', '', $file));
    $content = <<<PHP
<?php
// TODO: Implement logic for $file
echo "🔧 Placeholder for $label page.";
?>
PHP;

    file_put_contents($fullPath, $content);
    echo "<p style='color:green;'>🆕 Created: $file</p>";
}
?>