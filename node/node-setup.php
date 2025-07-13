<?php
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '仅允许POST方法']);
    exit;
}

$receivedCsrfToken = $_POST['csrf_token'] ?? '';
if ($receivedCsrfToken !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
    exit;
}

// 判断是否为开启安装模式
$action = $_POST['action'] ?? '';
$setupShellPath = __DIR__ . '/setup-node.sh';

if ($action === 'close_install') {
    // 删除 setup-node.sh、get.json 和 bash.json 文件（如果存在）
    $result = true;
    if (is_file($setupShellPath)) {
        $result = $result && @unlink($setupShellPath);
    }
    $getJsonPath = __DIR__ . '/get.json';
    if (is_file($getJsonPath)) {
        $result = $result && @unlink($getJsonPath);
    }
    $bashJsonPath = __DIR__ . '/bash.json';
    if (is_file($bashJsonPath)) {
        $result = $result && @unlink($bashJsonPath);
    }
    echo json_encode([
        'success' => $result,
        'install_mode' => 0
    ]);
    exit;
}

if ($action === 'start_install') {
    // 1. 生成token
    $token = bin2hex(random_bytes(24));

    // 2. 读取 site.json 获取站点地址
    $siteJsonPath = __DIR__ . '/site.json';
    if (!is_file($siteJsonPath)) {
        echo json_encode(['success' => false, 'message' => '未找到site.json', 'install_mode' => 0]);
        exit;
    }
    $siteData = json_decode(file_get_contents($siteJsonPath), true);
    $siteUrl = $siteData['site_url'] ?? '';
    if (!$siteUrl) {
        echo json_encode(['success' => false, 'message' => 'site.json中未配置site_url', 'install_mode' => 0]);
        exit;
    }

    // 3. 生成下载地址（给脚本用的 zip 包下载地址）
    $downloadUrl = rtrim($siteUrl, '/') . '/node/node-get.php?token=' . $token;

    // 生成 setup-node.sh 的访问地址（给用户用的安装命令）
    $setupShellUrl = rtrim($siteUrl, '/') . '/node/setup-node.sh';

    // 拼接多步一键命令
    $installCommand = 'curl -fsSL ' . $setupShellUrl . ' -o setup-node.sh && chmod +x setup-node.sh && ./setup-node.sh';
    
    // 4. 从数据库获取 apikey 和 nodekey 数据
    // 数据库文件路径
    $dbFile = $_SERVER['DOCUMENT_ROOT'] . '/data/db/xdm.db';
    $hasXdmData = false;
    
    if (file_exists($dbFile)) {
        try {
            $db = new SQLite3($dbFile);
            $db->enableExceptions(true);
            
            // 查询API密钥
            $result = $db->query('SELECT apikey, nodekey FROM api_keys WHERE id = 1');
            if ($result) {
                $row = $result->fetchArray(SQLITE3_ASSOC);
                if ($row && !empty($row['apikey']) && !empty($row['nodekey'])) {
                    $masterKey = $row['apikey'];
                    $nodeKey = $row['nodekey'];
                    $hasXdmData = true;
                }
            }
            
            $db->close();
        } catch (Exception $e) {
            // 如果数据库访问失败，记录错误日志
            error_log('无法访问XDM数据库: ' . $e->getMessage());
        }
    }
    
    // 如果数据库不存在或无数据，返回提示信息
    if (!$hasXdmData) {
        echo json_encode([
            'success' => false, 
            'message' => 'xdm扩展未设置，相关组件将无法安装',
            'install_mode' => 0
        ]);
        exit;
    }

    // 5. 读取php模板并替换变量
    $templatePath = __DIR__ . '/setup-template.php';
    if (!is_file($templatePath)) {
        echo json_encode(['success' => false, 'message' => '未找到模板文件', 'install_mode' => 0]);
        exit;
    }
    $templateContent = file_get_contents($templatePath);
    $renderedContent = str_replace(
        ['{{DOWNLOAD_URL}}', '{{MASTER_ADDR}}', '{{MASTER_KEY}}', '{{NODE_KEY}}'],
        [
            htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8'), 
            htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($masterKey, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($nodeKey, ENT_QUOTES, 'UTF-8')
        ],
        $templateContent
    );
    
    // 6. 创建setup-node.sh文件（最终生成的文件）
    file_put_contents($setupShellPath, $renderedContent);

    // 7. 创建get.json文件，存入加密的token内容
    $getJsonPath = __DIR__ . '/get.json';
    $tokenHash = password_hash($token, PASSWORD_DEFAULT);
    file_put_contents($getJsonPath, json_encode(['token' => $tokenHash], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    // 8. 新增：生成 bash.json，存放安装命令
    $bashJsonPath = __DIR__ . '/bash.json';
    file_put_contents($bashJsonPath, json_encode(['install_command' => $installCommand], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    // 9. 返回渲染后的内容和安装命令
    echo json_encode([
        'success' => true,
        'download_url' => $downloadUrl,
        'install_command' => $installCommand,
        'template' => $renderedContent,
        'install_mode' => 1,
        'master_key' => $masterKey,
        'node_key' => $nodeKey
    ]);
    exit;
}

// 判断是否处于安装模式（setup-node.sh 是否存在）
if (is_file($setupShellPath)) {
    // 新增：如果 bash.json 存在，读取安装命令
    $bashJsonPath = __DIR__ . '/bash.json';
    $installCommand = '';
    if (is_file($bashJsonPath)) {
        $bashData = json_decode(file_get_contents($bashJsonPath), true);
        $installCommand = $bashData['install_command'] ?? '';
    }
    echo json_encode(['success' => true, 'install_mode' => 1, 'install_command' => $installCommand]);
} else {
    echo json_encode(['success' => true, 'install_mode' => 0]);
}
exit;
