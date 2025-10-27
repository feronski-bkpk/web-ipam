<?php
// includes/csrf.php
class CSRFProtection {
    
    public static function generateToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    public static function validateToken($token) {
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }
    
    public static function getTokenField() {
        return '<input type="hidden" name="csrf_token" value="' . self::generateToken() . '">';
    }
}
?>