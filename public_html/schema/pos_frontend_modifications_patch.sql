-- ========================================================================
-- SME 180 POS — Consolidated POS Frontend Migration (MySQL-safe, idempotent)
-- Date: 2025-09-15
-- Compatible with phpMyAdmin / SiteGround MySQL 8.x
-- ========================================================================

SET @schema := DATABASE();

-- ------------------------------------------------------------------------
-- 1) ORDERS — add/ensure POS columns (guarded)
-- ------------------------------------------------------------------------

-- kitchen_status
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='orders' AND COLUMN_NAME='kitchen_status');
SET @sql := IF(@col_exists=0,
  'ALTER TABLE `orders` ADD COLUMN `kitchen_status` ENUM(''pending'',''fired'',''preparing'',''ready'',''served'',''cancelled'') DEFAULT ''pending'' AFTER `created_at`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- client_id
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='orders' AND COLUMN_NAME='client_id');
SET @sql := IF(@col_exists=0,
  'ALTER TABLE `orders` ADD COLUMN `client_id` VARCHAR(100) DEFAULT NULL COMMENT ''For offline sync'' AFTER `kitchen_status`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- created_offline
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='orders' AND COLUMN_NAME='created_offline');
SET @sql := IF(@col_exists=0,
  'ALTER TABLE `orders` ADD COLUMN `created_offline` TINYINT(1) DEFAULT ''0'' AFTER `client_id`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- synced_at
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='orders' AND COLUMN_NAME='synced_at');
SET @sql := IF(@col_exists=0,
  'ALTER TABLE `orders` ADD COLUMN `synced_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_offline`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Index: idx_orders_kitchen_status
SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='orders' AND INDEX_NAME='idx_orders_kitchen_status');
SET @sql := IF(@idx_exists=0,
  'CREATE INDEX `idx_orders_kitchen_status` ON `orders` (`kitchen_status`)',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Index: idx_orders_client_id
SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='orders' AND INDEX_NAME='idx_orders_client_id');
SET @sql := IF(@idx_exists=0,
  'CREATE INDEX `idx_orders_client_id` ON `orders` (`client_id`)',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ------------------------------------------------------------------------
-- 2) SETTINGS — non-deprecated upserts (one row per statement)
-- ------------------------------------------------------------------------
START TRANSACTION;

INSERT INTO `settings` (`tenant_id`,`key`,`value`,`data_type`)
VALUES (1,'pos_enable_tips','1','boolean')
ON DUPLICATE KEY UPDATE `value`='1', `data_type`='boolean';

INSERT INTO `settings` (`tenant_id`,`key`,`value`,`data_type`)
VALUES (1,'pos_enable_service_charge','1','boolean')
ON DUPLICATE KEY UPDATE `value`='1', `data_type`='boolean';

INSERT INTO `settings` (`tenant_id`,`key`,`value`,`data_type`)
VALUES (1,'pos_service_charge_percent','10','number')
ON DUPLICATE KEY UPDATE `value`='10', `data_type`='number';

INSERT INTO `settings` (`tenant_id`,`key`,`value`,`data_type`)
VALUES (1,'pos_enable_cash_management','1','boolean')
ON DUPLICATE KEY UPDATE `value`='1', `data_type`='boolean';

INSERT INTO `settings` (`tenant_id`,`key`,`value`,`data_type`)
VALUES (1,'pos_require_cash_session','1','boolean')
ON DUPLICATE KEY UPDATE `value`='1', `data_type`='boolean';

INSERT INTO `settings` (`tenant_id`,`key`,`value`,`data_type`)
VALUES (1,'pos_enable_offline_mode','1','boolean')
ON DUPLICATE KEY UPDATE `value`='1', `data_type`='boolean';

INSERT INTO `settings` (`tenant_id`,`key`,`value`,`data_type`)
VALUES (1,'pos_offline_sync_interval','30','number')
ON DUPLICATE KEY UPDATE `value`='30', `data_type`='number';

INSERT INTO `settings` (`tenant_id`,`key`,`value`,`data_type`)
VALUES (1,'pos_pin_max_attempts','3','number')
ON DUPLICATE KEY UPDATE `value`='3', `data_type`='number';

INSERT INTO `settings` (`tenant_id`,`key`,`value`,`data_type`)
VALUES (1,'pos_pin_lockout_minutes','15','number')
ON DUPLICATE KEY UPDATE `value`='15', `data_type`='number';

INSERT INTO `settings` (`tenant_id`,`key`,`value`,`data_type`)
VALUES (1,'pos_receipt_header','Welcome to SME 180','string')
ON DUPLICATE KEY UPDATE `value`='Welcome to SME 180', `data_type`='string';

INSERT INTO `settings` (`tenant_id`,`key`,`value`,`data_type`)
VALUES (1,'pos_receipt_footer','Thank you for your visit!','string')
ON DUPLICATE KEY UPDATE `value`='Thank you for your visit!', `data_type`='string';

INSERT INTO `settings` (`tenant_id`,`key`,`value`,`data_type`)
VALUES (1,'pos_enable_customer_display','0','boolean')
ON DUPLICATE KEY UPDATE `value`='0', `data_type`='boolean';

INSERT INTO `settings` (`tenant_id`,`key`,`value`,`data_type`)
VALUES (1,'pos_auto_print_receipt','1','boolean')
ON DUPLICATE KEY UPDATE `value`='1', `data_type`='boolean';

INSERT INTO `settings` (`tenant_id`,`key`,`value`,`data_type`)
VALUES (1,'pos_auto_print_kitchen','1','boolean')
ON DUPLICATE KEY UPDATE `value`='1', `data_type`='boolean';

INSERT INTO `settings` (`tenant_id`,`key`,`value`,`data_type`)
VALUES (1,'pos_kitchen_ticket_copies','1','number')
ON DUPLICATE KEY UPDATE `value`='1', `data_type`='number';

COMMIT;

-- ------------------------------------------------------------------------
-- 3) VIEWS — recreate (safe to re-run)
-- ------------------------------------------------------------------------
CREATE OR REPLACE VIEW `v_active_pos_sessions` AS
SELECT 
    cs.id            AS session_id,
    cs.tenant_id,
    cs.branch_id,
    cs.user_id,
    cs.station_id,
    cs.opened_at,
    cs.opening_amount,
    cs.cash_sales,
    cs.card_sales,
    cs.other_sales,
    u.name           AS cashier_name,
    b.name           AS branch_name,
    ps.station_name
FROM cash_sessions cs
JOIN users    u  ON cs.user_id   = u.id
JOIN branches b  ON cs.branch_id = b.id
LEFT JOIN pos_stations ps ON cs.station_id = ps.id
WHERE cs.status = 'open';

CREATE OR REPLACE VIEW `v_today_sales_summary` AS
SELECT 
    o.tenant_id,
    o.branch_id,
    COUNT(DISTINCT o.id)           AS order_count,
    COUNT(DISTINCT o.customer_id)  AS customer_count,
    SUM(o.subtotal_amount)         AS total_subtotal,
    SUM(o.discount_amount)         AS total_discounts,
    SUM(o.tax_amount)              AS total_tax,
    SUM(o.service_charge_amount)   AS total_service_charge,
    SUM(o.tip_amount)              AS total_tips,
    SUM(o.total_amount)            AS total_sales,
    AVG(o.total_amount)            AS average_order_value
FROM orders o
WHERE DATE(o.created_at) = CURDATE()
  AND o.status <> 'cancelled'
  AND o.payment_status = 'paid'
GROUP BY o.tenant_id, o.branch_id;
-- ========================================================================
-- End
-- ========================================================================
