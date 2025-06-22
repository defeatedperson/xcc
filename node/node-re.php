<?php
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '仅允许POST方法']);
    exit;
}

$receivedCsrfToken = $_POST['csrf_token'] ?? '';
if ($receivedCsrfToken !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
    exit;
}

// 1. 检查 questions.json 是否存在
$srcJson = __DIR__ . '/set/questions.json';
if (!is_file($srcJson)) {
    echo json_encode(['success' => false, 'message' => '未找到 questions.json']);
    exit;
}

// 2. 校验 JSON 格式
$jsonContent = file_get_contents($srcJson);
$data = json_decode($jsonContent, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['success' => false, 'message' => 'questions.json 格式错误: ' . json_last_error_msg()]);
    exit;
}

// 3. 移动并替换到 common/ng/lua/questions.json
$targetDir = __DIR__ . '/common/ng/lua';
if (!is_dir($targetDir)) {
    if (!mkdir($targetDir, 0755, true)) {
        echo json_encode(['success' => false, 'message' => '无法创建目标目录']);
        exit;
    }
}
$targetJson = $targetDir . '/questions.json';
if (!@copy($srcJson, $targetJson)) {
    echo json_encode(['success' => false, 'message' => '移动 questions.json 失败']);
    exit;
}

// 4. 打包 common 文件夹为 xcc.zip 到 finish 目录
$commonDir = __DIR__ . '/common';
$finishDir = __DIR__ . '/finish';
$zipFile = $finishDir . '/xcc.zip';

if (!is_dir($finishDir)) {
    if (!mkdir($finishDir, 0755, true)) {
        echo json_encode(['success' => false, 'message' => '无法创建 finish 目录']);
        exit;
    }
}

// 删除旧的 zip 文件
if (is_file($zipFile)) {
    @unlink($zipFile);
}

$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE) !== true) {
    echo json_encode(['success' => false, 'message' => '无法创建 zip 文件']);
    exit;
}

// 递归添加 common 目录下所有文件
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($commonDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($files as $file) {
    $filePath = $file->getRealPath();
    $relativePath = substr($filePath, strlen($commonDir) + 1);
    if ($file->isDir()) {
        $zip->addEmptyDir($relativePath);
    } else {
        $zip->addFile($filePath, $relativePath);
    }
}
$zip->close();

echo json_encode(['success' => true, 'message' => '打包完成', 'zip' => 'finish/xcc.zip']);
exit;