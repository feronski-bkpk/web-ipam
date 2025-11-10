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
                        <h1 class="h3 mb-1">Глобальный поиск</h1>
                        <p class="text-muted mb-0">Поиск по всем данным системы</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Форма поиска -->
        <div class="card stat-card mb-4">
            <div class="card-header bg-transparent">
                <h5 class="card-title mb-0">
                    <i class="bi bi-search me-2"></i>Поисковый запрос
                </h5>
            </div>
            <div class="card-body">
                <form method="GET" action="">
                    <div class="input-group input-group-lg">
                        <input type="text" class="form-control" 
                               name="q" placeholder="Введите MAC, IP, ФИО клиента, договор..." 
                               value="<?php echo htmlspecialchars($search_query); ?>" required>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search me-1"></i>Поиск
                        </button>
                    </div>
                    <div class="form-text mt-2">
                        <small class="text-muted">
                            <i class="bi bi-info-circle me-1"></i>
                            Поиск по: MAC-адресам, IP-адресам, ФИО клиентов, номерам договоров, моделям устройств
                        </small>
                    </div>
                </form>
            </div>
        </div>

        <?php if (!empty($search_query)): ?>
            <!-- Результаты поиска -->
            <div class="card stat-card">
                <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-list-ul me-2"></i>Результаты поиска
                    </h5>
                    <span class="badge bg-primary"><?php echo count($results); ?> найдено</span>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <p class="text-muted mb-0">
                            Запрос: <strong>"<?php echo htmlspecialchars($search_query); ?>"</strong>
                        </p>
                    </div>

                    <?php if (empty($results)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-search display-4 text-muted mb-3"></i>
                            <h5 class="text-muted">Ничего не найдено</h5>
                            <p class="text-muted mb-3">Попробуйте изменить запрос или искать по другим параметрам</p>
                            <div class="d-flex gap-2 justify-content-center flex-wrap">
                                <span class="badge bg-light text-dark border">MAC-адрес</span>
                                <span class="badge bg-light text-dark border">IP-адрес</span>
                                <span class="badge bg-light text-dark border">ФИО клиента</span>
                                <span class="badge bg-light text-dark border">Номер договора</span>
                                <span class="badge bg-light text-dark border">Модель устройства</span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($results as $result): 
                                $type_config = [
                                    'client' => [
                                        'bg_class' => 'bg-primary',
                                        'icon' => 'bi-people',
                                        'type_name' => 'Клиент'
                                    ],
                                    'device' => [
                                        'bg_class' => 'bg-warning',
                                        'icon' => 'bi-hdd',
                                        'type_name' => 'Устройство'
                                    ],
                                    'ip' => [
                                        'bg_class' => 'bg-success', 
                                        'icon' => 'bi-router',
                                        'type_name' => 'IP-адрес'
                                    ]
                                ][$result['type']] ?? [
                                    'bg_class' => 'bg-secondary',
                                    'icon' => 'bi-file-text',
                                    'type_name' => 'Запись'
                                ];
                                
                                $action_url = [
                                    'client' => "../clients/edit.php?id={$result['id']}",
                                    'device' => "../devices/edit.php?id={$result['id']}",
                                    'ip' => "../ip-addresses/edit.php?id={$result['id']}"
                                ][$result['type']];
                            ?>
                                <a href="<?php echo $action_url; ?>" class="list-group-item list-group-item-action border-0 mb-2 rounded">
                                    <div class="d-flex w-100 justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <div class="d-flex align-items-center mb-2">
                                                <span class="badge <?php echo $type_config['bg_class']; ?> me-2">
                                                    <i class="bi <?php echo $type_config['icon']; ?> me-1"></i>
                                                    <?php echo $type_config['type_name']; ?>
                                                </span>
                                                <h6 class="mb-0 text-dark"><?php echo htmlspecialchars($result['title']); ?></h6>
                                            </div>
                                            <?php if ($result['subtitle']): ?>
                                                <p class="mb-1 text-muted">
                                                    <i class="bi bi-tag me-1"></i>
                                                    <?php echo htmlspecialchars($result['subtitle']); ?>
                                                </p>
                                            <?php endif; ?>
                                            <?php if ($result['description']): ?>
                                                <small class="text-muted">
                                                    <i class="bi bi-info-circle me-1"></i>
                                                    <?php echo htmlspecialchars($result['description']); ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-end">
                                            <small class="text-muted d-block">
                                                <?php echo date('d.m.Y', strtotime($result['created_at'])); ?>
                                            </small>
                                            <span class="badge bg-light text-dark border">
                                                <i class="bi bi-box-arrow-up-right me-1"></i>
                                                Перейти
                                            </span>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>

                        <!-- Статистика поиска -->
                        <div class="mt-4 pt-3 border-top">
                            <div class="row text-center">
                                <?php
                                $clients_count = count(array_filter($results, fn($r) => $r['type'] === 'client'));
                                $devices_count = count(array_filter($results, fn($r) => $r['type'] === 'device'));
                                $ips_count = count(array_filter($results, fn($r) => $r['type'] === 'ip'));
                                ?>
                                <div class="col-4">
                                    <div class="stat-number text-primary"><?php echo $clients_count; ?></div>
                                    <div class="stat-label">Клиентов</div>
                                </div>
                                <div class="col-4">
                                    <div class="stat-number text-warning"><?php echo $devices_count; ?></div>
                                    <div class="stat-label">Устройств</div>
                                </div>
                                <div class="col-4">
                                    <div class="stat-number text-success"><?php echo $ips_count; ?></div>
                                    <div class="stat-label">IP-адресов</div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Подсказки для улучшения поиска -->
            <?php if (empty($results)): ?>
            <div class="card stat-card mt-4">
                <div class="card-header bg-transparent">
                    <h6 class="card-title mb-0">
                        <i class="bi bi-lightbulb me-2"></i>Советы по поиску
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <ul class="text-muted small">
                                <li>Используйте частичные совпадения (например, "192.168" для поиска IP)</li>
                                <li>MAC-адресы можно вводить в любом формате</li>
                                <li>Поиск по фамилии клиента работает без полного ФИО</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <ul class="text-muted small">
                                <li>Номер договора должен точно соответствовать</li>
                                <li>Модели устройств чувствительны к регистру</li>
                                <li>Поиск работает по всем основным модулям системы</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Автофокус на поле поиска
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.querySelector('input[name="q"]');
            if (searchInput) {
                searchInput.focus();
                // Помещаем курсор в конец текста
                searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
            }
        });

        // Очистка поиска при нажатии Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const searchInput = document.querySelector('input[name="q"]');
                if (searchInput && document.activeElement === searchInput) {
                    searchInput.value = '';
                }
            }
        });
    </script>
</body>
</html>