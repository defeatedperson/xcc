<?php
// 或先定义根目录常量，再引用（更推荐）
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';

// 新增：设置JSON响应头（关键修复）
header('Content-Type: application/json; charset=utf-8');

// 在文件顶部添加
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
// 新增：设置 PHP 时区（与 SQLite 的 localtime 一致）
date_default_timezone_set('Asia/Shanghai');

// 设置数据库目录和文件路径（Linux系统路径）
$dbDir = __DIR__ . '/db';  // 数据库目录：当前文件所在目录的db子目录
$dbFile = $dbDir . '/site_config.db';  // 数据库文件路径

// 自动创建数据库目录（若不存在）
if (!is_dir($dbDir)) {
    if (!mkdir($dbDir, 0755, true)) {  // 递归创建目录，权限0755
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => "数据库目录 {$dbDir} 创建失败"]);
        exit;
    }
}

// 新增：处理域名保存逻辑
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_GET['action'] === 'save_domain') {
    try {
        // 连接 SQLite 数据库（与 read.php 保持一致）
        $pdo = new PDO("sqlite:{$dbFile}");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);  // 开启错误异常模式

        // 新增：自动创建 site_config 表（若不存在）
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

        // --------------------------
        // 步骤1：基础参数校验
        // --------------------------
        if (!isset($_POST['domain']) || !isset($_POST['originUrl'])) {
            throw new Exception('缺少必要参数：domain或originUrl');
        }

        $domain = trim($_POST['domain']);
        $originUrl = trim($_POST['originUrl']);
        $type = $_POST['type'] ?? '';

        // --------------------------
        // 步骤2：格式校验（域名/原站地址）
        // --------------------------
        // 域名正则（支持中文域名xn--前缀，要求至少两级域名）
        $domainPattern = '/^(xn--)?[a-zA-Z0-9-]+(?:\.[a-zA-Z0-9-]+)*\.[a-zA-Z]{2,}$/';
        if (!preg_match($domainPattern, $domain)) {
            throw new Exception('域名格式错误（示例：example.com 或 xn--中文域名格式）');
        }

        // 原站地址正则（支持http/https协议、域名/IP+端口）
        $originUrlPattern = '/^https?:\/\/(?:[a-zA-Z0-9-]+(?:\.[a-zA-Z0-9-]+)*(?:\:[0-9]+)?|(?:[0-9]{1,3}\.){3}[0-9]{1,3}(?:\:[0-9]+)?)$/';
        if (!preg_match($originUrlPattern, $originUrl)) {
            throw new Exception('原站地址格式错误（示例：http://origin.example.com 或 https://192.168.1.1:8080）');
        }

        // --------------------------
        // 步骤3：长度校验（遵循RFC规范和常见限制）
        // --------------------------
        if (strlen($domain) > 253) {
            throw new Exception('域名总长度不能超过253字符');
        }
        $domainLabels = explode('.', $domain);
        foreach ($domainLabels as $label) {
            if (strlen($label) > 63 || $label === '') {
                throw new Exception('域名标签长度不能超过63字符且不能为空');
            }
        }

        if (strlen($originUrl) > 2048) {
            throw new Exception('原站地址长度不能超过2048字符');
        }

        // --------------------------
        // 步骤4：检查域名是否已存在
        // --------------------------
        $checkStmt = $pdo->prepare("SELECT id FROM site_config WHERE domain = ?");
        $checkStmt->execute([$domain]);
        $exists = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($exists) {
            // 编辑模式：仅更新原站地址（保留原有SSL和防护状态）
            $updateStmt = $pdo->prepare("UPDATE site_config SET origin_url = ? WHERE domain = ?");
            $updateStmt->execute([$originUrl, $domain]);

            // 同步更新 admin_updates 的 sync_status 为 0（删除状态字段已移除）
            $updateAdminStmt = $pdo->prepare("
                UPDATE admin_updates 
                SET sync_status = 0  -- 仅重置同步状态
                WHERE domain = :domain
            ");
            $updateAdminStmt->execute([':domain' => $domain]);
            $adminUpdated = $updateAdminStmt->rowCount();

            echo json_encode([
                'success' => true,
                'message' => '域名更新成功（已同步重置同步状态）'
            ]);
        } else {
            // 新增模式：开启事务保证原子性（所有操作要么全部成功，要么全部回滚）
            $pdo->beginTransaction();

            try {
                // 插入新记录（使用前端传递或默认值）
                $sslEnabled = isset($_POST['ssl_enabled']) ? (int)$_POST['ssl_enabled'] : 0;
                $protectionEnabled = isset($_POST['protection_enabled']) ? (int)$_POST['protection_enabled'] : 0;
                
                $insertStmt = $pdo->prepare("
                    INSERT INTO site_config (domain, origin_url, ssl_enabled, protection_enabled)
                    VALUES (?, ?, ?, ?)
                ");
                $insertStmt->execute([$domain, $originUrl, $sslEnabled, $protectionEnabled]);

                // 写入默认CC防护规则（参考cc_form.php的默认值）
                $defaultCCRules = [
                    'interval' => 60,          // 全局统计时间（秒）
                    'threshold' => 100,        // 全局触发请求数
                    'valid_duration' => 300,   // 全局验证有效期（秒）
                    'max_try' => 3,            // 全局最大尝试次数
                    'custom_rules' => [
                        'interval' => 30,      // 个人统计时间（秒）
                        'threshold' => 50,     // 个人触发请求数
                        'max_threshold' => 200 // 个人最大封禁请求数
                    ]
                ];
                $jsonData = json_encode($defaultCCRules);

                // 自动创建cc_robots表（与cc-robots.php保持表结构一致）
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS cc_robots (
                        domain TEXT PRIMARY KEY NOT NULL,
                        update_time DATETIME NOT NULL DEFAULT (datetime('now', 'localtime')),
                        json_data TEXT NOT NULL CHECK (json_data != '')
                    )
                ");

                // 写入默认规则（使用REPLACE INTO确保幂等性，避免重复写入）
                $ccStmt = $pdo->prepare("
                    REPLACE INTO cc_robots (domain, json_data)
                    VALUES (:domain, :json_data)
                ");
                $ccStmt->execute([':domain' => $domain, ':json_data' => $jsonData]);

                // 同步更新 admin_updates 的 sync_status 为 0（删除状态字段已移除）
                $updateAdminStmt = $pdo->prepare("
                    UPDATE admin_updates 
                    SET sync_status = 0  -- 仅重置同步状态
                    WHERE domain = :domain
                ");
                $updateAdminStmt->execute([':domain' => $domain]);
                $adminUpdated = $updateAdminStmt->rowCount();

                // 提交事务（包含 admin_updates 的更新）
                $pdo->commit();
                echo json_encode([
                    'success' => true,
                    'message' => '新域名添加成功（已初始化默认CC防护规则，同步重置同步状态）'
                ]);
            } catch (Exception $e) {
                // 任意一步失败，回滚事务
                $pdo->rollBack();
                throw new Exception("新增域名失败（事务回滚）：" . $e->getMessage());
            }
        }
    } catch (PDOException $e) {
        // 数据库相关异常（如连接失败、SQL语法错误）
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '数据库操作失败：' . $e->getMessage()]);
    } catch (Exception $e) {
        // 业务逻辑异常（如参数校验失败）
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit; // 确保脚本终止，避免后续代码执行
}

?>