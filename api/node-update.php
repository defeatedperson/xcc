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

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

$dbDir = __DIR__ . '/db';
$dbFile = $dbDir . '/nodes.db';

// 自动创建数据库目录（新增：参考node-add.php）
if (!is_dir($dbDir)) {
    if (!mkdir($dbDir, 0755, true)) {  // 递归创建目录，权限0755
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => "数据库目录 {$dbDir} 创建失败"]);
        exit;
    }
}

try {
    // 校验参数（优化：清理输入后校验）
    if (!isset($_POST['node_id'], $_POST['node_name'], $_POST['node_ip'], $_POST['node_key'])) {
        throw new Exception('缺少必要参数：node_id、node_name、node_ip、node_key', 400);
    }
    $nodeName = trim($_POST['node_name']);  // 清理前后空格
    if (!preg_match('/^[a-zA-Z]{1,5}$/', $nodeName)) {  // 使用清理后的值校验
        throw new Exception('节点名称格式错误（要求1-5位纯字母）', 400);
    }

    $nodeKey = trim($_POST['node_key']);  // 新增：清理密钥输入
    // 新增：节点密钥长度校验（16-32位）
    if (strlen($nodeKey) < 16 || strlen($nodeKey) > 32) {
        throw new Exception('节点密钥长度需为16-32位', 400);
    }

    $pdo = new PDO("sqlite:{$dbFile}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // 注册REGEXP函数（优化：与node-add.php一致，严格首尾匹配）
    $pdo->sqliteCreateFunction('REGEXP', function($pattern, $string) {
        return preg_match("/^{$pattern}$/i", $string);  // 严格匹配首尾
    }, 2);  // 明确参数数量为2
    $pdo->beginTransaction();

    // 执行更新（使用清理后的值）
    $stmt = $pdo->prepare("
        UPDATE nodes 
        SET node_name = :name, node_ip = :ip, node_key = :key, update_time = datetime('now', 'localtime')
        WHERE id = :id
    ");
    $stmt->execute([
        ':id' => (int)$_POST['node_id'],
        ':name' => $nodeName,  // 使用清理后的值
        ':ip' => trim($_POST['node_ip']),
        ':key' => $nodeKey  // 使用清理后并校验的密钥值
    ]);

    if ($stmt->rowCount() === 0) {
        throw new Exception('未找到要更新的节点（ID不存在）', 404);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => '节点更新成功'], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    $pdo->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '数据库操作失败：' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>