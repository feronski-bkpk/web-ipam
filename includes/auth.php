<?php
// includes/auth.php
session_start();

// Таймаут сессии - 1 час
const SESSION_TIMEOUT = 3600;

// Инициализация сессионных переменных если их нет
function initSessionVars() {
    $_SESSION['user_id'] = $_SESSION['user_id'] ?? null;
    $_SESSION['user_login'] = $_SESSION['user_login'] ?? '';
    $_SESSION['user_name'] = $_SESSION['user_name'] ?? '';
    $_SESSION['user_role'] = $_SESSION['user_role'] ?? '';
    $_SESSION['login_time'] = $_SESSION['login_time'] ?? 0;
    $_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? '';
}

// Функция для проверки авторизации с таймаутом
function isLoggedIn() {
    initSessionVars();
    
    if (!$_SESSION['user_id'] || !$_SESSION['login_time']) {
        return false;
    }
    
    // Проверяем таймаут сессии
    if (time() - $_SESSION['login_time'] > SESSION_TIMEOUT) {
        session_destroy();
        return false;
    }
    
    // Обновляем время активности
    $_SESSION['login_time'] = time();
    
    return true;
}

// Функция для проверки роли пользователя
function hasRole($requiredRole) {
    if (!isLoggedIn()) return false;
    return ($_SESSION['user_role'] ?? '') === $requiredRole;
}

// Функция для проверки нескольких ролей
function hasAnyRole($allowedRoles) {
    if (!isLoggedIn()) return false;
    return in_array($_SESSION['user_role'] ?? '', $allowedRoles);
}

// Функция для редиректа неавторизованных пользователей
function requireAuth() {
    if (!isLoggedIn()) {
        header('Location: /web-ipam/login.php');
        exit();
    }
}

// Функция для редиректа по ролям
function requireRole($requiredRole) {
    requireAuth();
    if (!hasRole($requiredRole)) {
        header('Location: /web-ipam/index.php');
        exit();
    }
}

// Функция для проверки нескольких ролей с редиректом
function requireAnyRole($allowedRoles) {
    requireAuth();
    if (!hasAnyRole($allowedRoles)) {
        header('Location: /web-ipam/index.php');
        exit();
    }
}

// Защита от CSRF - генерация токена
function generateCsrfToken() {
    initSessionVars();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Валидация CSRF токена
function validateCsrfToken($token) {
    initSessionVars();
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Безопасный редирект
function safeRedirect($url) {
    header('Location: ' . $url);
    exit();
}
?>