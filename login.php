<?php
session_start();
require_once 'includes/db_connect.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login']);
    $password = $_POST['password'];
    
    if (empty($login) || empty($password)) {
        $error = 'Логин и пароль обязательны для заполнения';
    } elseif (strlen($login) > 50) {
        $error = 'Логин слишком длинный';
    } else {
        $stmt = $conn->prepare("SELECT id, login, password_hash, role, full_name FROM users WHERE login = ?");
        
        if ($stmt === false) {
            $error = 'Ошибка подготовки запроса';
        } else {
            $stmt->bind_param("s", $login);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                
                if (password_verify($password, $user['password_hash'])) {
                    session_regenerate_id(true);
                    
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_login'] = $user['login'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['user_name'] = $user['full_name'];
                    $_SESSION['login_time'] = time();
                    
                    error_log("Successful login: " . $user['login'] . " from " . $_SERVER['REMOTE_ADDR']);
                    
                    header('Location: index.php');
                    exit();
                }
            }
            
            $error = 'Неверный логин или пароль';
            
            error_log("Failed login attempt for: " . $login . " from " . $_SERVER['REMOTE_ADDR']);
            
            $stmt->close();
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
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-md-6 col-lg-4">
                <div class="card shadow">
                    <div class="card-body p-4">
                        <div class="text-center mb-4">
                            <h2 class="card-title">Web-IPAM</h2>
                            <p class="text-muted">Вход в систему</p>
                        </div>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-danger">
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label for="login" class="form-label">Логин</label>
                                <input type="text" class="form-control" id="login" name="login" required 
                                       value="<?php echo isset($_POST['login']) ? htmlspecialchars($_POST['login']) : ''; ?>"
                                       maxlength="50">
                            </div>
                            
                            <div class="mb-3">
                                <label for="password" class="form-label">Пароль</label>
                                <input type="password" class="form-control" id="password" name="password" required
                                       minlength="1" maxlength="255">
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100">Войти</button>
                        </form>
                        
                        <div class="mt-4">
                            <h6>Тестовые пользователи:</h6>
                            <small class="text-muted">
                                <div>admin / admin123</div>
                                <div>engineer / admin123</div>
                                <div>operator / admin123</div>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>