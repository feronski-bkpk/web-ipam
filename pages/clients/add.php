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
                        <li class="breadcrumb-item active">Добавить клиента</li>
                    </ol>
                </nav>

                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="h3 mb-1">Добавить клиента</h1>
                        <p class="text-muted mb-0">Создание новой клиентской записи</p>
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
                            <i class="bi bi-person-plus me-2"></i>Форма добавления клиента
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
                                               value="<?php echo htmlspecialchars($_POST['contract_number'] ?? ''); ?>" 
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
                                               value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" 
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
                                       value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" 
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
                                          placeholder="г. Москва, ул. Примерная, д. 1, кв. 1"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                                <?php if (isset($errors['address'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['address']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">Полный адрес подключения услуг</div>
                            </div>

                            <div class="d-grid gap-2 d-md-flex">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-plus-circle me-1"></i>Добавить клиента
                                </button>
                                <a href="list.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-x-circle me-1"></i>Отмена
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Информация о клиентах -->
                <div class="card stat-card mt-4">
                    <div class="card-header bg-transparent">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-info-circle me-2"></i>Информация о клиентах
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="text-primary">Обязательные поля</h6>
                                <ul class="text-muted small">
                                    <li><strong>Номер договора</strong> - уникальный идентификатор</li>
                                    <li><strong>ФИО клиента</strong> - полное имя абонента</li>
                                    <li><strong>Адрес подключения</strong> - место оказания услуг</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-primary">Особенности</h6>
                                <ul class="text-muted small">
                                    <li>Один клиент может иметь несколько устройств</li>
                                    <li>Номер договора должен быть уникальным</li>
                                    <li>Телефон используется для связи с клиентом</li>
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