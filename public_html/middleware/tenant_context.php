<?php
declare(strict_types=1);

/**
 * Tenant Context & Auth Helpers — POS-safe & idempotent
 * - LAX for /pos/api/* (no hard throws if context missing)
 * - Strict for admin pages
 * - Accepts X-Tenant-Id / X-Branch-Id headers
 * - Guards to avoid redeclare fatals
 */

if (session_status() === PHP_SESSION_NONE) { @session_start(); }

if (!defined('POS_API_MODE')) {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    define('POS_API_MODE', is_string($uri) && stripos($uri, '/pos/api/') !== false);
}

if (!function_exists('tc_get_header')) {
    function tc_get_header(string $name): ?string {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($_SERVER[$key]) && $_SERVER[$key] !== '' ? (string)$_SERVER[$key] : null;
    }
}
if (!function_exists('tc_to_int')) {
    function tc_to_int($v): int { return (is_numeric($v) ? (int)$v : 0); }
}

if (!class_exists('TenantContext')) {
    class TenantContext {
        private static ?self $instance = null;
        private ?int $tenant_id = null;
        private ?int $branch_id = null;
        private ?array $user = null;
        private bool $initialized = false;

        private function __construct() {}

        public static function getInstance(): self {
            if (!self::$instance) self::$instance = new self();
            return self::$instance;
        }

        public function initialize(): void {
            if ($this->initialized) return;

            // Headers override if provided
            $hTenant = tc_get_header('X-Tenant-Id');
            $hBranch = tc_get_header('X-Branch-Id');
            if ($hTenant !== null) $this->tenant_id = tc_to_int($hTenant);
            if ($hBranch !== null) $this->branch_id = tc_to_int($hBranch);

            // User context (POS or admin)
            if (isset($_SESSION['pos_user_id'])) {
                $this->user = [
                    'id'   => tc_to_int($_SESSION['pos_user_id']),
                    'role' => $_SESSION['pos_user_role'] ?? null,
                    'type' => 'pos',
                ];
            } elseif (isset($_SESSION['user_id'])) {
                $this->user = [
                    'id'   => tc_to_int($_SESSION['user_id']),
                    'role' => $_SESSION['role_key'] ?? ($_SESSION['role'] ?? null),
                    'type' => $_SESSION['user_type'] ?? 'user',
                ];
            } elseif (isset($_SESSION['admin_id'])) {
                $this->user = [
                    'id'   => tc_to_int($_SESSION['admin_id']),
                    'role' => 'admin',
                    'type' => 'admin',
                ];
            }

            // Super admin impersonation
            if (!empty($_SESSION['user_type']) && $_SESSION['user_type'] === 'super_admin') {
                if (isset($_SESSION['viewing_tenant_id'])) $this->tenant_id = tc_to_int($_SESSION['viewing_tenant_id']);
                if (isset($_SESSION['viewing_branch_id'])) $this->branch_id = tc_to_int($_SESSION['viewing_branch_id']);
            }

            // Regular session keys
            if (isset($_SESSION['tenant_id']))  $this->tenant_id = tc_to_int($_SESSION['tenant_id']);
            if (isset($_SESSION['branch_id']))  $this->branch_id = tc_to_int($_SESSION['branch_id']);
            if (!$this->branch_id && isset($_SESSION['selected_branch_id'])) {
                $this->branch_id = tc_to_int($_SESSION['selected_branch_id']);
            }

            // Strict only outside POS API
            if (!POS_API_MODE) {
                if (!($this->user['id'] ?? 0)) throw new \Exception('No authenticated user');
                $isSuper = (($_SESSION['user_type'] ?? '') === 'super_admin');
                if (!$isSuper && (!($this->tenant_id) || !($this->branch_id))) {
                    throw new \Exception('Missing tenant or branch context');
                }
            }

            $this->initialized = true;
        }

        public function getTenantId(): ?int { $this->initialize(); return $this->tenant_id; }
        public function getBranchId(): ?int { $this->initialize(); return $this->branch_id; }
        public function getUser(): ?array   { $this->initialize(); return $this->user; }

        public function requireTenant(bool $strict = true): int {
            $this->initialize();
            $id = tc_to_int($this->tenant_id);
            if ($id) return $id;
            if (POS_API_MODE && !$strict) return 0;
            throw new \Exception('Tenant context required but not set');
        }
        public function requireBranch(bool $strict = true): int {
            $this->initialize();
            $id = tc_to_int($this->branch_id);
            if ($id) return $id;
            if (POS_API_MODE && !$strict) return 0;
            throw new \Exception('Branch context required but not set');
        }

        public function switchBranch(int $branch_id): bool {
            $this->initialize();
            $tenantId = tc_to_int($this->tenant_id);
            $userId   = tc_to_int($this->user['id'] ?? 0);
            if (!$tenantId || !$userId) throw new \Exception('Cannot switch branch without tenant/user');

            $pdo = db();
            $stmt = $pdo->prepare("
                SELECT b.id, b.name
                FROM branches b
                JOIN user_branches ub ON b.id = ub.branch_id
                WHERE b.id = ? AND b.tenant_id = ? AND ub.user_id = ? AND b.is_active = 1
            ");
            $stmt->execute([$branch_id, $tenantId, $userId]);
            $branch = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$branch) throw new \Exception('Access denied to branch');

            $_SESSION['branch_id']   = (int)$branch_id;
            $_SESSION['branch_name'] = (string)$branch['name'];
            $old = $this->branch_id; $this->branch_id = (int)$branch_id;

            try {
                audit_log('branch_switch', ['from_branch' => tc_to_int($old), 'to_branch' => (int)$branch_id]);
            } catch (\Throwable $e) { error_log('Audit log failed: '.$e->getMessage()); }

            @session_regenerate_id(true);
            return true;
        }
    }
}

if (!function_exists('get_tenant_context')) { function get_tenant_context(): TenantContext { return TenantContext::getInstance(); } }
if (!function_exists('get_tenant_id'))     { function get_tenant_id(): ?int { return TenantContext::getInstance()->getTenantId(); } }
if (!function_exists('get_branch_id'))     { function get_branch_id(): ?int { return TenantContext::getInstance()->getBranchId(); } }
if (!function_exists('require_tenant_id')) { function require_tenant_id(bool $strict = true): int { return TenantContext::getInstance()->requireTenant($strict); } }
if (!function_exists('require_branch_id')) { function require_branch_id(bool $strict = true): int { return TenantContext::getInstance()->requireBranch($strict); } }

if (!function_exists('audit_log')) {
    function audit_log(string $action, array $details = []): void {
        try {
            $pdo = db();
            $ctx = get_tenant_context();
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (user_id, tenant_id, branch_id, action, details, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                tc_to_int(($ctx->getUser()['id'] ?? null)),
                tc_to_int($ctx->getTenantId()),
                tc_to_int($ctx->getBranchId()),
                $action,
                json_encode($details, JSON_UNESCAPED_UNICODE),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
        } catch (\Throwable $e) {
            error_log('Audit log failed: '.$e->getMessage());
        }
    }
}