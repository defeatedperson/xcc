<?php
// 缓存规则管理接口（适配前端重构后的新表结构）
// 包含公共认证文件（校验登录状态和权限）
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';

// 强制仅允许POST方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '仅允许POST方法']);
    exit;
}

// CSRF验证
$receivedCsrfToken = $_POST['csrf_token'] ?? '';
if ($receivedCsrfToken !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
    exit;
}

// 设置时区和响应头
date_default_timezone_set('Asia/Shanghai');
header('Content-Type: application/json; charset=utf-8');

// 数据库配置
$dbDir = __DIR__ . '/db';
$dbFile = $dbDir . '/site_config.db';

try {
    // 步骤1：检查并创建数据库目录
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0755, true); // Linux权限755，递归创建
    }

    // 步骤2：连接SQLite数据库
    $pdo = new PDO("sqlite:{$dbFile}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 步骤3：初始化新数据表（核心修改）
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS domain_cache_settings (
        domain TEXT PRIMARY KEY NOT NULL,
        enabled BOOLEAN NOT NULL DEFAULT 0,
        suffix TEXT,
        cache_time TEXT,  -- 存储时间单位（如：3600s/1h）
        update_time DATETIME NOT NULL DEFAULT (datetime('now', 'localtime'))
    )
    ");

    // 新增：根据action参数处理读写操作
    $action = $_POST['action'] ?? 'init'; // 默认初始化操作
    $domain = trim($_POST['domain'] ?? '');

    switch ($action) {
        case 'init':
            // 原初始化响应（兼容旧逻辑）
            echo json_encode([
                'success' => true,
                'message' => '缓存设置数据表初始化完成',
                'table_name' => 'domain_cache_settings'
            ]);
            break;

        case 'get':
            // 读取指定域名的缓存设置
            if (empty($domain)) {
                throw new Exception('缺少必要参数：domain', 400);
            }
            // 校验域名格式（示例：example.com）
            if (!preg_match('/^[a-zA-Z0-9-]+(\.[a-zA-Z0-9-]+)*\.[a-zA-Z]{2,}$/', $domain)) {
                throw new Exception('域名格式无效', 400);
            }

            $stmt = $pdo->prepare("
                SELECT enabled, suffix, cache_time, update_time 
                FROM domain_cache_settings 
                WHERE domain = :domain
            ");
            $stmt->bindParam(':domain', $domain);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'message' => '缓存设置读取成功',
                'data' => $result ?: [] // 无记录时返回空数组
            ]);
            break;

        case 'save':
            // 写入/更新指定域名的缓存设置
            $enabled = (bool)($_POST['enabled'] ?? 0);
            $suffix = trim($_POST['suffix'] ?? '');
            $cacheTime = trim($_POST['cache_time'] ?? '');

            // 校验必要参数（domain和enabled必须存在）
            if (empty($domain)) {
                throw new Exception('缺少必要参数：domain', 400);
            }
            if (!isset($_POST['enabled'])) {
                throw new Exception('缺少必要参数：enabled', 400);
            }

            // 使用REPLACE INTO实现插入或更新（因domain是主键）
            $stmt = $pdo->prepare("
                REPLACE INTO domain_cache_settings 
                (domain, enabled, suffix, cache_time, update_time)
                VALUES (:domain, :enabled, :suffix, :cache_time, datetime('now', 'localtime'))
            ");
            $stmt->bindParam(':domain', $domain);
            $stmt->bindParam(':enabled', $enabled, PDO::PARAM_BOOL);
            $stmt->bindParam(':suffix', $suffix);
            $stmt->bindParam(':cache_time', $cacheTime);
            $stmt->execute();

            echo json_encode([
                'success' => true,
                'message' => '缓存设置保存成功',
                'domain' => $domain
            ]);
            break;

        default:
            throw new Exception('无效的action参数（可选值：init/get/save）', 400);
    }

} catch (Exception $e) {
    $statusCode = (int)$e->getCode();
    if ($statusCode < 100 || $statusCode > 599) {
        $statusCode = 500;
    }
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'debug' => $statusCode === 500 ? '数据库或服务器内部错误' : ''
    ]);
}
exit;
?>