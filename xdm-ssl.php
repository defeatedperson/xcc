<?php
header('Content-Type: application/json; charset=utf-8');

// 仅允许POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '仅允许POST方法']);
    exit;
}

// 数据库文件路径
$dbFile = __DIR__ . '/data/db/xdm.db';

// 确保db目录存在
$dbDir = __DIR__ . '/data/db';
if (!is_dir($dbDir)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '数据库目录不存在']);
    exit;
}

// 验证API密钥
$apikey = $_POST['apikey'] ?? '';
if (empty($apikey)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '缺少API密钥']);
    exit;
}

// 连接数据库并验证API密钥
try {
    $db = new SQLite3($dbFile);
    $db->enableExceptions(true);
    
    // 查询API密钥
    $stmt = $db->prepare('SELECT apikey FROM api_keys WHERE id = 1');
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$row || $row['apikey'] !== $apikey) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'API密钥验证失败']);
        $db->close();
        exit;
    }
    
    $db->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '数据库连接失败: ' . $e->getMessage()]);
    exit;
}

// 验证是否上传了证书文件
if (!isset($_FILES['cert']) || !isset($_FILES['key'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少证书文件或密钥文件']);
    exit;
}

// 获取域名参数
$domain = $_POST['domain'] ?? '';
if (empty($domain)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少域名参数']);
    exit;
}

// 验证域名格式
if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-\.]{1,61}[a-zA-Z0-9]\.[a-zA-Z]{2,}$/', $domain)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '域名格式无效']);
    exit;
}

// 证书存储目录
$certDir = __DIR__ . '/data/config/certs';
if (!is_dir($certDir)) {
    if (!mkdir($certDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '无法创建证书目录']);
        exit;
    }
}

// 处理证书文件上传
$certFile = $_FILES['cert'];
$keyFile = $_FILES['key'];

// 检查文件上传错误
if ($certFile['error'] !== UPLOAD_ERR_OK || $keyFile['error'] !== UPLOAD_ERR_OK) {
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE => '文件大小超过php.ini中upload_max_filesize的限制',
        UPLOAD_ERR_FORM_SIZE => '文件大小超过表单中MAX_FILE_SIZE的限制',
        UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
        UPLOAD_ERR_NO_FILE => '没有文件被上传',
        UPLOAD_ERR_NO_TMP_DIR => '找不到临时文件夹',
        UPLOAD_ERR_CANT_WRITE => '文件写入失败',
        UPLOAD_ERR_EXTENSION => '文件上传被扩展程序中断'
    ];
    
    $certErrorMsg = $certFile['error'] !== UPLOAD_ERR_OK ? ($errorMessages[$certFile['error']] ?? '未知错误') : '';
    $keyErrorMsg = $keyFile['error'] !== UPLOAD_ERR_OK ? ($errorMessages[$keyFile['error']] ?? '未知错误') : '';
    
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '文件上传失败: ' . $certErrorMsg . ' ' . $keyErrorMsg]);
    exit;
}

// 验证证书文件内容
$certContent = file_get_contents($certFile['tmp_name']);
$keyContent = file_get_contents($keyFile['tmp_name']);

// 简单验证证书格式
if (!preg_match('/-----BEGIN CERTIFICATE-----/', $certContent) || 
    !preg_match('/-----END CERTIFICATE-----/', $certContent)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '无效的证书文件格式']);
    exit;
}

// 简单验证密钥格式
if (!preg_match('/-----BEGIN (RSA |EC |)PRIVATE KEY-----/', $keyContent) || 
    !preg_match('/-----END (RSA |EC |)PRIVATE KEY-----/', $keyContent)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '无效的密钥文件格式']);
    exit;
}

// 保存证书和密钥文件
$certFilePath = $certDir . '/' . $domain . '.crt';
$keyFilePath = $certDir . '/' . $domain . '.key';

try {
    // 写入证书文件
    if (file_put_contents($certFilePath, $certContent) === false) {
        throw new Exception('无法写入证书文件');
    }
    
    // 写入密钥文件
    if (file_put_contents($keyFilePath, $keyContent) === false) {
        // 如果密钥写入失败，删除已写入的证书文件
        @unlink($certFilePath);
        throw new Exception('无法写入密钥文件');
    }
    
    // 设置文件权限
    chmod($certFilePath, 0644);
    chmod($keyFilePath, 0644);
    
    // 更新domains.json中的时间
    $domainsJsonPath = __DIR__ . '/data/config/domains.json';
    $currentTime = date('Y-m-d H:i:s');
    $domainFound = false;
    
    if (file_exists($domainsJsonPath)) {
        $domainsJson = file_get_contents($domainsJsonPath);
        $domains = json_decode($domainsJson, true);
        
        // 查找并更新域名的时间
        foreach ($domains as &$item) {
            if ($item['domain'] === $domain) {
                $item['update_time'] = $currentTime;
                $domainFound = true;
                break;
            }
        }
        
        // 如果找到域名，则更新domains.json文件
        if ($domainFound) {
            file_put_contents($domainsJsonPath, json_encode($domains, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            // 在后台触发同步任务
            triggerSyncTaskBackground();
        }
        // 如果未找到域名，则跳过更新和同步任务
    }
    
    // 立即返回成功响应
    echo json_encode([
        'success' => true, 
        'message' => '证书上传成功' . ($domainFound ? '，同步任务已在后台启动' : '，但域名不在列表中，跳过同步'),
        'data' => [
            'domain' => $domain,
            'cert_path' => $certFilePath,
            'key_path' => $keyFilePath,
            'upload_time' => date('Y-m-d H:i:s'),
            'domain_found' => $domainFound
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '保存证书文件失败: ' . $e->getMessage()]);
    exit;
}

/**
 * 在后台触发同步任务
 */
function triggerSyncTaskBackground() {
    // 检查锁文件，如果有任务正在执行，则不启动新任务，但不影响当前请求返回
    $lockFile = __DIR__ . '/api/logs/update-worker.lock';
    $fp = fopen($lockFile, 'c+');
    if (!$fp) {
        // 无法创建锁文件，但不影响当前请求
        return;
    }
    
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        // 已有任务在执行，记录日志但不影响当前请求
        fclose($fp);
        $rejectLogFile = __DIR__ . '/api/logs/reject.log';
        $ts = date('Y-m-d H:i:s');
        $msg = "[$ts] [SSL证书同步] 已有同步任务未完成，跳过本次同步\n";
        file_put_contents($rejectLogFile, $msg, FILE_APPEND);
        return;
    }
    
    // 可以启动新任务，释放锁（实际任务会重新获取锁）
    flock($fp, LOCK_UN);
    fclose($fp);
    
    // 启动后台进程
    $phpPath = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php';
    $workerScript = __DIR__ . '/update-worker.php';
    
    // 根据操作系统选择不同的执行方式
    if (stripos(PHP_OS, 'WIN') === 0) {
        // Windows
        $cmd = "start /B \"\" \"{$phpPath}\" \"{$workerScript}\"";
        @exec($cmd);
    } else {
        // Linux/Unix
        $cmd = "{$phpPath} \"{$workerScript}\" > /dev/null 2>&1 &";
        @exec($cmd);
    }
}
?>