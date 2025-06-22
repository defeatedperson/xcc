<?php
// 黑白名单规则管理接口（支持写入/读取/删除域名的黑白名单规则）
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';

// 强制仅允许POST方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
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

date_default_timezone_set('Asia/Shanghai');
header('Content-Type: application/json; charset=utf-8');

$dbDir = __DIR__ . '/db';
$dbFile = $dbDir . '/site_config.db';

try {
    // 基础参数校验（所有操作必填domain）
    if (!isset($_POST['domain']) || empty(trim($_POST['domain']))) {
        throw new Exception('缺少必要参数：domain', 400);
    }
    $domain = trim($_POST['domain']);

    // 域名格式校验（与cache-rules.php保持一致）
    $domainPattern = '/^[a-zA-Z0-9-]+(\.[a-zA-Z0-9-]+)*\.[a-zA-Z]{2,}$/';
    if (!preg_match($domainPattern, $domain)) {
        throw new Exception('域名格式无效（示例：example.com 或 sub.example.com）', 400);
    }

    // 连接数据库（自动创建目录）
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0755, true);
    }
    $pdo = new PDO("sqlite:{$dbFile}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 创建表（字段：域名、类型、名单类型、内容、备注、更新时间）
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ip_rules (
            domain TEXT NOT NULL,
            type TEXT NOT NULL CHECK (type IN ('url', 'ip')),
            list_type TEXT NOT NULL CHECK (list_type IN ('black', 'white')),
            content TEXT NOT NULL,
            remark TEXT,
            update_time DATETIME NOT NULL DEFAULT (datetime('now', 'localtime')),
            PRIMARY KEY (domain, type, list_type, content)  -- 组合主键避免重复
        )
    ");

    $action = $_POST['action'] ?? 'save';

    if ($action === 'get') {
        // 读取规则
        $stmt = $pdo->prepare("
            SELECT type, list_type, content, remark 
            FROM ip_rules 
            WHERE domain = :domain
            ORDER BY update_time DESC
        ");
        $stmt->bindParam(':domain', $domain);
        $stmt->execute();
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'rules' => $rules]);

    } elseif ($action === 'delete') {
        // 删除规则
        $requiredParams = ['type', 'list_type', 'content'];
        foreach ($requiredParams as $param) {
            if (!isset($_POST[$param])) throw new Exception("缺少必要参数：{$param}", 400);
        }
        $stmt = $pdo->prepare("
            DELETE FROM ip_rules 
            WHERE domain = :domain 
              AND type = :type 
              AND list_type = :list_type 
              AND content = :content
        ");
        $stmt->execute([
            ':domain' => $domain,
            ':type' => $_POST['type'],
            ':list_type' => $_POST['list_type'],
            ':content' => $_POST['content']
        ]);
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => '规则删除成功']);
        } else {
            throw new Exception('未找到匹配的规则', 404);
        }

    } else {
        // 保存规则（新增/更新）
        $requiredParams = ['type', 'list_type', 'content'];
        foreach ($requiredParams as $param) {
            if (!isset($_POST[$param])) throw new Exception("缺少必要参数：{$param}", 400);
        }
        $content = trim($_POST['content']);
        $remark = $_POST['remark'] ?? '';

        // 内容长度校验
        if (strlen($content) > 255) {
            throw new Exception('内容长度不能超过255字符', 400);
        }

        // 执行保存（REPLACE INTO自动处理重复）
        $stmt = $pdo->prepare("
            REPLACE INTO ip_rules 
            (domain, type, list_type, content, remark)
            VALUES (:domain, :type, :list_type, :content, :remark)
        ");
        $stmt->execute([
            ':domain' => $domain,
            ':type' => $_POST['type'],
            ':list_type' => $_POST['list_type'],
            ':content' => $content,
            ':remark' => $remark
        ]);
        echo json_encode([
            'success' => true,
            'message' => '规则保存成功',
            'update_time' => date('Y-m-d H:i:s')
        ]);
    }

} catch (Exception $e) {
    http_response_code($e->getCode() >= 400 ? $e->getCode() : 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}