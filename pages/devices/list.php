<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
require_once '../../includes/pagination.php';
requireAuth();

$search = $_GET['search'] ?? '';
$client_filter = $_GET['client_id'] ?? '';
$sort = $_GET['sort'] ?? 'mac_asc';

// Пагинация
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 50;

// Базовый запрос для подсчета
$count_sql = "
    SELECT COUNT(*) as total
    FROM devices d 
    LEFT JOIN clients c ON d.client_id = c.id 
    WHERE 1=1
";

// Базовый запрос для данных
$sql = "
    SELECT d.*, c.full_name as client_name, c.contract_number,
           (SELECT COUNT(*) FROM ip_addresses ip WHERE ip.device_id = d.id) as ip_count
    FROM devices d 
    LEFT JOIN clients c ON d.client_id = c.id 
    WHERE 1=1
";

$params = [];
$types = "";
$count_params = [];
$count_types = "";

if (!empty($search)) {
    $search_param = "%$search%";
    $sql .= " AND (d.mac_address LIKE ? OR d.model LIKE ? OR d.serial_number LIKE ? OR c.full_name LIKE ?)";
    $count_sql .= " AND (d.mac_address LIKE ? OR d.model LIKE ? OR d.serial_number LIKE ? OR c.full_name LIKE ?)";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    $count_params = array_merge($count_params, [$search_param, $search_param, $search_param, $search_param]);
    $types .= "ssss";
    $count_types .= "ssss";
}

if (!empty($client_filter)) {
    $sql .= " AND d.client_id = ?";
    $count_sql .= " AND d.client_id = ?";
    $params[] = $client_filter;
    $count_params[] = $client_filter;
    $types .= "i";
    $count_types .= "i";
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

// Добавляем пагинацию
$offset = ($page - 1) * $per_page;
$sql .= " LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types .= "ii";

// Выполняем запрос для подсчета
try {
    $count_stmt = $conn->prepare($count_sql);
    if (!empty($count_params)) {
        $count_stmt->bind_param($count_types, ...$count_params);
    }
    $count_stmt->execute();
    $total_result = $count_stmt->get_result()->fetch_assoc();
    $total_records = $total_result['total'];
    $count_stmt->close();
} catch (Exception $e) {
    error_log("Error counting devices: " . $e->getMessage());
    $total_records = 0;
}

// Выполняем запрос для данных
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

// Создаем пагинацию
$pagination = new Pagination($total_records, $per_page, $page);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Устройства - Web-IPAM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link href="../../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    
    <div class="container mt-4">
        <!-- Заголовок и кнопки -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="h3 mb-1">Управление устройствами</h1>
                        <p class="text-muted mb-0">Список всех сетевых устройств в системе</p>
                    </div>
                    <?php if (hasAnyRole(['admin', 'engineer'])): ?>
                        <a href="add.php" class="btn btn-primary">
                            <i class="bi bi-plus-circle me-1"></i>Добавить устройство
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Уведомления -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php echo htmlspecialchars($_SESSION['success_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <!-- Фильтры и поиск -->
        <div class="card stat-card mb-4">
            <div class="card-header bg-transparent">
                <h5 class="card-title mb-0">
                    <i class="bi bi-funnel me-2"></i>Фильтры и поиск
                </h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-5">
                        <label for="search" class="form-label">Поиск устройств</label>
                        <input type="text" class="form-control" id="search" name="search" 
                               placeholder="Поиск по MAC, модели, серийному номеру или клиенту..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="client_id" class="form-label">Фильтр по клиенту</label>
                        <select class="form-select" id="client_id" name="client_id">
                            <option value="">Все клиенты</option>
                            <?php foreach ($all_clients as $client): ?>
                                <option value="<?php echo htmlspecialchars($client['id']); ?>" 
                                    <?php echo $client_filter == $client['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($client['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="sort" class="form-label">Сортировка</label>
                        <select class="form-select" id="sort" name="sort">
                            <option value="mac_asc" <?php echo $sort === 'mac_asc' ? 'selected' : ''; ?>>MAC ↑</option>
                            <option value="mac_desc" <?php echo $sort === 'mac_desc' ? 'selected' : ''; ?>>MAC ↓</option>
                            <option value="client_asc" <?php echo $sort === 'client_asc' ? 'selected' : ''; ?>>Клиент А-Я</option>
                            <option value="client_desc" <?php echo $sort === 'client_desc' ? 'selected' : ''; ?>>Клиент Я-А</option>
                            <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Сначала новые</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-filter me-1"></i>Применить
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Информация о пагинации -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <span class="text-muted">
                    Показано <strong><?php echo count($devices); ?></strong> из <strong><?php echo $total_records; ?></strong> устройств
                </span>
            </div>
            <div>
                <span class="text-muted">
                    Страница <strong><?php echo $page; ?></strong> из <strong><?php echo $pagination->total_pages; ?></strong>
                </span>
            </div>
        </div>

        <!-- Таблица устройств -->
        <div class="card stat-card">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-hdd me-2"></i>Список устройств
                </h5>
                <span class="badge bg-primary"><?php echo $total_records; ?> всего</span>
            </div>
            <div class="card-body">
                <?php if (empty($devices)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-hdd display-4 text-muted mb-3"></i>
                        <h5 class="text-muted">Устройства не найдены</h5>
                        <p class="text-muted mb-3">Попробуйте изменить параметры поиска</p>
                        <?php if ($search || $client_filter): ?>
                            <a href="list.php" class="btn btn-outline-primary">
                                <i class="bi bi-arrow-clockwise me-1"></i>Сбросить фильтры
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped">
                            <thead class="table-light">
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
                                            <code class="fw-bold"><?php echo htmlspecialchars($device['mac_address']); ?></code>
                                        </td>
                                        <td>
                                            <?php if ($device['model']): ?>
                                                <?php echo htmlspecialchars($device['model']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($device['serial_number']): ?>
                                                <code><?php echo htmlspecialchars($device['serial_number']); ?></code>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
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
                                                <code class="text-success"><?php echo htmlspecialchars($ip_address['ip_address']); ?></code>
                                            <?php else: ?>
                                                <span class="badge bg-warning">
                                                    <i class="bi bi-exclamation-circle me-1"></i>Нет IP
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <?php echo date('d.m.Y', strtotime($device['created_at'])); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="edit.php?id=<?php echo htmlspecialchars($device['id']); ?>" 
                                                   class="btn btn-outline-primary" 
                                                   title="Редактировать устройство">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <a href="../ip-addresses/list.php?search=<?php echo urlencode($device['mac_address']); ?>" 
                                                   class="btn btn-outline-info" 
                                                   title="Найти связанные IP-адреса">
                                                    <i class="bi bi-router"></i>
                                                </a>
                                                <?php if (hasRole('admin') && $device['ip_count'] == 0): ?>
                                                    <a href="delete.php?id=<?php echo htmlspecialchars($device['id']); ?>" 
                                                       class="btn btn-outline-danger" 
                                                       onclick="return confirm('Удалить устройство <?php echo htmlspecialchars($device['mac_address']); ?>?')"
                                                       title="Удалить устройство">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Пагинация -->
                    <?php if ($pagination->total_pages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center mt-4">
                        <div>
                            <span class="text-muted">
                                Страница <?php echo $page; ?> из <?php echo $pagination->total_pages; ?>
                            </span>
                        </div>
                        <nav>
                            <ul class="pagination mb-0">
                                <?php if ($pagination->has_previous()): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?php echo $pagination->get_page_url($page - 1); ?>">
                                            <i class="bi bi-chevron-left"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>

                                <?php foreach ($pagination->get_pages() as $p): ?>
                                    <li class="page-item <?php echo $p == $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo $pagination->get_page_url($p); ?>">
                                            <?php echo $p; ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>

                                <?php if ($pagination->has_next()): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?php echo $pagination->get_page_url($page + 1); ?>">
                                            <i class="bi bi-chevron-right"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Статистика -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card stat-card">
                    <div class="card-header bg-transparent">
                        <h6 class="card-title mb-0">
                            <i class="bi bi-graph-up me-2"></i>Статистика по устройствам
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <?php
                            $with_ip = count(array_filter($devices, fn($device) => $device['ip_count'] > 0));
                            $without_ip = count(array_filter($devices, fn($device) => $device['ip_count'] == 0));
                            $with_client = count(array_filter($devices, fn($device) => !empty($device['client_id'])));
                            $without_client = count(array_filter($devices, fn($device) => empty($device['client_id'])));
                            ?>
                            <div class="col">
                                <div class="stat-number text-primary"><?php echo $total_records; ?></div>
                                <div class="stat-label">Всего устройств</div>
                            </div>
                            <div class="col">
                                <div class="stat-number text-success"><?php echo $with_ip; ?></div>
                                <div class="stat-label">С IP-адресами</div>
                            </div>
                            <div class="col">
                                <div class="stat-number text-warning"><?php echo $without_ip; ?></div>
                                <div class="stat-label">Без IP-адресов</div>
                            </div>
                            <div class="col">
                                <div class="stat-number text-info"><?php echo $with_client; ?></div>
                                <div class="stat-label">С клиентами</div>
                            </div>
                            <div class="col">
                                <div class="stat-number text-secondary"><?php echo $without_client; ?></div>
                                <div class="stat-label">Без клиентов</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Авто-сабмит при изменении сортировки
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('sort').addEventListener('change', function() {
                this.form.submit();
            });
        });
    </script>
</body>
</html>