<?php
// 域名全量删除接口：删除指定域名在所有相关表中的配置信息
// 包含公共认证文件（校验登录状态和权限）
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';

// 安全校验：仅允许POST方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '仅允许POST方法']);
    exit;
}

// 安全校验：CSRF令牌验证
$receivedCsrfToken = $_POST['csrf_token'] ?? '';
if ($receivedCsrfToken !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
    exit;
}

// 基础配置：时区、响应头
date_default_timezone_set('Asia/Shanghai');
header('Content-Type: application/json; charset=utf-8');

// 数据库配置（Linux路径兼容）
$dbDir = __DIR__ . '/db';
$dbFile = $dbDir . '/site_config.db';

// 日志配置
define('LOG_DIR', __DIR__ . '/logs');
// 修改后（固定名称）
define('DELETE_LOG_FILE', LOG_DIR . '/delete_log.log');  // 固定日志文件名

/**
 * 写入删除操作日志（带域名上下文）
 * @param string $message 日志内容
 * @param string $domain 关联域名
 */
function writeDeleteLog(string $message, string $domain): void {
    // 自动创建日志目录（权限0755）
    if (!is_dir(LOG_DIR)) {
        mkdir(LOG_DIR, 0755, true);
    }
    $logContent = sprintf('[%s] [Domain: %s] %s%s', 
        date('Y-m-d H:i:s'), 
        $domain, 
        $message, 
        PHP_EOL
    );
    file_put_contents(DELETE_LOG_FILE, $logContent, FILE_APPEND | LOCK_EX);
}

try {
    // 新增：每次执行模块时清空日志文件
    if (file_exists(DELETE_LOG_FILE)) {
        file_put_contents(DELETE_LOG_FILE, '', LOCK_EX);  // 清空文件内容（LOCK_EX防止并发写入冲突）
        chmod(DELETE_LOG_FILE, 0600);  // 保持文件权限（所有者读写）
    }

    // 步骤1：参数校验（必填domain）
    if (!isset($_POST['domain']) || empty(trim($_POST['domain']))) {
        throw new Exception('缺少必要参数：domain', 400);
    }
    $domain = trim($_POST['domain']);

    // 步骤2：域名格式校验（兼容中文域名）
    $domainPattern = '/^(xn--)?[a-zA-Z0-9-]+(?:\.[a-zA-Z0-9-]+)*\.[a-zA-Z]{2,}$/';
    if (!preg_match($domainPattern, $domain)) {
        throw new Exception('域名格式无效（示例：example.com 或 sub.example.com）', 400);
    }

    // 步骤3：数据库连接与事务开启
    $pdo = new PDO("sqlite:{$dbFile}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->beginTransaction();

    // 辅助函数：检查表是否存在
    $checkTableExists = function(string $tableName) use ($pdo): bool {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
        $stmt->execute([$tableName]);
        return (bool)$stmt->fetchColumn();
    };

    // 步骤4：删除关联表数据（带表存在性检查）
    $deletedCounts = []; // 记录各表删除数量

    // 4.1 缓存规则表（cache-rules.php对应表）
    if ($checkTableExists('cache_rules')) {
        $stmt = $pdo->prepare("DELETE FROM cache_rules WHERE domain = ?");
        $stmt->execute([$domain]);
        $deletedCounts['cache_rules'] = $stmt->rowCount();
        writeDeleteLog("cache_rules表删除{$deletedCounts['cache_rules']}条记录", $domain);
    } else {
        $deletedCounts['cache_rules'] = 0;
        writeDeleteLog("cache_rules表不存在，跳过删除", $domain);
    }

    // 4.2 CC防护规则表（cc-robots.php对应表）
    if ($checkTableExists('cc_robots')) {
        $stmt = $pdo->prepare("DELETE FROM cc_robots WHERE domain = ?");
        $stmt->execute([$domain]);
        $deletedCounts['cc_robots'] = $stmt->rowCount();
        writeDeleteLog("cc_robots表删除{$deletedCounts['cc_robots']}条记录", $domain);
    } else {
        $deletedCounts['cc_robots'] = 0;
        writeDeleteLog("cc_robots表不存在，跳过删除", $domain);
    }

    // 4.3 黑白名单规则表（ip-rules.php对应表）
    if ($checkTableExists('ip_rules')) {
        $stmt = $pdo->prepare("DELETE FROM ip_rules WHERE domain = ?");
        $stmt->execute([$domain]);
        $deletedCounts['ip_rules'] = $stmt->rowCount();
        writeDeleteLog("ip_rules表删除{$deletedCounts['ip_rules']}条记录", $domain);
    } else {
        $deletedCounts['ip_rules'] = 0;
        writeDeleteLog("ip_rules表不存在，跳过删除", $domain);
    }

        // 4.4 域名HTTPS配置表（ssl-certs.php对应表）
    if ($checkTableExists('domain_https_config')) {
        $stmt = $pdo->prepare("DELETE FROM domain_https_config WHERE domain = ?");
        $stmt->execute([$domain]);
        $deletedCounts['domain_https_config'] = $stmt->rowCount();
        writeDeleteLog("domain_https_config表删除{$deletedCounts['domain_https_config']}条记录", $domain);
    } else {
        $deletedCounts['domain_https_config'] = 0;
        writeDeleteLog("domain_https_config表不存在，跳过删除", $domain);
    }

    // 4.5 主配置表（核心数据表）
    if ($checkTableExists('site_config')) {
        $stmt = $pdo->prepare("DELETE FROM site_config WHERE domain = ?");
        $stmt->execute([$domain]);
        $deletedCounts['site_config'] = $stmt->rowCount();
        writeDeleteLog("site_config表删除{$deletedCounts['site_config']}条记录", $domain);
    } else {
        $deletedCounts['site_config'] = 0;
        writeDeleteLog("site_config表不存在，跳过删除", $domain);
        $pdo->rollback();
        throw new Exception('主配置表不存在，删除终止', 500);
    }

    // 4.6 admin_updates表（联动删除）
    if ($checkTableExists('admin_updates')) {
        $stmt = $pdo->prepare("DELETE FROM admin_updates WHERE domain = ?");
        $stmt->execute([$domain]);
        $deletedCounts['admin_updates'] = $stmt->rowCount();
        writeDeleteLog("admin_updates表删除{$deletedCounts['admin_updates']}条记录", $domain);
    } else {
        $deletedCounts['admin_updates'] = 0;
        writeDeleteLog("admin_updates表不存在，跳过删除", $domain);
    }

    // 4.7 more_settings表（自定义回源设置 more.php对应表）
    if ($checkTableExists('more_settings')) {
        $stmt = $pdo->prepare("DELETE FROM more_settings WHERE domain = ?");
        $stmt->execute([$domain]);
        $deletedCounts['more_settings'] = $stmt->rowCount();
        writeDeleteLog("more_settings表删除{$deletedCounts['more_settings']}条记录", $domain);
    } else {
        $deletedCounts['more_settings'] = 0;
        writeDeleteLog("more_settings表不存在，跳过删除", $domain);
    }

    // 校验主配置是否实际删除（避免删除不存在的域名）
    if ($deletedCounts['site_config'] === 0) {
        $pdo->rollback();
        writeDeleteLog("主配置记录不存在，删除终止", $domain);
        throw new Exception('未找到该域名的主配置记录，删除终止', 404);
    }

    // 删除成功后，重置所有已生成配置域名的同步状态
    $pdo->exec("UPDATE admin_updates SET sync_status = 0 WHERE config_status = 1");

    // 提交事务（所有操作成功）
    $pdo->commit();
    writeDeleteLog("所有数据库操作提交成功", $domain);

    // 步骤5：删除关联配置文件
    $configDir = __DIR__ . '/config';
    $deletedFiles = 0;

    if (is_dir($configDir)) {
        $it = new RecursiveDirectoryIterator($configDir, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $file) {
            if ($file->isFile() && fnmatch("{$domain}.*", $file->getFilename())) {
                try {
                    unlink($file->getPathname());
                    $deletedFiles++;
                    writeDeleteLog("成功删除配置文件：{$file->getPathname()}", $domain);
                } catch (Exception $e) {
                    writeDeleteLog("删除文件失败：{$file->getPathname()}（错误：{$e->getMessage()}）", $domain);
                }
            }
        }
    } else {
        writeDeleteLog("配置目录不存在：{$configDir}", $domain);
    }

    // 返回成功响应
    echo json_encode([
        'success' => true,
        'message' => "域名 {$domain} 所有配置删除成功（数据库+文件）",
        'deleted_counts' => $deletedCounts,
        'file_deletion' => [
            'directory' => $configDir,
            'deleted_files' => $deletedFiles
        ],
        'log_file' => DELETE_LOG_FILE
    ]);

} catch (Exception $e) {
    // 事务回滚（仅当PDO连接存在时）
    if (isset($pdo) && $pdo instanceof PDO) {
        $pdo->rollback();
        writeDeleteLog("事务回滚：{$e->getMessage()}", $domain ?? '未知域名');
    }

    // 强制转换状态码为整数（避免类型错误）
    $statusCode = (int)$e->getCode();
    $statusCode = $statusCode > 0 ? $statusCode : 500;
    http_response_code($statusCode);

    // 返回错误响应
    echo json_encode([
        'success' => false,
        'message' => '删除失败：' . $e->getMessage(),
        'log_file' => DELETE_LOG_FILE
    ]);
}
?>