<?php
// 或先定义根目录常量，再引用（更推荐）
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';

// 新增：强制仅允许 POST 请求（关键安全增强）
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => '仅允许 POST 方法']);
    exit;
}
// 在 data/read.php 和 api/read_sql.php 的请求处理前添加
$receivedCsrfToken = $_POST['csrf_token'] ?? '';
if ($receivedCsrfToken !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
    exit;
}
// 配置项（与 log_to_sqlite.php 共用数据库路径，兼容Linux系统路径）
$dbPath = __DIR__ . '/db/logs.db'; // 数据库路径（Linux下为 /data/cdn/php/admin_data/db/logs.db）

// 新增：恶意IP溢出标记（与log_to_sqlite.php保持一致）
$ipOverflowMarker = '[IP_OVERFLOW]'; 

// 步骤1：接收并校验参数（修改为POST获取）
$action = $_POST['action'] ?? 'default'; // 默认执行默认查询（修改：$_GET → $_POST）
$domain = $_POST['domain'] ?? ''; // 修改：$_GET → $_POST
$timeRange = $_POST['time_range'] ?? 'last_10min'; // 默认最近10分钟（修改：$_GET → $_POST）
$allowedTimeRanges = ['last_10min', 'today', 'yesterday', 'last_7days'];
$allowedActions = ['default', 'get_domains', 'get_blocked_ips']; // 新增允许的action

// 参数校验（新增action校验）
if (!in_array($action, $allowedActions)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '无效的action参数（可选值：default/get_domains）']);
    exit;
}

// 处理不同action的逻辑分支
try {
    // 新增：自动创建数据库目录（兼容Linux系统）
    $dbDir = dirname($dbPath);
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0755, true); // 递归创建目录，权限0755（用户读写执行，组和其他读执行）
    }
    $pdo = new PDO("sqlite:{$dbPath}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 新增：自动创建数据表（与 log_to_sqlite.php 保持表结构一致）
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS request_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            log_time TEXT NOT NULL,       -- 日志时间（格式：YYYY-MM-DD HH:MM:SS）
            domain TEXT,                 -- 域名（request日志特有字段）
            requests INTEGER,            -- 请求数（request日志特有字段）
            traffic_bytes INTEGER,       -- 流量（字节，request日志特有字段）
            ip TEXT,                     -- IP地址（ban日志特有字段）
            node_id TEXT NOT NULL,       -- 节点ID（标识日志来源）
            create_time DATETIME DEFAULT CURRENT_TIMESTAMP, -- 记录创建时间（用于自动清理过期数据）
            UNIQUE(log_time, domain, node_id) ON CONFLICT IGNORE,  -- 补充request日志唯一约束
            UNIQUE(log_time, ip, node_id) ON CONFLICT IGNORE       -- 补充ban日志唯一约束
        )
    ");

    if ($action === 'default') {
        // 需求1：默认查询（最近10分钟所有域名的数据）
        // 参数校验（仅校验time_range）
        if (!in_array($timeRange, $allowedTimeRanges)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '时间范围错误（可选值：last_10min/today/yesterday/last_7days）']);
            exit;
        }

        // 计算时间范围（与原逻辑一致）
        $now = new DateTime();
        $startTime = '';
        $endTime = '';
        $groupFormat = '';
        $timeRangeDesc = '';
        switch ($timeRange) {
            case 'last_10min':
                $startTime = (clone $now)->modify('-10 minutes')->format('Y-m-d H:i:s');
                $endTime = $now->format('Y-m-d H:i:s');
                $groupFormat = '%Y-%m-%d %H:%M';
                $timeRangeDesc = "最近10分钟（{$startTime} 至 {$endTime}）";
                break;
            case 'today':
                $startTime = date('Y-m-d 00:00:00');
                $endTime = date('Y-m-d 23:59:59');
                $groupFormat = '%Y-%m-%d %H:00';
                $timeRangeDesc = "今天（{$startTime} 至 {$endTime}）";
                break;
            case 'yesterday':
                $startTime = date('Y-m-d 00:00:00', strtotime('-1 day'));
                $endTime = date('Y-m-d 23:59:59', strtotime('-1 day'));
                $groupFormat = '%Y-%m-%d %H:00';
                $timeRangeDesc = "昨天（{$startTime} 至 {$endTime}）";
                break;
            case 'last_7days':
                $startTime = date('Y-m-d 00:00:00', strtotime('-7 days'));
                $endTime = date('Y-m-d 23:59:59');
                $groupFormat = '%Y-%m-%d';
                $timeRangeDesc = "近7天（{$startTime} 至 {$endTime}）";
                break;
        }

        // 查询逻辑（关键修改：添加域名过滤条件）
    $whereClause = "log_time BETWEEN :start_time AND :end_time AND domain IS NOT NULL";
    $params = [':start_time' => $startTime, ':end_time' => $endTime];
    
    // 如果传入了具体域名，添加过滤条件
    if (!empty($domain)) {
        $whereClause .= " AND domain = :domain";
        $params[':domain'] = $domain; // 绑定域名参数，防止SQL注入
    }

    $requestStmt = $pdo->prepare("
        SELECT 
            strftime('{$groupFormat}', log_time) AS time_slot,
            SUM(requests) AS total_requests, 
            SUM(traffic_bytes) AS total_traffic 
        FROM request_logs 
        WHERE {$whereClause}
        GROUP BY time_slot 
        ORDER BY time_slot ASC
    ");
    $requestStmt->execute($params); // 使用动态参数执行查询
    $requestData = $requestStmt->fetchAll(PDO::FETCH_ASSOC);

    // 查询拉黑IP（移除域名过滤）
    $banWhereClause = "ip IS NOT NULL AND log_time BETWEEN :start_time AND :end_time";
    $banParams = [':start_time' => $startTime, ':end_time' => $endTime];  // 不再绑定域名参数

    $banStmt = $pdo->prepare("
        SELECT DISTINCT ip 
        FROM request_logs 
        WHERE {$banWhereClause}
    ");
    $banStmt->execute($banParams);
    $banIPs = $banStmt->fetchAll(PDO::FETCH_COLUMN, 0) ?? [];

        $result = [
            'success' => true,
            'action' => 'default',
            'time_range' => $timeRangeDesc,
            'data_points' => $requestData,
            'blocked_ips' => $banIPs
        ];

    } else {
        // 需求2：获取数据库存在的域名（action=get_domains）
        $domainStmt = $pdo->prepare("
            SELECT DISTINCT domain 
            FROM request_logs 
            WHERE domain IS NOT NULL  -- 排除非request日志
            ORDER BY domain ASC  -- 按域名排序
        ");
        $domainStmt->execute();
        $domains = $domainStmt->fetchAll(PDO::FETCH_COLUMN, 0) ?? [];

        $result = [
            'success' => true,
            'action' => 'get_domains',
            'domains' => $domains
        ];
    }

    if ($action === 'get_blocked_ips') {
        // 分页参数校验
        $page = (int)($_POST['page'] ?? 1);
        $pageSize = (int)($_POST['pageSize'] ?? 5);
        if ($page < 1) $page = 1;
        if ($pageSize < 1 || $pageSize > 50) $pageSize = 5; // 限制每页最大50条
    
        // 新增：检查是否存在IP溢出标记
        $overflowStmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM request_logs 
            WHERE ip = :overflow_marker
        ");
        $overflowStmt->execute([':overflow_marker' => $ipOverflowMarker]);
        $hasOverflow = (bool)$overflowStmt->fetchColumn();
    
        // 查询总记录数（排除溢出标记）
        $countStmt = $pdo->prepare("
            SELECT COUNT(DISTINCT ip) AS total 
            FROM request_logs 
            WHERE ip IS NOT NULL AND ip != :overflow_marker
        ");
        $countStmt->execute([':overflow_marker' => $ipOverflowMarker]);
        $total = $countStmt->fetchColumn();
    
        // 查询分页数据（排除溢出标记）
        $offset = ($page - 1) * $pageSize;
        $blockStmt = $pdo->prepare("
            SELECT log_time, ip 
            FROM request_logs 
            WHERE ip IS NOT NULL AND ip != :overflow_marker
            GROUP BY ip 
            ORDER BY log_time DESC 
            LIMIT :limit OFFSET :offset
        ");
        $blockStmt->bindValue(':overflow_marker', $ipOverflowMarker, PDO::PARAM_STR);
        $blockStmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $blockStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $blockStmt->execute();
        $blockedIps = $blockStmt->fetchAll(PDO::FETCH_ASSOC);
    
        $result = [
            'success' => true,
            'action' => 'get_blocked_ips',
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'blocked_ips' => $blockedIps,
            'has_overflow' => $hasOverflow, // 新增：标记是否存在溢出情况
            'overflow_message' => $hasOverflow ? '数量过多，建议清理IP列表' : '' // 新增：溢出提示消息
        ];
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '数据库查询失败：' . $e->getMessage()]);
}
?>