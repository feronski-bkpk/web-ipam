<?php
require_once 'includes/auth.php';
require_once 'includes/db_connect.php';
requireAuth();

try {
    $clients_count = 0;
    $active_ips_count = 0;
    $free_ips_count = 0;
    $subnets_count = 0;
    $white_ips_count = 0;
    $gray_ips_count = 0;
    
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM clients");
    $stmt->execute();
    $result = $stmt->get_result();
    $clients_count = $result->fetch_assoc()['total'];
    $stmt->close();
    
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM ip_addresses WHERE status = 'active'");
    $stmt->execute();
    $result = $stmt->get_result();
    $active_ips_count = $result->fetch_assoc()['total'];
    $stmt->close();
    
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM ip_addresses WHERE status = 'free'");
    $stmt->execute();
    $result = $stmt->get_result();
    $free_ips_count = $result->fetch_assoc()['total'];
    $stmt->close();
    
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM subnets");
    $stmt->execute();
    $result = $stmt->get_result();
    $subnets_count = $result->fetch_assoc()['total'];
    $stmt->close();
    
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM ip_addresses WHERE type = 'white' AND status = 'active'");
    $stmt->execute();
    $result = $stmt->get_result();
    $white_ips_count = $result->fetch_assoc()['total'];
    $stmt->close();
    
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM ip_addresses WHERE type = 'gray' AND status = 'active'");
    $stmt->execute();
    $result = $stmt->get_result();
    $gray_ips_count = $result->fetch_assoc()['total'];
    $stmt->close();
    
} catch (Exception $e) {
    error_log("Error getting statistics: " . $e->getMessage());
    $clients_count = $active_ips_count = $free_ips_count = $subnets_count = $white_ips_count = $gray_ips_count = 0;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Главная - Web-IPAM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="index.php">🌐 Web-IPAM</a>
            
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">
                    <?php echo htmlspecialchars($_SESSION['user_name']); ?>
                    <small class="text-muted">(<?php echo htmlspecialchars($_SESSION['user_role']); ?>)</small>
                </span>
                <a class="nav-link" href="logout.php">Выйти</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <h1>Добро пожаловать в Web-IPAM!</h1>
                <p class="lead">Система управления IP-адресным пространством</p>
            </div>
        </div>

        <!-- Основная статистика -->
        <div class="row mt-4">
            <div class="col-md-3 mb-3">
                <div class="card text-white bg-primary">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="card-title"><?php echo htmlspecialchars($clients_count); ?></h4>
                                <p class="card-text">Клиентов</p>
                            </div>
                            <div class="align-self-center">
                                <span style="font-size: 2rem;">👥</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card text-white bg-success">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="card-title"><?php echo htmlspecialchars($active_ips_count); ?></h4>
                                <p class="card-text">Активных IP</p>
                            </div>
                            <div class="align-self-center">
                                <span style="font-size: 2rem;">🔗</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card text-white bg-warning">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="card-title"><?php echo htmlspecialchars($free_ips_count); ?></h4>
                                <p class="card-text">Свободных IP</p>
                            </div>
                            <div class="align-self-center">
                                <span style="font-size: 2rem;">🔄</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card text-white bg-info">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="card-title"><?php echo htmlspecialchars($subnets_count); ?></h4>
                                <p class="card-text">Подсетей</p>
                            </div>
                            <div class="align-self-center">
                                <span style="font-size: 2rem;">🌐</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Дополнительная статистика по типам IP -->
        <div class="row mt-3">
            <div class="col-md-6 mb-3">
                <div class="card text-white bg-secondary">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="card-title"><?php echo htmlspecialchars($white_ips_count); ?></h4>
                                <p class="card-text">Белых IP</p>
                            </div>
                            <div class="align-self-center">
                                <span style="font-size: 2rem;">⚪</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6 mb-3">
                <div class="card text-white bg-dark">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="card-title"><?php echo htmlspecialchars($gray_ips_count); ?></h4>
                                <p class="card-text">Серых IP</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Быстрое меню -->
<div class="row mt-5">
    <div class="col-12">
        <h3>Быстрый доступ</h3>
        <div class="d-grid gap-2 d-md-flex">
            <a href="pages/ip-addresses/list.php" class="btn btn-outline-primary me-2 mb-2">
                📡 IP-адреса
            </a>
            <a href="pages/clients/list.php" class="btn btn-outline-success me-2 mb-2">
                👥 Клиенты
            </a>
            <a href="pages/devices/list.php" class="btn btn-outline-warning me-2 mb-2">
                🖧 Устройства
            </a>
            <a href="pages/subnets/list.php" class="btn btn-outline-info me-2 mb-2">
                🌐 Подсети
            </a>
            <?php if (hasRole('admin')): ?>
                <a href="pages/audit/list.php" class="btn btn-outline-dark me-2 mb-2">
                    📋 Аудит
                </a>
                <a href="pages/users/list.php" class="btn btn-outline-danger mb-2">
                    👤 Пользователи
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

        <div class="row mt-5">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Последние действия</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">Здесь будет отображаться история изменений системы.</p>
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item">
                                <small class="text-muted">Сегодня, 14:30</small><br>
                                Пользователь <strong><?php echo htmlspecialchars($_SESSION['user_name']); ?></strong> вошел в систему
                            </li>
                            <li class="list-group-item">
                                <small class="text-muted">Вчера, 16:45</small><br>
                                Добавлен новый клиент: <strong>Иванов Алексей Петрович</strong>
                            </li>
                            <li class="list-group-item">
                                <small class="text-muted">Вчера, 15:20</small><br>
                                Выделен белый IP <code>95.165.150.5</code> для клиента Козлов Д.И.
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Информация о системе -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Информация о системе</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Текущий пользователь:</strong> <?php echo htmlspecialchars($_SESSION['user_name']); ?><br>
                                <strong>Роль:</strong> <span class="badge bg-<?php 
                                    echo $_SESSION['user_role'] === 'admin' ? 'danger' : 
                                         ($_SESSION['user_role'] === 'engineer' ? 'warning' : 'info'); 
                                ?>"><?php echo htmlspecialchars($_SESSION['user_role']); ?></span><br>
                                <strong>Логин:</strong> <?php echo htmlspecialchars($_SESSION['user_login']); ?>
                            </div>
                            <div class="col-md-6">
                                <strong>Время входа:</strong> <?php echo date('d.m.Y H:i:s', $_SESSION['login_time']); ?><br>
                                <strong>Текущее время:</strong> <?php echo date('d.m.Y H:i:s'); ?><br>
                                <strong>Версия PHP:</strong> <?php echo phpversion(); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Кастомный JS -->
    <script src="assets/js/main.js"></script>
</body>
</html>