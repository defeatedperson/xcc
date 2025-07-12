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
$dbFile = __DIR__ . '/db/ssl_domains.db';
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
    $db->exec("CREATE TABLE IF NOT EXISTS ssl_domains (
        id INTEGER PRIMARY KEY CHECK(id >= 1 AND id <= 20),
        domain TEXT UNIQUE NOT NULL
    )");
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '数据库连接失败: ' . $e->getMessage()]);
    exit;
}

// 读取SSL域名配置
if ($action === 'get') {
    try {
        $result = $db->query('SELECT * FROM ssl_domains ORDER BY id');
        $domains = [];
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $domains[$row['id']] = $row['domain'];
        }
        
        echo json_encode(['success' => true, 'data' => $domains], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '查询数据库失败: ' . $e->getMessage()]);
    }
    $db->close();
    exit;
}

// 添加或更新单个SSL域名配置
if ($action === 'update') {
    $id = $_POST['id'] ?? '';
    if (!$id || !is_numeric($id) || $id < 1 || $id > 20) {
        echo json_encode(['success' => false, 'message' => 'ID必须是1~20之间的数字']);
        $db->close();
        exit;
    }
    
    $domain = $_POST['domain'] ?? '';
    
    if (!$domain) {
        echo json_encode(['success' => false, 'message' => '缺少必要参数']);
        $db->close();
        exit;
    }
    
    // 验证域名格式（简单验证）
    if (!filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
        echo json_encode(['success' => false, 'message' => '域名格式不合法']);
        $db->close();
        exit;
    }
    
    try {
        // 检查domain是否重复（排除当前ID）
        $stmt = $db->prepare('SELECT COUNT(*) FROM ssl_domains WHERE domain = ? AND id != ?');
        $stmt->bindValue(1, $domain, SQLITE3_TEXT);
        $stmt->bindValue(2, $id, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $count = $result->fetchArray()[0];
        
        if ($count > 0) {
            echo json_encode(['success' => false, 'message' => "域名已存在: {$domain}"]);
            $db->close();
            exit;
        }
        
        // 检查ID是否存在
        $stmt = $db->prepare('SELECT COUNT(*) FROM ssl_domains WHERE id = ?');
        $stmt->bindValue(1, $id, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $exists = $result->fetchArray()[0] > 0;
        
        if ($exists) {
            // 更新记录
            $stmt = $db->prepare('UPDATE ssl_domains SET domain = ? WHERE id = ?');
            $stmt->bindValue(1, $domain, SQLITE3_TEXT);
            $stmt->bindValue(2, $id, SQLITE3_INTEGER);
            $stmt->execute();
            
            echo json_encode(['success' => true, 'message' => 'SSL域名配置更新成功']);
        } else {
            // 插入新记录
            $stmt = $db->prepare('INSERT INTO ssl_domains (id, domain) VALUES (?, ?)');
            $stmt->bindValue(1, $id, SQLITE3_INTEGER);
            $stmt->bindValue(2, $domain, SQLITE3_TEXT);
            $stmt->execute();
            
            echo json_encode(['success' => true, 'message' => 'SSL域名配置添加成功']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '操作失败: ' . $e->getMessage()]);
    }
    
    $db->close();
    exit;
}

// 删除SSL域名配置
if ($action === 'delete') {
    $id = $_POST['id'] ?? '';
    if (!$id || !is_numeric($id) || $id < 1 || $id > 20) {
        echo json_encode(['success' => false, 'message' => 'ID必须是1~20之间的数字']);
        $db->close();
        exit;
    }
    
    try {
        // 检查ID是否存在
        $stmt = $db->prepare('SELECT COUNT(*) FROM ssl_domains WHERE id = ?');
        $stmt->bindValue(1, $id, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $exists = $result->fetchArray()[0] > 0;
        
        if (!$exists) {
            echo json_encode(['success' => false, 'message' => '指定ID的SSL域名配置不存在']);
            $db->close();
            exit;
        }
        
        // 删除记录
        $stmt = $db->prepare('DELETE FROM ssl_domains WHERE id = ?');
        $stmt->bindValue(1, $id, SQLITE3_INTEGER);
        $stmt->execute();
        
        echo json_encode(['success' => true, 'message' => 'SSL域名配置删除成功']);
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