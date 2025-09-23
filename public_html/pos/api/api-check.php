<?php
/**
 * SME 180 POS - API Diagnostic Tool
 * File: /public_html/pos/api-check.php
 * 
 * This file helps diagnose API endpoint issues
 */

header('Content-Type: text/html; charset=utf-8');

// Get the current directory structure
$currentDir = __DIR__;
$parentDir = dirname(__DIR__);
$configPath = dirname(__DIR__, 2) . '/config/db.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>POS API Diagnostic</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; }
        .section { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .warning { color: orange; }
        .path { background: #f0f0f0; padding: 5px; margin: 5px 0; border-left: 3px solid #007bff; }
        pre { background: #f8f8f8; padding: 10px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f0f0f0; }
        .test-btn { padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; margin: 5px; }
        .test-btn:hover { background: #0056b3; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔍 SME 180 POS - API Diagnostic Tool</h1>
    
    <div class="section">
        <h2>1. Current Directory Structure</h2>
        <div class="path">Current Script: <?php echo __FILE__; ?></div>
        <div class="path">Current Directory: <?php echo $currentDir; ?></div>
        <div class="path">Parent Directory: <?php echo $parentDir; ?></div>
        <div class="path">Expected Config Path: <?php echo $configPath; ?></div>
    </div>

    <div class="section">
        <h2>2. File System Check</h2>
        <?php
        $checkPaths = [
            'Config File' => dirname(__DIR__, 2) . '/config/db.php',
            'API Directory' => __DIR__ . '/api',
            'Device Directory' => __DIR__ . '/api/device',
            'Auth Directory' => __DIR__ . '/api/auth',
            'Validate Setup API' => __DIR__ . '/api/device/validate_setup.php',
            'Register API' => __DIR__ . '/api/device/register.php',
            'PIN Login API' => __DIR__ . '/api/auth/pin_login.php'
        ];
        
        echo '<table>';
        echo '<tr><th>Component</th><th>Path</th><th>Status</th></tr>';
        foreach ($checkPaths as $name => $path) {
            $exists = file_exists($path);
            $status = $exists ? '<span class="success">✓ EXISTS</span>' : '<span class="error">✗ MISSING</span>';
            $isDir = is_dir($path);
            if ($exists && $isDir) {
                $status .= ' (Directory)';
            } elseif ($exists) {
                $status .= ' (File: ' . number_format(filesize($path)) . ' bytes)';
            }
            echo "<tr>";
            echo "<td><strong>$name</strong></td>";
            echo "<td><code>" . str_replace($parentDir, '...', $path) . "</code></td>";
            echo "<td>$status</td>";
            echo "</tr>";
        }
        echo '</table>';
        ?>
    </div>

    <div class="section">
        <h2>3. Directory Contents</h2>
        <?php
        $apiDir = __DIR__ . '/api';
        if (is_dir($apiDir)) {
            echo "<h3>API Directory Contents:</h3>";
            echo "<pre>";
            $files = scandir($apiDir);
            foreach ($files as $file) {
                if ($file != '.' && $file != '..') {
                    $fullPath = $apiDir . '/' . $file;
                    if (is_dir($fullPath)) {
                        echo "📁 $file/\n";
                        $subFiles = scandir($fullPath);
                        foreach ($subFiles as $subFile) {
                            if ($subFile != '.' && $subFile != '..') {
                                echo "   📄 $subFile\n";
                            }
                        }
                    } else {
                        echo "📄 $file\n";
                    }
                }
            }
            echo "</pre>";
        } else {
            echo '<p class="error">API directory does not exist!</p>';
            echo '<p>You need to create: <code>' . $apiDir . '</code></p>';
        }
        ?>
    </div>

    <div class="section">
        <h2>4. Database Connection Test</h2>
        <?php
        if (file_exists($configPath)) {
            echo '<p class="success">✓ Config file found</p>';
            try {
                require_once $configPath;
                $pdo = db();
                if ($pdo) {
                    echo '<p class="success">✓ Database connection successful</p>';
                    
                    // Check required tables
                    $tables = ['tenants', 'users', 'branches', 'settings', 'user_branches'];
                    echo '<h3>Table Check:</h3>';
                    echo '<table>';
                    echo '<tr><th>Table</th><th>Status</th><th>Rows</th></tr>';
                    foreach ($tables as $table) {
                        try {
                            $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
                            $count = $stmt->fetchColumn();
                            echo "<tr>";
                            echo "<td><strong>$table</strong></td>";
                            echo "<td><span class='success'>✓ EXISTS</span></td>";
                            echo "<td>$count rows</td>";
                            echo "</tr>";
                        } catch (Exception $e) {
                            echo "<tr>";
                            echo "<td><strong>$table</strong></td>";
                            echo "<td><span class='error'>✗ MISSING</span></td>";
                            echo "<td>-</td>";
                            echo "</tr>";
                        }
                    }
                    echo '</table>';
                } else {
                    echo '<p class="error">✗ Database connection failed - db() returned null</p>';
                }
            } catch (Exception $e) {
                echo '<p class="error">✗ Database error: ' . htmlspecialchars($e->getMessage()) . '</p>';
            }
        } else {
            echo '<p class="error">✗ Config file not found at: ' . $configPath . '</p>';
        }
        ?>
    </div>

    <div class="section">
        <h2>5. API Endpoint Tests</h2>
        <p>Click the buttons below to test each API endpoint:</p>
        
        <button class="test-btn" onclick="testAPI('validate')">Test Validate Setup API</button>
        <button class="test-btn" onclick="testAPI('register')">Test Register API</button>
        <button class="test-btn" onclick="testAPI('pin')">Test PIN Login API</button>
        
        <div id="test-results" style="margin-top: 20px;"></div>
    </div>

    <div class="section">
        <h2>6. Quick Fix Instructions</h2>
        <?php
        $needsCreation = [];
        
        if (!is_dir(__DIR__ . '/api')) {
            $needsCreation[] = "mkdir -p " . __DIR__ . "/api/device";
            $needsCreation[] = "mkdir -p " . __DIR__ . "/api/auth";
        }
        
        if (!file_exists(__DIR__ . '/api/device/validate_setup.php')) {
            $needsCreation[] = "Create file: " . __DIR__ . "/api/device/validate_setup.php";
        }
        
        if (!file_exists(__DIR__ . '/api/device/register.php')) {
            $needsCreation[] = "Create file: " . __DIR__ . "/api/device/register.php";
        }
        
        if (!empty($needsCreation)) {
            echo '<p class="warning">⚠️ You need to:</p>';
            echo '<pre>';
            foreach ($needsCreation as $cmd) {
                echo $cmd . "\n";
            }
            echo '</pre>';
        } else {
            echo '<p class="success">✓ All required directories and files appear to be in place</p>';
        }
        ?>
        
        <h3>Server Info:</h3>
        <pre>
PHP Version: <?php echo PHP_VERSION; ?>

Document Root: <?php echo $_SERVER['DOCUMENT_ROOT']; ?>

Script Name: <?php echo $_SERVER['SCRIPT_NAME']; ?>

Request URI: <?php echo $_SERVER['REQUEST_URI']; ?>

Server Software: <?php echo $_SERVER['SERVER_SOFTWARE']; ?>
        </pre>
    </div>
</div>

<script>
function testAPI(type) {
    const resultsDiv = document.getElementById('test-results');
    resultsDiv.innerHTML = '<p>Testing...</p>';
    
    let url = '';
    let data = {};
    
    switch(type) {
        case 'validate':
            url = '/pos/api/device/validate_setup.php';
            data = {
                tenant_code: 'REST001',
                username: 'admin',
                pin: '1234',
                device_fingerprint: 'test123'
            };
            break;
        case 'register':
            url = '/pos/api/device/register.php';
            data = {
                device_token: 'test_token_' + Date.now(),
                tenant_id: 1,
                branch_id: 1,
                user_id: 1,
                device_name: 'Test Device'
            };
            break;
        case 'pin':
            url = '/pos/api/auth/pin_login.php';
            data = {
                user_id: 1,
                pin: '1234'
            };
            break;
    }
    
    resultsDiv.innerHTML = `
        <h4>Testing: ${url}</h4>
        <p>Sending data:</p>
        <pre>${JSON.stringify(data, null, 2)}</pre>
    `;
    
    fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(data)
    })
    .then(response => {
        const status = response.status;
        const statusText = response.statusText;
        return response.text().then(text => {
            let json = null;
            try {
                json = JSON.parse(text);
            } catch(e) {
                // Not JSON
            }
            
            let html = `<h4>Response from ${url}:</h4>`;
            html += `<p>Status: ${status} ${statusText}</p>`;
            
            if (status === 404) {
                html += '<p class="error">❌ 404 - Endpoint not found! File does not exist at this path.</p>';
            } else if (status === 200) {
                html += '<p class="success">✅ Endpoint found and responding!</p>';
            }
            
            if (json) {
                html += '<pre>' + JSON.stringify(json, null, 2) + '</pre>';
            } else {
                html += '<p>Raw response:</p>';
                html += '<pre>' + text.substring(0, 500) + '</pre>';
            }
            
            resultsDiv.innerHTML += html;
        });
    })
    .catch(error => {
        resultsDiv.innerHTML += `<p class="error">Network error: ${error.message}</p>`;
    });
}

// Auto-test on load
window.addEventListener('load', function() {
    // Automatically test the validate endpoint
    setTimeout(() => {
        console.log('Auto-testing validate endpoint...');
        testAPI('validate');
    }, 1000);
});
</script>

</body>
</html>