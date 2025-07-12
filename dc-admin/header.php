<?php
// 公共头部：包含logo区域和导航菜单
// 会话安全配置
// 或先定义根目录常量，再引用（更推荐）
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>星尘CC防护</title>
    <link rel="stylesheet" href="assets/style.css"> <!-- 引入全局样式 -->
    <link rel="stylesheet" href="assets/back.css">
</head>
<body class="background-anim-container">
    <div class="topback">
        <div class="header">
            <!-- logo区域 -->
            <div class="logo">
                <img src="assets/photo/xcc.png" alt="星尘CC防护" height="30"> <!-- 替换为实际logo路径 -->
                <span class="logo-text">控制台</span>
            </div>
            <div>
                <!-- 原GET链接改为POST表单 -->
                <form method="post" action="/auth/logout.php" class="logout-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <button type="submit" class="logout-btn">退出登录</button>
                </form>
            </div>
        </div>  
    </div>
    <div class="topmenus">
        <div class="top_menu">
            <!-- 导航菜单 -->
            <nav class="nav">
                <ul>
                    <!-- 当前页面高亮：通过判断当前文件名添加active类 -->
                    <li><a href="dashboard.php" <?= basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'class="active"' : '' ?>>概览</a></li>
                    <li><a href="manage.php" <?= basename($_SERVER['PHP_SELF']) === 'manage.php' ? 'class="active"' : '' ?>>网站</a></li>
                    <li><a href="certs.php" <?= basename($_SERVER['PHP_SELF']) === 'certs.php' ? 'class="active"' : '' ?>>证书</a></li>
                    <li><a href="node.php" <?= basename($_SERVER['PHP_SELF']) === 'node.php' ? 'class="active"' : '' ?>>节点</a></li>
                    <li><a href="logs.php" <?= basename($_SERVER['PHP_SELF']) === 'logs.php' ? 'class="active"' : '' ?>>日志</a></li>
                    <li><a href="extension.php" <?= basename($_SERVER['PHP_SELF']) === 'extension.php' ? 'class="active"' : '' ?>>扩展</a></li>
                    <li><a href="setting.php" <?= basename($_SERVER['PHP_SELF']) === 'setting.php' ? 'class="active"' : '' ?>>设置</a></li>
                </ul>
            </nav>
        </div>
    </div>
        <!-- 公共错误提示弹窗 -->
        <div id="customError" class="custom-alert custom-alert-error">
        <span class="close-btn">&times;关闭</span>
        <p id="errorMsg"></p>
    </div>
    <!-- 公共成功提示弹窗（新增） -->
    <div id="customSuccess" class="custom-alert custom-alert-success">
        <span class="close-btn">&times;关闭</span>
        <p id="successMsg"></p>
    </div>
    <!-- 新增：公共信息提示弹窗 -->
    <div id="customInfo" class="custom-alert custom-alert-info">
        <span class="close-btn">&times;关闭</span>
        <p id="infoMsg"></p>
    </div>
    <!-- 新增：公共确认弹窗 -->
    <div id="customConfirm" class="custom-alert custom-alert-confirm">
        <div class="alert-content">
            <h3 class="alert-title">确认操作</h3>
            <p class="alert-message"></p>
            <div class="alert-buttons">
                <button class="btn-confirm">确认</button>
                <button class="btn-cancel">取消</button>
            </div>
        </div>
    </div>

    <!-- 引入独立JS文件（保留nonce） -->
    <script nonce="<?= $_SESSION['csp_nonce'] ?? '' ?>" src="assets/header.js"></script>