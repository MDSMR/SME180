<?php
declare(strict_types=1); // ✅ Must be the first line

// Enable error reporting and log to file
ini_set('display_errors', '0'); // Hide errors from browser
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/error_log.txt');
error_reporting(E_ALL);

// Define paths
$root = dirname(__DIR__, 2); // Points to /public_html
$scanPath = $root . '/views/admin'; // ✅ Corrected path

echo "<p>✅ Script started</p>";
echo "<p>Scan path: $scanPath</p>";

$pattern = '/(?:href|src|action)\s*=\s*[\'"]([^\'"]+)[\'"]/i';
$missing = [];

function isLocalPath(string $path): bool {
    return !preg_match('#^(https?:)?//#', $path) && strpos($path, 'mailto:') !== 0;
}

function resolvePath(string $path, string $base): string {
    $clean = preg_replace('#\?.*$#', '', $path); // Remove query strings
    return $base . '/' . ltrim($clean, '/');
}

// Confirm scan path exists
if (!is_dir($scanPath)) {
    echo "<p style='color:red;'>❌ Directory not found: $scanPath</p>";
    exit;
}

// Try scanning files
try {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scanPath));
    foreach ($rii as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') continue;

        $contents = file_get_contents($file->getPathname());
        if (preg_match_all($pattern, $contents, $matches)) {
            foreach ($matches[1] as $link) {
                if (!isLocalPath($link)) continue;

                $target = resolvePath($link, $root);
                if (!file_exists($target)) {
                    $missing[] = [
                        'file' => str_replace($root, '', $file->getPathname()),
                        'link' => $link,
                        'resolved' => str_replace($root, '', $target)
                    ];
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log("❌ Error scanning files: " . $e->getMessage());
    echo "<p style='color:red;'>❌ Error occurred — check error_log.txt</p>";
    exit;
}

// Output results
echo "<h2>🔍 Broken Link Report</h2>";
if (empty($missing)) {
    echo "<p style='color:green;'>✅ No broken links found.</p>";
} else {
    echo "<table border='1' cellpadding='6' style='border-collapse:collapse;'>";
    echo "<tr><th>File</th><th>Broken Link</th><th>Resolved Path</th></tr>";
    foreach ($missing as $m) {
        echo "<tr><td>{$m['file']}</td><td>{$m['link']}</td><td>{$m['resolved']}</td></tr>";
    }
    echo "</table>";
}
?>