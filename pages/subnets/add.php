<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
require_once '../../includes/audit_system.php';
requireAuth();
requireAnyRole(['admin', 'engineer']);

$errors = [];
$success = '';

// Функции для работы с подсетями (остаются без изменений)
function isValidIP($ip) {
    return filter_var($ip, FILTER_VALIDATE_IP);
}

function isValidCIDR($cidr) {
    return $cidr >= 0 && $cidr <= 32;
}

function calculateNetworkRange($network, $cidr) {
    $network_long = ip2long($network);
    $mask = -1 << (32 - $cidr);
    
    $network_start = $network_long & $mask;
    $network_end = $network_start + pow(2, (32 - $cidr)) - 1;
    
    return [
        'start' => long2ip($network_start),
        'end' => long2ip($network_end),
        'total_ips' => pow(2, (32 - $cidr)) - 2,
        'network' => long2ip($network_start),
        'broadcast' => long2ip($network_end)
    ];
}

function checkSubnetOverlap($network, $cidr, $conn) {
    $network_long = ip2long($network);
    $mask = -1 << (32 - $cidr);
    $network_start = $network_long & $mask;
    $network_end = $network_start + pow(2, (32 - $cidr)) - 1;
    
    $check_stmt = $conn->prepare("
        SELECT network_address, cidr_mask 
        FROM subnets 
        WHERE id IS NOT NULL
    ");
    $check_stmt->execute();
    $existing_subnets = $check_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $check_stmt->close();
    
    foreach ($existing_subnets as $existing) {
        $existing_network_long = ip2long($existing['network_address']);
        $existing_mask = -1 << (32 - $existing['cidr_mask']);
        $existing_start = $existing_network_long & $existing_mask;
        $existing_end = $existing_start + pow(2, (32 - $existing['cidr_mask'])) - 1;
        
        if (($network_start >= $existing_start && $network_start <= $existing_end) ||
            ($network_end >= $existing_start && $network_end <= $existing_end) ||
            ($existing_start >= $network_start && $existing_start <= $network_end)) {
            return "Пересекается с существующей подсетью: {$existing['network_address']}/{$existing['cidr_mask']}";
        }
    }
    
    return null;
}

function createIPAddressesForSubnet($subnet_id, $network_range, $conn) {
    $network_long = ip2long($network_range['network']);
    $broadcast_long = ip2long($network_range['broadcast']);
    
    $start_ip = $network_long + 1;
    $end_ip = $broadcast_long - 1;
    
    $created_count = 0;
    
    try {
        $stmt = $conn->prepare("INSERT INTO ip_addresses (ip_address, subnet_id, type, status) VALUES (?, ?, 'gray', 'free')");
        
        for ($ip_long = $start_ip; $ip_long <= $end_ip; $ip_long++) {
            $ip_address = long2ip($ip_long);
            $stmt->bind_param("si", $ip_address, $subnet_id);
            
            if ($stmt->execute()) {
                $created_count++;
            }
        }
        
        $stmt->close();
        return $created_count;
        
    } catch (Exception $e) {
        error_log("Error in createIPAddressesForSubnet: " . $e->getMessage());
        return 0;
    }
}

// Обработка формы (остается без изменений)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $network_address = trim($_POST['network_address'] ?? '');
    $cidr_mask = intval($_POST['cidr_mask'] ?? 24);
    $gateway = trim($_POST['gateway'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    // Валидация
    if (empty($network_address)) {
        $errors['network_address'] = 'Адрес сети обязателен';
    } elseif (!isValidIP($network_address)) {
        $errors['network_address'] = 'Неверный формат IP-адреса сети';
    }
    
    if (!isValidCIDR($cidr_mask)) {
        $errors['cidr_mask'] = 'Маска CIDR должна быть от 0 до 32';
    }
    
    if ($gateway && !isValidIP($gateway)) {
        $errors['gateway'] = 'Неверный формат IP-адреса шлюза';
    }
    
    // Проверка уникальности подсети
    if (empty($errors)) {
        try {
            $check_stmt = $conn->prepare("SELECT id FROM subnets WHERE network_address = ? AND cidr_mask = ?");
            $check_stmt->bind_param("si", $network_address, $cidr_mask);
            $check_stmt->execute();
            
            if ($check_stmt->get_result()->num_rows > 0) {
                $errors['network_address'] = 'Подсеть с такими параметрами уже существует';
            }
            $check_stmt->close();
        } catch (Exception $e) {
            $errors['general'] = 'Ошибка проверки данных: ' . $e->getMessage();
        }
    }
    
    // Проверка пересечения подсетей
    if (empty($errors)) {
        $overlap_error = checkSubnetOverlap($network_address, $cidr_mask, $conn);
        if ($overlap_error) {
            $errors['network_address'] = $overlap_error;
        }
    }
    
    // Проверка шлюза (если указан)
    if (empty($errors) && $gateway) {
        $network_range = calculateNetworkRange($network_address, $cidr_mask);
        $gateway_long = ip2long($gateway);
        $network_long = ip2long($network_range['network']);
        $broadcast_long = ip2long($network_range['broadcast']);
        
        if ($gateway_long <= $network_long || $gateway_long >= $broadcast_long) {
            $errors['gateway'] = 'Шлюз должен находиться внутри диапазона подсети (исключая адрес сети и широковещательный адрес)';
        }
    }
    
    // Сохранение
    if (empty($errors)) {
        try {
            $insert_stmt = $conn->prepare("
                INSERT INTO subnets (network_address, cidr_mask, gateway, description) 
                VALUES (?, ?, ?, ?)
            ");
            $insert_stmt->bind_param("siss", $network_address, $cidr_mask, $gateway, $description);
            
            if ($insert_stmt->execute()) {
                $subnet_id = $insert_stmt->insert_id;
                
                // Автоматически создаем IP-адреса в подсети
                $network_range = calculateNetworkRange($network_address, $cidr_mask);
                $created_ips = createIPAddressesForSubnet($subnet_id, $network_range, $conn);
                
                // Логируем создание
                AuditSystem::logCreate('subnets', $subnet_id, 
                    "Добавлена подсеть: {$network_address}/{$cidr_mask}",
                    [
                        'network_address' => $network_address,
                        'cidr_mask' => $cidr_mask,
                        'gateway' => $gateway,
                        'description' => $description,
                        'ip_range' => $network_range['start'] . ' - ' . $network_range['end'],
                        'total_ips' => $network_range['total_ips'],
                        'created_ips' => $created_ips
                    ]
                );
                
                $success = "Подсеть успешно добавлена. Автоматически создано {$created_ips} IP-адресов в диапазоне.";
                $_POST = []; // Очищаем форму
            } else {
                $errors['general'] = 'Ошибка при сохранении: ' . $insert_stmt->error;
            }
            
            $insert_stmt->close();
        } catch (Exception $e) {
            $errors['general'] = 'Ошибка базы данных: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Добавить подсеть - Web-IPAM</title>
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
                        <h1 class="h3 mb-1">Добавление подсети</h1>
                        <p class="text-muted mb-0">Создание новой подсети в системе</p>
                    </div>
                    <div>
                        <a href="list.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i>Назад к списку
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-8 mx-auto">
                <!-- Уведомления -->
                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <?php echo htmlspecialchars($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($errors['general'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <?php echo htmlspecialchars($errors['general']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Основная форма -->
                <div class="card stat-card">
                    <div class="card-header bg-transparent">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-plus-circle me-2"></i>Новая подсеть
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" id="subnet-form">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="network_address" class="form-label">Адрес сети <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control <?php echo isset($errors['network_address']) ? 'is-invalid' : ''; ?>" 
                                               id="network_address" name="network_address" 
                                               value="<?php echo htmlspecialchars($_POST['network_address'] ?? ''); ?>" 
                                               required placeholder="192.168.1.0">
                                        <?php if (isset($errors['network_address'])): ?>
                                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['network_address']); ?></div>
                                        <?php endif; ?>
                                        <div class="form-text">Адрес сети (первый IP в диапазоне)</div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="cidr_mask" class="form-label">Маска подсети (CIDR) <span class="text-danger">*</span></label>
                                        <select class="form-select <?php echo isset($errors['cidr_mask']) ? 'is-invalid' : ''; ?>" 
                                                id="cidr_mask" name="cidr_mask" required>
                                            <?php for ($i = 8; $i <= 30; $i++): ?>
                                                <option value="<?php echo htmlspecialchars($i); ?>" 
                                                    <?php echo ($_POST['cidr_mask'] ?? 24) == $i ? 'selected' : ''; ?>>
                                                    /<?php echo htmlspecialchars($i); ?> (<?php echo long2ip(-1 << (32 - $i)); ?>)
                                                </option>
                                            <?php endfor; ?>
                                        </select>
                                        <?php if (isset($errors['cidr_mask'])): ?>
                                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['cidr_mask']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="gateway" class="form-label">Шлюз по умолчанию</label>
                                <input type="text" class="form-control <?php echo isset($errors['gateway']) ? 'is-invalid' : ''; ?>" 
                                       id="gateway" name="gateway" 
                                       value="<?php echo htmlspecialchars($_POST['gateway'] ?? ''); ?>" 
                                       placeholder="192.168.1.1">
                                <?php if (isset($errors['gateway'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['gateway']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">Обычно первый usable IP в подсети</div>
                            </div>

                            <div class="mb-4">
                                <label for="description" class="form-label">Описание</label>
                                <textarea class="form-control" id="description" name="description" 
                                          rows="3" placeholder="Описание назначения подсети"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                            </div>

                            <!-- Предварительный расчет -->
                            <div class="card stat-card mb-4">
                                <div class="card-header bg-transparent">
                                    <h6 class="card-title mb-0">
                                        <i class="bi bi-calculator me-2"></i>Предварительный расчет
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div id="range-info" class="text-muted">
                                        Введите адрес сети и маску для расчета диапазона
                                    </div>
                                </div>
                            </div>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <a href="list.php" class="btn btn-outline-secondary me-2">
                                    <i class="bi bi-x-circle me-1"></i>Отмена
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-plus-circle me-1"></i>Добавить подсеть
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Справка по подсетям -->
                <div class="card stat-card mt-4">
                    <div class="card-header bg-transparent">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-info-circle me-2"></i>Справка по подсетям
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Распространенные маски:</strong>
                                <ul class="text-muted small">
                                    <li><code>/24</code> - 256 IP (254 usable) - Маленькие сети</li>
                                    <li><code>/23</code> - 512 IP (510 usable) - Средние сети</li>
                                    <li><code>/22</code> - 1024 IP (1022 usable) - Крупные сети</li>
                                    <li><code>/16</code> - 65536 IP - Очень крупные сети</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <strong>Правила:</strong>
                                <ul class="text-muted small">
                                    <li>Система автоматически создаст все IP-адреса в диапазоне</li>
                                    <li>Первый IP - адрес сети, последний - широковещательный</li>
                                    <li>Шлюз обычно устанавливается на первый usable IP</li>
                                    <li>Система проверяет пересечения с существующими подсетями</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Предварительный расчет диапазона
        function calculateRange() {
            const network = document.getElementById('network_address').value;
            const cidr = parseInt(document.getElementById('cidr_mask').value);
            
            if (!network || !cidr) return;
            
            const ipRegex = /^(\d{1,3}\.){3}\d{1,3}$/;
            if (!ipRegex.test(network)) {
                document.getElementById('range-info').innerHTML = 
                    '<span class="text-danger">Неверный формат IP-адреса</span>';
                return;
            }
            
            fetch('calculate_range.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `network=${encodeURIComponent(network)}&cidr=${cidr}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('range-info').innerHTML = `
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Диапазон:</strong><br>
                                <code>${data.range.start} - ${data.range.end}</code>
                            </div>
                            <div class="col-md-6">
                                <strong>Используемых IP:</strong> ${data.range.total_ips}<br>
                                <strong>Адрес сети:</strong> ${data.range.network}<br>
                                <strong>Широковещательный:</strong> ${data.range.broadcast}
                            </div>
                        </div>
                    `;
                } else {
                    document.getElementById('range-info').innerHTML = 
                        '<span class="text-danger">Ошибка расчета: ' + data.error + '</span>';
                }
            })
            .catch(error => {
                document.getElementById('range-info').innerHTML = 
                    '<span class="text-danger">Ошибка соединения</span>';
            });
        }

        document.getElementById('network_address').addEventListener('input', calculateRange);
        document.getElementById('cidr_mask').addEventListener('change', calculateRange);

        document.addEventListener('DOMContentLoaded', function() {
            calculateRange();
        });
    </script>
</body>
</html>