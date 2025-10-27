<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
require_once '../../includes/audit_system.php';
requireAuth();
requireRole('admin');

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

// Проверяем, есть ли связанные IP-адреса
$ips_stmt = $conn->prepare("SELECT COUNT(*) as ip_count FROM ip_addresses WHERE subnet_id = ?");
$ips_stmt->bind_param("i", $subnet_id);
$ips_stmt->execute();
$ip_count = $ips_stmt->get_result()->fetch_assoc()['ip_count'];
$ips_stmt->close();

// Получаем информацию об активных IP для предупреждения
$active_ips_stmt = $conn->prepare("
    SELECT COUNT(*) as active_count 
    FROM ip_addresses 
    WHERE subnet_id = ? AND status = 'active'
");
$active_ips_stmt->bind_param("i", $subnet_id);
$active_ips_stmt->execute();
$active_ip_count = $active_ips_stmt->get_result()->fetch_assoc()['active_count'];
$active_ips_stmt->close();

// Обработка удаления
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $confirm_force = isset($_POST['force_delete']) && $_POST['force_delete'] === '1';
    
    if ($ip_count > 0 && !$confirm_force) {
        $error = "В подсети имеются IP-адреса. Для удаления подсети необходимо подтвердить удаление всех связанных IP-адресов.";
    } else {
        try {
            // Логируем информацию перед удалением
            $ip_info = "IP-адресов: {$ip_count}, активных: {$active_ip_count}";
            
            // Удаляем связанные IP-адреса
            $deleted_ips_count = 0;
            if ($ip_count > 0) {
                $delete_ips_stmt = $conn->prepare("DELETE FROM ip_addresses WHERE subnet_id = ?");
                $delete_ips_stmt->bind_param("i", $subnet_id);
                $delete_ips_stmt->execute();
                $deleted_ips_count = $delete_ips_stmt->affected_rows;
                $delete_ips_stmt->close();
            }
            
            // Удаляем подсеть
            $delete_stmt = $conn->prepare("DELETE FROM subnets WHERE id = ?");
            $delete_stmt->bind_param("i", $subnet_id);
            
            if ($delete_stmt->execute()) {
                // Логируем удаление
                AuditSystem::logDelete('subnets', $subnet_id, 
                    "Удалена подсеть: {$subnet_data['network_address']}/{$subnet_data['cidr_mask']} ({$ip_info})",
                    [
                        'network_address' => $subnet_data['network_address'],
                        'cidr_mask' => $subnet_data['cidr_mask'],
                        'gateway' => $subnet_data['gateway'],
                        'description' => $subnet_data['description'],
                        'deleted_ips_count' => $deleted_ips_count,
                        'total_ips_count' => $ip_count,
                        'active_ips_count' => $active_ip_count
                    ]
                );
                
                $_SESSION['success_message'] = "Подсеть {$subnet_data['network_address']}/{$subnet_data['cidr_mask']} успешно удалена. Удалено IP-адресов: {$deleted_ips_count}";
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
    <title>Удалить подсеть - Web-IPAM</title>
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
                        <li class="breadcrumb-item active">Удалить подсеть</li>
                    </ol>
                </nav>

                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">Подтверждение удаления</h4>
                        
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>

                        <?php if ($ip_count > 0): ?>
                            <div class="alert alert-warning">
                                <h5>⚠️ Внимание! В подсети имеются IP-адреса</h5>
                                <p>Удаление подсети приведет к удалению всех связанных IP-адресов.</p>
                                <div class="mt-3">
                                    <strong>Статистика IP-адресов:</strong>
                                    <ul class="mb-3">
                                        <li>Всего IP-адресов: <strong><?php echo $ip_count; ?></strong></li>
                                        <li>Активных IP-адресов: <strong class="text-danger"><?php echo $active_ip_count; ?></strong></li>
                                        <li>Свободных IP-адресов: <strong><?php echo $ip_count - $active_ip_count; ?></strong></li>
                                    </ul>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="force_delete" value="1" id="forceDelete">
                                        <label class="form-check-label text-danger fw-bold" for="forceDelete">
                                            Я понимаю, что все IP-адреса будут удалены, включая активные
                                        </label>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <h5>Вы уверены, что хотите удалить эту подсеть?</h5>
                                <p>Это действие нельзя отменить. Все данные будут записаны в журнал аудита.</p>
                            </div>
                        <?php endif; ?>

                        <div class="card mb-3">
                            <div class="card-body">
                                <h6>Информация о подсети:</h6>
                                <table class="table table-sm">
                                    <tr>
                                        <td><strong>Подсеть:</strong></td>
                                        <td><code><?php echo htmlspecialchars($subnet_data['network_address']); ?>/<?php echo $subnet_data['cidr_mask']; ?></code></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Шлюз:</strong></td>
                                        <td><?php echo $subnet_data['gateway'] ? htmlspecialchars($subnet_data['gateway']) : '—'; ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Описание:</strong></td>
                                        <td><?php echo $subnet_data['description'] ? htmlspecialchars($subnet_data['description']) : '—'; ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>IP-адресов:</strong></td>
                                        <td>
                                            <?php if ($ip_count > 0): ?>
                                                <span class="text-danger fw-bold"><?php echo $ip_count; ?> (<?php echo $active_ip_count; ?> активных)</span>
                                            <?php else: ?>
                                                <span class="text-success">0</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>Создана:</strong></td>
                                        <td><?php echo date('d.m.Y H:i', strtotime($subnet_data['created_at'])); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <form method="POST" action="" id="delete-form">
                            <?php if ($ip_count > 0): ?>
                                <input type="hidden" name="force_delete" id="forceDeleteHidden" value="0">
                            <?php endif; ?>
                            
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-danger" id="delete-button" 
                                    <?php if ($ip_count > 0) echo 'disabled'; ?>>
                                    <?php if ($ip_count > 0): ?>
                                        🗑️ Удалить подсеть и все IP-адреса
                                    <?php else: ?>
                                        🗑️ Удалить подсеть
                                    <?php endif; ?>
                                </button>
                                <a href="list.php" class="btn btn-secondary">❌ Отмена</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        <?php if ($ip_count > 0): ?>
        // Обработка чекбокса подтверждения удаления
        const forceDeleteCheckbox = document.getElementById('forceDelete');
        const forceDeleteHidden = document.getElementById('forceDeleteHidden');
        const deleteButton = document.getElementById('delete-button');
        
        forceDeleteCheckbox.addEventListener('change', function() {
            if (this.checked) {
                deleteButton.disabled = false;
                forceDeleteHidden.value = '1';
                deleteButton.classList.remove('btn-secondary');
                deleteButton.classList.add('btn-danger');
            } else {
                deleteButton.disabled = true;
                forceDeleteHidden.value = '0';
                deleteButton.classList.remove('btn-danger');
                deleteButton.classList.add('btn-secondary');
            }
        });
        
        // Подтверждение удаления с активными IP
        document.getElementById('delete-form').addEventListener('submit', function(e) {
            if (<?php echo $active_ip_count; ?> > 0) {
                const activeCount = <?php echo $active_ip_count; ?>;
                const totalCount = <?php echo $ip_count; ?>;
                if (!confirm(`ВНИМАНИЕ! Вы собираетесь удалить подсеть с ${activeCount} активными IP-адресами (всего ${totalCount}). Это действие невозможно отменить. Продолжить?`)) {
                    e.preventDefault();
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>