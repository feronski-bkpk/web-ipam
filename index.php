<?php
require_once 'includes/auth.php';
require_once 'includes/db_connect.php';
requireAuth();

try {
    // Статистика клиентов
    $clients_count = 0;
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM clients");
    $stmt->execute();
    $result = $stmt->get_result();
    $clients_count = $result->fetch_assoc()['total'];
    $stmt->close();
    
    // Статистика IP-адресов
    $active_ips_count = 0;
    $free_ips_count = 0;
    $white_ips_count = 0;
    $gray_ips_count = 0;
    $total_ips_count = 0;
    $reserved_ips_count = 0;
    
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
    
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM ip_addresses WHERE status = 'reserved'");
    $stmt->execute();
    $result = $stmt->get_result();
    $reserved_ips_count = $result->fetch_assoc()['total'];
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
    
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM ip_addresses");
    $stmt->execute();
    $result = $stmt->get_result();
    $total_ips_count = $result->fetch_assoc()['total'];
    $stmt->close();
    
    // Статистика подсетей
    $subnets_count = 0;
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM subnets");
    $stmt->execute();
    $result = $stmt->get_result();
    $subnets_count = $result->fetch_assoc()['total'];
    $stmt->close();
    
    // Статистика устройств
    $devices_count = 0;
    $devices_with_ip = 0;
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM devices");
    $stmt->execute();
    $result = $stmt->get_result();
    $devices_count = $result->fetch_assoc()['total'];
    $stmt->close();
    
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT device_id) as total FROM ip_addresses WHERE device_id IS NOT NULL");
    $stmt->execute();
    $result = $stmt->get_result();
    $devices_with_ip = $result->fetch_assoc()['total'];
    $stmt->close();
    
    // Расчет процентов использования
    $usage_percent = $total_ips_count > 0 ? round(($active_ips_count / $total_ips_count) * 100, 1) : 0;
    
    // Статистика использования по подсетям
    $subnet_usage = [];
    try {
        $subnet_stmt = $conn->prepare("
            SELECT 
                s.network_address,
                s.cidr_mask,
                s.description,
                COUNT(ip.id) as total_ips,
                SUM(CASE WHEN ip.status = 'active' THEN 1 ELSE 0 END) as active_ips,
                ROUND((SUM(CASE WHEN ip.status = 'active' THEN 1 ELSE 0 END) / COUNT(ip.id)) * 100, 1) as usage_percent
            FROM subnets s
            LEFT JOIN ip_addresses ip ON s.id = ip.subnet_id
            GROUP BY s.id, s.network_address, s.cidr_mask, s.description
            ORDER BY usage_percent DESC
            LIMIT 5
        ");
        $subnet_stmt->execute();
        $subnet_usage = $subnet_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $subnet_stmt->close();
    } catch (Exception $e) {
        error_log("Error fetching subnet usage: " . $e->getMessage());
        $subnet_usage = [];
    }
    
    // Топ клиентов по количеству устройств
    $top_clients = [];
    try {
        $clients_stmt = $conn->prepare("
            SELECT 
                c.full_name,
                c.contract_number,
                COUNT(d.id) as device_count
            FROM clients c
            LEFT JOIN devices d ON c.id = d.client_id
            GROUP BY c.id, c.full_name, c.contract_number
            HAVING device_count > 0
            ORDER BY device_count DESC
            LIMIT 5
        ");
        $clients_stmt->execute();
        $top_clients = $clients_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $clients_stmt->close();
    } catch (Exception $e) {
        error_log("Error fetching top clients: " . $e->getMessage());
        $top_clients = [];
    }
    
    // Получаем последние действия из аудита
    $recent_actions = [];
    try {
        $audit_stmt = $conn->prepare("
            SELECT al.description, al.created_at, u.full_name 
            FROM system_audit_log al 
            LEFT JOIN users u ON al.user_id = u.id 
            WHERE al.action_type IN ('create', 'update', 'delete', 'login')
            ORDER BY al.created_at DESC 
            LIMIT 5
        ");
        $audit_stmt->execute();
        $recent_actions = $audit_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $audit_stmt->close();
    } catch (Exception $e) {
        error_log("Error fetching recent actions: " . $e->getMessage());
        $recent_actions = [];
    }
    
} catch (Exception $e) {
    error_log("Error getting statistics: " . $e->getMessage());
    $clients_count = $active_ips_count = $free_ips_count = $subnets_count = 0;
    $white_ips_count = $gray_ips_count = $devices_count = $total_ips_count = 0;
    $usage_percent = 0;
    $recent_actions = [];
    $subnet_usage = [];
    $top_clients = [];
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Главная - Web-IPAM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        .stat-card {
            border: none;
            border-radius: 12px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            height: 100%;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        .stat-label {
            font-size: 0.9rem;
            color: #6c757d;
            font-weight: 500;
        }
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        .usage-progress {
            height: 8px;
            border-radius: 4px;
        }
        .recent-activity-item {
            border-left: 3px solid transparent;
            transition: all 0.2s ease;
        }
        .recent-activity-item:hover {
            border-left-color: #007bff;
            background-color: #f8f9fa;
        }
        .system-info-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: none;
        }
        .nav-brand {
            font-weight: 600;
            font-size: 1.25rem;
        }
        .usage-high { background: linear-gradient(135deg, #ff6b6b, #ee5a24); }
        .usage-medium { background: linear-gradient(135deg, #feca57, #ff9ff3); }
        .usage-low { background: linear-gradient(135deg, #48dbfb, #0abde3); }
        .usage-very-low { background: linear-gradient(135deg, #1dd1a1, #10ac84); }
        .progress-thin { height: 6px; }
        
        /* Новые стили для навигации и поиска */
        .main-nav {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            border-bottom: 3px solid #3498db;
            padding: 0.5rem 0;
        }
        .nav-link-custom {
            color: #ecf0f1 !important;
            padding: 0.75rem 1rem;
            border-radius: 6px;
            transition: all 0.3s ease;
            margin: 0 2px;
            font-weight: 500;
        }
        .nav-link-custom:hover {
            background-color: rgba(52, 152, 219, 0.2);
            color: #3498db !important;
            transform: translateY(-1px);
        }
        .nav-link-custom.active {
            background-color: #3498db;
            color: white !important;
            box-shadow: 0 2px 8px rgba(52, 152, 219, 0.3);
        }
        .search-container {
            max-width: 400px;
            position: relative;
        }
        .search-form {
            position: relative;
        }
        .user-info {
            color: #ecf0f1;
            font-size: 0.9rem;
        }
        .nav-divider {
            border-left: 1px solid rgba(255,255,255,0.2);
            height: 30px;
            margin: 0 1rem;
        }
    </style>
</head>
<body>
    <!-- Главная навигация -->
    <nav class="navbar navbar-expand-lg navbar-dark main-nav">
        <div class="container">
            <a class="navbar-brand nav-brand d-flex align-items-center" href="index.php">
                <i class="bi bi-hdd-network me-2"></i>
                Web-IPAM
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="mainNavbar">
                <!-- Основное меню -->
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link nav-link-custom active" href="index.php">
                            <i class="bi bi-speedometer2 me-1"></i>Дашборд
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link nav-link-custom" href="pages/ip-addresses/list.php">
                            <i class="bi bi-router me-1"></i>IP-адреса
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link nav-link-custom" href="pages/clients/list.php">
                            <i class="bi bi-people me-1"></i>Клиенты
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link nav-link-custom" href="pages/devices/list.php">
                            <i class="bi bi-hdd me-1"></i>Устройства
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link nav-link-custom" href="pages/subnets/list.php">
                            <i class="bi bi-diagram-3 me-1"></i>Подсети
                        </a>
                    </li>
                    <?php if (hasRole('admin')): ?>
                    <li class="nav-item">
                        <a class="nav-link nav-link-custom" href="pages/audit/list.php">
                            <i class="bi bi-clipboard-data me-1"></i>Аудит
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link nav-link-custom" href="pages/users/list.php">
                            <i class="bi bi-people-fill me-1"></i>Пользователи
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
                
                <!-- Поиск и пользователь -->
                <div class="d-flex align-items-center">
                    <!-- Поиск -->
                    <div class="search-container me-3">
                        <form method="GET" action="pages/search/global.php" class="search-form" id="searchForm">
                            <div class="input-group">
                                <input type="text" 
                                       name="q" 
                                       class="form-control" 
                                       placeholder="Поиск IP, клиента, устройства..." 
                                       id="searchInput"
                                       style="border-radius: 20px 0 0 20px;">
                                <button class="btn btn-outline-light" type="submit" style="border-radius: 0 20px 20px 0;">
                                    <i class="bi bi-search"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <div class="nav-divider"></div>
                    
                    <!-- Информация пользователя -->
                    <div class="user-info">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-person-circle me-2"></i>
                            <div>
                                <div class="fw-bold"><?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?></div>
                                <small class="text-light">
                                    <?php echo htmlspecialchars($_SESSION['user_role'] ?? ''); ?>
                                </small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="nav-divider"></div>
                    
                    <!-- Выход -->
                    <a href="logout.php" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-box-arrow-right me-1"></i>Выйти
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <!-- Заголовок и приветствие -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="h3 mb-1">Обзор системы</h1>
                        <p class="text-muted mb-0">Добро пожаловать в систему управления IP-адресами</p>
                    </div>
                    <div class="text-end">
                        <small class="text-muted">Текущее время</small>
                        <div class="fw-bold" id="current-time"><?php echo date('d.m.Y H:i:s'); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Основная статистика -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card stat-card border-start border-primary border-4">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <div class="stat-number text-primary"><?php echo htmlspecialchars($clients_count); ?></div>
                                <div class="stat-label">Клиентов</div>
                            </div>
                            <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                                <i class="bi bi-people"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card stat-card border-start border-success border-4">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <div class="stat-number text-success"><?php echo htmlspecialchars($active_ips_count); ?></div>
                                <div class="stat-label">Активных IP</div>
                            </div>
                            <div class="stat-icon bg-success bg-opacity-10 text-success">
                                <i class="bi bi-router"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card stat-card border-start border-info border-4">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <div class="stat-number text-info"><?php echo htmlspecialchars($free_ips_count); ?></div>
                                <div class="stat-label">Свободных IP</div>
                            </div>
                            <div class="stat-icon bg-info bg-opacity-10 text-info">
                                <i class="bi bi-circle"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card stat-card border-start border-warning border-4">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <div class="stat-number text-warning"><?php echo htmlspecialchars($subnets_count); ?></div>
                                <div class="stat-label">Подсетей</div>
                            </div>
                            <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                                <i class="bi bi-diagram-3"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Детальная статистика и использование -->
        <div class="row mb-4">
            <div class="col-md-8">
                <div class="card stat-card">
                    <div class="card-header bg-transparent">
                        <h5 class="card-title mb-0">Использование IP-адресов</h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="text-muted">Общее использование:</span>
                                    <strong><?php echo htmlspecialchars($usage_percent); ?>%</strong>
                                </div>
                                <div class="progress usage-progress mb-3">
                                    <div class="progress-bar 
                                        <?php echo $usage_percent > 80 ? 'bg-danger' : 
                                              ($usage_percent > 60 ? 'bg-warning' : 'bg-success'); ?>" 
                                         style="width: <?php echo $usage_percent; ?>%">
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex justify-content-between mb-1">
                                    <small class="text-muted">Активные:</small>
                                    <small class="fw-bold"><?php echo htmlspecialchars($active_ips_count); ?></small>
                                </div>
                                <div class="d-flex justify-content-between mb-1">
                                    <small class="text-muted">Свободные:</small>
                                    <small class="fw-bold"><?php echo htmlspecialchars($free_ips_count); ?></small>
                                </div>
                                <div class="d-flex justify-content-between mb-1">
                                    <small class="text-muted">Зарезервированные:</small>
                                    <small class="fw-bold"><?php echo htmlspecialchars($reserved_ips_count); ?></small>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <small class="text-muted">Всего:</small>
                                    <small class="fw-bold"><?php echo htmlspecialchars($total_ips_count); ?></small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card bg-light border-0">
                                    <div class="card-body py-2">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-muted">Белые IP:</span>
                                            <span class="badge bg-warning"><?php echo htmlspecialchars($white_ips_count); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card bg-light border-0">
                                    <div class="card-body py-2">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-muted">Серые IP:</span>
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($gray_ips_count); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card stat-card">
                    <div class="card-header bg-transparent">
                        <h5 class="card-title mb-0">Устройства</h5>
                    </div>
                    <div class="card-body text-center">
                        <div class="stat-number text-dark mb-2"><?php echo htmlspecialchars($devices_count); ?></div>
                        <div class="stat-label">Всего устройств</div>
                        <div class="mt-3">
                            <small class="text-muted">С IP-адресами: <?php echo htmlspecialchars($devices_with_ip); ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Аналитика и топы -->
        <div class="row mb-4">
            <!-- Топ подсетей по использованию -->
            <div class="col-md-6">
                <div class="card stat-card">
                    <div class="card-header bg-transparent">
                        <h5 class="card-title mb-0">Топ подсетей по использованию</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($subnet_usage)): ?>
                            <p class="text-muted">Нет данных о подсетях</p>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($subnet_usage as $subnet): 
                                    $usage_class = '';
                                    if ($subnet['usage_percent'] > 80) $usage_class = 'usage-high';
                                    elseif ($subnet['usage_percent'] > 60) $usage_class = 'usage-medium';
                                    elseif ($subnet['usage_percent'] > 30) $usage_class = 'usage-low';
                                    else $usage_class = 'usage-very-low';
                                ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                        <div>
                                            <strong><code><?php echo htmlspecialchars($subnet['network_address'] ?? ''); ?>/<?php echo htmlspecialchars($subnet['cidr_mask'] ?? ''); ?></code></strong>
                                            <br>
                                            <small class="text-muted"><?php echo htmlspecialchars(($subnet['description'] ?? '') ?: 'Без описания'); ?></small>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge bg-primary"><?php echo htmlspecialchars($subnet['active_ips'] ?? 0); ?>/<?php echo htmlspecialchars($subnet['total_ips'] ?? 0); ?></span>
                                            <br>
                                            <div class="progress progress-thin mt-1" style="width: 80px;">
                                                <div class="progress-bar <?php echo $usage_class; ?>" 
                                                     style="width: <?php echo htmlspecialchars($subnet['usage_percent'] ?? 0); ?>%">
                                                </div>
                                            </div>
                                            <small class="text-muted"><?php echo htmlspecialchars($subnet['usage_percent'] ?? 0); ?>%</small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Топ клиентов по устройствам -->
            <div class="col-md-6">
                <div class="card stat-card">
                    <div class="card-header bg-transparent">
                        <h5 class="card-title mb-0">Топ клиентов по устройствам</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($top_clients)): ?>
                            <p class="text-muted">Нет данных о клиентах</p>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($top_clients as $client): ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                        <div>
                                            <strong><?php echo htmlspecialchars($client['full_name'] ?? ''); ?></strong>
                                            <br>
                                            <small class="text-muted"><?php echo htmlspecialchars($client['contract_number'] ?? ''); ?></small>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge bg-success"><?php echo htmlspecialchars($client['device_count'] ?? 0); ?> уст.</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Быстрый доступ и последние действия -->
        <div class="row">
            <div class="col-md-6 mb-4">
                <div class="card stat-card">
                    <div class="card-header bg-transparent">
                        <h5 class="card-title mb-0">Быстрый доступ</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-6">
                                <a href="pages/ip-addresses/list.php" class="btn btn-outline-primary w-100 d-flex align-items-center justify-content-center py-2">
                                    <span class="me-2"></span>
                                    <span>IP-адреса</span>
                                </a>
                            </div>
                            <div class="col-6">
                                <a href="pages/clients/list.php" class="btn btn-outline-success w-100 d-flex align-items-center justify-content-center py-2">
                                    <span class="me-2"></span>
                                    <span>Клиенты</span>
                                </a>
                            </div>
                            <div class="col-6">
                                <a href="pages/devices/list.php" class="btn btn-outline-warning w-100 d-flex align-items-center justify-content-center py-2">
                                    <span class="me-2"></span>
                                    <span>Устройства</span>
                                </a>
                            </div>
                            <div class="col-6">
                                <a href="pages/subnets/list.php" class="btn btn-outline-info w-100 d-flex align-items-center justify-content-center py-2">
                                    <span class="me-2"></span>
                                    <span>Подсети</span>
                                </a>
                            </div>
                            <?php if (hasRole('admin')): ?>
                            <div class="col-6">
                                <a href="pages/audit/list.php" class="btn btn-outline-dark w-100 d-flex align-items-center justify-content-center py-2">
                                    <span class="me-2"></span>
                                    <span>Аудит</span>
                                </a>
                            </div>
                            <div class="col-6">
                                <a href="pages/users/list.php" class="btn btn-outline-danger w-100 d-flex align-items-center justify-content-center py-2">
                                    <span class="me-2"></span>
                                    <span>Пользователи</span>
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6 mb-4">
                <div class="card stat-card">
                    <div class="card-header bg-transparent">
                        <h5 class="card-title mb-0">Последние действия</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_actions)): ?>
                            <div class="text-center py-3">
                                <p class="text-muted mb-0">Нет записей о последних действиях</p>
                            </div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($recent_actions as $action): ?>
                                    <div class="list-group-item recent-activity-item px-0 py-2 border-0">
                                        <div class="d-flex w-100 justify-content-between align-items-start">
                                            <div class="flex-grow-1">
                                                <p class="mb-1 small"><?php echo htmlspecialchars($action['description'] ?? ''); ?></p>
                                                <small class="text-muted">
                                                    <?php if ($action['full_name']): ?>
                                                        <?php echo htmlspecialchars($action['full_name'] ?? ''); ?> • 
                                                    <?php endif; ?>
                                                    <?php echo date('d.m.Y H:i', strtotime($action['created_at'] ?? '')); ?>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Информация о системе -->
        <div class="row">
            <div class="col-12">
                <div class="card system-info-card">
                    <div class="card-header bg-transparent">
                        <h5 class="card-title mb-0">Информация о системе</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-2">
                                    <strong>Текущий пользователь:</strong> 
                                    <span class="float-end"><?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?></span>
                                </div>
                                <div class="mb-2">
                                    <strong>Роль:</strong> 
                                    <span class="float-end badge bg-<?php 
                                        echo ($_SESSION['user_role'] ?? 'user') === 'admin' ? 'danger' : 
                                             (($_SESSION['user_role'] ?? 'user') === 'engineer' ? 'warning' : 'info'); 
                                    ?>"><?php echo htmlspecialchars($_SESSION['user_role'] ?? ''); ?></span>
                                </div>
                                <div class="mb-2">
                                    <strong>Логин:</strong> 
                                    <span class="float-end"><?php echo htmlspecialchars($_SESSION['user_login'] ?? ''); ?></span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-2">
                                    <strong>Время входа:</strong> 
                                    <span class="float-end"><?php echo date('d.m.Y H:i:s', $_SESSION['login_time'] ?? time()); ?></span>
                                </div>
                                <div class="mb-2">
                                    <strong>Версия PHP:</strong> 
                                    <span class="float-end"><?php echo phpversion(); ?></span>
                                </div>
                                <div class="mb-2">
                                    <strong>Сессия активна:</strong> 
                                    <span class="float-end badge bg-success">Да</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Обновление времени
        function updateCurrentTime() {
            const now = new Date();
            const options = { 
                day: '2-digit', 
                month: '2-digit', 
                year: 'numeric',
                hour: '2-digit', 
                minute: '2-digit', 
                second: '2-digit',
                hour12: false
            };
            const timeString = now.toLocaleDateString('ru-RU', options);
            document.getElementById('current-time').textContent = timeString;
        }

        // Обновляем время каждую секунду
        setInterval(updateCurrentTime, 1000);
        
        // Инициализация при загрузке
        document.addEventListener('DOMContentLoaded', function() {
            updateCurrentTime();
        });
    </script>
</body>
</html>