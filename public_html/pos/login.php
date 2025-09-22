<?php
/**
 * SME 180 POS - Login Interface
 * Path: /public_html/pos/login.php
 * 
 * Simplified version matching your current system
 */
declare(strict_types=1);

// Start session and include configuration
require_once __DIR__ . '/../config/db.php';

// Simple session start
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if already logged in
if (isset($_SESSION['pos_user_id']) && $_SESSION['pos_user_id'] > 0) {
    header('Location: /pos/index.php');
    exit;
}

// Get database connection
try {
    $pdo = db();
} catch (Exception $e) {
    die('Database connection failed. Please contact support.');
}

// Initialize variables
$error = '';
$success = '';

// Check for logout message
if (isset($_GET['logout']) && $_GET['logout'] === '1') {
    $success = 'You have been successfully logged out.';
}

// Get available POS stations for this branch
$stations = [
    ['code' => 'POS1', 'name' => 'Front Counter', 'type' => 'pos'],
    ['code' => 'POS2', 'name' => 'Drive Thru', 'type' => 'pos'],
    ['code' => 'BAR1', 'name' => 'Bar Station', 'type' => 'bar'],
    ['code' => 'MOBILE1', 'name' => 'Mobile POS', 'type' => 'mobile']
];

// Get currency symbol from settings
$currency = 'EGP';
try {
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE `key` = 'currency' LIMIT 1");
    $stmt->execute();
    $result = $stmt->fetchColumn();
    if ($result) {
        $currency = $result;
    }
} catch (Exception $e) {
    // Use default currency
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>POS Login - SME 180</title>
    <link rel="stylesheet" href="/pos/assets/css/pos-login.css">
    <link rel="icon" type="image/x-icon" href="/assets/images/favicon.ico">
</head>
<body>
    <!-- Clock Display -->
    <div class="clock-display">
        <div class="clock-time" id="clockTime">--:--:-- --</div>
        <div class="clock-date" id="clockDate">Loading...</div>
    </div>

    <!-- Main Login Container -->
    <div class="login-container">
        <!-- Header -->
        <div class="login-header">
            <div class="logo">🏪</div>
            <h1 class="login-title">SME 180 POS</h1>
            <p class="login-subtitle">Point of Sale Terminal</p>
        </div>

        <!-- Login Mode Tabs -->
        <div class="login-mode-tabs">
            <div class="mode-tab active" data-mode="pin">
                PIN Login
            </div>
            <div class="mode-tab" data-mode="manager">
                Manager Override
            </div>
        </div>

        <!-- Login Form -->
        <form id="loginForm" method="POST" action="/pos/api/auth/pin_login.php">
            
            <div class="login-body">
                <!-- Alert Messages -->
                <div id="alertContainer">
                    <?php if ($error): ?>
                    <div class="alert alert-error">
                        <svg class="alert-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($success): ?>
                    <div class="alert alert-success">
                        <svg class="alert-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Station Selector -->
                <div class="station-selector">
                    <label class="form-label">Select Station</label>
                    <div class="station-grid">
                        <?php foreach ($stations as $index => $station): ?>
                        <div class="station-card <?php echo $index === 0 ? 'selected' : ''; ?>" 
                             data-station="<?php echo htmlspecialchars($station['code']); ?>">
                            <div class="station-name"><?php echo htmlspecialchars($station['name']); ?></div>
                            <div class="station-code"><?php echo htmlspecialchars($station['code']); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" id="stationCode" name="station_code" value="<?php echo htmlspecialchars($stations[0]['code'] ?? 'POS1'); ?>">
                </div>

                <!-- PIN Login Mode -->
                <div id="pinMode" class="login-mode-content">
                    <div class="form-group">
                        <label class="form-label">Enter PIN Code</label>
                        <div class="pin-input-container">
                            <input type="text" class="pin-input" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off">
                            <input type="text" class="pin-input" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off">
                            <input type="text" class="pin-input" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off">
                            <input type="text" class="pin-input" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off">
                            <input type="text" class="pin-input" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off" style="display: none;">
                            <input type="text" class="pin-input" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off" style="display: none;">
                        </div>
                        <input type="hidden" id="pinValue" name="pin">
                        <input type="hidden" id="loginType" name="login_type" value="pin">
                    </div>

                    <!-- Numeric Keypad -->
                    <div class="numeric-keypad">
                        <button type="button" class="keypad-btn" data-key="1">1</button>
                        <button type="button" class="keypad-btn" data-key="2">2</button>
                        <button type="button" class="keypad-btn" data-key="3">3</button>
                        <button type="button" class="keypad-btn" data-key="4">4</button>
                        <button type="button" class="keypad-btn" data-key="5">5</button>
                        <button type="button" class="keypad-btn" data-key="6">6</button>
                        <button type="button" class="keypad-btn" data-key="7">7</button>
                        <button type="button" class="keypad-btn" data-key="8">8</button>
                        <button type="button" class="keypad-btn" data-key="9">9</button>
                        <button type="button" class="keypad-btn clear" data-action="clear">Clear</button>
                        <button type="button" class="keypad-btn" data-key="0">0</button>
                        <button type="button" class="keypad-btn backspace" data-action="backspace">←</button>
                    </div>
                </div>

                <!-- Manager Override Mode -->
                <div id="managerMode" class="login-mode-content hidden">
                    <div class="form-group">
                        <label class="form-label">Manager PIN</label>
                        <div class="pin-input-container">
                            <input type="text" class="pin-input manager-pin" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off">
                            <input type="text" class="pin-input manager-pin" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off">
                            <input type="text" class="pin-input manager-pin" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off">
                            <input type="text" class="pin-input manager-pin" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off">
                            <input type="text" class="pin-input manager-pin" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off">
                            <input type="text" class="pin-input manager-pin" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off">
                        </div>
                        <input type="hidden" id="managerPinValue" name="manager_pin">
                    </div>
                    <div class="info-text">
                        Manager override allows temporary elevated access for administrative functions.
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn-submit" id="submitBtn" <?php echo $is_locked ? 'disabled' : ''; ?>>
                    <span id="submitText">Sign In</span>
                    <span class="spinner hidden" id="submitSpinner"></span>
                </button>

                <!-- PIN Length Toggle -->
                <div class="pin-options">
                    <button type="button" class="pin-toggle-btn" id="pinToggle">
                        Switch to 6-digit PIN
                    </button>
                </div>
            </div>
        </form>

        <!-- Footer -->
        <div class="login-footer">
            <div class="footer-links">
                <a href="/views/auth/login.php" class="footer-link">Admin Panel</a>
                <a href="#" class="footer-link" onclick="return false;" data-action="help">Help</a>
                <a href="#" class="footer-link" onclick="return false;" data-action="about">Version 1.0.0</a>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div class="loading-overlay hidden" id="loadingOverlay">
        <div class="loading-content">
            <div class="loading-spinner-large"></div>
            <div class="loading-text">Authenticating...</div>
        </div>
    </div>

    <!-- Scripts -->
    <script>
        // Pass PHP data to JavaScript (simplified)
        window.POS_CONFIG = {
            currency: '<?php echo $currency; ?>',
            apiEndpoint: '/pos/api/auth/pin_login.php'
        };
    </script>
    <script src="/pos/assets/js/pos-login.js"></script>
</body>
</html>