/**
 * SME 180 POS - Login JavaScript
 * Path: /public_html/pos/assets/js/pos-login.js
 * 
 * Production-ready JavaScript for POS login interface
 */

(function() {
    'use strict';

    // Configuration
    const CONFIG = window.POS_CONFIG || {
        csrfToken: '',
        currency: 'EGP',
        apiEndpoint: '/pos/api/auth/pin_login.php',
        isLocked: false
    };

    // State management
    let currentMode = 'pin';
    let selectedStation = 'POS1';
    let pinLength = 4;
    let pinInputs = [];
    let managerPinInputs = [];
    let loginAttempts = 0;
    const MAX_ATTEMPTS = 5;

    // DOM Elements
    const elements = {
        loginForm: null,
        alertContainer: null,
        submitBtn: null,
        submitText: null,
        submitSpinner: null,
        loadingOverlay: null,
        clockTime: null,
        clockDate: null,
        stationCode: null,
        pinValue: null,
        managerPinValue: null,
        loginType: null,
        pinMode: null,
        managerMode: null,
        pinToggle: null
    };

    // Initialize on DOM ready
    document.addEventListener('DOMContentLoaded', init);

    /**
     * Initialize the application
     */
    function init() {
        // Cache DOM elements
        cacheElements();
        
        // Initialize components
        initializeClock();
        initializeModeTabs();
        initializeStationSelector();
        initializePinInputs();
        initializeKeypad();
        initializeForm();
        initializePinToggle();
        initializeFooterLinks();
        
        // Check for lockout
        checkLockout();
        
        // Focus first input
        focusFirstInput();
    }

    /**
     * Cache DOM elements
     */
    function cacheElements() {
        elements.loginForm = document.getElementById('loginForm');
        elements.alertContainer = document.getElementById('alertContainer');
        elements.submitBtn = document.getElementById('submitBtn');
        elements.submitText = document.getElementById('submitText');
        elements.submitSpinner = document.getElementById('submitSpinner');
        elements.loadingOverlay = document.getElementById('loadingOverlay');
        elements.clockTime = document.getElementById('clockTime');
        elements.clockDate = document.getElementById('clockDate');
        elements.stationCode = document.getElementById('stationCode');
        elements.pinValue = document.getElementById('pinValue');
        elements.managerPinValue = document.getElementById('managerPinValue');
        elements.loginType = document.getElementById('loginType');
        elements.pinMode = document.getElementById('pinMode');
        elements.managerMode = document.getElementById('managerMode');
        elements.pinToggle = document.getElementById('pinToggle');
    }

    /**
     * Initialize clock display
     */
    function initializeClock() {
        const updateClock = () => {
            const now = new Date();
            const timeOptions = { 
                hour: '2-digit', 
                minute: '2-digit', 
                second: '2-digit', 
                hour12: true 
            };
            const dateOptions = { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            };
            
            if (elements.clockTime) {
                elements.clockTime.textContent = now.toLocaleTimeString('en-US', timeOptions);
            }
            if (elements.clockDate) {
                elements.clockDate.textContent = now.toLocaleDateString('en-US', dateOptions);
            }
        };
        
        updateClock();
        setInterval(updateClock, 1000);
    }

    /**
     * Initialize mode tabs
     */
    function initializeModeTabs() {
        const tabs = document.querySelectorAll('.mode-tab');
        
        tabs.forEach(tab => {
            tab.addEventListener('click', function() {
                const mode = this.dataset.mode;
                switchMode(mode);
            });
        });
    }

    /**
     * Switch between login modes
     */
    function switchMode(mode) {
        currentMode = mode;
        
        // Update tabs
        document.querySelectorAll('.mode-tab').forEach(tab => {
            tab.classList.toggle('active', tab.dataset.mode === mode);
        });
        
        // Update form
        if (mode === 'pin') {
            elements.pinMode.classList.remove('hidden');
            elements.managerMode.classList.add('hidden');
            elements.loginType.value = 'pin';
            clearPin();
            focusFirstInput();
        } else if (mode === 'manager') {
            elements.pinMode.classList.add('hidden');
            elements.managerMode.classList.remove('hidden');
            elements.loginType.value = 'manager';
            clearManagerPin();
            focusFirstManagerInput();
        }
    }

    /**
     * Initialize station selector
     */
    function initializeStationSelector() {
        const stationCards = document.querySelectorAll('.station-card');
        
        stationCards.forEach(card => {
            card.addEventListener('click', function() {
                selectStation(this);
            });
        });
    }

    /**
     * Select a station
     */
    function selectStation(element) {
        // Update UI
        document.querySelectorAll('.station-card').forEach(card => {
            card.classList.remove('selected');
        });
        element.classList.add('selected');
        
        // Update state
        selectedStation = element.dataset.station;
        if (elements.stationCode) {
            elements.stationCode.value = selectedStation;
        }
    }

    /**
     * Initialize PIN inputs
     */
    function initializePinInputs() {
        // Regular PIN inputs
        pinInputs = document.querySelectorAll('.pin-input:not(.manager-pin)');
        pinInputs.forEach((input, index) => {
            input.addEventListener('input', function(e) {
                handlePinInput(this, index, pinInputs);
            });
            
            input.addEventListener('keydown', function(e) {
                handlePinKeydown(e, index, pinInputs);
            });
            
            input.addEventListener('paste', function(e) {
                handlePinPaste(e, pinInputs);
            });
        });
        
        // Manager PIN inputs
        managerPinInputs = document.querySelectorAll('.pin-input.manager-pin');
        managerPinInputs.forEach((input, index) => {
            input.addEventListener('input', function(e) {
                handlePinInput(this, index, managerPinInputs);
            });
            
            input.addEventListener('keydown', function(e) {
                handlePinKeydown(e, index, managerPinInputs);
            });
            
            input.addEventListener('paste', function(e) {
                handlePinPaste(e, managerPinInputs);
            });
        });
    }

    /**
     * Handle PIN input
     */
    function handlePinInput(element, index, inputs) {
        const value = element.value;
        
        // Only allow numbers
        if (!/^\d?$/.test(value)) {
            element.value = '';
            return;
        }
        
        // Update visual state
        element.classList.toggle('filled', value !== '');
        
        // Move to next input
        if (value && index < inputs.length - 1) {
            inputs[index + 1].focus();
        }
        
        // Update hidden PIN value
        updatePinValue(inputs);
        
        // Auto-submit if all digits entered
        if (currentMode === 'pin' && getPinValue(pinInputs).length === pinLength) {
            setTimeout(() => {
                elements.loginForm.dispatchEvent(new Event('submit'));
            }, 100);
        }
    }

    /**
     * Handle PIN keydown
     */
    function handlePinKeydown(e, index, inputs) {
        // Handle backspace
        if (e.key === 'Backspace' && !inputs[index].value && index > 0) {
            e.preventDefault();
            inputs[index - 1].focus();
            inputs[index - 1].value = '';
            inputs[index - 1].classList.remove('filled');
            updatePinValue(inputs);
        }
        
        // Handle arrow keys
        if (e.key === 'ArrowLeft' && index > 0) {
            e.preventDefault();
            inputs[index - 1].focus();
        }
        if (e.key === 'ArrowRight' && index < inputs.length - 1) {
            e.preventDefault();
            inputs[index + 1].focus();
        }
        
        // Prevent non-numeric input
        if (!/^\d$/.test(e.key) && !['Backspace', 'Tab', 'ArrowLeft', 'ArrowRight', 'Enter'].includes(e.key)) {
            e.preventDefault();
        }
    }

    /**
     * Handle PIN paste
     */
    function handlePinPaste(e, inputs) {
        e.preventDefault();
        const pastedData = (e.clipboardData || window.clipboardData).getData('text');
        const digits = pastedData.replace(/\D/g, '').split('');
        
        inputs.forEach((input, index) => {
            if (digits[index]) {
                input.value = digits[index];
                input.classList.add('filled');
            }
        });
        
        updatePinValue(inputs);
    }

    /**
     * Initialize keypad
     */
    function initializeKeypad() {
        const keypadButtons = document.querySelectorAll('.keypad-btn');
        
        keypadButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                const key = this.dataset.key;
                const action = this.dataset.action;
                
                if (key) {
                    appendToPin(key);
                } else if (action === 'clear') {
                    currentMode === 'pin' ? clearPin() : clearManagerPin();
                } else if (action === 'backspace') {
                    backspacePin();
                }
            });
        });
    }

    /**
     * Append digit to PIN
     */
    function appendToPin(digit) {
        const inputs = currentMode === 'pin' ? pinInputs : managerPinInputs;
        const maxLength = currentMode === 'pin' ? pinLength : 6;
        
        for (let i = 0; i < Math.min(inputs.length, maxLength); i++) {
            if (!inputs[i].value) {
                inputs[i].value = digit;
                inputs[i].classList.add('filled');
                if (i < inputs.length - 1 && i < maxLength - 1) {
                    inputs[i + 1].focus();
                }
                break;
            }
        }
        
        updatePinValue(inputs);
        
        // Auto-submit if PIN complete
        if (currentMode === 'pin' && getPinValue(pinInputs).length === pinLength) {
            setTimeout(() => {
                elements.loginForm.dispatchEvent(new Event('submit'));
            }, 100);
        }
    }

    /**
     * Clear PIN
     */
    function clearPin() {
        pinInputs.forEach(input => {
            input.value = '';
            input.classList.remove('filled', 'error');
        });
        updatePinValue(pinInputs);
        if (pinInputs[0]) {
            pinInputs[0].focus();
        }
    }

    /**
     * Clear Manager PIN
     */
    function clearManagerPin() {
        managerPinInputs.forEach(input => {
            input.value = '';
            input.classList.remove('filled', 'error');
        });
        updatePinValue(managerPinInputs);
        if (managerPinInputs[0]) {
            managerPinInputs[0].focus();
        }
    }

    /**
     * Backspace PIN
     */
    function backspacePin() {
        const inputs = currentMode === 'pin' ? pinInputs : managerPinInputs;
        
        for (let i = inputs.length - 1; i >= 0; i--) {
            if (inputs[i].value) {
                inputs[i].value = '';
                inputs[i].classList.remove('filled');
                inputs[i].focus();
                break;
            }
        }
        
        updatePinValue(inputs);
    }

    /**
     * Get PIN value
     */
    function getPinValue(inputs) {
        return Array.from(inputs).map(input => input.value).join('');
    }

    /**
     * Update hidden PIN value
     */
    function updatePinValue(inputs) {
        const value = getPinValue(inputs);
        
        if (inputs === pinInputs && elements.pinValue) {
            elements.pinValue.value = value;
        } else if (inputs === managerPinInputs && elements.managerPinValue) {
            elements.managerPinValue.value = value;
        }
    }

    /**
     * Initialize form submission
     */
    function initializeForm() {
        if (!elements.loginForm) return;
        
        elements.loginForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            await handleLogin();
        });
    }

    /**
     * Handle login
     */
    async function handleLogin() {
        // Check if locked
        if (CONFIG.isLocked) {
            showAlert('Account is temporarily locked. Please try again later.', 'error');
            return;
        }
        
        // Validate inputs
        const pin = currentMode === 'pin' 
            ? getPinValue(pinInputs) 
            : getPinValue(managerPinInputs);
            
        const expectedLength = currentMode === 'pin' ? pinLength : 6;
        
        if (pin.length !== expectedLength) {
            showAlert(`Please enter a ${expectedLength}-digit PIN`, 'error');
            currentMode === 'pin' ? clearPin() : clearManagerPin();
            return;
        }
        
        // Show loading state
        showLoading(true);
        
        try {
            // Prepare request data - NO CSRF needed for your system
            const data = {
                pin: pin, // Send raw PIN (not hashed)
                station_code: selectedStation
            };
            
            // Send login request
            const response = await fetch(CONFIG.apiEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(data)
            });
            
            const result = await response.json();
            
            if (result.success) {
                showAlert('Login successful! Redirecting...', 'success');
                
                // Store session data
                if (result.data) {
                    sessionStorage.setItem('pos_user', JSON.stringify(result.data.user));
                    sessionStorage.setItem('pos_station', selectedStation);
                }
                
                // Redirect
                setTimeout(() => {
                    window.location.href = result.redirect || '/pos/index.php';
                }, 1000);
            } else {
                // Handle error
                loginAttempts++;
                const attemptsLeft = MAX_ATTEMPTS - loginAttempts;
                
                if (attemptsLeft > 0) {
                    showAlert(`${result.error || 'Invalid PIN'}. ${attemptsLeft} attempts remaining.`, 'error');
                } else {
                    showAlert('Maximum attempts exceeded. Account locked for 15 minutes.', 'error');
                    CONFIG.isLocked = true;
                    elements.submitBtn.disabled = true;
                    setTimeout(() => {
                        CONFIG.isLocked = false;
                        elements.submitBtn.disabled = false;
                        loginAttempts = 0;
                    }, 15 * 60 * 1000); // 15 minutes
                }
                
                // Clear PIN
                currentMode === 'pin' ? clearPin() : clearManagerPin();
            }
        } catch (error) {
            console.error('Login error:', error);
            showAlert('Connection error. Please check your internet connection and try again.', 'error');
        } finally {
            showLoading(false);
        }
    }

    /**
     * Initialize PIN toggle
     */
    function initializePinToggle() {
        if (!elements.pinToggle) return;
        
        elements.pinToggle.addEventListener('click', function() {
            togglePinLength();
        });
    }

    /**
     * Toggle between 4 and 6 digit PIN
     */
    function togglePinLength() {
        const inputs = document.querySelectorAll('.pin-input:not(.manager-pin)');
        
        if (pinLength === 4) {
            pinLength = 6;
            elements.pinToggle.textContent = 'Switch to 4-digit PIN';
            inputs[4].style.display = '';
            inputs[5].style.display = '';
        } else {
            pinLength = 4;
            elements.pinToggle.textContent = 'Switch to 6-digit PIN';
            inputs[4].style.display = 'none';
            inputs[5].style.display = 'none';
            inputs[4].value = '';
            inputs[5].value = '';
            inputs[4].classList.remove('filled');
            inputs[5].classList.remove('filled');
        }
        
        clearPin();
    }

    /**
     * Initialize footer links
     */
    function initializeFooterLinks() {
        const footerLinks = document.querySelectorAll('.footer-link[data-action]');
        
        footerLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const action = this.dataset.action;
                
                if (action === 'help') {
                    showHelp();
                } else if (action === 'about') {
                    showAbout();
                }
            });
        });
    }

    /**
     * Show help information
     */
    function showHelp() {
        showAlert('For assistance, contact IT support at ext. 999 or email support@sme180.com', 'info');
    }

    /**
     * Show about information
     */
    function showAbout() {
        showAlert('SME 180 POS System v1.0.0 - © 2024 SME 180. All rights reserved.', 'info');
    }

    /**
     * Show alert message
     */
    function showAlert(message, type = 'error') {
        if (!elements.alertContainer) return;
        
        const alertClass = `alert-${type}`;
        const iconMap = {
            success: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>',
            error: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>',
            warning: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>',
            info: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>'
        };
        
        elements.alertContainer.innerHTML = `
            <div class="alert ${alertClass} fade-in">
                <svg class="alert-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    ${iconMap[type] || iconMap.error}
                </svg>
                ${escapeHtml(message)}
            </div>
        `;
        
        // Auto-hide after 5 seconds
        setTimeout(() => {
            if (elements.alertContainer) {
                elements.alertContainer.innerHTML = '';
            }
        }, 5000);
    }

    /**
     * Show/hide loading state
     */
    function showLoading(show) {
        if (show) {
            elements.submitBtn.disabled = true;
            elements.submitText.textContent = 'Signing In...';
            elements.submitSpinner.classList.remove('hidden');
            elements.loadingOverlay.classList.remove('hidden');
        } else {
            elements.submitBtn.disabled = CONFIG.isLocked;
            elements.submitText.textContent = 'Sign In';
            elements.submitSpinner.classList.add('hidden');
            elements.loadingOverlay.classList.add('hidden');
        }
    }

    /**
     * Check for lockout
     */
    function checkLockout() {
        const lockoutTime = localStorage.getItem('pos_lockout_time');
        if (lockoutTime) {
            const now = Date.now();
            const lockoutEnd = parseInt(lockoutTime);
            
            if (now < lockoutEnd) {
                CONFIG.isLocked = true;
                elements.submitBtn.disabled = true;
                const remainingMinutes = Math.ceil((lockoutEnd - now) / 60000);
                showAlert(`Account locked. Please try again in ${remainingMinutes} minutes.`, 'warning');
                
                setTimeout(() => {
                    CONFIG.isLocked = false;
                    elements.submitBtn.disabled = false;
                    localStorage.removeItem('pos_lockout_time');
                }, lockoutEnd - now);
            } else {
                localStorage.removeItem('pos_lockout_time');
            }
        }
    }

    /**
     * Focus first input
     */
    function focusFirstInput() {
        if (currentMode === 'pin' && pinInputs[0]) {
            pinInputs[0].focus();
        }
    }

    /**
     * Focus first manager input
     */
    function focusFirstManagerInput() {
        if (managerPinInputs[0]) {
            managerPinInputs[0].focus();
        }
    }

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

})();