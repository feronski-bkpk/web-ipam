<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
require_once '../../includes/audit_system.php';
requireAuth();
requireAnyRole(['admin', 'engineer']);

$errors = [];
$success = '';

// Проверяем ID подсети
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: list.php');
    exit();
}

$subnet_id = intval($_GET['id']);

// Получаем данные подсети
try {
    $subnet_stmt = $conn->prepare("SELECT * FROM subnets WHERE id = ?");
    $subnet_stmt->bind_param("i", $subnet_id);
    $subnet_stmt->execute();
    $subnet_data = $subnet_stmt->get_result()->fetch_assoc();
    $subnet_stmt->close();
    
    if (!$subnet_data) {
        header('Location: list.php');
        exit();
    }
} catch (Exception $e) {
    error_log("Error fetching subnet data: " . $e->getMessage());
    header('Location: list.php');
    exit();
}

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $gateway = trim($_POST['gateway'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    // Валидация
    if ($gateway && !filter_var($gateway, FILTER_VALIDATE_IP)) {
        $errors['gateway'] = 'Неверный формат IP-адреса шлюза';
    }
    
    // Сохранение
    if (empty($errors)) {
        try {
            // Сохраняем старые значения для аудита
            $old_values = [
                'gateway' => $subnet_data['gateway'],
                'description' => $subnet_data['description']
            ];
            
            $update_stmt = $conn->prepare("
                UPDATE subnets 
                SET gateway = ?, description = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $update_stmt->bind_param("ssi", $gateway, $description, $subnet_id);
            
            if ($update_stmt->execute()) {
                // Логируем изменение
                $changes = [];
                if ($subnet_data['gateway'] != $gateway) $changes['gateway'] = $gateway;
                if ($subnet_data['description'] != $description) $changes['description'] = $description;
                
                if (!empty($changes)) {
                    AuditSystem::logUpdate('subnets', $subnet_id, 
                        "Изменена подсеть: {$subnet_data['network_address']}/{$subnet_data['cidr_mask']}",
                        $old_values,
                        [
                            'gateway' => $gateway,
                            'description' => $description
                        ]
                    );
                }
                
                $success = 'Данные подсети успешно обновлены';
                // Обновляем данные для отображения
                $subnet_data = array_merge($subnet_data, [
                    'gateway' => $gateway,
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
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактировать подсеть - Web-IPAM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link href="../../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    
    <div class="container mt-4">
        <!-- Заголовок -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="h3 mb-1">Редактирование подсети</h1>
                        <p class="text-muted mb-0">Изменение параметров существующей подсети</p>
                    </div>
                    <div>
                        <a href="list.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i>Назад к списку
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-8 mx-auto">
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
                            <i class="bi bi-pencil me-2"></i>Редактирование подсети
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <!-- Неизменяемые поля -->
                            <div class="mb-3">
                                <label class="form-label">Подсеть</label>
                                <input type="text" class="form-control" 
                                       value="<?php echo htmlspecialchars($subnet_data['network_address']); ?>/<?php echo $subnet_data['cidr_mask']; ?>" 
                                       readonly>
                                <div class="form-text">Адрес сети и маска не могут быть изменены</div>
                            </div>

                            <div class="mb-3">
                                <label for="gateway" class="form-label">Шлюз по умолчанию</label>
                                <input type="text" class="form-control <?php echo isset($errors['gateway']) ? 'is-invalid' : ''; ?>" 
                                       id="gateway" name="gateway" 
                                       value="<?php echo htmlspecialchars($_POST['gateway'] ?? $subnet_data['gateway']); ?>" 
                                       placeholder="192.168.1.1">
                                <?php if (isset($errors['gateway'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['gateway']); ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="mb-4">
                                <label for="description" class="form-label">Описание</label>
                                <textarea class="form-control" id="description" name="description" 
                                          rows="3" placeholder="Описание назначения подсети"><?php echo htmlspecialchars($_POST['description'] ?? $subnet_data['description']); ?></textarea>
                            </div>

                            <!-- Информация о записи -->
                            <div class="card stat-card mb-4">
                                <div class="card-header bg-transparent">
                                    <h6 class="card-title mb-0">
                                        <i class="bi bi-info-circle me-2"></i>Информация о записи
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <small class="text-muted">
                                                <strong>Создана:</strong><br>
                                                <?php echo date('d.m.Y H:i', strtotime($subnet_data['created_at'])); ?>
                                            </small>
                                        </div>
                                        <div class="col-md-6">
                                            <small class="text-muted">
                                                <?php if ($subnet_data['updated_at'] && $subnet_data['updated_at'] != $subnet_data['created_at']): ?>
                                                    <strong>Обновлена:</strong><br>
                                                    <?php echo date('d.m.Y H:i', strtotime($subnet_data['updated_at'])); ?>
                                                <?php else: ?>
                                                    <strong>ID записи:</strong><br>
                                                    <?php echo $subnet_data['id']; ?>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <a href="list.php" class="btn btn-outline-secondary me-2">
                                    <i class="bi bi-x-circle me-1"></i>Отмена
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-circle me-1"></i>Сохранить изменения
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>