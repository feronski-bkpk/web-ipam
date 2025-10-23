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
    <style>
        .user-status-active { color: #198754; }
        .user-status-locked { color: #dc3545; }
        .user-status-warning { color: #fd7e14; }
        .role-badge-admin { background-color: #dc3545; }
        .role-badge-engineer { background-color: #fd7e14; }
        .role-badge-operator { background-color: #0dcaf0; }
    </style>
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    
    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1>Управление пользователями системы</h1>
                    <a href="add.php" class="btn btn-primary">Добавить пользователя</a>
                </div>

                <!-- Статистика по ролям -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card border-0 bg-light">
                            <div class="card-body text-center py-3">
                                <h5 class="card-title text-danger"><?php echo $role_stats['admin'] ?? 0; ?></h5>
                                <p class="card-text text-muted mb-0">Администраторов</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-0 bg-light">
                            <div class="card-body text-center py-3">
                                <h5 class="card-title text-warning"><?php echo $role_stats['engineer'] ?? 0; ?></h5>
                                <p class="card-text text-muted mb-0">Инженеров</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-0 bg-light">
                            <div class="card-body text-center py-3">
                                <h5 class="card-title text-info"><?php echo $role_stats['operator'] ?? 0; ?></h5>
                                <p class="card-text text-muted mb-0">Операторов</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-0 bg-light">
                            <div class="card-body text-center py-3">
                                <h5 class="card-title text-dark"><?php echo count($users); ?></h5>
                                <p class="card-text text-muted mb-0">Всего пользователей</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Поиск и фильтры -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-5">
                                <input type="text" class="form-control" name="search" 
                                       placeholder="Поиск по логину или ФИО..." 
                                       value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" name="role">
                                    <option value="">Все роли</option>
                                    <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>Администратор</option>
                                    <option value="engineer" <?php echo $role_filter === 'engineer' ? 'selected' : ''; ?>>Инженер</option>
                                    <option value="operator" <?php echo $role_filter === 'operator' ? 'selected' : ''; ?>>Оператор</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select" name="sort">
                                    <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>ФИО А-Я</option>
                                    <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>ФИО Я-А</option>
                                    <option value="login_asc" <?php echo $sort === 'login_asc' ? 'selected' : ''; ?>>Логин А-Я</option>
                                    <option value="login_desc" <?php echo $sort === 'login_desc' ? 'selected' : ''; ?>>Логин Я-А</option>
                                    <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Сначала новые</option>
                                    <option value="last_login" <?php echo $sort === 'last_login' ? 'selected' : ''; ?>>Последний вход</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">Применить</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Таблица пользователей -->
                <div class="card">
                    <div class="card-body">
                        <?php if (empty($users)): ?>
                            <div class="text-center py-4">
                                <p class="text-muted">Пользователи не найдены</p>
                                <?php if ($search || $role_filter): ?>
                                    <a href="list.php" class="btn btn-outline-secondary">Сбросить фильтры</a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
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
                                            
                                            if ($user['account_locked_until'] && strtotime($user['account_locked_until']) > time()) {
                                                $status_class = 'user-status-locked';
                                                $status_text = 'Заблокирован';
                                            } elseif ($user['failed_login_attempts'] > 0) {
                                                $status_class = 'user-status-warning';
                                                $status_text = 'Неудачные попытки: ' . $user['failed_login_attempts'];
                                            } else {
                                                $status_class = 'user-status-active';
                                            }
                                            
                                            $role_badge_class = 'role-badge-' . $user['role'];
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
                                                        <?php 
                                                        $role_names = [
                                                            'admin' => 'Администратор',
                                                            'engineer' => 'Инженер', 
                                                            'operator' => 'Оператор'
                                                        ];
                                                        echo $role_names[$user['role']] ?? $user['role'];
                                                        ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="<?php echo $status_class; ?>">
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
                                                        <a href="edit.php?id=<?php echo $user['id']; ?>" class="btn btn-outline-primary">Редактировать</a>
                                                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                            <a href="delete.php?id=<?php echo $user['id']; ?>" class="btn btn-outline-danger" 
                                                               onclick="return confirm('Удалить пользователя <?php echo htmlspecialchars($user['full_name']); ?>?')">Удалить</a>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>