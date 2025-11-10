<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
require_once '../../includes/audit_system.php';
require_once '../../includes/pagination.php';
requireAuth();

$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'network_asc';

// Пагинация
$current_page = max(1, intval($_GET['page'] ?? 1));
$items_per_page = 10;

// Базовый запрос для подсчета
$count_sql = "SELECT COUNT(*) as total FROM subnets WHERE 1=1";
$count_params = [];
$count_types = "";

if (!empty($search)) {
    $count_sql .= " AND (network_address LIKE ? OR description LIKE ? OR gateway LIKE ?)";
    $search_param = "%$search%";
    $count_params = array_merge($count_params, [$search_param, $search_param, $search_param]);
    $count_types .= "sss";
}

// Получаем общее количество
$total_items = 0;
try {
    $count_stmt = $conn->prepare($count_sql);
    if (!empty($count_params)) {
        $count_stmt->bind_param($count_types, ...$count_params);
    }
    $count_stmt->execute();
    $count_result = $count_stmt->get_result()->fetch_assoc();
    $total_items = $count_result['total'];
    $count_stmt->close();
} catch (Exception $e) {
    error_log("Error counting subnets: " . $e->getMessage());
}

// Создаем пагинацию
$pagination = new Pagination($total_items, $items_per_page, $current_page);

// Основной запрос с пагинацией
$sql = "SELECT * FROM subnets WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $sql .= " AND (network_address LIKE ? OR description LIKE ? OR gateway LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param]);
    $types .= "sss";
}

// ПРАВИЛЬНАЯ сортировка
switch ($sort) {
    case 'network_desc':
        $sql .= " ORDER BY INET_ATON(network_address) DESC";
        break;
    case 'mask_asc':
        $sql .= " ORDER BY cidr_mask ASC, INET_ATON(network_address) ASC";
        break;
    case 'mask_desc':
        $sql .= " ORDER BY cidr_mask DESC, INET_ATON(network_address) ASC";
        break;
    case 'newest':
        $sql .= " ORDER BY created_at DESC";
        break;
    case 'oldest':
        $sql .= " ORDER BY created_at ASC";
        break;
    case 'network_asc':
    default:
        $sql .= " ORDER BY INET_ATON(network_address) ASC";
        break;
}

// Добавляем пагинацию (исправленная строка)
$sql .= " " . $pagination->getLimit();

try {
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $subnets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    error_log("Error fetching subnets: " . $e->getMessage());
    $subnets = [];
}

// Получаем статистику по IP для каждой подсети
foreach ($subnets as &$subnet) {
    $stats_stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_ips,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_ips,
            SUM(CASE WHEN status = 'free' THEN 1 ELSE 0 END) as free_ips,
            SUM(CASE WHEN type = 'white' THEN 1 ELSE 0 END) as white_ips,
            SUM(CASE WHEN type = 'gray' THEN 1 ELSE 0 END) as gray_ips
        FROM ip_addresses 
        WHERE subnet_id = ?
    ");
    $stats_stmt->bind_param("i", $subnet['id']);
    $stats_stmt->execute();
    $stats = $stats_stmt->get_result()->fetch_assoc();
    $stats_stmt->close();
    
    $subnet['stats'] = $stats;
    $subnet['usage_percent'] = $stats['total_ips'] > 0 ? round(($stats['active_ips'] / $stats['total_ips']) * 100, 1) : 0;
}

// Генерируем URL для пагинации
$pagination_url = '?' . http_build_query(array_merge($_GET, ['page' => '{page}']));
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Подсети - Web-IPAM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link href="../../assets/css/style.css" rel="stylesheet">
    <style>
        .progress {
            height: 20px;
        }
        .usage-low { background-color: #28a745; }
        .usage-medium { background-color: #ffc107; }
        .usage-high { background-color: #dc3545; }
    </style>
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    
    <div class="container mt-4">
        <!-- Заголовок и кнопки -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="h3 mb-1">Управление подсетями</h1>
                        <p class="text-muted mb-0">Список всех сетевых подсетей в системе</p>
                    </div>
                    <?php if (hasAnyRole(['admin', 'engineer'])): ?>
                        <a href="add.php" class="btn btn-primary">
                            <i class="bi bi-plus-circle me-1"></i>Добавить подсеть
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Информация о пагинации -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <span class="text-muted">
                    Показано <strong><?php echo count($subnets); ?></strong> из <strong><?php echo $total_items; ?></strong> подсетей
                </span>
            </div>
            <div>
                <span class="text-muted">
                    Страница <strong><?php echo $pagination->getCurrentPage(); ?></strong> из <strong><?php echo $pagination->getTotalPages(); ?></strong>
                </span>
            </div>
        </div>

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
                        <label for="search" class="form-label">Поиск подсетей</label>
                        <input type="text" class="form-control" id="search" name="search" 
                               placeholder="Поиск по адресу сети, описанию или шлюзу..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="sort" class="form-label">Сортировка</label>
                        <select class="form-select" id="sort" name="sort">
                            <option value="network_asc" <?php echo $sort === 'network_asc' ? 'selected' : ''; ?>>Сеть ↑</option>
                            <option value="network_desc" <?php echo $sort === 'network_desc' ? 'selected' : ''; ?>>Сеть ↓</option>
                            <option value="mask_asc" <?php echo $sort === 'mask_asc' ? 'selected' : ''; ?>>Маска ↑</option>
                            <option value="mask_desc" <?php echo $sort === 'mask_desc' ? 'selected' : ''; ?>>Маска ↓</option>
                            <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Сначала новые</option>
                            <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Сначала старые</option>
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

        <!-- Таблица подсетей -->
        <div class="card stat-card">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-diagram-3 me-2"></i>Список подсетей
                </h5>
                <span class="badge bg-primary"><?php echo $total_items; ?> всего</span>
            </div>
            <div class="card-body">
                <?php if (empty($subnets)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-diagram-3 display-4 text-muted mb-3"></i>
                        <h5 class="text-muted">Подсети не найдены</h5>
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
                                    <th>Подсеть</th>
                                    <th>Шлюз</th>
                                    <th>Описание</th>
                                    <th>Статистика IP</th>
                                    <th>Использование</th>
                                    <th>Дата создания</th>
                                    <th>Действия</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($subnets as $subnet): 
                                    $usage_class = $subnet['usage_percent'] > 80 ? 'usage-high' : 
                                                  ($subnet['usage_percent'] > 60 ? 'usage-medium' : 'usage-low');
                                ?>
                                    <tr>
                                        <td>
                                            <code class="fw-bold"><?php echo htmlspecialchars($subnet['network_address']); ?>/<?php echo $subnet['cidr_mask']; ?></code>
                                        </td>
                                        <td>
                                            <?php if ($subnet['gateway']): ?>
                                                <code><?php echo htmlspecialchars($subnet['gateway']); ?></code>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($subnet['description']): ?>
                                                <span title="<?php echo htmlspecialchars($subnet['description']); ?>">
                                                    <?php echo mb_strimwidth(htmlspecialchars($subnet['description']), 0, 40, '...'); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="small">
                                                <div><strong><?php echo $subnet['stats']['total_ips']; ?></strong> всего</div>
                                                <div>
                                                    <span class="badge bg-success">
                                                        <i class="bi bi-check-circle me-1"></i><?php echo $subnet['stats']['active_ips']; ?>
                                                    </span> активных
                                                </div>
                                                <div>
                                                    <span class="badge bg-info">
                                                        <i class="bi bi-circle me-1"></i><?php echo $subnet['stats']['free_ips']; ?>
                                                    </span> свободных
                                                </div>
                                            </div>
                                        </td>
                                        <td style="width: 200px;">
                                            <div class="progress">
                                                <div class="progress-bar <?php echo $usage_class; ?>" 
                                                     style="width: <?php echo $subnet['usage_percent']; ?>%">
                                                    <?php echo $subnet['usage_percent']; ?>%
                                                </div>
                                            </div>
                                            <small class="text-muted">
                                                <?php echo $subnet['stats']['active_ips']; ?> из <?php echo $subnet['stats']['total_ips']; ?> IP
                                            </small>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <?php echo date('d.m.Y', strtotime($subnet['created_at'])); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="view.php?id=<?php echo htmlspecialchars($subnet['id']); ?>" 
                                                   class="btn btn-outline-info" 
                                                   title="Просмотр подсети">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <?php if (hasAnyRole(['admin', 'engineer'])): ?>
                                                    <a href="edit.php?id=<?php echo htmlspecialchars($subnet['id']); ?>" 
                                                       class="btn btn-outline-primary" 
                                                       title="Редактировать подсеть">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <?php if (hasRole('admin') && $subnet['stats']['total_ips'] == 0): ?>
                                                    <a href="delete.php?id=<?php echo htmlspecialchars($subnet['id']); ?>" 
                                                       class="btn btn-outline-danger" 
                                                       onclick="return confirm('Удалить подсеть <?php echo htmlspecialchars($subnet['network_address']); ?>/<?php echo $subnet['cidr_mask']; ?>?')"
                                                       title="Удалить подсеть">
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
                    <?php if ($pagination->getTotalPages() > 1): ?>
                    <div class="d-flex justify-content-between align-items-center mt-4">
                        <div>
                            <span class="text-muted">
                                Страница <?php echo $pagination->getCurrentPage(); ?> из <?php echo $pagination->getTotalPages(); ?>
                            </span>
                        </div>
                        <nav>
                            <ul class="pagination mb-0">
                                <?php if ($pagination->has_previous): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?php echo $pagination->get_page_url($pagination->getCurrentPage() - 1); ?>">
                                            <i class="bi bi-chevron-left"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>

                                <?php foreach ($pagination->get_pages() as $p): ?>
                                    <li class="page-item <?php echo $p == $pagination->getCurrentPage() ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo $pagination->get_page_url($p); ?>">
                                            <?php echo $p; ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>

                                <?php if ($pagination->has_next): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?php echo $pagination->get_page_url($pagination->getCurrentPage() + 1); ?>">
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
                            <i class="bi bi-graph-up me-2"></i>Общая статистика по подсетям
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <?php
                            $total_ips = array_sum(array_column(array_column($subnets, 'stats'), 'total_ips'));
                            $active_ips = array_sum(array_column(array_column($subnets, 'stats'), 'active_ips'));
                            $free_ips = array_sum(array_column(array_column($subnets, 'stats'), 'free_ips'));
                            $white_ips = array_sum(array_column(array_column($subnets, 'stats'), 'white_ips'));
                            $gray_ips = array_sum(array_column(array_column($subnets, 'stats'), 'gray_ips'));
                            $total_usage = $total_ips > 0 ? round(($active_ips / $total_ips) * 100, 1) : 0;
                            ?>
                            <div class="col">
                                <div class="stat-number text-primary"><?php echo $total_items; ?></div>
                                <div class="stat-label">Всего подсетей</div>
                            </div>
                            <div class="col">
                                <div class="stat-number text-info"><?php echo $total_ips; ?></div>
                                <div class="stat-label">Всего IP</div>
                            </div>
                            <div class="col">
                                <div class="stat-number text-success"><?php echo $active_ips; ?></div>
                                <div class="stat-label">Активных IP</div>
                            </div>
                            <div class="col">
                                <div class="stat-number text-warning"><?php echo $free_ips; ?></div>
                                <div class="stat-label">Свободных IP</div>
                            </div>
                            <div class="col">
                                <div class="stat-number text-secondary"><?php echo $total_usage; ?>%</div>
                                <div class="stat-label">Общая загрузка</div>
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