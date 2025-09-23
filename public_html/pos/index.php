<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SME 180 POS - Enhanced Modern Interface</title>
    <style>
        /* Microsoft 365 / Fluent Design System Inspired Styles */
        :root {
            --ms-blue: #0078d4;
            --ms-dark-blue: #106ebe;
            --ms-light-blue: #e3f2fd;
            --ms-green: #107c10;
            --ms-red: #d83b01;
            --ms-orange: #ff8c00;
            --ms-yellow: #ffb900;
            --ms-purple: #5c2d91;
            --primary: #667eea;
            --primary-dark: #5a67d8;
            --secondary: #764ba2;
            --ms-gray-10: #faf9f8;
            --ms-gray-20: #f3f2f1;
            --ms-gray-30: #edebe9;
            --ms-gray-40: #e1dfdd;
            --ms-gray-50: #d2d0ce;
            --ms-gray-60: #c8c6c4;
            --ms-gray-70: #a19f9d;
            --ms-gray-80: #605e5c;
            --ms-gray-90: #323130;
            --shadow-depth1: 0 1px 2px rgba(0,0,0,0.06), 0 1px 3px rgba(0,0,0,0.1);
            --shadow-depth2: 0 2px 4px rgba(0,0,0,0.06), 0 2px 6px rgba(0,0,0,0.1);
            --shadow-depth3: 0 4px 8px rgba(0,0,0,0.08), 0 4px 12px rgba(0,0,0,0.12);
            --shadow-depth4: 0 8px 16px rgba(0,0,0,0.1), 0 8px 24px rgba(0,0,0,0.14);
            --font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, 'Roboto', 'Helvetica Neue', sans-serif;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: var(--font-family);
            background: #f8f9fa;
            height: 100vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            color: var(--ms-gray-90);
        }

        /* Modern Header */
        .app-header {
            background: white;
            height: 52px;
            display: flex;
            align-items: center;
            padding: 0 20px;
            border-bottom: 1px solid rgba(0,0,0,0.08);
            z-index: 100;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .burger-menu {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border-radius: 8px;
            transition: all 0.2s;
        }

        .burger-menu:hover {
            background: var(--ms-gray-20);
        }

        .burger-icon {
            width: 18px;
            height: 14px;
            position: relative;
        }

        .burger-icon span {
            display: block;
            height: 2px;
            background: var(--ms-gray-80);
            position: absolute;
            width: 100%;
            border-radius: 2px;
            transition: 0.3s;
        }

        .burger-icon span:nth-child(1) { top: 0; }
        .burger-icon span:nth-child(2) { top: 6px; }
        .burger-icon span:nth-child(3) { bottom: 0; }

        .app-logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        /* Animated Logo */
        .logo-icon {
            width: 40px;
            height: 40px;
            position: relative;
        }

        .logo-svg {
            width: 100%;
            height: 100%;
        }

        @keyframes rot-ccw {
            from { transform: rotate(0deg); }
            to { transform: rotate(-360deg); }
        }

        @keyframes rot-cw {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.15); opacity: 0.9; }
        }

        .logo-text {
            font-size: 20px;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .header-center {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .quick-stats {
            display: flex;
            gap: 32px;
        }

        .stat {
            text-align: center;
        }

        .stat-value {
            font-size: 20px;
            font-weight: 700;
            color: var(--ms-gray-90);
        }

        .stat-label {
            font-size: 11px;
            color: var(--ms-gray-60);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-badge {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 6px 12px;
            background: linear-gradient(135deg, #f0f0f0, #e8e8e8);
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .user-badge:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-depth2);
        }

        .user-avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--ms-purple), var(--ms-blue));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 600;
        }

        .user-name {
            font-size: 13px;
            font-weight: 500;
            color: var(--ms-gray-90);
        }

        /* Main Layout */
        .main-container {
            flex: 1;
            display: flex;
            overflow: hidden;
            background: #f8f9fa;
        }

        /* Products Panel */
        .products-panel {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        /* Search and Category Bar */
        .top-controls {
            background: white;
            padding: 12px 16px;
            border-bottom: 1px solid rgba(0,0,0,0.06);
        }

        /* Search Box - Now on top */
        .search-container {
            margin-bottom: 12px;
            display: flex;
            justify-content: center;
        }

        .search-box {
            position: relative;
            width: 360px;
            max-width: 100%;
        }

        .search-input {
            width: 100%;
            padding: 10px 14px 10px 36px;
            border: 2px solid var(--ms-gray-30);
            border-radius: 24px;
            font-size: 14px;
            background: var(--ms-gray-10);
            transition: all 0.2s;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            background: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 16px;
            height: 16px;
            opacity: 0.5;
        }

        /* Categories - Centered */
        .category-tabs {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            justify-content: center;
            padding: 0 8px;
            scrollbar-width: thin;
        }

        .category-tabs::-webkit-scrollbar {
            height: 4px;
        }

        .category-tabs::-webkit-scrollbar-thumb {
            background: var(--ms-gray-40);
            border-radius: 2px;
        }

        .category-tab {
            padding: 10px 20px;
            background: transparent;
            border: 2px solid transparent;
            border-radius: 24px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            color: var(--ms-gray-70);
            white-space: nowrap;
            transition: all 0.2s;
        }

        .category-tab:hover {
            background: var(--ms-gray-20);
            border-color: var(--ms-gray-30);
            transform: translateY(-1px);
        }

        .category-tab.active {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
            border-color: transparent;
        }

        /* Products Grid - 5 cards with TIGHT vertical spacing */
        .products-grid {
            flex: 1;
            padding: 6px 8px 8px 8px;
            overflow-y: auto;
            overflow-x: hidden;
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            grid-auto-rows: min-content;
            gap: 2px 6px; /* MINIMAL 2px vertical, 6px horizontal */
        }

        /* Product Card - Compact design */
        .product-card {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid #e5e7eb;
            position: relative;
            padding: 12px;
            height: 95px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            text-align: center;
        }

        .product-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.08);
            border-color: var(--primary);
            background: linear-gradient(to bottom, #ffffff, #fafbfd);
        }

        .product-card:active {
            transform: translateY(0);
        }

        .product-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 40px;
            height: 2px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .product-card:hover::before {
            opacity: 1;
        }

        .product-category {
            font-size: 11px;
            font-weight: 600;
            color: var(--ms-gray-60);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 3px;
        }

        .product-name {
            font-size: 14px;
            font-weight: 600;
            color: var(--ms-gray-90);
            line-height: 1.2;
            margin-bottom: 4px;
        }

        .product-price {
            font-size: 17px;
            font-weight: 700;
            color: var(--ms-gray-90);
        }

        /* Order Panel - Wider for better visibility */
        .order-panel {
            width: 420px;
            background: white;
            display: flex;
            flex-direction: column;
            border-left: 1px solid rgba(0,0,0,0.08);
        }

        /* Order Header - Compact single line */
        .order-header {
            padding: 10px 14px;
            background: linear-gradient(135deg, #f8f9fa, #ffffff);
            border-bottom: 1px solid rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .order-info {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
            min-width: 0;
        }

        .order-number {
            font-size: 15px;
            font-weight: 700;
            color: var(--ms-gray-90);
        }

        .order-meta {
            display: flex;
            gap: 6px;
            align-items: center;
            font-size: 11px;
            color: var(--ms-gray-60);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .edit-order-btn {
            padding: 6px 14px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border: none;
            border-radius: 16px;
            cursor: pointer;
            font-size: 11px;
            font-weight: 600;
            color: white;
            transition: all 0.2s;
            white-space: nowrap;
        }

        .edit-order-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
        }

        /* Order Items - Better spacing and size */
        .order-items {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 6px 8px;
        }

        .order-items::-webkit-scrollbar {
            width: 4px;
        }

        .order-items::-webkit-scrollbar-thumb {
            background: var(--ms-gray-40);
            border-radius: 2px;
        }

        /* Order Item Cards - LARGER and more readable */
        .order-item {
            border-radius: 10px;
            margin-bottom: 5px;
            position: relative;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: white;
            border: 1px solid var(--ms-gray-30);
            min-height: 70px;
        }

        .order-item.pending {
            background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
            border-left: 3px solid #2196f3;
        }

        .order-item.fired {
            background: linear-gradient(135deg, #fffbeb, #fef3c7);
            border-left: 3px solid var(--ms-orange);
        }

        .order-item.voided {
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            border-left: 3px solid var(--ms-red);
            opacity: 0.6;
        }

        .order-item:hover {
            transform: translateX(-3px);
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }

        .swipe-container {
            position: relative;
            transition: transform 0.3s ease;
        }

        .swipe-actions {
            position: absolute;
            right: 0;
            top: 0;
            bottom: 0;
            width: 240px;
            display: flex;
            align-items: stretch;
            transform: translateX(100%);
        }

        .swipe-action {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 10px;
            cursor: pointer;
            transition: all 0.2s;
            flex-direction: column;
            gap: 2px;
        }

        .action-qty {
            background: linear-gradient(135deg, var(--ms-blue), #0056b3);
            display: flex;
            align-items: center;
            gap: 4px;
            flex-direction: row;
        }

        .qty-btn {
            width: 22px;
            height: 22px;
            border: 1px solid rgba(255,255,255,0.3);
            background: rgba(255,255,255,0.2);
            color: white;
            cursor: pointer;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
        }

        .qty-btn:hover {
            background: rgba(255,255,255,0.3);
        }

        .qty-display {
            min-width: 20px;
            text-align: center;
            font-size: 12px;
            font-weight: 600;
        }

        .action-discount {
            background: linear-gradient(135deg, var(--ms-purple), #8e24aa);
        }

        .action-void {
            background: linear-gradient(135deg, var(--ms-red), #c62828);
        }

        .order-item.swiped .swipe-container {
            transform: translateX(-240px);
        }

        /* Item Content - Larger and more readable */
        .item-content {
            padding: 11px 14px;
            background: rgba(255,255,255,0.95);
            position: relative;
            z-index: 1;
        }

        .item-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 6px;
        }

        .item-row:last-child {
            margin-bottom: 0;
        }

        .item-name {
            font-size: 14px;
            font-weight: 700;
            color: var(--ms-gray-90);
            flex: 1;
        }

        .item-price {
            font-size: 15px;
            font-weight: 700;
            color: var(--ms-gray-90);
            margin-left: 10px;
        }

        .item-qty {
            display: inline-block;
            background: var(--primary);
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 10px;
        }

        .item-modifiers {
            font-size: 11px;
            color: var(--ms-gray-70);
            background: rgba(102, 126, 234, 0.08);
            padding: 3px 8px;
            border-radius: 6px;
            margin-bottom: 3px;
        }

        .item-notes {
            font-size: 10px;
            color: var(--ms-gray-70);
            font-style: italic;
            background: rgba(255, 193, 7, 0.08);
            padding: 3px 8px;
            border-radius: 5px;
            border-left: 2px solid #ffc107;
        }

        /* Footer Actions */
        .order-footer {
            background: linear-gradient(135deg, #f8f9fa, #ffffff);
            border-top: 1px solid rgba(0,0,0,0.08);
        }

        .action-grid {
            padding: 8px;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 5px;
        }

        .action-btn {
            padding: 10px 4px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 10px;
            font-weight: 600;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 3px;
            position: relative;
            overflow: hidden;
        }

        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-depth2);
        }

        .btn-park {
            background: linear-gradient(135deg, var(--ms-gray-60), var(--ms-gray-70));
            color: white;
        }

        .btn-fire-all {
            background: linear-gradient(135deg, var(--ms-yellow), #ff9800);
            color: white;
        }

        .btn-print {
            background: linear-gradient(135deg, #6c757d, #495057);
            color: white;
        }

        .btn-split {
            background: linear-gradient(135deg, var(--ms-blue), #0056b3);
            color: white;
        }

        .btn-notes {
            background: linear-gradient(135deg, var(--ms-purple), #8e24aa);
            color: white;
        }

        .btn-more {
            background: linear-gradient(135deg, #e9ecef, #dee2e6);
            color: var(--ms-gray-80);
        }

        .btn-pay {
            grid-column: span 3;
            background: linear-gradient(135deg, #4caf50, #45a049);
            color: white;
            font-size: 16px;
            padding: 14px;
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(76,175,80,0.3);
        }

        .action-icon {
            font-size: 18px;
        }

        /* Open Orders Bar */
        .orders-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 420px;
            background: white;
            padding: 8px 12px;
            border-top: 1px solid rgba(0,0,0,0.08);
            display: flex;
            gap: 8px;
            align-items: center;
            z-index: 50;
            box-shadow: 0 -4px 12px rgba(0,0,0,0.05);
        }

        .orders-scroll {
            flex: 1;
            display: flex;
            gap: 6px;
            overflow-x: auto;
        }

        .orders-scroll::-webkit-scrollbar {
            height: 4px;
        }

        .orders-scroll::-webkit-scrollbar-thumb {
            background: var(--ms-gray-40);
            border-radius: 2px;
        }

        .order-tab {
            padding: 6px 12px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border: 1px solid rgba(0,0,0,0.08);
            border-radius: 18px;
            cursor: pointer;
            font-size: 11px;
            transition: all 0.2s;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .order-tab:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-depth2);
        }

        .order-tab.active {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border-color: transparent;
        }

        .order-tab-icon {
            font-size: 13px;
        }

        .order-tab-text {
            display: flex;
            flex-direction: column;
            gap: 1px;
        }

        .order-tab-title {
            font-weight: 600;
        }

        .order-tab-amount {
            font-size: 10px;
            opacity: 0.9;
        }

        .new-order-btn {
            padding: 8px 16px;
            background: linear-gradient(135deg, #4caf50, #45a049);
            color: white;
            border: none;
            border-radius: 18px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.2s;
            box-shadow: 0 2px 8px rgba(76,175,80,0.3);
            white-space: nowrap;
        }

        .new-order-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(76,175,80,0.4);
        }

        /* Side Menu */
        .side-menu {
            position: fixed;
            top: 0;
            left: -300px;
            width: 300px;
            height: 100%;
            background: white;
            box-shadow: var(--shadow-depth4);
            transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1500;
        }

        .side-menu.open {
            left: 0;
        }

        .menu-header {
            padding: 24px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }

        .menu-items {
            padding: 16px 0;
        }

        .menu-item {
            padding: 14px 24px;
            display: flex;
            align-items: center;
            gap: 16px;
            cursor: pointer;
            transition: background 0.2s;
            font-size: 14px;
            color: var(--ms-gray-80);
        }

        .menu-item:hover {
            background: var(--ms-gray-20);
            color: var(--ms-gray-90);
        }

        .menu-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.4);
            display: none;
            z-index: 1400;
        }

        .menu-overlay.show {
            display: block;
        }

        /* Mobile Responsive Styles */
        @media (max-width: 1024px) {
            .order-panel {
                width: 360px;
            }

            .orders-bar {
                right: 360px;
            }

            .products-grid {
                grid-template-columns: repeat(4, 1fr);
            }

            .quick-stats {
                gap: 20px;
            }

            .stat-value {
                font-size: 18px;
            }
        }

        @media (max-width: 768px) {
            .app-header {
                padding: 0 12px;
            }

            .header-center {
                display: none;
            }

            .logo-text {
                display: none;
            }

            .main-container {
                flex-direction: column;
            }

            .order-panel {
                width: 100%;
                height: 50%;
                border-left: none;
                border-top: 2px solid rgba(0,0,0,0.08);
            }

            .products-panel {
                height: 50%;
            }

            .orders-bar {
                display: none;
            }

            .products-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 4px 6px;
                padding: 6px;
            }

            .product-card {
                height: 85px;
                padding: 10px;
            }

            .product-category {
                font-size: 10px;
            }

            .product-name {
                font-size: 12px;
            }

            .product-price {
                font-size: 15px;
            }

            .category-tabs {
                justify-content: flex-start;
                overflow-x: auto;
            }

            .search-box {
                width: 100%;
            }

            .order-header {
                padding: 8px 10px;
            }

            .order-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 2px;
            }

            .order-meta {
                font-size: 10px;
            }

            .action-grid {
                gap: 4px;
                padding: 6px;
            }

            .action-btn {
                padding: 8px 4px;
                font-size: 9px;
            }

            .action-icon {
                font-size: 16px;
            }

            .btn-pay {
                font-size: 14px;
                padding: 12px;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="app-header">
        <div class="header-left">
            <div class="burger-menu" onclick="toggleMenu()">
                <div class="burger-icon">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
            </div>
            <div class="app-logo">
                <div class="logo-icon">
                    <svg class="logo-svg" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
                        <defs>
                            <linearGradient id="gradPrimary" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#667eea"/>
                                <stop offset="100%" stop-color="#764ba2"/>
                            </linearGradient>
                            <radialGradient id="gradOrb" cx="30%" cy="30%" r="70%">
                                <stop offset="0%" stop-color="#667eea"/>
                                <stop offset="100%" stop-color="#764ba2"/>
                            </radialGradient>
                        </defs>
                        <circle style="animation: rot-ccw 6s linear infinite; transform-origin: 100px 100px;"
                                cx="100" cy="100" r="75"
                                fill="none" stroke="url(#gradPrimary)" stroke-width="8" stroke-linecap="round"
                                stroke-dasharray="235 235" transform="rotate(-45 100 100)"/>
                        <circle style="animation: rot-cw 5s linear infinite; transform-origin: 100px 100px;"
                                cx="100" cy="100" r="52"
                                fill="none" stroke="#764ba2" stroke-width="8" stroke-linecap="round"
                                stroke-dasharray="163 163" transform="rotate(135 100 100)"/>
                        <circle style="animation: pulse 2.2s ease-in-out infinite; transform-origin: 100px 100px;"
                                cx="100" cy="100" r="30" fill="url(#gradOrb)"/>
                    </svg>
                </div>
                <div class="logo-text">SME 180</div>
            </div>
        </div>
        <div class="header-center">
            <div class="quick-stats">
                <div class="stat">
                    <div class="stat-value">127</div>
                    <div class="stat-label">Orders Today</div>
                </div>
                <div class="stat">
                    <div class="stat-value">8,542</div>
                    <div class="stat-label">Revenue</div>
                </div>
                <div class="stat">
                    <div class="stat-value">14:22</div>
                    <div class="stat-label">Avg Time</div>
                </div>
            </div>
        </div>
        <div class="header-right">
            <div class="user-badge">
                <div class="user-avatar">JD</div>
                <span class="user-name">John Doe</span>
            </div>
        </div>
    </div>

    <!-- Main Container -->
    <div class="main-container">
        <!-- Products Panel -->
        <div class="products-panel">
            <!-- Search and Category Bar -->
            <div class="top-controls">
                <!-- Search Box on top -->
                <div class="search-container">
                    <div class="search-box">
                        <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        <input type="text" class="search-input" placeholder="Search products..." oninput="filterProducts(this.value)">
                    </div>
                </div>
                <!-- Categories centered below -->
                <div class="category-tabs">
                    <button class="category-tab active">All Items</button>
                    <button class="category-tab">Appetizers</button>
                    <button class="category-tab">Main Dishes</button>
                    <button class="category-tab">Beverages</button>
                    <button class="category-tab">Desserts</button>
                    <button class="category-tab">Sides</button>
                </div>
            </div>

            <!-- Products Grid -->
            <div class="products-grid" id="productsGrid">
                <div class="product-card" onclick="showVariations('Grilled Chicken', 18.99)">
                    <div class="product-category">Main Course</div>
                    <div class="product-name">Grilled Chicken Breast</div>
                    <div class="product-price">18.99</div>
                </div>
                <div class="product-card" onclick="showVariations('Caesar Salad', 12.99)">
                    <div class="product-category">Starter</div>
                    <div class="product-name">Caesar Salad</div>
                    <div class="product-price">12.99</div>
                </div>
                <div class="product-card" onclick="showVariations('Margherita Pizza', 15.99)">
                    <div class="product-category">Artisan Pizza</div>
                    <div class="product-name">Margherita</div>
                    <div class="product-price">15.99</div>
                </div>
                <div class="product-card" onclick="showVariations('Beef Burger', 14.99)">
                    <div class="product-category">Signature</div>
                    <div class="product-name">Wagyu Beef Burger</div>
                    <div class="product-price">14.99</div>
                </div>
                <div class="product-card" onclick="showVariations('French Fries', 5.99)">
                    <div class="product-category">Sides</div>
                    <div class="product-name">Truffle Fries</div>
                    <div class="product-price">5.99</div>
                </div>
                <div class="product-card" onclick="showVariations('Coca Cola', 3.99)">
                    <div class="product-category">Beverage</div>
                    <div class="product-name">Artisan Cola</div>
                    <div class="product-price">3.99</div>
                </div>
                <div class="product-card" onclick="showVariations('Chocolate Cake', 7.99)">
                    <div class="product-category">Dessert</div>
                    <div class="product-name">Chocolate Soufflé</div>
                    <div class="product-price">7.99</div>
                </div>
                <div class="product-card" onclick="showVariations('Fish Tacos', 13.99)">
                    <div class="product-category">Seafood</div>
                    <div class="product-name">Mahi Mahi Tacos</div>
                    <div class="product-price">13.99</div>
                </div>
            </div>
        </div>

        <!-- Order Panel -->
        <div class="order-panel">
            <!-- Order Header - Streamlined -->
            <div class="order-header">
                <div class="order-info">
                    <span class="order-number">Order #1024</span>
                    <span class="order-meta">Table 5 • 2 Guests • 10:45 AM</span>
                </div>
                <button class="edit-order-btn" onclick="editOrder()">Edit</button>
            </div>

            <!-- Order Items -->
            <div class="order-items" id="orderItems">
                <!-- Item 1 - Grilled Chicken -->
                <div class="order-item pending" onclick="toggleSwipe(this)">
                    <div class="swipe-container">
                        <div class="swipe-actions">
                            <div class="swipe-action action-qty">
                                <button class="qty-btn" onclick="event.stopPropagation(); updateQty(this, -1)">−</button>
                                <span class="qty-display">1</span>
                                <button class="qty-btn" onclick="event.stopPropagation(); updateQty(this, 1)">+</button>
                            </div>
                            <div class="swipe-action action-discount">
                                <span>%</span>
                                <span>Discount</span>
                            </div>
                            <div class="swipe-action action-void">
                                <span>✕</span>
                                <span>Void</span>
                            </div>
                        </div>
                        <div class="item-content">
                            <div class="item-row">
                                <span class="item-name">Grilled Chicken</span>
                                <span class="item-qty">Qty: 1</span>
                                <span class="item-price">18.99</span>
                            </div>
                            <div class="item-modifiers">Large (+2.00) • Medium Spice</div>
                            <div class="item-notes">Make it well done, customer is allergic to...</div>
                        </div>
                    </div>
                </div>

                <!-- Item 2 - Caesar Salad -->
                <div class="order-item fired" onclick="toggleSwipe(this)">
                    <div class="swipe-container">
                        <div class="swipe-actions">
                            <div class="swipe-action action-qty">
                                <button class="qty-btn" onclick="event.stopPropagation(); updateQty(this, -1)">−</button>
                                <span class="qty-display">2</span>
                                <button class="qty-btn" onclick="event.stopPropagation(); updateQty(this, 1)">+</button>
                            </div>
                            <div class="swipe-action action-discount">
                                <span>%</span>
                                <span>Discount</span>
                            </div>
                            <div class="swipe-action action-void">
                                <span>✕</span>
                                <span>Void</span>
                            </div>
                        </div>
                        <div class="item-content">
                            <div class="item-row">
                                <span class="item-name">Caesar Salad</span>
                                <span class="item-qty">Qty: 2</span>
                                <span class="item-price">25.98</span>
                            </div>
                            <div class="item-modifiers">No Croutons • Extra Dressing</div>
                        </div>
                    </div>
                </div>

                <!-- Item 3 - Margherita Pizza -->
                <div class="order-item pending" onclick="toggleSwipe(this)">
                    <div class="swipe-container">
                        <div class="swipe-actions">
                            <div class="swipe-action action-qty">
                                <button class="qty-btn" onclick="event.stopPropagation(); updateQty(this, -1)">−</button>
                                <span class="qty-display">1</span>
                                <button class="qty-btn" onclick="event.stopPropagation(); updateQty(this, 1)">+</button>
                            </div>
                            <div class="swipe-action action-discount">
                                <span>%</span>
                                <span>Discount</span>
                            </div>
                            <div class="swipe-action action-void">
                                <span>✕</span>
                                <span>Void</span>
                            </div>
                        </div>
                        <div class="item-content">
                            <div class="item-row">
                                <span class="item-name">Margherita Pizza</span>
                                <span class="item-qty">Qty: 1</span>
                                <span class="item-price">15.99</span>
                            </div>
                            <div class="item-modifiers">Extra Cheese • Thin Crust</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer Actions -->
            <div class="order-footer">
                <div class="action-grid">
                    <button class="action-btn btn-park">
                        <span class="action-icon">P</span>
                        <span>Park</span>
                    </button>
                    <button class="action-btn btn-fire-all">
                        <span class="action-icon">🔥</span>
                        <span>Fire All</span>
                    </button>
                    <button class="action-btn btn-print">
                        <span class="action-icon">🖨</span>
                        <span>Print</span>
                    </button>
                    <button class="action-btn btn-split">
                        <span class="action-icon">✂</span>
                        <span>Split</span>
                    </button>
                    <button class="action-btn btn-notes">
                        <span class="action-icon">📝</span>
                        <span>Notes</span>
                    </button>
                    <button class="action-btn btn-more">
                        <span class="action-icon">⋯</span>
                        <span>More</span>
                    </button>
                    <button class="action-btn btn-pay" onclick="showPayment()">
                        <span>Pay 60.96</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Open Orders Bar -->
    <div class="orders-bar">
        <div class="orders-scroll">
            <div class="order-tab active">
                <span class="order-tab-icon">🍽️</span>
                <div class="order-tab-text">
                    <span class="order-tab-title">Table 5</span>
                    <span class="order-tab-amount">60.96</span>
                </div>
            </div>
            <div class="order-tab">
                <span class="order-tab-icon">🍽️</span>
                <div class="order-tab-text">
                    <span class="order-tab-title">Table 3</span>
                    <span class="order-tab-amount">45.50</span>
                </div>
            </div>
            <div class="order-tab">
                <span class="order-tab-icon">🛍️</span>
                <div class="order-tab-text">
                    <span class="order-tab-title">Take #15</span>
                    <span class="order-tab-amount">22.00</span>
                </div>
            </div>
            <div class="order-tab">
                <span class="order-tab-icon">🏍️</span>
                <div class="order-tab-text">
                    <span class="order-tab-title">Delivery #8</span>
                    <span class="order-tab-amount">38.90</span>
                </div>
            </div>
        </div>
        <button class="new-order-btn" onclick="showNewOrderModal()">+ New Order</button>
    </div>

    <!-- Side Menu -->
    <div class="side-menu" id="sideMenu">
        <div class="menu-header">
            <h3>SME 180 POS</h3>
        </div>
        <div class="menu-items">
            <div class="menu-item">
                <span>📊</span>
                <span>Dashboard</span>
            </div>
            <div class="menu-item">
                <span>📋</span>
                <span>Orders</span>
            </div>
            <div class="menu-item">
                <span>🪑</span>
                <span>Tables</span>
            </div>
            <div class="menu-item">
                <span>👨‍🍳</span>
                <span>Kitchen</span>
            </div>
            <div class="menu-item">
                <span>📈</span>
                <span>Reports</span>
            </div>
            <div class="menu-item">
                <span>📦</span>
                <span>Inventory</span>
            </div>
            <div class="menu-item">
                <span>👥</span>
                <span>Customers</span>
            </div>
            <div class="menu-item">
                <span>⚙️</span>
                <span>Settings</span>
            </div>
        </div>
    </div>
    <div class="menu-overlay" id="menuOverlay" onclick="closeMenu()"></div>

    <script>
        let currentSwipedItem = null;
        let currentProduct = null;

        // Toggle menu
        function toggleMenu() {
            const menu = document.getElementById('sideMenu');
            const overlay = document.getElementById('menuOverlay');
            menu.classList.toggle('open');
            overlay.classList.toggle('show');
        }

        function closeMenu() {
            const menu = document.getElementById('sideMenu');
            const overlay = document.getElementById('menuOverlay');
            menu.classList.remove('open');
            overlay.classList.remove('show');
        }

        // Swipe functionality
        function toggleSwipe(element) {
            // Prevent any other swiped items from staying open
            if (currentSwipedItem && currentSwipedItem !== element) {
                currentSwipedItem.classList.remove('swiped');
            }
            
            // Toggle the current element
            element.classList.toggle('swiped');
            
            // Update the current swiped item reference
            currentSwipedItem = element.classList.contains('swiped') ? element : null;
        }

        // Update quantity
        function updateQty(button, change) {
            const display = button.parentElement.querySelector('.qty-display');
            let qty = parseInt(display.textContent);
            qty = Math.max(0, qty + change);
            display.textContent = qty;
            
            // Update the quantity display in the item card
            const itemElement = button.closest('.order-item');
            const qtyBadge = itemElement.querySelector('.item-qty');
            if (qtyBadge) {
                qtyBadge.textContent = `Qty: ${qty}`;
            }
            
            updateTotal();
        }

        // Show variations
        function showVariations(name, price) {
            currentProduct = { name, price };
            // Add item directly for demo
            addItem(name, price);
        }

        // Filter products
        function filterProducts(query) {
            const cards = document.querySelectorAll('.product-card');
            cards.forEach(card => {
                const name = card.querySelector('.product-name').textContent.toLowerCase();
                if (name.includes(query.toLowerCase())) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        // Add item
        function addItem(name, price) {
            const orderItems = document.getElementById('orderItems');
            
            const statusClass = 'pending';
            
            const newItem = document.createElement('div');
            newItem.className = `order-item ${statusClass}`;
            newItem.onclick = function() { toggleSwipe(this); };
            newItem.innerHTML = `
                <div class="swipe-container">
                    <div class="swipe-actions">
                        <div class="swipe-action action-qty">
                            <button class="qty-btn" onclick="event.stopPropagation(); updateQty(this, -1)">−</button>
                            <span class="qty-display">1</span>
                            <button class="qty-btn" onclick="event.stopPropagation(); updateQty(this, 1)">+</button>
                        </div>
                        <div class="swipe-action action-discount">
                            <span>%</span>
                            <span>Discount</span>
                        </div>
                        <div class="swipe-action action-void">
                            <span>✕</span>
                            <span>Void</span>
                        </div>
                    </div>
                    <div class="item-content">
                        <div class="item-row">
                            <span class="item-name">${name}</span>
                            <span class="item-qty">Qty: 1</span>
                            <span class="item-price">${price.toFixed(2)}</span>
                        </div>
                    </div>
                </div>
            `;
            orderItems.appendChild(newItem);
            updateTotal();
        }

        // Update total
        function updateTotal() {
            let total = 0;
            document.querySelectorAll('.order-item').forEach(item => {
                if (!item.classList.contains('voided')) {
                    const priceText = item.querySelector('.item-price').textContent;
                    const price = parseFloat(priceText);
                    total += price;
                }
            });
            document.querySelector('.btn-pay span').textContent = `Pay ${total.toFixed(2)}`;
        }

        // Show payment with discount
        function showPayment() {
            alert('Payment screen opens with discount options and payment methods');
        }

        // Edit order
        function editOrder() {
            alert('Edit Order: Change table, order type, merge tables, assign customer');
        }

        // Show new order modal
        function showNewOrderModal() {
            alert('Opening new order modal...');
        }

        // Close swipe on outside click
        document.addEventListener('click', function(e) {
            if (currentSwipedItem && !currentSwipedItem.contains(e.target)) {
                currentSwipedItem.classList.remove('swiped');
                currentSwipedItem = null;
            }
        });
        
        // Category tabs
        document.querySelectorAll('.category-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.category-tab').forEach(t => t.classList.remove('active'));
                this.classList.add('active');
            });
        });

        // Order tabs
        document.querySelectorAll('.order-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.order-tab').forEach(t => t.classList.remove('active'));
                this.classList.add('active');
            });
        });
    </script>
</body>
</html>