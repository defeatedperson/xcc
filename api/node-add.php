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

// 安全增强：CSRF令牌校验（与data/write.php保持一致）
$receivedCsrfToken = $_POST['csrf_token'] ?? '';
if ($receivedCsrfToken !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
    exit;
}

// 设置PHP时区（与SQLite的localtime一致，参考data/write.php）
date_default_timezone_set('Asia/Shanghai');
header('Content-Type: application/json; charset=utf-8');

// 数据库配置（与node-read.php共用同一路径）
$dbDir = __DIR__ . '/db';
$dbFile = $dbDir . '/nodes.db';

// 自动创建数据库目录（若不存在）
if (!is_dir($dbDir)) {
    if (!mkdir($dbDir, 0755, true)) {  // 递归创建目录，权限0755
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => "数据库目录 {$dbDir} 创建失败"]);
        exit;
    }
}

try {
    // 连接数据库并开启事务（关键优化：参考ssl-write.php的事务机制）
    $pdo = new PDO("sqlite:{$dbFile}");
    // 注册REGEXP函数（新增）
    $pdo->sqliteCreateFunction('REGEXP', function($pattern, $value) {
        return preg_match("/^{$pattern}$/i", $value);  // 严格匹配首尾
    }, 2);  // 2表示函数需要2个参数
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->beginTransaction();  // 开启事务

    // 自动创建节点表（新增：参考ssl-write.php的表结构自动创建逻辑）
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS nodes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            node_name TEXT NOT NULL CHECK (node_name REGEXP '^[a-zA-Z]{1,5}$'),  -- 移除UNIQUE约束
            node_ip TEXT NOT NULL,
            node_key TEXT NOT NULL,
            update_time DATETIME NOT NULL DEFAULT (datetime('now', 'localtime'))
        )
    ");

    // --------------------------
    // 步骤1：基础参数校验
    // --------------------------
    if (!isset($_POST['node_name']) || !isset($_POST['node_ip']) || !isset($_POST['node_key'])) {
        throw new Exception('缺少必要参数：node_name（节点名称）、node_ip（节点IP）、node_key（节点密钥）', 400);
    }

    $nodeName = trim($_POST['node_name']);
    $nodeIp = trim($_POST['node_ip']);
    $nodeKey = trim($_POST['node_key']);

    // --------------------------
    // 步骤2：节点名称格式校验（与node-read.php表结构约束一致）
    // --------------------------
    if (!preg_match('/^[a-zA-Z]{1,5}$/', $nodeName)) {
        throw new Exception('节点名称格式错误（要求1-5位纯字母）', 400);
    }

    // 新增：节点密钥长度校验（16-32位）
    if (strlen($nodeKey) < 16 || strlen($nodeKey) > 32) {
        throw new Exception('节点密钥长度需为16-32位', 400);
    }

    // --------------------------
    // 步骤3：插入新节点（使用事务保证原子性）
    // --------------------------
    $insertStmt = $pdo->prepare("
        INSERT INTO nodes (node_name, node_ip, node_key)
        VALUES (:node_name, :node_ip, :node_key)
    ");
    $insertStmt->bindParam(':node_name', $nodeName, PDO::PARAM_STR);
    $insertStmt->bindParam(':node_ip', $nodeIp, PDO::PARAM_STR);
    $insertStmt->bindParam(':node_key', $nodeKey, PDO::PARAM_STR);
    $insertStmt->execute();

    // 提交事务（关键优化：所有操作成功后提交）
    $pdo->commit();

    // 返回成功信息
    echo json_encode([
        'success' => true,
        'message' => '节点新增成功',
        'node_id' => $pdo->lastInsertId()  // 返回自增的节点ID
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    // 事务回滚（关键优化：任意操作失败时回滚所有变更）
    if (isset($pdo)) {
        $pdo->rollback();
    }
    // 数据库相关异常（如连接失败、SQL语法错误）
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '数据库操作失败：' . $e->getMessage()]);
} catch (Exception $e) {
    // 业务逻辑异常（如参数校验失败）
    http_response_code($e->getCode() ?: 400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>