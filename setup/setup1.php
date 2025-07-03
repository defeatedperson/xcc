<?php
// 检查 /auth/data 目录下是否存在 json 文件，若存在则跳转到网站根目录并终止执行
$authDataDir = $_SERVER['DOCUMENT_ROOT'] . '/auth/data';
if (is_dir($authDataDir)) {
    foreach (glob($authDataDir . '/*.json') as $jsonFile) {
        // 排除我们自己创建的临时测试文件
        if (is_file($jsonFile) && strpos(basename($jsonFile), 'test_') !== 0) {
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

// 检查 ZIP 扩展
$zipAvailable = extension_loaded('zip');
$checks['ZIP 扩展'] = $zipAvailable ?
    ['ok' => true, 'msg' => "ZIP 扩展已启用"] :
    ['ok' => false, 'msg' => "未启用 ZIP 扩展，无法进行系统更新"];

// 检查 CURL 扩展
$curlAvailable = extension_loaded('curl');
$checks['CURL 扩展'] = $curlAvailable ?
    ['ok' => true, 'msg' => "CURL 扩展已启用"] :
    ['ok' => false, 'msg' => "未启用 CURL 扩展，无法进行节点同步和远程请求"];

// 检查 PHP 是否有权限读写 /auth/data 目录
$rwTestFile = $authDataDir . '/rwtest.txt';
$canWrite = @file_put_contents($rwTestFile, 'rwtest') !== false;
$canRead = $canWrite && @file_get_contents($rwTestFile) === 'rwtest';
if ($canWrite && $canRead) {
    $checks['目录读写权限'] = ['ok' => true, 'msg' => "PHP 有权限读写 /auth/data 目录"];
} else {
    $checks['目录读写权限'] = ['ok' => false, 'msg' => "PHP 无法读写 /auth/data 目录，请检查权限"];
}
@unlink($rwTestFile);

// 检测 Web 服务器类型
$serverSoftware = strtolower($_SERVER['SERVER_SOFTWARE'] ?? '');
if (strpos($serverSoftware, 'nginx') !== false) {
    $webServerType = 'nginx';
} elseif (strpos($serverSoftware, 'apache') !== false) {
    $webServerType = 'apache';
} else {
    $webServerType = 'other';
}

// Nginx 伪静态规则
$nginxRewrite = <<<NGINX
location ~* \.(db|json|conf|key|crt|log|zip)$ {
    deny all;
    return 403;
    access_log off;
}

location ~* /(update-worker|clear-cache-worker)\.php$ {
    deny all;
}
NGINX;

// Apache 伪静态规则
$apacheRewrite = <<<APACHE
RewriteEngine On

RewriteRule \.(db|json|conf|key|crt|log|zip)$ - [NC,F,L]

RewriteRule ^(update-worker|clear-cache-worker)\.php$ - [NC,F,L]
APACHE;

// 改进的 HTTPS 检测
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
        || $_SERVER['SERVER_PORT'] == 443
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https')
        || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] == 'on')
        || (isset($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], 'https') !== false);

$checks['HTTPS 启用'] = $isHttps ?
    ['ok' => true, 'msg' => "已启用 HTTPS"] :
    ['ok' => false, 'msg' => "未启用 HTTPS (如使用反向代理，请确保正确转发HTTPS头)"];

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

// --- 伪静态检测准备 ---
$rewriteCheckData = ['url' => '', 'error' => ''];
$testJsonName = 'test_' . time() . '.json';
$testJsonPath = $authDataDir . '/' . $testJsonName;

// PHP 只负责创建文件，JS 负责检测
if (@file_put_contents($testJsonPath, '{"test":1}') === false) {
    // 如果连文件都创建失败，直接标记为权限问题
    $rewriteCheckData['error'] = '检测文件创建失败，请检查 /auth/data/ 目录权限';
} else {
    // 将测试文件的相对路径传递给 JS
    $rewriteCheckData['url'] = '/auth/data/' . $testJsonName;
}

// 是否全部通过 (初始状态，不包括JS检测项)
$allPass = true;
foreach ($checks as $item) {
    if (!$item['ok']) {
        $allPass = false;
        break;
    }
}
if (!empty($rewriteCheckData['error'])) {
    $allPass = false;
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
        .pending { color: #f59e0b; } /* 新增：检测中状态 */
        .setup-btn { background: #2563eb; color: #fff; border: none; border-radius: 5px; font-size: 16px; padding: 10px 38px; cursor: pointer; transition: background 0.2; }
        .setup-btn:disabled { background: #b3b3b3; cursor: not-allowed; }
        .rewrite-block { background: #f4f6fa; border: 1px solid #dbeafe; border-radius: 6px; padding: 16px 12px; margin: 24px 0 0 0; }
        .rewrite-title { font-size: 15px; color: #2563eb; margin-bottom: 8px; font-weight: bold; }
        .rewrite-code { background: #222; color: #fff; font-size: 13px; border-radius: 4px; padding: 10px 12px; margin-bottom: 8px; white-space: pre; }
        .copy-btn { background: #2563eb; color: #fff; border: none; border-radius: 4px; padding: 4px 14px; font-size: 14px; margin-left: 8px; cursor: pointer; transition: background 0.2; }
        .copy-btn:hover { background: #1746a2; }
        .copy-tip { color: #22b573; font-size: 13px; margin-left: 8px; display: none; }
    </style>
</head>
<body class="background-anim-container">
    <div class="setup-container">
        <div class="setup-title">环境检测</div>
        <ul class="env-check-list">
            <?php foreach ($checks as $name => $item): ?>
                <li>
                    <span class="<?php echo $item['ok'] ? 'ok' : 'fail'; ?>">
                        <?php echo $item['ok'] ? '✔' : '✖'; ?>
                    </span>
                    <b><?php echo htmlspecialchars($name); ?>：</b>
                    <?php echo htmlspecialchars($item['msg']); ?>
                </li>
            <?php endforeach; ?>
            
            <!-- 伪静态检测占位符 -->
            <li id="rewrite-check-li">
                <span id="rewrite-check-icon" class="pending">...</span>
                <b>伪静态规则：</b>
                <span id="rewrite-check-msg">正在检测...</span>
            </li>
        </ul>
        <form action="setup2.php" method="get">
            <button id="next-btn" type="submit" class="setup-btn" disabled>下一步</button>
        </form>
        <div id="error-msg" style="color:#e53e3e;margin-top:16px;display:none;">请修复所有环境问题后再继续。</div>

        <!-- 伪静态规则展示区域 -->
        <div class="rewrite-block">
            <div class="rewrite-title">
                <?php if ($webServerType === 'apache'): ?>
                    Apache 伪静态规则（推荐使用 Nginx！）
                <?php elseif ($webServerType === 'nginx'): ?>
                    Nginx 伪静态规则
                <?php else: ?>
                    伪静态规则（请根据实际服务器类型选择）
                <?php endif; ?>
            </div>
            <div class="rewrite-code" id="rewriteCode">
<?php
if ($webServerType === 'apache') {
    echo htmlspecialchars($apacheRewrite);
} else {
    echo htmlspecialchars($nginxRewrite);
}
?>
            </div>
            <?php if ($webServerType === 'apache'): ?>
                <div style="color:#e53e3e;font-size:14px;margin:8px 0 0 0;">
                    检测到当前为 Apache 环境，推荐使用 Nginx 部署以获得最佳性能和兼容性！
                </div>
            <?php endif; ?>
            <button type="button" class="copy-btn" onclick="copyRewrite()">复制</button>
            <span class="copy-tip" id="copyTip">已复制！</span>
        </div>
    </div>

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

    document.addEventListener('DOMContentLoaded', function() {
        const rewriteCheckData = <?php echo json_encode($rewriteCheckData); ?>;
        const iconEl = document.getElementById('rewrite-check-icon');
        const msgEl = document.getElementById('rewrite-check-msg');

        function updateNextButtonState() {
            const hasFailures = document.querySelector('.env-check-list .fail, .env-check-list .pending');
            document.getElementById('next-btn').disabled = !!hasFailures;
            document.getElementById('error-msg').style.display = hasFailures ? 'block' : 'none';
        }

        async function checkRewriteRule() {
            if (rewriteCheckData.error) {
                iconEl.textContent = '✖';
                iconEl.className = 'fail';
                msgEl.textContent = rewriteCheckData.error;
                updateNextButtonState();
                return;
            }

            const testUrl = window.location.origin + rewriteCheckData.url;
            
            try {
                // 使用 HEAD 请求，我们只需要状态码，不需要下载文件内容，更高效
                const response = await fetch(testUrl, { method: 'HEAD', cache: 'no-cache' });

                if (response.status === 403) {
                    iconEl.textContent = '✔';
                    iconEl.className = 'ok';
                    msgEl.textContent = '已生效（访问json文件返回403）';
                } else {
                    iconEl.textContent = '✖';
                    iconEl.className = 'fail';
                    msgEl.textContent = `未生效（访问json文件返回${response.status}），请检查伪静态配置`;
                }
            } catch (error) {
                iconEl.textContent = '✖';
                iconEl.className = 'fail';
                msgEl.textContent = '检测请求失败，请检查浏览器控制台网络错误';
            } finally {
                // 无论成功失败，都更新一次按钮状态
                updateNextButtonState();
            }
        }

        // 启动检测
        checkRewriteRule();
    });
    </script>
</body>
</html>
       
