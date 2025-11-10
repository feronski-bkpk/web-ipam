<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
require_once '../../includes/audit_system.php';
requireAuth();

// Проверяем ID подсети
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: list.php');
    exit();
}

$subnet_id = intval($_GET['id']);

// Получаем данные подсети
try {
    $subnet_stmt = $conn->prepare("SELECT * FROM subnets WHERE id = ?");
    $subnet_stmt->bind_param("i", $subnet_id);
    $subnet_stmt->execute();
    $subnet = $subnet_stmt->get_result()->fetch_assoc();
    $subnet_stmt->close();
    
    if (!$subnet) {
        header('Location: list.php');
        exit();
    }
} catch (Exception $e) {
    error_log("Error fetching subnet data: " . $e->getMessage());
    header('Location: list.php');
    exit();
}

// Получаем IP-адреса в подсети
try {
    $ips_stmt = $conn->prepare("
        SELECT 
            ip.*,
            d.mac_address, d.model,
            c.full_name as client_name
        FROM ip_addresses ip
        LEFT JOIN devices d ON ip.device_id = d.id
        LEFT JOIN clients c ON d.client_id = c.id
        WHERE ip.subnet_id = ?
        ORDER BY INET_ATON(ip.ip_address)
    ");
    $ips_stmt->bind_param("i", $subnet_id);
    $ips_stmt->execute();
    $ip_addresses = $ips_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $ips_stmt->close();
} catch (Exception $e) {
    error_log("Error fetching IP addresses: " . $e->getMessage());
    $ip_addresses = [];
}

// Статистика
$stats = [
    'total' => count($ip_addresses),
    'active' => count(array_filter($ip_addresses, fn($ip) => $ip['status'] === 'active')),
    'free' => count(array_filter($ip_addresses, fn($ip) => $ip['status'] === 'free')),
    'reserved' => count(array_filter($ip_addresses, fn($ip) => $ip['status'] === 'reserved')),
    'white' => count(array_filter($ip_addresses, fn($ip) => $ip['type'] === 'white')),
    'gray' => count(array_filter($ip_addresses, fn($ip) => $ip['type'] === 'gray'))
];

$usage_percent = $stats['total'] > 0 ? round(($stats['active'] / $stats['total']) * 100, 1) : 0;
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Просмотр подсети - Web-IPAM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link href="../../assets/css/style.css" rel="stylesheet">
    <style>
        .ip-status-active { background-color: rgba(25, 135, 84, 0.05); }
        .ip-status-free { background-color: rgba(108, 117, 125, 0.05); }
        .ip-status-reserved { background-color: rgba(255, 193, 7, 0.05); }
        .ip-table td { vertical-align: middle; }
        .subnet-header { border-left: 4px solid #3498db; }
    </style>
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    
    <div class="container mt-4">
        <!-- Заголовок -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="h3 mb-1">Просмотр подсети</h1>
                        <p class="text-muted mb-0">Детальная информация о подсети и её IP-адресах</p>
                    </div>
                    <div>
                        <a href="list.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i>Назад к списку
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Основная информация о подсети -->
        <div class="row mb-4">
            <div class="col-lg-8">
                <div class="card stat-card subnet-header">
                    <div class="card-header bg-transparent">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-diagram-3 me-2"></i>Основная информация
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="text-muted" style="width: 120px;">Подсеть:</td>
                                        <td><code class="fs-5"><?php echo htmlspecialchars($subnet['network_address']); ?>/<?php echo $subnet['cidr_mask']; ?></code></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Шлюз:</td>
                                        <td>
                                            <?php if ($subnet['gateway']): ?>
                                                <code><?php echo htmlspecialchars($subnet['gateway']); ?></code>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="text-muted" style="width: 120px;">Описание:</td>
                                        <td><?php echo $subnet['description'] ? htmlspecialchars($subnet['description']) : '<span class="text-muted">—</span>'; ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Создана:</td>
                                        <td><?php echo date('d.m.Y H:i', strtotime($subnet['created_at'])); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4">
                <div class="card stat-card">
                    <div class="card-header bg-transparent">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-graph-up me-2"></i>Статистика использования
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted">Использование:</span>
                                <strong><?php echo $usage_percent; ?>%</strong>
                            </div>
                            <div class="progress usage-progress">
                                <div class="progress-bar 
                                    <?php echo $usage_percent > 80 ? 'bg-danger' : 
                                          ($usage_percent > 60 ? 'bg-warning' : 'bg-success'); ?>" 
                                     style="width: <?php echo $usage_percent; ?>%">
                                </div>
                            </div>
                            <div class="text-center mt-1">
                                <small class="text-muted"><?php echo $stats['active']; ?> / <?php echo $stats['total']; ?> IP-адресов</small>
                            </div>
                        </div>
                        
                        <div class="row text-center">
                            <div class="col-4">
                                <div class="stat-number text-success"><?php echo $stats['active']; ?></div>
                                <div class="stat-label">Активных</div>
                            </div>
                            <div class="col-4">
                                <div class="stat-number text-info"><?php echo $stats['free']; ?></div>
                                <div class="stat-label">Свободных</div>
                            </div>
                            <div class="col-4">
                                <div class="stat-number text-warning"><?php echo $stats['reserved']; ?></div>
                                <div class="stat-label">Резерв</div>
                            </div>
                        </div>
                        
                        <div class="row text-center mt-3">
                            <div class="col-6">
                                <div class="stat-number text-warning"><?php echo $stats['white']; ?></div>
                                <div class="stat-label">Белых IP</div>
                            </div>
                            <div class="col-6">
                                <div class="stat-number text-secondary"><?php echo $stats['gray']; ?></div>
                                <div class="stat-label">Серых IP</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Таблица IP-адресов -->
        <div class="card stat-card">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-list-ul me-2"></i>IP-адреса в подсети
                </h5>
                <div>
                    <span class="badge bg-primary me-2"><?php echo $stats['total']; ?> всего</span>
                    <?php if (hasAnyRole(['admin', 'engineer'])): ?>
                        <a href="../ip-addresses/add.php?subnet_id=<?php echo $subnet_id; ?>" class="btn btn-primary btn-sm">
                            <i class="bi bi-plus-circle me-1"></i>Добавить IP
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($ip_addresses)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox display-4 text-muted mb-3"></i>
                        <h5 class="text-muted">В подсети нет IP-адресов</h5>
                        <p class="text-muted mb-3">Начните с добавления первого IP-адреса в эту подсеть</p>
                        <?php if (hasAnyRole(['admin', 'engineer'])): ?>
                            <a href="../ip-addresses/add.php?subnet_id=<?php echo $subnet_id; ?>" class="btn btn-primary">
                                <i class="bi bi-plus-circle me-1"></i>Добавить первый IP-адрес
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover ip-table">
                            <thead class="table-light">
                                <tr>
                                    <th>IP-адрес</th>
                                    <th>Тип</th>
                                    <th>Статус</th>
                                    <th>Устройство</th>
                                    <th>Клиент</th>
                                    <th>Описание</th>
                                    <th>Действия</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ip_addresses as $ip): 
                                    $status_class = 'ip-status-' . $ip['status'];
                                ?>
                                    <tr class="<?php echo $status_class; ?>">
                                        <td>
                                            <code class="fw-bold"><?php echo htmlspecialchars($ip['ip_address']); ?></code>
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
                                            <?php echo $ip['client_name'] ? htmlspecialchars($ip['client_name']) : '<span class="text-muted">—</span>'; ?>
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
                                            <div class="btn-group btn-group-sm">
                                                <a href="../ip-addresses/edit.php?id=<?php echo $ip['id']; ?>" 
                                                   class="btn btn-outline-primary" title="Редактировать">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <?php if (hasRole('admin')): ?>
                                                    <a href="../ip-addresses/delete.php?id=<?php echo $ip['id']; ?>" 
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
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>