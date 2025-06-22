<?php
// SSL证书读取模块（根据域名查询SQLite数据库）

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

// 修改参数获取方式（GET → POST）
if (!isset($_POST['domain']) || empty($_POST['domain'])) { // 关键修改：$_GET → $_POST
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少必要参数：domain']);
    exit;
}
$domain = trim($_POST['domain']); // 关键修改：$_GET → $_POST

try {
    // 连接数据库
    $pdo = new PDO("sqlite:{$dbFile}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 与 ssl-write.php 保持一致的表结构（移除tls_version字段）
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ssl_certs (
            domain TEXT PRIMARY KEY NOT NULL,
            update_time DATETIME NOT NULL DEFAULT (datetime('now', 'localtime')),
            ssl_cert TEXT NOT NULL,
            ssl_key TEXT NOT NULL,
            expiry_time DATETIME NOT NULL,
            force_https INTEGER NOT NULL DEFAULT 0  -- 保留字段
        )
    ");

    // 查询证书信息（移除tls_version字段）
    $stmt = $pdo->prepare("
        SELECT ssl_cert, ssl_key, force_https, expiry_time  -- 移除tls_version
        FROM ssl_certs 
        WHERE domain = :domain
    ");
    $stmt->bindParam(':domain', $domain, PDO::PARAM_STR);
    $stmt->execute();
    $certData = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($certData) {
        echo json_encode([
            'success' => true,
            'domain' => $domain,
            'cert' => $certData['ssl_cert'],
            'key' => $certData['ssl_key'],
            'force_https' => $certData['force_https'],
            'expiry_time' => $certData['expiry_time']  // 移除tls_version字段
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => '证书不存在'
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '数据库错误：' . $e->getMessage()
    ]);
}
?>