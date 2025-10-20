<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
require_once '../../includes/audit_system.php';
requireAuth();

$search_query = trim($_GET['q'] ?? '');
$results = [];

if (!empty($search_query)) {
    try {
        // Поиск по клиентам
        $clients_stmt = $conn->prepare("
            SELECT 
                'client' as type,
                id,
                full_name as title,
                contract_number as subtitle,
                address as description,
                created_at
            FROM clients 
            WHERE full_name LIKE ? OR contract_number LIKE ? OR address LIKE ? OR phone LIKE ?
            ORDER BY full_name
            LIMIT 10
        ");
        $search_param = "%$search_query%";
        $clients_stmt->bind_param("ssss", $search_param, $search_param, $search_param, $search_param);
        $clients_stmt->execute();
        $clients_results = $clients_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $clients_stmt->close();

        // Поиск по устройствам
        $devices_stmt = $conn->prepare("
            SELECT 
                'device' as type,
                d.id,
                d.mac_address as title,
                d.model as subtitle,
                CONCAT('Клиент: ', COALESCE(c.full_name, 'не назначен')) as description,
                d.created_at
            FROM devices d
            LEFT JOIN clients c ON d.client_id = c.id
            WHERE d.mac_address LIKE ? OR d.model LIKE ? OR d.serial_number LIKE ? OR c.full_name LIKE ?
            ORDER BY d.mac_address
            LIMIT 10
        ");
        $devices_stmt->bind_param("ssss", $search_param, $search_param, $search_param, $search_param);
        $devices_stmt->execute();
        $devices_results = $devices_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $devices_stmt->close();

        // Поиск по IP-адресам
        $ips_stmt = $conn->prepare("
            SELECT 
                'ip' as type,
                ip.id,
                ip.ip_address as title,
                CONCAT(s.network_address, '/', s.cidr_mask) as subtitle,
                CONCAT('Устройство: ', COALESCE(d.mac_address, 'не назначено'), ' | Клиент: ', COALESCE(c.full_name, '—')) as description,
                ip.created_at
            FROM ip_addresses ip
            LEFT JOIN subnets s ON ip.subnet_id = s.id
            LEFT JOIN devices d ON ip.device_id = d.id
            LEFT JOIN clients c ON d.client_id = c.id
            WHERE ip.ip_address LIKE ? OR d.mac_address LIKE ? OR c.full_name LIKE ? OR ip.description LIKE ?
            ORDER BY ip.ip_address
            LIMIT 10
        ");
        $ips_stmt->bind_param("ssss", $search_param, $search_param, $search_param, $search_param);
        $ips_stmt->execute();
        $ips_results = $ips_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $ips_stmt->close();

        // Объединяем результаты
        $results = array_merge($clients_results, $devices_results, $ips_results);

        // Логируем поиск
        if (!empty($results)) {
            AuditSystem::logSearch('global', "Глобальный поиск: '{$search_query}' - найдено " . count($results) . " результатов", 
                ['query' => $search_query, 'results_count' => count($results)]
            );
        }

    } catch (Exception $e) {
        error_log("Error performing global search: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Глобальный поиск - Web-IPAM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    
    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <h1>Глобальный поиск</h1>

                <!-- Форма поиска -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" action="">
                            <div class="input-group">
                                <input type="text" class="form-control form-control-lg" 
                                       name="q" placeholder="Введите MAC, IP, ФИО клиента, договор..." 
                                       value="<?php echo htmlspecialchars($search_query); ?>" required>
                                <button type="submit" class="btn btn-primary btn-lg">🔍 Поиск</button>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if (!empty($search_query)): ?>
                    <!-- Результаты поиска -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                Результаты поиска для "<?php echo htmlspecialchars($search_query); ?>"
                                <span class="badge bg-secondary"><?php echo count($results); ?></span>
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($results)): ?>
                                <div class="text-center py-4">
                                    <p class="text-muted">Ничего не найдено</p>
                                    <p>Попробуйте изменить запрос или искать по:</p>
                                    <div class="d-flex gap-2 justify-content-center flex-wrap">
                                        <span class="badge bg-light text-dark">MAC-адрес</span>
                                        <span class="badge bg-light text-dark">IP-адрес</span>
                                        <span class="badge bg-light text-dark">ФИО клиента</span>
                                        <span class="badge bg-light text-dark">Номер договора</span>
                                        <span class="badge bg-light text-dark">Модель устройства</span>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="list-group">
                                    <?php foreach ($results as $result): 
                                        $type_badge = [
                                            'client' => ['bg-primary', '👥'],
                                            'device' => ['bg-warning', '🖧'], 
                                            'ip' => ['bg-success', '📡']
                                        ][$result['type']] ?? ['bg-secondary', '📄'];
                                        
                                        $action_url = [
                                            'client' => "../clients/edit.php?id={$result['id']}",
                                            'device' => "../devices/edit.php?id={$result['id']}",
                                            'ip' => "../ip-addresses/edit.php?id={$result['id']}"
                                        ][$result['type']];
                                    ?>
                                        <a href="<?php echo $action_url; ?>" class="list-group-item list-group-item-action">
                                            <div class="d-flex w-100 justify-content-between align-items-start">
                                                <div class="flex-grow-1">
                                                    <div class="d-flex align-items-center mb-2">
                                                        <span class="badge <?php echo $type_badge[0]; ?> me-2"><?php echo $type_badge[1]; ?></span>
                                                        <h6 class="mb-0"><?php echo htmlspecialchars($result['title']); ?></h6>
                                                    </div>
                                                    <?php if ($result['subtitle']): ?>
                                                        <p class="mb-1 text-muted"><?php echo htmlspecialchars($result['subtitle']); ?></p>
                                                    <?php endif; ?>
                                                    <?php if ($result['description']): ?>
                                                        <small class="text-muted"><?php echo htmlspecialchars($result['description']); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                                <small class="text-muted"><?php echo date('d.m.Y', strtotime($result['created_at'])); ?></small>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>