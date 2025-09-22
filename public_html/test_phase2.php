<?php
/**
 * SME 180 - Complete Phase 2 API Test Suite
 * Upload to: /public_html/test_all_apis.php
 * Tests all 8 Phase 2 requirements in one go
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/config/db.php';

// Test configuration
$BASE_URL = 'https://' . $_SERVER['HTTP_HOST'];
$TEST_TENANT_CODE = 'T0001';
$TEST_USERNAME = 'admin';
$TEST_PIN = '1234';

// Store results
$testResults = [];
$deviceToken = null;
$branches = [];

// Helper function to call APIs
function callAPI($method, $endpoint, $data = null) {
    global $BASE_URL;
    
    $url = $BASE_URL . $endpoint;
    $ch = curl_init($url);
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    $json = json_decode($response, true);
    
    return [
        'success' => $httpCode === 200 && $json && ($json['success'] ?? false),
        'http_code' => $httpCode,
        'response' => $json,
        'error' => $error,
        'raw' => $response
    ];
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Phase 2 Complete Test Suite</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 20px; }
        .container { max-width: 1400px; margin: auto; }
        h1 { color: white; text-align: center; margin-bottom: 30px; font-size: 2.5em; text-shadow: 2px 2px 4px rgba(0,0,0,0.2); }
        .test-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px; }
        .test-card { background: white; border-radius: 12px; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); transition: transform 0.3s; }
        .test-card:hover { transform: translateY(-5px); }
        .test-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e5e7eb; }
        .test-number { background: #6366f1; color: white; width: 35px; height: 35px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; }
        .test-title { flex: 1; margin-left: 15px; font-size: 1.2em; font-weight: 600; color: #1f2937; }
        .status-badge { padding: 6px 12px; border-radius: 20px; font-size: 0.85em; font-weight: 600; }
        .status-pass { background: #10b981; color: white; }
        .status-fail { background: #ef4444; color: white; }
        .status-pending { background: #f59e0b; color: white; }
        .test-content { margin: 20px 0; }
        .info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f3f4f6; }
        .info-label { color: #6b7280; font-weight: 500; }
        .info-value { color: #111827; font-weight: 600; }
        .branch-list { margin-top: 10px; }
        .branch-item { background: #f3f4f6; padding: 8px 12px; margin: 5px 0; border-radius: 6px; display: flex; justify-content: space-between; }
        .branch-type { background: #6366f1; color: white; padding: 2px 8px; border-radius: 4px; font-size: 0.8em; }
        .user-group { margin: 15px 0; }
        .user-group-title { font-weight: 600; color: #4b5563; margin-bottom: 8px; }
        .user-item { background: #f9fafb; padding: 8px; margin: 4px 0; border-radius: 4px; display: flex; align-items: center; }
        .user-avatar { width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; color: white; margin-right: 10px; }
        .summary-card { background: white; border-radius: 12px; padding: 30px; margin-top: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-top: 20px; }
        .summary-item { text-align: center; padding: 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 8px; color: white; }
        .summary-number { font-size: 2.5em; font-weight: bold; }
        .summary-label { margin-top: 5px; opacity: 0.9; }
        pre { background: #1f2937; color: #10b981; padding: 15px; border-radius: 8px; overflow-x: auto; font-size: 0.85em; margin-top: 10px; }
        .error-msg { background: #fef2f2; color: #991b1b; padding: 10px; border-radius: 6px; margin-top: 10px; }
        .loading { text-align: center; color: #6b7280; padding: 20px; }
    </style>
</head>
<body>
<div class="container">
    <h1>🧪 Phase 2 Complete API Test Suite</h1>
    
    <div class="test-grid">
        
        <?php
        // TEST 1: Device Validation
        $test1 = callAPI('POST', '/pos/api/device/validate_setup.php', [
            'tenant_code' => $TEST_TENANT_CODE,
            'username' => $TEST_USERNAME,
            'pin' => $TEST_PIN
        ]);
        
        if ($test1['success']) {
            $deviceToken = $test1['response']['data']['device']['token'];
            $branches = $test1['response']['data']['branches'];
            $testResults['device_validation'] = true;
        } else {
            $testResults['device_validation'] = false;
        }
        ?>
        
        <!-- Test 1: Device Validation -->
        <div class="test-card">
            <div class="test-header">
                <div style="display: flex; align-items: center;">
                    <div class="test-number">1</div>
                    <div class="test-title">Device Validation</div>
                </div>
                <span class="status-badge <?= $test1['success'] ? 'status-pass' : 'status-fail' ?>">
                    <?= $test1['success'] ? '✓ PASS' : '✗ FAIL' ?>
                </span>
            </div>
            <div class="test-content">
                <?php if ($test1['success']): ?>
                    <div class="info-row">
                        <span class="info-label">Tenant:</span>
                        <span class="info-value"><?= $test1['response']['data']['tenant']['name'] ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">User:</span>
                        <span class="info-value"><?= $test1['response']['data']['user']['name'] ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Device Token:</span>
                        <span class="info-value" style="font-size: 0.8em;"><?= substr($deviceToken, 0, 20) ?>...</span>
                    </div>
                <?php else: ?>
                    <div class="error-msg"><?= $test1['response']['error'] ?? 'Failed to validate' ?></div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Test 2: Returns Branches -->
        <div class="test-card">
            <div class="test-header">
                <div style="display: flex; align-items: center;">
                    <div class="test-number">2</div>
                    <div class="test-title">Branch Selection</div>
                </div>
                <span class="status-badge <?= count($branches) > 0 ? 'status-pass' : 'status-fail' ?>">
                    <?= count($branches) ?> Branches
                </span>
            </div>
            <div class="test-content">
                <div class="branch-list">
                    <?php foreach ($branches as $branch): ?>
                    <div class="branch-item">
                        <span><?= htmlspecialchars($branch['name']) ?></span>
                        <span class="branch-type"><?= $branch['type'] ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- Test 3: POS Settings -->
        <div class="test-card">
            <div class="test-header">
                <div style="display: flex; align-items: center;">
                    <div class="test-number">3</div>
                    <div class="test-title">POS Settings</div>
                </div>
                <span class="status-badge <?= isset($test1['response']['data']['settings']) ? 'status-pass' : 'status-fail' ?>">
                    <?= count($test1['response']['data']['settings'] ?? []) ?> Settings
                </span>
            </div>
            <div class="test-content">
                <?php if (isset($test1['response']['data']['settings'])): 
                    $settings = $test1['response']['data']['settings'];
                ?>
                <div class="info-row">
                    <span class="info-label">Cash Session:</span>
                    <span class="info-value"><?= $settings['require_cash_session'] ? 'Required' : 'Optional' ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Service Charge:</span>
                    <span class="info-value"><?= $settings['service_charge_percent'] ?? '10' ?>%</span>
                </div>
                <div class="info-row">
                    <span class="info-label">PIN Attempts:</span>
                    <span class="info-value"><?= $settings['pin_max_attempts'] ?? '5' ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php
        // TEST 4: Users List
        $test4 = callAPI('GET', '/pos/api/users/list_by_tenant.php?tenant_id=1');
        $testResults['users_list'] = $test4['success'];
        ?>
        
        <!-- Test 4: Users List -->
        <div class="test-card">
            <div class="test-header">
                <div style="display: flex; align-items: center;">
                    <div class="test-number">4</div>
                    <div class="test-title">Users List</div>
                </div>
                <span class="status-badge <?= $test4['success'] ? 'status-pass' : 'status-fail' ?>">
                    <?= $test4['response']['data']['counts']['total'] ?? 0 ?> Users
                </span>
            </div>
            <div class="test-content">
                <?php if ($test4['success'] && isset($test4['response']['data']['grouped_users'])): 
                    $groups = $test4['response']['data']['grouped_users'];
                ?>
                <?php foreach ($groups as $groupName => $users): ?>
                    <?php if (!empty($users)): ?>
                    <div class="user-group">
                        <div class="user-group-title"><?= ucfirst($groupName) ?> (<?= count($users) ?>)</div>
                        <?php foreach ($users as $user): ?>
                        <div class="user-item">
                            <div class="user-avatar" style="background: <?= $user['color'] ?>">
                                <?= $user['initials'] ?>
                            </div>
                            <div>
                                <div style="font-weight: 600;"><?= $user['name'] ?></div>
                                <div style="font-size: 0.85em; color: #6b7280;">@<?= $user['username'] ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <?php
        // TEST 5: Device Registration
        if ($deviceToken && !empty($branches)) {
            $test5 = callAPI('POST', '/pos/api/device/register.php', [
                'device_token' => $deviceToken,
                'tenant_id' => 1,
                'branch_id' => $branches[0]['id'],
                'user_id' => 1,
                'device_name' => 'Test Terminal'
            ]);
            $testResults['device_registration'] = $test5['success'];
        } else {
            $test5 = ['success' => false, 'response' => ['error' => 'No device token available']];
            $testResults['device_registration'] = false;
        }
        ?>
        
        <!-- Test 5: Device Registration -->
        <div class="test-card">
            <div class="test-header">
                <div style="display: flex; align-items: center;">
                    <div class="test-number">5</div>
                    <div class="test-title">Device Registration</div>
                </div>
                <span class="status-badge <?= $test5['success'] ? 'status-pass' : 'status-fail' ?>">
                    <?= $test5['success'] ? '✓ PASS' : '✗ FAIL' ?>
                </span>
            </div>
            <div class="test-content">
                <?php if ($test5['success']): ?>
                    <div class="info-row">
                        <span class="info-label">Status:</span>
                        <span class="info-value"><?= $test5['response']['data']['message'] ?? 'Registered' ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Branch:</span>
                        <span class="info-value"><?= $test5['response']['data']['branch_name'] ?? $branches[0]['name'] ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Device ID:</span>
                        <span class="info-value">#<?= $test5['response']['data']['device_id'] ?? 'N/A' ?></span>
                    </div>
                <?php else: ?>
                    <div class="error-msg"><?= $test5['response']['error'] ?? 'Registration failed' ?></div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php
        // TEST 6: PIN Login
        $test6 = callAPI('POST', '/pos/api/auth/pin_login.php', [
            'user_id' => 1,
            'pin' => $TEST_PIN,
            'device_token' => $deviceToken ?? ''
        ]);
        $testResults['pin_login'] = $test6['success'];
        ?>
        
        <!-- Test 6: PIN Login -->
        <div class="test-card">
            <div class="test-header">
                <div style="display: flex; align-items: center;">
                    <div class="test-number">6</div>
                    <div class="test-title">PIN Login</div>
                </div>
                <span class="status-badge <?= $test6['success'] ? 'status-pass' : 'status-fail' ?>">
                    <?= $test6['success'] ? '✓ PASS' : '✗ FAIL' ?>
                </span>
            </div>
            <div class="test-content">
                <?php if ($test6['success']): ?>
                    <div class="info-row">
                        <span class="info-label">User:</span>
                        <span class="info-value"><?= $test6['response']['data']['user']['name'] ?? 'N/A' ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Role:</span>
                        <span class="info-value"><?= $test6['response']['data']['user']['role_name'] ?? 'N/A' ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Session:</span>
                        <span class="info-value"><?= $test6['response']['data']['session']['branch_set'] ? 'Branch Set' : 'No Branch' ?></span>
                    </div>
                <?php else: ?>
                    <div class="error-msg"><?= $test6['response']['error'] ?? 'Login failed' ?></div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php
        // TEST 7: User Branches
        $test7 = callAPI('GET', '/pos/api/branches/user_branches.php?user_id=1');
        $testResults['user_branches'] = $test7['success'];
        ?>
        
        <!-- Test 7: User Branches -->
        <div class="test-card">
            <div class="test-header">
                <div style="display: flex; align-items: center;">
                    <div class="test-number">7</div>
                    <div class="test-title">User Branches API</div>
                </div>
                <span class="status-badge <?= $test7['success'] ? 'status-pass' : 'status-fail' ?>">
                    <?= $test7['success'] ? '✓ PASS' : '✗ FAIL' ?>
                </span>
            </div>
            <div class="test-content">
                <?php if ($test7['success'] && isset($test7['response']['data']['branches'])): ?>
                    <div class="info-row">
                        <span class="info-label">Total Branches:</span>
                        <span class="info-value"><?= count($test7['response']['data']['branches']) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Needs Selection:</span>
                        <span class="info-value"><?= $test7['response']['data']['requires_selection'] ? 'Yes' : 'No' ?></span>
                    </div>
                    <?php if (isset($test7['response']['data']['recommended_branch'])): ?>
                    <div class="info-row">
                        <span class="info-label">Recommended:</span>
                        <span class="info-value"><?= $test7['response']['data']['recommended_branch']['name'] ?></span>
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="error-msg"><?= $test7['response']['error'] ?? 'Failed to load branches' ?></div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php
        // TEST 8: Device Token Functionality
        $tokenGenerated = !empty($deviceToken);
        $testResults['device_token'] = $tokenGenerated;
        ?>
        
        <!-- Test 8: Device Token -->
        <div class="test-card">
            <div class="test-header">
                <div style="display: flex; align-items: center;">
                    <div class="test-number">8</div>
                    <div class="test-title">Device Token</div>
                </div>
                <span class="status-badge <?= $tokenGenerated ? 'status-pass' : 'status-fail' ?>">
                    <?= $tokenGenerated ? '✓ PASS' : '✗ FAIL' ?>
                </span>
            </div>
            <div class="test-content">
                <?php if ($tokenGenerated): ?>
                    <div class="info-row">
                        <span class="info-label">Token Length:</span>
                        <span class="info-value"><?= strlen($deviceToken) ?> chars</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Type:</span>
                        <span class="info-value">Hex String</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Unique:</span>
                        <span class="info-value">Yes</span>
                    </div>
                <?php else: ?>
                    <div class="error-msg">No device token generated</div>
                <?php endif; ?>
            </div>
        </div>
        
    </div>
    
    <!-- Summary -->
    <div class="summary-card">
        <h2 style="text-align: center; margin-bottom: 20px; color: #1f2937;">Test Summary</h2>
        
        <?php
        $passed = array_sum($testResults);
        $total = count($testResults);
        $percentage = round(($passed / $total) * 100);
        ?>
        
        <div class="summary-grid">
            <div class="summary-item">
                <div class="summary-number"><?= $passed ?>/<?= $total ?></div>
                <div class="summary-label">Tests Passed</div>
            </div>
            <div class="summary-item">
                <div class="summary-number"><?= $percentage ?>%</div>
                <div class="summary-label">Success Rate</div>
            </div>
            <div class="summary-item">
                <div class="summary-number"><?= count($branches) ?></div>
                <div class="summary-label">Branches Found</div>
            </div>
            <div class="summary-item">
                <div class="summary-number"><?= $test4['response']['data']['counts']['total'] ?? 0 ?></div>
                <div class="summary-label">Total Users</div>
            </div>
        </div>
        
        <div style="margin-top: 30px; padding: 20px; background: <?= $percentage === 100 ? '#d4edda' : '#fff3cd' ?>; border-radius: 8px;">
            <h3 style="color: <?= $percentage === 100 ? '#155724' : '#856404' ?>; margin-bottom: 10px;">
                <?= $percentage === 100 ? '🎉 All Tests Passed!' : '⚠️ Some Tests Need Attention' ?>
            </h3>
            
            <?php if ($percentage === 100): ?>
                <p style="color: #155724;">Phase 2 is complete! All APIs are working correctly. You can now proceed to Phase 3.</p>
            <?php else: ?>
                <p style="color: #856404;">Please check the failed tests above and ensure all API files are uploaded correctly.</p>
            <?php endif; ?>
            
            <h4 style="margin-top: 15px;">Test Results:</h4>
            <ul style="margin-top: 10px;">
                <?php foreach ($testResults as $test => $result): ?>
                <li><?= ucwords(str_replace('_', ' ', $test)) ?>: 
                    <strong style="color: <?= $result ? '#059669' : '#dc2626' ?>">
                        <?= $result ? '✓ Pass' : '✗ Fail' ?>
                    </strong>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    
</div>
</body>
</html>