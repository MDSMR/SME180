<?php
/**
 * Simple File List - No styling, just paths
 * Path: /list_all_files.php
 */

// Error reporting (comment these out after testing)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Increase limits to prevent timeout
set_time_limit(300); // 5 minutes
ini_set('memory_limit', '256M');

// Start output
echo "<pre>"; // For browser viewing
echo "PROJECT FILES - " . date('Y-m-d H:i:s') . "\n";
echo "Base Path: " . __DIR__ . "\n";
echo str_repeat("=", 80) . "\n\n";

$baseDir = __DIR__;
$excludeDirs = ['.git', 'node_modules', 'vendor'];

function listFiles($dir, $prefix = '') {
    global $excludeDirs, $baseDir;
    
    try {
        $files = scandir($dir);
        
        foreach ($files as $file) {
            if ($file == '.' || $file == '..') continue;
            
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            $relativePath = str_replace($baseDir . DIRECTORY_SEPARATOR, '', $path);
            // Convert backslashes to forward slashes for consistency
            $relativePath = str_replace('\\', '/', $relativePath);
            
            if (is_dir($path)) {
                if (!in_array($file, $excludeDirs)) {
                    echo $relativePath . "/\n";
                    listFiles($path, $prefix . '  ');
                }
            } else {
                echo $relativePath . "\n";
            }
        }
    } catch (Exception $e) {
        echo "Error reading: " . $dir . "\n";
    }
}

// Start listing
listFiles($baseDir);

echo "</pre>";
?>