<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
require_once '../../includes/audit.php';
requireAuth();
requireRole('admin');

// Проверяем наличие ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: list.php');
    exit();
}

$ip_id = intval($_GET['id']);

// Получаем данные IP-адреса перед удалением
try {
    $ip_stmt = $conn->prepare("
        SELECT ip.*, d.mac_address, c.full_name as client_name
        FROM ip_addresses ip 
        LEFT JOIN devices d ON ip.device_id = d.id 
        LEFT JOIN clients c ON d.client_id = c.id 
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

// Обработка подтверждения удаления
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Логируем данные перед удалением
        logIpAddressAction($ip_id, 'deleted', [
            'ip_address' => $ip_data['ip_address'],
            'subnet_id' => $ip_data['subnet_id'],
            'device_id' => $ip_data['device_id'],
            'type' => $ip_data['type'],
            'status' => $ip_data['status'],
            'description' => $ip_data['description'],
            'mac_address' => $ip_data['mac_address'],
            'client_name' => $ip_data['client_name']
        ], null);
        
        // Удаляем IP-адрес
        $delete_stmt = $conn->prepare("DELETE FROM ip_addresses WHERE id = ?");
        $delete_stmt->bind_param("i", $ip_id);
        
        if ($delete_stmt->execute()) {
            $_SESSION['success_message'] = "IP-адрес {$ip_data['ip_address']} успешно удален";
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
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Удалить IP-адрес - Web-IPAM</title>
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
                        <li class="breadcrumb-item"><a href="list.php">IP-адреса</a></li>
                        <li class="breadcrumb-item active">Удалить IP-адрес</li>
                    </ol>
                </nav>

                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">Подтверждение удаления</h4>
                        
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>

                        <div class="alert alert-warning">
                            <h5>Вы уверены, что хотите удалить этот IP-адрес?</h5>
                            <p>Это действие нельзя отменить.</p>
                        </div>

                        <div class="card mb-3">
                            <div class="card-body">
                                <h6>Информация об IP-адресе:</h6>
                                <table class="table table-sm">
                                    <tr><td><strong>IP-адрес:</strong></td><td><?php echo htmlspecialchars($ip_data['ip_address']); ?></td></tr>
                                    <tr><td><strong>Тип:</strong></td><td><?php echo $ip_data['type'] === 'white' ? 'Белый' : 'Серый'; ?></td></tr>
                                    <tr><td><strong>Статус:</strong></td><td><?php echo $ip_data['status'] === 'active' ? 'Активен' : ($ip_data['status'] === 'reserved' ? 'Зарезервирован' : 'Свободен'); ?></td></tr>
                                    <?php if ($ip_data['mac_address']): ?>
                                        <tr><td><strong>Устройство:</strong></td><td><?php echo htmlspecialchars($ip_data['mac_address']); ?></td></tr>
                                    <?php endif; ?>
                                    <?php if ($ip_data['client_name']): ?>
                                        <tr><td><strong>Клиент:</strong></td><td><?php echo htmlspecialchars($ip_data['client_name']); ?></td></tr>
                                    <?php endif; ?>
                                    <?php if ($ip_data['description']): ?>
                                        <tr><td><strong>Описание:</strong></td><td><?php echo htmlspecialchars($ip_data['description']); ?></td></tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>

                        <form method="POST" action="">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-danger">Да, удалить IP-адрес</button>
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