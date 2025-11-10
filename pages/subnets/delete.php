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
                        <h1 class="h3 mb-1">Удаление подсети</h1>
                        <p class="text-muted mb-0">Подтверждение удаления подсети из системы</p>
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
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Основная форма -->
                <div class="card stat-card">
                    <div class="card-header bg-transparent">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-trash me-2"></i>Подтверждение удаления
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if ($ip_count > 0): ?>
                            <div class="alert alert-warning">
                                <h5 class="alert-heading">
                                    <i class="bi bi-exclamation-triangle-fill me-2"></i>Внимание! В подсети имеются IP-адреса
                                </h5>
                                <p class="mb-3">Удаление подсети приведет к удалению всех связанных IP-адресов.</p>
                                
                                <div class="mb-3">
                                    <strong>Статистика IP-адресов:</strong>
                                    <div class="row mt-2">
                                        <div class="col-md-4">
                                            <div class="card bg-light">
                                                <div class="card-body text-center py-2">
                                                    <div class="h5 mb-0"><?php echo $ip_count; ?></div>
                                                    <small class="text-muted">Всего IP-адресов</small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="card bg-danger text-white">
                                                <div class="card-body text-center py-2">
                                                    <div class="h5 mb-0"><?php echo $active_ip_count; ?></div>
                                                    <small>Активных IP-адресов</small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="card bg-success text-white">
                                                <div class="card-body text-center py-2">
                                                    <div class="h5 mb-0"><?php echo $ip_count - $active_ip_count; ?></div>
                                                    <small>Свободных IP-адресов</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="force_delete" value="1" id="forceDelete">
                                    <label class="form-check-label text-danger fw-bold" for="forceDelete">
                                        Я понимаю, что все IP-адреса будут удалены, включая активные
                                    </label>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <h5 class="alert-heading">
                                    <i class="bi bi-exclamation-triangle me-2"></i>Вы уверены, что хотите удалить эту подсеть?
                                </h5>
                                <p class="mb-0">Это действие нельзя отменить. Все данные будут записаны в журнал аудита.</p>
                            </div>
                        <?php endif; ?>

                        <!-- Информация о подсети -->
                        <div class="card stat-card mb-4">
                            <div class="card-header bg-transparent">
                                <h6 class="card-title mb-0">
                                    <i class="bi bi-info-circle me-2"></i>Информация о подсети
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <table class="table table-sm table-borderless">
                                            <tr>
                                                <td><strong>Подсеть:</strong></td>
                                                <td><code><?php echo htmlspecialchars($subnet_data['network_address']); ?>/<?php echo $subnet_data['cidr_mask']; ?></code></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Шлюз:</strong></td>
                                                <td><?php echo $subnet_data['gateway'] ? htmlspecialchars($subnet_data['gateway']) : '<span class="text-muted">—</span>'; ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table class="table table-sm table-borderless">
                                            <tr>
                                                <td><strong>Описание:</strong></td>
                                                <td><?php echo $subnet_data['description'] ? htmlspecialchars($subnet_data['description']) : '<span class="text-muted">—</span>'; ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Создана:</strong></td>
                                                <td><?php echo date('d.m.Y H:i', strtotime($subnet_data['created_at'])); ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <form method="POST" action="" id="delete-form">
                            <?php if ($ip_count > 0): ?>
                                <input type="hidden" name="force_delete" id="forceDeleteHidden" value="0">
                            <?php endif; ?>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <a href="list.php" class="btn btn-outline-secondary me-2">
                                    <i class="bi bi-x-circle me-1"></i>Отмена
                                </a>
                                <button type="submit" class="btn btn-danger" id="delete-button" 
                                    <?php if ($ip_count > 0) echo 'disabled'; ?>>
                                    <i class="bi bi-trash me-1"></i>
                                    <?php if ($ip_count > 0): ?>
                                        Удалить подсеть и все IP-адреса
                                    <?php else: ?>
                                        Удалить подсеть
                                    <?php endif; ?>
                                </button>
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