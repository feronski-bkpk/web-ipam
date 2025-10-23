<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
require_once '../../includes/audit_system.php';
requireAuth();
requireAnyRole(['admin', 'engineer']);

$errors = [];
$success = '';

// Проверяем наличие ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: list.php');
    exit();
}

$ip_id = intval($_GET['id']);

// Получаем данные IP-адреса для редактирования
try {
    $ip_stmt = $conn->prepare("
        SELECT ip.*, s.network_address, s.cidr_mask 
        FROM ip_addresses ip 
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

// Получаем список подсетей для выпадающего списка
try {
    $subnets_stmt = $conn->prepare("SELECT id, network_address, cidr_mask, description FROM subnets ORDER BY network_address");
    $subnets_stmt->execute();
    $subnets = $subnets_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $subnets_stmt->close();
} catch (Exception $e) {
    error_log("Error fetching subnets: " . $e->getMessage());
    $subnets = [];
}

// Получаем список устройств для выпадающего списка
try {
    $devices_stmt = $conn->prepare("
        SELECT d.id, d.mac_address, d.model, c.full_name as client_name 
        FROM devices d 
        LEFT JOIN clients c ON d.client_id = c.id 
        WHERE d.id NOT IN (SELECT device_id FROM ip_addresses WHERE device_id IS NOT NULL AND id != ?)
        ORDER BY d.mac_address
    ");
    $devices_stmt->bind_param("i", $ip_id);
    $devices_stmt->execute();
    $devices = $devices_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $devices_stmt->close();
} catch (Exception $e) {
    error_log("Error fetching devices: " . $e->getMessage());
    $devices = [];
}

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Получаем и валидируем данные
    $ip_address = trim($_POST['ip_address'] ?? '');
    $subnet_id = intval($_POST['subnet_id'] ?? 0);
    $device_id = !empty($_POST['device_id']) ? intval($_POST['device_id']) : null;
    $type = $_POST['type'] ?? 'gray';
    $status = $_POST['status'] ?? 'free';
    $description = trim($_POST['description'] ?? '');
    
    // Валидация IP-адреса
    if (empty($ip_address)) {
        $errors['ip_address'] = 'IP-адрес обязателен для заполнения';
    } elseif (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
        $errors['ip_address'] = 'Неверный формат IP-адреса';
    }
    
    // Валидация подсети
    if ($subnet_id <= 0) {
        $errors['subnet_id'] = 'Выберите подсеть';
    }
    
    // Валидация типа
    if (!in_array($type, ['white', 'gray'])) {
        $errors['type'] = 'Неверный тип IP-адреса';
    }
    
    // Валидация статуса
    if (!in_array($status, ['active', 'free', 'reserved'])) {
        $errors['status'] = 'Неверный статус';
    }
    
    // Валидация согласованности данных
    if ($device_id && $status === 'free') {
        $errors['status'] = 'IP-адрес с привязанным устройством не может быть свободным';
    }

    if (!$device_id && $status === 'active') {
        $errors['status'] = 'Активный IP-адрес должен быть привязан к устройству';
    }

    if ($device_id && $status === 'reserved') {
        $errors['status'] = 'Зарезервированный IP-адрес не должен быть привязан к устройству';
    }
    
    // Если нет ошибок - проверяем бизнес-логику
    if (empty($errors)) {
        try {
            // Проверяем, что подсеть существует
            $check_subnet_stmt = $conn->prepare("SELECT network_address, cidr_mask FROM subnets WHERE id = ?");
            $check_subnet_stmt->bind_param("i", $subnet_id);
            $check_subnet_stmt->execute();
            $subnet_result = $check_subnet_stmt->get_result();

            if ($subnet_result->num_rows === 0) {
                $errors['subnet_id'] = 'Выбранная подсеть не существует';
            } else {
                $subnet_data = $subnet_result->fetch_assoc();
                
                // Проверяем принадлежность IP к подсети
                if (!isIpInSubnet($ip_address, $subnet_data['network_address'], $subnet_data['cidr_mask'])) {
                    $errors['ip_address'] = 'IP-адрес не принадлежит выбранной подсети. ';
                    
                    // Показываем диапазон подсети для помощи пользователю
                    $range = getSubnetRange($subnet_data['network_address'], $subnet_data['cidr_mask']);
                    $errors['ip_address'] .= "Допустимый диапазон: {$range['start']} - {$range['end']}";
                }
                
                // Проверяем уникальность IP в подсети (исключая текущую запись)
                else {
                    $check_ip_stmt = $conn->prepare("SELECT id FROM ip_addresses WHERE ip_address = ? AND subnet_id = ? AND id != ?");
                    $check_ip_stmt->bind_param("sii", $ip_address, $subnet_id, $ip_id);
                    $check_ip_stmt->execute();
                    
                    if ($check_ip_stmt->get_result()->num_rows > 0) {
                        $errors['ip_address'] = 'Этот IP-адрес уже существует в выбранной подсети';
                    }
                    $check_ip_stmt->close();
                }
            }
            $check_subnet_stmt->close();
            
            // Проверяем устройство, если указано
            if ($device_id) {
                $check_device_stmt = $conn->prepare("SELECT id, mac_address FROM devices WHERE id = ?");
                $check_device_stmt->bind_param("i", $device_id);
                $check_device_stmt->execute();
                $device_result = $check_device_stmt->get_result();
                
                if ($device_result->num_rows === 0) {
                    $errors['device_id'] = 'Выбранное устройство не существует';
                }
                $check_device_stmt->close();
            }
            
        } catch (Exception $e) {
            $errors['general'] = 'Ошибка проверки данных: ' . $e->getMessage();
        }
    }
    
    // Если все проверки пройдены - сохраняем
    if (empty($errors)) {
        try {
            // Сохраняем старые значения для аудита
            $old_values = [
                'ip_address' => $ip_data['ip_address'],
                'subnet_id' => $ip_data['subnet_id'],
                'device_id' => $ip_data['device_id'],
                'type' => $ip_data['type'],
                'status' => $ip_data['status'],
                'description' => $ip_data['description']
            ];
            
            $update_stmt = $conn->prepare("
                UPDATE ip_addresses 
                SET ip_address = ?, subnet_id = ?, device_id = ?, type = ?, status = ?, description = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $update_stmt->bind_param(
                "siisssi", 
                $ip_address, 
                $subnet_id, 
                $device_id, 
                $type, 
                $status, 
                $description, 
                $ip_id
            );
            
            if ($update_stmt->execute()) {
                // ЛОГИРУЕМ ИЗМЕНЕНИЕ В СИСТЕМЕ АУДИТА
                $changes = [];
                if ($ip_data['ip_address'] != $ip_address) $changes['ip_address'] = $ip_address;
                if ($ip_data['subnet_id'] != $subnet_id) $changes['subnet_id'] = $subnet_id;
                if ($ip_data['device_id'] != $device_id) $changes['device_id'] = $device_id;
                if ($ip_data['type'] != $type) $changes['type'] = $type;
                if ($ip_data['status'] != $status) $changes['status'] = $status;
                if ($ip_data['description'] != $description) $changes['description'] = $description;
                
                if (!empty($changes)) {
                    AuditSystem::logUpdate('ip_addresses', $ip_id, 
                        "Изменен IP-адрес: {$ip_address}", 
                        $old_values,
                        [
                            'ip_address' => $ip_address,
                            'subnet_id' => $subnet_id,
                            'device_id' => $device_id,
                            'type' => $type,
                            'status' => $status,
                            'description' => $description
                        ]
                    );
                }
                
                $success = 'IP-адрес успешно обновлен';
                // Обновляем данные для отображения
                $ip_data = array_merge($ip_data, [
                    'ip_address' => $ip_address,
                    'subnet_id' => $subnet_id,
                    'device_id' => $device_id,
                    'type' => $type,
                    'status' => $status,
                    'description' => $description
                ]);
            } else {
                $errors['general'] = 'Ошибка при обновлении: ' . $update_stmt->error;
            }
            
            $update_stmt->close();
            
        } catch (Exception $e) {
            $errors['general'] = 'Ошибка базы данных: ' . $e->getMessage();
        }
    }
}

// Функция проверки принадлежности IP к подсети
function isIpInSubnet($ip, $network, $cidr) {
    $ip_long = ip2long($ip);
    $network_long = ip2long($network);
    
    if ($ip_long === false || $network_long === false) {
        return false;
    }
    
    $mask = -1 << (32 - $cidr);
    $network_masked = $network_long & $mask;
    $ip_masked = $ip_long & $mask;
    
    return $network_masked == $ip_masked;
}

// Функция для получения диапазона IP в подсети
function getSubnetRange($network, $cidr) {
    $network_long = ip2long($network);
    $mask = -1 << (32 - $cidr);
    
    $network_start = $network_long & $mask;
    $network_end = $network_start + pow(2, (32 - $cidr)) - 1;
    
    return [
        'start' => long2ip($network_start),
        'end' => long2ip($network_end)
    ];
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактировать IP-адрес - Web-IPAM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../../assets/css/style.css" rel="stylesheet">
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
                        <li class="breadcrumb-item active">Редактировать IP-адрес</li>
                    </ol>
                </nav>

                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1>Редактировать IP-адрес</h1>
                    <a href="list.php" class="btn btn-outline-secondary">← Назад к списку</a>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>

                <?php if (isset($errors['general'])): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($errors['general']); ?></div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-body">
                        <form method="POST" action="" id="ip-form">
                            <div class="row">
                                <div class="col-md-6">
                                    <!-- Поле IP-адрес -->
                                    <div class="mb-3">
                                        <label for="ip_address" class="form-label">IP-адрес *</label>
                                        <input type="text" class="form-control <?php echo isset($errors['ip_address']) ? 'is-invalid' : ''; ?>" 
                                               id="ip_address" name="ip_address" 
                                               value="<?php echo htmlspecialchars($_POST['ip_address'] ?? $ip_data['ip_address']); ?>" 
                                               required>
                                        <?php if (isset($errors['ip_address'])): ?>
                                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['ip_address']); ?></div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Выбор подсети -->
                                    <div class="mb-3">
                                        <label for="subnet_id" class="form-label">Подсеть *</label>
                                        <select class="form-select <?php echo isset($errors['subnet_id']) ? 'is-invalid' : ''; ?>" 
                                                id="subnet_id" name="subnet_id" required>
                                            <option value="">Выберите подсеть</option>
                                            <?php foreach ($subnets as $subnet): 
                                                $selected = ($_POST['subnet_id'] ?? $ip_data['subnet_id']) == $subnet['id'] ? 'selected' : '';
                                                $display = $subnet['network_address'] . '/' . $subnet['cidr_mask'];
                                                if ($subnet['description']) {
                                                    $display .= ' - ' . $subnet['description'];
                                                }
                                            ?>
                                                <option value="<?php echo htmlspecialchars($subnet['id']); ?>" <?php echo $selected; ?>>
                                                    <?php echo htmlspecialchars($display); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if (isset($errors['subnet_id'])): ?>
                                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['subnet_id']); ?></div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Выбор устройства -->
                                    <div class="mb-3">
                                        <label for="device_id" class="form-label">Устройство</label>
                                        <select class="form-select <?php echo isset($errors['device_id']) ? 'is-invalid' : ''; ?>" 
                                                id="device_id" name="device_id">
                                            <option value="">Не привязано к устройству</option>
                                            <?php foreach ($devices as $device): 
                                                $selected = ($_POST['device_id'] ?? $ip_data['device_id']) == $device['id'] ? 'selected' : '';
                                                $display = $device['mac_address'];
                                                if ($device['model']) {
                                                    $display .= ' - ' . $device['model'];
                                                }
                                                if ($device['client_name']) {
                                                    $display .= ' (' . $device['client_name'] . ')';
                                                }
                                            ?>
                                                <option value="<?php echo htmlspecialchars($device['id']); ?>" <?php echo $selected; ?>>
                                                    <?php echo htmlspecialchars($display); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if (isset($errors['device_id'])): ?>
                                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['device_id']); ?></div>
                                        <?php endif; ?>
                                        <div class="form-text">Показываются только устройства без IP-адресов</div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <!-- Тип IP-адреса -->
                                    <div class="mb-3">
                                        <label class="form-label">Тип IP-адреса *</label>
                                        <div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="type" id="type_gray" 
                                                       value="gray" <?php echo ($_POST['type'] ?? $ip_data['type']) === 'gray' ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="type_gray">Серый</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="type" id="type_white" 
                                                       value="white" <?php echo ($_POST['type'] ?? $ip_data['type']) === 'white' ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="type_white">Белый</label>
                                            </div>
                                        </div>
                                        <?php if (isset($errors['type'])): ?>
                                            <div class="text-danger"><?php echo htmlspecialchars($errors['type']); ?></div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Статус -->
                                    <div class="mb-3">
                                        <label for="status" class="form-label">Статус *</label>
                                        <select class="form-select <?php echo isset($errors['status']) ? 'is-invalid' : ''; ?>" 
                                                id="status" name="status" required>
                                            <option value="free" <?php echo ($_POST['status'] ?? $ip_data['status']) === 'free' ? 'selected' : ''; ?>>Свободен</option>
                                            <option value="active" <?php echo ($_POST['status'] ?? $ip_data['status']) === 'active' ? 'selected' : ''; ?>>Активен</option>
                                            <option value="reserved" <?php echo ($_POST['status'] ?? $ip_data['status']) === 'reserved' ? 'selected' : ''; ?>>Зарезервирован</option>
                                        </select>
                                        <?php if (isset($errors['status'])): ?>
                                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['status']); ?></div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Описание -->
                                    <div class="mb-3">
                                        <label for="description" class="form-label">Описание</label>
                                        <textarea class="form-control" id="description" name="description" 
                                                  rows="3" placeholder="Дополнительная информация о IP-адресе"><?php echo htmlspecialchars($_POST['description'] ?? $ip_data['description'] ?? ''); ?></textarea>
                                    </div>

                                    <!-- Информация о записи -->
                                    <div class="card bg-light">
                                        <div class="card-body">
                                            <small class="text-muted">
                                                <strong>Информация о записи:</strong><br>
                                                Создано: <?php echo date('d.m.Y H:i', strtotime($ip_data['created_at'])); ?><br>
                                                <?php if ($ip_data['updated_at'] && $ip_data['updated_at'] != $ip_data['created_at']): ?>
                                                    Обновлено: <?php echo date('d.m.Y H:i', strtotime($ip_data['updated_at'])); ?><br>
                                                <?php endif; ?>
                                                ID записи: <?php echo htmlspecialchars($ip_data['id']); ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row mt-4">
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">💾 Сохранить изменения</button>
                                    <a href="list.php" class="btn btn-secondary">❌ Отмена</a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Динамическая валидация согласованности данных
        function validateFormConsistency() {
            const deviceId = document.getElementById('device_id').value;
            const status = document.getElementById('status').value;
            const statusError = document.getElementById('status-error');
            
            if (statusError) {
                statusError.remove();
            }
            
            let isValid = true;
            let errorMessage = '';
            
            if (deviceId && status === 'free') {
                errorMessage = 'IP-адрес с привязанным устройством не может быть свободным';
                isValid = false;
            }
            
            if (!deviceId && status === 'active') {
                errorMessage = 'Активный IP-адрес должен быть привязан к устройству';
                isValid = false;
            }
            
            if (deviceId && status === 'reserved') {
                errorMessage = 'Зарезервированный IP-адрес не должен быть привязан к устройству';
                isValid = false;
            }
            
            if (!isValid) {
                const errorDiv = document.createElement('div');
                errorDiv.id = 'status-error';
                errorDiv.className = 'text-danger mt-1';
                errorDiv.textContent = errorMessage;
                document.getElementById('status').parentNode.appendChild(errorDiv);
            }
            
            return isValid;
        }

        function updateAvailableStatuses() {
            const deviceId = document.getElementById('device_id').value;
            const statusSelect = document.getElementById('status');
            const currentStatus = statusSelect.value;
            
            Array.from(statusSelect.options).forEach(option => {
                option.disabled = false;
            });
            
            if (deviceId) {
                statusSelect.querySelector('option[value="free"]').disabled = true;
                statusSelect.querySelector('option[value="reserved"]').disabled = true;
                
                if (currentStatus === 'free' || currentStatus === 'reserved') {
                    statusSelect.value = 'active';
                }
            } else {
                statusSelect.querySelector('option[value="active"]').disabled = true;
                
                if (currentStatus === 'active') {
                    statusSelect.value = 'free';
                }
            }
            
            validateFormConsistency();
        }

        document.getElementById('device_id').addEventListener('change', updateAvailableStatuses);
        document.getElementById('status').addEventListener('change', validateFormConsistency);

        document.getElementById('ip-form').addEventListener('submit', function(e) {
            if (!validateFormConsistency()) {
                e.preventDefault();
                alert('Исправьте несоответствия в форме перед отправкой');
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            updateAvailableStatuses();
        });
    </script>
</body>
</html>