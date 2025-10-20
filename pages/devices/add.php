<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
require_once '../../includes/audit_system.php';
requireAuth();
requireAnyRole(['admin', 'engineer']);

$errors = [];
$success = '';

// Получаем список клиентов для выпадающего списка
try {
    $clients_stmt = $conn->prepare("SELECT id, full_name, contract_number FROM clients ORDER BY full_name");
    $clients_stmt->execute();
    $clients = $clients_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $clients_stmt->close();
} catch (Exception $e) {
    error_log("Error fetching clients: " . $e->getMessage());
    $clients = [];
}

/**
 * Валидация MAC-адреса
 */
function isValidMacAddress($mac) {
    // Формат: XX:XX:XX:XX:XX:XX
    return preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $mac);
}

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mac_address = trim($_POST['mac_address'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $serial_number = trim($_POST['serial_number'] ?? '');
    $client_id = !empty($_POST['client_id']) ? intval($_POST['client_id']) : null;
    
    // Валидация MAC-адреса
    if (empty($mac_address)) {
        $errors['mac_address'] = 'MAC-адрес обязателен';
    } elseif (!isValidMacAddress($mac_address)) {
        $errors['mac_address'] = 'Неверный формат MAC-адреса. Используйте формат: XX:XX:XX:XX:XX:XX';
    } elseif (strlen($mac_address) > 17) {
        $errors['mac_address'] = 'MAC-адрес слишком длинный';
    }
    
    if ($model && strlen($model) > 100) {
        $errors['model'] = 'Модель слишком длинная';
    }
    
    if ($serial_number && strlen($serial_number) > 50) {
        $errors['serial_number'] = 'Серийный номер слишком длинный';
    }
    
    // Проверка уникальности MAC-адреса
    if (empty($errors)) {
        try {
            $check_stmt = $conn->prepare("SELECT id FROM devices WHERE mac_address = ?");
            $check_stmt->bind_param("s", $mac_address);
            $check_stmt->execute();
            
            if ($check_stmt->get_result()->num_rows > 0) {
                $errors['mac_address'] = 'Устройство с таким MAC-адресом уже существует';
            }
            $check_stmt->close();
        } catch (Exception $e) {
            $errors['general'] = 'Ошибка проверки данных: ' . $e->getMessage();
        }
    }
    
    // Проверка клиента
    if ($client_id) {
        try {
            $check_client_stmt = $conn->prepare("SELECT id FROM clients WHERE id = ?");
            $check_client_stmt->bind_param("i", $client_id);
            $check_client_stmt->execute();
            
            if ($check_client_stmt->get_result()->num_rows === 0) {
                $errors['client_id'] = 'Выбранный клиент не существует';
            }
            $check_client_stmt->close();
        } catch (Exception $e) {
            $errors['general'] = 'Ошибка проверки клиента: ' . $e->getMessage();
        }
    }
    
    // Сохранение
    if (empty($errors)) {
        try {
            $insert_stmt = $conn->prepare("
                INSERT INTO devices (mac_address, model, serial_number, client_id) 
                VALUES (?, ?, ?, ?)
            ");
            $insert_stmt->bind_param("sssi", $mac_address, $model, $serial_number, $client_id);
            
            if ($insert_stmt->execute()) {
                $device_id = $insert_stmt->insert_id;
                
                // Логируем создание
                $client_name = 'без клиента';
                if ($client_id) {
                    foreach ($clients as $client) {
                        if ($client['id'] == $client_id) {
                            $client_name = $client['full_name'];
                            break;
                        }
                    }
                }
                
                AuditSystem::logCreate('devices', $device_id, 
                    "Добавлено устройство: {$mac_address} (клиент: {$client_name})",
                    [
                        'mac_address' => $mac_address,
                        'model' => $model,
                        'serial_number' => $serial_number,
                        'client_id' => $client_id
                    ]
                );
                
                $success = 'Устройство успешно добавлено';
                $_POST = []; // Очищаем форму
            } else {
                $errors['general'] = 'Ошибка при сохранении: ' . $insert_stmt->error;
            }
            
            $insert_stmt->close();
        } catch (Exception $e) {
            $errors['general'] = 'Ошибка базы данных: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Добавить устройство - Web-IPAM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    
    <div class="container mt-4">
        <div class="row">
            <div class="col-md-8 mx-auto">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="../../index.php">Главная</a></li>
                        <li class="breadcrumb-item"><a href="list.php">Устройства</a></li>
                        <li class="breadcrumb-item active">Добавить устройство</li>
                    </ol>
                </nav>

                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">Добавить новое устройство</h4>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                        <?php endif; ?>

                        <?php if (isset($errors['general'])): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($errors['general']); ?></div>
                        <?php endif; ?>

                        <form method="POST" action="" id="device-form">
                            <div class="mb-3">
                                <label for="mac_address" class="form-label">MAC-адрес *</label>
                                <input type="text" class="form-control <?php echo isset($errors['mac_address']) ? 'is-invalid' : ''; ?>" 
                                       id="mac_address" name="mac_address" 
                                       value="<?php echo htmlspecialchars($_POST['mac_address'] ?? ''); ?>" 
                                       required maxlength="17" placeholder="AA:BB:CC:DD:EE:FF">
                                <?php if (isset($errors['mac_address'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['mac_address']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">Формат: XX:XX:XX:XX:XX:XX</div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="model" class="form-label">Модель устройства</label>
                                        <input type="text" class="form-control <?php echo isset($errors['model']) ? 'is-invalid' : ''; ?>" 
                                               id="model" name="model" 
                                               value="<?php echo htmlspecialchars($_POST['model'] ?? ''); ?>" 
                                               maxlength="100" placeholder="TP-Link Archer C7">
                                        <?php if (isset($errors['model'])): ?>
                                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['model']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="serial_number" class="form-label">Серийный номер</label>
                                        <input type="text" class="form-control <?php echo isset($errors['serial_number']) ? 'is-invalid' : ''; ?>" 
                                               id="serial_number" name="serial_number" 
                                               value="<?php echo htmlspecialchars($_POST['serial_number'] ?? ''); ?>" 
                                               maxlength="50">
                                        <?php if (isset($errors['serial_number'])): ?>
                                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['serial_number']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="client_id" class="form-label">Клиент</label>
                                <select class="form-select <?php echo isset($errors['client_id']) ? 'is-invalid' : ''; ?>" 
                                        id="client_id" name="client_id">
                                    <option value="">Не привязано к клиенту</option>
                                    <?php foreach ($clients as $client): 
                                        $selected = ($_POST['client_id'] ?? '') == $client['id'] ? 'selected' : '';
                                        $display = $client['full_name'] . ' (' . $client['contract_number'] . ')';
                                    ?>
                                        <option value="<?php echo $client['id']; ?>" <?php echo $selected; ?>>
                                            <?php echo htmlspecialchars($display); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (isset($errors['client_id'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['client_id']); ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="d-grid gap-2 d-md-flex">
                                <button type="submit" class="btn btn-primary">Добавить устройство</button>
                                <a href="list.php" class="btn btn-secondary">Отмена</a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Правила системы -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Правила системы</h5>
                    </div>
                    <div class="card-body">
                        <ul class="text-muted">
                            <li>MAC-адрес должен быть уникальным в системе</li>
                            <li>Одно устройство может быть привязано только к одному клиенту</li>
                            <li>Одному устройству может быть назначен только один IP-адрес</li>
                            <li>Устройство без клиента может использоваться для служебных целей</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Автоматическое форматирование MAC-адреса
        document.getElementById('mac_address').addEventListener('input', function(e) {
            let value = e.target.value.replace(/[^a-fA-F0-9]/g, '').toUpperCase();
            if (value.length > 12) value = value.substr(0, 12);
            
            // Форматируем как XX:XX:XX:XX:XX:XX
            let formatted = '';
            for (let i = 0; i < value.length; i++) {
                if (i > 0 && i % 2 === 0) {
                    formatted += ':';
                }
                formatted += value[i];
            }
            
            e.target.value = formatted;
        });

        // Валидация MAC-адреса при отправке
        document.getElementById('device-form').addEventListener('submit', function(e) {
            const macInput = document.getElementById('mac_address');
            const macValue = macInput.value.trim();
            const macRegex = /^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/;
            
            if (!macRegex.test(macValue)) {
                e.preventDefault();
                alert('Пожалуйста, введите корректный MAC-адрес в формате XX:XX:XX:XX:XX:XX');
                macInput.focus();
            }
        });
    </script>
</body>
</html>