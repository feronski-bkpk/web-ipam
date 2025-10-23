<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
require_once '../../includes/audit_system.php';
requireAuth();
requireRole('admin'); // Только админы могут смотреть логи

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

$page = max(1, intval($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

// Получаем логи
$logs = AuditSystem::getLogs($filters, $limit, $offset);

// Получаем статистику
$stats_today = AuditSystem::getStats('today');
$stats_week = AuditSystem::getStats('week');
$failed_logins_stats = AuditSystem::getFailedLoginStats('today');

// Получаем список пользователей для фильтра
$users_result = $conn->query("SELECT id, login, full_name FROM users ORDER BY login");
$users = $users_result->fetch_all(MYSQLI_ASSOC);

// Получаем активные блокировки
$active_blocks = AuditSystem::getActiveBlocks();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Журнал аудита - Web-IPAM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../../assets/css/style.css" rel="stylesheet">
    <style>
        .security-critical { background-color: #fff3cd; }
        .security-high { background-color: #f8d7da; }
        .security-medium { background-color: #ffeaa7; }
        .badge-security { background-color: #dc3545; }
    </style>
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    
    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1>Журнал аудита системы</h1>
                    <div>
                        <a href="export.php?<?php echo http_build_query($_GET); ?>" class="btn btn-outline-success me-2">📊 Экспорт отчетов</a>
                        <a href="security.php" class="btn btn-outline-warning">🛡️ Безопасность</a>
                    </div>
                </div>

                <!-- Статистика безопасности -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card border-primary">
                            <div class="card-body">
                                <h6>Статистика за сегодня</h6>
                                <?php 
                                $success_logins = AuditSystem::getSuccessLoginCount('today');
                                $failed_logins = AuditSystem::getFailedLoginCount('today');
                                ?>
                                <div class="d-flex justify-content-between">
                                    <strong>Успешные входы:</strong>
                                    <span class="badge bg-success"><?php echo $success_logins; ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <strong>Неудачные входы:</strong>
                                    <span class="badge bg-danger"><?php echo $failed_logins; ?></span>
                                </div>
                                <hr>
                                <?php foreach ($stats_today as $stat): 
                                    if ($stat['module'] === 'system' && in_array($stat['action_type'], ['login', 'failed_login'])) continue;
                                ?>
                                    <div class="d-flex justify-content-between">
                                        <span><?php echo "{$stat['module']}.{$stat['action_type']}"; ?></span>
                                        <span class="badge bg-info"><?php echo $stat['count']; ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card border-success">
                            <div class="card-body">
                                <h6>Статистика за неделю</h6>
                                <?php 
                                $week_success = AuditSystem::getSuccessLoginCount('week');
                                $week_failed = AuditSystem::getFailedLoginCount('week');
                                ?>
                                <div class="d-flex justify-content-between">
                                    <strong>Успешные входы:</strong>
                                    <span class="badge bg-success"><?php echo $week_success; ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <strong>Неудачные входы:</strong>
                                    <span class="badge bg-danger"><?php echo $week_failed; ?></span>
                                </div>
                                <hr>
                                <?php foreach ($stats_week as $stat): 
                                    if ($stat['module'] === 'system' && in_array($stat['action_type'], ['login', 'failed_login'])) continue;
                                ?>
                                    <div class="d-flex justify-content-between">
                                        <span><?php echo "{$stat['module']}.{$stat['action_type']}"; ?></span>
                                        <span class="badge bg-secondary"><?php echo $stat['count']; ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card border-warning">
                            <div class="card-body">
                                <h6>Подозрительная активность</h6>
                                <?php if (empty($failed_logins_stats)): ?>
                                    <p class="text-muted">Нет подозрительной активности</p>
                                <?php else: ?>
                                    <?php foreach ($failed_logins_stats as $stat): ?>
                                        <div class="mb-3 p-2 border rounded <?php echo $stat['attempts'] > 5 ? 'security-high' : 'security-medium'; ?>">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <small class="text-muted">
                                                    <strong>IP:</strong> <?php echo htmlspecialchars($stat['ip_address']); ?>
                                                </small>
                                                <span class="badge bg-<?php echo $stat['attempts'] > 5 ? 'danger' : 'warning'; ?>">
                                                    <?php echo $stat['attempts']; ?> попыток
                                                </span>
                                            </div>
                                            <div class="mt-1">
                                                <small>
                                                    <strong>Период:</strong> 
                                                    <?php echo date('H:i', strtotime($stat['first_attempt'])); ?> - 
                                                    <?php echo date('H:i', strtotime($stat['last_attempt'])); ?>
                                                </small>
                                            </div>
                                            <?php if (!empty($stat['reasons'])): ?>
                                                <div class="mt-1">
                                                    <small>
                                                        <strong>Причины:</strong> 
                                                        <?php echo htmlspecialchars($stat['reasons']); ?>
                                                    </small>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($stat['user_agent'])): ?>
                                                <div class="mt-1">
                                                    <small>
                                                        <strong>Браузер:</strong> 
                                                        <?php echo htmlspecialchars(substr($stat['user_agent'], 0, 50)); ?>...
                                                    </small>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Активные блокировки -->
                <?php if (!empty($active_blocks)): ?>
                <div class="card border-danger mb-4">
                    <div class="card-header bg-danger text-white">
                        <h5 class="card-title mb-0">🚫 Активные блокировки</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>IP-адрес</th>
                                        <th>Тип</th>
                                        <th>Попыток</th>
                                        <th>Заблокирован до</th>
                                        <th>Осталось</th>
                                        <th>Причина</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($active_blocks as $block): ?>
                                    <tr class="security-critical">
                                        <td><code><?php echo htmlspecialchars($block['ip_address']); ?></code></td>
                                        <td><span class="badge bg-danger"><?php echo $block['block_type']; ?></span></td>
                                        <td><span class="badge bg-warning"><?php echo $block['attempts']; ?></span></td>
                                        <td><?php echo date('H:i:s', strtotime($block['blocked_until'])); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $block['minutes_remaining'] > 30 ? 'warning' : 'danger'; ?>">
                                                <?php echo $block['minutes_remaining']; ?> мин.
                                            </span>
                                        </td>
                                        <td><small><?php echo htmlspecialchars($block['reason']); ?></small></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Фильтры -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Фильтры</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-2">
                                <label class="form-label">Модуль</label>
                                <select name="module" class="form-select">
                                    <option value="">Все модули</option>
                                    <option value="system" <?php echo $filters['module'] === 'system' ? 'selected' : ''; ?>>Система</option>
                                    <option value="users" <?php echo $filters['module'] === 'users' ? 'selected' : ''; ?>>Пользователи</option>
                                    <option value="clients" <?php echo $filters['module'] === 'clients' ? 'selected' : ''; ?>>Клиенты</option>
                                    <option value="devices" <?php echo $filters['module'] === 'devices' ? 'selected' : ''; ?>>Устройства</option>
                                    <option value="subnets" <?php echo $filters['module'] === 'subnets' ? 'selected' : ''; ?>>Подсети</option>
                                    <option value="ip_addresses" <?php echo $filters['module'] === 'ip_addresses' ? 'selected' : ''; ?>>IP-адреса</option>
                                    <option value="security" <?php echo $filters['module'] === 'security' ? 'selected' : ''; ?>>Безопасность</option>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">Действие</label>
                                <select name="action_type" class="form-select">
                                    <option value="">Все действия</option>
                                    <option value="login" <?php echo $filters['action_type'] === 'login' ? 'selected' : ''; ?>>Вход</option>
                                    <option value="failed_login" <?php echo $filters['action_type'] === 'failed_login' ? 'selected' : ''; ?>>Неудачный вход</option>
                                    <option value="logout" <?php echo $filters['action_type'] === 'logout' ? 'selected' : ''; ?>>Выход</option>
                                    <option value="create" <?php echo $filters['action_type'] === 'create' ? 'selected' : ''; ?>>Создание</option>
                                    <option value="update" <?php echo $filters['action_type'] === 'update' ? 'selected' : ''; ?>>Изменение</option>
                                    <option value="delete" <?php echo $filters['action_type'] === 'delete' ? 'selected' : ''; ?>>Удаление</option>
                                    <option value="search" <?php echo $filters['action_type'] === 'search' ? 'selected' : ''; ?>>Поиск</option>
                                    <option value="view" <?php echo $filters['action_type'] === 'view' ? 'selected' : ''; ?>>Просмотр</option>
                                    <option value="block_ip" <?php echo $filters['action_type'] === 'block_ip' ? 'selected' : ''; ?>>Блокировка IP</option>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">Пользователь</label>
                                <select name="user_id" class="form-select">
                                    <option value="">Все пользователи</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?php echo $user['id']; ?>" <?php echo $filters['user_id'] == $user['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($user['login']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">Тип записей</label>
                                <select name="failed_logins" class="form-select">
                                    <option value="">Все записи</option>
                                    <option value="1" <?php echo $filters['failed_logins'] ? 'selected' : ''; ?>>Только неудачные входы</option>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">Поиск</label>
                                <input type="text" name="search" class="form-control" placeholder="Поиск по описанию..." value="<?php echo htmlspecialchars($filters['search']); ?>">
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">Дата с</label>
                                <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($filters['date_from']); ?>">
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">Дата по</label>
                                <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($filters['date_to']); ?>">
                            </div>
                            
                            <div class="col-md-12">
                                <button type="submit" class="btn btn-primary">Применить фильтры</button>
                                <a href="list.php" class="btn btn-secondary">Сбросить</a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Таблица логов -->
                <div class="card">
                    <div class="card-body">
                        <?php if (empty($logs)): ?>
                            <div class="text-center py-4">
                                <p class="text-muted">Записи не найдены</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Дата/Время</th>
                                            <th>Пользователь</th>
                                            <th>Модуль</th>
                                            <th>Действие</th>
                                            <th>Описание</th>
                                            <th>IP-адрес</th>
                                            <th>Детали</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($logs as $log): ?>
                                            <tr class="<?php echo $log['action_type'] === 'failed_login' ? 'table-warning' : ''; ?>">
                                                <td>
                                                    <small><?php echo date('d.m.Y H:i:s', strtotime($log['created_at'])); ?></small>
                                                </td>
                                                <td>
                                                    <?php if ($log['user_name']): ?>
                                                        <div><?php echo htmlspecialchars($log['user_name']); ?></div>
                                                        <small class="text-muted"><?php echo htmlspecialchars($log['user_login']); ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($log['module']); ?></span>
                                                </td>
                                                <td>
                                                    <?php
                                                    $action_badges = [
                                                        'login' => 'success',
                                                        'failed_login' => 'danger',
                                                        'logout' => 'secondary', 
                                                        'create' => 'primary',
                                                        'update' => 'warning',
                                                        'delete' => 'danger',
                                                        'search' => 'info',
                                                        'view' => 'light',
                                                        'block_ip' => 'danger'
                                                    ];
                                                    $badge_class = $action_badges[$log['action_type']] ?? 'light';
                                                    ?>
                                                    <span class="badge bg-<?php echo $badge_class; ?>">
                                                        <?php echo htmlspecialchars($log['action_type']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $safe_description = htmlspecialchars($log['description']);
                                                    echo $safe_description; 
                                                    ?>
                                                    <?php if ($log['record_id']): ?>
                                                        <br><small class="text-muted">ID: <?php echo $log['record_id']; ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <code><?php echo htmlspecialchars($log['ip_address']); ?></code>
                                                </td>
                                                <td>
                                                    <?php if ($log['old_values'] || $log['new_values']): ?>
                                                        <button class="btn btn-sm btn-outline-info" 
                                                                onclick="showSafeDetails(<?php echo htmlspecialchars(json_encode([
                                                                    'old' => AuditSecurityHelper::sanitizeAuditData($log['old_values'] ? json_decode($log['old_values'], true) : null),
                                                                    'new' => AuditSecurityHelper::sanitizeAuditData($log['new_values'] ? json_decode($log['new_values'], true) : null)
                                                                ])); ?>)">
                                                            📋 Детали
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Пагинация -->
                            <nav>
                                <ul class="pagination justify-content-center">
                                    <?php if ($page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">Назад</a>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <li class="page-item disabled">
                                        <span class="page-link">Страница <?php echo $page; ?></span>
                                    </li>
                                    
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Вперед</a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно для деталей (БЕЗОПАСНОЕ) -->
    <div class="modal fade" id="detailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Детали изменения</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <small>⚠️ Конфиденциальные данные скрыты для безопасности</small>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Старые значения:</h6>
                            <pre id="oldValues" class="bg-light p-3"></pre>
                        </div>
                        <div class="col-md-6">
                            <h6>Новые значения:</h6>
                            <pre id="newValues" class="bg-light p-3"></pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // БЕЗОПАСНОЕ отображение деталей
        function showSafeDetails(data) {
            document.getElementById('oldValues').textContent = data.old ? JSON.stringify(data.old, null, 2) : 'Нет данных';
            document.getElementById('newValues').textContent = data.new ? JSON.stringify(data.new, null, 2) : 'Нет данных';
            new bootstrap.Modal(document.getElementById('detailsModal')).show();
        }
    </script>
</body>
</html>

<?php
// Класс для очистки конфиденциальных данных в логах
class AuditSecurityHelper {
    
    /**
     * Очистка конфиденциальных данных перед отображением
     */
    public static function sanitizeAuditData($data) {
        if (!$data) return null;
        
        $sensitive_fields = [
            'password', 'password_hash', 'secret', 'token', 'api_key',
            'private_key', 'credit_card', 'phone', 'email', 'passport'
        ];
        
        $sanitized = [];
        foreach ($data as $key => $value) {
            // Проверяем, является ли поле конфиденциальным
            $is_sensitive = false;
            foreach ($sensitive_fields as $sensitive) {
                if (stripos($key, $sensitive) !== false) {
                    $is_sensitive = true;
                    break;
                }
            }
            
            if ($is_sensitive) {
                $sanitized[$key] = '***СКРЫТО***';
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }
}
?>