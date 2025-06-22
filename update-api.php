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

header('Content-Type: application/json; charset=utf-8');
//拒绝执行的日志函数
$rejectLogFile = __DIR__ . '/api/logs/reject.log';
function writeRejectLog($type, $reason) {
    $rejectLogFile = __DIR__ . '/api/logs/reject.log';
    $ts = date('Y-m-d H:i:s');
    $msg = "[$ts] [$type] $reason\n";
    file_put_contents($rejectLogFile, $msg, FILE_APPEND);
}

// 检查锁文件，防止重复提交
$lockFile = __DIR__ . '/api/logs/update-worker.lock';
$fp = fopen($lockFile, 'c+');
if (!$fp) {
    echo json_encode(['success' => false, 'message' => '无法创建锁文件，任务终止']);
    exit;
}
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    fclose($fp);
    writeRejectLog('同步', '已有同步任务未完成，拒绝新请求');
    echo json_encode([
        'success' => false,
        'message' => '提交失败，存在未完成任务，请稍后至节点页面点击 “同步”。'
    ]);
    exit;
}
flock($fp, LOCK_UN); // 立即释放锁，仅用于检测
fclose($fp);

// 启动后台进程（Windows下用start /B，Linux下用nohup）
$phpPath = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php';
$workerScript = __DIR__ . '/update-worker.php';

// exec方式后台运行（Windows下用start /B，Linux下用nohup）
if (stripos(PHP_OS, 'WIN') === 0) {
    // Windows
    $cmd = "start /B \"\" \"{$phpPath}\" \"{$workerScript}\"";
    exec($cmd);
} else {
    // Linux/Unix
    $cmd = "{$phpPath} \"{$workerScript}\" > /dev/null 2>&1 &";
    exec($cmd);
}

// 立即返回前端
echo json_encode([
    'success' => true,
    'message' => '正在处理，请稍后查看日志。'
], JSON_UNESCAPED_UNICODE);
?>