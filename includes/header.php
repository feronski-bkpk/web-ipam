<?php
// includes/header.php
if (!isset($_SESSION)) {
    session_start();
}
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand" href="../../index.php">🌐 Web-IPAM</a>
        
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="../../index.php">📊 Дашборд</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../ip-addresses/list.php">📡 IP-адреса</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../clients/list.php">👥 Клиенты</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../devices/list.php">🖧 Устройства</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../subnets/list.php">🌐 Подсети</a>
                </li>
                <?php if (hasRole('admin')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="../audit/list.php">📋 Аудит</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../users/list.php">👤 Пользователи</a>
                    </li>
                <?php endif; ?>
            </ul>
            
            <!-- Поиск в навбаре -->
            <form class="d-flex me-3" action="../search/global.php" method="GET">
                <div class="input-group input-group-sm">
                    <input type="text" class="form-control" placeholder="Поиск..." name="q" 
                           style="width: 200px;" aria-label="Search">
                    <button class="btn btn-outline-light" type="submit">🔍</button>
                </div>
            </form>
            
            <div class="navbar-nav">
                <span class="navbar-text me-3">
                    <?php echo htmlspecialchars($_SESSION['user_name']); ?>
                    <small class="text-muted">(<?php echo htmlspecialchars($_SESSION['user_role']); ?>)</small>
                </span>
                <a class="nav-link" href="../../logout.php">🚪 Выйти</a>
            </div>
        </div>
    </div>
</nav>