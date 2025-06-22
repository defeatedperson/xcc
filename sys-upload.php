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

// 限制最大上传50M
$maxSize = 50 * 1024 * 1024; // 50MB
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '未检测到上传文件或上传失败']);
    exit;
}
if ($_FILES['file']['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '文件大小不能超过50MB']);
    exit;
}

// 检查文件类型，只允许zip
$fileInfo = pathinfo($_FILES['file']['name']);
if (strtolower($fileInfo['extension']) !== 'zip') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '只允许上传zip文件']);
    exit;
}

// 检查/update/new文件夹，不存在则自动创建
$updateNewDir = __DIR__ . '/update/new';
if (!is_dir($updateNewDir)) {
    if (!mkdir($updateNewDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '/update/new 文件夹创建失败']);
        exit;
    }
}

// 删除/update/new文件夹下所有zip文件
$zipFiles = glob($updateNewDir . '/*.zip');
foreach ($zipFiles as $zipFile) {
    if (is_file($zipFile)) {
        unlink($zipFile);
    }
}

// 移动上传文件到/update/new目录，文件名用原名
$targetFile = $updateNewDir . '/' . $_FILES['file']['name'];
if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetFile)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '文件保存失败']);
    exit;
}

echo json_encode(['success' => true, 'message' => '文件上传成功', 'filename' => $_FILES['file']['name']]);