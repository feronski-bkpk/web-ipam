<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
require_once '../../includes/audit_system.php';
requireAuth();
requireRole('admin');

$search = $_GET['search'] ?? '';
$role_filter = $_GET['role'] ?? '';
$sort = $_GET['sort'] ?? 'name_asc';

$sql = "SELECT id, login, role, full_name, created_at, last_login, failed_login_attempts, account_locked_until FROM users WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $sql .= " AND (login LIKE ? OR full_name LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param]);
    $types .= "ss";
}

if (!empty($role_filter)) {
    $sql .= " AND role = ?";
    $params[] = $role_filter;
    $types .= "s";
}

switch ($sort) {
    case 'name_desc':
        $sql .= " ORDER BY full_name DESC";
        break;
    case 'login_asc':
        $sql .= " ORDER BY login ASC";
        break;
    case 'login_desc':
        $sql .= " ORDER BY login DESC";
        break;
    case 'newest':
        $sql .= " ORDER BY created_at DESC";
        break;
    case 'last_login':
        $sql .= " ORDER BY last_login DESC";
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
    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    error_log("Error fetching users: " . $e->getMessage());
    $users = [];
}

// Статистика по ролям
$role_stats = [];
try {
    $stats_stmt = $conn->prepare("SELECT role, COUNT(*) as count FROM users GROUP BY role");
    $stats_stmt->execute();
    $role_stats_result = $stats_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stats_stmt->close();
    
    foreach ($role_stats_result as $stat) {
        $role_stats[$stat['role']] = $stat['count'];
    }
} catch (Exception $e) {
    error_log("Error fetching role statistics: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Пользователи системы - Web-IPAM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link href="../../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    
    <div class="container mt-4">
        <!-- Заголовок -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="h3 mb-1">Управление пользователями</h1>
                        <p class="text-muted mb-0">Список пользователей системы и управление ролями</p>
                    </div>
                    <div>
                        <a href="add.php" class="btn btn-primary">
                            <i class="bi bi-plus-circle me-1"></i>Добавить пользователя
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Статистика по ролям -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card stat-card border-start border-danger border-4">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <div class="stat-number text-danger"><?php echo $role_stats['admin'] ?? 0; ?></div>
                                <div class="stat-label">Администраторов</div>
                            </div>
                            <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                                <i class="bi bi-shield-check"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card stat-card border-start border-warning border-4">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <div class="stat-number text-warning"><?php echo $role_stats['engineer'] ?? 0; ?></div>
                                <div class="stat-label">Инженеров</div>
                            </div>
                            <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                                <i class="bi bi-tools"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card stat-card border-start border-info border-4">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <div class="stat-number text-info"><?php echo $role_stats['operator'] ?? 0; ?></div>
                                <div class="stat-label">Операторов</div>
                            </div>
                            <div class="stat-icon bg-info bg-opacity-10 text-info">
                                <i class="bi bi-eye"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card stat-card border-start border-primary border-4">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <div class="stat-number text-primary"><?php echo count($users); ?></div>
                                <div class="stat-label">Всего пользователей</div>
                            </div>
                            <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                                <i class="bi bi-people"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Поиск и фильтры -->
        <div class="card stat-card mb-4">
            <div class="card-header bg-transparent">
                <h5 class="card-title mb-0">
                    <i class="bi bi-funnel me-2"></i>Фильтры и поиск
                </h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-5">
                        <label for="search" class="form-label">Поиск</label>
                        <input type="text" class="form-control" id="search" name="search" 
                               placeholder="Поиск по логину или ФИО..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="role" class="form-label">Роль</label>
                        <select class="form-select" id="role" name="role">
                            <option value="">Все роли</option>
                            <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>Администратор</option>
                            <option value="engineer" <?php echo $role_filter === 'engineer' ? 'selected' : ''; ?>>Инженер</option>
                            <option value="operator" <?php echo $role_filter === 'operator' ? 'selected' : ''; ?>>Оператор</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="sort" class="form-label">Сортировка</label>
                        <select class="form-select" id="sort" name="sort">
                            <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>ФИО А-Я</option>
                            <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>ФИО Я-А</option>
                            <option value="login_asc" <?php echo $sort === 'login_asc' ? 'selected' : ''; ?>>Логин А-Я</option>
                            <option value="login_desc" <?php echo $sort === 'login_desc' ? 'selected' : ''; ?>>Логин Я-А</option>
                            <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Сначала новые</option>
                            <option value="last_login" <?php echo $sort === 'last_login' ? 'selected' : ''; ?>>Последний вход</option>
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

        <!-- Таблица пользователей -->
        <div class="card stat-card">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-people me-2"></i>Список пользователей
                </h5>
                <span class="badge bg-primary"><?php echo count($users); ?> пользователей</span>
            </div>
            <div class="card-body">
                <?php if (empty($users)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-people display-4 text-muted mb-3"></i>
                        <h5 class="text-muted">Пользователи не найдены</h5>
                        <p class="text-muted mb-3">Попробуйте изменить параметры поиска</p>
                        <?php if ($search || $role_filter): ?>
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
                                    <th>ФИО</th>
                                    <th>Логин</th>
                                    <th>Роль</th>
                                    <th>Статус</th>
                                    <th>Последний вход</th>
                                    <th>Дата создания</th>
                                    <th>Действия</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): 
                                    $status_class = '';
                                    $status_text = 'Активен';
                                    $status_icon = 'check-circle';
                                    
                                    if ($user['account_locked_until'] && strtotime($user['account_locked_until']) > time()) {
                                        $status_class = 'user-status-locked';
                                        $status_text = 'Заблокирован';
                                        $status_icon = 'lock-fill';
                                    } elseif ($user['failed_login_attempts'] > 0) {
                                        $status_class = 'user-status-warning';
                                        $status_text = 'Неудачные попытки: ' . $user['failed_login_attempts'];
                                        $status_icon = 'exclamation-triangle';
                                    } else {
                                        $status_class = 'user-status-active';
                                        $status_icon = 'check-circle';
                                    }
                                    
                                    $role_badge_class = 'role-badge-' . $user['role'];
                                    $role_names = [
                                        'admin' => 'Администратор',
                                        'engineer' => 'Инженер', 
                                        'operator' => 'Оператор'
                                    ];
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($user['full_name']); ?></strong>
                                            <?php if ($user['id'] == $_SESSION['user_id']): ?>
                                                <span class="badge bg-secondary ms-1">Вы</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <code><?php echo htmlspecialchars($user['login']); ?></code>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $role_badge_class; ?>">
                                                <i class="bi bi-<?php 
                                                    echo $user['role'] === 'admin' ? 'shield-check' : 
                                                         ($user['role'] === 'engineer' ? 'tools' : 'eye'); 
                                                ?> me-1"></i>
                                                <?php echo $role_names[$user['role']] ?? $user['role']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="<?php echo $status_class; ?>">
                                                <i class="bi bi-<?php echo $status_icon; ?> me-1"></i>
                                                <?php echo $status_text; ?>
                                            </span>
                                            <?php if ($user['account_locked_until'] && strtotime($user['account_locked_until']) > time()): ?>
                                                <br>
                                                <small class="text-muted">
                                                    До: <?php echo date('H:i', strtotime($user['account_locked_until'])); ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($user['last_login']): ?>
                                                <small><?php echo date('d.m.Y H:i', strtotime($user['last_login'])); ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small><?php echo date('d.m.Y', strtotime($user['created_at'])); ?></small>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="edit.php?id=<?php echo $user['id']; ?>" class="btn btn-outline-primary" title="Редактировать">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                    <a href="delete.php?id=<?php echo $user['id']; ?>" class="btn btn-outline-danger" 
                                                       onclick="return confirm('Удалить пользователя <?php echo htmlspecialchars($user['full_name']); ?>?')"
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