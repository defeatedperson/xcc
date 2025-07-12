<?php
// 或先定义根目录常量，再引用（更推荐）
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';
// 强制仅允许POST方法（全局检查）
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
// 设置 PHP 时区（与 SQLite 的 localtime 一致）
date_default_timezone_set('Asia/Shanghai');
header('Content-Type: application/json; charset=utf-8');

// 数据库配置（统一路径）
$dbDir = __DIR__ . '/db';
$dbFile = $dbDir . '/site_config.db';

try {
    // 步骤1：基础参数校验（所有操作必填domain）
    if (!isset($_POST['domain']) || empty(trim($_POST['domain']))) {
        throw new Exception('缺少必要参数：domain', 400);
    }
    $domain = trim($_POST['domain']);

    // 步骤2：域名格式校验（统一校验）
    $domainPattern = '/^([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}$/'; 
    if (!preg_match($domainPattern, $domain)) {
        throw new Exception('域名格式无效（示例：example.com）', 400);
    }

    // 步骤3：根据action参数区分操作类型
    $action = $_POST['action'] ?? 'save'; 

    // 连接数据库（统一连接，避免重复）
    $pdo = new PDO("sqlite:{$dbFile}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($action === 'get') {
        // 处理「读取规则」请求
        $stmt = $pdo->prepare("SELECT json_data, update_time FROM cc_robots WHERE domain = :domain");
        $stmt->bindParam(':domain', $domain, PDO::PARAM_STR);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            echo json_encode([
                'success' => true,
                'domain' => $domain,
                'json_data' => json_decode($result['json_data'], true),
                'update_time' => $result['update_time']
            ]);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '未找到该域名的CC防护规则']);
        }
    } else {
        // 处理「保存规则」请求（合并原写入逻辑）
        if (!isset($_POST['json_data']) || empty(trim($_POST['json_data']))) {
            throw new Exception('缺少必要参数：json_data', 400);
        }
        $jsonData = trim($_POST['json_data']);

        // JSON格式校验
        $decodedJson = json_decode($jsonData, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON数据格式无效：' . json_last_error_msg(), 400);
        }
        if (!is_array($decodedJson)) {
            throw new Exception('JSON数据必须为对象格式', 400);
        }

        // 业务字段内容校验（正整数）
        $requiredFields = [
            'interval' => '全局统计时间（秒）',
            'threshold' => '全局触发请求数',
            'valid_duration' => '全局验证有效期（秒）',
            'max_try' => '全局最大尝试次数',
            'custom_rules' => [
                'interval' => '个人统计时间（秒）',
                'threshold' => '个人触发请求数',
                'max_threshold' => '个人最大封禁请求数'
            ]
        ];

        // 校验全局规则字段
        foreach (['interval', 'threshold', 'valid_duration', 'max_try'] as $field) {
            if (!isset($decodedJson[$field]) || !is_int($decodedJson[$field]) || $decodedJson[$field] < 1) {
                throw new Exception("{$requiredFields[$field]}必须为正整数（≥1）", 400);
            }
        }

        // 校验个人规则字段
        if (!isset($decodedJson['custom_rules']) || !is_array($decodedJson['custom_rules'])) {
            throw new Exception('个人规则数据缺失或格式错误', 400);
        }
        foreach (['interval', 'threshold', 'max_threshold'] as $field) {
            if (!isset($decodedJson['custom_rules'][$field]) || 
                !is_int($decodedJson['custom_rules'][$field]) || 
                $decodedJson['custom_rules'][$field] < 1) {
                throw new Exception("{$requiredFields['custom_rules'][$field]}必须为正整数（≥1）", 400);
            }
        }

        // 自动创建表
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS cc_robots (
                domain TEXT PRIMARY KEY NOT NULL,
                update_time DATETIME NOT NULL DEFAULT (datetime('now', 'localtime')),
                json_data TEXT NOT NULL CHECK (json_data != '')
            )
        ");

        // 写入/更新数据
        $stmt = $pdo->prepare("
            REPLACE INTO cc_robots (domain, json_data) 
            VALUES (:domain, :json_data)
        ");
        $stmt->bindParam(':domain', $domain, PDO::PARAM_STR);
        $stmt->bindParam(':json_data', $jsonData, PDO::PARAM_STR);
        $stmt->execute();

        // 更新site_config表
        $updateStmt = $pdo->prepare("UPDATE site_config SET protection_enabled = 1 WHERE domain = :domain");
        $updateStmt->bindParam(':domain', $domain, PDO::PARAM_STR);
        $updateStmt->execute();

        // 返回成功响应
        echo json_encode([
            'success' => true,
            'message' => 'CC防护规则保存成功',
            'update_time' => date('Y-m-d H:i:s'),
            'domain' => $domain
        ]);
    }

} catch (Exception $e) {
    // 统一捕获所有异常（包括PDOException和自定义异常）
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
exit;
?>