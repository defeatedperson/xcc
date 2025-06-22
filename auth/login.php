<?php
// 移除独立生成的 nonce，改为包含 auth.php 继承公共逻辑
// 或先定义根目录常量，再引用（更推荐）
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';

// 会话 Cookie 安全配置（已由 auth.php 处理，无需重复设置）
// 已登录用户直接跳转控制台
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header("Location: dashboard.php");
    exit;
}
// 新增：未初始化时跳转setup.php
$userDataPath = __DIR__ . '/data/user_data.json';
if (!file_exists($userDataPath)) {
    header("Location: /setup/index.php");
    exit;
}

$error = '';
// 处理滑块验证提交（原 slider_verify.php 的 POST 逻辑）
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['verify_slider'])) {
    // 新增 CSRF 校验
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        $error = "非法请求，请重试";
        unset($_SESSION['target_left'], $_SESSION['container_width']); // 清除关联Session
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // 重新生成CSRF令牌
    } else {
        $sliderWidth = 50; // 与前端滑块宽度（50px）一致
        $targetWidth = 20;
        $sliderLeft = (int)$_POST['slider_verify'];
        $targetLeft = $_SESSION['target_left'] ?? null;
        $containerWidth = $_SESSION['container_width'] ?? 300; // 从Session获取容器宽度

        if (!$targetLeft) {
            $error = "验证失败，请重试";
        } else {
            // 重新计算有效范围（基于实际覆盖逻辑）
            $sliderRight = $sliderLeft + $sliderWidth; // 滑块右边缘
            $targetRight = $targetLeft + $targetWidth; // 目标右边缘

            // 允许±2px误差（覆盖目标区域的宽松条件）
            $isValid = (
                $sliderLeft <= $targetLeft + 2 && // 滑块左边缘 ≤ 目标左边缘+2px
                $sliderRight >= $targetRight - 2   // 滑块右边缘 ≥ 目标右边缘-2px
            );

            if ($isValid) {
                $_SESSION['slider_verified'] = true;
                $_SESSION['slider_verified_time'] = time();
                unset($_SESSION['target_left'], $_SESSION['container_width']);
            } else {
                $error = "验证失败，请重试";
                unset($_SESSION['target_left'], $_SESSION['container_width']);
            }
        }
    }
}

// 检查是否通过人机验证（临时session，5分钟有效期）
$showSlider = !isset($_SESSION['slider_verified']) || (time() - $_SESSION['slider_verified_time'] > 300);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录页面</title>
    <link rel="stylesheet" href="./css/back.css">
    <link rel="stylesheet" href="./css/login.css">
    <script nonce="<?= $_SESSION['csp_nonce'] ?>" src="./js/login.js"></script>
</head>
<body class="background-anim-container">
    <div class="login-container">
        <h1>
            <span class="xcc-text">XCC</span>
            <span class="protect-text">主控台</span>
        </h1>
        
        <?php if ($showSlider): ?>
            <!-- 加载滑块验证子页面 -->
            <?php include 'slider_widget.php'; ?>
        <?php else: ?>
            <!-- 显示登录表单 -->
            <?php if (!empty($_SESSION['login_error'])): ?>
                <p class="error-message"><?= htmlspecialchars($_SESSION['login_error'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php unset($_SESSION['login_error']); ?>
            <?php endif; ?>
            <form method="post" action="login-auth.php">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                <label for="username">用户名:</label>
                <input type="text" id="username" name="username" required maxlength="50">
                <label for="password">密码:</label>
                <input type="password" id="password" name="password" required maxlength="50">
                <input type="submit" value="登录">
                <div class="login-tip">
                    登录即代表你同意
                    <a href="/pact.php" target="_blank" class="login-agreement-link">使用协议</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</body>

</html>