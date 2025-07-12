<?php
// 自定义回源域名设置接口
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';

// 仅允许POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '仅允许POST方法']);
    exit;
}

// CSRF校验
$receivedCsrfToken = $_POST['csrf_token'] ?? '';
if ($receivedCsrfToken !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
    exit;
}

date_default_timezone_set('Asia/Shanghai');
header('Content-Type: application/json; charset=utf-8');

$dbDir = __DIR__ . '/db';
$dbFile = $dbDir . '/site_config.db';

try {
    // 参数校验
    if (!isset($_POST['domain']) || empty(trim($_POST['domain']))) {
        throw new Exception('缺少必要参数：domain', 400);
    }
    $domain = trim($_POST['domain']);

    // 域名格式校验
    $domainPattern = '/^[a-zA-Z0-9-]+(\.[a-zA-Z0-9-]+)*\.[a-zA-Z]{2,}$/';
    if (!preg_match($domainPattern, $domain)) {
        throw new Exception('域名格式无效（示例：example.com 或 sub.example.com）', 400);
    }

    // 连接数据库
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0755, true);
    }
    $pdo = new PDO("sqlite:{$dbFile}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 创建表
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS more_settings (
            domain TEXT PRIMARY KEY,
            origin_domain TEXT,
            origin_enabled INTEGER NOT NULL DEFAULT 0,
            hsts_enabled INTEGER NOT NULL DEFAULT 0,
            update_time DATETIME NOT NULL DEFAULT (datetime('now', 'localtime'))
        )
    ");

    $action = $_POST['action'] ?? 'save';

    if ($action === 'get') {
        // 读取设置
        $stmt = $pdo->prepare("SELECT origin_domain, origin_enabled, hsts_enabled FROM more_settings WHERE domain = :domain");
        $stmt->bindParam(':domain', $domain);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $row ?: [
            'origin_domain' => '',
            'origin_enabled' => 0,
            'hsts_enabled' => 0
        ]]);
    } else {
        // 保存设置
        $origin_domain = trim($_POST['origin_domain'] ?? '');
        $origin_enabled = isset($_POST['origin_enabled']) ? (intval($_POST['origin_enabled']) ? 1 : 0) : 0;
        $hsts_enabled = isset($_POST['hsts_enabled']) ? (intval($_POST['hsts_enabled']) ? 1 : 0) : 0;

        // 回源域名长度和内容校验（只允许字母、数字、冒号和小数点，最长100）
        if ($origin_enabled) {
            if ($origin_domain === '') {
                throw new Exception('已开启自定义回源域名，请填写回源域名', 400);
            }
            if (strlen($origin_domain) > 100 || !preg_match('/^[a-zA-Z0-9:.]*$/', $origin_domain)) {
                throw new Exception('回源域名格式无效，只允许字母、数字、冒号、小数点，且最长100字符', 400);
            }
        }

        $stmt = $pdo->prepare("
            REPLACE INTO more_settings
            (domain, origin_domain, origin_enabled, hsts_enabled, update_time)
            VALUES (:domain, :origin_domain, :origin_enabled, :hsts_enabled, datetime('now', 'localtime'))
        ");
        $stmt->execute([
            ':domain' => $domain,
            ':origin_domain' => $origin_domain,
            ':origin_enabled' => $origin_enabled,
            ':hsts_enabled' => $hsts_enabled
        ]);
        echo json_encode([
            'success' => true,
            'message' => '设置保存成功',
            'update_time' => date('Y-m-d H:i:s')
        ]);
    }
} catch (Exception $e) {
    http_response_code($e->getCode() >= 400 ? $e->getCode() : 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}