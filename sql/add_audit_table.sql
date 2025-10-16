-- Таблица для аудита изменений
CREATE TABLE IF NOT EXISTS `ip_audit_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip_address_id` int NOT NULL,
  `user_id` int NOT NULL,
  `action` enum('created','updated','deleted') NOT NULL,
  `old_values` json DEFAULT NULL,
  `new_values` json DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_audit_ip` (`ip_address_id`),
  KEY `fk_audit_user` (`user_id`),
  CONSTRAINT `fk_audit_ip` FOREIGN KEY (`ip_address_id`) REFERENCES `ip_addresses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Добавляем поле updated_at в ip_addresses
ALTER TABLE `ip_addresses` ADD COLUMN `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;