<?php
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
// SSL证书写入模块（插入/更新SQLite数据库）
// 新增：设置 PHP 时区（与 SQLite 的 localtime 一致）
date_default_timezone_set('Asia/Shanghai');
header('Content-Type: application/json; charset=utf-8');

// 数据库配置（与read.php保持一致）
$dbDir = __DIR__ . '/db';
$dbFile = $dbDir . '/site_config.db';

// 校验请求参数
if (!isset($_POST['domain']) || empty($_POST['domain']) || 
    !isset($_POST['ssl_cert']) || empty($_POST['ssl_cert']) || 
    !isset($_POST['ssl_key']) || empty($_POST['ssl_key'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少必要参数：domain、ssl_cert或ssl_key']);
    exit;
}

$domain = trim($_POST['domain']);
$sslCert = trim($_POST['ssl_cert']);
$sslKey = trim($_POST['ssl_key']);

// 新增：获取强制HTTPS和TLS版本参数（关键修复）
$forceHttps = isset($_POST['force_https']) ? (int)$_POST['force_https'] : 0;  // 确保为整数

// 调整：仅检查证书包含PEM标记（不强制解析内容）
if (!str_contains($sslCert, '-----BEGIN CERTIFICATE-----') || 
    !str_contains($sslCert, '-----END CERTIFICATE-----')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '证书格式不完整（缺少PEM标记）']);
    exit;
}

// 调整：仅检查私钥包含PEM标记（不强制解析类型）
if (!str_contains($sslKey, '-----BEGIN ') || 
    !str_contains($sslKey, '-----END ')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '私钥格式不完整（缺少PEM标记）']);
    exit;
}

// 保留：验证证书域名匹配（Nginx需要证书域名与站点一致）
// 提取第一个证书的CN（简化解析，仅匹配第一行CN）
if (preg_match('/CN=([^,]+)/', $sslCert, $matches)) {
    $certDomain = trim($matches[1]);
    if ($certDomain !== $domain) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "证书域名不匹配（证书CN：{$certDomain}）"]);
        exit;
    }
} else {
    // 若无法提取CN，仅提示但允许保存（Nginx可能仍能加载）
    error_log("证书未找到CN字段：{$domain}");
}

// 提取到期时间（转换为本地时间）
// 解析证书内容获取有效期
$certInfo = openssl_x509_parse($sslCert);
if (!$certInfo || !isset($certInfo['validTo_time_t'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '证书无法解析有效期']);
    exit;
}
$expiryTime = date('Y-m-d H:i:s', $certInfo['validTo_time_t']);

try {
    // 连接数据库
    $pdo = new PDO("sqlite:{$dbFile}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 开启事务（关键修改）
    $pdo->beginTransaction();

    // 自动创建表（移除tls_version字段）
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ssl_certs (
            domain TEXT PRIMARY KEY NOT NULL,
            update_time DATETIME NOT NULL DEFAULT (datetime('now', 'localtime')),
            ssl_cert TEXT NOT NULL,
            ssl_key TEXT NOT NULL,
            expiry_time DATETIME NOT NULL,
            force_https INTEGER NOT NULL DEFAULT 0
        )
    ");

    // 使用REPLACE INTO实现插入或更新（移除tls_version字段）
    $stmt = $pdo->prepare("
    REPLACE INTO ssl_certs (domain, ssl_cert, ssl_key, expiry_time, force_https) 
    VALUES (:domain, :ssl_cert, :ssl_key, :expiry_time, :force_https)
    ");
    $stmt->bindParam(':domain', $domain, PDO::PARAM_STR);
    $stmt->bindParam(':ssl_cert', $sslCert, PDO::PARAM_STR);
    $stmt->bindParam(':ssl_key', $sslKey, PDO::PARAM_STR);
    $stmt->bindParam(':expiry_time', $expiryTime, PDO::PARAM_STR);
    $stmt->bindParam(':force_https', $forceHttps, PDO::PARAM_INT);
    $stmt->execute();  // 操作1：写入ssl_certs表

    // 新增：更新site_config表的ssl_enabled为1（操作2）
    $updateStmt = $pdo->prepare("UPDATE site_config SET ssl_enabled = 1 WHERE domain = :domain");
    $updateStmt->bindParam(':domain', $domain, PDO::PARAM_STR);
    $updateStmt->execute();

    // 提交事务（关键修改）
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => '证书保存成功',
        'update_time' => date('Y-m-d H:i:s'),
        'expiry_time' => $expiryTime
    ]);

} catch (PDOException $e) {
    // 事务回滚（关键修改：若任意操作失败，回滚所有变更）
    if (isset($pdo)) {
        $pdo->rollback();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '数据库错误：' . $e->getMessage()
    ]);
}
?>