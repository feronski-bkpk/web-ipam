<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
requireAuth();

// Параметры фильтрации
$search = $_GET['search'] ?? '';
$type_filter = $_GET['type'] ?? '';
$status_filter = $_GET['status'] ?? '';
$subnet_filter = $_GET['subnet'] ?? '';
$sort = $_GET['sort'] ?? 'ip_desc';

// Базовый запрос
$sql = "
    SELECT 
        ip.*,
        s.network_address, s.cidr_mask, s.description as subnet_description,
        d.mac_address, d.model,
        c.full_name as client_name, c.id as client_id,
        u.full_name as created_by_name
    FROM ip_addresses ip
    LEFT JOIN subnets s ON ip.subnet_id = s.id
    LEFT JOIN devices d ON ip.device_id = d.id
    LEFT JOIN clients c ON d.client_id = c.id
    LEFT JOIN users u ON ip.created_by = u.id
    WHERE 1=1
";

$params = [];
$types = "";

// Применяем фильтры
if (!empty($search)) {
    $sql .= " AND (ip.ip_address LIKE ? OR d.mac_address LIKE ? OR c.full_name LIKE ? OR ip.description LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    $types .= "ssss";
}

if (!empty($type_filter)) {
    $sql .= " AND ip.type = ?";
    $params[] = $type_filter;
    $types .= "s";
}

if (!empty($status_filter)) {
    $sql .= " AND ip.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($subnet_filter)) {
    $sql .= " AND ip.subnet_id = ?";
    $params[] = $subnet_filter;
    $types .= "i";
}

// Применяем сортировку
switch ($sort) {
    case 'ip_asc':
        $sql .= " ORDER BY ip.created_at ASC";
        break;
    case 'client_asc':
        $sql .= " ORDER BY c.full_name ASC";
        break;
    case 'client_desc':
        $sql .= " ORDER BY c.full_name DESC";
        break;
    case 'updated_desc':
        $sql .= " ORDER BY ip.updated_at DESC";
        break;
    case 'ip_desc':
    default:
        $sql .= " ORDER BY ip.created_at DESC";
        break;
}

// Выполняем запрос
try {
    $stmt = $conn->prepare($sql);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $ip_addresses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
} catch (Exception $e) {
    error_log("Error fetching IP addresses: " . $e->getMessage());
    $ip_addresses = [];
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IP-адреса - Web-IPAM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    
    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1>Управление IP-адресами</h1>
                    <?php if (hasAnyRole(['admin', 'engineer'])): ?>
                        <a href="add.php" class="btn btn-primary">Добавить IP-адрес</a>
                    <?php endif; ?>
                </div>

                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['success_message']); ?></div>
                    <?php unset($_SESSION['success_message']); ?>
                <?php endif; ?>

                <!-- Расширенные фильтры и поиск -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Фильтры и поиск</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3" id="filter-form">
                            <!-- Быстрые фильтры -->
                            <div class="col-md-12">
                                <div class="row">
                                    <div class="col-auto">
                                        <label class="form-label"><small>Быстрые фильтры:</small></label>
                                    </div>
                                    <div class="col-auto">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setFilter('type', 'white')">Белые IP</button>
                                    </div>
                                    <div class="col-auto">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setFilter('type', 'gray')">Серые IP</button>
                                    </div>
                                    <div class="col-auto">
                                        <button type="button" class="btn btn-sm btn-outline-success" onclick="setFilter('status', 'active')">Активные</button>
                                    </div>
                                    <div class="col-auto">
                                        <button type="button" class="btn btn-sm btn-outline-info" onclick="setFilter('status', 'free')">Свободные</button>
                                    </div>
                                    <div class="col-auto">
                                        <button type="button" class="btn btn-sm btn-outline-warning" onclick="setFilter('status', 'reserved')">Резерв</button>
                                    </div>
                                    <div class="col-auto">
                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="clearFilters()">Сбросить</button>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <label for="search" class="form-label">Поиск</label>
                                <input type="text" class="form-control" id="search" name="search" 
                                       placeholder="IP, MAC, клиент, описание..." 
                                       value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                            </div>
                            
                            <div class="col-md-2">
                                <label for="type" class="form-label">Тип</label>
                                <select class="form-select" id="type" name="type">
                                    <option value="">Все типы</option>
                                    <option value="white" <?php echo ($_GET['type'] ?? '') === 'white' ? 'selected' : ''; ?>>Белые</option>
                                    <option value="gray" <?php echo ($_GET['type'] ?? '') === 'gray' ? 'selected' : ''; ?>>Серые</option>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label for="status" class="form-label">Статус</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="">Все статусы</option>
                                    <option value="active" <?php echo ($_GET['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Активные</option>
                                    <option value="free" <?php echo ($_GET['status'] ?? '') === 'free' ? 'selected' : ''; ?>>Свободные</option>
                                    <option value="reserved" <?php echo ($_GET['status'] ?? '') === 'reserved' ? 'selected' : ''; ?>>Зарезервированные</option>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label for="subnet" class="form-label">Подсеть</label>
                                <select class="form-select" id="subnet" name="subnet">
                                    <option value="">Все подсети</option>
                                    <?php
                                    $subnets_stmt = $conn->prepare("SELECT id, network_address, cidr_mask FROM subnets ORDER BY network_address");
                                    $subnets_stmt->execute();
                                    $all_subnets = $subnets_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                                    foreach ($all_subnets as $subnet): 
                                        $selected = ($_GET['subnet'] ?? '') == $subnet['id'] ? 'selected' : '';
                                        $subnet_display = $subnet['network_address'] . '/' . $subnet['cidr_mask'];
                                    ?>
                                        <option value="<?php echo $subnet['id']; ?>" <?php echo $selected; ?>>
                                            <?php echo htmlspecialchars($subnet_display); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label for="sort" class="form-label">Сортировка</label>
                                <select class="form-select" id="sort" name="sort">
                                    <option value="ip_desc" <?php echo ($_GET['sort'] ?? 'ip_desc') === 'ip_desc' ? 'selected' : ''; ?>>IP (новые)</option>
                                    <option value="ip_asc" <?php echo ($_GET['sort'] ?? '') === 'ip_asc' ? 'selected' : ''; ?>>IP (старые)</option>
                                    <option value="client_asc" <?php echo ($_GET['sort'] ?? '') === 'client_asc' ? 'selected' : ''; ?>>Клиент А-Я</option>
                                    <option value="client_desc" <?php echo ($_GET['sort'] ?? '') === 'client_desc' ? 'selected' : ''; ?>>Клиент Я-А</option>
                                    <option value="updated_desc" <?php echo ($_GET['sort'] ?? '') === 'updated_desc' ? 'selected' : ''; ?>>Обновленные</option>
                                </select>
                            </div>
                            
                            <div class="col-md-1 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">Применить</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Таблица IP-адресов -->
                <div class="card">
                    <div class="card-body">
                        <?php if (empty($ip_addresses)): ?>
                            <div class="text-center py-4">
                                <p class="text-muted">IP-адреса не найдены</p>
                                <?php if ($search || $type_filter || $status_filter || $subnet_filter): ?>
                                    <a href="list.php" class="btn btn-outline-secondary">Сбросить фильтры</a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>IP-адрес</th>
                                            <th>Подсеть</th>
                                            <th>Тип</th>
                                            <th>Статус</th>
                                            <th>Устройство (MAC)</th>
                                            <th>Клиент</th>
                                            <th>Описание</th>
                                            <th>Создано</th>
                                            <th>Действия</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($ip_addresses as $ip): ?>
                                            <tr>
                                                <td>
                                                    <code><?php echo htmlspecialchars($ip['ip_address']); ?></code>
                                                </td>
                                                <td>
                                                    <small><?php echo htmlspecialchars($ip['network_address'] . '/' . $ip['cidr_mask']); ?></small>
                                                    <?php if ($ip['subnet_description']): ?>
                                                        <br><small class="text-muted"><?php echo htmlspecialchars($ip['subnet_description']); ?></small>
                                                    <?php endif; ?>
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
                                                    <small>
                                                        <?php echo date('d.m.Y H:i', strtotime($ip['created_at'])); ?>
                                                        <?php if (isset($ip['updated_at']) && $ip['updated_at'] && $ip['updated_at'] != $ip['created_at']): ?>
                                                            <br><span class="text-muted" title="Обновлено: <?php echo date('d.m.Y H:i', strtotime($ip['updated_at'])); ?>">изм.</span>
                                                        <?php endif; ?>
                                                        <?php if ($ip['created_by_name']): ?>
                                                            <br><span class="text-muted"><?php echo htmlspecialchars($ip['created_by_name']); ?></span>
                                                        <?php endif; ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="edit.php?id=<?php echo $ip['id']; ?>" class="btn btn-outline-primary" title="Редактировать">
                                                            ✏️
                                                        </a>
                                                        <?php if (hasRole('admin')): ?>
                                                            <a href="delete.php?id=<?php echo $ip['id']; ?>" class="btn btn-outline-danger" 
                                                               onclick="return confirm('Удалить IP-адрес <?php echo $ip['ip_address']; ?>?')" title="Удалить">
                                                                🗑️
                                                            </a>
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

                <!-- Статистика -->
                <div class="row mt-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-body">
                                <h6>Статистика по IP-адресам</h6>
                                <div class="row text-center">
                                    <div class="col">
                                        <small class="text-muted">Всего записей</small>
                                        <h5><?php echo count($ip_addresses); ?></h5>
                                    </div>
                                    <div class="col">
                                        <small class="text-muted">Активных</small>
                                        <h5><?php echo count(array_filter($ip_addresses, fn($ip) => $ip['status'] === 'active')); ?></h5>
                                    </div>
                                    <div class="col">
                                        <small class="text-muted">Свободных</small>
                                        <h5><?php echo count(array_filter($ip_addresses, fn($ip) => $ip['status'] === 'free')); ?></h5>
                                    </div>
                                    <div class="col">
                                        <small class="text-muted">Белых IP</small>
                                        <h5><?php echo count(array_filter($ip_addresses, fn($ip) => $ip['type'] === 'white')); ?></h5>
                                    </div>
                                    <div class="col">
                                        <small class="text-muted">Серых IP</small>
                                        <h5><?php echo count(array_filter($ip_addresses, fn($ip) => $ip['type'] === 'gray')); ?></h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Управление фильтрами
        function setFilter(filter, value) {
            document.getElementById(filter).value = value;
            document.getElementById('filter-form').submit();
        }

        function clearFilters() {
            document.getElementById('search').value = '';
            document.getElementById('type').value = '';
            document.getElementById('status').value = '';
            document.getElementById('subnet').value = '';
            document.getElementById('sort').value = 'ip_desc';
            document.getElementById('filter-form').submit();
        }

        // Показываем активные фильтры
        function showActiveFilters() {
            const params = new URLSearchParams(window.location.search);
            const activeFilters = [];
            
            if (params.get('search')) activeFilters.push(`Поиск: "${params.get('search')}"`);
            if (params.get('type')) activeFilters.push(`Тип: ${params.get('type') === 'white' ? 'Белые' : 'Серые'}`);
            if (params.get('status')) {
                const statusText = {
                    'active': 'Активные',
                    'free': 'Свободные', 
                    'reserved': 'Зарезервированные'
                };
                activeFilters.push(`Статус: ${statusText[params.get('status')]}`);
            }
            if (params.get('subnet')) activeFilters.push(`Подсеть: выбранная`);
            
            if (activeFilters.length > 0) {
                const filterInfo = document.createElement('div');
                filterInfo.className = 'alert alert-info mt-3';
                filterInfo.innerHTML = `<strong>Активные фильтры:</strong> ${activeFilters.join(', ')} 
                                       <button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="clearFilters()">Очистить все</button>`;
                document.querySelector('.card-body').appendChild(filterInfo);
            }
        }

        // Инициализация при загрузке
        document.addEventListener('DOMContentLoaded', function() {
            showActiveFilters();
            
            // Авто-сабмит при изменении сортировки
            document.getElementById('sort').addEventListener('change', function() {
                document.getElementById('filter-form').submit();
            });
        });
    </script>
</body>
</html>