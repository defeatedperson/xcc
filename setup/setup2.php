<?php
// 检查 /auth/data 目录下是否存在 json 文件，若存在则跳转到网站根目录并终止执行
$authDataDir = $_SERVER['DOCUMENT_ROOT'] . '/auth/data';
if (is_dir($authDataDir)) {
    foreach (glob($authDataDir . '/*.json') as $jsonFile) {
        if (is_file($jsonFile)) {
            header("Location: /");
            exit;
        }
    }
}

// 步骤2必须检测 set1.txt 存在，否则跳转回步骤1
$flagFile = __DIR__ . '/set1.txt';
if (!is_file($flagFile)) {
    header("Location: setup1.php");
    exit;
}

// 处理表单提交
$errMsg = '';
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $site_url = trim($_POST['site_url'] ?? '');

    // 校验用户名
    if (!preg_match('/^[\w]{1,50}$/', $username)) {
        $errMsg = "用户名格式无效（仅允许字母、数字、下划线，长度≤50）";
    }
    // 校验密码
    elseif (strlen($password) < 8 || strlen($password) > 50) {
        $errMsg = "密码长度需为8-50位";
    }
    elseif (!preg_match('/^(?=.*[A-Za-z])(?=.*\d)(?=.*[!@#$%^&*()])[A-Za-z\d!@#$%^&*()]{8,50}$/', $password)) {
        $errMsg = "密码需包含字母、数字和特殊字符（如!@#$%^&*()）";
    }
    // 校验站点信息
    elseif (!preg_match('#^https://[^/]+(\:[0-9]+)?$#', $site_url)) {
        $errMsg = "站点地址格式无效，需以 https:// 开头，不能以 / 结尾";
    }
    else {
        // 保存账号密码
        $userData = [
            'username' => $username,
            'password' => password_hash($password, PASSWORD_DEFAULT)
        ];
        $authDataPath = $_SERVER['DOCUMENT_ROOT'] . '/auth/data/user_data.json';
        if (!is_dir(dirname($authDataPath))) {
            mkdir(dirname($authDataPath), 0750, true);
        }
        file_put_contents($authDataPath, json_encode($userData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        // 保存站点信息
        $nodeDir = $_SERVER['DOCUMENT_ROOT'] . '/node';
        if (!is_dir($nodeDir)) {
            mkdir($nodeDir, 0750, true);
        }
        $siteInfo = ['site_url' => $site_url];
        file_put_contents($nodeDir . '/site.json', json_encode($siteInfo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        // 步骤2完成标记
        file_put_contents(__DIR__ . '/set2.txt', 'step2-ok');
        $success = true;
        header("Location: setup3.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>设置账号与站点信息 - XCC主控安装</title>
    <link rel="stylesheet" href="./back.css">
    <style>
        body { background: #f6f8fa; font-family: "Segoe UI", "PingFang SC", "Hiragino Sans GB", "Arial", sans-serif; }
        .setup-container { max-width: 420px; margin: 60px auto 0 auto; background: #fff; border-radius: 10px; box-shadow: 0 2px 12px rgba(0,0,0,0.07); padding: 32px 32px 24px 32px; }
        .setup-title { font-size: 20px; color: #2563eb; margin-bottom: 18px; }
        .setup-form label { display: block; margin: 18px 0 6px 0; font-weight: bold; }
        .setup-form input { width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 5px; font-size: 15px; }
        .setup-btn { background: #2563eb; color: #fff; border: none; border-radius: 5px; font-size: 16px; padding: 10px 38px; cursor: pointer; transition: background 0.2s; margin-top: 22px;}
        .setup-btn:hover { background: #1d4fd7; }
        .err-msg { color: #e53e3e; margin-top: 16px; }
    </style>
</head>
<body class="background-anim-container">
    <div class="setup-container">
        <div class="setup-title">设置账号与站点信息</div>
        <?php if ($errMsg): ?>
            <div class="err-msg"><?php echo htmlspecialchars($errMsg); ?></div>
        <?php endif; ?>
        <form class="setup-form" method="post" action="">
            <label for="username">管理员账号</label>
            <input type="text" id="username" name="username" maxlength="50" required autocomplete="off" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">

            <label for="password">管理员密码</label>
            <input type="password" id="password" name="password" maxlength="50" required autocomplete="new-password">

            <label for="site_url">站点地址（以 https:// 开头，不能以 / 结尾）</label>
            <input type="text" id="site_url" name="site_url" maxlength="100" required autocomplete="off" value="<?php echo htmlspecialchars($_POST['site_url'] ?? ''); ?>">

            <button type="submit" class="setup-btn">保存并进入下一步</button>
        </form>
    </div>
    <script>
        window.addEventListener('DOMContentLoaded', function() {
            var input = document.getElementById('site_url');
            if (input && !input.value) {
                var url = window.location.protocol + '//' + window.location.host;
                input.value = url;
            }
        });
    </script>
</body>
</html>