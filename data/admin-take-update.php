<?php
// 管理员特殊查询接口（查询删除状态为0且配置状态为0的域名）
// 包含公共认证文件（校验登录状态和权限）
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';

// 安全增强：强制仅允许POST方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '仅允许POST方法']);
    exit;
}

// 安全增强：CSRF令牌校验（与admin-update.php保持一致）
$receivedCsrfToken = $_POST['csrf_token'] ?? '';
if ($receivedCsrfToken !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
    exit;
}

// 设置时区和响应头（与admin-update.php统一）
date_default_timezone_set('Asia/Shanghai');
header('Content-Type: application/json; charset=utf-8');

// 数据库配置（与admin-read-update.php共用同一路径）
$dbDir = __DIR__ . '/db';
$dbFile = $dbDir . '/site_config.db';

try {
    // 连接SQLite数据库（不自动创建表）
    if (!file_exists($dbFile)) {
        throw new Exception('数据库文件不存在', 404);
    }
    $pdo = new PDO("sqlite:{$dbFile}");
// 修正常量使用错误，正确的错误模式异常常量为 PDO::ERRMODE_EXCEPTION
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 检查admin_updates表是否存在（关键逻辑：不自动创建表）
    $tableCheckStmt = $pdo->query("
        SELECT name FROM sqlite_master 
        WHERE type='table' AND name='admin_updates'
    ");
    if (!$tableCheckStmt->fetchColumn()) {
        throw new Exception('数据不存在（表未创建）', 404);
    }

    // 定义固定action：查询删除状态为0且配置状态为0的域名
    $action = $_POST['action'] ?? '';
    if ($action !== 'take_query') {
        throw new Exception('无效的action参数（仅支持take_query）', 400);
    }

    // 执行查询（config_status=0）
    $stmt = $pdo->prepare("
        SELECT domain 
        FROM admin_updates 
        WHERE config_status = 0
    ");
    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_COLUMN, 0); // 仅获取domain列

    // 新增：判断是否存在待处理域名
    if (empty($result)) {
        $response = [
            'success' => true,
            'message' => '不存在待生成配置文件的域名',
            'data' => []
        ];
    } else {
        $response = [
            'success' => true,
            'message' => '查询成功',
            'data' => $result
        ];
    }

    echo json_encode($response);

} catch (Exception $e) {
    // 异常处理（与admin-update.php保持一致）
    $statusCode = (int)$e->getCode() ?: 500;
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'debug' => $statusCode === 500 ? '数据库或服务器内部错误' : ''
    ]);
}
exit;
?>