<?php
// SSL配置读取与修改模块（根据域名查询/更新SQLite数据库）

// 或先定义根目录常量，再引用（更推荐）
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';
// 新增：强制仅允许POST方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => '仅允许POST方法']);
    exit;
}
// 在 data/read.php 和 api/read_sql.php 的请求处理前添加
$receivedCsrfToken = $_POST['csrf_token'] ?? '';
if ($receivedCsrfToken !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
    exit;
}
header('Content-Type: application/json; charset=utf-8');

// 数据库配置（与write.php保持一致）
$dbDir = __DIR__ . '/db';
$dbFile = $dbDir . '/site_config.db';

// 判断是读取操作还是写入操作
$action = isset($_POST['action']) ? $_POST['action'] : 'read';

// 修改：对于list_auto_ssl操作不需要domain参数
if ($action !== 'list_auto_ssl') {
    // 修改参数获取方式（GET → POST）
    if (!isset($_POST['domain']) || empty($_POST['domain'])) { // 关键修改：$_GET → $_POST
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少必要参数：domain']);
        exit;
    }
    $domain = trim($_POST['domain']); // 关键修改：$_GET → $_POST
}

// 自动创建数据库目录（若不存在）
if (!is_dir($dbDir)) {
    if (!mkdir($dbDir, 0755, true)) {  // 递归创建目录，权限0755
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => "数据库目录 {$dbDir} 创建失败"]);
        exit;
    }
}

try {
    // 连接数据库
    $pdo = new PDO("sqlite:{$dbFile}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 检查ssl_certs表是否存在，如果存在则删除
    // 修改：使用try-catch处理可能的锁定错误
    try {
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='ssl_certs';");
        if ($stmt && $stmt->fetch()) {
            $pdo->exec("DROP TABLE IF EXISTS ssl_certs");
        }
    } catch (PDOException $e) {
        // 如果表被锁定，忽略错误继续执行
        // 通常这不会影响后续操作，因为我们只是尝试删除旧表
    }
    
    // 创建新的域名HTTPS配置表
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS domain_https_config (
            domain TEXT PRIMARY KEY NOT NULL,
            update_time DATETIME NOT NULL DEFAULT (datetime('now', 'localtime')),
            https_enabled INTEGER NOT NULL DEFAULT 0,
            force_https INTEGER NOT NULL DEFAULT 0,
            auto_ssl INTEGER NOT NULL DEFAULT 0
        )
    ");

    // 判断是读取操作还是写入操作
    $action = isset($_POST['action']) ? $_POST['action'] : 'read';

    if ($action === 'write') {
        // 开启事务，确保两个表的更新是原子操作
        $pdo->beginTransaction();
        
        try {
            // 写入/更新操作
            // 获取布尔值参数，默认为false
            $https_enabled = isset($_POST['https_enabled']) ? filter_var($_POST['https_enabled'], FILTER_VALIDATE_BOOLEAN) : false;
            $force_https = isset($_POST['force_https']) ? filter_var($_POST['force_https'], FILTER_VALIDATE_BOOLEAN) : false;
            $auto_ssl = isset($_POST['auto_ssl']) ? filter_var($_POST['auto_ssl'], FILTER_VALIDATE_BOOLEAN) : false;
            
            // 将布尔值转换为整数存储
            $https_enabled_int = $https_enabled ? 1 : 0;
            $force_https_int = $force_https ? 1 : 0;
            $auto_ssl_int = $auto_ssl ? 1 : 0;
            
            // 插入或更新domain_https_config表
            $stmt = $pdo->prepare("
                INSERT OR REPLACE INTO domain_https_config 
                (domain, update_time, https_enabled, force_https, auto_ssl) 
                VALUES (:domain, datetime('now', 'localtime'), :https_enabled, :force_https, :auto_ssl)
            ");
            
            $stmt->bindParam(':domain', $domain, PDO::PARAM_STR);
            $stmt->bindParam(':https_enabled', $https_enabled_int, PDO::PARAM_INT);
            $stmt->bindParam(':force_https', $force_https_int, PDO::PARAM_INT);
            $stmt->bindParam(':auto_ssl', $auto_ssl_int, PDO::PARAM_INT);
            
            $stmt->execute();
            
            // 检查site_config表是否存在，如果不存在则跳过更新
            $checkTableStmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='site_config';");
            $tableExists = $checkTableStmt && $checkTableStmt->fetch();
            
            if ($tableExists) {
                // 检查域名是否存在于site_config表中
                $checkDomainStmt = $pdo->prepare("SELECT domain FROM site_config WHERE domain = :domain");
                $checkDomainStmt->bindParam(':domain', $domain, PDO::PARAM_STR);
                $checkDomainStmt->execute();
                $domainExists = $checkDomainStmt->fetch();
                
                if ($domainExists) {
                    // 同步更新site_config表中的ssl_enabled状态
                    $updateSiteConfig = $pdo->prepare("
                        UPDATE site_config 
                        SET ssl_enabled = :ssl_enabled 
                        WHERE domain = :domain
                    ");
                    
                    $updateSiteConfig->bindParam(':domain', $domain, PDO::PARAM_STR);
                    $updateSiteConfig->bindParam(':ssl_enabled', $https_enabled_int, PDO::PARAM_INT);
                    $updateSiteConfig->execute();
                }
                // 如果域名不存在于site_config表中，不进行更新操作
            }
            // 如果site_config表不存在，不进行更新操作
            
            // 提交事务
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'message' => '域名HTTPS配置已更新',
                'domain' => $domain,
                'https_enabled' => $https_enabled,
                'force_https' => $force_https,
                'auto_ssl' => $auto_ssl
            ]);
        } catch (Exception $e) {
            // 如果发生错误，回滚事务
            $pdo->rollBack();
            throw $e;
        }
    } else if ($action === 'list_auto_ssl') {
        // 新增：查询所有开启了自动SSL的域名
        // 这个操作不需要domain参数
        $stmt = $pdo->prepare("SELECT domain FROM domain_https_config WHERE auto_ssl = 1");
        $stmt->execute();
        $domains = [];
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $domains[] = $row['domain'];
        }
        
        echo json_encode([
            'success' => true,
            'data' => $domains
        ]);
        exit;
    } else {
        // 读取操作
        $stmt = $pdo->prepare("
            SELECT https_enabled, force_https, auto_ssl
            FROM domain_https_config 
            WHERE domain = :domain
        ");
        $stmt->bindParam(':domain', $domain, PDO::PARAM_STR);
        $stmt->execute();
        $configData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($configData) {
            echo json_encode([
                'success' => true,
                'domain' => $domain,
                'https_enabled' => (bool)$configData['https_enabled'],
                'force_https' => (bool)$configData['force_https'],
                'auto_ssl' => (bool)$configData['auto_ssl']
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => '域名HTTPS配置不存在'
            ]);
        }
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '数据库错误：' . $e->getMessage()
    ]);
}
?>