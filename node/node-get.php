<?php
// 1. 获取token参数
$token = $_GET['token'] ?? '';
if (!$token) {
    http_response_code(400);
    echo '缺少token参数';
    exit;
}

// 2. 读取get.json文件
$getJsonPath = __DIR__ . '/get.json';
if (!is_file($getJsonPath)) {
    http_response_code(403);
    echo '未找到授权文件';
    exit;
}
$getData = json_decode(file_get_contents($getJsonPath), true);
$tokenHash = $getData['token'] ?? '';
if (!$tokenHash) {
    http_response_code(403);
    echo '授权文件无效';
    exit;
}

// 3. 验证token
if (!password_verify($token, $tokenHash)) {
    http_response_code(403);
    echo 'token无效';
    exit;
}

// 4. 检查zip文件是否存在
$zipPath = __DIR__ . '/finish/xcc.zip';
if (!is_file($zipPath)) {
    http_response_code(404);
    echo '文件不存在';
    exit;
}

// 5. 返回zip文件（以下载方式）
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="xcc.zip"');
header('Content-Length: ' . filesize($zipPath));
readfile($zipPath);
exit;