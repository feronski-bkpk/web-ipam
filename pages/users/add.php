<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
require_once '../../includes/audit_system.php';
requireAuth();
requireRole('admin');

$errors = [];
$success = '';

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
    
    if (empty($password)) {
        $errors['password'] = 'Пароль обязателен';
    } elseif (strlen($password) < 6) {
        $errors['password'] = 'Пароль должен содержать минимум 6 символов';
    }
    
    if ($password !== $password_confirm) {
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
    
    // Проверка уникальности логина
    if (empty($errors)) {
        try {
            $check_stmt = $conn->prepare("SELECT id FROM users WHERE login = ?");
            $check_stmt->bind_param("s", $login);
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
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            $insert_stmt = $conn->prepare("
                INSERT INTO users (login, password_hash, role, full_name) 
                VALUES (?, ?, ?, ?)
            ");
            $insert_stmt->bind_param("ssss", $login, $password_hash, $role, $full_name);
            
            if ($insert_stmt->execute()) {
                $user_id = $insert_stmt->insert_id;
                
                // Логируем создание
                AuditSystem::logCreate('users', $user_id, 
                    "Добавлен пользователь: {$full_name} (логин: {$login}, роль: {$role})",
                    [
                        'login' => $login,
                        'role' => $role,
                        'full_name' => $full_name
                    ]
                );
                
                $success = 'Пользователь успешно добавлен';
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
    <title>Добавить пользователя - Web-IPAM</title>
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
                        <li class="breadcrumb-item"><a href="list.php">Пользователи</a></li>
                        <li class="breadcrumb-item active">Добавить пользователя</li>
                    </ol>
                </nav>

                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">Добавить нового пользователя</h4>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                        <?php endif; ?>

                        <?php if (isset($errors['general'])): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($errors['general']); ?></div>
                        <?php endif; ?>

                        <form method="POST" action="" id="user-form">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="login" class="form-label">Логин *</label>
                                        <input type="text" class="form-control <?php echo isset($errors['login']) ? 'is-invalid' : ''; ?>" 
                                               id="login" name="login" 
                                               value="<?php echo htmlspecialchars($_POST['login'] ?? ''); ?>" 
                                               required minlength="3" maxlength="50" pattern="[a-zA-Z0-9_]+">
                                        <?php if (isset($errors['login'])): ?>
                                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['login']); ?></div>
                                        <?php endif; ?>
                                        <div class="form-text">Только буквы, цифры и подчеркивания. Минимум 3 символа.</div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="role" class="form-label">Роль *</label>
                                        <select class="form-select <?php echo isset($errors['role']) ? 'is-invalid' : ''; ?>" 
                                                id="role" name="role" required>
                                            <option value="operator" <?php echo ($_POST['role'] ?? '') === 'operator' ? 'selected' : ''; ?>>Оператор</option>
                                            <option value="engineer" <?php echo ($_POST['role'] ?? '') === 'engineer' ? 'selected' : ''; ?>>Инженер</option>
                                            <option value="admin" <?php echo ($_POST['role'] ?? '') === 'admin' ? 'selected' : ''; ?>>Администратор</option>
                                        </select>
                                        <?php if (isset($errors['role'])): ?>
                                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['role']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="full_name" class="form-label">ФИО *</label>
                                <input type="text" class="form-control <?php echo isset($errors['full_name']) ? 'is-invalid' : ''; ?>" 
                                       id="full_name" name="full_name" 
                                       value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" 
                                       required maxlength="100">
                                <?php if (isset($errors['full_name'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['full_name']); ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="password" class="form-label">Пароль *</label>
                                        <input type="password" class="form-control <?php echo isset($errors['password']) ? 'is-invalid' : ''; ?>" 
                                               id="password" name="password" 
                                               required minlength="6">
                                        <?php if (isset($errors['password'])): ?>
                                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['password']); ?></div>
                                        <?php endif; ?>
                                        <div class="form-text">Минимум 6 символов</div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="password_confirm" class="form-label">Подтверждение пароля *</label>
                                        <input type="password" class="form-control <?php echo isset($errors['password_confirm']) ? 'is-invalid' : ''; ?>" 
                                               id="password_confirm" name="password_confirm" 
                                               required minlength="6">
                                        <?php if (isset($errors['password_confirm'])): ?>
                                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['password_confirm']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="d-grid gap-2 d-md-flex">
                                <button type="submit" class="btn btn-primary">Добавить пользователя</button>
                                <a href="list.php" class="btn btn-secondary">Отмена</a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Справка по ролям -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Описание ролей</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <h6 class="text-danger">Администратор</h6>
                                <ul class="text-muted small">
                                    <li>Полный доступ ко всем функциям</li>
                                    <li>Управление пользователями</li>
                                    <li>Просмотр журнала аудита</li>
                                    <li>Удаление любых записей</li>
                                </ul>
                            </div>
                            <div class="col-md-4">
                                <h6 class="text-warning">Инженер</h6>
                                <ul class="text-muted small">
                                    <li>Управление IP-адресами</li>
                                    <li>Управление клиентами и устройствами</li>
                                    <li>Привязка белых IP</li>
                                    <li>Просмотр всей информации</li>
                                </ul>
                            </div>
                            <div class="col-md-4">
                                <h6 class="text-info">Оператор</h6>
                                <ul class="text-muted small">
                                    <li>Просмотр информации</li>
                                    <li>Ограниченное редактирование IP</li>
                                    <li>Поиск информации для техподдержки</li>
                                    <li>Нет доступа к удалению</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Валидация паролей на клиенте
        document.getElementById('user-form').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const passwordConfirm = document.getElementById('password_confirm').value;
            
            if (password !== passwordConfirm) {
                e.preventDefault();
                alert('Пароли не совпадают. Пожалуйста, проверьте введенные данные.');
                document.getElementById('password_confirm').focus();
            }
        });

        // Показать/скрыть пароль
        function togglePasswordVisibility(fieldId) {
            const field = document.getElementById(fieldId);
            const type = field.getAttribute('type') === 'password' ? 'text' : 'password';
            field.setAttribute('type', type);
        }
    </script>
</body>
</html>