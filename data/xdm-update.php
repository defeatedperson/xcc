<?php
// 设置响应头为JSON格式
header('Content-Type: application/json; charset=utf-8');

// 定义常量
define('DOMAINS_DB_FILE', __DIR__ . '/db/domains.db');
define('SSL_DOMAINS_DB_FILE', __DIR__ . '/db/ssl_domains.db');
define('XDM_DB_FILE', __DIR__ . '/db/xdm.db');

// 错误响应函数
function error_response($code, $message) {
    echo json_encode([
        'code' => $code,
        'message' => $message
    ]);
    exit;
}

// 成功响应函数
function success_response($data = null) {
    $response = ['code' => 0, 'message' => 'success'];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// 验证请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_response(405, '仅支持POST请求');
}

// 验证必要参数
if (!isset($_POST['master_key'])) {
    error_response(400, '缺少必要参数');
}

// 确保db目录存在
$dbDir = __DIR__ . '/db';
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0755, true);
}

// 从xdm.db获取API密钥
try {
    if (!file_exists(XDM_DB_FILE)) {
        error_response(500, 'XDM数据库文件不存在');
    }
    
    $db = new SQLite3(XDM_DB_FILE);
    $db->enableExceptions(true);
    
    $result = $db->query('SELECT apikey FROM api_keys WHERE id = 1');
    $row = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$row) {
        $db->close();
        error_response(500, 'API密钥配置不存在');
    }
    
    $apikey = $row['apikey'];
    $db->close();
} catch (Exception $e) {
    error_response(500, '获取API密钥失败: ' . $e->getMessage());
}

// 验证主密钥
if ($_POST['master_key'] !== $apikey) {
    error_response(401, '无效的主密钥');
}

// 准备返回数据
$response_data = [
    'dns' => ['domains' => []],
    'ssl' => ['domains' => []]
];

// 查询domains数据库
if (file_exists(DOMAINS_DB_FILE)) {
    try {
        $db = new SQLite3(DOMAINS_DB_FILE);
        $db->enableExceptions(true);
        
        $result = $db->query('SELECT * FROM domains ORDER BY id');
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            // 将status从整数转换为布尔值
            $row['status'] = (bool)$row['status'];
            $response_data['dns']['domains'][$row['id']] = [
                'host' => $row['host'],
                'main_type' => $row['main_type'],
                'main_value' => $row['main_value'],
                'backup_type' => $row['backup_type'],
                'backup_value' => $row['backup_value'],
                'line' => $row['line'],
                'jk_type' => $row['jk_type'],
                'status' => $row['status']
            ];
        }
        
        $db->close();
    } catch (Exception $e) {
        // 如果查询失败，不中断程序，继续查询SSL域名
        error_log('查询domains数据库失败: ' . $e->getMessage());
    }
}

// 查询ssl_domains数据库
if (file_exists(SSL_DOMAINS_DB_FILE)) {
    try {
        $db = new SQLite3(SSL_DOMAINS_DB_FILE);
        $db->enableExceptions(true);
        
        $result = $db->query('SELECT * FROM ssl_domains ORDER BY id');
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $response_data['ssl']['domains'][$row['id']] = $row['domain'];
        }
        
        $db->close();
    } catch (Exception $e) {
        // 如果查询失败，记录错误但不中断程序
        error_log('查询ssl_domains数据库失败: ' . $e->getMessage());
    }
}

// 返回数据
echo json_encode($response_data, JSON_UNESCAPED_UNICODE);
?>