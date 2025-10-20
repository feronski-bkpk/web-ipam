<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
require_once '../../includes/audit_system.php';
requireAuth();

// Поиск и фильтрация
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'name_asc';

// Базовый запрос
$sql = "SELECT * FROM clients WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $sql .= " AND (full_name LIKE ? OR contract_number LIKE ? OR address LIKE ? OR phone LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    $types .= "ssss";
}

// Сортировка
switch ($sort) {
    case 'name_desc':
        $sql .= " ORDER BY full_name DESC";
        break;
    case 'contract_asc':
        $sql .= " ORDER BY contract_number ASC";
        break;
    case 'contract_desc':
        $sql .= " ORDER BY contract_number DESC";
        break;
    case 'newest':
        $sql .= " ORDER BY created_at DESC";
        break;
    case 'name_asc':
    default:
        $sql .= " ORDER BY full_name ASC";
        break;
}

try {
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $clients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    error_log("Error fetching clients: " . $e->getMessage());
    $clients = [];
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Клиенты - Web-IPAM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    
    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1>Управление клиентами</h1>
                    <a href="add.php" class="btn btn-primary">Добавить клиента</a>
                </div>

                <!-- Поиск и фильтры -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-8">
                                <input type="text" class="form-control" name="search" 
                                       placeholder="Поиск по ФИО, договору, адресу или телефону..." 
                                       value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="col-md-2">
                                <select class="form-select" name="sort">
                                    <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>ФИО А-Я</option>
                                    <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>ФИО Я-А</option>
                                    <option value="contract_asc" <?php echo $sort === 'contract_asc' ? 'selected' : ''; ?>>Договор ↑</option>
                                    <option value="contract_desc" <?php echo $sort === 'contract_desc' ? 'selected' : ''; ?>>Договор ↓</option>
                                    <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Сначала новые</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">Поиск</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Таблица клиентов -->
                <div class="card">
                    <div class="card-body">
                        <?php if (empty($clients)): ?>
                            <div class="text-center py-4">
                                <p class="text-muted">Клиенты не найдены</p>
                                <?php if ($search): ?>
                                    <a href="list.php" class="btn btn-outline-secondary">Сбросить поиск</a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>ФИО</th>
                                            <th>Договор</th>
                                            <th>Адрес</th>
                                            <th>Телефон</th>
                                            <th>Устройства</th>
                                            <th>Дата создания</th>
                                            <th>Действия</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($clients as $client): 
                                            // Получаем количество устройств клиента
                                            $devices_stmt = $conn->prepare("SELECT COUNT(*) as device_count FROM devices WHERE client_id = ?");
                                            $devices_stmt->bind_param("i", $client['id']);
                                            $devices_stmt->execute();
                                            $device_count = $devices_stmt->get_result()->fetch_assoc()['device_count'];
                                            $devices_stmt->close();
                                        ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($client['full_name']); ?></strong>
                                                </td>
                                                <td>
                                                    <code><?php echo htmlspecialchars($client['contract_number']); ?></code>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($client['address']); ?>
                                                </td>
                                                <td>
                                                    <?php echo $client['phone'] ? htmlspecialchars($client['phone']) : '<span class="text-muted">—</span>'; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info"><?php echo $device_count; ?></span>
                                                </td>
                                                <td>
                                                    <small><?php echo date('d.m.Y', strtotime($client['created_at'])); ?></small>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="edit.php?id=<?php echo $client['id']; ?>" class="btn btn-outline-primary">✏️</a>
                                                        <a href="devices.php?id=<?php echo $client['id']; ?>" class="btn btn-outline-info" title="Устройства клиента">🖧</a>
                                                        <?php if (hasRole('admin') && $device_count == 0): ?>
                                                            <a href="delete.php?id=<?php echo $client['id']; ?>" class="btn btn-outline-danger" 
                                                               onclick="return confirm('Удалить клиента <?php echo htmlspecialchars($client['full_name']); ?>?')">🗑️</a>
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
                                <h6>Статистика по клиентам</h6>
                                <div class="row text-center">
                                    <div class="col">
                                        <small class="text-muted">Всего клиентов</small>
                                        <h5><?php echo count($clients); ?></h5>
                                    </div>
                                    <div class="col">
                                        <small class="text-muted">С устройствами</small>
                                        <h5>
                                            <?php
                                            $with_devices = 0;
                                            foreach ($clients as $client) {
                                                $devices_stmt = $conn->prepare("SELECT COUNT(*) as count FROM devices WHERE client_id = ?");
                                                $devices_stmt->bind_param("i", $client['id']);
                                                $devices_stmt->execute();
                                                if ($devices_stmt->get_result()->fetch_assoc()['count'] > 0) {
                                                    $with_devices++;
                                                }
                                                $devices_stmt->close();
                                            }
                                            echo $with_devices;
                                            ?>
                                        </h5>
                                    </div>
                                    <div class="col">
                                        <small class="text-muted">Без устройств</small>
                                        <h5><?php echo count($clients) - $with_devices; ?></h5>
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
</body>
</html>