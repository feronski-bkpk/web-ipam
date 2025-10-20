<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
requireAuth();

// Получаем расширенную статистику
try {
    // Статистика по подсетям
    $subnets_stats = $conn->query("
        SELECT 
            s.network_address, 
            s.cidr_mask,
            s.description,
            COUNT(ip.id) as total_ips,
            SUM(CASE WHEN ip.status = 'active' THEN 1 ELSE 0 END) as active_ips,
            SUM(CASE WHEN ip.status = 'free' THEN 1 ELSE 0 END) as free_ips,
            SUM(CASE WHEN ip.type = 'white' THEN 1 ELSE 0 END) as white_ips,
            SUM(CASE WHEN ip.type = 'gray' THEN 1 ELSE 0 END) as gray_ips
        FROM subnets s
        LEFT JOIN ip_addresses ip ON s.id = ip.subnet_id
        GROUP BY s.id, s.network_address, s.cidr_mask, s.description
        ORDER BY s.network_address
    ")->fetch_all(MYSQLI_ASSOC);

    // Топ клиентов по количеству устройств
    $top_clients = $conn->query("
        SELECT 
            c.full_name,
            c.contract_number,
            COUNT(d.id) as device_count
        FROM clients c
        LEFT JOIN devices d ON c.id = d.client_id
        GROUP BY c.id, c.full_name, c.contract_number
        HAVING device_count > 0
        ORDER BY device_count DESC
        LIMIT 10
    ")->fetch_all(MYSQLI_ASSOC);

    // Распределение IP по типам
    $ip_distribution = $conn->query("
        SELECT 
            type,
            status,
            COUNT(*) as count
        FROM ip_addresses 
        GROUP BY type, status
        ORDER BY type, status
    ")->fetch_all(MYSQLI_ASSOC);

} catch (Exception $e) {
    error_log("Error fetching reports: " . $e->getMessage());
    $subnets_stats = $top_clients = $ip_distribution = [];
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Отчеты - Web-IPAM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    
    <div class="container mt-4">
        <h1>Аналитика и отчеты</h1>

        <!-- Статистика по подсетям -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5>Использование подсетей</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Подсеть</th>
                                        <th>Описание</th>
                                        <th>Всего IP</th>
                                        <th>Активных</th>
                                        <th>Свободных</th>
                                        <th>Белых</th>
                                        <th>Серых</th>
                                        <th>Загрузка</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($subnets_stats as $subnet): 
                                        $total = $subnet['total_ips'];
                                        $active = $subnet['active_ips'];
                                        $usage = $total > 0 ? round(($active / $total) * 100, 1) : 0;
                                    ?>
                                        <tr>
                                            <td><code><?php echo $subnet['network_address']; ?>/<?php echo $subnet['cidr_mask']; ?></code></td>
                                            <td><?php echo htmlspecialchars($subnet['description']); ?></td>
                                            <td><?php echo $total; ?></td>
                                            <td><span class="badge bg-success"><?php echo $active; ?></span></td>
                                            <td><span class="badge bg-info"><?php echo $subnet['free_ips']; ?></span></td>
                                            <td><span class="badge bg-warning"><?php echo $subnet['white_ips']; ?></span></td>
                                            <td><span class="badge bg-secondary"><?php echo $subnet['gray_ips']; ?></span></td>
                                            <td>
                                                <div class="progress" style="height: 20px;">
                                                    <div class="progress-bar <?php echo $usage > 80 ? 'bg-danger' : ($usage > 60 ? 'bg-warning' : 'bg-success'); ?>" 
                                                         style="width: <?php echo $usage; ?>%">
                                                        <?php echo $usage; ?>%
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Другие отчеты -->
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5>Топ клиентов по устройствам</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($top_clients)): ?>
                            <p class="text-muted">Нет данных</p>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($top_clients as $client): ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?php echo htmlspecialchars($client['full_name']); ?></strong>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($client['contract_number']); ?></small>
                                        </div>
                                        <span class="badge bg-primary rounded-pill"><?php echo $client['device_count']; ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5>Распределение IP-адресов</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($ip_distribution)): ?>
                            <p class="text-muted">Нет данных</p>
                        <?php else: ?>
                            <table class="table table-sm">
                                <?php foreach ($ip_distribution as $dist): ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-<?php echo $dist['type'] === 'white' ? 'warning' : 'secondary'; ?>">
                                                <?php echo $dist['type'] === 'white' ? 'Белый' : 'Серый'; ?>
                                            </span>
                                            <span class="badge bg-<?php 
                                                echo $dist['status'] === 'active' ? 'success' : 
                                                     ($dist['status'] === 'reserved' ? 'warning' : 'info'); 
                                            ?> ms-1">
                                                <?php echo $dist['status'] === 'active' ? 'Активен' : 
                                                       ($dist['status'] === 'reserved' ? 'Резерв' : 'Свободен'); ?>
                                            </span>
                                        </td>
                                        <td class="text-end"><strong><?php echo $dist['count']; ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>