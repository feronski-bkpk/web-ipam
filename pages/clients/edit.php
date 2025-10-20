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
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    
    <div class="container mt-4">
        <div class="row">
            <div class="col-md-8 mx-auto">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="../../index.php">Главная</a></li>
                        <li class="breadcrumb-item"><a href="list.php">Клиенты</a></li>
                        <li class="breadcrumb-item active">Редактировать клиента</li>
                    </ol>
                </nav>

                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">Редактировать клиента</h4>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                        <?php endif; ?>

                        <?php if (isset($errors['general'])): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($errors['general']); ?></div>
                        <?php endif; ?>

                        <form method="POST" action="">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="contract_number" class="form-label">Номер договора *</label>
                                        <input type="text" class="form-control <?php echo isset($errors['contract_number']) ? 'is-invalid' : ''; ?>" 
                                               id="contract_number" name="contract_number" 
                                               value="<?php echo htmlspecialchars($_POST['contract_number'] ?? $client_data['contract_number']); ?>" 
                                               required maxlength="20">
                                        <?php if (isset($errors['contract_number'])): ?>
                                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['contract_number']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="phone" class="form-label">Телефон</label>
                                        <input type="text" class="form-control <?php echo isset($errors['phone']) ? 'is-invalid' : ''; ?>" 
                                               id="phone" name="phone" 
                                               value="<?php echo htmlspecialchars($_POST['phone'] ?? $client_data['phone']); ?>" 
                                               maxlength="20" placeholder="+7 (900) 123-45-67">
                                        <?php if (isset($errors['phone'])): ?>
                                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['phone']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="full_name" class="form-label">ФИО клиента *</label>
                                <input type="text" class="form-control <?php echo isset($errors['full_name']) ? 'is-invalid' : ''; ?>" 
                                       id="full_name" name="full_name" 
                                       value="<?php echo htmlspecialchars($_POST['full_name'] ?? $client_data['full_name']); ?>" 
                                       required maxlength="100">
                                <?php if (isset($errors['full_name'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['full_name']); ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="mb-3">
                                <label for="address" class="form-label">Адрес подключения *</label>
                                <textarea class="form-control <?php echo isset($errors['address']) ? 'is-invalid' : ''; ?>" 
                                          id="address" name="address" rows="3" 
                                          required maxlength="255"><?php echo htmlspecialchars($_POST['address'] ?? $client_data['address']); ?></textarea>
                                <?php if (isset($errors['address'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['address']); ?></div>
                                <?php endif; ?>
                            </div>

                            <!-- Информация о записи -->
                            <div class="card bg-light mb-3">
                                <div class="card-body">
                                    <small class="text-muted">
                                        <strong>Информация о записи:</strong><br>
                                        Создан: <?php echo date('d.m.Y H:i', strtotime($client_data['created_at'])); ?><br>
                                        <?php if ($client_data['updated_at'] && $client_data['updated_at'] != $client_data['created_at']): ?>
                                            Обновлен: <?php echo date('d.m.Y H:i', strtotime($client_data['updated_at'])); ?><br>
                                        <?php endif; ?>
                                        ID записи: <?php echo $client_data['id']; ?>
                                    </small>
                                </div>
                            </div>

                            <div class="d-grid gap-2 d-md-flex">
                                <button type="submit" class="btn btn-primary">Сохранить изменения</button>
                                <a href="list.php" class="btn btn-secondary">Отмена</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>