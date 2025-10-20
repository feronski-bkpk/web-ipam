<?php
// logout.php
session_start();

// Сохраняем информацию о пользователе для логирования
$user_id = $_SESSION['user_id'] ?? null;
$username = $_SESSION['user_login'] ?? 'unknown';

// Уничтожаем сессию
session_destroy();

// Логируем выход только если был залогиненный пользователь
if ($user_id) {
    require_once 'includes/db_connect.php';
    require_once 'includes/audit_system.php';
    
    AuditSystem::logLogout($user_id, $username);
}

// Перенаправляем на страницу входа
header('Location: login.php');
exit();
?>