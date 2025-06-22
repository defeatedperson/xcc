<?php
// 定义根目录常量（安全引用认证文件）
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';

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

// 删除 /update/new 文件夹中的所有 zip 文件
$updateNewDir = __DIR__ . '/update/new';
$zipFiles = glob($updateNewDir . '/*.zip');
$deleted = 0;
foreach ($zipFiles as $zipFile) {
    if (is_file($zipFile)) {
        unlink($zipFile);
        $deleted++;
    }
}

echo json_encode([
    'success' => true,
    'message' => $deleted > 0 ? 'zip文件已删除' : '未找到zip文件'
]);