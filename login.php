<?php
// login.php
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/audit_system.php';

$error = '';

// Проверяем блокировку IP (ТОЛЬКО из security_blocks)
$ip_block = AuditSystem::isIpBlocked();
if ($ip_block['blocked']) {
    $error = "Доступ временно заблокирован. Попробуйте позже после " . date('H:i:s', strtotime($ip_block['until'] ?? ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // Собираем информацию о клиенте ДО любой проверки
    $client_info = AuditSystem::getClientInfo();
    
    // Проверяем блокировку пользователя (ТОЛЬКО из users)
    if ($login) {
        $user_lock = AuditSystem::isUserLocked($login);
        if ($user_lock['locked']) {
            $error = 'Аккаунт временно заблокирован. Попробуйте позже после ' . date('H:i:s', strtotime($user_lock['until'] ?? ''));
        }
    }
    
    if (!$error) {
        if (empty($login) || empty($password)) {
            $error = 'Логин и пароль обязательны для заполнения';
            AuditSystem::registerFailedLogin($login, "Пустые учетные данные");
            
        } elseif (strlen($login) > 50) {
            $error = 'Логин слишком длинный';
            AuditSystem::registerFailedLogin($login, "Слишком длинный логин");
            
        } else {
            $stmt = $conn->prepare("SELECT id, login, password_hash, role, full_name FROM users WHERE login = ?");
            
            if ($stmt === false) {
                $error = 'Ошибка подготовки запроса';
                AuditSystem::registerFailedLogin($login, "Ошибка базы данных");
                
            } else {
                $stmt->bind_param("s", $login);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows === 1) {
                    $user = $result->fetch_assoc();
                    
                    if (password_verify($password, $user['password_hash'])) {
                        // УСПЕШНЫЙ ВХОД
                        session_regenerate_id(true);
                        
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_login'] = $user['login'] ?? '';
                        $_SESSION['user_role'] = $user['role'] ?? 'user';
                        $_SESSION['user_name'] = $user['full_name'] ?? '';
                        $_SESSION['login_time'] = time();
                        
                        // Сбрасываем счетчик неудачных попыток
                        AuditSystem::resetFailedAttempts($user['id']);
                        AuditSystem::logLogin($user['id'], $user['login'], $client_info);
                        
                        header('Location: index.php');
                        exit();
                    } else {
                        // НЕВЕРНЫЙ ПАРОЛЬ
                        $error = 'Неверный логин или пароль';
                        AuditSystem::registerFailedLogin($login, "Неверный пароль");
                    }
                } else {
                    // ПОЛЬЗОВАТЕЛЬ НЕ НАЙДЕН
                    $error = 'Неверный логин или пароль';
                    AuditSystem::registerFailedLogin($login, "Пользователь не существует");
                }
                
                $stmt->close();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход в систему - Web-IPAM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        .login-container {
            min-height: 100vh;
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }
        
        .login-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            border: none;
            width: 100%;
            max-width: 400px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .login-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 40px rgba(0,0,0,0.15);
        }
        
        .login-header {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white;
            border-radius: 12px 12px 0 0;
            padding: 2rem;
            text-align: center;
        }
        
        .login-body {
            padding: 2rem;
        }
        
        .form-control {
            border-radius: 6px;
            padding: 0.75rem;
            border: 1px solid #ddd;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }
        
        .btn-login {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            border: none;
            border-radius: 6px;
            padding: 0.75rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-login:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(52, 152, 219, 0.3);
        }
        
        .error-alert {
            border-radius: 6px;
            border: 1px solid #ffcdd2;
        }
        
        .brand-text {
            font-weight: 600;
            font-size: 1.25rem;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="d-flex align-items-center justify-content-center mb-3">
                    <i class="bi bi-hdd-network me-2" style="font-size: 2rem;"></i>
                    <span class="brand-text">Web-IPAM</span>
                </div>
                <h1 class="h4 mb-0">Вход в систему</h1>
            </div>
            
            <div class="login-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger error-alert d-flex align-items-center" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="mb-3">
                        <label for="login" class="form-label">Логин</label>
                        <input type="text" 
                               id="login" 
                               name="login" 
                               class="form-control" 
                               value="<?php echo isset($_POST['login']) ? htmlspecialchars($_POST['login'] ?? '') : ''; ?>" 
                               required 
                               autofocus
                               placeholder="Введите ваш логин">
                    </div>
                    
                    <div class="mb-4">
                        <label for="password" class="form-label">Пароль</label>
                        <input type="password" 
                               id="password" 
                               name="password" 
                               class="form-control" 
                               required
                               placeholder="Введите ваш пароль">
                    </div>
                    
                    <button type="submit" class="btn btn-login w-100 text-white">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Войти в систему
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>