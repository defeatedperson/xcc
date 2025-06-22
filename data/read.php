<?php
// 设置数据库目录和文件路径（与 write.php 保持一致）
// 或先定义根目录常量，再引用（更推荐）
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';
$dbDir = __DIR__ . '/db';
$dbFile = $dbDir . '/site_config.db';

// 新增：强制仅允许POST方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => '仅允许POST方法']);
    exit;
}
// 在 data/read.php 和 api/read_sql.php 的请求处理前添加
$receivedCsrfToken = $_POST['csrf_token'] ?? '';
if ($receivedCsrfToken !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
    exit;
}

try {
    // 连接 SQLite 数据库（自动创建不存在的文件）
    $pdo = new PDO("sqlite:{$dbFile}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);  // 开启错误异常模式

    // 新增：自动创建站点配置表（若不存在），结构与 write.php 保持一致
    $createSiteConfigTableSql = "
        CREATE TABLE IF NOT EXISTS site_config (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain TEXT NOT NULL UNIQUE,  -- 域名为唯一标识
            origin_url TEXT NOT NULL,     -- 原站地址
            ssl_enabled INTEGER NOT NULL DEFAULT 0,  -- SSL启用状态（0-禁用，1-启用）
            protection_enabled INTEGER NOT NULL DEFAULT 0  -- 防护启用状态
        )
    ";
    $pdo->exec($createSiteConfigTableSql);

    // 修改：分页参数通过 POST 获取（关键修复）
    $page = isset($_POST['page']) ? max(1, (int)$_POST['page']) : 1; // 页码，至少为1
    $pageSize = isset($_POST['pageSize']) ? max(1, (int)$_POST['pageSize']) : 3; // 每页数量，至少为1
    $offset = ($page - 1) * $pageSize;

    // 查询总记录数（新增）
    $countStmt = $pdo->query("SELECT COUNT(*) FROM site_config");
    $total = (int)$countStmt->fetchColumn();

    // 查询分页数据（修改：添加排序和分页）
    $stmt = $pdo->prepare("
        SELECT id, domain, origin_url, ssl_enabled, protection_enabled 
        FROM site_config 
        ORDER BY id ASC  -- 按id顺序排列
        LIMIT :pageSize OFFSET :offset
    ");
    $stmt->bindValue(':pageSize', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);  // 获取完整配置数组

    // 获取请求参数（修改：GET → POST）
    $action = $_POST['action'] ?? 'count';  // 关键修改：$_GET → $_POST

    // 根据参数决定返回内容（修改：参数获取方式）
    if ($action === 'domain_list') {  // 新增：仅返回域名列表
        $stmt = $pdo->prepare("SELECT domain FROM site_config ORDER BY id ASC");
        $stmt->execute();
        $domainList = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);  // 仅获取domain列
        $response = [
            'success' => true,
            'domains' => $domainList,
            'message' => '成功读取' . count($domainList) . '个域名'
        ];
    } elseif ($action === 'list')  {
        // 补充：处理分页列表的响应数据
        $response = [
            'success' => true,
            'domains' => $domains,  // 使用已查询的分页数据
            'total' => $total       // 使用已查询的总记录数
        ];
    } elseif ($action === 'get_domain_detail') {  // 新增：获取单个域名详细信息
        if (!isset($_POST['domain'])) {  // 关键修改：$_GET → $_POST
            $response = [
                'success' => false,
                'message' => '缺少必要参数domain'
            ];
        } else {
            $domain = $_POST['domain'];  // 关键修改：$_GET → $_POST
            $stmt = $pdo->prepare("
                SELECT id, domain, origin_url, ssl_enabled 
                FROM site_config 
                WHERE domain = :domain
            ");
            $stmt->bindValue(':domain', $domain, PDO::PARAM_STR);
            $stmt->execute();
            $domainData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($domainData) {
                $response = [
                    'success' => true,
                    'data' => $domainData
                ];
            } else {
                $response = [
                    'success' => false,
                    'message' => '未找到该域名的配置信息'
                ];
            }
        }
    } else {
        $response = [
            'success' => true,
            'count' => count($domains),
            'message' => '成功读取' . count($domains) . '个域名配置'
        ];
    }

    // 返回 JSON 格式结果
    header('Content-Type: application/json');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);  // 确保中文正常显示

} catch (PDOException $e) {
    // 异常处理（兼容原错误响应格式）
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '数据库查询失败：' . $e->getMessage()
    ]);
}
?>