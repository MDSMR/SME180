<?php
class FeatureGate {
    private static $cache = [];
    
    public static function allows($feature, $tenantId = null) {
        if (!$tenantId) {
            $tenantId = $_SESSION['tenant_id'] ?? null;
        }
        
        if (!$tenantId) return false;
        
        $cacheKey = "features_{$tenantId}";
        
        if (!isset(self::$cache[$cacheKey])) {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                SELECT feature_key 
                FROM tenant_features 
                WHERE tenant_id = ? AND is_enabled = 1 
                AND (expires_at IS NULL OR expires_at > NOW())
            ");
            $stmt->execute([$tenantId]);
            self::$cache[$cacheKey] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        return in_array($feature, self::$cache[$cacheKey]);
    }
    
    public static function requireFeature($feature) {
        if (!self::allows($feature)) {
            header('HTTP/1.0 403 Forbidden');
            die('This feature is not available in your subscription plan.');
        }
    }
}