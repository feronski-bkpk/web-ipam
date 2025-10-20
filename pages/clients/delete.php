<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
require_once '../../includes/audit_system.php';
requireAuth();
requireRole('admin');

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

// Проверяем, есть ли связанные устройства
$devices_stmt = $conn->prepare("SELECT COUNT(*) as device_count FROM devices WHERE client_id = ?");
$devices_stmt->bind_param("i", $client_id);
$devices_stmt->execute();
$device_count = $devices_stmt->get_result()->fetch_assoc()['device_count'];
$devices_stmt->close();

// Обработка удаления
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($device_count > 0) {
        $error = "Невозможно удалить клиента: имеются связанные устройства ({$device_count} шт.)";
    } else {
        try {
            // Логируем удаление
            AuditSystem::logDelete('clients', $client_id, 
                "Удален клиент: {$client_data['full_name']} (договор: {$client_data['contract_number']})",
                [
                    'contract_number' => $client_data['contract_number'],
                    'full_name' => $client_data['full_name'],
                    'address' => $client_data['address'],
                    'phone' => $client_data['phone']
                ]
            );
            
            // Удаляем клиента
            $delete_stmt = $conn->prepare("DELETE FROM clients WHERE id = ?");
            $delete_stmt->bind_param("i", $client_id);
            
            if ($delete_stmt->execute()) {
                $_SESSION['success_message'] = "Клиент {$client_data['full_name']} успешно удален";
                header('Location: list.php');
                exit();
            } else {
                $error = "Ошибка при удалении: " . $delete_stmt->error;
            }
            
            $delete_stmt->close();
        } catch (Exception $e) {
            $error = "Ошибка базы данных: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Удалить клиента - Web-IPAM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    
    <div class="container mt-4">
        <div class="row">
            <div class="col-md-6 mx-auto">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="../../index.php">Главная</a></li>
                        <li class="breadcrumb-item"><a href="list.php">Клиенты</a></li>
                        <li class="breadcrumb-item active">Удалить клиента</li>
                    </ol>
                </nav>

                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">Подтверждение удаления</h4>
                        
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>

                        <?php if ($device_count > 0): ?>
                            <div class="alert alert-warning">
                                <h5>Невозможно удалить клиента!</h5>
                                <p>У клиента имеются связанные устройства. Сначала удалите или переназначьте устройства.</p>
                                <p><strong>Количество устройств:</strong> <?php echo $device_count; ?></p>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <h5>Вы уверены, что хотите удалить этого клиента?</h5>
                                <p>Это действие нельзя отменить. Все данные будут записаны в журнал аудита.</p>
                            </div>
                        <?php endif; ?>

                        <div class="card mb-3">
                            <div class="card-body">
                                <h6>Информация о клиенте:</h6>
                                <table class="table table-sm">
                                    <tr><td><strong>ФИО:</strong></td><td><?php echo htmlspecialchars($client_data['full_name']); ?></td></tr>
                                    <tr><td><strong>Договор:</strong></td><td><?php echo htmlspecialchars($client_data['contract_number']); ?></td></tr>
                                    <tr><td><strong>Адрес:</strong></td><td><?php echo htmlspecialchars($client_data['address']); ?></td></tr>
                                    <tr><td><strong>Телефон:</strong></td><td><?php echo $client_data['phone'] ? htmlspecialchars($client_data['phone']) : '—'; ?></td></tr>
                                    <tr><td><strong>Устройств:</strong></td><td><?php echo $device_count; ?></td></tr>
                                    <tr><td><strong>Создан:</strong></td><td><?php echo date('d.m.Y', strtotime($client_data['created_at'])); ?></td></tr>
                                </table>
                            </div>
                        </div>

                        <?php if ($device_count == 0): ?>
                            <form method="POST" action="">
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-danger">Да, удалить клиента</button>
                                    <a href="list.php" class="btn btn-secondary">Отмена</a>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="d-grid gap-2">
                                <a href="list.php" class="btn btn-primary">Вернуться к списку</a>
                                <a href="../devices/list.php?client_id=<?php echo $client_id; ?>" class="btn btn-outline-warning">Просмотреть устройства</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>