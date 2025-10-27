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
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    
    <div class="container mt-4">
        <div class="row">
            <div class="col-md-8 mx-auto">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="../../index.php">Главная</a></li>
                        <li class="breadcrumb-item"><a href="list.php">Подсети</a></li>
                        <li class="breadcrumb-item active">Редактировать подсеть</li>
                    </ol>
                </nav>

                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">Редактировать подсеть</h4>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                        <?php endif; ?>

                        <?php if (isset($errors['general'])): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($errors['general']); ?></div>
                        <?php endif; ?>

                        <form method="POST" action="">
                            <!-- Неизменяемые поля (только для информации) -->
                            <div class="mb-3">
                                <label class="form-label">Подсеть (неизменяемо)</label>
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

                            <div class="mb-3">
                                <label for="description" class="form-label">Описание</label>
                                <textarea class="form-control" id="description" name="description" 
                                          rows="3" placeholder="Описание назначения подсети"><?php echo htmlspecialchars($_POST['description'] ?? $subnet_data['description']); ?></textarea>
                            </div>

                            <!-- Информация о записи -->
                            <div class="card bg-light mb-3">
                                <div class="card-body">
                                    <small class="text-muted">
                                        <strong>Информация о записи:</strong><br>
                                        Создана: <?php echo date('d.m.Y H:i', strtotime($subnet_data['created_at'])); ?><br>
                                        <?php if ($subnet_data['updated_at'] && $subnet_data['updated_at'] != $subnet_data['created_at']): ?>
                                            Обновлена: <?php echo date('d.m.Y H:i', strtotime($subnet_data['updated_at'])); ?><br>
                                        <?php endif; ?>
                                        ID записи: <?php echo $subnet_data['id']; ?>
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