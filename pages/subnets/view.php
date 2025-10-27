<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
require_once '../../includes/audit_system.php';
requireAuth();

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
    $subnet = $subnet_stmt->get_result()->fetch_assoc();
    $subnet_stmt->close();
    
    if (!$subnet) {
        header('Location: list.php');
        exit();
    }
} catch (Exception $e) {
    error_log("Error fetching subnet data: " . $e->getMessage());
    header('Location: list.php');
    exit();
}

// Получаем IP-адреса в подсети
try {
    $ips_stmt = $conn->prepare("
        SELECT 
            ip.*,
            d.mac_address, d.model,
            c.full_name as client_name
        FROM ip_addresses ip
        LEFT JOIN devices d ON ip.device_id = d.id
        LEFT JOIN clients c ON d.client_id = c.id
        WHERE ip.subnet_id = ?
        ORDER BY INET_ATON(ip.ip_address)
    ");
    $ips_stmt->bind_param("i", $subnet_id);
    $ips_stmt->execute();
    $ip_addresses = $ips_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $ips_stmt->close();
} catch (Exception $e) {
    error_log("Error fetching IP addresses: " . $e->getMessage());
    $ip_addresses = [];
}

// Статистика
$stats = [
    'total' => count($ip_addresses),
    'active' => count(array_filter($ip_addresses, fn($ip) => $ip['status'] === 'active')),
    'free' => count(array_filter($ip_addresses, fn($ip) => $ip['status'] === 'free')),
    'reserved' => count(array_filter($ip_addresses, fn($ip) => $ip['status'] === 'reserved')),
    'white' => count(array_filter($ip_addresses, fn($ip) => $ip['type'] === 'white')),
    'gray' => count(array_filter($ip_addresses, fn($ip) => $ip['type'] === 'gray'))
];

$usage_percent = $stats['total'] > 0 ? round(($stats['active'] / $stats['total']) * 100, 1) : 0;
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Просмотр подсети - Web-IPAM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .ip-status-active { background-color: #d1e7dd; }
        .ip-status-free { background-color: #e2e3e5; }
        .ip-status-reserved { background-color: #fff3cd; }
        .ip-table td { vertical-align: middle; }
    </style>
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    
    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="../../index.php">Главная</a></li>
                        <li class="breadcrumb-item"><a href="list.php">Подсети</a></li>
                        <li class="breadcrumb-item active">Просмотр подсети</li>
                    </ol>
                </nav>

                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1>Просмотр подсети: <?php echo htmlspecialchars($subnet['network_address']); ?>/<?php echo $subnet['cidr_mask']; ?></h1>
                    <a href="list.php" class="btn btn-outline-secondary">Назад к списку</a>
                </div>

                <!-- Информация о подсети -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Основная информация</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm">
                                    <tr>
                                        <td><strong>Подсеть:</strong></td>
                                        <td><code><?php echo htmlspecialchars($subnet['network_address']); ?>/<?php echo $subnet['cidr_mask']; ?></code></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Шлюз:</strong></td>
                                        <td>
                                            <?php if ($subnet['gateway']): ?>
                                                <code><?php echo htmlspecialchars($subnet['gateway']); ?></code>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>Описание:</strong></td>
                                        <td><?php echo $subnet['description'] ? htmlspecialchars($subnet['description']) : '<span class="text-muted">—</span>'; ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Создана:</strong></td>
                                        <td><?php echo date('d.m.Y H:i', strtotime($subnet['created_at'])); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Статистика использования</h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span>Использование: <?php echo $usage_percent; ?>%</span>
                                        <span><?php echo $stats['active']; ?>/<?php echo $stats['total']; ?></span>
                                    </div>
                                    <div class="progress" style="height: 20px;">
                                        <div class="progress-bar 
                                            <?php echo $usage_percent > 80 ? 'bg-danger' : 
                                                  ($usage_percent > 60 ? 'bg-warning' : 'bg-success'); ?>" 
                                             style="width: <?php echo $usage_percent; ?>%">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row text-center">
                                    <div class="col-4">
                                        <small class="text-muted">Активные</small>
                                        <div class="h5 text-success"><?php echo $stats['active']; ?></div>
                                    </div>
                                    <div class="col-4">
                                        <small class="text-muted">Свободные</small>
                                        <div class="h5 text-info"><?php echo $stats['free']; ?></div>
                                    </div>
                                    <div class="col-4">
                                        <small class="text-muted">Резерв</small>
                                        <div class="h5 text-warning"><?php echo $stats['reserved']; ?></div>
                                    </div>
                                </div>
                                
                                <div class="row text-center mt-2">
                                    <div class="col-6">
                                        <small class="text-muted">Белые IP</small>
                                        <div class="h5 text-warning"><?php echo $stats['white']; ?></div>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted">Серые IP</small>
                                        <div class="h5 text-secondary"><?php echo $stats['gray']; ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Таблица IP-адресов -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">IP-адреса в подсети</h5>
                        <a href="../ip-addresses/add.php?subnet_id=<?php echo $subnet_id; ?>" class="btn btn-primary btn-sm">Добавить IP</a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($ip_addresses)): ?>
                            <div class="text-center py-4">
                                <p class="text-muted">В подсети нет IP-адресов</p>
                                <a href="../ip-addresses/add.php?subnet_id=<?php echo $subnet_id; ?>" class="btn btn-primary">Добавить первый IP-адрес</a>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm ip-table">
                                    <thead>
                                        <tr>
                                            <th>IP-адрес</th>
                                            <th>Тип</th>
                                            <th>Статус</th>
                                            <th>Устройство</th>
                                            <th>Клиент</th>
                                            <th>Описание</th>
                                            <th>Действия</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($ip_addresses as $ip): 
                                            $status_class = 'ip-status-' . $ip['status'];
                                        ?>
                                            <tr class="<?php echo $status_class; ?>">
                                                <td>
                                                    <code><?php echo htmlspecialchars($ip['ip_address']); ?></code>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $ip['type'] === 'white' ? 'warning' : 'secondary'; ?>">
                                                        <?php echo $ip['type'] === 'white' ? 'Белый' : 'Серый'; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        echo $ip['status'] === 'active' ? 'success' : 
                                                             ($ip['status'] === 'reserved' ? 'warning' : 'info'); 
                                                    ?>">
                                                        <?php echo $ip['status'] === 'active' ? 'Активен' : 
                                                               ($ip['status'] === 'reserved' ? 'Резерв' : 'Свободен'); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($ip['mac_address']): ?>
                                                        <code><?php echo htmlspecialchars($ip['mac_address']); ?></code>
                                                        <?php if ($ip['model']): ?>
                                                            <br><small class="text-muted"><?php echo htmlspecialchars($ip['model']); ?></small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo $ip['client_name'] ? htmlspecialchars($ip['client_name']) : '<span class="text-muted">—</span>'; ?>
                                                </td>
                                                <td>
                                                    <?php echo $ip['description'] ? htmlspecialchars($ip['description']) : '<span class="text-muted">—</span>'; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="../ip-addresses/edit.php?id=<?php echo $ip['id']; ?>" 
                                                           class="btn btn-outline-primary" title="Редактировать IP">✏️</a>
                                                        <?php if (hasRole('admin')): ?>
                                                            <a href="../ip-addresses/delete.php?id=<?php echo $ip['id']; ?>" 
                                                               class="btn btn-outline-danger" 
                                                               onclick="return confirm('Удалить IP-адрес <?php echo htmlspecialchars($ip['ip_address']); ?>?')"
                                                               title="Удалить IP">🗑️</a>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>