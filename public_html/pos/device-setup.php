<?php
/**
 * SME 180 POS - Device Setup Page
 * File: /public_html/pos/device-setup.php
 * Version: 2.1.0 - Production Ready with Reset Device Feature
 * 
 * First-time device setup for POS terminals
 * Handles restaurant code validation, user authentication, and branch selection
 */

declare(strict_types=1);
error_reporting(0);
ini_set('display_errors', '0');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if device reset is requested
if (isset($_GET['reset']) && $_GET['reset'] === '1') {
    // Clear all device data
    $_SESSION = [];
    
    // Clear localStorage via JavaScript (will be handled client-side)
    $clearLocalStorage = true;
    
    // Clear cookies
    $cookies = ['pos_device_token', 'pos_tenant_id', 'pos_tenant_code', 'pos_branch_id'];
    foreach ($cookies as $cookie) {
        setcookie($cookie, '', time() - 3600, '/');
    }
    
    // Show success message
    $resetSuccess = true;
} else {
    $clearLocalStorage = false;
    $resetSuccess = false;
}

// Check if already registered (but not if we're resetting)
$isRegistered = false;
if (!isset($_GET['reset']) && isset($_COOKIE['pos_device_token']) && isset($_COOKIE['pos_tenant_id'])) {
    // Verify the registration is still valid
    $deviceToken = $_COOKIE['pos_device_token'];
    $tenantId = $_COOKIE['pos_tenant_id'];
    $isRegistered = true;
    
    // Redirect to login if already registered
    header('Location: /pos/login.php');
    exit;
}

// Clear any existing session data if not registered
if (!$isRegistered) {
    $_SESSION = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Device Setup - SME 180 POS</title>
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, 'Inter', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #8B5CF6 0%, #7C3AED 25%, #6366F1 50%, #3B82F6 75%, #06B6D4 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }
        
        /* Copilot-style animated background */
        body::before {
            content: '';
            position: absolute;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle at 30% 107%, rgba(139, 92, 246, 0.5) 0%, transparent 40%),
                        radial-gradient(circle at 70% 10%, rgba(99, 102, 241, 0.4) 0%, transparent 40%),
                        radial-gradient(circle at 90% 60%, rgba(6, 182, 212, 0.3) 0%, transparent 40%);
            animation: copilotFlow 25s ease-in-out infinite;
        }
        
        body::after {
            content: '';
            position: absolute;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle at 10% 50%, rgba(59, 130, 246, 0.3) 0%, transparent 45%),
                        radial-gradient(circle at 80% 80%, rgba(139, 92, 246, 0.4) 0%, transparent 45%);
            animation: copilotFlow 30s ease-in-out infinite reverse;
        }
        
        /* Help Button (top right) */
        .help-button {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            display: flex;
            gap: 10px;
        }
        
        .help-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: white;
            text-decoration: none;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            border: 1px solid rgba(255, 255, 255, 0.3);
            transition: all 0.3s ease;
        }
        
        .help-link:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .help-link svg {
            width: 16px;
            height: 16px;
        }
        
        /* Floating light particles */
        .particle {
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
        }
        
        .particle1 {
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(139, 92, 246, 0.4) 0%, transparent 70%);
            top: -200px;
            right: -200px;
            animation: float1 20s infinite ease-in-out;
        }
        
        .particle2 {
            width: 350px;
            height: 350px;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.3) 0%, transparent 70%);
            bottom: -175px;
            left: -175px;
            animation: float2 25s infinite ease-in-out;
        }
        
        .particle3 {
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(6, 182, 212, 0.3) 0%, transparent 70%);
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            animation: float3 30s infinite ease-in-out;
        }
        
        @keyframes copilotFlow {
            0%, 100% {
                transform: translate(0, 0) scale(1) rotate(0deg);
            }
            25% {
                transform: translate(-30px, 30px) scale(1.1) rotate(90deg);
            }
            50% {
                transform: translate(30px, -30px) scale(0.9) rotate(180deg);
            }
            75% {
                transform: translate(-20px, -20px) scale(1.05) rotate(270deg);
            }
        }
        
        @keyframes float1 {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(-50px, 50px) scale(1.2); }
        }
        
        @keyframes float2 {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(50px, -30px) scale(0.9); }
        }
        
        @keyframes float3 {
            0%, 100% { transform: translate(-50%, -50%) scale(1); }
            50% { transform: translate(-45%, -55%) scale(1.1); }
        }
        
        /* Setup container with glass effect */
        .setup-container {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 18px;
            box-shadow: 0 32px 64px rgba(0, 0, 0, 0.15),
                        0 12px 24px rgba(0, 0, 0, 0.1),
                        inset 0 2px 0 rgba(255, 255, 255, 1);
            width: 100%;
            max-width: 420px;
            padding: 32px 36px;
            position: relative;
            z-index: 10;
            border: 1px solid rgba(255, 255, 255, 0.5);
            animation: slideUp 0.5s ease-out;
        }
        
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Logo and branding */
        .logo-container {
            text-align: center;
            margin-bottom: 24px;
            position: relative;
        }
        
        .logo-wrapper {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            position: relative;
        }
        
        .logo-icon {
            width: 36px;
            height: 36px;
            position: relative;
        }
        
        .logo-text {
            font-size: 32px;
            font-weight: 700;
            background: linear-gradient(135deg, #8B5CF6 0%, #6366F1 50%, #06B6D4 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -1px;
            position: relative;
            font-family: 'Segoe UI', sans-serif;
        }
        
        .logo-subtitle {
            color: #64748B;
            margin-top: 6px;
            font-size: 12px;
            font-weight: 500;
            letter-spacing: 0.2px;
        }
        
        /* Step indicator */
        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 20px;
            gap: 10px;
        }
        
        .step-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #E2E8F0;
            transition: all 0.3s ease;
        }
        
        .step-dot.active {
            background: linear-gradient(135deg, #8B5CF6, #6366F1);
            transform: scale(1.2);
            box-shadow: 0 2px 8px rgba(139, 92, 246, 0.4);
        }
        
        /* Form styling */
        .setup-step {
            animation: fadeIn 0.5s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .form-group {
            margin-bottom: 16px;
            position: relative;
        }
        
        .form-label {
            display: block;
            margin-bottom: 6px;
            color: #475569;
            font-weight: 600;
            font-size: 13px;
            letter-spacing: 0.1px;
        }
        
        .input-wrapper {
            position: relative;
        }
        
        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            width: 18px;
            height: 18px;
            stroke: #94A3B8;
            fill: none;
            z-index: 2;
            pointer-events: none;
            transition: stroke 0.3s ease;
        }
        
        .form-input {
            width: 100%;
            padding: 10px 14px 10px 42px;
            border: 2px solid #E2E8F0;
            border-radius: 10px;
            font-size: 14px;
            font-family: 'Segoe UI', sans-serif;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: #FFFFFF;
            position: relative;
            z-index: 1;
        }
        
        .form-input:hover {
            border-color: #CBD5E1;
        }
        
        .form-input:hover ~ .input-icon {
            stroke: #64748B;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #8B5CF6;
            box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.1),
                        0 2px 8px rgba(139, 92, 246, 0.08);
        }
        
        .form-input:focus ~ .input-icon {
            stroke: #8B5CF6;
        }
        
        .form-input::placeholder {
            color: #CBD5E1;
            font-size: 14px;
        }
        
        /* Branch selection */
        .branch-grid {
            display: grid;
            gap: 10px;
            max-height: 200px;
            overflow-y: auto;
            padding: 4px;
            margin-bottom: 16px;
        }
        
        .branch-grid::-webkit-scrollbar {
            width: 6px;
        }
        
        .branch-grid::-webkit-scrollbar-track {
            background: #F1F5F9;
            border-radius: 3px;
        }
        
        .branch-grid::-webkit-scrollbar-thumb {
            background: #CBD5E1;
            border-radius: 3px;
        }
        
        .branch-grid::-webkit-scrollbar-thumb:hover {
            background: #94A3B8;
        }
        
        .branch-item {
            padding: 12px;
            border: 2px solid #E2E8F0;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: #FFFFFF;
            position: relative;
        }
        
        .branch-item:hover {
            border-color: #8B5CF6;
            background: linear-gradient(to right, rgba(139, 92, 246, 0.05), rgba(99, 102, 241, 0.05));
            transform: translateX(4px);
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.1);
        }
        
        .branch-item.selected {
            border-color: #8B5CF6;
            background: linear-gradient(to right, rgba(139, 92, 246, 0.1), rgba(99, 102, 241, 0.1));
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.15);
        }
        
        .branch-item.selected::after {
            content: '✓';
            position: absolute;
            top: 10px;
            right: 10px;
            width: 20px;
            height: 20px;
            background: linear-gradient(135deg, #8B5CF6, #6366F1);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            animation: checkBounce 0.3s ease;
        }
        
        @keyframes checkBounce {
            0% { transform: scale(0); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }
        
        .branch-name {
            font-weight: 600;
            color: #1E293B;
            margin-bottom: 3px;
            font-size: 14px;
        }
        
        .branch-type {
            font-size: 12px;
            color: #64748B;
            text-transform: capitalize;
        }
        
        .branch-address {
            font-size: 11px;
            color: #94A3B8;
            margin-top: 3px;
        }
        
        /* Submit button */
        .btn {
            width: 100%;
            padding: 11px 20px;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            font-family: 'Segoe UI', sans-serif;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #8B5CF6 0%, #6366F1 50%, #3B82F6 100%);
            color: white;
            box-shadow: 0 4px 14px rgba(139, 92, 246, 0.3),
                        0 2px 4px rgba(139, 92, 246, 0.2);
        }
        
        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, 
                transparent, 
                rgba(255, 255, 255, 0.2), 
                transparent);
            transition: left 0.6s;
        }
        
        .btn-primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(139, 92, 246, 0.4),
                        0 3px 6px rgba(139, 92, 246, 0.3);
            background: linear-gradient(135deg, #9F6FF7 0%, #7376F2 50%, #4B8BF7 100%);
        }
        
        .btn-primary:hover::before {
            left: 100%;
        }
        
        .btn-primary:active:not(:disabled) {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(139, 92, 246, 0.3);
        }
        
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
        }
        
        /* Success state */
        .success-container {
            text-align: center;
            animation: scaleIn 0.5s ease-out;
        }
        
        @keyframes scaleIn {
            from { transform: scale(0.9); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        
        .success-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #10B981, #059669);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.3);
            animation: successPulse 1s ease-out;
        }
        
        @keyframes successPulse {
            0% { transform: scale(0); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
        .success-icon svg {
            width: 30px;
            height: 30px;
            stroke: white;
            stroke-width: 3;
        }
        
        .success-title {
            font-size: 20px;
            font-weight: 700;
            color: #1E293B;
            margin-bottom: 8px;
        }
        
        .success-text {
            color: #64748B;
            font-size: 13px;
            margin-bottom: 6px;
        }
        
        .success-details {
            color: #1E293B;
            font-weight: 600;
            font-size: 14px;
        }
        
        /* Loading spinner */
        .spinner {
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            display: inline-block;
            margin-left: 6px;
            vertical-align: middle;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Alerts */
        .alert {
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 13px;
            animation: slideIn 0.3s ease-out;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .alert-icon {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
        }
        
        .alert-error {
            background: linear-gradient(135deg, #FEE2E2, #FECACA);
            color: #991B1B;
            border: 1px solid #FCA5A5;
        }
        
        .alert-success {
            background: linear-gradient(135deg, #D1FAE5, #A7F3D0);
            color: #065F46;
            border: 1px solid #6EE7B7;
        }
        
        /* Loading overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        
        .loading-overlay.show {
            display: flex;
        }
        
        .loading-content {
            text-align: center;
        }
        
        .loading-spinner-large {
            width: 50px;
            height: 50px;
            border: 3px solid #E2E8F0;
            border-top: 3px solid;
            border-top-color: #8B5CF6;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 16px;
        }
        
        .loading-text {
            color: #64748B;
            font-size: 14px;
            font-weight: 500;
        }
        
        /* Responsive design */
        @media (max-width: 480px) {
            .setup-container {
                padding: 24px 20px;
                margin: 15px;
            }
            
            .logo-text {
                font-size: 28px;
            }
            
            .logo-icon {
                width: 32px;
                height: 32px;
            }
            
            .form-input {
                font-size: 16px;
            }
            
            .help-button {
                flex-direction: column;
                gap: 6px;
            }
            
            .help-link {
                padding: 8px 16px;
                font-size: 13px;
            }
        }
    </style>
</head>
<body>
    <!-- Help/Reset Button -->
    <div class="help-button">
        <?php if ($isRegistered || isset($_COOKIE['pos_device_token'])): ?>
        <a href="/pos/device-setup.php?reset=1" class="help-link" onclick="return confirm('This will reset the device registration. You will need to set up the device again. Continue?')">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
            </svg>
            Reset Device
        </a>
        <?php endif; ?>
        
        <a href="#" class="help-link" onclick="alert('For assistance, contact IT support at ext. 999 or email support@sme180.com'); return false;">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            Help
        </a>
    </div>
    
    <!-- Copilot-style floating particles -->
    <div class="particle particle1"></div>
    <div class="particle particle2"></div>
    <div class="particle particle3"></div>
    
    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="loading-content">
            <div class="loading-spinner-large"></div>
            <div class="loading-text" id="loadingText">Setting up device...</div>
        </div>
    </div>
    
    <!-- Main Setup Container -->
    <div class="setup-container">
        <!-- Logo -->
        <div class="logo-container">
            <div class="logo-wrapper">
                <div class="logo-icon">
                    <svg class="logo-svg" width="36" height="36" viewBox="0 0 200 200"
                         xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Animated SME 180 icon">
                        <defs>
                            <linearGradient id="gradOuter" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#8B5CF6"/>
                                <stop offset="100%" stop-color="#6366F1"/>
                            </linearGradient>
                            <radialGradient id="gradOrb" cx="30%" cy="30%" r="70%">
                                <stop offset="0%" stop-color="#8B5CF6"/>
                                <stop offset="100%" stop-color="#06B6D4"/>
                            </radialGradient>
                            <style><![CDATA[
                                .rot-ccw { animation: rot-ccw 6s linear infinite; transform-origin: 100px 100px; }
                                .rot-cw  { animation: rot-cw  5s linear infinite; transform-origin: 100px 100px; }
                                .pulse   { animation: pulse   2.2s ease-in-out infinite; transform-origin: 100px 100px; }
                                @keyframes rot-ccw { from { transform: rotate(0deg); } to { transform: rotate(-360deg); } }
                                @keyframes rot-cw  { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
                                @keyframes pulse   { 0%,100% { transform: scale(1); opacity: 1; }
                                                     50%     { transform: scale(1.15); opacity: 0.9; } }
                            ]]></style>
                        </defs>
                        <!-- Outer arc -->
                        <circle class="rot-ccw"
                                cx="100" cy="100" r="75"
                                fill="none" stroke="url(#gradOuter)" stroke-width="8" stroke-linecap="round"
                                stroke-dasharray="235 235" transform="rotate(-45 100 100)"/>
                        <!-- Inner arc -->
                        <circle class="rot-cw"
                                cx="100" cy="100" r="52"
                                fill="none" stroke="#06B6D4" stroke-width="8" stroke-linecap="round"
                                stroke-dasharray="163 163" transform="rotate(135 100 100)"/>
                        <!-- Center orb -->
                        <circle class="pulse" cx="100" cy="100" r="30" fill="url(#gradOrb)"/>
                    </svg>
                </div>
                <div class="logo-text">SME 180</div>
            </div>
            <p class="logo-subtitle">POS Device Configuration</p>
        </div>
        
        <!-- Step Indicator -->
        <div class="step-indicator">
            <div class="step-dot active" id="step1Dot"></div>
            <div class="step-dot" id="step2Dot"></div>
            <div class="step-dot" id="step3Dot"></div>
        </div>
        
        <!-- Alert Container -->
        <div id="alertContainer"></div>
        
        <!-- Step 1: Validation Form -->
        <div id="step1" class="setup-step">
            <h2 class="step-title"></h2>
            
            <form id="validationForm">
                <div class="form-group">
                    <label for="tenantCode" class="form-label">Restaurant Code</label>
                    <div class="input-wrapper">
                        <input 
                            type="text" 
                            name="tenantCode" 
                            id="tenantCode" 
                            class="form-input" 
                            placeholder="Enter restaurant code"
                            required 
                            autocomplete="off"
                            style="text-transform: uppercase;"
                        >
                        <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                        </svg>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="username" class="form-label">Username</label>
                    <div class="input-wrapper">
                        <input 
                            type="text" 
                            name="username" 
                            id="username" 
                            class="form-input" 
                            placeholder="Enter your username"
                            required 
                            autocomplete="off"
                        >
                        <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4" stroke-linecap="round" stroke-linejoin="round"></circle>
                        </svg>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="pin" class="form-label">PIN Code</label>
                    <div class="input-wrapper">
                        <input 
                            type="password" 
                            name="pin" 
                            id="pin" 
                            class="form-input" 
                            placeholder="Enter 4-6 digit PIN"
                            required 
                            maxlength="6" 
                            pattern="\d{4,6}"
                            inputmode="numeric" 
                            autocomplete="off"
                        >
                        <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2" stroke-linecap="round" stroke-linejoin="round"></rect>
                            <path d="M7 11V7a5 5 0 0110 0v4" stroke-linecap="round" stroke-linejoin="round"></path>
                        </svg>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary" id="validateBtn">
                    <span id="validateBtnText">VALIDATE SETUP</span>
                    <div class="spinner" id="validateSpinner" style="display: none;"></div>
                </button>
            </form>
        </div>
        
        <!-- Step 2: Branch Selection -->
        <div id="step2" class="setup-step" style="display: none;">
            <div id="branchList" class="branch-grid"></div>
            
            <button type="button" class="btn btn-primary" id="selectBranchBtn" disabled>
                <span id="selectBtnText">SELECT BRANCH</span>
                <div class="spinner" id="selectSpinner" style="display: none;"></div>
            </button>
        </div>
        
        <!-- Step 3: Success -->
        <div id="step3" class="setup-step" style="display: none;">
            <div class="success-container">
                <div class="success-icon">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                </div>
                <h2 class="success-title">Setup Complete!</h2>
                <p class="success-text">Device configured successfully</p>
                <div class="success-details">
                    <div id="tenantName"></div>
                    <div id="branchName"></div>
                </div>
                <p class="success-text" style="margin-top: 12px;">Redirecting to POS...</p>
            </div>
        </div>
    </div>
    
    <script>
    (function() {
        'use strict';
        
        // Check if we need to clear localStorage (device reset)
        <?php if ($clearLocalStorage): ?>
        // Clear all POS-related localStorage data
        const keysToRemove = [];
        for (let i = 0; i < localStorage.length; i++) {
            const key = localStorage.key(i);
            if (key && key.startsWith('pos_')) {
                keysToRemove.push(key);
            }
        }
        keysToRemove.forEach(key => localStorage.removeItem(key));
        
        // Show reset success message
        setTimeout(() => {
            showAlert('Device reset successful. Please configure the device again.', 'success');
        }, 500);
        <?php endif; ?>
        
        // State management
        let deviceData = {
            deviceToken: null,
            tenantId: null,
            tenantName: null,
            userId: null,
            userName: null,
            branches: [],
            selectedBranchId: null,
            selectedBranchName: null,
            deviceFingerprint: null,
            settings: {}
        };
        
        // Generate device fingerprint
        function generateFingerprint() {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            ctx.textBaseline = 'top';
            ctx.font = '14px Arial';
            ctx.fillText('fingerprint', 2, 2);
            const dataURL = canvas.toDataURL();
            
            const fingerprint = {
                screen: `${screen.width}x${screen.height}x${screen.colorDepth}`,
                timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                language: navigator.language,
                platform: navigator.platform,
                userAgent: navigator.userAgent,
                canvas: dataURL.substring(0, 50),
                timestamp: Date.now()
            };
            
            return btoa(JSON.stringify(fingerprint));
        }
        
        // Show alert
        function showAlert(message, type = 'error') {
            const alertContainer = document.getElementById('alertContainer');
            const alertId = 'alert_' + Date.now();
            
            const icons = {
                error: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>',
                success: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>'
            };
            
            const alertHtml = `
                <div id="${alertId}" class="alert alert-${type}">
                    <svg class="alert-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        ${icons[type] || icons.error}
                    </svg>
                    ${message}
                </div>
            `;
            
            alertContainer.innerHTML = alertHtml;
            
            // Auto-dismiss after 5 seconds
            setTimeout(() => {
                const alert = document.getElementById(alertId);
                if (alert) {
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 300);
                }
            }, 5000);
        }
        
        // Show loading
        function showLoading(show = true, text = 'Processing...') {
            const overlay = document.getElementById('loadingOverlay');
            const loadingText = document.getElementById('loadingText');
            
            if (show) {
                loadingText.textContent = text;
                overlay.classList.add('show');
            } else {
                overlay.classList.remove('show');
            }
        }
        
        // Update step indicator
        function updateStepIndicator(step) {
            document.querySelectorAll('.step-dot').forEach((dot, index) => {
                if (index < step) {
                    dot.classList.add('active');
                } else {
                    dot.classList.remove('active');
                }
            });
        }
        
        // Store device data
        function storeDeviceData() {
            // Store in localStorage
            localStorage.setItem('pos_device_token', deviceData.deviceToken);
            localStorage.setItem('pos_tenant_id', deviceData.tenantId.toString());
            localStorage.setItem('pos_tenant_name', deviceData.tenantName);
            localStorage.setItem('pos_tenant_code', document.getElementById('tenantCode').value.toUpperCase());
            localStorage.setItem('pos_branch_id', deviceData.selectedBranchId.toString());
            localStorage.setItem('pos_branch_name', deviceData.selectedBranchName);
            localStorage.setItem('pos_device_registered', 'true');
            localStorage.setItem('pos_device_registered_at', new Date().toISOString());
            localStorage.setItem('pos_settings', JSON.stringify(deviceData.settings));
            
            // Set cookies (30 days)
            const expires = new Date();
            expires.setDate(expires.getDate() + 30);
            const expiresStr = expires.toUTCString();
            
            document.cookie = `pos_device_token=${deviceData.deviceToken}; expires=${expiresStr}; path=/`;
            document.cookie = `pos_tenant_id=${deviceData.tenantId}; expires=${expiresStr}; path=/`;
            document.cookie = `pos_tenant_code=${document.getElementById('tenantCode').value.toUpperCase()}; expires=${expiresStr}; path=/`;
            document.cookie = `pos_branch_id=${deviceData.selectedBranchId}; expires=${expiresStr}; path=/`;
        }
        
        // Handle validation form submission
        document.getElementById('validationForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const tenantCode = document.getElementById('tenantCode').value.trim().toUpperCase();
            const username = document.getElementById('username').value.trim();
            const pin = document.getElementById('pin').value.trim();
            const validateBtn = document.getElementById('validateBtn');
            const btnText = document.getElementById('validateBtnText');
            const spinner = document.getElementById('validateSpinner');
            
            // Validate PIN format
            if (!/^\d{4,6}$/.test(pin)) {
                showAlert('PIN must be 4-6 digits');
                return;
            }
            
            // Generate fingerprint
            deviceData.deviceFingerprint = generateFingerprint();
            
            // Show loading state
            btnText.textContent = 'VALIDATING';
            spinner.style.display = 'inline-block';
            validateBtn.disabled = true;
            
            setTimeout(() => {
                showLoading(true, 'Validating restaurant setup...');
            }, 300);
            
            try {
                const response = await fetch('/pos/api/device/validate_setup.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        tenant_code: tenantCode,
                        username: username,
                        pin: pin,
                        device_fingerprint: deviceData.deviceFingerprint
                    })
                });
                
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.error || 'Validation failed');
                }
                
                // Store validation data
                deviceData.deviceToken = result.data.device.token;
                deviceData.tenantId = result.data.tenant.id;
                deviceData.tenantName = result.data.tenant.name;
                deviceData.userId = result.data.user.id;
                deviceData.userName = result.data.user.name;
                deviceData.branches = result.data.branches;
                deviceData.settings = result.data.settings || {};
                
                // Check if branch selection is needed
                if (deviceData.branches.length === 1) {
                    // Single branch - auto-select
                    deviceData.selectedBranchId = deviceData.branches[0].id;
                    deviceData.selectedBranchName = deviceData.branches[0].name;
                    
                    // Register device immediately
                    await registerDevice();
                } else {
                    // Multiple branches - show selection
                    showBranchSelection();
                }
                
            } catch (error) {
                console.error('Validation error:', error);
                showAlert(error.message || 'Failed to validate setup. Please try again.');
                
                // Reset button state
                btnText.textContent = 'VALIDATE SETUP';
                spinner.style.display = 'none';
                validateBtn.disabled = false;
            } finally {
                showLoading(false);
            }
        });
        
        // Show branch selection
        function showBranchSelection() {
            // Reset button state
            document.getElementById('validateBtnText').textContent = 'VALIDATE SETUP';
            document.getElementById('validateSpinner').style.display = 'none';
            document.getElementById('validateBtn').disabled = false;
            
            // Update UI
            document.getElementById('step1').style.display = 'none';
            document.getElementById('step2').style.display = 'block';
            updateStepIndicator(2);
            
            // Populate branches
            const branchList = document.getElementById('branchList');
            branchList.innerHTML = '';
            
            deviceData.branches.forEach(branch => {
                const branchItem = document.createElement('div');
                branchItem.className = 'branch-item';
                branchItem.dataset.branchId = branch.id;
                branchItem.dataset.branchName = branch.name;
                
                let html = `
                    <div class="branch-name">${branch.name}</div>
                    <div class="branch-type">${branch.type || 'Restaurant'}</div>
                `;
                
                if (branch.address) {
                    html += `<div class="branch-address">${branch.address}</div>`;
                }
                
                branchItem.innerHTML = html;
                
                branchItem.addEventListener('click', function() {
                    // Remove previous selection
                    document.querySelectorAll('.branch-item').forEach(item => {
                        item.classList.remove('selected');
                    });
                    
                    // Add selection
                    this.classList.add('selected');
                    
                    // Store selection
                    deviceData.selectedBranchId = parseInt(this.dataset.branchId);
                    deviceData.selectedBranchName = this.dataset.branchName;
                    
                    // Enable button
                    document.getElementById('selectBranchBtn').disabled = false;
                });
                
                branchList.appendChild(branchItem);
            });
        }
        
        // Handle branch selection
        document.getElementById('selectBranchBtn').addEventListener('click', async function() {
            if (!deviceData.selectedBranchId) {
                showAlert('Please select a branch');
                return;
            }
            
            const btnText = document.getElementById('selectBtnText');
            const spinner = document.getElementById('selectSpinner');
            
            btnText.textContent = 'CONFIGURING';
            spinner.style.display = 'inline-block';
            this.disabled = true;
            
            await registerDevice();
        });
        
        // Register device
        async function registerDevice() {
            showLoading(true, 'Registering device...');
            
            try {
                const response = await fetch('/pos/api/device/register.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        device_token: deviceData.deviceToken,
                        tenant_id: deviceData.tenantId,
                        branch_id: deviceData.selectedBranchId,
                        user_id: deviceData.userId,
                        device_name: 'POS Terminal',
                        device_fingerprint: deviceData.deviceFingerprint
                    })
                });
                
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.error || 'Registration failed');
                }
                
                // Store device data
                storeDeviceData();
                
                // Show success
                showSuccess();
                
            } catch (error) {
                console.error('Registration error:', error);
                showAlert(error.message || 'Failed to register device. Please try again.');
                
                // Reset button state
                document.getElementById('selectBtnText').textContent = 'SELECT BRANCH';
                document.getElementById('selectSpinner').style.display = 'none';
                document.getElementById('selectBranchBtn').disabled = false;
            } finally {
                showLoading(false);
            }
        }
        
        // Show success
        function showSuccess() {
            // Update UI
            document.getElementById('step1').style.display = 'none';
            document.getElementById('step2').style.display = 'none';
            document.getElementById('step3').style.display = 'block';
            updateStepIndicator(3);
            
            // Show tenant and branch info
            document.getElementById('tenantName').textContent = deviceData.tenantName;
            document.getElementById('branchName').textContent = deviceData.selectedBranchName;
            
            // Redirect after 2 seconds
            setTimeout(() => {
                window.location.href = '/pos/login.php';
            }, 2000);
        }
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Focus on first input
            document.getElementById('tenantCode').focus();
            
            // Uppercase tenant code as typed
            document.getElementById('tenantCode').addEventListener('input', function() {
                this.value = this.value.toUpperCase();
            });
            
            // Only allow numbers in PIN
            document.getElementById('pin').addEventListener('input', function() {
                this.value = this.value.replace(/[^\d]/g, '');
            });
        });
        
    })();
    </script>
</body>
</html>