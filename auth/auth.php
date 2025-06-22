<?php
// 会话安全配置（必须在输出内容前）
ini_set('session.cookie_secure', 1); // 仅在 HTTPS 下传输会话 cookie
ini_set('session.cookie_httponly', 1); // 防止 JavaScript 访问会话 cookie
ini_set('session.cookie_samesite', 'Lax');

// 开启会话（必须在生成nonce前）
session_start();

// 设置 CSP nonce 有效期（30分钟，单位：秒）
$nonceExpiration = 1800;

// 仅首次访问时生成 nonce（关键修改）
if (!isset($_SESSION['csp_nonce'])) {
    $nonce = bin2hex(random_bytes(32));
    $_SESSION['csp_nonce'] = $nonce; // 存储到 Session，后续请求复用
} else {
    $nonce = $_SESSION['csp_nonce']; // 复用已有的 nonce
}

// 安全响应头：在 session_start 后设置（必须在输出内容前）
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self'; img-src 'self'; font-src 'self'; frame-ancestors 'none';");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: same-origin");
header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");

// 定义会话过期时间（单位：秒），这里设置为 30 分钟
$sessionExpiration = 1800; 

// 检查会话是否过期
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $sessionExpiration)) {
    // 会话过期，销毁会话并重定向到登录页面
    session_unset();
    session_destroy();
    header('Location: /auth/login.php');
    exit;
}

// 更新最后活动时间
$_SESSION['last_activity'] = time();

// 定期轮换会话 ID，例如每 10 分钟轮换一次
$sessionIdRotation = 600; 
if (!isset($_SESSION['session_id_rotation_time']) || (time() - $_SESSION['session_id_rotation_time'] > $sessionIdRotation)) {
    session_regenerate_id(true);
    $_SESSION['session_id_rotation_time'] = time();
}

// 生成或获取 CSRF 令牌
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 检查用户是否已登录
$currentScript = basename($_SERVER['PHP_SELF']); // 获取当前请求的脚本名（如login.php）
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // 仅当当前页面不是登录页面时才重定向
    if ($currentScript !== 'login.php') {
        header('Location: /auth/login.php');
        exit;
    }
}

// 处理退出登录逻辑，添加 CSRF 验证
if (isset($_GET['logout']) && isset($_GET['csrf_token']) && $_GET['csrf_token'] === $_SESSION['csrf_token']) {
    session_destroy();
    header('Location: /auth/login.php');
    exit;
}