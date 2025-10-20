<?php
// login.php
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/audit_system.php';

$error = '';

// Проверяем блокировку IP (ТОЛЬКО из security_blocks)
$ip_block = AuditSystem::isIpBlocked();
if ($ip_block['blocked']) {
    $error = "Доступ временно заблокирован. Попробуйте позже после " . date('H:i:s', strtotime($ip_block['until']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $login = trim($_POST['login']);
    $password = $_POST['password'];
    
    // Собираем информацию о клиенте ДО любой проверки
    $client_info = AuditSystem::getClientInfo();
    
    // Проверяем блокировку пользователя (ТОЛЬКО из users)
    if ($login) {
        $user_lock = AuditSystem::isUserLocked($login);
        if ($user_lock['locked']) {
            $error = 'Аккаунт временно заблокирован. Попробуйте позже после ' . date('H:i:s', strtotime($user_lock['until']));
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
                        $_SESSION['user_login'] = $user['login'];
                        $_SESSION['user_role'] = $user['role'];
                        $_SESSION['user_name'] = $user['full_name'];
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
    <title>Вход в систему IPAM</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        
        .login-container {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 400px;
        }
        
        h1 {
            text-align: center;
            color: #333;
            margin-bottom: 1.5rem;
        }
        
        .error {
            background-color: #ffebee;
            color: #c62828;
            padding: 0.75rem;
            border-radius: 4px;
            margin-bottom: 1rem;
            border: 1px solid #ffcdd2;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #555;
            font-weight: bold;
        }
        
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 1rem;
        }
        
        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #2196F3;
        }
        
        button {
            width: 100%;
            padding: 0.75rem;
            background-color: #2196F3;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 1rem;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        
        button:hover {
            background-color: #1976D2;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>Вход в систему</h1>
        
        <?php if ($error): ?>
            <div class="error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="login">Логин:</label>
                <input type="text" id="login" name="login" value="<?php echo isset($_POST['login']) ? htmlspecialchars($_POST['login']) : ''; ?>" required autofocus>
            </div>
            
            <div class="form-group">
                <label for="password">Пароль:</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit">Войти</button>
        </form>
    </div>
</body>
</html>