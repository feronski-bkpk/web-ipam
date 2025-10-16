<?php
// pages/ip-addresses/add.php
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
requireAuth();
requireAnyRole(['admin', 'engineer']);

// Заглушка - редирект на список
header('Location: list.php');
exit();
?>