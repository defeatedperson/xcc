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

// 环境检测
$checks = [];

// 检查 PHP 版本
$phpVersion = PHP_VERSION;
$checks['PHP 版本'] = (version_compare($phpVersion, '8.0.0', '>=') && version_compare($phpVersion, '9.0.0', '<')) ?
    ['ok' => true, 'msg' => "当前版本：$phpVersion"] :
    ['ok' => false, 'msg' => "当前版本：$phpVersion，需 PHP 8.x"];

// 检查 exec 函数
$execEnabled = is_callable('exec') && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))));
$checks['exec 函数可用'] = $execEnabled ?
    ['ok' => true, 'msg' => "exec 函数可用"] :
    ['ok' => false, 'msg' => "exec 函数不可用"];


// 检查 PHP 是否有权限读写 /auth/data 目录
$rwTestFile = $authDataDir . '/rwtest.txt';
$canWrite = @file_put_contents($rwTestFile, 'rwtest') !== false;
$canRead = $canWrite && @file_get_contents($rwTestFile) === 'rwtest';
if ($canWrite && $canRead) {
    $checks['目录读写权限'] = ['ok' => true, 'msg' => "PHP 有权限读写 /auth/data 目录"];
} else {
    $checks['目录读写权限'] = ['ok' => false, 'msg' => "PHP 无法读写 /auth/data 目录，请检查权限"];
}
// 删除测试文件
@unlink($rwTestFile);


// 改进的 HTTPS 检测，添加对常见反向代理头的支持
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
        || $_SERVER['SERVER_PORT'] == 443
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https')
        || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] == 'on')
        || (isset($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], 'https') !== false);

$checks['HTTPS 启用'] = $isHttps ?
    ['ok' => true, 'msg' => "已启用 HTTPS"] :
    ['ok' => false, 'msg' => "未启用 HTTPS (如使用反向代理，请确保正确转发HTTPS头)"];

// 先定义测试文件路径
$testJsonName = 'test_' . time() . '.json';
$testJsonPath = $_SERVER['DOCUMENT_ROOT'] . '/auth/data/' . $testJsonName;
$testJsonUrl = '/auth/data/' . $testJsonName;

// 改进的伪静态检测，获取当前实际访问URL
$scheme = $isHttps ? 'https' : 'http';
$host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'];
$testUrl = "{$scheme}://{$host}{$testJsonUrl}";

// 自动创建测试文件
file_put_contents($testJsonPath, '{"test":1}');

// 访问 test.json 检查返回状态码
$httpCode = 0;
if (function_exists('curl_init')) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $testUrl);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
} else {
    // fallback: 用 get_headers
    $headers = @get_headers($testJsonUrl, 1);
    if ($headers && isset($headers[0])) {
        if (preg_match('/\s(\d{3})\s/', $headers[0], $m)) {
            $httpCode = intval($m[1]);
        }
    }
}

// 检查结果
if ($httpCode === 403) {
    $checks['伪静态规则'] = ['ok' => true, 'msg' => "已生效（访问json文件返回403）"];
} elseif ($httpCode === 404) {
    $checks['伪静态规则'] = ['ok' => false, 'msg' => "检测文件不存在，请检查 /auth/data/ 目录权限"];
} else {
    $checks['伪静态规则'] = ['ok' => false, 'msg' => "未生效（访问json文件返回{$httpCode}），请检查nginx配置"];
}

// 删除测试文件
@unlink($testJsonPath);

// 检查 SQLite 可用
try {
    $sqliteAvailable = class_exists('SQLite3');
    if ($sqliteAvailable) {
        $db = new SQLite3(':memory:');
        $db->exec('CREATE TABLE test (id INTEGER)');
        $db->close();
        $checks['SQLite 数据库'] = ['ok' => true, 'msg' => "SQLite 可用"];
    } else {
        $checks['SQLite 数据库'] = ['ok' => false, 'msg' => "未安装 SQLite3 扩展"];
    }
} catch (Exception $e) {
    $checks['SQLite 数据库'] = ['ok' => false, 'msg' => "SQLite 不可用：" . $e->getMessage()];
}

// 是否全部通过
$allPass = true;
foreach ($checks as $item) {
    if (!$item['ok']) {
        $allPass = false;
        break;
    }
}

// 步骤1通过后，生成 set1.txt 文件作为步骤完成标记
if ($allPass) {
    $flagFile = __DIR__ . '/set1.txt';
    @file_put_contents($flagFile, 'step1-ok');
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>环境检测 - XCC主控安装</title>
    <link rel="stylesheet" href="./back.css">
    <style>
        body { background: #f6f8fa; font-family: "Segoe UI", "PingFang SC", "Hiragino Sans GB", "Arial", sans-serif; }
        .setup-container { max-width: 480px; margin: 60px auto 0 auto; background: #fff; border-radius: 10px; box-shadow: 0 2px 12px rgba(0,0,0,0.07); padding: 32px 32px 24px 32px; }
        .setup-title { font-size: 20px; color: #2563eb; margin-bottom: 18px; }
        .env-check-list { margin: 18px 0 24px 0; }
        .env-check-list li { margin-bottom: 10px; font-size: 15px; }
        .ok { color: #22b573; }
        .fail { color: #e53e3e; }
        .setup-btn { background: #2563eb; color: #fff; border: none; border-radius: 5px; font-size: 16px; padding: 10px 38px; cursor: pointer; transition: background 0.2; }
        .setup-btn:disabled { background: #b3b3b3; cursor: not-allowed; }
        .rewrite-block { background: #f4f6fa; border: 1px solid #dbeafe; border-radius: 6px; padding: 16px 12px; margin: 24px 0 0 0; }
        .rewrite-title { font-size: 15px; color: #2563eb; margin-bottom: 8px; font-weight: bold; }
        .rewrite-code { background: #222; color: #fff; font-size: 13px; border-radius: 4px; padding: 10px 12px; margin-bottom: 8px; white-space: pre; }
        .copy-btn { background: #2563eb; color: #fff; border: none; border-radius: 4px; padding: 4px 14px; font-size: 14px; margin-left: 8px; cursor: pointer; transition: background 0.2; }
        .copy-btn:hover { background: #1746a2; }
        .copy-tip { color: #22b573; font-size: 13px; margin-left: 8px; display: none; }
    </style>
    <script>
    function copyRewrite() {
        const code = document.getElementById('rewriteCode').textContent;
        navigator.clipboard.writeText(code).then(function() {
            document.getElementById('copyTip').style.display = 'inline';
            setTimeout(function() {
                document.getElementById('copyTip').style.display = 'none';
            }, 1500);
        });
    }
    </script>
</head>
<body class="background-anim-container">
    <div class="setup-container">
        <div class="setup-title">环境检测</div>
        <ul class="env-check-list">
            <?php foreach ($checks as $name => $item): ?>
                <li>
                    <?php if ($item['ok']): ?>
                        <span class="ok">✔</span>
                    <?php else: ?>
                        <span class="fail">✖</span>
                    <?php endif; ?>
                    <b><?php echo htmlspecialchars($name); ?>：</b>
                    <?php echo htmlspecialchars($item['msg']); ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <form action="setup2.php" method="get">
            <button type="submit" class="setup-btn" <?php if(!$allPass) echo 'disabled'; ?>>下一步</button>
        </form>
        <?php if(!$allPass): ?>
            <div style="color:#e53e3e;margin-top:16px;">请修复所有环境问题后再继续。</div>
        <?php endif; ?>

        <!-- 伪静态规则展示区域 -->
        <div class="rewrite-block">
            <div class="rewrite-title">Nginx 伪静态规则</div>
            <div class="rewrite-code" id="rewriteCode">
location ~* \.(db|json|conf|key|crt|log|zip)$ {
        deny all;
        return 403;
        access_log off;
    }

location ~* /(update-worker|clear-cache-worker)\.php$ {
    deny all;
}
            </div>
            <button type="button" class="copy-btn" onclick="copyRewrite()">复制</button>
            <span class="copy-tip" id="copyTip">已复制！</span>
        </div>
    </div>
</body>
</html>
