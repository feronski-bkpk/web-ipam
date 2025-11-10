<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
require_once '../../includes/audit_system.php';
requireAuth();
requireRole('admin');

$errors = [];
$success = '';

// Проверяем ID пользователя
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: list.php');
    exit();
}

$user_id = intval($_GET['id']);

// Получаем данные пользователя
try {
    $user_stmt = $conn->prepare("SELECT id, login, role, full_name, created_at, last_login FROM users WHERE id = ?");
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

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    $role = $_POST['role'] ?? 'operator';
    
    // Валидация
    if (empty($login)) {
        $errors['login'] = 'Логин обязателен';
    } elseif (strlen($login) < 3) {
        $errors['login'] = 'Логин должен содержать минимум 3 символа';
    } elseif (strlen($login) > 50) {
        $errors['login'] = 'Логин слишком длинный';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $login)) {
        $errors['login'] = 'Логин может содержать только буквы, цифры и подчеркивания';
    }
    
    if ($password && strlen($password) < 6) {
        $errors['password'] = 'Пароль должен содержать минимум 6 символов';
    }
    
    if ($password && $password !== $password_confirm) {
        $errors['password_confirm'] = 'Пароли не совпадают';
    }
    
    if (empty($full_name)) {
        $errors['full_name'] = 'ФИО обязательно';
    } elseif (strlen($full_name) > 100) {
        $errors['full_name'] = 'ФИО слишком длинное';
    }
    
    if (!in_array($role, ['admin', 'engineer', 'operator'])) {
        $errors['role'] = 'Неверная роль пользователя';
    }
    
    // Проверка уникальности логина (исключая текущего пользователя)
    if (empty($errors)) {
        try {
            $check_stmt = $conn->prepare("SELECT id FROM users WHERE login = ? AND id != ?");
            $check_stmt->bind_param("si", $login, $user_id);
            $check_stmt->execute();
            
            if ($check_stmt->get_result()->num_rows > 0) {
                $errors['login'] = 'Пользователь с таким логином уже существует';
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
                'login' => $user_data['login'],
                'role' => $user_data['role'],
                'full_name' => $user_data['full_name']
            ];
            
            if ($password) {
                // Обновляем с паролем
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $update_stmt = $conn->prepare("
                    UPDATE users 
                    SET login = ?, password_hash = ?, role = ?, full_name = ? 
                    WHERE id = ?
                ");
                $update_stmt->bind_param("ssssi", $login, $password_hash, $role, $full_name, $user_id);
            } else {
                // Обновляем без пароля
                $update_stmt = $conn->prepare("
                    UPDATE users 
                    SET login = ?, role = ?, full_name = ? 
                    WHERE id = ?
                ");
                $update_stmt->bind_param("sssi", $login, $role, $full_name, $user_id);
            }
            
            if ($update_stmt->execute()) {
                // Логируем изменение
                $changes = [];
                if ($user_data['login'] != $login) $changes['login'] = $login;
                if ($user_data['role'] != $role) $changes['role'] = $role;
                if ($user_data['full_name'] != $full_name) $changes['full_name'] = $full_name;
                if ($password) $changes['password'] = '***';
                
                if (!empty($changes)) {
                    AuditSystem::logUpdate('users', $user_id, 
                        "Изменен пользователь: {$full_name}",
                        $old_values,
                        [
                            'login' => $login,
                            'role' => $role,
                            'full_name' => $full_name
                        ]
                    );
                }
                
                $success = 'Данные пользователя успешно обновлены';
                // Обновляем данные для отображения
                $user_data = array_merge($user_data, [
                    'login' => $login,
                    'role' => $role,
                    'full_name' => $full_name
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
    <title>Редактировать пользователя - Web-IPAM</title>
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
                        <h1 class="h3 mb-1">Редактирование пользователя</h1>
                        <p class="text-muted mb-0">Изменение данных пользователя системы</p>
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
                            <i class="bi bi-pencil me-2"></i>Редактирование пользователя
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" id="user-form">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="login" class="form-label">Логин <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control <?php echo isset($errors['login']) ? 'is-invalid' : ''; ?>" 
                                               id="login" name="login" 
                                               value="<?php echo htmlspecialchars($_POST['login'] ?? $user_data['login']); ?>" 
                                               required minlength="3" maxlength="50" pattern="[a-zA-Z0-9_]+">
                                        <?php if (isset($errors['login'])): ?>
                                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['login']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="role" class="form-label">Роль <span class="text-danger">*</span></label>
                                        <select class="form-select <?php echo isset($errors['role']) ? 'is-invalid' : ''; ?>" 
                                                id="role" name="role" required>
                                            <option value="operator" <?php echo ($_POST['role'] ?? $user_data['role']) === 'operator' ? 'selected' : ''; ?>>Оператор</option>
                                            <option value="engineer" <?php echo ($_POST['role'] ?? $user_data['role']) === 'engineer' ? 'selected' : ''; ?>>Инженер</option>
                                            <option value="admin" <?php echo ($_POST['role'] ?? $user_data['role']) === 'admin' ? 'selected' : ''; ?>>Администратор</option>
                                        </select>
                                        <?php if (isset($errors['role'])): ?>
                                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['role']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="full_name" class="form-label">ФИО <span class="text-danger">*</span></label>
                                <input type="text" class="form-control <?php echo isset($errors['full_name']) ? 'is-invalid' : ''; ?>" 
                                       id="full_name" name="full_name" 
                                       value="<?php echo htmlspecialchars($_POST['full_name'] ?? $user_data['full_name']); ?>" 
                                       required maxlength="100">
                                <?php if (isset($errors['full_name'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['full_name']); ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="password" class="form-label">Новый пароль</label>
                                        <div class="input-group">
                                            <input type="password" class="form-control <?php echo isset($errors['password']) ? 'is-invalid' : ''; ?>" 
                                                   id="password" name="password" 
                                                   minlength="6">
                                            <button type="button" class="btn btn-outline-secondary" onclick="togglePassword('password')">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                        <?php if (isset($errors['password'])): ?>
                                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['password']); ?></div>
                                        <?php endif; ?>
                                        <div class="form-text">Оставьте пустым, если не хотите менять пароль</div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="password_confirm" class="form-label">Подтверждение пароля</label>
                                        <div class="input-group">
                                            <input type="password" class="form-control <?php echo isset($errors['password_confirm']) ? 'is-invalid' : ''; ?>" 
                                                   id="password_confirm" name="password_confirm" 
                                                   minlength="6">
                                            <button type="button" class="btn btn-outline-secondary" onclick="togglePassword('password_confirm')">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                        <?php if (isset($errors['password_confirm'])): ?>
                                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['password_confirm']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
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
                                                <strong>Создан:</strong><br>
                                                <?php echo date('d.m.Y H:i', strtotime($user_data['created_at'])); ?>
                                            </small>
                                        </div>
                                        <div class="col-md-6">
                                            <small class="text-muted">
                                                <?php if ($user_data['last_login']): ?>
                                                    <strong>Последний вход:</strong><br>
                                                    <?php echo date('d.m.Y H:i', strtotime($user_data['last_login'])); ?>
                                                <?php else: ?>
                                                    <strong>ID записи:</strong><br>
                                                    <?php echo $user_data['id']; ?>
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
    <script>
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const icon = field.parentNode.querySelector('.bi');
            const type = field.getAttribute('type') === 'password' ? 'text' : 'password';
            field.setAttribute('type', type);
            
            if (type === 'text') {
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        }

        // Валидация паролей на клиенте
        document.getElementById('user-form').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const passwordConfirm = document.getElementById('password_confirm').value;
            
            if (password && password !== passwordConfirm) {
                e.preventDefault();
                alert('Пароли не совпадают. Пожалуйста, проверьте введенные данные.');
                document.getElementById('password_confirm').focus();
            }
        });
    </script>
</body>
</html>