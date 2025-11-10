<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
require_once '../../includes/pagination.php';
requireAuth();

// Параметры фильтрации
$search = $_GET['search'] ?? '';
$type_filter = $_GET['type'] ?? '';
$status_filter = $_GET['status'] ?? '';
$subnet_filter = $_GET['subnet'] ?? '';
$sort = $_GET['sort'] ?? 'ip_desc';

// Пагинация
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 50; // Количество записей на странице

// Базовый запрос для подсчета общего количества
$count_sql = "
    SELECT COUNT(*) as total
    FROM ip_addresses ip
    LEFT JOIN subnets s ON ip.subnet_id = s.id
    LEFT JOIN devices d ON ip.device_id = d.id
    LEFT JOIN clients c ON d.client_id = c.id
    WHERE 1=1
";

// Базовый запрос для данных
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
$count_params = [];
$count_types = "";

// Применяем фильтры
if (!empty($search)) {
    $search_param = "%$search%";
    $sql .= " AND (ip.ip_address LIKE ? OR d.mac_address LIKE ? OR c.full_name LIKE ? OR ip.description LIKE ?)";
    $count_sql .= " AND (ip.ip_address LIKE ? OR d.mac_address LIKE ? OR c.full_name LIKE ? OR ip.description LIKE ?)";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    $count_params = array_merge($count_params, [$search_param, $search_param, $search_param, $search_param]);
    $types .= "ssss";
    $count_types .= "ssss";
}

if (!empty($type_filter)) {
    $sql .= " AND ip.type = ?";
    $count_sql .= " AND ip.type = ?";
    $params[] = $type_filter;
    $count_params[] = $type_filter;
    $types .= "s";
    $count_types .= "s";
}

if (!empty($status_filter)) {
    $sql .= " AND ip.status = ?";
    $count_sql .= " AND ip.status = ?";
    $params[] = $status_filter;
    $count_params[] = $status_filter;
    $types .= "s";
    $count_types .= "s";
}

if (!empty($subnet_filter)) {
    $sql .= " AND ip.subnet_id = ?";
    $count_sql .= " AND ip.subnet_id = ?";
    $params[] = $subnet_filter;
    $count_params[] = $subnet_filter;
    $types .= "i";
    $count_types .= "i";
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

// Добавляем лимит для пагинации
$offset = ($page - 1) * $per_page;
$sql .= " LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types .= "ii";

// Выполняем запрос для подсчета общего количества
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
    error_log("Error counting IP addresses: " . $e->getMessage());
    $total_records = 0;
}

// Выполняем запрос для данных
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

// Получаем подсети для фильтра
$subnets_stmt = $conn->prepare("SELECT id, network_address, cidr_mask FROM subnets ORDER BY network_address");
$subnets_stmt->execute();
$all_subnets = $subnets_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$subnets_stmt->close();

// Создаем пагинацию
$pagination = new Pagination($total_records, $per_page, $page);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IP-адреса - Web-IPAM</title>
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
                        <h1 class="h3 mb-1">Управление IP-адресами</h1>
                        <p class="text-muted mb-0">Список всех IP-адресов в системе</p>
                    </div>
                    <div>
                        <?php if (hasAnyRole(['admin', 'engineer'])): ?>
                            <a href="bulk_operations.php" class="btn btn-warning me-2">
                                <i class="bi bi-lightning-charge me-1"></i>Массовые операции
                            </a>
                            <a href="add.php" class="btn btn-primary">
                                <i class="bi bi-plus-circle me-1"></i>Добавить IP-адрес
                            </a>
                        <?php endif; ?>
                    </div>
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
                <form method="GET" class="row g-3" id="filter-form">
                    <!-- Быстрые фильтры -->
                    <div class="col-12">
                        <label class="form-label"><small>Быстрые фильтры:</small></label>
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-secondary" onclick="setFilter('type', 'white')">
                                <i class="bi bi-globe me-1"></i>Белые IP
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="setFilter('type', 'gray')">
                                <i class="bi bi-layers me-1"></i>Серые IP
                            </button>
                            <button type="button" class="btn btn-outline-success" onclick="setFilter('status', 'active')">
                                <i class="bi bi-check-circle me-1"></i>Активные
                            </button>
                            <button type="button" class="btn btn-outline-info" onclick="setFilter('status', 'free')">
                                <i class="bi bi-circle me-1"></i>Свободные
                            </button>
                            <button type="button" class="btn btn-outline-warning" onclick="setFilter('status', 'reserved')">
                                <i class="bi bi-shield-lock me-1"></i>Резерв
                            </button>
                            <button type="button" class="btn btn-outline-danger" onclick="clearFilters()">
                                <i class="bi bi-x-circle me-1"></i>Сбросить
                            </button>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <label for="search" class="form-label">Поиск</label>
                        <input type="text" class="form-control" id="search" name="search" 
                               placeholder="IP, MAC, клиент, описание..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    
                    <div class="col-md-2">
                        <label for="type" class="form-label">Тип</label>
                        <select class="form-select" id="type" name="type">
                            <option value="">Все типы</option>
                            <option value="white" <?php echo $type_filter === 'white' ? 'selected' : ''; ?>>Белые</option>
                            <option value="gray" <?php echo $type_filter === 'gray' ? 'selected' : ''; ?>>Серые</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label for="status" class="form-label">Статус</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">Все статусы</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Активные</option>
                            <option value="free" <?php echo $status_filter === 'free' ? 'selected' : ''; ?>>Свободные</option>
                            <option value="reserved" <?php echo $status_filter === 'reserved' ? 'selected' : ''; ?>>Зарезервированные</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label for="subnet" class="form-label">Подсеть</label>
                        <select class="form-select" id="subnet" name="subnet">
                            <option value="">Все подсети</option>
                            <?php foreach ($all_subnets as $subnet): 
                                $selected = $subnet_filter == $subnet['id'] ? 'selected' : '';
                                $subnet_display = $subnet['network_address'] . '/' . $subnet['cidr_mask'];
                            ?>
                                <option value="<?php echo htmlspecialchars($subnet['id']); ?>" <?php echo $selected; ?>>
                                    <?php echo htmlspecialchars($subnet_display); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label for="sort" class="form-label">Сортировка</label>
                        <select class="form-select" id="sort" name="sort">
                            <option value="ip_desc" <?php echo $sort === 'ip_desc' ? 'selected' : ''; ?>>IP (новые)</option>
                            <option value="ip_asc" <?php echo $sort === 'ip_asc' ? 'selected' : ''; ?>>IP (старые)</option>
                            <option value="client_asc" <?php echo $sort === 'client_asc' ? 'selected' : ''; ?>>Клиент А-Я</option>
                            <option value="client_desc" <?php echo $sort === 'client_desc' ? 'selected' : ''; ?>>Клиент Я-А</option>
                            <option value="updated_desc" <?php echo $sort === 'updated_desc' ? 'selected' : ''; ?>>Обновленные</option>
                        </select>
                    </div>
                    
                    <div class="col-md-1 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-200">
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
                    Показано <strong><?php echo count($ip_addresses); ?></strong> из <strong><?php echo $total_records; ?></strong> записей
                </span>
            </div>
            <div>
                <span class="text-muted">
                    Страница <strong><?php echo $page; ?></strong> из <strong><?php echo $pagination->total_pages; ?></strong>
                </span>
            </div>
        </div>

        <!-- Основная таблица -->
        <div class="card stat-card">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-list-ul me-2"></i>Список IP-адресов
                </h5>
                <span class="badge bg-primary"><?php echo $total_records; ?> всего</span>
            </div>
            <div class="card-body">
                <?php if (empty($ip_addresses)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox display-4 text-muted mb-3"></i>
                        <h5 class="text-muted">IP-адреса не найдены</h5>
                        <p class="text-muted mb-3">Попробуйте изменить параметры фильтрации</p>
                        <?php if ($search || $type_filter || $status_filter || $subnet_filter): ?>
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
                                    <th>IP-адрес</th>
                                    <th>Подсеть</th>
                                    <th>Тип</th>
                                    <th>Статус</th>
                                    <th>Устройство</th>
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
                                            <code class="fw-bold"><?php echo htmlspecialchars($ip['ip_address']); ?></code>
                                        </td>
                                        <td>
                                            <small class="text-muted"><?php echo htmlspecialchars($ip['network_address'] . '/' . $ip['cidr_mask']); ?></small>
                                            <?php if ($ip['subnet_description']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($ip['subnet_description']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $ip['type'] === 'white' ? 'warning' : 'secondary'; ?>">
                                                <i class="bi bi-<?php echo $ip['type'] === 'white' ? 'globe' : 'layers'; ?> me-1"></i>
                                                <?php echo $ip['type'] === 'white' ? 'Белый' : 'Серый'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                echo $ip['status'] === 'active' ? 'success' : 
                                                     ($ip['status'] === 'reserved' ? 'warning' : 'info'); 
                                            ?>">
                                                <i class="bi bi-<?php 
                                                    echo $ip['status'] === 'active' ? 'check-circle' : 
                                                         ($ip['status'] === 'reserved' ? 'shield-lock' : 'circle'); 
                                                ?> me-1"></i>
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
                                            <?php if ($ip['client_name']): ?>
                                                <?php echo htmlspecialchars($ip['client_name']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($ip['description']): ?>
                                                <span title="<?php echo htmlspecialchars($ip['description']); ?>">
                                                    <?php echo mb_strimwidth(htmlspecialchars($ip['description']), 0, 30, '...'); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <?php echo date('d.m.Y H:i', strtotime($ip['created_at'])); ?>
                                                <?php if ($ip['created_by_name']): ?>
                                                    <br><span class="text-muted"><?php echo htmlspecialchars($ip['created_by_name']); ?></span>
                                                <?php endif; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="edit.php?id=<?php echo htmlspecialchars($ip['id']); ?>" 
                                                   class="btn btn-outline-primary" 
                                                   title="Редактировать">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <?php if (hasRole('admin')): ?>
                                                    <a href="delete.php?id=<?php echo htmlspecialchars($ip['id']); ?>" 
                                                       class="btn btn-outline-danger" 
                                                       onclick="return confirm('Удалить IP-адрес <?php echo htmlspecialchars($ip['ip_address']); ?>?')" 
                                                       title="Удалить">
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
                            <i class="bi bi-graph-up me-2"></i>Статистика по IP-адресам
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col">
                                <div class="stat-number text-primary"><?php echo $total_records; ?></div>
                                <div class="stat-label">Всего записей</div>
                            </div>
                            <?php 
                            // Быстрая статистика по текущей странице
                            $active_count = count(array_filter($ip_addresses, fn($ip) => $ip['status'] === 'active'));
                            $free_count = count(array_filter($ip_addresses, fn($ip) => $ip['status'] === 'free'));
                            $white_count = count(array_filter($ip_addresses, fn($ip) => $ip['type'] === 'white'));
                            $gray_count = count(array_filter($ip_addresses, fn($ip) => $ip['type'] === 'gray'));
                            ?>
                            <div class="col">
                                <div class="stat-number text-success"><?php echo $active_count; ?></div>
                                <div class="stat-label">Активных</div>
                            </div>
                            <div class="col">
                                <div class="stat-number text-info"><?php echo $free_count; ?></div>
                                <div class="stat-label">Свободных</div>
                            </div>
                            <div class="col">
                                <div class="stat-number text-warning"><?php echo $white_count; ?></div>
                                <div class="stat-label">Белых IP</div>
                            </div>
                            <div class="col">
                                <div class="stat-number text-secondary"><?php echo $gray_count; ?></div>
                                <div class="stat-label">Серых IP</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function setFilter(filter, value) {
            document.getElementById(filter).value = value;
            document.getElementById('filter-form').submit();
        }

        function clearFilters() {
            window.location.href = 'list.php';
        }

        // Авто-сабмит при изменении сортировки
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('sort').addEventListener('change', function() {
                document.getElementById('filter-form').submit();
            });
        });
    </script>
</body>
</html>