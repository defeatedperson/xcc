<?php
// 公共头部：包含logo区域和导航菜单
// 会话安全配置
// 或先定义根目录常量，再引用（更推荐）
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';

// 访问本文件自动跳转到 dashboard.php
header("Location: /dc-admin/dashboard.php");
exit;
?>