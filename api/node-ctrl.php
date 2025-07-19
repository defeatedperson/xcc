<?php
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';

header('Content-Type: application/json; charset=utf-8');

// 统一的响应函数
function sendResponse($success, $message, $data = null, $httpCode = 200) {
    http_response_code($httpCode);
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response);
    exit;
}

// curl选项配置函数
function setCurlOptions($ch, $postData) {
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData); // 修正：直接传数组
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
}

// 发送请求到节点
function sendRequestToNode($url, $postData) {
    $ch = curl_init($url);
    setCurlOptions($ch, $postData);
    
    $response = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        sendResponse(false, '请求节点失败: ' . $err, null, 500);
    }

    if ($httpCode !== 200) {
        sendResponse(false, '节点返回错误状态码', [
            'http_code' => $httpCode,
            'response' => $response
        ], $httpCode);
    }

    return $response;
}

// 验证请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, '仅允许POST方法', null, 405);
}

// CSRF校验
$receivedCsrfToken = $_POST['csrf_token'] ?? '';
if ($receivedCsrfToken !== $_SESSION['csrf_token']) {
    sendResponse(false, 'CSRF验证失败', null, 403);
}

// 获取并验证参数
$nodeId = $_POST['node_id'] ?? '';
if (!$nodeId || !is_numeric($nodeId) || (int)$nodeId <= 0) {
    sendResponse(false, '无效的节点ID（需为正整数）', null, 400);
}
$nodeId = (int)$nodeId;  // 强制转换为整数
$command = $_POST['command'] ?? '';
$action = $_POST['action'] ?? '';
$logType = $_POST['log_type'] ?? '';

if (!$nodeId) {
    sendResponse(false, '缺少节点ID');
}

try {
    // 数据库配置和连接
    $dbDir = __DIR__ . '/db';
    $dbFile = $dbDir . '/nodes.db';
    $pdo = new PDO("sqlite:{$dbFile}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 查询节点信息
    $stmt = $pdo->prepare("SELECT node_key, node_ip FROM nodes WHERE id = :id LIMIT 1");
    $stmt->bindValue(':id', $nodeId, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row) {
        sendResponse(false, '节点不存在', null, 404);
    }
    
    $nodeKey = $row['node_key'];
    $nodeIp = $row['node_ip'];

    // 处理日志查询请求
    if ($action === 'log_query') {
        $validLogTypes = ['go', 'error'];
        if (!in_array($logType, $validLogTypes)) {
            sendResponse(false, '无效的日志类型', ['valid_types' => $validLogTypes], 400);
        }

        $url = "https://{$nodeIp}:8080/api/log_query?log_type={$logType}";
        $postData = [
            'node_id' => $nodeId,
            'node_key' => $nodeKey,
        ];

        $response = sendRequestToNode($url, $postData);
        echo $response;  // 直接转发节点返回的日志内容
        exit;
    }

    // 处理Nginx控制请求
    if ($command) {
        $validCommands = ['reload', 'start', 'stop'];
        if (!in_array($command, $validCommands)) {
            sendResponse(false, '无效的Nginx操作指令', ['valid_commands' => $validCommands], 400);
        }
        $url = "https://{$nodeIp}:8080/nginx-control";
        $postData = [
            'node_id' => $nodeId,
            'node_key' => $nodeKey,
            'command' => $command,
        ];

        $response = sendRequestToNode($url, $postData);
        echo $response;  // 直接转发节点的响应
        exit;
    }

    // 没有有效的操作参数
    sendResponse(false, '缺少操作指令', null, 400);

} catch (PDOException $e) {
    sendResponse(false, '数据库操作失败：' . $e->getMessage(), null, 500);
} catch (Exception $e) {
    sendResponse(false, $e->getMessage(), null, 500);
}
?>
