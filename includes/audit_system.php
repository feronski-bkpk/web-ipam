<?php
// includes/audit_system.php

class AuditSystem {
    
    // Настройки безопасности
    const MAX_FAILED_ATTEMPTS_PER_USER = 5;      // 5 попыток на одного пользователя
    const MAX_FAILED_ATTEMPTS_PER_IP = 10;       // 10 попыток с одного IP-адреса за час
    const BLOCK_DURATION_MINUTES = 30;           // Блокировка пользователя на 30 минут
    const IP_BLOCK_DURATION_MINUTES = 60;        // Блокировка IP на 60 минут
    
    /**
     * Логирование создания записи
     */
    public static function logCreate($module, $record_id, $description, $new_values = null, $client_info = null) {
        return self::log('create', $module, $record_id, $description, null, $new_values, $client_info);
    }
    
    /**
     * Логирование обновления записи
     */
    public static function logUpdate($module, $record_id, $description, $old_values = null, $new_values = null, $client_info = null) {
        return self::log('update', $module, $record_id, $description, $old_values, $new_values, $client_info);
    }
    
    /**
     * Логирование удаления записи
     */
    public static function logDelete($module, $record_id, $description, $old_values = null, $client_info = null) {
        return self::log('delete', $module, $record_id, $description, $old_values, null, $client_info);
    }
    
    /**
     * Логирование просмотра записи
     */
    public static function logView($module, $record_id, $description, $client_info = null) {
        return self::log('view', $module, $record_id, $description, null, null, $client_info);
    }
    
    /**
     * Логирование поиска
     */
    public static function logSearch($module, $description, $search_params = null, $client_info = null) {
        return self::log('search', $module, null, $description, null, $search_params, $client_info);
    }
    
    /**
     * Получение детальной информации о клиенте
     */
    public static function getClientInfo() {
        return [
            'ip_address' => self::getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'forwarded_for' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            'real_ip' => $_SERVER['HTTP_X_REAL_IP'] ?? null,
            'referer' => $_SERVER['HTTP_REFERER'] ?? null,
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Проверка блокировки IP (ТОЛЬКО из security_blocks)
     */
    public static function isIpBlocked($ip = null) {
        global $conn;
        
        // Проверяем существование таблицы security_blocks
        $table_check = $conn->query("SHOW TABLES LIKE 'security_blocks'");
        if ($table_check->num_rows === 0) {
            return ['blocked' => false];
        }
        
        $ip = $ip ?? self::getClientIp();
        $now = date('Y-m-d H:i:s');
        
        $stmt = $conn->prepare("
            SELECT id, blocked_until, reason, attempts 
            FROM security_blocks 
            WHERE ip_address = ? AND blocked_until > ? 
            ORDER BY blocked_until DESC 
            LIMIT 1
        ");
        
        $stmt->bind_param("ss", $ip, $now);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $block = $result->fetch_assoc();
            return [
                'blocked' => true,
                'until' => $block['blocked_until'],
                'reason' => $block['reason'],
                'attempts' => $block['attempts']
            ];
        }
        
        return ['blocked' => false];
    }
    
    /**
     * Проверка блокировки пользователя (ТОЛЬКО из users)
     */
    public static function isUserLocked($username) {
        global $conn;
        
        // Используем NOW() из MySQL для корректного сравнения времени
        $stmt = $conn->prepare("
            SELECT 
                login,
                account_locked_until, 
                failed_login_attempts,
                TIMESTAMPDIFF(SECOND, NOW(), account_locked_until) as seconds_left
            FROM users 
            WHERE login = ? AND account_locked_until IS NOT NULL
        ");
        
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            
            // Если время блокировки истекло - автоматически разблокируем
            if ($user['seconds_left'] <= 0) {
                $cleanup_stmt = $conn->prepare("
                    UPDATE users 
                    SET failed_login_attempts = 0, 
                        account_locked_until = NULL 
                    WHERE login = ?
                ");
                $cleanup_stmt->bind_param("s", $username);
                $cleanup_stmt->execute();
                $cleanup_stmt->close();
                
                return ['locked' => false];
            }
            
            return [
                'locked' => true,
                'until' => $user['account_locked_until'],
                'attempts' => $user['failed_login_attempts'],
                'seconds_left' => $user['seconds_left']
            ];
        }
        
        return ['locked' => false];
    }
    
    /**
     * Регистрация неудачной попытки входа
     */
    public static function registerFailedLogin($username, $reason = '') {
        global $conn;
        
        $client_info = self::getClientInfo();
        $ip_address = $client_info['ip_address'];
        
        try {
            // Логируем неудачную попытку
            self::logFailedLogin($username, $reason, $client_info);
            
            // Обновляем счетчик неудачных попыток для пользователя
            if ($username && strlen($username) <= 50) {
                $stmt = $conn->prepare("
                    UPDATE users 
                    SET failed_login_attempts = failed_login_attempts + 1,
                        account_locked_until = CASE 
                            WHEN failed_login_attempts + 1 >= ? THEN DATE_ADD(NOW(), INTERVAL ? MINUTE)
                            ELSE NULL 
                        END
                    WHERE login = ?
                ");
                
                $max_attempts = self::MAX_FAILED_ATTEMPTS_PER_USER;
                $block_duration = self::BLOCK_DURATION_MINUTES;
                $stmt->bind_param("iis", $max_attempts, $block_duration, $username);
                $stmt->execute();
            }
            
            // Проверяем количество попыток с этого IP за последний час
            $stmt = $conn->prepare("
                SELECT COUNT(*) as attempts 
                FROM system_audit_log 
                WHERE ip_address = ? 
                AND action_type = 'failed_login' 
                AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            
            $stmt->bind_param("s", $ip_address);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $ip_attempts = $row['attempts'] ?? 0;
            
            // Если превышен лимит для IP - работаем с блокировкой
            if ($ip_attempts >= self::MAX_FAILED_ATTEMPTS_PER_IP) {
                $block_check = self::isIpBlocked($ip_address);
                
                if ($block_check['blocked']) {
                    // ОБНОВЛЯЕМ существующую блокировку - увеличиваем счетчик и продлеваем время
                    $stmt = $conn->prepare("
                        UPDATE security_blocks 
                        SET attempts = attempts + 1, 
                            last_attempt = NOW(),
                            blocked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE)
                        WHERE ip_address = ? AND blocked_until > NOW()
                    ");
                    
                    $ip_block_duration = self::IP_BLOCK_DURATION_MINUTES;
                    $stmt->bind_param("is", $ip_block_duration, $ip_address);
                    $stmt->execute();
                } else {
                    // Создаем новую блокировку ТОЛЬКО если ее нет
                    $stmt = $conn->prepare("
                        INSERT INTO security_blocks 
                        (ip_address, block_type, attempts, first_attempt, last_attempt, blocked_until, reason) 
                        VALUES (?, 'failed_login', ?, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)
                    ");
                    
                    $reason_text = "Превышено максимальное количество неудачных попыток входа: {$ip_attempts}";
                    $ip_block_duration = self::IP_BLOCK_DURATION_MINUTES;
                    $stmt->bind_param("siis", $ip_address, $ip_attempts, $ip_block_duration, $reason_text);
                    $stmt->execute();
                    
                    // Логируем блокировку IP
                    self::logSecurityEvent("block_ip", "Блокировка IP {$ip_address} из-за {$ip_attempts} неудачных попыток входа");
                }
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log("Failed to register failed login: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Сброс счетчика неудачных попыток при успешном входе
     */
    public static function resetFailedAttempts($user_id) {
        global $conn;
        
        try {
            $stmt = $conn->prepare("
                UPDATE users 
                SET failed_login_attempts = 0, 
                    account_locked_until = NULL,
                    last_login = NOW()
                WHERE id = ?
            ");
            
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            
            return true;
            
        } catch (Exception $e) {
            error_log("Failed to reset failed attempts: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Удалить старые блокировки (вызывать периодически)
     */
    public static function cleanupExpiredBlocks() {
        global $conn;
        
        try {
            $stmt = $conn->prepare("
                DELETE FROM security_blocks 
                WHERE blocked_until <= NOW()
            ");
            
            $result = $stmt->execute();
            $stmt->close();
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Error cleaning up expired blocks: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Логирование действия в системе
     */
    public static function log($action_type, $module, $record_id = null, $description = '', $old_values = null, $new_values = null, $client_info = null) {
        global $conn;
        
        try {
            $stmt = $conn->prepare("
                INSERT INTO system_audit_log 
                (user_id, action_type, module, record_id, description, ip_address, user_agent, old_values, new_values) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            if (!$stmt) {
                error_log("Failed to prepare statement: " . $conn->error);
                return false;
            }
            
            // Безопасное получение user_id из сессии
            $user_id = null;
            if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id'])) {
                $user_id = $_SESSION['user_id'];
            }
            
            $client_info = $client_info ?? self::getClientInfo();
            
            $ip_address = $client_info['ip_address'];
            $user_agent = $client_info['user_agent'];
            
            $old_json = $old_values ? json_encode($old_values, JSON_UNESCAPED_UNICODE) : null;
            $new_json = $new_values ? json_encode($new_values, JSON_UNESCAPED_UNICODE) : null;
            
            $stmt->bind_param("ississsss", 
                $user_id, 
                $action_type, 
                $module, 
                $record_id, 
                $description,
                $ip_address,
                $user_agent,
                $old_json,
                $new_json
            );
            
            $result = $stmt->execute();
            
            if (!$result) {
                error_log("Failed to execute audit log: " . $stmt->error);
            }
            
            $stmt->close();
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Audit system error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Логирование успешного входа пользователя
     */
    public static function logLogin($user_id, $username, $client_info = null) {
        $description = "✅ Успешный вход пользователя: {$username}";
        return self::log('login', 'system', null, $description, null, null, $client_info);
    }
    
    /**
     * Логирование НЕУДАЧНОЙ попытки входа
     */
    public static function logFailedLogin($username, $reason = '', $client_info = null) {
        global $conn;
        
        try {
            $stmt = $conn->prepare("
                INSERT INTO system_audit_log 
                (user_id, action_type, module, record_id, description, ip_address, user_agent) 
                VALUES (NULL, 'failed_login', 'system', NULL, ?, ?, ?)
            ");
            
            if (!$stmt) {
                error_log("Failed to prepare failed login statement: " . $conn->error);
                return false;
            }
            
            // Безопасное описание без излишней информации для злоумышленников
            $safe_username = $username ? "логин: " . substr($username, 0, 3) . "***" : "логин не указан";
            $description = "🚫 Неудачная попытка входа ({$reason}) | {$safe_username}";
            
            $client_info = $client_info ?? self::getClientInfo();
            $ip_address = $client_info['ip_address'];
            $user_agent = $client_info['user_agent'];
            
            $stmt->bind_param("sss", 
                $description,
                $ip_address,
                $user_agent
            );
            
            $result = $stmt->execute();
            
            if (!$result) {
                error_log("Failed to execute failed login: " . $stmt->error);
            }
            
            $stmt->close();
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Failed login audit error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Логирование событий безопасности
     */
    public static function logSecurityEvent($event_type, $description, $client_info = null) {
        return self::log($event_type, 'security', null, $description, null, null, $client_info);
    }
    
    /**
     * Логирование выхода пользователя
     */
    public static function logLogout($user_id, $username) {
        $description = "Пользователь {$username} вышел из системы";
        return self::log('logout', 'system', null, $description);
    }
    
    /**
     * Получение реального IP клиента (работает за прокси)
     */
    public static function getClientIp() {
        $ip_keys = [
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR', 
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                // Берем первый IP из списка (клиентский)
                $ips = explode(',', $_SERVER[$key]);
                $ip = trim($ips[0]);
                
                // Проверяем валидность IP
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    
    /**
     * Получить логи с фильтрацией
     */
    public static function getLogs($filters = [], $limit = 100, $offset = 0) {
        global $conn;
        
        try {
            $sql = "
                SELECT 
                    al.*,
                    u.login as user_login,
                    u.full_name as user_name,
                    u.role as user_role
                FROM system_audit_log al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE 1=1
            ";
            
            $params = [];
            $types = "";
            
            // Применяем фильтры
            if (!empty($filters['module'])) {
                $sql .= " AND al.module = ?";
                $params[] = $filters['module'];
                $types .= "s";
            }
            
            if (!empty($filters['action_type'])) {
                $sql .= " AND al.action_type = ?";
                $params[] = $filters['action_type'];
                $types .= "s";
            }
            
            if (!empty($filters['user_id'])) {
                $sql .= " AND al.user_id = ?";
                $params[] = $filters['user_id'];
                $types .= "i";
            }
            
            if (!empty($filters['date_from'])) {
                $sql .= " AND DATE(al.created_at) >= ?";
                $params[] = $filters['date_from'];
                $types .= "s";
            }
            
            if (!empty($filters['date_to'])) {
                $sql .= " AND DATE(al.created_at) <= ?";
                $params[] = $filters['date_to'];
                $types .= "s";
            }
            
            if (!empty($filters['search'])) {
                $sql .= " AND (al.description LIKE ? OR u.login LIKE ? OR u.full_name LIKE ?)";
                $search_param = "%{$filters['search']}%";
                $params = array_merge($params, [$search_param, $search_param, $search_param]);
                $types .= "sss";
            }
            
            // Фильтр для неудачных попыток входа
            if (!empty($filters['failed_logins'])) {
                $sql .= " AND al.action_type = 'failed_login'";
            }
            
            $sql .= " ORDER BY al.created_at DESC LIMIT ? OFFSET ?";
            $params = array_merge($params, [$limit, $offset]);
            $types .= "ii";
            
            $stmt = $conn->prepare($sql);
            
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            
            $stmt->execute();
            $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Error getting audit logs: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Получить статистику по действиям
     */
    public static function getStats($period = 'today') {
        global $conn;
        
        try {
            $date_condition = self::getDateCondition($period);
            
            $sql = "
                SELECT 
                    action_type,
                    module,
                    COUNT(*) as count,
                    COUNT(DISTINCT user_id) as unique_users
                FROM system_audit_log 
                WHERE {$date_condition}
                GROUP BY action_type, module
                ORDER BY count DESC
            ";
            
            $result = $conn->query($sql);
            return $result->fetch_all(MYSQLI_ASSOC);
            
        } catch (Exception $e) {
            error_log("Error getting audit stats: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Получить расширенную статистику по неудачным попыткам входа
     */
    public static function getFailedLoginStats($period = 'today') {
        global $conn;
        
        try {
            $date_condition = self::getDateCondition($period);
            
            $sql = "
                SELECT 
                    description,
                    ip_address,
                    user_agent,
                    COUNT(*) as attempts,
                    MIN(created_at) as first_attempt,
                    MAX(created_at) as last_attempt,
                    GROUP_CONCAT(DISTINCT SUBSTRING_INDEX(SUBSTRING_INDEX(description, '(', -1), ')', 1) SEPARATOR ' | ') as reasons
                FROM system_audit_log 
                WHERE action_type = 'failed_login' 
                AND {$date_condition}
                GROUP BY ip_address, user_agent, DATE(created_at)
                ORDER BY attempts DESC, last_attempt DESC
            ";
            
            $result = $conn->query($sql);
            return $result->fetch_all(MYSQLI_ASSOC);
            
        } catch (Exception $e) {
            error_log("Error getting failed login stats: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Получить количество неудачных попыток входа за период
     */
    public static function getFailedLoginCount($period = 'today') {
        global $conn;
        
        try {
            $date_condition = self::getDateCondition($period);
            
            $sql = "
                SELECT COUNT(*) as count
                FROM system_audit_log 
                WHERE action_type = 'failed_login' 
                AND {$date_condition}
            ";
            
            $result = $conn->query($sql);
            $row = $result->fetch_assoc();
            return $row['count'] ?? 0;
            
        } catch (Exception $e) {
            error_log("Error getting failed login count: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Получить количество успешных входов за период
     */
    public static function getSuccessLoginCount($period = 'today') {
        global $conn;
        
        try {
            $date_condition = self::getDateCondition($period);
            
            $sql = "
                SELECT COUNT(*) as count
                FROM system_audit_log 
                WHERE action_type = 'login' 
                AND user_id IS NOT NULL
                AND {$date_condition}
            ";
            
            $result = $conn->query($sql);
            $row = $result->fetch_assoc();
            return $row['count'] ?? 0;
            
        } catch (Exception $e) {
            error_log("Error getting success login count: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Получить активные блокировки
     */
    public static function getActiveBlocks() {
        global $conn;
        
        try {
            $sql = "
                SELECT 
                    ip_address,
                    block_type,
                    attempts,
                    first_attempt,
                    last_attempt,
                    blocked_until,
                    reason,
                    TIMESTAMPDIFF(MINUTE, NOW(), blocked_until) as minutes_remaining
                FROM security_blocks 
                WHERE blocked_until > NOW()
                ORDER BY blocked_until DESC
            ";
            
            $result = $conn->query($sql);
            return $result->fetch_all(MYSQLI_ASSOC);
            
        } catch (Exception $e) {
            error_log("Error getting active blocks: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Получить топ подозрительных IP-адресов
     */
    public static function getSuspiciousIPs($limit = 10) {
        global $conn;
        
        try {
            $sql = "
                SELECT 
                    ip_address,
                    COUNT(*) as total_attempts,
                    COUNT(DISTINCT DATE(created_at)) as attack_days,
                    MIN(created_at) as first_seen,
                    MAX(created_at) as last_seen,
                    GROUP_CONCAT(DISTINCT SUBSTRING(description, 1, 50) SEPARATOR ' | ') as recent_attempts
                FROM system_audit_log 
                WHERE action_type = 'failed_login'
                AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY ip_address
                HAVING total_attempts > 5
                ORDER BY total_attempts DESC
                LIMIT ?
            ";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $limit);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Error getting suspicious IPs: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Получить условие WHERE для периода
     */
    private static function getDateCondition($period) {
        switch ($period) {
            case 'today':
                return "DATE(created_at) = CURDATE()";
            case 'week':
                return "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            case 'month':
                return "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            default:
                return "1=1";
        }
    }
    
    /**
     * Автоматическая очистка старых логов
     */
    public static function cleanupOldLogs($days = 90) {
        global $conn;
        
        try {
            $stmt = $conn->prepare("
                DELETE FROM system_audit_log 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->bind_param("i", $days);
            $result = $stmt->execute();
            $stmt->close();
            
            return $result;
        } catch (Exception $e) {
            error_log("Error cleaning old logs: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Статистика использования системы по пользователям
     */
    public static function getUserActivityStats($period = 'week') {
        global $conn;
        
        $date_condition = self::getDateCondition($period);
        
        $sql = "
            SELECT 
                u.id,
                u.login,
                u.full_name,
                u.role,
                COUNT(al.id) as total_actions,
                COUNT(DISTINCT DATE(al.created_at)) as active_days,
                MAX(al.created_at) as last_activity
            FROM users u
            LEFT JOIN system_audit_log al ON u.id = al.user_id
            WHERE {$date_condition}
            GROUP BY u.id, u.login, u.full_name, u.role
            ORDER BY total_actions DESC
        ";
        
        $result = $conn->query($sql);
        return $result->fetch_all(MYSQLI_ASSOC);
    }
}
?>