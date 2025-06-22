<?php
session_start();


// 步骤1：验证CSRF令牌（改为从POST获取）
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    $_SESSION['alert'] = ['type' => 'error', 'message' => '非法退出请求，令牌验证失败'];
    header("Location: " . ($_SERVER['HTTP_REFERER'] ?? 'dashboard.php'));
    exit;
}

// 步骤2：清除所有会话数据（比$_SESSION=[]更彻底）
session_unset();

// 步骤3：删除会话Cookie（兼容HTTPS和HttpOnly）
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 86400,  // 更早的过期时间确保浏览器删除
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// 步骤4：销毁会话（与session_unset配合确保无残留）
session_destroy();

// 步骤5：设置退出成功提示（通过公共弹窗显示）
$_SESSION['alert'] = ['type' => 'success', 'message' => '已成功退出登录'];

// 重定向到登录页
header("Location: login.php");
exit;
?>