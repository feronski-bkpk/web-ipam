<?php
// includes/validation.php
class Validation {
    
    public static function sanitizeInput($data) {
        if (is_array($data)) {
            return array_map([self::class, 'sanitizeInput'], $data);
        }
        return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
    }
    
    public static function validateIP($ip) {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
    
    public static function validateMAC($mac) {
        return preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $mac);
    }
    
    public static function validateCIDR($cidr) {
        return $cidr >= 0 && $cidr <= 32;
    }
    
    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    public static function preventXSS($data) {
        if (is_array($data)) {
            return array_map([self::class, 'preventXSS'], $data);
        }
        return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    }
}
?>