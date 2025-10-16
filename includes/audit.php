<?php
// includes/audit.php
require_once 'db_connect.php';

function logIpAddressAction($ip_address_id, $action, $old_values = null, $new_values = null) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            INSERT INTO ip_audit_log 
            (ip_address_id, user_id, action, old_values, new_values) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $old_json = $old_values ? json_encode($old_values, JSON_UNESCAPED_UNICODE) : null;
        $new_json = $new_values ? json_encode($new_values, JSON_UNESCAPED_UNICODE) : null;
        
        $stmt->bind_param("iisss", 
            $ip_address_id, 
            $_SESSION['user_id'], 
            $action, 
            $old_json, 
            $new_json
        );
        
        $stmt->execute();
        $stmt->close();
        
    } catch (Exception $e) {
        error_log("Audit log error: " . $e->getMessage());
    }
}

function getIpAddressAuditLog($ip_address_id) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            SELECT al.*, u.login, u.full_name 
            FROM ip_audit_log al 
            JOIN users u ON al.user_id = u.id 
            WHERE al.ip_address_id = ? 
            ORDER BY al.created_at DESC
        ");
        $stmt->bind_param("i", $ip_address_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        return $result;
    } catch (Exception $e) {
        error_log("Error getting audit log: " . $e->getMessage());
        return [];
    }
}
?>