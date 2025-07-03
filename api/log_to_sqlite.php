<?php
// 配置项（基于 Linux 系统，使用当前文件所在目录的相对路径）
$currentDir = __DIR__; // 获取当前文件所在目录的绝对路径（如：/data/cdn/php/admin_data）
$cacheDir = $currentDir . '/cache'; // 缓存目录：当前目录下的 cache 文件夹（路径示例：/data/cdn/php/admin_data/cache）
$dbPath = $currentDir . '/db/logs.db'; // SQLite 数据库路径：当前目录下的 db 文件夹中的 logs.db（路径示例：/data/cdn/php/admin_data/db/logs.db）
$retainDays = 7; // 数据保留天数（7天）

// 新增：日志目录和文件配置（关键修改）
$logDir = $currentDir . '/logs'; // 日志目录（当前文件同级的 logs 文件夹）
$logFileName = 'log_to_sql_error.log'; // 固定日志文件名（名称不变）
$logFile = $logDir . '/' . $logFileName; // 完整日志文件路径（如：/data/cdn/php/admin_data/logs/log_to_sql_error.log）
// 新增：恶意IP记录相关配置
$maxBlockedIps = 10; // 单次上传最大恶意IP记录数量
$ipOverflowMarker = '[IP_OVERFLOW]'; // 恶意IP数量过多的标记

// 安全增强：强制仅允许POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '仅允许POST方法']);
    exit;
}

// 初始化目录（新增：日志目录初始化）
// 1. 初始化缓存目录
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true); // 递归创建缓存目录（权限：用户读/写/执行，组和其他读/执行）
}
// 2. 初始化数据库目录
$dbDir = dirname($dbPath);
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0755, true);
}
// 3. 初始化日志目录（关键新增）
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true); // 递归创建日志目录（权限：用户读/写/执行，组和其他读/执行）
}

// 步骤1：接收上传文件和节点信息（新增节点密钥校验）
if (!isset($_FILES['log_file'], $_POST['node_id'], $_POST['node_key'])) {
    // 新增：记录参数缺失错误到日志文件（关键修改）
    error_log("参数缺失：log_file、node_id或node_key未提供\n", 3, $logFile);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少必要参数（log_file、node_id或node_key）']);
    exit;
}

$uploadFile = $_FILES['log_file']['tmp_name']; // 上传文件的临时路径
$originalName = $_FILES['log_file']['name']; // 原始文件名
$nodeId = trim($_POST['node_id']); // 节点唯一标识符（去首尾空格）
$nodeKey = trim($_POST['node_key']); // 节点密钥（去首尾空格）

// 步骤2：节点信息格式校验
// 节点ID校验（纯数字，1-5位）
if (!preg_match('/^\d{1,5}$/', $nodeId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '节点ID需为1-5位纯数字']);
    exit;
}

// 节点密钥校验（最大32位）
if (strlen($nodeKey) > 32) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '节点密钥长度不能超过32位']);
    exit;
}

// 步骤3：连接nodes.db验证节点信息（与node-update.php共用数据库）
$nodesDbDir = __DIR__ . '/db'; // 与node-update.php同目录
$nodesDbFile = $nodesDbDir . '/nodes.db'; // 与node-update.php共用nodes.db

// 确保数据库目录存在
if (!is_dir($nodesDbDir)) {
    mkdir($nodesDbDir, 0755, true);
    error_log("自动创建节点数据库目录：{$nodesDbDir}\n", 3, $logFile);
}

// 新增：日志记录函数
function writeNodeLog($nodeId, $result) {
    global $logFile;
    $ts = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$ts] 节点ID:{$nodeId} {$result}\n", FILE_APPEND);
}

try {
    $nodePdo = new PDO("sqlite:{$nodesDbFile}");
    $nodePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $nodePdo->prepare("SELECT id FROM nodes WHERE id = :id AND node_key = :key");
    $stmt->execute([
        ':id' => (int)$nodeId,
        ':key' => $nodeKey
    ]);
    
    $nodeResult = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$nodeResult) {
        writeNodeLog($nodeId, "验证失败: 节点ID或密钥验证失败");
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '节点ID或密钥验证失败']);
        exit;
    } else {
        writeNodeLog($nodeId, "验证成功");
    }
} catch (PDOException $e) {
    writeNodeLog($nodeId, "验证数据库错误: {$e->getMessage()}");
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '节点验证数据库错误：' . $e->getMessage()]);
    exit;
}

// 步骤4：重命名并移动至缓存目录（避免文件名冲突，格式：时间戳_原文件名（特殊字符替换为下划线））
$timestamp = date('YmdHis'); // 当前时间戳（如：20250515112544）
$cachedFileName = "{$timestamp}_" . preg_replace('/[^a-zA-Z0-9.]/', '_', $originalName); // 重命名后的文件名
$cachedFilePath = "{$cacheDir}/{$cachedFileName}"; // 缓存文件完整路径（如：/data/cdn/php/admin_data/cache/20250515112544_request.log）

// 移动上传文件到缓存目录（失败时返回错误）
if (!move_uploaded_file($uploadFile, $cachedFilePath)) {
    // 新增：记录文件移动失败错误到日志文件（关键修改）
    error_log("文件移动失败：临时文件 {$uploadFile} 无法移动至 {$cachedFilePath}\n", 3, $logFile);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '文件移动至缓存目录失败']);
    exit;
}

// 立即返回成功响应，并断开客户端连接，兼容 nginx+php-fpm 和 Apache
$response = [
    'success' => true,
    'message' => '文件已成功接收，正在后台处理',
    'cached_file' => basename($cachedFilePath)
];

if (function_exists('fastcgi_finish_request')) {
    // FastCGI 环境（nginx+php-fpm）
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode($response);
    fastcgi_finish_request();
} else {
    // 兼容 Apache mod_php 或其他环境
    ignore_user_abort(true);
    set_time_limit(0);
    ob_start();
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode($response);
    header('Connection: close');
    header('Content-Length: ' . ob_get_length());
    ob_end_flush();
    flush();
}

// 后台处理逻辑（关键新增）
try {
    // 连接 SQLite 数据库（自动创建文件，需确保 db 目录有写权限）
    $pdo = new PDO("sqlite:{$dbPath}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // 开启错误异常模式

    // 创建表结构（首次运行时自动创建，兼容 request 和 ban 两种日志类型）
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
        -- 添加唯一约束：request日志（log_time+domain+node_id）
        UNIQUE(log_time, domain, node_id) ON CONFLICT IGNORE,
        -- 添加唯一约束：ban日志（log_time+ip+node_id）
        UNIQUE(log_time, ip, node_id) ON CONFLICT IGNORE
    )
    ");

    // 新增：为高频查询字段添加索引（node_id和log_time）
    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_node_id ON request_logs (node_id);
        CREATE INDEX IF NOT EXISTS idx_log_time ON request_logs (log_time);
    ");

    // 新增：预查询当前节点最后一条 request 和 ban 日志的 log_time
    $lastRequestTime = '0000-00-00 00:00:00'; // 默认值（无记录时全量解析）
    $lastBanTime = '0000-00-00 00:00:00';

    // 查询 request 日志最后时间（domain 非空表示 request 类型）
    $requestStmt = $pdo->prepare("
        SELECT MAX(log_time) AS last_time 
        FROM request_logs 
        WHERE node_id = :node_id 
          AND domain IS NOT NULL
    ");
    $requestStmt->execute([':node_id' => $nodeId]);
    $requestResult = $requestStmt->fetch(PDO::FETCH_ASSOC);
    if ($requestResult && $requestResult['last_time']) {
        $lastRequestTime = $requestResult['last_time'];
    }

    // 查询 ban 日志最后时间（ip 非空表示 ban 类型）
    $banStmt = $pdo->prepare("
        SELECT MAX(log_time) AS last_time 
        FROM request_logs 
        WHERE node_id = :node_id 
          AND ip IS NOT NULL
    ");
    $banStmt->execute([':node_id' => $nodeId]);
    $banResult = $banStmt->fetch(PDO::FETCH_ASSOC);
    if ($banResult && $banResult['last_time']) {
        $lastBanTime = $banResult['last_time'];
    }

    // 步骤5：解析缓存日志文件并写入数据库
    $handle = fopen($cachedFilePath, 'r');
    if (!$handle) {
        error_log("缓存文件打开失败：{$cachedFilePath}\n", 3, $logFile);
        return; // 跳过后续解析逻辑
    }
    if ($handle) {
        // 新增：恶意IP计数器和记录容器
        $bannedIpCount = 0;
        $bannedIpRecords = [];
        $requestRecords = [];
        $ipOverflowDetected = false;

        while (($line = fgets($handle)) !== false) { // 逐行读取日志
            $line = trim($line); // 去除行首尾空白

            // 判断日志类型并解析（支持 request 和 ban 两种格式）
            if (strpos($line, '域名[') !== false) { // request 日志格式
                if (preg_match(
                    '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] 域名\[([^\]]+)\] 每分钟请求数: (\d+)，出站流量: (\d+) bytes$/',
                    $line,
                    $matches
                )) {
                    $logTime = $matches[1];

                    // 新增：校验 request 日志时间格式（YYYY-MM-DD HH:MM:SS）
                    $date = DateTime::createFromFormat('Y-m-d H:i:s', $logTime);
                    if (!$date || $date->format('Y-m-d H:i:s') !== $logTime) {
                        error_log("无效的 request 日志时间格式：{$logTime}（节点：{$nodeId}）");
                        continue; // 跳过格式错误的日志行
                    }

                    // 仅保留 log_time 晚于最后一条 request 日志的条目
                    if ($logTime > $lastRequestTime) { 
                        $requestRecords[] = [
                            'log_time' => $logTime,      // 日志时间
                            'domain' => $matches[2],        // 域名
                            'requests' => (int)$matches[3], // 请求数（转为整数）
                            'traffic_bytes' => (int)$matches[4], // 流量（字节，转为整数）
                            'node_id' => $nodeId            // 节点ID
                        ];
                    }
                }
            } elseif (strpos($line, '封禁时间:') !== false) { // ban 日志格式
                if (preg_match(
                    '/^封禁时间: (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \| IP: ([0-9.]+)$/',
                    $line,
                    $matches
                )) {
                    $logTime = $matches[1];

                    // 新增：校验 ban 日志时间格式（YYYY-MM-DD HH:MM:SS）
                    $date = DateTime::createFromFormat('Y-m-d H:i:s', $logTime);
                    if (!$date || $date->format('Y-m-d H:i:s') !== $logTime) {
                        error_log("无效的 ban 日志时间格式：{$logTime}（节点：{$nodeId}）");
                        continue; // 跳过格式错误的日志行
                    }

                    // 仅保留 log_time 晚于最后一条 ban 日志的条目
                    if ($logTime > $lastBanTime) { 
                        // 恶意IP日志，计数+1
                        $bannedIpCount++;
                        
                        // 如果超过最大数量，标记溢出状态
                        if ($bannedIpCount > $maxBlockedIps && !$ipOverflowDetected) {
                            // 记录一条溢出标记（仅记录一次）
                            $bannedIpRecords[] = [
                                'log_time' => $logTime, // 使用当前日志时间而非系统时间
                                'ip' => $ipOverflowMarker,
                                'node_id' => $nodeId
                            ];
                            $ipOverflowDetected = true; // 设置溢出标记，避免重复记录
                            
                            // 记录溢出警告日志
                            error_log("警告：节点 {$nodeId} 提交的恶意IP数量超过 {$maxBlockedIps} 个，已使用标记：{$ipOverflowMarker}");
                        } 
                        
                        // 只有在未超过最大数量或首次发现溢出时才记录实际IP
                        if ($bannedIpCount <= $maxBlockedIps) {
                            $bannedIpRecords[] = [
                                'log_time' => $logTime, // 日志时间
                                'ip' => $matches[2],       // 被封禁IP
                                'node_id' => $nodeId       // 节点ID
                            ];
                        }
                    }
                }
            }
        }
        fclose($handle); // 关闭文件句柄
            
            // 批量插入请求日志
            $pdo->beginTransaction();
            try {
                // 插入 request 日志
                if (!empty($requestRecords)) {
                    $stmt = $pdo->prepare("
                        INSERT INTO request_logs (log_time, domain, requests, traffic_bytes, node_id)
                        VALUES (:log_time, :domain, :requests, :traffic_bytes, :node_id)
                    ");
                    foreach ($requestRecords as $record) {
                        $stmt->execute($record);
                    }
                }
                
                // 修复bannedIp插入同样的问题
                if (!empty($bannedIpRecords)) {
                    $stmt = $pdo->prepare("
                        INSERT INTO request_logs (log_time, ip, node_id)
                        VALUES (:log_time, :ip, :node_id)
                    ");
                    foreach ($bannedIpRecords as $record) {
                        $stmt->execute($record);
                    }
                }
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("请求日志插入失败（节点：{$nodeId}，文件：{$cachedFileName}）：" . $e->getMessage());
            }
        }

        // 步骤6：自动清理7天前的数据（通过 SQL 语句直接删除过期记录，无需全量读取）
        $pdo->exec("
        DELETE FROM request_logs 
        WHERE create_time < datetime('now', '-{$retainDays} days')
        ");
// 错误处理（关键修改：补充日志记录）
} catch (PDOException $e) {
    // 记录数据库错误到日志文件（参数3表示写入文件，\n确保换行）
    error_log("数据库错误：{$e->getMessage()}\n", 3, $logFile);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '数据库操作失败：' . $e->getMessage()]);
    exit;
} catch (Exception $e) {
    // 记录通用异常到日志文件
    error_log("程序异常：{$e->getMessage()}\n", 3, $logFile);
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
} finally {
    // 清理缓存文件（增强版）
    $retry = 3;
    while ($retry > 0 && file_exists($cachedFilePath)) {
        if (unlink($cachedFilePath)) {
            break; // 删除成功，退出循环
        }
        $retry--;
        usleep(100000); // 等待 0.1 秒后重试
    }
    if ($retry === 0 && file_exists($cachedFilePath)) {
        error_log("缓存文件删除失败（重试 3 次）：{$cachedFilePath}", 3, $logFile);
    }
}
?>
