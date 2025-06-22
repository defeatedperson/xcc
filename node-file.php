<?php
// 调整文件类型路径为当前文件夹下的相对路径（关键修改）
$fileTypePaths = [
    'cache'     => __DIR__ . '/data/config/cache',    // 当前文件夹下的data/config/cache
    'cachelist' => __DIR__ . '/data/config/cachelist',
    'certs'     => __DIR__ . '/data/config/certs',
    'conf'      => __DIR__ . '/data/config/conf',
    'list'      => __DIR__ . '/data/config/list',
    'lua'       => __DIR__ . '/data/config/lua'
];



// 数据库配置（根据需求指向当前文件夹/api/db）
$dbDir = __DIR__ . '/api/db';  // 当前文件所在目录的api/db子目录（Linux路径格式）
$dbFile = $dbDir . '/nodes.db';  // 与node-add.php共用同一张表

// 自动创建数据库目录（若不存在，参考node-add.php）
if (!is_dir($dbDir)) {
    if (!mkdir($dbDir, 0755, true)) {  // 递归创建目录，权限0755
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => "数据库目录 {$dbDir} 创建失败"]);
        exit;
    }
}

// 设置响应头（JSON优先，文件下载时动态调整）
header('Content-Type: application/json; charset=utf-8');

// 步骤1：接收并校验基础请求参数
// 替换原参数获取方式
$input = file_get_contents('php://input');
$params = json_decode($input, true);
$nodeId = $params['node_id'] ?? '';
$nodeKey = $params['node_key'] ?? '';
$fileType = $params['file_type'] ?? '';
$domain = $params['domain'] ?? '';

// 参数缺失校验
if (empty($nodeId) || empty($nodeKey) || empty($fileType) || empty($domain)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => '缺少必要参数：node_id、node_key、file_type或domain'
    ]);
    exit;
}

try {
    // 步骤2：连接数据库（参考node-add.php的PDO连接方式）
    $pdo = new PDO("sqlite:{$dbFile}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 步骤3：查询节点信息（验证node_id和node_key是否匹配）
    $stmt = $pdo->prepare("SELECT * FROM nodes WHERE id = :node_id AND node_key = :node_key");
    $stmt->bindParam(':node_id', $nodeId, PDO::PARAM_INT);
    $stmt->bindParam(':node_key', $nodeKey, PDO::PARAM_STR);
    $stmt->execute();
    $nodeInfo = $stmt->fetch(PDO::FETCH_ASSOC);

    // 节点验证失败处理
    if (!$nodeInfo) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => '节点ID或密钥验证失败'
        ]);
        exit;
    }

    // 步骤4：校验文件类型是否合法
    if (!isset($fileTypePaths[$fileType])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => '无效的文件类型，允许值：cache/cachelist/certs/conf/list/lua'
        ]);
        exit;
    }

    // 步骤5：构造目标文件路径（根据不同文件类型生成指定格式）
    $targetDir = $fileTypePaths[$fileType];
    // 定义各文件类型对应的文件名模板（关键修改）
    $fileTypeTemplates = [
        'cache'     => '%s.cache.conf',       // 示例：domain.cache.conf
        'cachelist' => '%s.cache.conf',       // 示例：domain.cache.conf
        'certs'     => ['%s.key', '%s.crt'],  // 证书需要两个文件（Go程序需分两次请求）
        'conf'      => '%s.conf',             // 示例：domain.conf
        'list'      => '%s.list.conf',        // 示例：domain.list.conf
        'lua'       => '%s.json'              // 示例：domain.json
    ];

    // 处理特殊类型：certs需要返回两个文件（Go程序需分两次请求）
    if ($fileType === 'certs') {
        // 这里假设Go程序会通过额外参数区分key/crt（如通过请求参数中的sub_type）
        $subType = $params['sub_type'] ?? 'key';  // 默认请求key文件
        $template = $subType === 'key' ? $fileTypeTemplates['certs'][0] : $fileTypeTemplates['certs'][1];
        $fileName = sprintf($template, $domain);
    } else {
        $fileName = sprintf($fileTypeTemplates[$fileType], $domain);
    }
    $filePath = "{$targetDir}/{$fileName}";

    // 新增：检查文件是否存在（关键修改）
    if (!file_exists($filePath)) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => "文件不存在：{$filePath}"
        ]);
        exit;
    }

    // 步骤6：读取文件内容并返回（切换为二进制流响应）
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
    readfile($filePath);
    exit;

    } catch (PDOException $e) {
    // 数据库异常处理
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '数据库操作失败：' . $e->getMessage()]);
    exit;
}
?>