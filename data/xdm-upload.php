<?php
// 设置响应头为JSON格式
header('Content-Type: application/json; charset=utf-8');

// 定义常量
define('DB_DIR', __DIR__ . '/db');
define('XDM_DB_FILE', DB_DIR . '/xdm.db');

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
    echo json_encode($response);
    exit;
}

// 验证请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_response(405, '仅支持POST请求');
}

// 验证必要参数
if (!isset($_POST['master_key']) || !isset($_POST['action'])) {
    error_response(400, '缺少必要参数');
}

// 确保数据库目录存在
if (!is_dir(DB_DIR)) {
    mkdir(DB_DIR, 0755, true);
}

// 连接数据库
try {
    $db = new SQLite3(XDM_DB_FILE);
    $db->enableExceptions(true);
} catch (Exception $e) {
    error_response(500, '数据库连接失败: ' . $e->getMessage());
}

// 验证主密钥
try {
    $stmt = $db->prepare('SELECT apikey FROM api_keys WHERE id = 1');
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$row || $_POST['master_key'] !== $row['apikey']) {
        error_response(401, '无效的主密钥');
    }
} catch (Exception $e) {
    error_response(500, '验证主密钥失败: ' . $e->getMessage());
}

// 处理不同的操作
$action = $_POST['action'];

switch ($action) {
    case 'update_status':
        // 验证状态数据
        if (!isset($_POST['status_data'])) {
            error_response(400, '缺少状态数据');
        }
        
        // 解析状态数据
        $status_data = json_decode($_POST['status_data'], true);
        if ($status_data === null) {
            error_response(400, '状态数据格式错误 - 无效的JSON');
        }
        
        // 在文件顶部添加时区设置
        date_default_timezone_set('Asia/Shanghai'); // 设置为中国时区
        
        // 获取当前时间作为更新时间
        $update_time = date('Y-m-d H:i:s');
        
        try {
            // 开始事务
            $db->exec('BEGIN TRANSACTION');
            
            // 直接将上传的节点ID作为故障节点
            $fault_node_ids = array_keys($status_data);
            
            // 更新故障节点列表
            $stmt = $db->prepare('UPDATE node_status SET node_ids = ?, update_time = ? WHERE id = 2');
            $stmt->bindValue(1, json_encode($fault_node_ids), SQLITE3_TEXT);
            $stmt->bindValue(2, $update_time, SQLITE3_TEXT);
            $stmt->execute();
            
            // 提交事务
            $db->exec('COMMIT');
            
            // 返回成功响应
            success_response();
        } catch (Exception $e) {
            // 回滚事务
            $db->exec('ROLLBACK');
            error_response(500, '更新节点状态失败: ' . $e->getMessage());
        }
        break;
        
    default:
        error_response(400, '不支持的操作类型');
}

// 关闭数据库连接
$db->close();
?>