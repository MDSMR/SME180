<?php
// /config/tenant.php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

class TenantManager {
    private static ?int $currentTenantId = null;
    private static ?int $currentBranchId = null;
    
    public static function initialize(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        self::$currentTenantId = $_SESSION['tenant_id'] ?? null;
        self::$currentBranchId = $_SESSION['branch_id'] ?? null;
    }
    
    public static function getTenantId(): ?int {
        return self::$currentTenantId;
    }
    
    public static function getBranchId(): ?int {
        return self::$currentBranchId;
    }
    
    public static function setTenant(int $tenantId, int $branchId): void {
        self::$currentTenantId = $tenantId;
        self::$currentBranchId = $branchId;
        $_SESSION['tenant_id'] = $tenantId;
        $_SESSION['branch_id'] = $branchId;
    }
    
    public static function validateAccess(): bool {
        return self::$currentTenantId !== null && self::$currentBranchId !== null;
    }
    
    public static function addTenantFilter(string $query, string $alias = ''): string {
        $tenantId = self::getTenantId();
        if (!$tenantId) {
            throw new Exception('No tenant context set');
        }
        
        $table = $alias ? "$alias." : "";
        $condition = "{$table}tenant_id = " . $tenantId;
        
        if (stripos($query, 'WHERE') !== false) {
            return str_replace('WHERE', "WHERE $condition AND", $query);
        } else {
            return $query . " WHERE $condition";
        }
    }
}