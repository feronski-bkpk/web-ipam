<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
requireAuth();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $network = trim($_POST['network'] ?? '');
    $cidr = intval($_POST['cidr'] ?? 24);
    
    function isValidIP($ip) {
        return filter_var($ip, FILTER_VALIDATE_IP);
    }
    
    function calculateNetworkRange($network, $cidr) {
        $network_long = ip2long($network);
        if ($network_long === false) {
            return null;
        }
        
        $mask = -1 << (32 - $cidr);
        $network_start = $network_long & $mask;
        $network_end = $network_start + pow(2, (32 - $cidr)) - 1;
        
        return [
            'start' => long2ip($network_start + 1), // Первый usable IP
            'end' => long2ip($network_end - 1),     // Последний usable IP
            'total_ips' => pow(2, (32 - $cidr)) - 2,
            'network' => long2ip($network_start),
            'broadcast' => long2ip($network_end)
        ];
    }
    
    if (empty($network) || !isValidIP($network)) {
        echo json_encode(['success' => false, 'error' => 'Неверный формат IP-адреса']);
        exit;
    }
    
    if ($cidr < 0 || $cidr > 32) {
        echo json_encode(['success' => false, 'error' => 'Неверная маска CIDR']);
        exit;
    }
    
    $range = calculateNetworkRange($network, $cidr);
    if ($range === null) {
        echo json_encode(['success' => false, 'error' => 'Ошибка расчета диапазона']);
        exit;
    }
    
    echo json_encode(['success' => true, 'range' => $range]);
} else {
    echo json_encode(['success' => false, 'error' => 'Неверный метод запроса']);
}
?>