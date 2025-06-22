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
// SSL证书删除模块（根据域名删除SQLite数据库记录）
header('Content-Type: application/json; charset=utf-8');

// 数据库配置（与ssl-write.php保持一致）
$dbDir = __DIR__ . '/db';
$dbFile = $dbDir . '/site_config.db';

// 校验请求参数
if (!isset($_POST['domain']) || empty($_POST['domain'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少必要参数：domain']);
    exit;
}
$domain = trim($_POST['domain']);

try {
    // 连接数据库
    $pdo = new PDO("sqlite:{$dbFile}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 开启事务（关键修改）
    $pdo->beginTransaction();

    // 执行删除操作（操作1）
    $stmt = $pdo->prepare("DELETE FROM ssl_certs WHERE domain = :domain");
    $stmt->bindParam(':domain', $domain, PDO::PARAM_STR);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        // 新增：更新site_config表的ssl_enabled为0（操作2）
        $updateStmt = $pdo->prepare("UPDATE site_config SET ssl_enabled = 0 WHERE domain = :domain");
        $updateStmt->bindParam(':domain', $domain, PDO::PARAM_STR);
        $updateStmt->execute();

        // 提交事务（关键修改）
        $pdo->commit();

        echo json_encode(['success' => true, 'message' => '证书删除成功']);
    } else {
        // 无记录删除，无需提交事务（自动回滚？不，需显式提交或回滚）
        // 由于未修改数据，可直接提交（或回滚，但SQLite中无操作的事务提交不影响）
        $pdo->commit();
        echo json_encode(['success' => false, 'message' => '未找到对应证书记录']);
    }

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