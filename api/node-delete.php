<?php
// 定义根目录常量（安全引用认证文件，与node-read.php一致）
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';

// 安全增强：强制仅允许POST请求（与node-read.php一致）
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '仅允许POST方法']);
    exit;
}

// 安全增强：CSRF令牌校验（与node-read.php一致）
$receivedCsrfToken = $_POST['csrf_token'] ?? '';
if ($receivedCsrfToken !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
    exit;
}

// 设置JSON响应头（与node-read.php一致）
header('Content-Type: application/json; charset=utf-8');

// 数据库配置（与node-read.php共用同一路径）
$dbDir = __DIR__ . '/db';
$dbFile = $dbDir . '/nodes.db';

try {
    // --------------------------
    // 步骤1：校验传入的节点ID
    // --------------------------
    if (!isset($_POST['id']) || !is_numeric($_POST['id']) || (int)$_POST['id'] <= 0) {
        throw new Exception('无效的节点ID（需为正整数）', 400);
    }
    $nodeId = (int)$_POST['id'];  // 强制转换为整数防注入

    // --------------------------
    // 步骤2：连接数据库（与node-read.php一致）
    // --------------------------
    $pdo = new PDO("sqlite:{$dbFile}");
// 修正常量引用，正确的错误模式异常常量为 PDO::ERRMODE_EXCEPTION
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // --------------------------
    // 步骤3：执行删除操作（使用预处理防SQL注入）
    // --------------------------
    $deleteStmt = $pdo->prepare("DELETE FROM nodes WHERE id = :id");
    $deleteStmt->bindValue(':id', $nodeId, PDO::PARAM_INT);
    $deleteStmt->execute();

    // 检查是否有行被删除（防止删除不存在的ID）
    if ($deleteStmt->rowCount() === 0) {
        throw new Exception('未找到要删除的节点（ID不存在）', 404);
    }

    // --------------------------
    // 步骤4：返回成功信息
    // --------------------------
    echo json_encode([
        'success' => true,
        'message' => '节点删除成功',
        'deleted_id' => $nodeId
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    // 数据库相关异常（如连接失败、SQL语法错误）
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '数据库操作失败：' . $e->getMessage()]);
} catch (Exception $e) {
    // 业务逻辑异常（如参数校验失败）
    http_response_code($e->getCode() ?: 400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>