<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
requireAuth();

$search = $_GET['search'] ?? '';
$client_filter = $_GET['client_id'] ?? '';
$sort = $_GET['sort'] ?? 'mac_asc';

$sql = "
    SELECT d.*, c.full_name as client_name, c.contract_number,
           (SELECT COUNT(*) FROM ip_addresses ip WHERE ip.device_id = d.id) as ip_count
    FROM devices d 
    LEFT JOIN clients c ON d.client_id = c.id 
    WHERE 1=1
";

$params = [];
$types = "";

if (!empty($search)) {
    $sql .= " AND (d.mac_address LIKE ? OR d.model LIKE ? OR d.serial_number LIKE ? OR c.full_name LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    $types .= "ssss";
}

if (!empty($client_filter)) {
    $sql .= " AND d.client_id = ?";
    $params[] = $client_filter;
    $types .= "i";
}

switch ($sort) {
    case 'mac_desc':
        $sql .= " ORDER BY d.mac_address DESC";
        break;
    case 'client_asc':
        $sql .= " ORDER BY c.full_name ASC";
        break;
    case 'client_desc':
        $sql .= " ORDER BY c.full_name DESC";
        break;
    case 'newest':
        $sql .= " ORDER BY d.created_at DESC";
        break;
    case 'mac_asc':
    default:
        $sql .= " ORDER BY d.mac_address ASC";
        break;
}

try {
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $devices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    error_log("Error fetching devices: " . $e->getMessage());
    $devices = [];
}

// Получаем список клиентов для фильтра
$clients_result = $conn->query("SELECT id, full_name FROM clients ORDER BY full_name");
$all_clients = $clients_result->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Устройства - Web-IPAM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    
    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1>Управление устройствами</h1>
                    <a href="add.php" class="btn btn-primary">Добавить устройство</a>
                </div>

                <!-- Фильтры -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-5">
                                <input type="text" class="form-control" name="search" 
                                       placeholder="Поиск по MAC, модели, серийному номеру или клиенту..." 
                                       value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" name="client_id">
                                    <option value="">Все клиенты</option>
                                    <?php foreach ($all_clients as $client): ?>
                                        <option value="<?php echo $client['id']; ?>" 
                                            <?php echo $client_filter == $client['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($client['full_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select" name="sort">
                                    <option value="mac_asc" <?php echo $sort === 'mac_asc' ? 'selected' : ''; ?>>MAC ↑</option>
                                    <option value="mac_desc" <?php echo $sort === 'mac_desc' ? 'selected' : ''; ?>>MAC ↓</option>
                                    <option value="client_asc" <?php echo $sort === 'client_asc' ? 'selected' : ''; ?>>Клиент А-Я</option>
                                    <option value="client_desc" <?php echo $sort === 'client_desc' ? 'selected' : ''; ?>>Клиент Я-А</option>
                                    <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Сначала новые</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">Применить</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Таблица устройств -->
                <div class="card">
                    <div class="card-body">
                        <?php if (empty($devices)): ?>
                            <div class="text-center py-4">
                                <p class="text-muted">Устройства не найдены</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>MAC-адрес</th>
                                            <th>Модель</th>
                                            <th>Серийный номер</th>
                                            <th>Клиент</th>
                                            <th>IP-адрес</th>
                                            <th>Дата добавления</th>
                                            <th>Действия</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($devices as $device): ?>
                                            <tr>
                                                <td>
                                                    <code><?php echo htmlspecialchars($device['mac_address']); ?></code>
                                                </td>
                                                <td>
                                                    <?php echo $device['model'] ? htmlspecialchars($device['model']) : '<span class="text-muted">—</span>'; ?>
                                                </td>
                                                <td>
                                                    <?php echo $device['serial_number'] ? htmlspecialchars($device['serial_number']) : '<span class="text-muted">—</span>'; ?>
                                                </td>
                                                <td>
                                                    <?php if ($device['client_name']): ?>
                                                        <div><?php echo htmlspecialchars($device['client_name']); ?></div>
                                                        <small class="text-muted"><?php echo htmlspecialchars($device['contract_number']); ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($device['ip_count'] > 0): ?>
                                                        <?php
                                                        $ip_stmt = $conn->prepare("SELECT ip_address FROM ip_addresses WHERE device_id = ?");
                                                        $ip_stmt->bind_param("i", $device['id']);
                                                        $ip_stmt->execute();
                                                        $ip_result = $ip_stmt->get_result();
                                                        $ip_address = $ip_result->fetch_assoc();
                                                        $ip_stmt->close();
                                                        ?>
                                                        <code><?php echo htmlspecialchars($ip_address['ip_address']); ?></code>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning">Нет IP</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small><?php echo date('d.m.Y', strtotime($device['created_at'])); ?></small>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="edit.php?id=<?php echo $device['id']; ?>" class="btn btn-outline-primary">✏️</a>
                                                        <a href="../ip-addresses/list.php?search=<?php echo urlencode($device['mac_address']); ?>" 
                                                           class="btn btn-outline-info" title="Найти IP">📡</a>
                                                        <?php if (hasRole('admin') && $device['ip_count'] == 0): ?>
                                                            <a href="delete.php?id=<?php echo $device['id']; ?>" class="btn btn-outline-danger" 
                                                               onclick="return confirm('Удалить устройство <?php echo htmlspecialchars($device['mac_address']); ?>?')">🗑️</a>
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
</body>
</html>