-- Cash drawer sessions
CREATE TABLE `cash_drawer_sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `branch_id` int NOT NULL,
  `user_id` int NOT NULL,
  `opening_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `closing_amount` decimal(10,2) DEFAULT NULL,
  `expected_amount` decimal(10,2) DEFAULT NULL,
  `variance` decimal(10,2) DEFAULT NULL,
  `status` enum('open','closed','reconciled') NOT NULL DEFAULT 'open',
  `opened_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `closed_at` timestamp NULL DEFAULT NULL,
  `notes` text,
  PRIMARY KEY (`id`),
  KEY `idx_cash_drawer_tenant_branch` (`tenant_id`, `branch_id`),
  KEY `idx_cash_drawer_user` (`user_id`),
  KEY `idx_cash_drawer_status` (`status`),
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cash transactions
CREATE TABLE `cash_transactions` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `branch_id` int NOT NULL,
  `drawer_session_id` int NOT NULL,
  `order_id` int DEFAULT NULL,
  `transaction_type` enum('sale','refund','payout','float_add','float_remove') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `created_by` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cash_trans_session` (`drawer_session_id`),
  KEY `idx_cash_trans_order` (`order_id`),
  FOREIGN KEY (`drawer_session_id`) REFERENCES `cash_drawer_sessions` (`id`),
  FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;