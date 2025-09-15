<?php
class SubscriptionManager {
    
    public static function checkLimits($tenantId) {
        $db = Database::getInstance()->getConnection();
        
        // Get tenant limits
        $stmt = $db->prepare("
            SELECT max_users, max_branches, max_products, 
                   subscription_expires_at, subscription_status
            FROM tenants 
            WHERE id = ?
        ");
        $stmt->execute([$tenantId]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tenant) return false;
        
        // Check subscription expiry
        if ($tenant['subscription_expires_at'] < date('Y-m-d H:i:s')) {
            return ['error' => 'Subscription expired'];
        }
        
        // Check status
        if ($tenant['subscription_status'] !== 'active') {
            return ['error' => 'Subscription not active'];
        }
        
        // Check user limit
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE tenant_id = ?");
        $stmt->execute([$tenantId]);
        if ($stmt->fetchColumn() >= $tenant['max_users']) {
            return ['error' => 'User limit reached'];
        }
        
        // Check branch limit
        $stmt = $db->prepare("SELECT COUNT(*) FROM branches WHERE tenant_id = ?");
        $stmt->execute([$tenantId]);
        if ($stmt->fetchColumn() >= $tenant['max_branches']) {
            return ['error' => 'Branch limit reached'];
        }
        
        // Check product limit
        $stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE tenant_id = ?");
        $stmt->execute([$tenantId]);
        if ($stmt->fetchColumn() >= $tenant['max_products']) {
            return ['error' => 'Product limit reached'];
        }
        
        return true;
    }
    
    public static function enforceLimit($type, $tenantId) {
        $limits = self::checkLimits($tenantId);
        if ($limits !== true) {
            throw new Exception($limits['error']);
        }
    }
}