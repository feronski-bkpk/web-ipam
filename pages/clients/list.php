<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
require_once '../../includes/audit_system.php';
require_once '../../includes/pagination.php';
requireAuth();

// Поиск и фильтрация
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'name_asc';

// Пагинация
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 50;

// Базовый запрос для подсчета
$count_sql = "SELECT COUNT(*) as total FROM clients WHERE 1=1";
$count_params = [];
$count_types = "";

// Базовый запрос для данных
$sql = "SELECT * FROM clients WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $search_param = "%$search%";
    $sql .= " AND (full_name LIKE ? OR contract_number LIKE ? OR address LIKE ? OR phone LIKE ?)";
    $count_sql .= " AND (full_name LIKE ? OR contract_number LIKE ? OR address LIKE ? OR phone LIKE ?)";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    $count_params = array_merge($count_params, [$search_param, $search_param, $search_param, $search_param]);
    $types .= "ssss";
    $count_types .= "ssss";
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
    case 'oldest':
        $sql .= " ORDER BY created_at ASC";
        break;
    case 'name_asc':
    default:
        $sql .= " ORDER BY full_name ASC";
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
    error_log("Error counting clients: " . $e->getMessage());
    $total_records = 0;
}

// Выполняем запрос для данных
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

// Получаем количество устройств для каждого клиента
$client_devices = [];
foreach ($clients as $client) {
    $devices_stmt = $conn->prepare("SELECT COUNT(*) as device_count FROM devices WHERE client_id = ?");
    $devices_stmt->bind_param("i", $client['id']);
    $devices_stmt->execute();
    $device_count = $devices_stmt->get_result()->fetch_assoc()['device_count'];
    $devices_stmt->close();
    $client_devices[$client['id']] = $device_count;
}

// Создаем пагинацию
$pagination = new Pagination($total_records, $per_page, $page);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Клиенты - Web-IPAM</title>
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
                        <h1 class="h3 mb-1">Управление клиентами</h1>
                        <p class="text-muted mb-0">Список всех клиентов в системе</p>
                    </div>
                    <?php if (hasAnyRole(['admin', 'engineer'])): ?>
                        <a href="add.php" class="btn btn-primary">
                            <i class="bi bi-plus-circle me-1"></i>Добавить клиента
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

        <!-- Поиск и фильтры -->
        <div class="card stat-card mb-4">
            <div class="card-header bg-transparent">
                <h5 class="card-title mb-0">
                    <i class="bi bi-search me-2"></i>Поиск и фильтрация
                </h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-8">
                        <label for="search" class="form-label">Поиск клиентов</label>
                        <input type="text" class="form-control" id="search" name="search" 
                               placeholder="Поиск по ФИО, договору, адресу или телефону..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="sort" class="form-label">Сортировка</label>
                        <select class="form-select" id="sort" name="sort">
                            <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>ФИО А-Я</option>
                            <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>ФИО Я-А</option>
                            <option value="contract_asc" <?php echo $sort === 'contract_asc' ? 'selected' : ''; ?>>Договор ↑</option>
                            <option value="contract_desc" <?php echo $sort === 'contract_desc' ? 'selected' : ''; ?>>Договор ↓</option>
                            <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Сначала новые</option>
                            <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Сначала старые</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search me-1"></i>Поиск
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Информация о пагинации -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <span class="text-muted">
                    Показано <strong><?php echo count($clients); ?></strong> из <strong><?php echo $total_records; ?></strong> клиентов
                </span>
            </div>
            <div>
                <span class="text-muted">
                    Страница <strong><?php echo $page; ?></strong> из <strong><?php echo $pagination->total_pages; ?></strong>
                </span>
            </div>
        </div>

        <!-- Таблица клиентов -->
        <div class="card stat-card">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-people me-2"></i>Список клиентов
                </h5>
                <span class="badge bg-primary"><?php echo $total_records; ?> всего</span>
            </div>
            <div class="card-body">
                <?php if (empty($clients)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-people display-4 text-muted mb-3"></i>
                        <h5 class="text-muted">Клиенты не найдены</h5>
                        <p class="text-muted mb-3">Попробуйте изменить параметры поиска</p>
                        <?php if ($search): ?>
                            <a href="list.php" class="btn btn-outline-primary">
                                <i class="bi bi-arrow-clockwise me-1"></i>Сбросить поиск
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped">
                            <thead class="table-light">
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
                                    $device_count = $client_devices[$client['id']] ?? 0;
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($client['full_name']); ?></strong>
                                        </td>
                                        <td>
                                            <code class="fw-bold"><?php echo htmlspecialchars($client['contract_number']); ?></code>
                                        </td>
                                        <td>
                                            <span title="<?php echo htmlspecialchars($client['address']); ?>">
                                                <?php echo mb_strimwidth(htmlspecialchars($client['address']), 0, 40, '...'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($client['phone']): ?>
                                                <code><?php echo htmlspecialchars($client['phone']); ?></code>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $device_count > 0 ? 'info' : 'secondary'; ?>">
                                                <i class="bi bi-hdd me-1"></i><?php echo $device_count; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <?php echo date('d.m.Y', strtotime($client['created_at'])); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="edit.php?id=<?php echo htmlspecialchars($client['id']); ?>" 
                                                   class="btn btn-outline-primary" 
                                                   title="Редактировать клиента">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <a href="../devices/list.php?client_id=<?php echo htmlspecialchars($client['id']); ?>" 
                                                   class="btn btn-outline-info" 
                                                   title="Устройства клиента">
                                                    <i class="bi bi-hdd"></i>
                                                </a>
                                                <?php if (hasRole('admin') && $device_count == 0): ?>
                                                    <a href="delete.php?id=<?php echo htmlspecialchars($client['id']); ?>" 
                                                       class="btn btn-outline-danger" 
                                                       onclick="return confirm('Удалить клиента <?php echo htmlspecialchars($client['full_name']); ?>?')"
                                                       title="Удалить клиента">
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
                            <i class="bi bi-graph-up me-2"></i>Статистика по клиентам
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col">
                                <div class="stat-number text-primary"><?php echo $total_records; ?></div>
                                <div class="stat-label">Всего клиентов</div>
                            </div>
                            <div class="col">
                                <div class="stat-number text-success"><?php echo count(array_filter($client_devices, fn($count) => $count > 0)); ?></div>
                                <div class="stat-label">С устройствами</div>
                            </div>
                            <div class="col">
                                <div class="stat-number text-info"><?php echo count(array_filter($client_devices, fn($count) => $count == 0)); ?></div>
                                <div class="stat-label">Без устройств</div>
                            </div>
                            <div class="col">
                                <div class="stat-number text-warning"><?php echo array_sum($client_devices); ?></div>
                                <div class="stat-label">Всего устройств</div>
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