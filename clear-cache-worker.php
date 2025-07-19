<?php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

// 文件锁防止并发
$lockFile = __DIR__ . '/api/logs/clear-cache-worker.lock';
$fp = fopen($lockFile, 'c+');
if (!$fp) {
    exit("无法创建锁文件，任务终止\n");
}
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "已有同步任务正在执行，拒绝重复执行\n");
    exit;
}

date_default_timezone_set('Asia/Shanghai');
$logDir = __DIR__ . '/api/logs';
if (!is_dir($logDir)) mkdir($logDir, 0755, true);
$logFile = $logDir . '/clear-cache.log';
function writeLog($msg) {
    global $logFile;
    $ts = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$ts] $msg\n", FILE_APPEND);
}

$domain = $argv[1] ?? '';
if (!$domain) {
    writeLog("未指定需要清理的域名，任务终止");
    exit;
}

try {
    $dbDir = __DIR__ . '/api/db';
    $dbFile = $dbDir . '/nodes.db';
    if (!file_exists($dbFile)) throw new Exception("节点数据库文件不存在: {$dbFile}");
    $pdo = new PDO("sqlite:{$dbFile}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $nodes = $pdo->query("SELECT id, node_ip, node_key FROM nodes")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($nodes)) throw new Exception("未找到任何节点信息");

    writeLog("开始清理缓存，域名: {$domain}，节点数: " . count($nodes));
    foreach ($nodes as $node) {
        $url = "https://{$node['node_ip']}:8080/api/delete_cache";
        $postFields = [
            'node_id' => $node['id'],
            'node_key' => $node['node_key'],
            'domain' => $domain
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        // 关键：直接传数组，curl会自动用 multipart/form-data
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            writeLog("节点 {$node['node_ip']} 清理失败: {$error}");
        } else {
            writeLog("节点 {$node['node_ip']} 响应({$httpCode}): {$response}");
        }
    }
    writeLog("清理任务结束，域名: {$domain}");
} catch (Exception $e) {
    writeLog("清理缓存异常: " . $e->getMessage());
}
fclose($fp);
?>
