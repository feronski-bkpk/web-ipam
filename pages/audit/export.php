<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
require_once '../../includes/audit_system.php';
requireAuth();
requireRole('admin');

// Параметры фильтрации
$filters = [
    'module' => $_GET['module'] ?? '',
    'action_type' => $_GET['action_type'] ?? '',
    'user_id' => $_GET['user_id'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'search' => $_GET['search'] ?? '',
    'failed_logins' => $_GET['failed_logins'] ?? ''
];

// Получаем все логи без пагинации
$logs = AuditSystem::getLogs($filters, 10000, 0);

// Устанавливаем заголовки для скачивания CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=audit_export_' . date('Y-m-d_H-i-s') . '.csv');
header('Pragma: no-cache');
header('Expires: 0');

// Создаем output stream
$output = fopen('php://output', 'w');

// Добавляем UTF-8 BOM для правильного отображения в Excel
fwrite($output, "\xEF\xBB\xBF");

// Заголовки CSV
fputcsv($output, [
    'Дата/Время',
    'Пользователь',
    'Логин',
    'Модуль', 
    'Действие',
    'Описание',
    'ID записи',
    'IP-адрес',
    'User Agent'
], ';');

// Данные
foreach ($logs as $log) {
    fputcsv($output, [
        $log['created_at'],
        $log['user_name'] ?? '—',
        $log['user_login'] ?? '—',
        $log['module'],
        $log['action_type'],
        $log['description'],
        $log['record_id'] ?? '—',
        $log['ip_address'],
        $log['user_agent'] ?? '—'
    ], ';');
}

fclose($output);
exit;