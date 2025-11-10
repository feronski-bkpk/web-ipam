<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
require_once '../../includes/audit_system.php';
requireAuth();
requireRole('admin');

// Проверяем ID устройства
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: list.php');
    exit();
}

$device_id = intval($_GET['id']);

// Получаем данные устройства
try {
    $device_stmt = $conn->prepare("
        SELECT d.*, c.full_name as client_name, c.contract_number
        FROM devices d 
        LEFT JOIN clients c ON d.client_id = c.id 
        WHERE d.id = ?
    ");
    $device_stmt->bind_param("i", $device_id);
    $device_stmt->execute();
    $device_data = $device_stmt->get_result()->fetch_assoc();
    $device_stmt->close();
    
    if (!$device_data) {
        header('Location: list.php');
        exit();
    }
} catch (Exception $e) {
    error_log("Error fetching device data: " . $e->getMessage());
    header('Location: list.php');
    exit();
}

// Проверяем, есть ли связанные IP-адреса
$ip_stmt = $conn->prepare("SELECT COUNT(*) as ip_count FROM ip_addresses WHERE device_id = ?");
$ip_stmt->bind_param("i", $device_id);
$ip_stmt->execute();
$ip_count = $ip_stmt->get_result()->fetch_assoc()['ip_count'];
$ip_stmt->close();

// Обработка удаления
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($ip_count > 0) {
        $error = "Невозможно удалить устройство: имеются связанные IP-адреса ({$ip_count} шт.)";
    } else {
        try {
            // Логируем удаление
            AuditSystem::logDelete('devices', $device_id, 
                "Удалено устройство: {$device_data['mac_address']}",
                [
                    'mac_address' => $device_data['mac_address'],
                    'model' => $device_data['model'],
                    'serial_number' => $device_data['serial_number'],
                    'client_id' => $device_data['client_id'],
                    'client_name' => $device_data['client_name']
                ]
            );
            
            // Удаляем устройство
            $delete_stmt = $conn->prepare("DELETE FROM devices WHERE id = ?");
            $delete_stmt->bind_param("i", $device_id);
            
            if ($delete_stmt->execute()) {
                $_SESSION['success_message'] = "Устройство {$device_data['mac_address']} успешно удалено";
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
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Удалить устройство - Web-IPAM</title>
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
                        <li class="breadcrumb-item"><a href="list.php">Устройства</a></li>
                        <li class="breadcrumb-item active">Удалить устройство</li>
                    </ol>
                </nav>

                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="h3 mb-1">Удаление устройства</h1>
                        <p class="text-muted mb-0">Подтверждение удаления устройства из системы</p>
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
                <div class="card stat-card <?php echo $ip_count > 0 ? 'border-warning' : 'border-danger'; ?>">
                    <div class="card-header <?php echo $ip_count > 0 ? 'bg-warning text-dark' : 'bg-danger text-white'; ?>">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <?php echo $ip_count > 0 ? 'Невозможно удалить устройство' : 'Подтверждение удаления'; ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if ($ip_count > 0): ?>
                            <div class="alert alert-warning border-warning">
                                <div class="d-flex">
                                    <i class="bi bi-shield-exclamation text-warning me-3 fs-4"></i>
                                    <div>
                                        <h5 class="alert-heading">Обнаружены связанные IP-адреса</h5>
                                        <p class="mb-0">У устройства имеются привязанные IP-адреса. Сначала удалите или отвяжите все IP-адреса.</p>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning border-warning">
                                <div class="d-flex">
                                    <i class="bi bi-exclamation-triangle-fill text-warning me-3 fs-4"></i>
                                    <div>
                                        <h5 class="alert-heading">Внимание! Это действие необратимо</h5>
                                        <p class="mb-0">Вы собираетесь удалить устройство из системы. Все данные будут записаны в журнал аудита, но восстановление будет невозможно.</p>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Информация об устройстве -->
                        <div class="card border-0 bg-light">
                            <div class="card-body">
                                <h6 class="card-title text-primary mb-3">
                                    <i class="bi bi-hdd me-2"></i>Информация об устройстве
                                </h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <table class="table table-sm table-borderless">
                                            <tr>
                                                <td class="text-muted" style="width: 120px;">MAC-адрес:</td>
                                                <td><strong><code><?php echo htmlspecialchars($device_data['mac_address']); ?></code></strong></td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Модель:</td>
                                                <td>
                                                    <?php if ($device_data['model']): ?>
                                                        <?php echo htmlspecialchars($device_data['model']); ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Серийный номер:</td>
                                                <td>
                                                    <?php if ($device_data['serial_number']): ?>
                                                        <code><?php echo htmlspecialchars($device_data['serial_number']); ?></code>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table class="table table-sm table-borderless">
                                            <tr>
                                                <td class="text-muted" style="width: 120px;">IP-адресов:</td>
                                                <td>
                                                    <span class="badge bg-<?php echo $ip_count > 0 ? 'warning' : 'success'; ?>">
                                                        <?php echo $ip_count; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Клиент:</td>
                                                <td>
                                                    <?php if ($device_data['client_name']): ?>
                                                        <?php echo htmlspecialchars($device_data['client_name']); ?>
                                                        <br><small class="text-muted"><?php echo htmlspecialchars($device_data['contract_number']); ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Создано:</td>
                                                <td><?php echo date('d.m.Y', strtotime($device_data['created_at'])); ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Форма подтверждения или альтернативные действия -->
                        <?php if ($ip_count > 0): ?>
                            <div class="mt-4">
                                <h6 class="text-warning mb-3">
                                    <i class="bi bi-lightning me-2"></i>Альтернативные действия
                                </h6>
                                <div class="d-grid gap-2">
                                    <a href="../ip-addresses/list.php?search=<?php echo urlencode($device_data['mac_address']); ?>" 
                                       class="btn btn-outline-warning">
                                        <i class="bi bi-router me-1"></i>Просмотреть связанные IP-адреса
                                    </a>
                                    <a href="list.php" class="btn btn-outline-secondary">
                                        <i class="bi bi-arrow-left me-1"></i>Вернуться к списку устройств
                                    </a>
                                </div>
                            </div>
                        <?php else: ?>
                            <form method="POST" action="" class="mt-4">
                                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                    <a href="list.php" class="btn btn-outline-secondary me-md-2">
                                        <i class="bi bi-x-circle me-1"></i>Отмена
                                    </a>
                                    <button type="submit" class="btn btn-danger">
                                        <i class="bi bi-trash me-1"></i>Да, удалить устройство
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Системная информация -->
                <div class="card stat-card mt-4 border-info">
                    <div class="card-header bg-info bg-opacity-10 text-info">
                        <h6 class="card-title mb-0">
                            <i class="bi bi-info-circle me-2"></i>Системная информация
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="text-info">Последствия удаления:</h6>
                                <ul class="text-muted small">
                                    <li>Запись устройства будет полностью удалена</li>
                                    <li>Информация сохранится в журнале аудита</li>
                                    <li>Восстановление данных невозможно</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-info">Ограничения:</h6>
                                <ul class="text-muted small">
                                    <li>Нельзя удалить устройство с IP-адресами</li>
                                    <li>Требуются права администратора</li>
                                    <li>Операция логируется в системе</li>
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