<?php
// 定义根目录常量（安全引用认证文件）
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';

// 设置响应类型为JSON
header('Content-Type: application/json');

// 仅允许POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '仅允许POST方法']);
    exit;
}

// CSRF令牌校验
$receivedCsrfToken = $_POST['csrf_token'] ?? '';
if ($receivedCsrfToken !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
    exit;
}

// 读取version.json文件
$versionFile = __DIR__ . '/update/version.json';

if (!file_exists($versionFile)) {
    echo json_encode(['success' => false, 'message' => '版本文件不存在']);
    exit;
}

try {
    // 读取文件内容
    $versionData = file_get_contents($versionFile);
    
    // 解析JSON
    $versionInfo = json_decode($versionData, true);
    
    if ($versionInfo === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('版本文件格式错误: ' . json_last_error_msg());
    }
    
    // 返回版本信息
    echo json_encode([
        'success' => true,
        'message' => '获取版本信息成功',
        'data' => $versionInfo
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '读取版本信息失败: ' . $e->getMessage()
    ]);
}

exit;