<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
require_once '../../includes/audit_system.php';
requireAuth();
requireRole('admin');

// Проверяем наличие ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: list.php');
    exit();
}

$ip_id = intval($_GET['id']);

// Получаем данные IP-адреса перед удалением
try {
    $ip_stmt = $conn->prepare("
        SELECT ip.*, d.mac_address, c.full_name as client_name, s.network_address, s.cidr_mask
        FROM ip_addresses ip 
        LEFT JOIN devices d ON ip.device_id = d.id 
        LEFT JOIN clients c ON d.client_id = c.id
        LEFT JOIN subnets s ON ip.subnet_id = s.id
        WHERE ip.id = ?
    ");
    $ip_stmt->bind_param("i", $ip_id);
    $ip_stmt->execute();
    $ip_data = $ip_stmt->get_result()->fetch_assoc();
    $ip_stmt->close();
    
    if (!$ip_data) {
        header('Location: list.php');
        exit();
    }
} catch (Exception $e) {
    error_log("Error fetching IP data: " . $e->getMessage());
    header('Location: list.php');
    exit();
}

// Обработка подтверждения удаления
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // ЛОГИРУЕМ УДАЛЕНИЕ В СИСТЕМЕ АУДИТА
        AuditSystem::logDelete('ip_addresses', $ip_id, 
            "Удален IP-адрес: {$ip_data['ip_address']} (подсеть: {$ip_data['network_address']}/{$ip_data['cidr_mask']})", 
            [
                'ip_address' => $ip_data['ip_address'],
                'subnet_id' => $ip_data['subnet_id'],
                'device_id' => $ip_data['device_id'],
                'type' => $ip_data['type'],
                'status' => $ip_data['status'],
                'description' => $ip_data['description'],
                'mac_address' => $ip_data['mac_address'],
                'client_name' => $ip_data['client_name']
            ]
        );
        
        // Удаляем IP-адрес
        $delete_stmt = $conn->prepare("DELETE FROM ip_addresses WHERE id = ?");
        $delete_stmt->bind_param("i", $ip_id);
        
        if ($delete_stmt->execute()) {
            $_SESSION['success_message'] = "IP-адрес {$ip_data['ip_address']} успешно удален";
            header('Location: list.php');
            exit();
        } else {
            $error = "Ошибка при удалении: " . $delete_stmt->error;
        }
        
        $delete_stmt->close();
        
    } catch (Exception $e) {
        $error = "Ошибка базы данных: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Удалить IP-адрес - Web-IPAM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link href="../../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    
    <div class="container mt-4">
        <!-- Заголовок и навигация -->
        <div class="row mb-4">
            <div class="col-12">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="../../index.php">Главная</a></li>
                        <li class="breadcrumb-item"><a href="list.php">IP-адреса</a></li>
                        <li class="breadcrumb-item active">Удалить IP-адрес</li>
                    </ol>
                </nav>

                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="h3 mb-1">Удаление IP-адреса</h1>
                        <p class="text-muted mb-0">Подтверждение удаления записи из системы</p>
                    </div>
                    <a href="list.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>Назад к списку
                    </a>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-8 mx-auto">
                <!-- Уведомления -->
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Карточка подтверждения -->
                <div class="card stat-card border-danger">
                    <div class="card-header bg-danger text-white">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>Подтверждение удаления
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-warning border-warning">
                            <div class="d-flex">
                                <i class="bi bi-exclamation-triangle-fill text-warning me-3 fs-4"></i>
                                <div>
                                    <h5 class="alert-heading">Внимание! Это действие необратимо</h5>
                                    <p class="mb-0">Вы собираетесь удалить IP-адрес из системы. Все данные будут записаны в журнал аудита, но восстановление будет невозможно.</p>
                                </div>
                            </div>
                        </div>

                        <!-- Информация об IP-адресе -->
                        <div class="card border-0 bg-light">
                            <div class="card-body">
                                <h6 class="card-title text-primary mb-3">
                                    <i class="bi bi-info-circle me-2"></i>Информация об IP-адресе
                                </h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <table class="table table-sm table-borderless">
                                            <tr>
                                                <td class="text-muted" style="width: 120px;">IP-адрес:</td>
                                                <td><strong><code><?php echo htmlspecialchars($ip_data['ip_address']); ?></code></strong></td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Подсеть:</td>
                                                <td><?php echo htmlspecialchars($ip_data['network_address'] . '/' . $ip_data['cidr_mask']); ?></td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Тип:</td>
                                                <td>
                                                    <span class="badge bg-<?php echo $ip_data['type'] === 'white' ? 'warning' : 'secondary'; ?>">
                                                        <?php echo $ip_data['type'] === 'white' ? 'Белый' : 'Серый'; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table class="table table-sm table-borderless">
                                            <tr>
                                                <td class="text-muted" style="width: 120px;">Статус:</td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        echo $ip_data['status'] === 'active' ? 'success' : 
                                                             ($ip_data['status'] === 'reserved' ? 'warning' : 'info'); 
                                                    ?>">
                                                        <?php echo $ip_data['status'] === 'active' ? 'Активен' : 
                                                               ($ip_data['status'] === 'reserved' ? 'Зарезервирован' : 'Свободен'); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php if ($ip_data['mac_address']): ?>
                                            <tr>
                                                <td class="text-muted">Устройство:</td>
                                                <td><code><?php echo htmlspecialchars($ip_data['mac_address']); ?></code></td>
                                            </tr>
                                            <?php endif; ?>
                                            <?php if ($ip_data['client_name']): ?>
                                            <tr>
                                                <td class="text-muted">Клиент:</td>
                                                <td><?php echo htmlspecialchars($ip_data['client_name']); ?></td>
                                            </tr>
                                            <?php endif; ?>
                                        </table>
                                    </div>
                                </div>
                                
                                <?php if ($ip_data['description']): ?>
                                <div class="mt-3">
                                    <small class="text-muted">Описание:</small>
                                    <p class="mb-0"><?php echo htmlspecialchars($ip_data['description']); ?></p>
                                </div>
                                <?php endif; ?>
                                
                                <div class="mt-3 pt-3 border-top">
                                    <small class="text-muted">
                                        <i class="bi bi-calendar me-1"></i>Создан: <?php echo date('d.m.Y H:i', strtotime($ip_data['created_at'])); ?>
                                        <?php if ($ip_data['updated_at'] && $ip_data['updated_at'] != $ip_data['created_at']): ?>
                                            <br><i class="bi bi-arrow-clockwise me-1"></i>Обновлен: <?php echo date('d.m.Y H:i', strtotime($ip_data['updated_at'])); ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            </div>
                        </div>

                        <!-- Форма подтверждения -->
                        <form method="POST" action="" class="mt-4">
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <a href="list.php" class="btn btn-outline-secondary me-md-2">
                                    <i class="bi bi-x-circle me-1"></i>Отмена
                                </a>
                                <button type="submit" class="btn btn-danger">
                                    <i class="bi bi-trash me-1"></i>Да, удалить IP-адрес
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Предупреждение системы -->
                <div class="card stat-card mt-4 border-warning">
                    <div class="card-header bg-warning bg-opacity-10 text-warning">
                        <h6 class="card-title mb-0">
                            <i class="bi bi-shield-exclamation me-2"></i>Системное предупреждение
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="text-warning">Последствия удаления:</h6>
                                <ul class="text-muted small">
                                    <li>Запись будет полностью удалена из базы данных</li>
                                    <li>Информация сохранится только в журнале аудита</li>
                                    <li>Восстановление данных невозможно</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-warning">Рекомендации:</h6>
                                <ul class="text-muted small">
                                    <li>Убедитесь в правильности выбора</li>
                                    <li>Рассмотрите изменение статуса вместо удаления</li>
                                    <li>Проверьте связанные устройства и клиентов</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>