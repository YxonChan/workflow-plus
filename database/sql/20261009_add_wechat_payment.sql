-- WeChat Pay v3 Native payment orders. The application also creates this table
-- lazily through PaymentService::ensureSchema for existing installations.
CREATE TABLE IF NOT EXISTS `payment_orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_no` varchar(64) NOT NULL,
  `request_id` char(32) NOT NULL,
  `user_id` int unsigned NOT NULL,
  `package_id` varchar(32) NOT NULL,
  `app_id` varchar(64) NOT NULL,
  `merchant_id` varchar(32) NOT NULL,
  `amount_fen` int unsigned NOT NULL,
  `credit_amount` decimal(14,2) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending' COMMENT 'pending|paid|closed',
  `transaction_id` varchar(64) DEFAULT NULL,
  `code_url` varchar(1000) NOT NULL DEFAULT '',
  `paid_at` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `last_checked_at` datetime NOT NULL DEFAULT '2000-01-01 00:00:00',
  `meta_json` json DEFAULT NULL,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_payment_orders_order_no` (`order_no`),
  UNIQUE KEY `uniq_payment_orders_request` (`user_id`, `request_id`),
  UNIQUE KEY `uniq_payment_orders_transaction` (`transaction_id`),
  KEY `idx_payment_orders_user_time` (`user_id`, `create_time`),
  KEY `idx_payment_orders_status_time` (`status`, `create_time`),
  KEY `idx_payment_orders_package` (`user_id`, `package_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='WeChat Pay payment orders';
