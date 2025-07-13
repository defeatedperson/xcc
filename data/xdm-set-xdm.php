<?php
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';

header('Content-Type: application/json; charset=utf-8');

// 仅允许POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '仅允许POST方法']);
    exit;
}

// CSRF校验
$receivedCsrfToken = $_POST['csrf_token'] ?? '';
if ($receivedCsrfToken !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
    exit;
}

// 数据库文件路径
$dbFile = __DIR__ . '/db/xdm.db';
$action = $_POST['action'] ?? 'get';

// 确保db目录存在
$dbDir = __DIR__ . '/db';
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0755, true);
}

// 连接数据库
try {
    $db = new SQLite3($dbFile);
    $db->enableExceptions(true);
    
    // 创建节点状态表（如果不存在）
    $db->exec("CREATE TABLE IF NOT EXISTS node_status (
        id INTEGER PRIMARY KEY CHECK(id = 2),
        node_ids TEXT NOT NULL,
        update_time TEXT NOT NULL
    )");
    
    // 创建API密钥表（如果不存在）
    $db->exec("CREATE TABLE IF NOT EXISTS api_keys (
        id INTEGER PRIMARY KEY CHECK(id = 1),
        apikey TEXT NOT NULL,
        nodekey TEXT NOT NULL,
        service_url TEXT NOT NULL DEFAULT ''
    )");
    
    // 初始化故障节点记录（如果不存在）
    $result = $db->query("SELECT COUNT(*) FROM node_status WHERE id = 2");
    if ($result->fetchArray()[0] == 0) {
        // 插入故障节点记录（id=2）
        $db->exec("INSERT INTO node_status (id, node_ids, update_time) VALUES (2, '[]', '" . date('Y-m-d H:i:s') . "')");
    }
    
    // 初始化API密钥记录（如果不存在）
    $result = $db->query("SELECT COUNT(*) FROM api_keys");
    if ($result->fetchArray()[0] == 0) {
        $db->exec("INSERT INTO api_keys (id, apikey, nodekey, service_url) VALUES (1, '', '', '')");
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '数据库连接失败: ' . $e->getMessage()]);
    exit;
}

// 读取节点配置
if ($action === 'get') {
    try {
        // 获取故障节点IDs和最后更新时间
        $result = $db->query('SELECT node_ids, update_time FROM node_status WHERE id = 2');
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $faultNodes = json_decode($row['node_ids'], true);
        $updateTime = $row['update_time']; // 使用数据库中存储的时间，不更新
        
        // 构建状态数据（所有节点默认为正常，故障节点标记为false）
        $statusData = [];
        foreach ($faultNodes as $nodeId) {
            $statusData[$nodeId] = false;
        }
        
        // 构建返回数据结构
        $data = [
            'default' => [
                'status_data' => $statusData,
                'update_time' => $updateTime,
                'ip' => '' // 保持与原结构一致，但不存储实际IP
            ]
        ];
        
        echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '查询数据库失败: ' . $e->getMessage()]);
    }
    $db->close();
    exit;
}

// 读取API密钥配置
if ($action === 'get_apikey') {
    try {
        $result = $db->query('SELECT apikey, nodekey, service_url FROM api_keys WHERE id = 1');
        $row = $result->fetchArray(SQLITE3_ASSOC);
        
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'API密钥配置不存在']);
            $db->close();
            exit;
        }
        
        $data = [
            'apikey' => $row['apikey'],
            'nodekey' => $row['nodekey'],
            'service_url' => $row['service_url']
        ];
        
        echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '查询数据库失败: ' . $e->getMessage()]);
    }
    $db->close();
    exit;
}

// 更新API密钥配置
if ($action === 'set_apikey') {
    $apikey = $_POST['apikey'] ?? '';
    $nodekey = $_POST['nodekey'] ?? '';
    $service_url = $_POST['service_url'] ?? '';
    
    if (!$apikey || !$nodekey) {
        echo json_encode(['success' => false, 'message' => '缺少必要参数']);
        $db->close();
        exit;
    }
    
    // 验证密钥格式
    if (strlen($apikey) < 8 || strlen($nodekey) < 8) {
        echo json_encode(['success' => false, 'message' => '密钥长度必须大于等于8个字符']);
        $db->close();
        exit;
    }
    
    // 验证服务地址格式（如果提供）
    if ($service_url && !filter_var($service_url, FILTER_VALIDATE_URL)) {
        echo json_encode(['success' => false, 'message' => '服务地址格式无效']);
        $db->close();
        exit;
    }
    
    try {
        // 更新API密钥
        $stmt = $db->prepare('UPDATE api_keys SET apikey = ?, nodekey = ?, service_url = ? WHERE id = 1');
        $stmt->bindValue(1, $apikey, SQLITE3_TEXT);
        $stmt->bindValue(2, $nodekey, SQLITE3_TEXT);
        $stmt->bindValue(3, $service_url, SQLITE3_TEXT);
        $stmt->execute();
        
        echo json_encode(['success' => true, 'message' => 'API密钥配置保存成功']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '更新API密钥失败: ' . $e->getMessage()]);
    }
    
    $db->close();
    exit;
}

// 未知操作
echo json_encode(['success' => false, 'message' => '未知操作']);
$db->close();
exit;
?>
