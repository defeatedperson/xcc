<?php
// 管理员配置读取/删除接口（支持分页查询和域名删除）
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

// 数据库配置（与admin-update.php共用同一路径）
$dbDir = __DIR__ . '/db';
$dbFile = $dbDir . '/site_config.db';

try {
    // 自动创建数据库目录（兼容Linux系统）
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0755, true); // 递归创建目录，权限0755（Linux标准）
    }

    // 连接SQLite数据库（自动创建不存在的文件）
    $pdo = new PDO("sqlite:{$dbFile}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 自动创建admin_updates表（若不存在，与admin-update.php保持一致）
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_updates (
            domain TEXT NOT NULL PRIMARY KEY,
            config_status BOOLEAN NOT NULL,
            sync_status BOOLEAN NOT NULL,
            update_time DATETIME NOT NULL DEFAULT (datetime('now', 'localtime'))
        )
    ");

    // 获取操作类型（读取或删除）
    $action = $_POST['action'] ?? '';

    // -------------------------- 分页读取逻辑 --------------------------
    if ($action === 'list') {
        // 分页参数校验（参考read.php）
        $page = isset($_POST['page']) ? max(1, (int)$_POST['page']) : 1; // 页码，至少为1
        $pageSize = isset($_POST['pageSize']) ? max(1, (int)$_POST['pageSize']) : 3; // 默认每页3条
        $offset = ($page - 1) * $pageSize;

        // 查询总记录数
        $countStmt = $pdo->query("SELECT COUNT(*) FROM admin_updates");
        $total = (int)$countStmt->fetchColumn();

        // 查询分页数据（按更新时间倒序排列）
        $stmt = $pdo->prepare("
            SELECT domain, config_status, sync_status, update_time 
            FROM admin_updates 
            ORDER BY update_time DESC 
            LIMIT :pageSize OFFSET :offset
        ");
        $stmt->bindValue(':pageSize', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $dataList = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response = [
            'success' => true,
            'data' => $dataList,
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total
        ];

    } else {
        throw new Exception('无效的action参数（可选值：list）', 400);
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