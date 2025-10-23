<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
require_once '../../includes/audit_system.php';
requireAuth();

$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'network_asc';

$sql = "SELECT * FROM subnets WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $sql .= " AND (network_address LIKE ? OR description LIKE ? OR gateway LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param]);
    $types .= "sss";
}

switch ($sort) {
    case 'network_desc':
        $sql .= " ORDER BY network_address DESC";
        break;
    case 'mask_asc':
        $sql .= " ORDER BY cidr_mask ASC";
        break;
    case 'mask_desc':
        $sql .= " ORDER BY cidr_mask DESC";
        break;
    case 'newest':
        $sql .= " ORDER BY created_at DESC";
        break;
    case 'network_asc':
    default:
        $sql .= " ORDER BY network_address ASC";
        break;
}

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
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Подсети - Web-IPAM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
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
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1>Управление подсетями</h1>
                    <?php if (hasAnyRole(['admin', 'engineer'])): ?>
                        <a href="add.php" class="btn btn-primary">Добавить подсеть</a>
                    <?php endif; ?>
                </div>

                <!-- Поиск и фильтры -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-8">
                                <input type="text" class="form-control" name="search" 
                                       placeholder="Поиск по адресу сети, описанию или шлюзу..." 
                                       value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="col-md-2">
                                <select class="form-select" name="sort">
                                    <option value="network_asc" <?php echo $sort === 'network_asc' ? 'selected' : ''; ?>>Сеть ↑</option>
                                    <option value="network_desc" <?php echo $sort === 'network_desc' ? 'selected' : ''; ?>>Сеть ↓</option>
                                    <option value="mask_asc" <?php echo $sort === 'mask_asc' ? 'selected' : ''; ?>>Маска ↑</option>
                                    <option value="mask_desc" <?php echo $sort === 'mask_desc' ? 'selected' : ''; ?>>Маска ↓</option>
                                    <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Сначала новые</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">Поиск</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Таблица подсетей -->
                <div class="card">
                    <div class="card-body">
                        <?php if (empty($subnets)): ?>
                            <div class="text-center py-4">
                                <p class="text-muted">Подсети не найдены</p>
                                <?php if ($search): ?>
                                    <a href="list.php" class="btn btn-outline-secondary">Сбросить поиск</a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
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
                                                    <code><?php echo htmlspecialchars($subnet['network_address']); ?>/<?php echo $subnet['cidr_mask']; ?></code>
                                                    <br><small class="text-muted"><?php echo $subnet['cidr_mask']; ?> бит</small>
                                                </td>
                                                <td>
                                                    <?php if ($subnet['gateway']): ?>
                                                        <code><?php echo htmlspecialchars($subnet['gateway']); ?></code>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo $subnet['description'] ? htmlspecialchars($subnet['description']) : '<span class="text-muted">—</span>'; ?>
                                                </td>
                                                <td>
                                                    <div class="small">
                                                        <div>Всего: <strong><?php echo $subnet['stats']['total_ips']; ?></strong></div>
                                                        <div>Активных: <span class="badge bg-success"><?php echo $subnet['stats']['active_ips']; ?></span></div>
                                                        <div>Свободных: <span class="badge bg-info"><?php echo $subnet['stats']['free_ips']; ?></span></div>
                                                        <div>Белых: <span class="badge bg-warning"><?php echo $subnet['stats']['white_ips']; ?></span></div>
                                                        <div>Серых: <span class="badge bg-secondary"><?php echo $subnet['stats']['gray_ips']; ?></span></div>
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
                                                    <small><?php echo date('d.m.Y', strtotime($subnet['created_at'])); ?></small>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="view.php?id=<?php echo $subnet['id']; ?>" class="btn btn-outline-info" title="Просмотр">👁️</a>
                                                        <?php if (hasAnyRole(['admin', 'engineer'])): ?>
                                                            <a href="edit.php?id=<?php echo $subnet['id']; ?>" class="btn btn-outline-primary">✏️</a>
                                                        <?php endif; ?>
                                                        <?php if (hasRole('admin') && $subnet['stats']['total_ips'] == 0): ?>
                                                            <a href="delete.php?id=<?php echo $subnet['id']; ?>" class="btn btn-outline-danger" 
                                                               onclick="return confirm('Удалить подсеть <?php echo htmlspecialchars($subnet['network_address']); ?>/<?php echo $subnet['cidr_mask']; ?>?')">🗑️</a>
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
                                <h6>Общая статистика по подсетям</h6>
                                <div class="row text-center">
                                    <div class="col">
                                        <small class="text-muted">Всего подсетей</small>
                                        <h5><?php echo count($subnets); ?></h5>
                                    </div>
                                    <div class="col">
                                        <small class="text-muted">Всего IP</small>
                                        <h5><?php echo array_sum(array_column(array_column($subnets, 'stats'), 'total_ips')); ?></h5>
                                    </div>
                                    <div class="col">
                                        <small class="text-muted">Активных IP</small>
                                        <h5><?php echo array_sum(array_column(array_column($subnets, 'stats'), 'active_ips')); ?></h5>
                                    </div>
                                    <div class="col">
                                        <small class="text-muted">Свободных IP</small>
                                        <h5><?php echo array_sum(array_column(array_column($subnets, 'stats'), 'free_ips')); ?></h5>
                                    </div>
                                    <div class="col">
                                        <small class="text-muted">Общая загрузка</small>
                                        <h5>
                                            <?php
                                            $total_ips = array_sum(array_column(array_column($subnets, 'stats'), 'total_ips'));
                                            $active_ips = array_sum(array_column(array_column($subnets, 'stats'), 'active_ips'));
                                            echo $total_ips > 0 ? round(($active_ips / $total_ips) * 100, 1) . '%' : '0%';
                                            ?>
                                        </h5>
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