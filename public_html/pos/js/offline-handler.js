/**
 * POS Offline Handler
 * Handles temporary internet outages by queuing orders locally
 */

class OfflineHandler {
    constructor() {
        this.isOnline = navigator.onLine;
        this.syncInterval = null;
        this.init();
    }

    init() {
        // Monitor connection status
        window.addEventListener('online', () => this.handleOnline());
        window.addEventListener('offline', () => this.handleOffline());
        
        // Check connection every 10 seconds
        setInterval(() => this.checkConnection(), 10000);
        
        // Try to sync every 30 seconds
        this.syncInterval = setInterval(() => this.syncPendingOrders(), 30000);
        
        // Load cached data on startup
        this.loadCachedData();
        
        // Update UI
        this.updateConnectionStatus();
    }

    handleOnline() {
        console.log('Connection restored');
        this.isOnline = true;
        this.updateConnectionStatus();
        
        // Immediately sync pending orders
        this.syncPendingOrders();
        
        // Refresh product data
        this.refreshProductCache();
        
        // Show notification
        this.showNotification('Connection restored! Syncing data...', 'success');
    }

    handleOffline() {
        console.log('Connection lost');
        this.isOnline = false;
        this.updateConnectionStatus();
        
        // Show notification
        this.showNotification('Working offline. Orders will sync when connection returns.', 'warning');
    }

    checkConnection() {
        // Ping server to verify real connection
        fetch('/api/me.php', {
            method: 'HEAD',
            cache: 'no-cache'
        })
        .then(() => {
            if (!this.isOnline) {
                this.handleOnline();
            }
        })
        .catch(() => {
            if (this.isOnline) {
                this.handleOffline();
            }
        });
    }

    updateConnectionStatus() {
        const statusEl = document.getElementById('connection-status');
        if (statusEl) {
            if (this.isOnline) {
                statusEl.innerHTML = '<span style="color: green;">● Online</span>';
            } else {
                statusEl.innerHTML = '<span style="color: orange;">● Offline Mode</span>';
            }
        }
    }

    /**
     * Cache critical data for offline use
     */
    async loadCachedData() {
        // Cache products if online
        if (this.isOnline) {
            try {
                const products = await this.fetchWithTimeout('/api/items.php');
                localStorage.setItem('cached_products', JSON.stringify({
                    data: products,
                    timestamp: Date.now()
                }));
                
                const categories = await this.fetchWithTimeout('/api/categories.php');
                localStorage.setItem('cached_categories', JSON.stringify({
                    data: categories,
                    timestamp: Date.now()
                }));
                
                console.log('Product cache updated');
            } catch (error) {
                console.error('Failed to cache products:', error);
            }
        }
    }

    refreshProductCache() {
        this.loadCachedData();
    }

    /**
     * Get cached products for offline use
     */
    getCachedProducts() {
        const cached = localStorage.getItem('cached_products');
        if (cached) {
            const { data, timestamp } = JSON.parse(cached);
            const age = Date.now() - timestamp;
            
            // Warn if cache is older than 24 hours
            if (age > 24 * 60 * 60 * 1000) {
                this.showNotification('Product data may be outdated', 'warning');
            }
            
            return data;
        }
        return [];
    }

    getCachedCategories() {
        const cached = localStorage.getItem('cached_categories');
        if (cached) {
            const { data } = JSON.parse(cached);
            return data;
        }
        return [];
    }

    /**
     * Create order (online or offline)
     */
    async createOrder(orderData) {
        // Add metadata
        orderData.created_offline = !this.isOnline;
        orderData.client_timestamp = new Date().toISOString();
        orderData.client_id = this.generateClientId();
        
        if (this.isOnline) {
            try {
                // Try to send to server
                const response = await this.fetchWithTimeout('/api/orders_create.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(orderData)
                });
                
                if (response.success) {
                    this.showNotification('Order created successfully', 'success');
                    return response;
                }
            } catch (error) {
                console.error('Failed to create order online:', error);
                // Fall through to offline handling
            }
        }
        
        // Save offline
        return this.saveOrderOffline(orderData);
    }

    saveOrderOffline(orderData) {
        const pendingOrders = this.getPendingOrders();
        
        // Generate temporary ID
        const tempOrder = {
            ...orderData,
            id: 'TEMP_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9),
            status: 'pending_sync',
            synced: false,
            created_at: new Date().toISOString()
        };
        
        pendingOrders.push(tempOrder);
        localStorage.setItem('pending_orders', JSON.stringify(pendingOrders));
        
        this.showNotification('Order saved offline. Will sync when connection returns.', 'info');
        
        // Update UI to show pending count
        this.updatePendingCount();
        
        return {
            success: true,
            offline: true,
            order: tempOrder
        };
    }

    getPendingOrders() {
        const stored = localStorage.getItem('pending_orders');
        return stored ? JSON.parse(stored) : [];
    }

    async syncPendingOrders() {
        if (!this.isOnline) return;
        
        const pendingOrders = this.getPendingOrders();
        const unsynced = pendingOrders.filter(o => !o.synced);
        
        if (unsynced.length === 0) return;
        
        console.log(`Syncing ${unsynced.length} pending orders...`);
        
        for (const order of unsynced) {
            try {
                // Remove temporary fields
                const orderToSend = { ...order };
                delete orderToSend.id; // Remove temp ID
                delete orderToSend.synced;
                delete orderToSend.status;
                
                const response = await this.fetchWithTimeout('/api/orders_create.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(orderToSend)
                });
                
                if (response.success) {
                    // Mark as synced
                    order.synced = true;
                    order.server_id = response.order_id;
                    order.synced_at = new Date().toISOString();
                    
                    console.log(`Order ${order.client_id} synced successfully`);
                }
            } catch (error) {
                console.error(`Failed to sync order ${order.client_id}:`, error);
                // Will retry next sync cycle
            }
        }
        
        // Update storage
        localStorage.setItem('pending_orders', JSON.stringify(pendingOrders));
        
        // Clean up old synced orders (keep for 24 hours for reference)
        this.cleanupSyncedOrders();
        
        // Update UI
        this.updatePendingCount();
        
        const syncedCount = unsynced.filter(o => o.synced).length;
        if (syncedCount > 0) {
            this.showNotification(`${syncedCount} orders synced successfully`, 'success');
        }
    }

    cleanupSyncedOrders() {
        const pendingOrders = this.getPendingOrders();
        const cutoff = Date.now() - (24 * 60 * 60 * 1000); // 24 hours
        
        const filtered = pendingOrders.filter(order => {
            if (!order.synced) return true; // Keep unsynced
            const syncTime = new Date(order.synced_at).getTime();
            return syncTime > cutoff; // Keep recently synced
        });
        
        localStorage.setItem('pending_orders', JSON.stringify(filtered));
    }

    updatePendingCount() {
        const pendingOrders = this.getPendingOrders();
        const unsynced = pendingOrders.filter(o => !o.synced).length;
        
        const countEl = document.getElementById('pending-orders-count');
        if (countEl) {
            if (unsynced > 0) {
                countEl.innerHTML = `<span style="color: orange;">${unsynced} pending</span>`;
                countEl.style.display = 'inline';
            } else {
                countEl.style.display = 'none';
            }
        }
    }

    async fetchWithTimeout(url, options = {}, timeout = 5000) {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), timeout);
        
        try {
            const response = await fetch(url, {
                ...options,
                signal: controller.signal
            });
            clearTimeout(timeoutId);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            return await response.json();
        } catch (error) {
            clearTimeout(timeoutId);
            throw error;
        }
    }

    generateClientId() {
        return Date.now().toString(36) + Math.random().toString(36).substr(2);
    }

    showNotification(message, type = 'info') {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            background: ${type === 'success' ? '#4CAF50' : type === 'warning' ? '#FF9800' : '#2196F3'};
            color: white;
            border-radius: 4px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            z-index: 10000;
            animation: slideIn 0.3s ease;
        `;
        notification.textContent = message;
        
        document.body.appendChild(notification);
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            notification.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => notification.remove(), 300);
        }, 5000);
    }

    /**
     * Get sync status report
     */
    getSyncStatus() {
        const pendingOrders = this.getPendingOrders();
        return {
            isOnline: this.isOnline,
            pendingCount: pendingOrders.filter(o => !o.synced).length,
            syncedCount: pendingOrders.filter(o => o.synced).length,
            totalPending: pendingOrders.length,
            oldestPending: pendingOrders.length > 0 ? 
                new Date(Math.min(...pendingOrders.map(o => new Date(o.created_at)))) : null
        };
    }
}

// Initialize on page load
let offlineHandler;
document.addEventListener('DOMContentLoaded', () => {
    offlineHandler = new OfflineHandler();
    
    // Expose globally for POS to use
    window.POSOfflineHandler = offlineHandler;
});

// CSS for notifications
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
`;
document.head.appendChild(style);