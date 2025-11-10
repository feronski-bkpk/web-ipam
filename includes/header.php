<?php
// includes/header.php
require_once 'csrf.php';
if (!isset($_SESSION)) {
    session_start();
}
?>
<!-- Главная навигация -->
<nav class="navbar navbar-expand-lg navbar-dark main-nav">
    <div class="container">
        <a class="navbar-brand nav-brand d-flex align-items-center" href="../../index.php">
            <i class="bi bi-hdd-network me-2"></i>
            Web-IPAM
        </a>
        
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <div class="collapse navbar-collapse" id="mainNavbar">
            <!-- Основное меню -->
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link nav-link-custom <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>" href="../../index.php">
                        <i class="bi bi-speedometer2 me-1"></i>Дашборд
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link nav-link-custom <?php echo strpos($_SERVER['PHP_SELF'], 'ip-addresses') !== false ? 'active' : ''; ?>" href="../ip-addresses/list.php">
                        <i class="bi bi-router me-1"></i>IP-адреса
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link nav-link-custom <?php echo strpos($_SERVER['PHP_SELF'], 'clients') !== false ? 'active' : ''; ?>" href="../clients/list.php">
                        <i class="bi bi-people me-1"></i>Клиенты
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link nav-link-custom <?php echo strpos($_SERVER['PHP_SELF'], 'devices') !== false ? 'active' : ''; ?>" href="../devices/list.php">
                        <i class="bi bi-hdd me-1"></i>Устройства
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link nav-link-custom <?php echo strpos($_SERVER['PHP_SELF'], 'subnets') !== false ? 'active' : ''; ?>" href="../subnets/list.php">
                        <i class="bi bi-diagram-3 me-1"></i>Подсети
                    </a>
                </li>
                <?php if (hasRole('admin')): ?>
                <li class="nav-item">
                    <a class="nav-link nav-link-custom <?php echo strpos($_SERVER['PHP_SELF'], 'audit') !== false ? 'active' : ''; ?>" href="../audit/list.php">
                        <i class="bi bi-clipboard-data me-1"></i>Аудит
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link nav-link-custom <?php echo strpos($_SERVER['PHP_SELF'], 'users') !== false ? 'active' : ''; ?>" href="../users/list.php">
                        <i class="bi bi-people-fill me-1"></i>Пользователи
                    </a>
                </li>
                <?php endif; ?>
            </ul>
            
            <!-- Поиск и пользователь -->
            <div class="d-flex align-items-center">
                <!-- Поиск -->
                <div class="search-container me-3">
                    <form method="GET" action="../search/global.php" class="search-form" id="searchForm">
                        <div class="input-group">
                            <input type="text" 
                                   name="q" 
                                   class="form-control" 
                                   placeholder="Поиск IP, клиента, устройства..." 
                                   id="searchInput"
                                   style="border-radius: 20px 0 0 20px;">
                            <button class="btn btn-outline-light" type="submit" style="border-radius: 0 20px 20px 0;">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                    </form>
                </div>
                
                <div class="nav-divider"></div>
                
                <!-- Информация пользователя -->
                <div class="user-info">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-person-circle me-2"></i>
                        <div>
                            <div class="fw-bold"><?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?></div>
                            <small class="text-light">
                                <?php echo htmlspecialchars($_SESSION['user_role'] ?? ''); ?>
                            </small>
                        </div>
                    </div>
                </div>
                
                <div class="nav-divider"></div>
                
                <!-- Выход -->
                <a href="../../logout.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-box-arrow-right me-1"></i>Выйти
                </a>
            </div>
        </div>
    </div>
</nav>

<style>
.main-nav {
    background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
    border-bottom: 3px solid #3498db;
    padding: 0.5rem 0;
}
.nav-link-custom {
    color: #ecf0f1 !important;
    padding: 0.75rem 1rem;
    border-radius: 6px;
    transition: all 0.3s ease;
    margin: 0 2px;
    font-weight: 500;
}
.nav-link-custom:hover {
    background-color: rgba(52, 152, 219, 0.2);
    color: #3498db !important;
    transform: translateY(-1px);
}
.nav-link-custom.active {
    background-color: #3498db;
    color: white !important;
    box-shadow: 0 2px 8px rgba(52, 152, 219, 0.3);
}
.nav-brand {
    font-weight: 600;
    font-size: 1.25rem;
}
.search-container {
    max-width: 400px;
    position: relative;
}
.search-form {
    position: relative;
}
.user-info {
    color: #ecf0f1;
    font-size: 0.9rem;
}
.nav-divider {
    border-left: 1px solid rgba(255,255,255,0.2);
    height: 30px;
    margin: 0 1rem;
}
</style>