<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
require_once '../../includes/audit_system.php';
requireAuth();
requireRole('admin');

// Проверяем ID пользователя
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: list.php');
    exit();
}

$user_id = intval($_GET['id']);

// Получаем данные пользователя
try {
    $user_stmt = $conn->prepare("SELECT id, login, role, full_name, created_at FROM users WHERE id = ?");
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user_data = $user_stmt->get_result()->fetch_assoc();
    $user_stmt->close();
    
    if (!$user_data) {
        header('Location: list.php');
        exit();
    }
} catch (Exception $e) {
    error_log("Error fetching user data: " . $e->getMessage());
    header('Location: list.php');
    exit();
}

// Проверяем, не пытается ли пользователь удалить самого себя
if ($user_data['id'] == $_SESSION['user_id']) {
    $_SESSION['error_message'] = 'Вы не можете удалить свой собственный аккаунт';
    header('Location: list.php');
    exit();
}

// Проверяем, есть ли действия пользователя в системе
$actions_count = 0;
try {
    $actions_stmt = $conn->prepare("SELECT COUNT(*) as count FROM system_audit_log WHERE user_id = ?");
    $actions_stmt->bind_param("i", $user_id);
    $actions_stmt->execute();
    $actions_result = $actions_stmt->get_result()->fetch_assoc();
    $actions_count = $actions_result['count'];
    $actions_stmt->close();
} catch (Exception $e) {
    error_log("Error counting user actions: " . $e->getMessage());
}

// Обработка удаления
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $confirm_delete = isset($_POST['confirm_delete']) && $_POST['confirm_delete'] === '1';
    
    if (!$confirm_delete) {
        $error = 'Необходимо подтвердить удаление пользователя';
    } else {
        try {
            // Логируем информацию перед удалением
            $user_info = "Пользователь: {$user_data['full_name']} (логин: {$user_data['login']}, роль: {$user_data['role']})";
            
            // Удаляем пользователя
            $delete_stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $delete_stmt->bind_param("i", $user_id);
            
            if ($delete_stmt->execute()) {
                // Логируем удаление
                AuditSystem::logDelete('users', $user_id, 
                    "Удален пользователь: {$user_data['full_name']}",
                    [
                        'login' => $user_data['login'],
                        'role' => $user_data['role'],
                        'full_name' => $user_data['full_name'],
                        'created_at' => $user_data['created_at'],
                        'actions_count' => $actions_count
                    ]
                );
                
                $_SESSION['success_message'] = "Пользователь {$user_data['full_name']} успешно удален";
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
    <title>Удалить пользователя - Web-IPAM</title>
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
                        <h1 class="h3 mb-1">Удаление пользователя</h1>
                        <p class="text-muted mb-0">Подтверждение удаления пользователя из системы</p>
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
                        <div class="alert alert-warning">
                            <h5 class="alert-heading">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>Вы уверены, что хотите удалить этого пользователя?
                            </h5>
                            <p class="mb-3">Это действие нельзя отменить. Все данные будут записаны в журнал аудита.</p>
                            
                            <?php if ($actions_count > 0): ?>
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle me-2"></i>
                                    <strong>Внимание:</strong> Этот пользователь совершил <?php echo $actions_count; ?> действий в системе, которые останутся в журнале аудита.
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Информация о пользователе -->
                        <div class="card stat-card mb-4">
                            <div class="card-header bg-transparent">
                                <h6 class="card-title mb-0">
                                    <i class="bi bi-person me-2"></i>Информация о пользователе
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <table class="table table-sm table-borderless">
                                            <tr>
                                                <td class="text-muted" style="width: 100px;">ФИО:</td>
                                                <td><strong><?php echo htmlspecialchars($user_data['full_name']); ?></strong></td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Логин:</td>
                                                <td><code><?php echo htmlspecialchars($user_data['login']); ?></code></td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table class="table table-sm table-borderless">
                                            <tr>
                                                <td class="text-muted" style="width: 100px;">Роль:</td>
                                                <td>
                                                    <span class="badge role-badge-<?php echo $user_data['role']; ?>">
                                                        <?php 
                                                        $role_names = [
                                                            'admin' => 'Администратор',
                                                            'engineer' => 'Инженер', 
                                                            'operator' => 'Оператор'
                                                        ];
                                                        echo $role_names[$user_data['role']] ?? $user_data['role'];
                                                        ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Создан:</td>
                                                <td><?php echo date('d.m.Y H:i', strtotime($user_data['created_at'])); ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <form method="POST" action="" id="delete-form">
                            <div class="form-check mb-4">
                                <input class="form-check-input" type="checkbox" name="confirm_delete" value="1" id="confirmDelete">
                                <label class="form-check-label text-danger fw-bold" for="confirmDelete">
                                    Я понимаю, что удаляю пользователя и это действие невозможно отменить
                                </label>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <a href="list.php" class="btn btn-outline-secondary me-2">
                                    <i class="bi bi-x-circle me-1"></i>Отмена
                                </a>
                                <button type="submit" class="btn btn-danger" id="delete-button" disabled>
                                    <i class="bi bi-trash me-1"></i>Удалить пользователя
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
        // Обработка чекбокса подтверждения удаления
        const confirmDeleteCheckbox = document.getElementById('confirmDelete');
        const deleteButton = document.getElementById('delete-button');
        
        confirmDeleteCheckbox.addEventListener('change', function() {
            if (this.checked) {
                deleteButton.disabled = false;
                deleteButton.classList.remove('btn-secondary');
                deleteButton.classList.add('btn-danger');
            } else {
                deleteButton.disabled = true;
                deleteButton.classList.remove('btn-danger');
                deleteButton.classList.add('btn-secondary');
            }
        });
        
        // Подтверждение удаления
        document.getElementById('delete-form').addEventListener('submit', function(e) {
            if (!confirm('Вы уверены, что хотите удалить пользователя <?php echo htmlspecialchars($user_data['full_name']); ?>? Это действие невозможно отменить.')) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>