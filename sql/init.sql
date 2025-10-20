-- Web-IPAM Database Initialization Script
-- Версия: 5.0 (расширенная система безопасности и аудита)

SET FOREIGN_KEY_CHECKS = 0;

-- Таблица пользователей системы
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `login` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','engineer','operator') NOT NULL DEFAULT 'operator',
  `full_name` varchar(100) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_login` datetime DEFAULT NULL,
  `failed_login_attempts` int DEFAULT '0',
  `account_locked_until` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `login` (`login`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Таблица клиентов-абонентов
DROP TABLE IF EXISTS `clients`;
CREATE TABLE `clients` (
  `id` int NOT NULL AUTO_INCREMENT,
  `contract_number` varchar(20) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `address` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contract_number` (`contract_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Таблица подсетей
DROP TABLE IF EXISTS `subnets`;
CREATE TABLE `subnets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `network_address` varchar(15) NOT NULL,
  `cidr_mask` int NOT NULL,
  `gateway` varchar(15) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_subnet` (`network_address`,`cidr_mask`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Таблица сетевых устройств
DROP TABLE IF EXISTS `devices`;
CREATE TABLE `devices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mac_address` varchar(17) NOT NULL,
  `model` varchar(100) DEFAULT NULL,
  `serial_number` varchar(50) DEFAULT NULL,
  `client_id` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mac_address` (`mac_address`),
  KEY `fk_devices_client` (`client_id`),
  CONSTRAINT `fk_devices_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Таблица IP-адресов (основная таблица системы)
DROP TABLE IF EXISTS `ip_addresses`;
CREATE TABLE `ip_addresses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(15) NOT NULL,
  `subnet_id` int NOT NULL,
  `device_id` int DEFAULT NULL,
  `type` enum('white','gray') NOT NULL DEFAULT 'gray',
  `status` enum('active','free','reserved') NOT NULL DEFAULT 'free',
  `description` varchar(255) DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_ip_subnet` (`ip_address`,`subnet_id`),
  UNIQUE KEY `unique_device_ip` (`device_id`),
  KEY `fk_ips_subnet` (`subnet_id`),
  KEY `fk_ips_created_by` (`created_by`),
  CONSTRAINT `fk_ips_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ips_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ips_subnet` FOREIGN KEY (`subnet_id`) REFERENCES `subnets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Общая таблица аудита для всех действий в системе
DROP TABLE IF EXISTS `system_audit_log`;
CREATE TABLE `system_audit_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NULL,
  `action_type` varchar(50) NOT NULL,
  `module` varchar(50) NOT NULL,
  `record_id` int DEFAULT NULL,
  `description` text,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `old_values` json DEFAULT NULL,
  `new_values` json DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_audit_user` (`user_id`),
  KEY `idx_audit_module` (`module`),
  KEY `idx_audit_action` (`action_type`),
  KEY `idx_audit_created` (`created_at`),
  KEY `idx_audit_ip` (`ip_address`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Таблица блокировок для защиты от brute-force атак
DROP TABLE IF EXISTS `security_blocks`;
CREATE TABLE `security_blocks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `block_type` enum('failed_login','suspicious_activity') NOT NULL,
  `attempts` int NOT NULL DEFAULT 1,
  `first_attempt` datetime NOT NULL,
  `last_attempt` datetime NOT NULL,
  `blocked_until` datetime NOT NULL,
  `reason` text,
  PRIMARY KEY (`id`),
  KEY `idx_block_ip` (`ip_address`),
  KEY `idx_block_type` (`block_type`),
  KEY `idx_block_until` (`blocked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- Устанавливаем московский часовой пояс для корректной работы блокировок
SET time_zone = '+03:00';

-- Начальные данные
-- Пароль: 'admin123' (ПРАВИЛЬНО захешированный)
INSERT INTO `users` (`login`, `password_hash`, `role`, `full_name`) VALUES
('admin', '$2y$10$L/0j/QeLF7LqLrlvYwXvQu3nr6jbqHGA4CubHqfFyK3LHTD5Oco3u', 'admin', 'Администратор Системы'),
('engineer', '$2y$10$Eo99SBo2VSU8JpzPpBdXt.3oRMCPV.LQwjW/7Up.7rRsolIA6awjm', 'engineer', 'Петров Иван Сергеевич'),
('operator', '$2y$10$vsZ/sTyAwDmEJ.RwnOq10OqsERid2uaR4oqwf2blp7E13bDBPN5LO', 'operator', 'Сидорова Мария Петровна');

-- Тестовые подсети
INSERT INTO `subnets` (`network_address`, `cidr_mask`, `gateway`, `description`) VALUES
('192.168.1.0', 24, '192.168.1.1', 'Основная подсеть офиса'),
('10.10.0.0', 23, '10.10.0.1', 'Подсеть для абонентов (серые IP)'),
('95.165.150.0', 27, '95.165.150.1', 'Пул белых IP-адресов для клиентов'),
('172.16.1.0', 28, '172.16.1.1', 'Служебная сеть для оборудования');

-- Тестовые клиенты
INSERT INTO `clients` (`contract_number`, `full_name`, `address`, `phone`) VALUES
('ДГ-2024-001', 'Иванов Алексей Петрович', 'ул. Ленина, д. 10, кв. 25', '+7 (900) 123-45-67'),
('ДГ-2024-002', 'Смирнова Ольга Васильевна', 'пр. Мира, д. 55, кв. 12', '+7 (900) 234-56-78'),
('ДГ-2024-003', 'Козлов Дмитрий Игоревич', 'ул. Советская, д. 33, кв. 7', '+7 (900) 345-67-89');

-- Тестовые устройства
INSERT INTO `devices` (`mac_address`, `model`, `serial_number`, `client_id`) VALUES
('AA:BB:CC:DD:EE:01', 'TP-Link Archer C7', 'SN123456789', 1),
('AA:BB:CC:DD:EE:02', 'D-Link DIR-825', 'SN987654321', 2),
('AA:BB:CC:DD:EE:03', 'ASUS RT-AC68U', 'SN555666777', 3);

-- Тестовые IP-адреса
INSERT INTO `ip_addresses` (`ip_address`, `subnet_id`, `device_id`, `type`, `status`, `description`, `created_by`) VALUES
('192.168.1.1', 1, NULL, 'gray', 'active', 'Основной шлюз', 1),
('192.168.1.10', 1, NULL, 'gray', 'active', 'Сервер', 1),
('10.10.0.10', 2, 1, 'gray', 'active', 'Абонент Иванов А.П.', 2),
('10.10.0.11', 2, 2, 'gray', 'active', 'Абонент Смирнова О.В.', 2),
('95.165.150.5', 3, 3, 'white', 'active', 'Белый IP для Козлова Д.И.', 2),
('95.165.150.6', 3, NULL, 'white', 'free', 'Свободный белый IP', NULL),
('10.10.0.15', 2, NULL, 'gray', 'free', 'Свободный серый IP', NULL);

-- Сообщение об успешном выполнении
SELECT 'Web-IPAM database initialized successfully!' as status;