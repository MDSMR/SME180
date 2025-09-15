<?php
// File: /public_html/test_phase1.php
// Updated Phase 1 POS API Test Page with Hash Support
declare(strict_types=1);

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Include database config to check users
require_once __DIR__ . '/config/db.php';

$BASE = "https://" . $_SERVER['HTTP_HOST'] . "/pos/api";

// Get available test users from database
$testUsers = [];
try {
    $pdo = db();
    $stmt = $pdo->query("
        SELECT id, username, name, role_key, pos_pin, manager_pin 
        FROM users 
        WHERE pos_pin IS NOT NULL 
        ORDER BY id DESC 
        LIMIT 10
    ");
    $testUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $testUsers = [];
}

// Detect if PINs are hashed
$pinsAreHashed = false;
if (!empty($testUsers) && strlen($testUsers[0]['pos_pin'] ?? '') > 20) {
    $pinsAreHashed = true;
}

// Known PIN mappings for hashed values
$knownPins = [
    '1234' => '03ac674216f3e15c761ee1a5e255f067953623c8b388b4459e13c02c16e304407',
    '2345' => 'Unknown - test with actual PIN',
    '3456' => 'Unknown - test with actual PIN',
    '5678' => 'Unknown - test with actual PIN',
    '7890' => 'Unknown - test with actual PIN',
    '9999' => 'Unknown - test with actual PIN',
    '0000' => 'Unknown - test with actual PIN'
];

// Defaults with detected values
$DEFAULTS = [
    'tenant_id'      => 1,
    'branch_id'      => 1,
    'station_code'   => 'POS1',
    'station_name'   => 'Front POS',
    'station_type'   => 'pos',
    'pin_user'       => '1234',   // Will work with admin user
    'pin_manager'    => '5678',   // Manager PIN for admin
    'opening_amount' => 500.00,
    'closing_amount' => 750.00,
    'user_id'        => !empty($testUsers) ? $testUsers[0]['id'] : 1
];

function callApi(string $url, array $payload): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 10
    ]);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = null;
    if (is_string($raw)) {
        $tmp = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $json = $tmp;
        }
    }

    return [
        'request_url'  => $url,
        'request_body' => $payload,
        'raw_response' => $raw,
        'json'         => $json,
        'http_code'    => $httpCode,
        'curl_errno'   => $errno,
        'curl_error'   => $err,
        'success'      => $httpCode === 200 && $json && ($json['success'] ?? false)
    ];
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Process form submission
$tenant_id      = (int)($_POST['tenant_id'] ?? $DEFAULTS['tenant_id']);
$branch_id      = (int)($_POST['branch_id'] ?? $DEFAULTS['branch_id']);
$station_code   = $_POST['station_code'] ?? $DEFAULTS['station_code'];
$station_name   = $_POST['station_name'] ?? $DEFAULTS['station_name'];
$station_type   = $_POST['station_type'] ?? $DEFAULTS['station_type'];
$pin_user       = $_POST['pin_user'] ?? $DEFAULTS['pin_user'];
$pin_manager    = $_POST['pin_manager'] ?? $DEFAULTS['pin_manager'];
$opening_amount = (float)($_POST['opening_amount'] ?? $DEFAULTS['opening_amount']);
$closing_amount = (float)($_POST['closing_amount'] ?? $DEFAULTS['closing_amount']);
$user_id        = (int)($_POST['user_id'] ?? $DEFAULTS['user_id']);
$station_id     = isset($_POST['station_id']) && $_POST['station_id'] !== '' ? (int)$_POST['station_id'] : null;
$run            = isset($_POST['run']);

$results = [];
$session_id_to_close = null;
$derived_user_id = null;
$derived_station_id = null;

// Run tests
if ($run) {
    // 1. Register Station
    $results['1. Register Station'] = callApi("$BASE/stations/register.php", [
        'tenant_id'    => $tenant_id,
        'branch_id'    => $branch_id,
        'station_code' => $station_code,
        'station_name' => $station_name,
        'station_type' => $station_type,
    ]);
    
    if ($results['1. Register Station']['success']) {
        $derived_station_id = $results['1. Register Station']['json']['data']['station_id'] ?? null;
    }

    // 2. PIN Login
    $results['2. PIN Login'] = callApi("$BASE/auth/pin_login.php", [
        'pin'          => $pin_user,
        'station_code' => $station_code,
        'tenant_id'    => $tenant_id,
        'branch_id'    => $branch_id,
    ]);

    if ($results['2. PIN Login']['success']) {
        $derived_user_id = $results['2. PIN Login']['json']['data']['user_id'] ?? null;
    }

    // 3. Open Session
    $results['3. Open Session'] = callApi("$BASE/session/open.php", [
        'tenant_id'      => $tenant_id,
        'branch_id'      => $branch_id,
        'station_id'     => $derived_station_id ?? $station_id,
        'user_id'        => $derived_user_id ?? $user_id,
        'opening_amount' => $opening_amount,
    ]);

    if ($results['3. Open Session']['success']) {
        $session_id_to_close = $results['3. Open Session']['json']['data']['session_id'] ?? null;
    }

    // 4. Station Heartbeat
    $results['4. Station Heartbeat'] = callApi("$BASE/stations/heartbeat.php", [
        'tenant_id'    => $tenant_id,
        'branch_id'    => $branch_id,
        'station_code' => $station_code,
    ]);

    // 5. Check Active Session
    $results['5. Active Session'] = callApi("$BASE/session/active.php", [
        'tenant_id'    => $tenant_id,
        'branch_id'    => $branch_id,
        'station_id'   => $derived_station_id ?? $station_id,
    ]);

    // 6. Validate Manager PIN
    $results['6. Validate Manager PIN'] = callApi("$BASE/auth/validate_pin.php", [
        'pin'       => $pin_manager,
        'tenant_id' => $tenant_id,
        'branch_id' => $branch_id,
    ]);

    // 7. Close Session
    if ($session_id_to_close) {
        $results['7. Close Session'] = callApi("$BASE/session/close.php", [
            'tenant_id'      => $tenant_id,
            'branch_id'      => $branch_id,
            'session_id'     => $session_id_to_close,
            'closing_amount' => $closing_amount,
        ]);
    }

    // 8. Logout
    $results['8. Logout'] = callApi("$BASE/auth/logout.php", []);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SME 180 - Phase 1 API Test Suite</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
    padding: 20px;
}
.container {
    max-width: 1400px;
    margin: 0 auto;
    background: white;
    border-radius: 20px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    overflow: hidden;
}
.header {
    background: linear-gradient(135deg, #2563eb 0%, #7c3aed 100%);
    color: white;
    padding: 30px;
    text-align: center;
}
.header h1 {
    font-size: 2rem;
    margin-bottom: 10px;
}
.header .subtitle {
    opacity: 0.9;
    font-size: 0.95rem;
}
.status-bar {
    background: #f8fafc;
    padding: 15px 30px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.status-item {
    display: flex;
    align-items: center;
    gap: 8px;
}
.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #10b981;
}
.status-dot.warning { background: #f59e0b; }
.status-dot.error { background: #ef4444; }
.content {
    padding: 30px;
}
.test-users {
    background: #f0f9ff;
    border: 1px solid #0284c7;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 25px;
}
.test-users h3 {
    color: #0c4a6e;
    margin-bottom: 10px;
    font-size: 1.1rem;
}
.users-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 10px;
}
.user-card {
    background: white;
    padding: 10px;
    border-radius: 6px;
    font-size: 0.9rem;
    border: 1px solid #e0f2fe;
}
.user-card strong {
    color: #0c4a6e;
}
.pin-display {
    font-family: monospace;
    background: #f1f5f9;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 0.85rem;
}
.form-section {
    background: #f8fafc;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 25px;
}
.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 15px;
}
.form-group {
    display: flex;
    flex-direction: column;
}
.form-group label {
    font-size: 0.9rem;
    font-weight: 600;
    margin-bottom: 5px;
    color: #475569;
}
.form-group input, .form-group select {
    padding: 10px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 0.95rem;
}
.form-group input:focus, .form-group select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}
.form-group .hint {
    font-size: 0.75rem;
    color: #64748b;
    margin-top: 3px;
}
.btn {
    padding: 12px 24px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 1rem;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
}
.btn-primary {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
}
.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 20px rgba(59, 130, 246, 0.3);
}
.results-section {
    margin-top: 30px;
}
.result-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    margin-bottom: 20px;
    overflow: hidden;
}
.result-header {
    padding: 15px 20px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.result-title {
    font-weight: 600;
    font-size: 1.05rem;
}
.result-status {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
}
.status-success {
    background: #10b981;
    color: white;
}
.status-error {
    background: #ef4444;
    color: white;
}
.status-pending {
    background: #6b7280;
    color: white;
}
.result-body {
    padding: 20px;
}
.result-section {
    margin-bottom: 15px;
}
.result-section h4 {
    font-size: 0.85rem;
    text-transform: uppercase;
    color: #64748b;
    margin-bottom: 8px;
    letter-spacing: 0.05em;
}
.code-block {
    background: #1e293b;
    color: #e2e8f0;
    padding: 12px;
    border-radius: 6px;
    font-family: 'Courier New', monospace;
    font-size: 0.85rem;
    overflow-x: auto;
    white-space: pre-wrap;
    word-wrap: break-word;
}
.summary-box {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    border: 1px solid #f59e0b;
    border-radius: 12px;
    padding: 20px;
    margin-top: 30px;
}
.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
    margin-top: 15px;
}
.summary-stat {
    text-align: center;
}
.summary-stat .number {
    font-size: 2rem;
    font-weight: bold;
    color: #92400e;
}
.summary-stat .label {
    font-size: 0.9rem;
    color: #78350f;
    text-transform: uppercase;
}
.warning-box {
    background: #fef2f2;
    border: 1px solid #ef4444;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
    color: #991b1b;
}
.success-box {
    background: #f0fdf4;
    border: 1px solid #10b981;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
    color: #166534;
}
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🚀 SME 180 - Phase 1 POS API Test Suite</h1>
        <div class="subtitle">Complete testing environment for all Phase 1 endpoints</div>
    </div>
    
    <div class="status-bar">
        <div class="status-item">
            <span class="status-dot <?= $pinsAreHashed ? 'warning' : '' ?>"></span>
            <span>PINs are <?= $pinsAreHashed ? 'Hashed (SHA256)' : 'Plain Text' ?></span>
        </div>
        <div class="status-item">
            <span class="status-dot"></span>
            <span><?= count($testUsers) ?> Test Users Available</span>
        </div>
        <div class="status-item">
            <span><?= date('Y-m-d H:i:s') ?></span>
        </div>
    </div>

    <div class="content">
        <?php if (!empty($testUsers)): ?>
        <div class="test-users">
            <h3>📋 Available Test Users in Database</h3>
            <div class="users-grid">
                <?php foreach ($testUsers as $user): ?>
                <div class="user-card">
                    <strong><?= h($user['name']) ?></strong> (<?= h($user['username']) ?>)<br>
                    Role: <?= h($user['role_key']) ?><br>
                    <?php if ($user['pos_pin']): ?>
                        POS PIN: <span class="pin-display"><?= strlen($user['pos_pin']) > 20 ? 'Hashed' : h($user['pos_pin']) ?></span>
                        <?php if ($user['username'] == 'admin' && strlen($user['pos_pin']) < 20): ?>
                            (Use: <?= h($user['pos_pin']) ?>)
                        <?php elseif ($user['username'] == 'admin'): ?>
                            (Try: 1234)
                        <?php elseif (strpos($user['username'], 'cashier') !== false): ?>
                            (Try: 1234)
                        <?php elseif (strpos($user['username'], 'waiter') !== false): ?>
                            (Try: 2345)
                        <?php elseif (strpos($user['username'], 'posmgr') !== false): ?>
                            (Try: 3456)
                        <?php elseif (strpos($user['username'], 'admin_') !== false): ?>
                            (Try: 9999)
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($user['manager_pin']): ?>
                        <br>MGR PIN: <span class="pin-display"><?= strlen($user['manager_pin']) > 20 ? 'Hashed' : h($user['manager_pin']) ?></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($pinsAreHashed): ?>
        <div class="warning-box">
            <strong>⚠️ PINs are Hashed:</strong> Your database stores PINs as SHA256 hashes. Make sure your API supports hashed PINs. 
            The test will use the plain PIN values, and the API should hash them before comparison.
        </div>
        <?php endif; ?>

        <form method="post">
            <div class="form-section">
                <h3>Test Configuration</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Tenant ID</label>
                        <input name="tenant_id" type="number" value="<?= h($tenant_id) ?>">
                    </div>
                    <div class="form-group">
                        <label>Branch ID</label>
                        <input name="branch_id" type="number" value="<?= h($branch_id) ?>">
                    </div>
                    <div class="form-group">
                        <label>Station Code</label>
                        <input name="station_code" value="<?= h($station_code) ?>">
                    </div>
                    <div class="form-group">
                        <label>Station Name</label>
                        <input name="station_name" value="<?= h($station_name) ?>">
                    </div>
                    <div class="form-group">
                        <label>Station Type</label>
                        <select name="station_type">
                            <?php foreach (['pos','bar','kitchen','host','mobile'] as $type): ?>
                            <option value="<?= $type ?>" <?= $station_type === $type ? 'selected' : '' ?>><?= $type ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>User PIN</label>
                        <input name="pin_user" value="<?= h($pin_user) ?>">
                        <span class="hint">For login (try: 1234, 2345, 3456, 9999)</span>
                    </div>
                    <div class="form-group">
                        <label>Manager PIN</label>
                        <input name="pin_manager" value="<?= h($pin_manager) ?>">
                        <span class="hint">For overrides (try: 5678, 7890, 0000)</span>
                    </div>
                    <div class="form-group">
                        <label>Opening Amount</label>
                        <input name="opening_amount" type="number" step="0.01" value="<?= h($opening_amount) ?>">
                    </div>
                    <div class="form-group">
                        <label>Closing Amount</label>
                        <input name="closing_amount" type="number" step="0.01" value="<?= h($closing_amount) ?>">
                    </div>
                    <div class="form-group">
                        <label>User ID (Fallback)</label>
                        <input name="user_id" type="number" value="<?= h($user_id) ?>">
                        <span class="hint">Used if PIN login doesn't return user_id</span>
                    </div>
                    <div class="form-group">
                        <label>Station ID (Optional)</label>
                        <input name="station_id" type="number" value="<?= h($station_id ?? '') ?>">
                        <span class="hint">Leave empty to auto-detect</span>
                    </div>
                </div>
                <div style="text-align: center; margin-top: 20px;">
                    <button type="submit" name="run" value="1" class="btn btn-primary">
                        ▶️ Run Complete Test Sequence
                    </button>
                </div>
            </div>
        </form>

        <?php if ($run && !empty($results)): ?>
        <div class="results-section">
            <h2 style="margin-bottom: 20px;">📊 Test Results</h2>
            
            <?php 
            $totalTests = count($results);
            $passedTests = 0;
            foreach ($results as $r) {
                if ($r['success']) $passedTests++;
            }
            ?>
            
            <div class="summary-box">
                <h3>Test Summary</h3>
                <div class="summary-grid">
                    <div class="summary-stat">
                        <div class="number"><?= $totalTests ?></div>
                        <div class="label">Total Tests</div>
                    </div>
                    <div class="summary-stat">
                        <div class="number" style="color: #059669;"><?= $passedTests ?></div>
                        <div class="label">Passed</div>
                    </div>
                    <div class="summary-stat">
                        <div class="number" style="color: #dc2626;"><?= $totalTests - $passedTests ?></div>
                        <div class="label">Failed</div>
                    </div>
                    <div class="summary-stat">
                        <div class="number"><?= round(($passedTests / $totalTests) * 100) ?>%</div>
                        <div class="label">Success Rate</div>
                    </div>
                </div>
            </div>

            <?php foreach ($results as $testName => $result): ?>
            <div class="result-card">
                <div class="result-header">
                    <div class="result-title"><?= h($testName) ?></div>
                    <div class="result-status <?= $result['success'] ? 'status-success' : 'status-error' ?>">
                        <?= $result['success'] ? '✓ PASSED' : '✗ FAILED' ?>
                    </div>
                </div>
                <div class="result-body">
                    <div class="result-section">
                        <h4>Endpoint</h4>
                        <div class="code-block"><?= h($result['request_url']) ?></div>
                    </div>
                    
                    <div class="result-section">
                        <h4>Request</h4>
                        <div class="code-block"><?= json_encode($result['request_body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?></div>
                    </div>
                    
                    <div class="result-section">
                        <h4>Response (HTTP <?= $result['http_code'] ?>)</h4>
                        <div class="code-block"><?= $result['json'] ? json_encode($result['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : h($result['raw_response'] ?: 'No response') ?></div>
                    </div>
                    
                    <?php if ($result['curl_error']): ?>
                    <div class="result-section">
                        <h4>Error</h4>
                        <div class="code-block" style="background: #7f1d1d; color: #fca5a5;"><?= h($result['curl_error']) ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($result['success'] && !empty($result['json']['data'])): ?>
                    <div class="result-section">
                        <h4>Extracted Data</h4>
                        <ul style="margin: 0; padding-left: 20px;">
                            <?php foreach ($result['json']['data'] as $key => $value): ?>
                            <li><?= h($key) ?>: <?= h(is_array($value) ? json_encode($value) : $value) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            
            <?php if ($passedTests === $totalTests): ?>
            <div class="success-box">
                <h3>🎉 Congratulations!</h3>
                <p>All Phase 1 tests passed successfully! Your POS system is ready for Phase 2.</p>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
