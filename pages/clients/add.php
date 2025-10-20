<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
require_once '../../includes/audit_system.php';
requireAuth();
requireAnyRole(['admin', 'engineer']);

$errors = [];
$success = '';

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
    
    // Проверка уникальности номера договора
    if (empty($errors)) {
        try {
            $check_stmt = $conn->prepare("SELECT id FROM clients WHERE contract_number = ?");
            $check_stmt->bind_param("s", $contract_number);
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
            $insert_stmt = $conn->prepare("
                INSERT INTO clients (contract_number, full_name, address, phone) 
                VALUES (?, ?, ?, ?)
            ");
            $insert_stmt->bind_param("ssss", $contract_number, $full_name, $address, $phone);
            
            if ($insert_stmt->execute()) {
                $client_id = $insert_stmt->insert_id;
                
                // Логируем создание
                AuditSystem::logCreate('clients', $client_id, 
                    "Добавлен клиент: {$full_name} (договор: {$contract_number})",
                    [
                        'contract_number' => $contract_number,
                        'full_name' => $full_name,
                        'address' => $address,
                        'phone' => $phone
                    ]
                );
                
                $success = 'Клиент успешно добавлен';
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
    <title>Добавить клиента - Web-IPAM</title>
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
                        <li class="breadcrumb-item active">Добавить клиента</li>
                    </ol>
                </nav>

                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">Добавить нового клиента</h4>
                        
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
                                               value="<?php echo htmlspecialchars($_POST['contract_number'] ?? ''); ?>" 
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
                                               value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" 
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
                                       value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" 
                                       required maxlength="100">
                                <?php if (isset($errors['full_name'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['full_name']); ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="mb-3">
                                <label for="address" class="form-label">Адрес подключения *</label>
                                <textarea class="form-control <?php echo isset($errors['address']) ? 'is-invalid' : ''; ?>" 
                                          id="address" name="address" rows="3" 
                                          required maxlength="255"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                                <?php if (isset($errors['address'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['address']); ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="d-grid gap-2 d-md-flex">
                                <button type="submit" class="btn btn-primary">Добавить клиента</button>
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