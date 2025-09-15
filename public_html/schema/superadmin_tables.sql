-- Super Admin Enhanced Database Schema
-- Run this migration to add all required tables and columns

-- Add columns to existing tenants table
ALTER TABLE tenants 
ADD COLUMN IF NOT EXISTS grace_period_days INT DEFAULT 7,
ADD COLUMN IF NOT EXISTS last_payment_date DATETIME,
ADD COLUMN IF NOT EXISTS next_billing_date DATETIME,
ADD COLUMN IF NOT EXISTS payment_method ENUM('manual','stripe','paypal') DEFAULT 'manual',
ADD COLUMN IF NOT EXISTS stripe_customer_id VARCHAR(255),
ADD COLUMN IF NOT EXISTS payment_status ENUM('current','grace','overdue','suspended') DEFAULT 'current',
ADD COLUMN IF NOT EXISTS suspension_reason VARCHAR(255),
ADD COLUMN IF NOT EXISTS suspended_at DATETIME,
ADD COLUMN IF NOT EXISTS terminated_at DATETIME;

-- Invoices table
CREATE TABLE IF NOT EXISTS invoices (
    id INT PRIMARY KEY AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    invoice_number VARCHAR(50) UNIQUE,
    invoice_date DATE,
    due_date DATE,
    amount DECIMAL(10,2),
    tax_amount DECIMAL(10,2) DEFAULT 0,
    total_amount DECIMAL(10,2),
    status ENUM('draft','sent','paid','partial','overdue','cancelled','refunded') DEFAULT 'draft',
    paid_date DATETIME,
    paid_amount DECIMAL(10,2) DEFAULT 0,
    payment_method VARCHAR(50),
    payment_reference VARCHAR(255),
    items JSON,
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    INDEX idx_invoice_status (status),
    INDEX idx_invoice_date (invoice_date),
    INDEX idx_due_date (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Payment history
CREATE TABLE IF NOT EXISTS payment_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    invoice_id INT,
    amount DECIMAL(10,2),
    payment_method VARCHAR(50),
    transaction_id VARCHAR(255),
    status ENUM('success','failed','pending','refunded') DEFAULT 'pending',
    gateway_response JSON,
    notes TEXT,
    processed_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    FOREIGN KEY (invoice_id) REFERENCES invoices(id),
    INDEX idx_payment_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tenant limits tracking
CREATE TABLE IF NOT EXISTS tenant_limits (
    id INT PRIMARY KEY AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    limit_key VARCHAR(50),
    limit_type ENUM('users','branches','products','storage','api_calls') NOT NULL,
    soft_limit INT,
    hard_limit INT,
    current_usage INT DEFAULT 0,
    last_reset DATE,
    notification_sent BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    UNIQUE KEY unique_tenant_limit (tenant_id, limit_key),
    INDEX idx_usage_check (tenant_id, current_usage, hard_limit)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Feature flags management
CREATE TABLE IF NOT EXISTS feature_flags (
    id INT PRIMARY KEY AUTO_INCREMENT,
    tenant_id INT,
    feature_key VARCHAR(50),
    feature_name VARCHAR(100),
    description TEXT,
    is_enabled BOOLEAN DEFAULT 0,
    enabled_by INT,
    metadata JSON,
    expires_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    UNIQUE KEY unique_tenant_feature (tenant_id, feature_key),
    INDEX idx_feature_enabled (tenant_id, is_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- System health monitoring
CREATE TABLE IF NOT EXISTS system_health_checks (
    id INT PRIMARY KEY AUTO_INCREMENT,
    service_name VARCHAR(100),
    service_type ENUM('database','cache','queue','storage','email','cron','api') DEFAULT 'api',
    status ENUM('healthy','degraded','down','unknown') DEFAULT 'unknown',
    last_check DATETIME,
    next_check DATETIME,
    response_time_ms INT,
    error_message TEXT,
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_service_status (service_name, status),
    INDEX idx_check_time (last_check)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Impersonation tracking
CREATE TABLE IF NOT EXISTS impersonation_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    admin_id INT NOT NULL,
    tenant_id INT NOT NULL,
    user_id INT,
    reason TEXT,
    started_at DATETIME,
    ended_at DATETIME,
    actions_count INT DEFAULT 0,
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    FOREIGN KEY (admin_id) REFERENCES super_admins(id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_impersonation_active (admin_id, ended_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Security anomalies
CREATE TABLE IF NOT EXISTS security_anomalies (
    id INT PRIMARY KEY AUTO_INCREMENT,
    tenant_id INT,
    user_id INT,
    anomaly_type ENUM('multiple_failures','geo_change','unusual_time','rate_limit','suspicious_pattern') NOT NULL,
    severity ENUM('low','medium','high','critical') DEFAULT 'medium',
    details JSON,
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    resolved BOOLEAN DEFAULT FALSE,
    resolved_by INT,
    resolved_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_unresolved (resolved, severity),
    INDEX idx_anomaly_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cron job monitoring
CREATE TABLE IF NOT EXISTS cron_jobs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    job_name VARCHAR(100) UNIQUE,
    job_command VARCHAR(500),
    schedule VARCHAR(50),
    last_run DATETIME,
    next_run DATETIME,
    last_duration_seconds INT,
    last_status ENUM('success','failed','running','skipped') DEFAULT 'success',
    last_output TEXT,
    failure_count INT DEFAULT 0,
    is_enabled BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_next_run (next_run, is_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- System notifications
CREATE TABLE IF NOT EXISTS system_notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    recipient_type ENUM('super_admin','tenant_admin','specific_user') DEFAULT 'super_admin',
    recipient_id INT,
    notification_type ENUM('alert','warning','info','success','error') DEFAULT 'info',
    category VARCHAR(50),
    title VARCHAR(255),
    message TEXT,
    action_url VARCHAR(500),
    is_read BOOLEAN DEFAULT FALSE,
    read_at DATETIME,
    priority ENUM('low','normal','high','urgent') DEFAULT 'normal',
    expires_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_unread (recipient_id, is_read),
    INDEX idx_priority (priority, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Announcements
CREATE TABLE IF NOT EXISTS announcements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255),
    message TEXT,
    announcement_type ENUM('info','warning','maintenance','feature','promotion') DEFAULT 'info',
    target_plans JSON COMMENT 'Array of plan keys or null for all',
    target_tenants JSON COMMENT 'Array of tenant IDs or null for all',
    display_location ENUM('dashboard','login','both') DEFAULT 'dashboard',
    starts_at DATETIME,
    expires_at DATETIME,
    is_dismissible BOOLEAN DEFAULT TRUE,
    created_by INT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES super_admins(id),
    INDEX idx_active_announcements (is_active, starts_at, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default cron jobs
INSERT INTO cron_jobs (job_name, job_command, schedule, next_run) VALUES
('subscription_check', '/scripts/cron/subscription_check.php', '0 2 * * *', DATE_ADD(NOW(), INTERVAL 1 DAY)),
('subscription_enforce', '/scripts/cron/subscription_enforce.php', '0 3 * * *', DATE_ADD(NOW(), INTERVAL 1 DAY)),
('invoice_generator', '/scripts/cron/invoice_generator.php', '0 4 1 * *', DATE_ADD(NOW(), INTERVAL 1 MONTH)),
('payment_reminder', '/scripts/cron/payment_reminder.php', '0 10 * * *', DATE_ADD(NOW(), INTERVAL 1 DAY)),
('session_cleanup', '/scripts/cron/session_cleanup.php', '0 */6 * * *', DATE_ADD(NOW(), INTERVAL 6 HOUR)),
('health_check', '/scripts/cron/health_check.php', '*/15 * * * *', DATE_ADD(NOW(), INTERVAL 15 MINUTE)),
('anomaly_detection', '/scripts/cron/anomaly_detection.php', '*/30 * * * *', DATE_ADD(NOW(), INTERVAL 30 MINUTE))
ON DUPLICATE KEY UPDATE job_name=VALUES(job_name);

-- Insert default feature flags template
INSERT INTO feature_flags (tenant_id, feature_key, feature_name, description, is_enabled) VALUES
(NULL, 'pos', 'Point of Sale', 'Core POS functionality', TRUE),
(NULL, 'loyalty', 'Loyalty Programs', 'Points, stamps, and rewards', FALSE),
(NULL, 'stockflow', 'Inventory Management', 'Stock tracking and transfers', FALSE),
(NULL, 'reports_advanced', 'Advanced Reports', 'Detailed analytics and insights', FALSE),
(NULL, 'api_access', 'API Access', 'REST API for integrations', FALSE),
(NULL, 'multi_branch', 'Multi-Branch', 'Manage multiple locations', FALSE),
(NULL, 'table_management', 'Table Management', 'Restaurant table tracking', TRUE),
(NULL, 'online_ordering', 'Online Ordering', 'Accept orders online', FALSE),
(NULL, 'kitchen_display', 'Kitchen Display', 'Digital kitchen management', FALSE),
(NULL, 'customer_display', 'Customer Display', 'Secondary display for customers', FALSE)
ON DUPLICATE KEY UPDATE feature_key=VALUES(feature_key);