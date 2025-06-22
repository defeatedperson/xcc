<?php
// 定义根目录常量（安全引用认证文件，与node-read.php保持一致）
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';

// 安全增强：强制仅允许POST请求（与node-read.php安全策略一致）
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '仅允许POST方法']);
    exit;
}

// 安全增强：CSRF令牌校验（与node-read.php安全策略一致）
$receivedCsrfToken = $_POST['csrf_token'] ?? '';
if ($receivedCsrfToken !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
    exit;
}

// 数据库配置（与node-read.php共用同一路径，确保数据一致性）
$dbDir = __DIR__ . '/db';
$dbFile = $dbDir . '/nodes.db';

// 自动创建db目录（如果不存在，与node-read.php逻辑一致）
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0755, true);
}

try {
    // 连接SQLite数据库（自动创建nodes.db，与node-read.php共用数据库）
    $pdo = new PDO("sqlite:{$dbFile}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 检查nodes表是否存在（关键逻辑：处理表不存在的情况）
    $tableCheckStmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='nodes'");
    $tableExists = $tableCheckStmt->fetchColumn();

    if (!$tableExists) {
        // 表不存在时返回空数组
        echo json_encode(['success' => true, 'node_ids' => []]);
        exit;
    }

    // 查询所有节点ID（仅返回id字段）
    $stmt = $pdo->query("SELECT id FROM nodes");
    $nodeIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0); // 提取第一列（id）

    // 响应结果（仅包含id数组）
    echo json_encode([
        'success' => true,
        'node_ids' => $nodeIds // 无数据时返回空数组
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '数据库操作失败：' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>