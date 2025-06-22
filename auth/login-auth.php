<?php
// 安全响应头：在 session_start 前设置
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: same-origin");
header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");

// 会话 Cookie 安全配置（必须在 session_start 前）
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
session_start();
$nonce = $_SESSION['csp_nonce'] ?? bin2hex(random_bytes(32));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self'; img-src 'self'; font-src 'self'; frame-ancestors 'none';");

// 仅允许POST请求
if ($_SERVER["REQUEST_METHOD"] != "POST") {
    $_SESSION['login_error'] = "非法请求，请使用POST方法";
    header("Location: login.php");
    exit;
}

// 检查是否存在有效临时session
if (!isset($_SESSION['slider_verified']) || $_SESSION['slider_verified'] !== true) {
    $_SESSION['login_error'] = "请先完成人机验证";
    header("Location: login.php");
    exit;
}

// 会话过期处理（30分钟）
$sessionExpiration = 1800;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $sessionExpiration)) {
    // 销毁会话并清除客户端 Cookie
    session_unset();
    session_destroy();
    // 清除客户端会话 Cookie
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 42000, '/');
    }
}
$_SESSION['last_activity'] = time();

// 会话ID轮换（每10分钟）
$sessionIdRotation = 600;
if (!isset($_SESSION['session_id_rotation_time']) || (time() - $_SESSION['session_id_rotation_time'] > $sessionIdRotation)) {
    session_regenerate_id(true);
    $_SESSION['session_id_rotation_time'] = time();
}

// 已登录用户直接跳转
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header("Location: dashboard.php");
    exit;
}

// 生成/校验CSRF令牌
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    $_SESSION['login_error'] = "非法请求，请重试";
    header("Location: login.php");
    exit;
}

// 替换原输入过滤逻辑
$username = trim($_POST['username']);
$password = trim($_POST['password']);

// 验证用户名格式（字母、数字、下划线，长度1-50）
if (!preg_match('/^[\w]{1,50}$/', $username)) {
    $_SESSION['login_error'] = "用户名格式无效（仅允许字母、数字、下划线，长度≤50）";
    header("Location: login.php");
    exit;
}

// 密码仅验证长度（实际应结合复杂度要求）
if (strlen($password) < 8 || strlen($password) > 50) {
    $_SESSION['login_error'] = "密码长度需为8-50位";
    header("Location: login.php");
    exit;
}
// 新增：密码复杂度校验（至少包含字母、数字、特殊字符各一种）
if (!preg_match('/^(?=.*[A-Za-z])(?=.*\d)(?=.*[!@#$%^&*()])[A-Za-z\d!@#$%^&*()]{8,50}$/', $password)) {
    $_SESSION['login_error'] = "密码需包含字母、数字和特殊字符（如!@#$%^&*()）";
    header("Location: login.php");
    exit;
}

// IP封禁检查（保持原有逻辑）
$clientIp = $_SERVER['REMOTE_ADDR'];
$banKey = 'ban_' . $clientIp;
if (isset($_SESSION[$banKey]) && $_SESSION[$banKey]['times'] >= 5) {
    if (time() - $_SESSION[$banKey]['last_failed_time'] < 1800) {
        $_SESSION['login_error'] = "登录失败次数过多，已被封禁30分钟";
        header("Location: login.php");
        exit;
    } else {
        unset($_SESSION[$banKey]);
    }
}

// 用户数据校验（保持原有逻辑）
$filePath = __DIR__ . '/data/user_data.json';
$userData = json_decode(file_get_contents($filePath), true);
if ($username !== $userData["username"] || !password_verify($password, $userData["password"])) {
    // 记录失败次数
    $_SESSION[$banKey]['times'] = ($_SESSION[$banKey]['times'] ?? 0) + 1;
    $_SESSION[$banKey]['last_failed_time'] = time();
    
    // 计算剩余次数
    $remaining = 5 - $_SESSION[$banKey]['times'];
    if ($remaining > 0) {
        $_SESSION['login_error'] = "登录失败，剩余{$remaining}次尝试机会";
    } else {
        $_SESSION['login_error'] = "登录失败次数过多，已被封禁30分钟";
    }
    
    header("Location: login.php");
    exit;
}

// 登录成功处理（清除临时session）
unset($_SESSION['slider_verified']);
unset($_SESSION['slider_verified_time']);

// 新增：记录登录成功日志（仅成功时记录）
$logDir = __DIR__ . '/login-log'; // 日志目录路径（当前文件夹下的login-log）
$clientIp = $_SERVER['REMOTE_ADDR'];
$loginTime = date('Y-m-d H:i:s'); // 登录时间（格式化）

// 校验客户端IP有效性（IPv4/IPv6格式校验）
if (filter_var($clientIp, FILTER_VALIDATE_IP)) {
    // 创建日志目录（若不存在）
    if (!is_dir($logDir)) {
        mkdir($logDir, 0750, true); // 递归创建，权限0750
    }
    // 日志文件固定为 login.log
    $logFile = $logDir . '/login.log';
    // 日志内容格式：时间 | IP（追加模式写入）
    $logContent = "{$loginTime} | {$clientIp}\n";
    file_put_contents($logFile, $logContent, FILE_APPEND);
}

// 新增：生成新会话ID防止会话固定
session_regenerate_id(true);

$_SESSION["logged_in"] = true;
$_SESSION["login_time"] = time();
$_SESSION["token"] = bin2hex(random_bytes(32));
$_SESSION['login_failed_times'] = 0;
unset($_SESSION[$banKey]); // 重置封禁记录

header("Location: /dc-admin/dashboard.php");
exit;