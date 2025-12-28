<?php
// tpc_data_system/Public/auth/logout.php
declare(strict_types=1);
require_once __DIR__ . '/../../config/auth.php';
logout_user();
header('Location: /tpc_data_system/Public/auth/login.php');
exit;
