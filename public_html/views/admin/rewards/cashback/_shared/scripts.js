// /views/admin/rewards/cashback/_shared/scripts.js
// Shared JavaScript functionality for Cashback system

(function() {
    'use strict';
    
    // Global cashback configuration
    window.CASHBACK_CONFIG = window.CASHBACK_CONFIG || {
        currency: 'EGP',
        maxLadderSteps: 8,
        tenantId: 1
    };
    
    // Utility functions
    window.CashbackUtils = {
        formatMoney: function(amount, currency) {
            const curr = currency || window.CASHBACK_CONFIG.currency;
            const symbols = {
                'USD': '$',
                'EUR': '€',
                'GBP': '£',
                'EGP': 'EGP '
            };
            const symbol = symbols[curr] || curr + ' ';
            return symbol + new Intl.NumberFormat('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(amount);
        },
        
        escapeHtml: function(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        },
        
        debounce: function(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        },
        
        showNotification: function(message, type) {
            const notification = document.createElement('div');
            notification.className = 'notification ' + (type || 'info');
            notification.textContent = message;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 16px 24px;
                background: ${type === 'error' ? 'var(--ms-red)' : 'var(--ms-green)'};
                color: white;
                border-radius: var(--ms-radius);
                box-shadow: var(--ms-shadow-3);
                z-index: 9999;
                animation: slideIn 0.3s ease;
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        }
    };
    
    // Initialize on DOM ready
    document.addEventListener('DOMContentLoaded', function() {
        console.log('Cashback System - Scripts loaded');
        
        // Initialize tooltips if needed
        const tooltips = document.querySelectorAll('[data-tooltip]');
        tooltips.forEach(el => {
            el.addEventListener('mouseenter', function() {
                const tooltip = document.createElement('div');
                tooltip.className = 'tooltip';
                tooltip.textContent = this.dataset.tooltip;
                tooltip.style.cssText = `
                    position: absolute;
                    background: var(--ms-gray-160);
                    color: white;
                    padding: 6px 12px;
                    border-radius: var(--ms-radius);
                    font-size: 12px;
                    z-index: 1000;
                    pointer-events: none;
                `;
                document.body.appendChild(tooltip);
                
                const rect = this.getBoundingClientRect();
                tooltip.style.left = rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2) + 'px';
                tooltip.style.top = rect.bottom + 8 + 'px';
                
                this._tooltip = tooltip;
            });
            
            el.addEventListener('mouseleave', function() {
                if (this._tooltip) {
                    this._tooltip.remove();
                    delete this._tooltip;
                }
            });
        });
    });
})();

// CSS animations for notifications
const style = document.createElement('style');
style.textContent = `
@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

@keyframes slideOut {
    from { transform: translateX(0); opacity: 1; }
    to { transform: translateX(100%); opacity: 0; }
}

.tooltip {
    transition: opacity 0.2s ease;
}
`;
document.head.appendChild(style);