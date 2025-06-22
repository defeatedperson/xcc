<?php
// 域名同步状态JSON生成接口（读取并更新admin_updates表）
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
    // 步骤1：自动创建数据库目录（兼容Linux系统）
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0755, true); // 递归创建目录，权限0755（Linux标准）
    }

    // 步骤2：连接SQLite数据库（自动创建不存在的文件）
    $pdo = new PDO("sqlite:{$dbFile}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 步骤3：开启事务保证原子性（查询+更新必须同时成功）
    $pdo->beginTransaction();

    // 步骤4：前置检查：是否存在至少一个满足生成条件的域名（未同步+已生成配置）
    $checkStmt = $pdo->prepare("
        SELECT COUNT(*) AS count 
        FROM admin_updates 
        WHERE sync_status = 0 AND config_status = 1  -- 移除delete_status条件
    ");
    $checkStmt->execute();
    $checkResult = $checkStmt->fetch(PDO::FETCH_ASSOC);
    $hasGenerateCondition = $checkResult['count'] > 0;

    // 步骤5：若存在生成条件，查询所有已生成配置的域名（无论是否同步）
    $domains = [];
    if ($hasGenerateCondition) {
        $queryAllStmt = $pdo->prepare("
            SELECT domain, update_time 
            FROM admin_updates 
            WHERE config_status = 1  -- 仅筛选已生成配置的域名（移除delete_status条件）
        ");
        $queryAllStmt->execute();
        $domains = $queryAllStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 步骤6：生成JSON文件（仅当存在生成条件时）
    if ($hasGenerateCondition && !empty($domains)) {
        $configDir = __DIR__ . '/config';
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true); // 创建config目录
        }
        $jsonPath = $configDir . '/domains.json';
        file_put_contents(
            $jsonPath, 
            json_encode($domains, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    // 步骤7：仅更新满足生成条件的域名的同步状态（未同步+已生成配置）
    if ($hasGenerateCondition) {
        $updateStmt = $pdo->prepare("
            UPDATE admin_updates 
            SET sync_status = 1 
            WHERE sync_status = 0 AND config_status = 1  -- 移除delete_status条件
        ");
        $updateStmt->execute();
    }

    // 提交事务
    $pdo->commit();

    // 返回消息调整（替换"未删除"为"已生成配置文件"）
    $generatedCount = count($domains);
    $message = $hasGenerateCondition 
        ? "JSON生成成功，共处理{$generatedCount}个已生成配置文件的域名" 
        : "不存在需要同步的域名";
    $jsonPath = $hasGenerateCondition && $generatedCount > 0 ? '/config/domains.json' : null;

    // 返回成功响应
    echo json_encode([
        'success' => true,
        'message' => $message,
        'generated_count' => $generatedCount,
        'json_path' => $jsonPath
    ]);

} catch (Exception $e) {
    // 事务回滚（任意步骤失败则回滚所有操作）
    if (isset($pdo)) {
        $pdo->rollback();
    }
    // 异常处理（与admin-update.php保持一致）
    $statusCode = (int)$e->getCode() ?: 500;
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'debug' => $statusCode === 500 ? '数据库或文件操作失败' : ''
    ]);
}
exit;
?>