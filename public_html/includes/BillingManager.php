<?php
/**
 * Billing Manager
 * Handles all billing operations for tenants
 */

class BillingManager {
    
    private $pdo;
    
    public function __construct() {
        $this->pdo = db();
    }
    
    /**
     * Generate invoice for tenant
     */
    public function generateInvoice($tenantId, $items = [], $dueDate = null, $notes = '') {
        try {
            // Get tenant details
            $tenant = $this->getTenant($tenantId);
            if (!$tenant) {
                throw new Exception('Tenant not found');
            }
            
            // Generate invoice number
            $invoiceNumber = $this->generateInvoiceNumber();
            
            // Calculate totals
            $subtotal = 0;
            $taxAmount = 0;
            $taxRate = 0.15; // 15% VAT default
            
            foreach ($items as &$item) {
                $item['total'] = $item['quantity'] * $item['unit_price'];
                $subtotal += $item['total'];
            }
            
            $taxAmount = $subtotal * $taxRate;
            $totalAmount = $subtotal + $taxAmount;
            
            // Set due date (default 30 days)
            if (!$dueDate) {
                $dueDate = date('Y-m-d', strtotime('+30 days'));
            }
            
            // Insert invoice
            $stmt = $this->pdo->prepare("
                INSERT INTO invoices (
                    tenant_id, invoice_number, invoice_date, due_date,
                    amount, tax_amount, total_amount, status,
                    items, notes, created_by
                ) VALUES (
                    :tenant_id, :invoice_number, CURDATE(), :due_date,
                    :amount, :tax_amount, :total_amount, 'draft',
                    :items, :notes, :created_by
                )
            ");
            
            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':invoice_number' => $invoiceNumber,
                ':due_date' => $dueDate,
                ':amount' => $subtotal,
                ':tax_amount' => $taxAmount,
                ':total_amount' => $totalAmount,
                ':items' => json_encode($items),
                ':notes' => $notes,
                ':created_by' => $_SESSION['super_admin']['id'] ?? null
            ]);
            
            $invoiceId = $this->pdo->lastInsertId();
            
            // Log the action
            $this->logAction('invoice_generated', $tenantId, [
                'invoice_id' => $invoiceId,
                'invoice_number' => $invoiceNumber,
                'total_amount' => $totalAmount
            ]);
            
            return $invoiceId;
            
        } catch (Exception $e) {
            error_log('Invoice generation error: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Process manual payment
     */
    public function processManualPayment($invoiceId, $amount, $paymentMethod, $reference = '', $notes = '') {
        try {
            $this->pdo->beginTransaction();
            
            // Get invoice details
            $stmt = $this->pdo->prepare("
                SELECT * FROM invoices WHERE id = :id
            ");
            $stmt->execute([':id' => $invoiceId]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$invoice) {
                throw new Exception('Invoice not found');
            }
            
            // Insert payment record
            $stmt = $this->pdo->prepare("
                INSERT INTO payment_history (
                    tenant_id, invoice_id, amount, payment_method,
                    transaction_id, status, notes, processed_by
                ) VALUES (
                    :tenant_id, :invoice_id, :amount, :payment_method,
                    :transaction_id, 'success', :notes, :processed_by
                )
            ");
            
            $stmt->execute([
                ':tenant_id' => $invoice['tenant_id'],
                ':invoice_id' => $invoiceId,
                ':amount' => $amount,
                ':payment_method' => $paymentMethod,
                ':transaction_id' => $reference,
                ':notes' => $notes,
                ':processed_by' => $_SESSION['super_admin']['id'] ?? null
            ]);
            
            // Update invoice
            $newPaidAmount = $invoice['paid_amount'] + $amount;
            $status = ($newPaidAmount >= $invoice['total_amount']) ? 'paid' : 'partial';
            
            $stmt = $this->pdo->prepare("
                UPDATE invoices 
                SET paid_amount = :paid_amount,
                    status = :status,
                    paid_date = CASE WHEN :status2 = 'paid' THEN NOW() ELSE paid_date END,
                    payment_method = :payment_method,
                    payment_reference = :reference
                WHERE id = :id
            ");
            
            $stmt->execute([
                ':paid_amount' => $newPaidAmount,
                ':status' => $status,
                ':status2' => $status,
                ':payment_method' => $paymentMethod,
                ':reference' => $reference,
                ':id' => $invoiceId
            ]);
            
            // Update tenant payment status if fully paid
            if ($status === 'paid') {
                $this->updateTenantPaymentStatus($invoice['tenant_id']);
            }
            
            $this->pdo->commit();
            
            // Log the action
            $this->logAction('payment_processed', $invoice['tenant_id'], [
                'invoice_id' => $invoiceId,
                'amount' => $amount,
                'method' => $paymentMethod,
                'status' => $status
            ]);
            
            return true;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log('Payment processing error: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Update tenant subscription plan
     */
    public function updateSubscriptionPlan($tenantId, $planKey, $startDate = null) {
        try {
            // Get plan details
            $stmt = $this->pdo->prepare("
                SELECT * FROM subscription_plans WHERE plan_key = :plan_key AND is_active = 1
            ");
            $stmt->execute([':plan_key' => $planKey]);
            $plan = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$plan) {
                throw new Exception('Invalid subscription plan');
            }
            
            // Calculate dates
            $startDate = $startDate ?: date('Y-m-d');
            $expiresAt = date('Y-m-d H:i:s', strtotime($startDate . ' +1 year'));
            
            // Update tenant
            $stmt = $this->pdo->prepare("
                UPDATE tenants 
                SET subscription_plan = :plan,
                    subscription_status = 'active',
                    subscription_starts_at = :starts_at,
                    subscription_expires_at = :expires_at,
                    max_users = :max_users,
                    max_branches = :max_branches,
                    max_products = :max_products,
                    features_json = :features
                WHERE id = :id
            ");
            
            $stmt->execute([
                ':plan' => $planKey,
                ':starts_at' => $startDate,
                ':expires_at' => $expiresAt,
                ':max_users' => $plan['max_users'],
                ':max_branches' => $plan['max_branches'],
                ':max_products' => $plan['max_products'],
                ':features' => $plan['features_json'],
                ':id' => $tenantId
            ]);
            
            // Update feature flags
            $this->updateTenantFeatures($tenantId, json_decode($plan['features_json'], true));
            
            // Log the action
            $this->logAction('subscription_updated', $tenantId, [
                'new_plan' => $planKey,
                'expires_at' => $expiresAt
            ]);
            
            return true;
            
        } catch (Exception $e) {
            error_log('Subscription update error: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Check and enforce subscription limits
     */
    public function enforceSubscriptionLimits() {
        try {
            // Check expired subscriptions
            $stmt = $this->pdo->query("
                SELECT id, name, subscription_expires_at, grace_period_days
                FROM tenants
                WHERE subscription_status = 'active'
                    AND subscription_expires_at < NOW()
            ");
            
            $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($expired as $tenant) {
                $gracePeriodEnd = date('Y-m-d', strtotime($tenant['subscription_expires_at'] . ' +' . ($tenant['grace_period_days'] ?? 7) . ' days'));
                
                if (date('Y-m-d') > $gracePeriodEnd) {
                    // Suspend tenant
                    $this->suspendTenant($tenant['id'], 'Subscription expired');
                } else {
                    // Set to grace period
                    $stmt = $this->pdo->prepare("
                        UPDATE tenants 
                        SET payment_status = 'grace'
                        WHERE id = :id
                    ");
                    $stmt->execute([':id' => $tenant['id']]);
                    
                    // Create notification
                    $this->createNotification(
                        'warning',
                        'subscription',
                        'Tenant in Grace Period',
                        "Tenant \"{$tenant['name']}\" is in grace period. Will be suspended on {$gracePeriodEnd}",
                        'high'
                    );
                }
            }
            
            // Check overdue invoices
            $stmt = $this->pdo->query("
                SELECT i.*, t.name as tenant_name
                FROM invoices i
                JOIN tenants t ON i.tenant_id = t.id
                WHERE i.status IN ('sent', 'partial')
                    AND i.due_date < CURDATE()
            ");
            
            $overdue = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($overdue as $invoice) {
                // Update invoice status
                $stmt = $this->pdo->prepare("
                    UPDATE invoices SET status = 'overdue' WHERE id = :id
                ");
                $stmt->execute([':id' => $invoice['id']]);
                
                // Update tenant payment status
                $stmt = $this->pdo->prepare("
                    UPDATE tenants SET payment_status = 'overdue' WHERE id = :id
                ");
                $stmt->execute([':id' => $invoice['tenant_id']]);
                
                // Create notification
                $this->createNotification(
                    'warning',
                    'billing',
                    'Invoice Overdue',
                    "Invoice #{$invoice['invoice_number']} for \"{$invoice['tenant_name']}\" is overdue",
                    'high'
                );
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log('Subscription enforcement error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Suspend tenant
     */
    public function suspendTenant($tenantId, $reason = '') {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE tenants 
                SET subscription_status = 'suspended',
                    suspended_at = NOW(),
                    suspension_reason = :reason
                WHERE id = :id
            ");
            
            $stmt->execute([
                ':reason' => $reason,
                ':id' => $tenantId
            ]);
            
            // Disable all users
            $stmt = $this->pdo->prepare("
                UPDATE users 
                SET disabled_at = NOW(),
                    disabled_by = :admin_id
                WHERE tenant_id = :tenant_id
                    AND disabled_at IS NULL
            ");
            
            $stmt->execute([
                ':admin_id' => $_SESSION['super_admin']['id'] ?? 0,
                ':tenant_id' => $tenantId
            ]);
            
            // Log the action
            $this->logAction('tenant_suspended', $tenantId, ['reason' => $reason]);
            
            return true;
            
        } catch (Exception $e) {
            error_log('Tenant suspension error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Activate tenant
     */
    public function activateTenant($tenantId) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE tenants 
                SET subscription_status = 'active',
                    suspended_at = NULL,
                    suspension_reason = NULL,
                    payment_status = 'current'
                WHERE id = :id
            ");
            
            $stmt->execute([':id' => $tenantId]);
            
            // Re-enable admin users
            $stmt = $this->pdo->prepare("
                UPDATE users 
                SET disabled_at = NULL,
                    disabled_by = NULL
                WHERE tenant_id = :tenant_id
                    AND role_key = 'admin'
            ");
            
            $stmt->execute([':tenant_id' => $tenantId]);
            
            // Log the action
            $this->logAction('tenant_activated', $tenantId, []);
            
            return true;
            
        } catch (Exception $e) {
            error_log('Tenant activation error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get billing summary for tenant
     */
    public function getTenantBillingSummary($tenantId) {
        try {
            $summary = [];
            
            // Get current subscription
            $stmt = $this->pdo->prepare("
                SELECT 
                    t.*,
                    sp.name as plan_name,
                    sp.monthly_price,
                    sp.yearly_price
                FROM tenants t
                LEFT JOIN subscription_plans sp ON t.subscription_plan = sp.plan_key
                WHERE t.id = :id
            ");
            $stmt->execute([':id' => $tenantId]);
            $summary['subscription'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get invoice summary
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as total_invoices,
                    SUM(total_amount) as total_billed,
                    SUM(paid_amount) as total_paid,
                    SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_invoices,
                    SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_invoices,
                    SUM(CASE WHEN status = 'overdue' THEN total_amount - paid_amount ELSE 0 END) as overdue_amount
                FROM invoices
                WHERE tenant_id = :tenant_id
            ");
            $stmt->execute([':tenant_id' => $tenantId]);
            $summary['invoices'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get recent payments
            $stmt = $this->pdo->prepare("
                SELECT 
                    ph.*,
                    i.invoice_number
                FROM payment_history ph
                LEFT JOIN invoices i ON ph.invoice_id = i.id
                WHERE ph.tenant_id = :tenant_id
                ORDER BY ph.created_at DESC
                LIMIT 10
            ");
            $stmt->execute([':tenant_id' => $tenantId]);
            $summary['recent_payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return $summary;
            
        } catch (Exception $e) {
            error_log('Error fetching billing summary: ' . $e->getMessage());
            return [];
        }
    }
    
    // Helper methods
    
    private function getTenant($tenantId) {
        $stmt = $this->pdo->prepare("SELECT * FROM tenants WHERE id = :id");
        $stmt->execute([':id' => $tenantId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    private function generateInvoiceNumber() {
        $year = date('Y');
        $month = date('m');
        
        // Get last invoice number for this month
        $stmt = $this->pdo->prepare("
            SELECT invoice_number 
            FROM invoices 
            WHERE invoice_number LIKE :pattern
            ORDER BY id DESC 
            LIMIT 1
        ");
        $stmt->execute([':pattern' => "INV-{$year}{$month}-%"]);
        $lastInvoice = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($lastInvoice) {
            $lastNumber = intval(substr($lastInvoice['invoice_number'], -4));
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }
        
        return "INV-{$year}{$month}-{$newNumber}";
    }
    
    private function updateTenantPaymentStatus($tenantId) {
        // Check if tenant has any overdue invoices
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as overdue_count
            FROM invoices
            WHERE tenant_id = :tenant_id
                AND status = 'overdue'
        ");
        $stmt->execute([':tenant_id' => $tenantId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $status = ($result['overdue_count'] > 0) ? 'overdue' : 'current';
        
        $stmt = $this->pdo->prepare("
            UPDATE tenants 
            SET payment_status = :status,
                last_payment_date = NOW()
            WHERE id = :id
        ");
        $stmt->execute([':status' => $status, ':id' => $tenantId]);
    }
    
    private function updateTenantFeatures($tenantId, $features) {
        // Remove existing features
        $stmt = $this->pdo->prepare("DELETE FROM tenant_features WHERE tenant_id = :tenant_id");
        $stmt->execute([':tenant_id' => $tenantId]);
        
        // Add new features
        foreach ($features as $key => $enabled) {
            if ($enabled) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO tenant_features (tenant_id, feature_key, is_enabled)
                    VALUES (:tenant_id, :feature_key, 1)
                ");
                $stmt->execute([':tenant_id' => $tenantId, ':feature_key' => $key]);
            }
        }
        
        // Update feature flags table
        foreach ($features as $key => $enabled) {
            $stmt = $this->pdo->prepare("
                INSERT INTO feature_flags (tenant_id, feature_key, is_enabled)
                VALUES (:tenant_id, :feature_key, :enabled)
                ON DUPLICATE KEY UPDATE is_enabled = :enabled2
            ");
            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':feature_key' => $key,
                ':enabled' => $enabled ? 1 : 0,
                ':enabled2' => $enabled ? 1 : 0
            ]);
        }
    }
    
    private function logAction($action, $tenantId, $details) {
        $stmt = $this->pdo->prepare("
            INSERT INTO super_admin_logs 
            (admin_id, action, tenant_id, details, ip_address, user_agent)
            VALUES (:admin_id, :action, :tenant_id, :details, :ip, :ua)
        ");
        
        $stmt->execute([
            ':admin_id' => $_SESSION['super_admin']['id'] ?? 0,
            ':action' => $action,
            ':tenant_id' => $tenantId,
            ':details' => json_encode($details),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    }
    
    private function createNotification($type, $category, $title, $message, $priority = 'normal') {
        $stmt = $this->pdo->prepare("
            INSERT INTO system_notifications 
            (recipient_type, notification_type, category, title, message, priority)
            VALUES ('super_admin', :type, :category, :title, :message, :priority)
        ");
        
        $stmt->execute([
            ':type' => $type,
            ':category' => $category,
            ':title' => $title,
            ':message' => $message,
            ':priority' => $priority
        ]);
    }
}