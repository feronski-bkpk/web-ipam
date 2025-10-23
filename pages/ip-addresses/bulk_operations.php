<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
require_once '../../includes/audit_system.php';
requireAuth();
requireAnyRole(['admin', 'engineer']);

$errors = [];
$success = '';
$selected_ips = [];

// Получаем список подсетей для фильтрации
try {
    $subnets_stmt = $conn->prepare("SELECT id, network_address, cidr_mask FROM subnets ORDER BY network_address");
    $subnets_stmt->execute();
    $subnets = $subnets_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $subnets_stmt->close();
} catch (Exception $e) {
    error_log("Error fetching subnets: " . $e->getMessage());
    $subnets = [];
}

// Обработка выбора IP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'select_ips') {
    $subnet_id = intval($_POST['subnet_id'] ?? 0);
    $status_filter = $_POST['status_filter'] ?? '';
    
    if ($subnet_id > 0) {
        $sql = "SELECT id, ip_address, status FROM ip_addresses WHERE subnet_id = ?";
        $params = [$subnet_id];
        $types = "i";
        
        if (!empty($status_filter)) {
            $sql .= " AND status = ?";
            $params[] = $status_filter;
            $types .= "s";
        }
        
        $sql .= " ORDER BY INET_ATON(ip_address)";
        
        try {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $selected_ips = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        } catch (Exception $e) {
            $errors['general'] = 'Ошибка получения IP-адресов: ' . $e->getMessage();
        }
    }
}

// Обработка массовых операций
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_operation') {
    $ip_ids = $_POST['ip_ids'] ?? [];
    $operation = $_POST['operation'] ?? '';
    $new_status = $_POST['new_status'] ?? '';
    
    if (empty($ip_ids)) {
        $errors['general'] = 'Не выбраны IP-адреса для операции';
    } elseif (empty($operation)) {
        $errors['general'] = 'Не выбрана операция';
    } else {
        $success_count = 0;
        $error_count = 0;
        $operation_details = [];
        
        foreach ($ip_ids as $ip_id) {
            try {
                // Получаем текущие данные IP
                $ip_stmt = $conn->prepare("SELECT ip_address, status FROM ip_addresses WHERE id = ?");
                $ip_stmt->bind_param("i", $ip_id);
                $ip_stmt->execute();
                $ip_data = $ip_stmt->get_result()->fetch_assoc();
                $ip_stmt->close();
                
                if ($ip_data) {
                    $old_status = $ip_data['status'];
                    
                    // Проверяем бизнес-логику для смены статуса
                    if ($operation === 'change_status') {
                        // Обновляем статус
                        $update_stmt = $conn->prepare("UPDATE ip_addresses SET status = ?, updated_at = NOW() WHERE id = ?");
                        $update_stmt->bind_param("si", $new_status, $ip_id);
                        
                        if ($update_stmt->execute()) {
                            // Логируем изменение
                            AuditSystem::logUpdate('ip_addresses', $ip_id, 
                                "Массовое изменение статуса: {$old_status} → {$new_status}",
                                ['status' => $old_status],
                                ['status' => $new_status]
                            );
                            $success_count++;
                            $operation_details[] = "IP {$ip_data['ip_address']}: {$old_status} → {$new_status}";
                        }
                        $update_stmt->close();
                    }
                }
            } catch (Exception $e) {
                $error_count++;
                error_log("Bulk operation error for IP {$ip_id}: " . $e->getMessage());
            }
        }
        
        if ($success_count > 0) {
            $success = "Успешно обработано: {$success_count} IP-адресов";
            if ($error_count > 0) {
                $success .= " (ошибок: {$error_count})";
            }
        } else {
            $errors['general'] = "Не удалось обработать ни одного IP-адреса";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Массовые операции - Web-IPAM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    
    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="../../index.php">Главная</a></li>
                        <li class="breadcrumb-item"><a href="list.php">IP-адреса</a></li>
                        <li class="breadcrumb-item active">Массовые операции</li>
                    </ol>
                </nav>

                <h1>Массовые операции с IP-адресами</h1>
                
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>

                <?php if (isset($errors['general'])): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($errors['general']); ?></div>
                <?php endif; ?>

                <!-- Форма выбора IP -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Выбор IP-адресов</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" class="row g-3">
                            <input type="hidden" name="action" value="select_ips">
                            
                            <div class="col-md-4">
                                <label for="subnet_id" class="form-label">Подсеть</label>
                                <select class="form-select" id="subnet_id" name="subnet_id" required>
                                    <option value="">Выберите подсеть</option>
                                    <?php foreach ($subnets as $subnet): ?>
                                        <option value="<?php echo htmlspecialchars($subnet['id']); ?>" 
                                            <?php echo ($_POST['subnet_id'] ?? '') == $subnet['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($subnet['network_address'] . '/' . $subnet['cidr_mask']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-4">
                                <label for="status_filter" class="form-label">Фильтр по статусу</label>
                                <select class="form-select" id="status_filter" name="status_filter">
                                    <option value="">Все статусы</option>
                                    <option value="free" <?php echo ($_POST['status_filter'] ?? '') === 'free' ? 'selected' : ''; ?>>Свободные</option>
                                    <option value="active" <?php echo ($_POST['status_filter'] ?? '') === 'active' ? 'selected' : ''; ?>>Активные</option>
                                    <option value="reserved" <?php echo ($_POST['status_filter'] ?? '') === 'reserved' ? 'selected' : ''; ?>>Зарезервированные</option>
                                </select>
                            </div>
                            
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">🔍 Выбрать IP-адреса</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Форма массовых операций -->
                <?php if (!empty($selected_ips)): ?>
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Массовые операции</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" id="bulk-form">
                            <input type="hidden" name="action" value="bulk_operation">
                            
                            <div class="row mb-4">
                                <div class="col-md-4">
                                    <label for="operation" class="form-label">Операция</label>
                                    <select class="form-select" id="operation" name="operation" required>
                                        <option value="">Выберите операцию</option>
                                        <option value="change_status">🔄 Изменить статус</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-4">
                                    <label for="new_status" class="form-label">Новый статус</label>
                                    <select class="form-select" id="new_status" name="new_status">
                                        <option value="free">🟢 Свободен</option>
                                        <option value="active">🔵 Активен</option>
                                        <option value="reserved">🟡 Зарезервирован</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-4 d-flex align-items-end">
                                    <button type="submit" class="btn btn-warning w-100">⚡ Выполнить операцию</button>
                                </div>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th style="width: 40px;">
                                                <input type="checkbox" id="select-all">
                                            </th>
                                            <th>IP-адрес</th>
                                            <th>Текущий статус</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($selected_ips as $ip): ?>
                                            <tr>
                                                <td>
                                                    <input type="checkbox" name="ip_ids[]" value="<?php echo htmlspecialchars($ip['id']); ?>" class="ip-checkbox">
                                                </td>
                                                <td>
                                                    <code><?php echo htmlspecialchars($ip['ip_address']); ?></code>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        echo $ip['status'] === 'active' ? 'success' : 
                                                             ($ip['status'] === 'reserved' ? 'warning' : 'info'); 
                                                    ?>">
                                                        <?php echo $ip['status'] === 'active' ? 'Активен' : 
                                                               ($ip['status'] === 'reserved' ? 'Зарезервирован' : 'Свободен'); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div class="mt-3">
                                <small class="text-muted">Выбрано: <span id="selected-count">0</span> IP-адресов</small>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Выделение всех IP
        document.getElementById('select-all').addEventListener('change', function(e) {
            const checkboxes = document.querySelectorAll('.ip-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = e.target.checked;
            });
            updateSelectedCount();
        });

        // Обновление счетчика выбранных IP
        function updateSelectedCount() {
            const selected = document.querySelectorAll('.ip-checkbox:checked').length;
            document.getElementById('selected-count').textContent = selected;
        }

        // Слушатель для всех чекбоксов
        document.querySelectorAll('.ip-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', updateSelectedCount);
        });

        // Валидация формы
        document.getElementById('bulk-form').addEventListener('submit', function(e) {
            const selectedCount = document.querySelectorAll('.ip-checkbox:checked').length;
            const operation = document.getElementById('operation').value;
            
            if (selectedCount === 0) {
                e.preventDefault();
                alert('Выберите хотя бы один IP-адрес для операции');
                return;
            }
            
            if (!operation) {
                e.preventDefault();
                alert('Выберите операцию');
                return;
            }
            
            if (!confirm(`Вы уверены, что хотите выполнить операцию над ${selectedCount} IP-адресами?`)) {
                e.preventDefault();
            }
        });

        // Инициализация счетчика
        document.addEventListener('DOMContentLoaded', function() {
            updateSelectedCount();
        });
    </script>
</body>
</html>