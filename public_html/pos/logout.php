<?php
/**
 * SME 180 - POS Logout and Device Reset
 * File: /public_html/pos/logout.php
 * Version: 1.0.0 - Production Ready
 * 
 * Clears device registration and returns to setup
 */

session_start();

// Clear server-side session
$_SESSION = [];
session_destroy();

// Clear cookies
setcookie('pos_device_token', '', time() - 3600, '/');
setcookie('pos_tenant_id', '', time() - 3600, '/');
setcookie('pos_tenant_code', '', time() - 3600, '/');
setcookie('pos_branch_id', '', time() - 3600, '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logging Out - SME 180 POS</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .logout-container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            text-align: center;
            max-width: 400px;
            width: 90%;
        }
        
        .logo {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            color: white;
        }
        
        h2 {
            color: #2d3748;
            margin-bottom: 10px;
        }
        
        p {
            color: #718096;
            margin-bottom: 20px;
        }
        
        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #e2e8f0;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .status {
            color: #667eea;
            font-weight: 600;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="logout-container">
        <div class="logo">🔒</div>
        <h2>Logging Out</h2>
        <p>Clearing device registration...</p>
        <div class="spinner"></div>
        <div class="status">Please wait...</div>
    </div>

    <script>
        // Clear all localStorage data
        const clearDeviceData = () => {
            console.log('Clearing device data...');
            
            // List of all POS-related localStorage keys
            const posKeys = [
                'pos_device_token',
                'pos_device_fingerprint',
                'pos_tenant_id',
                'pos_tenant_code',
                'pos_tenant_name',
                'pos_user_id',
                'pos_user_name',
                'pos_user_role',
                'pos_branch_id',
                'pos_branch_name',
                'pos_branch_type',
                'pos_settings',
                'pos_device_registered',
                'pos_device_registered_at',
                'device_token',
                'device_fingerprint',
                'tenant_id',
                'tenant_code',
                'tenant_name',
                'user_id',
                'user_name',
                'user_role',
                'branch_id',
                'branch_name',
                'branch_type',
                'setup_complete'
            ];
            
            // Clear each key
            posKeys.forEach(key => {
                localStorage.removeItem(key);
                console.log(`Cleared: ${key}`);
            });
            
            // Also clear sessionStorage
            sessionStorage.clear();
            
            // Update status
            document.querySelector('.status').textContent = 'Device reset complete. Redirecting...';
            
            // Redirect to device setup after a short delay
            setTimeout(() => {
                window.location.href = '/pos/device-setup.php';
            }, 1500);
        };
        
        // Execute logout
        window.addEventListener('load', () => {
            setTimeout(clearDeviceData, 500);
        });
        
        // Prevent back button
        history.pushState(null, null, document.URL);
        window.addEventListener('popstate', () => {
            history.pushState(null, null, document.URL);
        });
    </script>
</body>
</html>