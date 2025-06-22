<?php
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '仅允许POST方法']);
    exit;
}

$receivedCsrfToken = $_POST['csrf_token'] ?? '';
if ($receivedCsrfToken !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
    exit;
}

$domain = trim($_POST['domain'] ?? '');
if (!$domain) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少需要清理的域名']);
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

$lockFile = __DIR__ . '/api/logs/clear-cache-worker.lock';
$fp = fopen($lockFile, 'c+');
if (!$fp) {
    echo json_encode(['success' => false, 'message' => '无法创建锁文件，任务终止']);
    exit;
}
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    fclose($fp);
    writeRejectLog('清理', '已有清理任务未完成，拒绝新请求');
    echo json_encode([
        'success' => false,
        'message' => '提交失败，存在未完成任务，请稍后重试。'
    ]);
    exit;
}
flock($fp, LOCK_UN);
fclose($fp);

// 启动后台进程（异步清理缓存）
$phpPath = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php';
$workerScript = __DIR__ . '/clear-cache-worker.php';
$cmd = (stripos(PHP_OS, 'WIN') === 0)
    ? "start /B \"\" \"{$phpPath}\" \"{$workerScript}\" \"{$domain}\""
    : "{$phpPath} \"{$workerScript}\" \"{$domain}\" > /dev/null 2>&1 &";
exec($cmd);

// 立即返回
echo json_encode([
    'success' => true,
    'message' => '正在处理，请稍后查看日志。'
], JSON_UNESCAPED_UNICODE);
?>