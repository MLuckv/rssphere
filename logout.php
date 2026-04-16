<?php
require_once __DIR__ . '/auth_utils.php';
auth_boot();
auth_logout();
header('Location: auth.php?logged_out=1');
exit;
