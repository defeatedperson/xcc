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

// 安全增强：CSRF令牌校验（与update-api.php保持一致）
$receivedCsrfToken = $_POST['csrf_token'] ?? '';
if ($receivedCsrfToken !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
    exit;
}

// 设置PHP时区（参考update-api.php）
date_default_timezone_set('Asia/Shanghai');
header('Content-Type: application/json; charset=utf-8');

// 新增：获取前端传递的日志类型（默认conf-center）
$logType = $_POST['log_type'] ?? 'conf-center';
// 安全映射：限制仅允许两种日志类型，防止路径遍历
$logFileMap = [
    'conf-center' => 'conf-center.log',
    'delete' => 'delete_log.log'
];

// 校验日志类型有效性
if (!isset($logFileMap[$logType])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '无效的日志类型']);
    exit;
}

// 动态生成日志文件路径（使用映射后的文件名）
$logFilePath = $logFileMap[$logType];

// 读取日志文件内容
if (!file_exists($logFilePath)) {
    echo json_encode([
        'success' => false,
        'message' => '日志文件不存在',
        'log_path' => $logFilePath
    ]);
    exit;
}
// 获取请求类型（预览/下载）
$action = $_POST['action'] ?? 'preview';

// 处理下载请求
if ($action === 'download') {
    $maxDownloadSize = 5 * 1024 * 1024;  // 5MB限制
    // 新增：显式获取文件大小
    $fileSize = filesize($logFilePath);
    if ($fileSize > $maxDownloadSize) {
        echo json_encode([
            'success' => false,
            'message' => '日志文件超过5MB，无法下载'
        ]);
        exit;
    }

    $logContent = file_get_contents($logFilePath);
    if ($logContent === false) {
        echo json_encode([
            'success' => false,
            'message' => '无法读取日志文件'
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'log_content' => $logContent,
        'filename' => basename($logFilePath)  // 返回原始文件名
    ]);
    exit;
}

// 新增：检查文件大小是否超过1MB（1024*1024字节）
$fileSize = filesize($logFilePath);
if ($fileSize > 1024 * 1024) {
    echo json_encode([
        'success' => false,
        'message' => '日志过大，无法预览',  // 前端可直接显示此提示
        'log_path' => $logFilePath,
        'file_size' => $fileSize
    ]);
    exit;
}

// 读取日志内容（无需再限制长度，因已提前检查大小）
$logContent = file_get_contents($logFilePath);
if ($logContent === false) {
    echo json_encode([
        'success' => false,
        'message' => '无法读取日志文件',
        'log_path' => $logFilePath
    ]);
    exit;
}

// 返回成功信息
 echo json_encode([
    'success' => true,
    'message' => '日志读取成功',
    'log_path' => $logFilePath,
    'log_content' => $logContent
]);


?>