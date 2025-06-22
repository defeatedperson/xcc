<?php
// 定义根目录常量（安全引用认证文件）
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';

// 安全增强：强制仅允许POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '仅允许POST方法']);
    exit;
}

// 安全增强：CSRF令牌校验
$receivedCsrfToken = $_POST['csrf_token'] ?? '';
if ($receivedCsrfToken !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
    exit;
}

// 数据库配置（Linux路径兼容）
$dbDir = __DIR__ . '/db';
$dbFile = $dbDir . '/nodes.db';

// 自动创建db目录（如果不存在）
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0755, true);
}

try {
    // 连接SQLite数据库（自动创建nodes.db）
    $pdo = new PDO("sqlite:{$dbFile}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 分页参数处理（与data/read.php保持一致）
    $page = isset($_POST['page']) ? max(1, (int)$_POST['page']) : 1; // 页码，至少为1
    $pageSize = isset($_POST['pageSize']) ? max(1, (int)$_POST['pageSize']) : 3; // 每页数量，固定3条
    $offset = ($page - 1) * $pageSize;

    // 查询总记录数
    $countStmt = $pdo->query("SELECT COUNT(*) FROM nodes");
    $total = (int)$countStmt->fetchColumn();

    // 分页查询节点数据（按更新时间倒序排列）
    $stmt = $pdo->prepare("
        SELECT id, node_name, update_time, node_ip, node_key 
        FROM nodes 
        ORDER BY update_time DESC 
        LIMIT :pageSize OFFSET :offset
    ");
    $stmt->bindValue(':pageSize', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 响应分页数据（仅保留查询功能）
    $result = [
        'success' => true,
        'nodes' => $nodes,
        'total' => $total,
        'page' => $page,
        'pageSize' => $pageSize
    ];

    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    // 如果是表不存在，直接返回空数据
    if (strpos($e->getMessage(), 'no such table') !== false) {
        echo json_encode([
            'success' => true,
            'nodes' => [],
            'total' => 0,
            'page' => 1,
            'pageSize' => 3
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '数据库操作失败：' . $e->getMessage()]);
    }
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>