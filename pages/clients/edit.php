<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
require_once '../../includes/audit_system.php';
requireAuth();
requireAnyRole(['admin', 'engineer']);

$errors = [];
$success = '';

// Проверяем ID клиента
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: list.php');
    exit();
}

$client_id = intval($_GET['id']);

// Получаем данные клиента
try {
    $client_stmt = $conn->prepare("SELECT * FROM clients WHERE id = ?");
    $client_stmt->bind_param("i", $client_id);
    $client_stmt->execute();
    $client_data = $client_stmt->get_result()->fetch_assoc();
    $client_stmt->close();
    
    if (!$client_data) {
        header('Location: list.php');
        exit();
    }
} catch (Exception $e) {
    error_log("Error fetching client data: " . $e->getMessage());
    header('Location: list.php');
    exit();
}

// Получаем количество устройств клиента
$devices_stmt = $conn->prepare("SELECT COUNT(*) as device_count FROM devices WHERE client_id = ?");
$devices_stmt->bind_param("i", $client_id);
$devices_stmt->execute();
$device_count = $devices_stmt->get_result()->fetch_assoc()['device_count'];
$devices_stmt->close();

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contract_number = trim($_POST['contract_number'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    
    // Валидация
    if (empty($contract_number)) {
        $errors['contract_number'] = 'Номер договора обязателен';
    } elseif (strlen($contract_number) > 20) {
        $errors['contract_number'] = 'Номер договора слишком длинный';
    }
    
    if (empty($full_name)) {
        $errors['full_name'] = 'ФИО обязательно';
    } elseif (strlen($full_name) > 100) {
        $errors['full_name'] = 'ФИО слишком длинное';
    }
    
    if (empty($address)) {
        $errors['address'] = 'Адрес обязателен';
    }
    
    if ($phone && strlen($phone) > 20) {
        $errors['phone'] = 'Телефон слишком длинный';
    }
    
    // Проверка уникальности номера договора (исключая текущего клиента)
    if (empty($errors)) {
        try {
            $check_stmt = $conn->prepare("SELECT id FROM clients WHERE contract_number = ? AND id != ?");
            $check_stmt->bind_param("si", $contract_number, $client_id);
            $check_stmt->execute();
            
            if ($check_stmt->get_result()->num_rows > 0) {
                $errors['contract_number'] = 'Клиент с таким номером договора уже существует';
            }
            $check_stmt->close();
        } catch (Exception $e) {
            $errors['general'] = 'Ошибка проверки данных: ' . $e->getMessage();
        }
    }
    
    // Сохранение
    if (empty($errors)) {
        try {
            // Сохраняем старые значения для аудита
            $old_values = [
                'contract_number' => $client_data['contract_number'],
                'full_name' => $client_data['full_name'],
                'address' => $client_data['address'],
                'phone' => $client_data['phone']
            ];
            
            $update_stmt = $conn->prepare("
                UPDATE clients 
                SET contract_number = ?, full_name = ?, address = ?, phone = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $update_stmt->bind_param("ssssi", $contract_number, $full_name, $address, $phone, $client_id);
            
            if ($update_stmt->execute()) {
                // Логируем изменение
                $changes = [];
                if ($client_data['contract_number'] != $contract_number) $changes['contract_number'] = $contract_number;
                if ($client_data['full_name'] != $full_name) $changes['full_name'] = $full_name;
                if ($client_data['address'] != $address) $changes['address'] = $address;
                if ($client_data['phone'] != $phone) $changes['phone'] = $phone;
                
                if (!empty($changes)) {
                    AuditSystem::logUpdate('clients', $client_id, 
                        "Изменен клиент: {$full_name}",
                        $old_values,
                        [
                            'contract_number' => $contract_number,
                            'full_name' => $full_name,
                            'address' => $address,
                            'phone' => $phone
                        ]
                    );
                }
                
                $success = 'Данные клиента успешно обновлены';
                // Обновляем данные для отображения
                $client_data = array_merge($client_data, [
                    'contract_number' => $contract_number,
                    'full_name' => $full_name,
                    'address' => $address,
                    'phone' => $phone
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
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактировать клиента - Web-IPAM</title>
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
                        <li class="breadcrumb-item"><a href="list.php">Клиенты</a></li>
                        <li class="breadcrumb-item active">Редактировать клиента</li>
                    </ol>
                </nav>

                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="h3 mb-1">Редактировать клиента</h1>
                        <p class="text-muted mb-0">Изменение данных клиентской записи</p>
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
                            <i class="bi bi-pencil me-2"></i>Форма редактирования клиента
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="contract_number" class="form-label">Номер договора <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control <?php echo isset($errors['contract_number']) ? 'is-invalid' : ''; ?>" 
                                               id="contract_number" name="contract_number" 
                                               value="<?php echo htmlspecialchars($_POST['contract_number'] ?? $client_data['contract_number']); ?>" 
                                               required maxlength="20"
                                               placeholder="ДГ-2024-001">
                                        <?php if (isset($errors['contract_number'])): ?>
                                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['contract_number']); ?></div>
                                        <?php endif; ?>
                                        <div class="form-text">Уникальный номер договора</div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="phone" class="form-label">Телефон</label>
                                        <input type="text" class="form-control <?php echo isset($errors['phone']) ? 'is-invalid' : ''; ?>" 
                                               id="phone" name="phone" 
                                               value="<?php echo htmlspecialchars($_POST['phone'] ?? $client_data['phone']); ?>" 
                                               maxlength="20" 
                                               placeholder="+7 (900) 123-45-67">
                                        <?php if (isset($errors['phone'])): ?>
                                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['phone']); ?></div>
                                        <?php endif; ?>
                                        <div class="form-text">Необязательное поле</div>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="full_name" class="form-label">ФИО клиента <span class="text-danger">*</span></label>
                                <input type="text" class="form-control <?php echo isset($errors['full_name']) ? 'is-invalid' : ''; ?>" 
                                       id="full_name" name="full_name" 
                                       value="<?php echo htmlspecialchars($_POST['full_name'] ?? $client_data['full_name']); ?>" 
                                       required maxlength="100"
                                       placeholder="Иванов Иван Иванович">
                                <?php if (isset($errors['full_name'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['full_name']); ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="mb-4">
                                <label for="address" class="form-label">Адрес подключения <span class="text-danger">*</span></label>
                                <textarea class="form-control <?php echo isset($errors['address']) ? 'is-invalid' : ''; ?>" 
                                          id="address" name="address" rows="3" 
                                          required maxlength="255"
                                          placeholder="г. Москва, ул. Примерная, д. 1, кв. 1"><?php echo htmlspecialchars($_POST['address'] ?? $client_data['address']); ?></textarea>
                                <?php if (isset($errors['address'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['address']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">Полный адрес подключения услуг</div>
                            </div>

                            <!-- Информация о записи -->
                            <div class="card bg-light mb-4">
                                <div class="card-body">
                                    <h6 class="text-primary mb-3">
                                        <i class="bi bi-info-circle me-2"></i>Информация о записи
                                    </h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <small class="text-muted">
                                                <i class="bi bi-calendar me-1"></i>Создан: <?php echo date('d.m.Y H:i', strtotime($client_data['created_at'])); ?>
                                            </small>
                                        </div>
                                        <div class="col-md-6">
                                            <?php if ($client_data['updated_at'] && $client_data['updated_at'] != $client_data['created_at']): ?>
                                                <small class="text-muted">
                                                    <i class="bi bi-arrow-clockwise me-1"></i>Обновлен: <?php echo date('d.m.Y H:i', strtotime($client_data['updated_at'])); ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="row mt-2">
                                        <div class="col-md-6">
                                            <small class="text-muted">
                                                <i class="bi bi-hdd me-1"></i>Устройств: 
                                                <span class="badge bg-<?php echo $device_count > 0 ? 'info' : 'secondary'; ?>">
                                                    <?php echo $device_count; ?>
                                                </span>
                                            </small>
                                        </div>
                                        <div class="col-md-6">
                                            <small class="text-muted">
                                                <i class="bi bi-hash me-1"></i>ID: <?php echo htmlspecialchars($client_data['id']); ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="d-grid gap-2 d-md-flex">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-circle me-1"></i>Сохранить изменения
                                </button>
                                <a href="list.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-x-circle me-1"></i>Отмена
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Быстрые действия -->
                <div class="card stat-card mt-4">
                    <div class="card-header bg-transparent">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-lightning me-2"></i>Быстрые действия
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <a href="../devices/list.php?client_id=<?php echo htmlspecialchars($client_id); ?>" 
                                   class="btn btn-outline-info w-100 d-flex align-items-center justify-content-center py-2">
                                    <i class="bi bi-hdd me-2"></i>
                                    <span>Устройства клиента</span>
                                </a>
                            </div>
                            <div class="col-md-6">
                                <a href="../devices/add.php?client_id=<?php echo htmlspecialchars($client_id); ?>" 
                                   class="btn btn-outline-success w-100 d-flex align-items-center justify-content-center py-2">
                                    <i class="bi bi-plus-circle me-2"></i>
                                    <span>Добавить устройство</span>
                                </a>
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