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
                        <li class="breadcrumb-item active">Добавить устройство</li>
                    </ol>
                </nav>

                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="h3 mb-1">Добавить устройство</h1>
                        <p class="text-muted mb-0">Создание новой записи сетевого устройства</p>
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
                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <?php echo htmlspecialchars($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($errors['general'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <?php echo htmlspecialchars($errors['general']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Основная форма -->
                <div class="card stat-card">
                    <div class="card-header bg-transparent">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-hdd me-2"></i>Форма добавления устройства
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" id="device-form">
                            <!-- MAC-адрес -->
                            <div class="mb-3">
                                <label for="mac_address" class="form-label">MAC-адрес <span class="text-danger">*</span></label>
                                <input type="text" class="form-control <?php echo isset($errors['mac_address']) ? 'is-invalid' : ''; ?>" 
                                       id="mac_address" name="mac_address" 
                                       value="<?php echo htmlspecialchars($_POST['mac_address'] ?? ''); ?>" 
                                       required maxlength="17" 
                                       placeholder="AA:BB:CC:DD:EE:FF">
                                <?php if (isset($errors['mac_address'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['mac_address']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">Формат: XX:XX:XX:XX:XX:XX (буквы в верхнем регистре)</div>
                            </div>

                            <!-- Модель и серийный номер -->
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="model" class="form-label">Модель устройства</label>
                                        <input type="text" class="form-control <?php echo isset($errors['model']) ? 'is-invalid' : ''; ?>" 
                                               id="model" name="model" 
                                               value="<?php echo htmlspecialchars($_POST['model'] ?? ''); ?>" 
                                               maxlength="100" 
                                               placeholder="TP-Link Archer C7">
                                        <?php if (isset($errors['model'])): ?>
                                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['model']); ?></div>
                                        <?php endif; ?>
                                        <div class="form-text">Например: роутер, коммутатор, точка доступа</div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="serial_number" class="form-label">Серийный номер</label>
                                        <input type="text" class="form-control <?php echo isset($errors['serial_number']) ? 'is-invalid' : ''; ?>" 
                                               id="serial_number" name="serial_number" 
                                               value="<?php echo htmlspecialchars($_POST['serial_number'] ?? ''); ?>" 
                                               maxlength="50"
                                               placeholder="SN123456789">
                                        <?php if (isset($errors['serial_number'])): ?>
                                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['serial_number']); ?></div>
                                        <?php endif; ?>
                                        <div class="form-text">Необязательное поле</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Клиент -->
                            <div class="mb-4">
                                <label for="client_id" class="form-label">Клиент</label>
                                <select class="form-select <?php echo isset($errors['client_id']) ? 'is-invalid' : ''; ?>" 
                                        id="client_id" name="client_id">
                                    <option value="">Не привязано к клиенту</option>
                                    <?php foreach ($clients as $client): 
                                        $selected = ($_POST['client_id'] ?? '') == $client['id'] ? 'selected' : '';
                                        $display = $client['full_name'] . ' (' . $client['contract_number'] . ')';
                                    ?>
                                        <option value="<?php echo htmlspecialchars($client['id']); ?>" <?php echo $selected; ?>>
                                            <?php echo htmlspecialchars($display); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (isset($errors['client_id'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['client_id']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">Устройство можно привязать к существующему клиенту</div>
                            </div>

                            <div class="d-grid gap-2 d-md-flex">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-plus-circle me-1"></i>Добавить устройство
                                </button>
                                <a href="list.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-x-circle me-1"></i>Отмена
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Информация о устройствах -->
                <div class="card stat-card mt-4">
                    <div class="card-header bg-transparent">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-info-circle me-2"></i>Информация об устройствах
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="text-primary">Обязательные поля</h6>
                                <ul class="text-muted small">
                                    <li><strong>MAC-адрес</strong> - уникальный идентификатор устройства</li>
                                    <li>Должен быть в формате XX:XX:XX:XX:XX:XX</li>
                                    <li>Должен быть уникальным в системе</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-primary">Особенности</h6>
                                <ul class="text-muted small">
                                    <li>Одно устройство - один клиент</li>
                                    <li>Одному устройству - один IP-адрес</li>
                                    <li>Устройства без клиента - для служебных целей</li>
                                    <li>MAC-адрес используется для идентификации в сети</li>
                                </ul>
                            </div>
                        </div>
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