<?php
/**
 * SME 180 - Column Check Diagnostic
 * File: /public_html/check_columns.php
 * 
 * Identifies exactly which columns are missing
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/config/db.php';

$pdo = db();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Column Check Diagnostic - SME 180</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            padding: 20px;
            margin: 0;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #667eea;
            padding-bottom: 10px;
        }
        .table-check {
            margin: 20px 0;
            padding: 20px;
            background: #f9f9f9;
            border-radius: 8px;
        }
        .table-name {
            font-size: 18px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 10px;
        }
        .exists {
            color: #48bb78;
            font-weight: bold;
        }
        .missing {
            color: #f56565;
            font-weight: bold;
        }
        .column-list {
            background: white;
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
            font-family: monospace;
            font-size: 13px;
        }
        .issue {
            background: #fee;
            border-left: 4px solid #f56565;
            padding: 10px;
            margin: 10px 0;
        }
        .sql-fix {
            background: #2d3748;
            color: #68d391;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
            font-family: monospace;
            white-space: pre-wrap;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Column Check Diagnostic</h1>
        
        <?php
        // Tables to check
        $tables_to_check = [
            'users' => ['pos_pin', 'tenant_id'],
            'branches' => ['tenant_id', 'is_active', 'type'],
            'settings' => ['tenant_id', 'branch_id', 'key', 'value'],
            'pos_device_registry' => ['device_token', 'device_fingerprint', 'tenant_id', 'branch_id']
        ];
        
        $all_issues = [];
        $sql_fixes = [];
        
        foreach ($tables_to_check as $table => $required_columns) {
            echo '<div class="table-check">';
            echo '<div class="table-name">Table: ' . $table . '</div>';
            
            // Check if table exists
            try {
                $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
                $table_exists = $stmt->fetch() !== false;
                
                if ($table_exists) {
                    echo '<p class="exists">✅ Table exists</p>';
                    
                    // Get columns
                    $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
                    $existing_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    echo '<div class="column-list">';
                    echo '<strong>Columns found:</strong> ' . implode(', ', $existing_columns);
                    echo '</div>';
                    
                    // Check for missing columns
                    $missing = [];
                    foreach ($required_columns as $col) {
                        if (!in_array($col, $existing_columns)) {
                            // Check for alternatives
                            $found_alternative = false;
                            
                            if ($table === 'settings') {
                                if ($col === 'key' && in_array('setting_key', $existing_columns)) {
                                    echo '<p>ℹ️ Found alternative: setting_key instead of key</p>';
                                    $found_alternative = true;
                                }
                                if ($col === 'value' && in_array('setting_value', $existing_columns)) {
                                    echo '<p>ℹ️ Found alternative: setting_value instead of value</p>';
                                    $found_alternative = true;
                                }
                            }
                            
                            if ($table === 'branches') {
                                if ($col === 'type' && in_array('branch_type', $existing_columns)) {
                                    echo '<p>ℹ️ Found alternative: branch_type instead of type</p>';
                                    $found_alternative = true;
                                }
                            }
                            
                            if (!$found_alternative) {
                                $missing[] = $col;
                            }
                        }
                    }
                    
                    if (!empty($missing)) {
                        $all_issues[$table] = $missing;
                        echo '<div class="issue">';
                        echo '<strong>❌ Missing columns:</strong> ' . implode(', ', $missing);
                        echo '</div>';
                        
                        // Generate SQL fix
                        foreach ($missing as $col) {
                            $sql = '';
                            switch ($col) {
                                case 'tenant_id':
                                    $sql = "ALTER TABLE `$table` ADD COLUMN `tenant_id` INT NOT NULL DEFAULT 1 AFTER `id`;";
                                    break;
                                case 'branch_id':
                                    $sql = "ALTER TABLE `$table` ADD COLUMN `branch_id` INT DEFAULT NULL AFTER `tenant_id`;";
                                    break;
                                case 'is_active':
                                    $sql = "ALTER TABLE `$table` ADD COLUMN `is_active` BOOLEAN DEFAULT TRUE;";
                                    break;
                                case 'type':
                                    $sql = "ALTER TABLE `$table` ADD COLUMN `type` ENUM('central_kitchen', 'sales_branch', 'warehouse') DEFAULT 'sales_branch';";
                                    break;
                                case 'pos_pin':
                                    $sql = "ALTER TABLE `$table` ADD COLUMN `pos_pin` VARCHAR(255) DEFAULT NULL AFTER `password`;";
                                    break;
                                case 'device_token':
                                    $sql = "ALTER TABLE `$table` ADD COLUMN `device_token` VARCHAR(255) NOT NULL;";
                                    break;
                                case 'device_fingerprint':
                                    $sql = "ALTER TABLE `$table` ADD COLUMN `device_fingerprint` VARCHAR(255) DEFAULT NULL;";
                                    break;
                                case 'key':
                                    $sql = "-- Column 'key' might already exist as 'setting_key'";
                                    break;
                                case 'value':
                                    $sql = "-- Column 'value' might already exist as 'setting_value'";
                                    break;
                            }
                            if ($sql) {
                                $sql_fixes[] = $sql;
                            }
                        }
                    } else {
                        echo '<p class="exists">✅ All required columns exist</p>';
                    }
                    
                } else {
                    echo '<p class="missing">❌ Table does not exist</p>';
                    $all_issues[$table] = ['TABLE_MISSING'];
                    
                    // Generate CREATE TABLE statement for missing tables
                    if ($table === 'settings') {
                        $sql_fixes[] = "CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `tenant_id` INT NOT NULL,
  `branch_id` INT DEFAULT NULL,
  `key` VARCHAR(100) NOT NULL,
  `value` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_setting` (`tenant_id`, `branch_id`, `key`),
  KEY `idx_settings_tenant` (`tenant_id`),
  KEY `idx_settings_branch` (`branch_id`),
  KEY `idx_settings_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
                    }
                    
                    if ($table === 'pos_device_registry') {
                        $sql_fixes[] = "CREATE TABLE IF NOT EXISTS `pos_device_registry` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `tenant_id` INT NOT NULL,
  `branch_id` INT NOT NULL,
  `device_token` VARCHAR(255) NOT NULL,
  `device_fingerprint` VARCHAR(255) DEFAULT NULL,
  `device_name` VARCHAR(100) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `registered_by` INT NOT NULL,
  `last_activity` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_active` BOOLEAN DEFAULT TRUE,
  `registered_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_device_token` (`device_token`),
  KEY `idx_device_token` (`device_token`),
  KEY `idx_tenant_branch` (`tenant_id`, `branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
                    }
                }
                
            } catch (Exception $e) {
                echo '<div class="issue">Error: ' . $e->getMessage() . '</div>';
            }
            
            echo '</div>';
        }
        
        // Summary
        if (!empty($all_issues)) {
            echo '<div style="margin-top: 30px; padding: 20px; background: #fff5f5; border-radius: 8px;">';
            echo '<h2 style="color: #c53030;">Issues Found: ' . count($all_issues) . ' table(s)</h2>';
            
            if (!empty($sql_fixes)) {
                echo '<h3>SQL Commands to Fix:</h3>';
                echo '<div class="sql-fix">';
                foreach ($sql_fixes as $sql) {
                    echo $sql . "\n\n";
                }
                echo '</div>';
                
                echo '<p><strong>Copy and run these SQL commands in your database to fix the issues.</strong></p>';
            }
            
            echo '</div>';
        } else {
            echo '<div style="margin-top: 30px; padding: 20px; background: #f0fdf4; border-radius: 8px;">';
            echo '<h2 style="color: #166534;">✅ All checks passed!</h2>';
            echo '<p>All required tables and columns exist.</p>';
            echo '</div>';
        }
        ?>
        
        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
            <a href="/test_device_setup.php" style="color: #667eea; text-decoration: none; font-weight: bold;">
                ← Back to Device Setup Test
            </a>
        </div>
    </div>
</body>
</html>