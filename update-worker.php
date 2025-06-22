<?php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

// 文件锁防止并发
$lockFile = __DIR__ . '/api/logs/update-worker.lock';
$fp = fopen($lockFile, 'c+');
if (!$fp) {
    exit("无法创建锁文件，任务终止\n");
}
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "已有清理任务正在执行，拒绝重复执行\n");
    exit;
}

// 设置PHP时区
date_default_timezone_set('Asia/Shanghai');

// 日志目录配置
$logDir = __DIR__ . '/api/logs';

// 确保日志目录存在
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// 日志文件路径
$logFile = $logDir . '/update.log';

// 写入日志函数
function writeLog($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$message}\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

try {
    // 数据库配置
    $dbDir = __DIR__ . '/api/db';
    $dbFile = $dbDir . '/nodes.db';
    
    if (!file_exists($dbFile)) {
        throw new Exception("节点数据库文件不存在: {$dbFile}");
    }
    
    $pdo = new PDO("sqlite:{$dbFile}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->query("SELECT id, node_ip, node_key FROM nodes");
    $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($nodes)) {
        throw new Exception("未找到任何节点信息");
    }
    
    writeLog("成功获取节点信息，共 " . count($nodes) . " 个节点");
    
    $domainsFile = __DIR__ . '/data/config/domains.json'; 
    if (!file_exists($domainsFile)) {
        throw new Exception("domains.json文件不存在: {$domainsFile}");
    }

    $domainsJson = file_get_contents($domainsFile);
    if ($domainsJson === false) {
        throw new Exception("无法读取domains.json文件");
    }

    $domains = json_decode($domainsJson, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("domains.json格式错误: " . json_last_error_msg());
    }

    $domainsWrapper = ['domains' => $domains];
    $domainsJson = json_encode($domainsWrapper, JSON_UNESCAPED_UNICODE);

    writeLog("成功读取domains.json，包含 " . count($domains) . " 个域名");
    
    $results = [
        'success' => true,
        'message' => '开始向边缘节点发送更新请求',
        'nodes_total' => count($nodes),
        'nodes_success' => 0,
        'nodes_failed' => 0,
        'details' => []
    ];
    
    foreach ($nodes as $node) {
        $nodeId = $node['id'];
        $nodeIp = $node['node_ip'];
        $nodeKey = $node['node_key'];
        
        $url = "https://{$nodeIp}:8080/master-command";
        $postFields = [
            'node_id' => $nodeId,
            'node_key' => $nodeKey,
            'domains_json' => $domainsJson
        ];
        
        writeLog("开始请求节点 ID:{$nodeId}, IP:{$nodeIp}");
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        $nodeResult = [
            'node_id' => $nodeId,
            'node_ip' => $nodeIp,
            'http_code' => $httpCode,
            'success' => false,
            'message' => ''
        ];
        
        if ($response === false) {
            $nodeResult['message'] = "请求失败: {$error}";
            writeLog("节点 ID:{$nodeId}, IP:{$nodeIp} 请求失败: {$error}");
            $results['nodes_failed']++;
        } else {
            $responseData = json_decode($response, true);
            if ($httpCode == 200 && isset($responseData['status']) && $responseData['status'] == 'success') {
                $nodeResult['success'] = true;
                $nodeResult['message'] = $responseData['message'] ?? '请求成功';
                $nodeResult['result'] = $responseData['result'] ?? [];
                writeLog("节点 ID:{$nodeId}, IP:{$nodeIp} 请求成功: {$response}");

                // 新增：同步成功后调用nginx-control接口平滑重启
                $nginxUrl = "https://{$nodeIp}:8080/nginx-control";
                $nginxPost = [
                    'node_id' => $nodeId,
                    'node_key' => $nodeKey,
                    'command' => 'reload'
                ];
                $ch2 = curl_init();
                curl_setopt($ch2, CURLOPT_URL, $nginxUrl);
                curl_setopt($ch2, CURLOPT_POST, true);
                curl_setopt($ch2, CURLOPT_POSTFIELDS, $nginxPost);
                curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch2, CURLOPT_TIMEOUT, 20);
                $nginxResp = curl_exec($ch2);
                $nginxHttpCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                $nginxError = curl_error($ch2);
                curl_close($ch2);

                if ($nginxResp === false) {
                    writeLog("节点 ID:{$nodeId}, IP:{$nodeIp} nginx reload 失败: {$nginxError}");
                    $nodeResult['nginx_reload'] = [
                        'success' => false,
                        'message' => $nginxError
                    ];
                } else {
                    writeLog("节点 ID:{$nodeId}, IP:{$nodeIp} nginx reload 返回: HTTP {$nginxHttpCode}, {$nginxResp}");
                    $nodeResult['nginx_reload'] = [
                        'success' => $nginxHttpCode == 200,
                        'http_code' => $nginxHttpCode,
                        'response' => $nginxResp
                    ];
                }

                $results['nodes_success']++;
            } else {
                $nodeResult['message'] = "请求返回错误: HTTP {$httpCode}, " . ($responseData['message'] ?? $response);
                writeLog("节点 ID:{$nodeId}, IP:{$nodeIp} 请求返回错误: HTTP {$httpCode}, {$response}");
                $results['nodes_failed']++;
            }
        }
        
        $results['details'][] = $nodeResult;
    }

    writeLog("所有节点请求完成，成功: {$results['nodes_success']}, 失败: {$results['nodes_failed']}");

} catch (PDOException $e) {
    $errorMessage = "数据库操作失败: " . $e->getMessage();
    writeLog("错误: {$errorMessage}");
} catch (Exception $e) {
    $errorMessage = $e->getMessage();
    writeLog("错误: {$errorMessage}");
}finally {
    fclose($fp);
}
?>