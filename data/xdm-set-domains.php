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
$dbFile = __DIR__ . '/db/domains.db';
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
    
    // 创建表（如果不存在）
    $db->exec("CREATE TABLE IF NOT EXISTS domains (
        id INTEGER PRIMARY KEY CHECK(id >= 1 AND id <= 20),
        host TEXT UNIQUE NOT NULL,
        main_type TEXT NOT NULL,
        main_value TEXT NOT NULL,
        backup_type TEXT NOT NULL,
        backup_value TEXT NOT NULL,
        line TEXT NOT NULL,
        jk_type TEXT NOT NULL,
        status INTEGER NOT NULL
    )");
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '数据库连接失败: ' . $e->getMessage()]);
    exit;
}

// 读取域名配置
if ($action === 'get') {
    try {
        $result = $db->query('SELECT * FROM domains ORDER BY id');
        $domains = [];
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            // 将status从整数转换为布尔值
            $row['status'] = (bool)$row['status'];
            $domains[$row['id']] = [
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
        
        echo json_encode(['success' => true, 'data' => $domains], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '查询数据库失败: ' . $e->getMessage()]);
    }
    $db->close();
    exit;
}

// 添加或更新单个域名配置
if ($action === 'update') {
    $id = $_POST['id'] ?? '';
    if (!$id || !is_numeric($id) || $id < 1 || $id > 20) {
        echo json_encode(['success' => false, 'message' => 'ID必须是1~20之间的数字']);
        $db->close();
        exit;
    }
    
    $host = $_POST['host'] ?? '';
    $main_type = $_POST['main_type'] ?? '';
    $main_value = $_POST['main_value'] ?? '';
    $backup_type = $_POST['backup_type'] ?? '';
    $backup_value = $_POST['backup_value'] ?? '';
    $line = $_POST['line'] ?? '';
    $jk_type = $_POST['jk_type'] ?? '';
    
    if (!$host || !$main_type || !$main_value || !$backup_type || !$backup_value || !$line || !$jk_type) {
        echo json_encode(['success' => false, 'message' => '缺少必要参数']);
        $db->close();
        exit;
    }

    // 验证host值格式
    if (!preg_match('/^[a-zA-Z0-9.]{1,5}$/', $host)) {
        echo json_encode(['success' => false, 'message' => 'host值只能包含字母、数字和小数点，且长度不能超过5位']);
        $db->close();
        exit;
    }
    
    // 验证记录类型
    $validTypes = ['A', 'AAAA', 'CNAME'];
    if (!in_array($main_type, $validTypes) || !in_array($backup_type, $validTypes)) {
        echo json_encode(['success' => false, 'message' => '域名记录类型不合法']);
        $db->close();
        exit;
    }
    
    // 验证监控类型
    $validJkTypes = ['ping', 'monitor'];
    if (!in_array($jk_type, $validJkTypes)) {
        echo json_encode(['success' => false, 'message' => '监控类型不合法']);
        $db->close();
        exit;
    }
    
    try {
        // 检查host是否重复（排除当前ID）
        $stmt = $db->prepare('SELECT COUNT(*) FROM domains WHERE host = ? AND id != ?');
        $stmt->bindValue(1, $host, SQLITE3_TEXT);
        $stmt->bindValue(2, $id, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $count = $result->fetchArray()[0];
        
        if ($count > 0) {
            echo json_encode(['success' => false, 'message' => "域名host值已存在: {$host}"]);
            $db->close();
            exit;
        }
        
        // 检查ID是否存在
        $stmt = $db->prepare('SELECT COUNT(*) FROM domains WHERE id = ?');
        $stmt->bindValue(1, $id, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $exists = $result->fetchArray()[0] > 0;
        
        if ($exists) {
            // 更新记录
            $stmt = $db->prepare('UPDATE domains SET host = ?, main_type = ?, main_value = ?, backup_type = ?, backup_value = ?, line = ?, jk_type = ?, status = 1 WHERE id = ?');
            $stmt->bindValue(1, $host, SQLITE3_TEXT);
            $stmt->bindValue(2, $main_type, SQLITE3_TEXT);
            $stmt->bindValue(3, $main_value, SQLITE3_TEXT);
            $stmt->bindValue(4, $backup_type, SQLITE3_TEXT);
            $stmt->bindValue(5, $backup_value, SQLITE3_TEXT);
            $stmt->bindValue(6, $line, SQLITE3_TEXT);
            $stmt->bindValue(7, $jk_type, SQLITE3_TEXT);
            $stmt->bindValue(8, $id, SQLITE3_INTEGER);
            $stmt->execute();
            
            echo json_encode(['success' => true, 'message' => '域名配置更新成功']);
        } else {
            // 插入新记录
            $stmt = $db->prepare('INSERT INTO domains (id, host, main_type, main_value, backup_type, backup_value, line, jk_type, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)');
            $stmt->bindValue(1, $id, SQLITE3_INTEGER);
            $stmt->bindValue(2, $host, SQLITE3_TEXT);
            $stmt->bindValue(3, $main_type, SQLITE3_TEXT);
            $stmt->bindValue(4, $main_value, SQLITE3_TEXT);
            $stmt->bindValue(5, $backup_type, SQLITE3_TEXT);
            $stmt->bindValue(6, $backup_value, SQLITE3_TEXT);
            $stmt->bindValue(7, $line, SQLITE3_TEXT);
            $stmt->bindValue(8, $jk_type, SQLITE3_TEXT);
            $stmt->execute();
            
            echo json_encode(['success' => true, 'message' => '域名配置添加成功']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '操作失败: ' . $e->getMessage()]);
    }
    
    $db->close();
    exit;
}

// 删除域名配置
if ($action === 'delete') {
    $id = $_POST['id'] ?? '';
    if (!$id || !is_numeric($id) || $id < 1 || $id > 20) {
        echo json_encode(['success' => false, 'message' => 'ID必须是1~20之间的数字']);
        $db->close();
        exit;
    }
    
    try {
        // 检查ID是否存在
        $stmt = $db->prepare('SELECT COUNT(*) FROM domains WHERE id = ?');
        $stmt->bindValue(1, $id, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $exists = $result->fetchArray()[0] > 0;
        
        if (!$exists) {
            echo json_encode(['success' => false, 'message' => '指定ID的域名配置不存在']);
            $db->close();
            exit;
        }
        
        // 删除记录
        $stmt = $db->prepare('DELETE FROM domains WHERE id = ?');
        $stmt->bindValue(1, $id, SQLITE3_INTEGER);
        $stmt->execute();
        
        echo json_encode(['success' => true, 'message' => '域名配置删除成功']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '删除失败: ' . $e->getMessage()]);
    }
    
    $db->close();
    exit;
}

// 未知操作
echo json_encode(['success' => false, 'message' => '未知操作']);
$db->close();
exit;
?>