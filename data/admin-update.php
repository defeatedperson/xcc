<?php
// 管理员配置更新接口（自动创建数据库/数据表）
// 包含公共认证文件（校验登录状态和权限）
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';

// 安全增强：强制仅允许POST方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '仅允许POST方法']);
    exit;
}

// 安全增强：CSRF令牌校验（与cache-rules.php保持一致）
$receivedCsrfToken = $_POST['csrf_token'] ?? '';
if ($receivedCsrfToken !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
    exit;
}

// 设置时区和响应头（与cache-rules.php统一）
date_default_timezone_set('Asia/Shanghai');
header('Content-Type: application/json; charset=utf-8');

// 数据库配置（与cache-rules.php共用同一路径，保持统一）
$dbDir = __DIR__ . '/db';
$dbFile = $dbDir . '/site_config.db';

try {
    // 步骤1：自动创建数据库目录（兼容Linux系统）
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0755, true); // 递归创建目录，权限0755（Linux标准）
    }

    // 步骤2：连接SQLite数据库（自动创建不存在的文件）
    $pdo = new PDO("sqlite:{$dbFile}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 步骤3：自动创建数据表（关键功能）
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_updates (
            domain TEXT NOT NULL PRIMARY KEY,  -- 域名（唯一键）
            config_status BOOLEAN NOT NULL,     -- 配置文件状态（布尔值）
            sync_status BOOLEAN NOT NULL,       -- 同步状态（布尔值）
            update_time DATETIME NOT NULL DEFAULT (datetime('now', 'localtime'))  -- 更新时间（自动填充）
        )
    ");

    // -------------------------- 新增数据处理逻辑 --------------------------
    // 校验必要参数（所有操作需传入action和domain）
    if (!isset($_POST['action'], $_POST['domain'])) {
        throw new Exception('缺少必要参数：action（操作类型）或domain（域名）', 400);
    }
    $action = trim($_POST['action']);
    $domain = trim($_POST['domain']);

    // 域名格式校验（与系统其他接口保持一致）
    $domainPattern = '/^[a-zA-Z0-9-]+(\.[a-zA-Z0-9-]+)*\.[a-zA-Z]{2,}$/';
    if (!preg_match($domainPattern, $domain)) {
        throw new Exception('域名格式无效（示例：example.com 或 sub.example.com）', 400);
    }

    // 根据操作类型执行不同逻辑
    switch ($action) {
        case 'add': // 新增/更新配置（域名不存在时创建，存在时更新）
            // 检查域名是否已存在
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM admin_updates WHERE domain = :domain");
            $checkStmt->bindParam(':domain', $domain, PDO::PARAM_STR);
            $checkStmt->execute();
            $exists = $checkStmt->fetchColumn() > 0;

            if ($exists) {
                // 存在则更新配置状态和同步状态为0（用户需求）
                $updateStmt = $pdo->prepare("
                    UPDATE admin_updates 
                    SET config_status = 0, 
                        sync_status = 0, 
                        update_time = datetime('now', 'localtime') 
                    WHERE domain = :domain
                ");
                $updateStmt->bindParam(':domain', $domain, PDO::PARAM_STR);
                $updateStmt->execute();
                $message = "域名 {$domain} 已存在，配置状态/同步状态更新为0";
            } else {
                // 不存在则创建记录（两个状态均为0）
                $insertStmt = $pdo->prepare("
                    INSERT INTO admin_updates (domain, config_status, sync_status, update_time)
                    VALUES (:domain, 0, 0, datetime('now', 'localtime'))
                ");
                $insertStmt->bindParam(':domain', $domain, PDO::PARAM_STR);
                $insertStmt->execute();
                $message = "新增域名 {$domain}，配置状态/同步状态/删除状态初始化为0";
            }
            $response = ['success' => true, 'message' => $message, 'domain' => $domain];
            break;

            case 'generate_config': // 生成配置文件（配置状态改为1）
            case 'update_sync':      // 更新同步状态（需传入sync_status参数）
                    // 通用状态更新逻辑（根据action动态选择字段）
                    $fieldMap = [
                        'generate_config' => 'config_status',
                        'update_sync' => 'sync_status'
                    ];
            $field = $fieldMap[$action];
            
            // 校验状态值（布尔类型，需为0或1）
            $status = isset($_POST['status']) ? (int)$_POST['status'] : 0;
            if (!in_array($status, [0, 1])) {
                throw new Exception("{$field} 状态值无效（需为0或1）", 400);
            }

            // 执行更新
            $updateStmt = $pdo->prepare("
                UPDATE admin_updates 
                SET {$field} = :status, 
                    update_time = datetime('now', 'localtime') 
                WHERE domain = :domain
            ");
            $updateStmt->bindParam(':status', $status, PDO::PARAM_INT);
            $updateStmt->bindParam(':domain', $domain, PDO::PARAM_STR);
            $updateStmt->execute();

            // 检查是否有记录被更新
            if ($updateStmt->rowCount() === 0) {
                throw new Exception("域名 {$domain} 不存在，无法更新 {$field} 状态", 404);
            }
            $response = [
                'success' => true,
                'message' => "域名 {$domain} 的 {$field} 状态更新为 {$status}",
                'domain' => $domain,
                'updated_field' => $field,
                'new_status' => $status
            ];
            break;

        default:
            throw new Exception("无效的操作类型：{$action}（可选值：add, generate_config, update_sync）", 400);
    }

    // 返回成功响应（替换原固定响应）
    echo json_encode($response);

} catch (Exception $e) {
    // 异常处理（与cache-rules.php保持一致）
    $statusCode = (int)$e->getCode() ?: 500;
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'debug' => $statusCode === 500 ? '数据库或服务器内部错误' : ''
    ]);
}
exit;
?>