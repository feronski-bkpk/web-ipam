<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
requireAuth();

// Получаем список IP-адресов с JOIN для связанных данных
try {
    $stmt = $conn->prepare("
        SELECT 
            ip.*,
            s.network_address, s.cidr_mask, s.description as subnet_description,
            d.mac_address, d.model,
            c.full_name as client_name,
            u.full_name as created_by_name
        FROM ip_addresses ip
        LEFT JOIN subnets s ON ip.subnet_id = s.id
        LEFT JOIN devices d ON ip.device_id = d.id
        LEFT JOIN clients c ON d.client_id = c.id
        LEFT JOIN users u ON ip.created_by = u.id
        ORDER BY ip.created_at DESC
    ");
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
                    <h1>📡 Управление IP-адресами</h1>
                    <?php if (hasAnyRole(['admin', 'engineer'])): ?>
                        <a href="add.php" class="btn btn-primary">+ Добавить IP-адрес</a>
                    <?php endif; ?>
                </div>

                <!-- Фильтры и поиск -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label for="search" class="form-label">Поиск</label>
                                <input type="text" class="form-control" id="search" name="search" 
                                       placeholder="IP, MAC, клиент..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                            </div>
                            <div class="col-md-2">
                                <label for="type" class="form-label">Тип</label>
                                <select class="form-select" id="type" name="type">
                                    <option value="">Все</option>
                                    <option value="white" <?php echo ($_GET['type'] ?? '') === 'white' ? 'selected' : ''; ?>>Белые</option>
                                    <option value="gray" <?php echo ($_GET['type'] ?? '') === 'gray' ? 'selected' : ''; ?>>Серые</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="status" class="form-label">Статус</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="">Все</option>
                                    <option value="active" <?php echo ($_GET['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Активные</option>
                                    <option value="free" <?php echo ($_GET['status'] ?? '') === 'free' ? 'selected' : ''; ?>>Свободные</option>
                                    <option value="reserved" <?php echo ($_GET['status'] ?? '') === 'reserved' ? 'selected' : ''; ?>>Зарезервированные</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="subnet" class="form-label">Подсеть</label>
                                <select class="form-select" id="subnet" name="subnet">
                                    <option value="">Все подсети</option>
                                    <?php
                                    $subnets_stmt = $conn->prepare("SELECT id, network_address, cidr_mask FROM subnets ORDER BY network_address");
                                    $subnets_stmt->execute();
                                    $subnets = $subnets_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                                    foreach ($subnets as $subnet): 
                                        $selected = ($_GET['subnet'] ?? '') == $subnet['id'] ? 'selected' : '';
                                        $subnet_display = $subnet['network_address'] . '/' . $subnet['cidr_mask'];
                                    ?>
                                        <option value="<?php echo $subnet['id']; ?>" <?php echo $selected; ?>>
                                            <?php echo htmlspecialchars($subnet_display); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-outline-primary w-100">Применить</button>
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
                                                        <?php echo $ip['type'] === 'white' ? '⚪ Белый' : '⚫ Серый'; ?>
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
                                                               onclick="return confirm('Удалить IP-адрес?')" title="Удалить">
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