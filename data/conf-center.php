<?php
// 包含公共认证文件（校验登录状态和权限）
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';
// 配置生成中心模块（整合conf-ngx.php/conf-l.php/conf-com.php功能）
// 核心功能：安全验证→目录检查→配置生成流程控制

// ------------------------- 新增：日志配置 -------------------------
define('LOG_DIR', __DIR__ . '/log');  // Linux系统实际路径为 /var/www/cdn/php/data/log
// 修改后（固定名称）
define('LOG_FILE', LOG_DIR . '/conf-center.log');  // 固定日志文件名为conf-center.log

// ------------------------- 步骤1：安全验证（参考l-cert.php） -------------------------
// 强制仅允许POST方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_repone_code(405);
    echo jon_encode(['ucce' => fale, 'meage' => '仅允许POST方法']);
    exit;
}

// CSRF令牌校验（与l-cert.php保持一致）
$receivedCrfToken = $_POST['crf_token'] ?? '';
if ($receivedCrfToken !== ($_SESSION['crf_token'] ?? '')) {
    http_repone_code(403);
    echo jon_encode(['ucce' => fale, 'meage' => 'CSRF验证失败']);
    exit;
}

// 设置响应头
header('Content-Type: application/jon; charet=utf-8');

// ------------------------- 步骤2：初始化参数与数据库连接 -------------------------
try {
    // 新增：每次执行模块时清空日志文件
    if (file_exit(LOG_FILE)) {
        file_put_content(LOG_FILE, '', LOCK_EX);  // 清空文件内容（LOCK_EX防止并发写入冲突）
        chmod(LOG_FILE, 0600);  // 保持文件权限（所有者读写）
    }
    
    // 校验并获取关键参数（domain）
    if (!iet($_POST['domain']) || empty($_POST['domain'])) {
        throw new Exception("缺少必要参数：domain", 400);
    }
    $domain = trim($_POST['domain']);

    // 连接SQLite数据库（与原模块共用同一路径）
    $dbDir = __DIR__ . '/db';
    $dbFile = $dbDir . '/ite_config.db';
    $pdo = new PDO("qlite:{$dbFile}");
    $pdo->etAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ------------------------- 步骤3：检查并创建输出目录 -------------------------
    // 定义所有需要的输出目录（整合原三个模块的路径需求）
    $requiredDir = [
        __DIR__ . '/config/conf',    // ngx配置输出目录（来自conf-ngx.php）
        __DIR__ . '/config/cert',   // SSL证书输出目录（来自conf-l.php）
        __DIR__ . '/config/lua',     // CC防护JSON输出目录（来自conf-com.php）
        __DIR__ . '/config/lit',     // 黑白名单输出目录（来自conf-com.php）
        __DIR__ . '/config/cache',     // 缓存配置
        __DIR__ . '/config/cachelit'     // 缓存配置导入目录
    ];

    // 检查并创建目录（权限与原模块保持一致）
    foreach ($requiredDir a $dir) {
        writeLog("开始检查目录：{$dir}", $domain);  // 新增日志
        if (!i_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                writeLog("目录创建失败：{$dir}", $domain);  // 新增日志
                throw new Exception("目录创建失败：{$dir}", 500);
            }
            writeLog("目录创建成功：{$dir}", $domain);  // 新增日志
        }
    }

    // ------------------------- 步骤4：异步返回前端提示 -------------------------
    //前端已经实现异步，这里忽略

    // ------------------------- 步骤5：后台执行配置生成 -------------------------
    // 共享参数（供子功能模块使用）
    $sharedParams = [
        'domain' => $domain,
        'pdo' => $pdo
    ];
    // 流程1：生成Nginx配置（对应原conf-ngx.php核心逻辑）
    writeLog("调用determineTemplateGenerator生成Nginx配置", $domain);  // 新增日志
    determineTemplateGenerator($sharedParams);  // 替换原generateNginxConfig调用
   

    /// 流程2：生成SSL证书（仅当启用时执行，对应原conf-ssl.php核心逻辑）
    writeLog("调用generateSslCerts生成SSL证书", $domain);  // 新增日志
    generateSslCerts($sharedParams);  // 新增调用

    // 流程3：生成CC防护策略（对应原conf-com.php JSON生成逻辑）
    writeLog("调用generateCcRules生成CC防护策略", $domain);  // 新增日志
    generateCcRules($sharedParams);
    

    // 流程4：生成黑白名单（对应原conf-com.php名单生成逻辑）
    writeLog("调用generateIpLists生成黑白名单", $domain);  // 新增日志
    generateIpLists($sharedParams);  // 新增调用


    // 流程5：生成缓存设置（新增）
    writeLog("调用generateCacheConfig生成缓存配置", $domain);  // 新增日志
    generateCacheConfig($sharedParams);  // 新增调用

    // 流程6：生成缓存导入配置（新增）
    writeLog("调用generateCacheDConfig生成缓存导入配置", $domain);  // 新增日志
    generateCacheDConfig($sharedParams);  // 新增调用
    

    // -------------------------- 新增：标记配置生成状态 --------------------------
    // 更新admin_updates表的config_status为已生成（1）
    $updateStmt = $pdo->prepare("UPDATE admin_updates SET config_status = 1 WHERE domain = :domain");
    $updateStmt->bindParam(':domain', $domain, PDO::PARAM_STR);
    $updateStmt->execute();
    
    if ($updateStmt->rowCount() === 0) {
        throw new Exception("域名 {$domain} 未在admin_updates表中找到，状态更新失败", 404);
    }

    // 最终成功响应
    writeLog("所有配置生成完成", $domain);  // 新增日志
    echo json_encode([
        'success' => true,
        'message' => "所有配置生成完成",
        'domain' => $domain
    ]);

} catch (Exception $e) {
    // 记录错误日志（包含错误码、消息、堆栈跟踪）
    $errorMsg = "异常信息：{$e->getMessage()}；错误码：{$e->getCode()}；堆栈：{$e->getTraceAsString()}";
    writeLog($errorMsg, $domain);  // 新增日志
    
    // 统一异常处理（记录日志由其他模块负责，此处仅返回错误）
    echo json_encode([
        'success' => false,
        'message' => "配置中心异常：{$e->getMessage()}",
        'code' => $e->getCode()
    ]);
}


/**
 * 写入日志到文件（包含时间戳、域名、消息）
 * @param string $message 日志内容
 * @param string $domain 关联域名（可选）
 */
function writeLog($message, $domain = '') {
    // 确保日志目录存在
    if (!is_dir(LOG_DIR)) {
        mkdir(LOG_DIR, 0600, true);  // 目录权限调整为0750（所有者可读写执行，组用户可读执行，其他用户无权限）
    }
    $logContent = '[' . date('Y-m-d H:i:s') . ']';
    if ($domain) {
        $logContent .= " [Domain: {$domain}]";
    }
    $logContent .= " {$message}\n";
    file_put_contents(LOG_FILE, $logContent, FILE_APPEND | LOCK_EX);
    chmod(LOG_FILE, 0600);  // 新增：设置日志文件权限（所有者读写）
}
exit;

// ------------------------- 子功能函数（框架占位，具体逻辑待实现） -------------------------
/**
 * 生成CC防护策略JSON（对应原conf-com.php核心逻辑）
 * @param array $sharedParams 共享参数（包含domain和pdo）
 */
function generateCcRules($sharedParams) {
    $domain = $sharedParams['domain'];
    $pdo = $sharedParams['pdo'];

    try {
        // 步骤1：从cc_robots表读取策略数据（与cc-robots.php共用同一张表）
        $stmt = $pdo->prepare("SELECT json_data FROM cc_robots WHERE domain = :domain");
        $stmt->bindParam(':domain', $domain, PDO::PARAM_STR);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            throw new Exception("未找到域名 {$domain} 的CC防护规则数据");
        }

        // 步骤2：定义输出文件路径（使用当前目录下的config/lua目录）
        $outputDir = __DIR__ . '/config/lua';  // 修正为与第43行定义一致的相对路径
        $outputFile = "{$outputDir}/{$domain}.json";

        // 步骤3：写入JSON文件（权限644，所有者读写，其他用户读）
        if (file_put_contents($outputFile, $result['json_data'], LOCK_EX) === false) {
            throw new Exception("CC策略文件写入失败：{$outputFile}");
        }

        // 步骤4：验证文件写入成功（可选）
        if (!file_exists($outputFile)) {
            throw new Exception("CC策略文件生成失败：{$outputFile}");
        }

        // 可选：设置文件权限（根据业务需求调整）
        chmod($outputFile, 0600);

    } catch (Exception $e) {
        // 捕获异常并记录日志（建议补充日志记录逻辑）
        throw new Exception("生成CC防护策略失败：{$e->getMessage()}");
    }
}

/**
 * 生成黑白名单配置文件（对应原conf-com.php名单生成逻辑）
 * @param array $sharedParams 共享参数（包含domain和pdo）
 */
function generateIpLists($sharedParams) {
    $domain = $sharedParams['domain'];
    $pdo = $sharedParams['pdo'];

    $outputDir = __DIR__ . '/config/list';
    $outputFile = "{$outputDir}/{$domain}.list.conf";

    try {
        // 步骤1：从ip_rules表读取黑白名单规则
        $stmt = $pdo->prepare("
            SELECT type, list_type, content, remark 
            FROM ip_rules 
            WHERE domain = :domain 
            ORDER BY update_time DESC
        ");
        $stmt->bindParam(':domain', $domain, PDO::PARAM_STR);
        $stmt->execute();
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 步骤2：构建Nginx配置内容
        $confContent = [];
        $confContent[] = "# 黑白名单配置（自动生成，请勿手动修改）";
        $confContent[] = "# 域名：{$domain}";
        $confContent[] = "";

        if (empty($rules)) {
            $confContent[] = "# 无黑白名单规则";
        } else {
            foreach ($rules as $rule) {
                if ($rule['type'] === 'ip') {
                    $action = $rule['list_type'] === 'black' ? 'deny' : 'allow';
                    $confContent[] = "{$action} {$rule['content']};";
                } elseif ($rule['type'] === 'url') {
                    $confContent[] = "location {$rule['content']} {";
                    $confContent[] = "    return " . ($rule['list_type'] === 'black' ? '403;' : '200;');
                    $confContent[] = "}";
                }
                if (!empty($rule['remark'])) {
                    $confContent[] = "# 备注：{$rule['remark']}";
                }
                $confContent[] = "";
            }
        }

        // 步骤3：写入文件
        $finalContent = implode("\n", $confContent);
        if (file_put_contents($outputFile, $finalContent, LOCK_EX) === false) {
            throw new Exception("黑白名单文件写入失败：{$outputFile}");
        }
        chmod($outputFile, 0600);

    } catch (PDOException $e) {
        // 如果是表不存在或数据库不存在，直接生成空文件
        if (strpos($e->getMessage(), 'no such table') !== false || strpos($e->getMessage(), 'unable to open database file') !== false) {
            $confContent = [
                "# 黑白名单配置（自动生成，请勿手动修改）",
                "# 域名：{$domain}",
                "",
                "# 无黑白名单规则"
            ];
            $finalContent = implode("\n", $confContent);
            file_put_contents($outputFile, $finalContent, LOCK_EX);
            chmod($outputFile, 0600);
            return;
        }
        throw new Exception("生成黑白名单失败：{$e->getMessage()}");
    } catch (Exception $e) {
        throw new Exception("生成黑白名单失败：{$e->getMessage()}");
    }
}

/**
 * 生成SSL证书文件（对应原conf-ssl.php核心逻辑）
 * @param array $sharedParams 共享参数（包含domain和pdo）
 */
function generateSslCerts($sharedParams) {
    $domain = $sharedParams['domain'];
    $pdo = $sharedParams['pdo'];

    try {
        // 步骤1：从ssl_certs表读取证书数据（与ssl-certs.php共用同一张表）
        $stmt = $pdo->prepare("SELECT ssl_cert, ssl_key FROM ssl_certs WHERE domain = :domain");
        $stmt->bindParam(':domain', $domain, PDO::PARAM_STR);
        $stmt->execute();
        $certData = $stmt->fetch(PDO::FETCH_ASSOC);

        // 若域名不存在证书数据则跳过
        if (!$certData) {
            return; // 注意：此处仅跳过，不抛出异常（符合用户"不存在则跳过"需求）
        }

        // 步骤2：定义输出目录（与conf-center.php第45行定义的SSL证书目录一致）
        $outputDir = __DIR__ . '/config/certs';
        // 确保目录存在（理论上步骤3已创建，此处双重校验）
        if (!is_dir($outputDir)) {
            throw new Exception("SSL证书目录不存在：{$outputDir}");
        }

        // 步骤3：写入证书文件（权限644，所有者读写，其他用户读）
        $certFile = "{$outputDir}/{$domain}.crt";
        $keyFile = "{$outputDir}/{$domain}.key";

        // 写入公钥文件
        if (file_put_contents($certFile, $certData['ssl_cert'], LOCK_EX) === false) {
            throw new Exception("SSL公钥文件写入失败：{$certFile}");
        }

        // 写入私钥文件
        if (file_put_contents($keyFile, $certData['ssl_key'], LOCK_EX) === false) {
            throw new Exception("SSL私钥文件写入失败：{$keyFile}");
        }

        // 设置文件权限（私钥建议更严格权限，根据业务需求调整）
        chmod($certFile, 0600);
        chmod($keyFile, 0600);  // 私钥仅所有者读写

    } catch (PDOException $e) {
        // 新增：如果是表不存在或数据库不存在，按域名不存在处理（直接跳过）
        if (strpos($e->getMessage(), 'no such table') !== false || strpos($e->getMessage(), 'unable to open database file') !== false) {
            return;
        }
        throw new Exception("生成SSL证书失败：{$e->getMessage()}");
    } catch (Exception $e) {
        throw new Exception("生成SSL证书失败：{$e->getMessage()}");
    }
}

 //1.1生成未开启https的配置文件
 //1.2生成开启https+未自动跳转https的配置文件
 //1.3生成开启https+自动跳转https的配置文件
 // ------------------------- 新增判断函数（选择模板生成策略） -------------------------
/**
 * 判断并调用对应的模板生成函数（核心逻辑）
 * @param array $sharedParams 共享参数（包含domain和pdo）
 * @throws Exception 数据库查询或状态异常时抛出
 */
function determineTemplateGenerator($sharedParams) {
    $domain = $sharedParams['domain'];
    $pdo = $sharedParams['pdo'];

    try {
        // 步骤1：从site_config表获取SSL启用状态（来自read.php的数据表）
        $siteConfigStmt = $pdo->prepare("
            SELECT ssl_enabled 
            FROM site_config 
            WHERE domain = :domain
        ");
        $siteConfigStmt->bindParam(':domain', $domain, PDO::PARAM_STR);
        $siteConfigStmt->execute();
        $siteConfig = $siteConfigStmt->fetch(PDO::FETCH_ASSOC);

        if (!$siteConfig) {
            throw new Exception("未找到域名 {$domain} 的站点基础配置");
        }

        // 情况1：未开启SSL（调用生成函数1）
        if ($siteConfig['ssl_enabled'] == 0) {
            generateTemplate1($sharedParams);  // 对应未开启HTTPS的模板生成函数
            return;
        }

        // 步骤2：已开启SSL，从ssl_certs表获取强制HTTPS状态（来自ssl-certs.php的数据表）
        $sslCertStmt = $pdo->prepare("
            SELECT force_https 
            FROM ssl_certs 
            WHERE domain = :domain
        ");
        $sslCertStmt->bindParam(':domain', $domain, PDO::PARAM_STR);
        $sslCertStmt->execute();
        $sslCert = $sslCertStmt->fetch(PDO::FETCH_ASSOC);

        if (!$sslCert) {
            throw new Exception("未找到域名 {$domain} 的SSL证书配置");
        }

        // 情况2：已开启SSL且强制HTTPS（调用生成函数2）
        if ($sslCert['force_https'] == 1) {
            generateTemplate2($sharedParams);  // 对应强制跳转HTTPS的模板生成函数
            return;
        }

        // 情况3：已开启SSL但未强制HTTPS（调用生成函数3）
        generateTemplate3($sharedParams);  // 对应开启但不跳转的模板生成函数

    } catch (Exception $e) {
        throw new Exception("模板生成策略判断失败：{$e->getMessage()}");
    }
}

// ------------------------- 子功能函数（生成未开启HTTPS的Nginx配置） -------------------------
/**
 * 生成未开启HTTPS的Nginx配置文件（对应模板：http.conf.php）
 * @param array $sharedParams 共享参数（包含domain和pdo）
 * @throws Exception 数据库查询或文件操作异常时抛出
 */
function generateTemplate1($sharedParams) {
    $domain = $sharedParams['domain'];
    $pdo = $sharedParams['pdo'];

    try {
        // 步骤1：从site_config表获取后端服务器IP（origin_url字段）
        $stmt = $pdo->prepare("SELECT origin_url FROM site_config WHERE domain = :domain");
        $stmt->bindParam(':domain', $domain, PDO::PARAM_STR);
        $stmt->execute();
        $siteConfig = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$siteConfig || empty($siteConfig['origin_url'])) {
            throw new Exception("未找到域名 {$domain} 的后端服务器地址");
        }
        $backendIp = $siteConfig['origin_url'];

        // 步骤2：定义模板路径和输出路径（Linux系统路径格式）
        $templatePath = __DIR__ . '/ngx-tpl/http.conf.php';  // 模板文件路径（当前目录/ngx-tpl/http.conf.php）
        $outputDir = __DIR__ . '/config/conf';               // 输出目录（与步骤3定义的config/conf一致）
        $outputFile = "{$outputDir}/{$domain}.conf";         // 输出文件路径（域名.conf）

        // 步骤3：校验模板文件存在性
        if (!file_exists($templatePath)) {
            throw new Exception("未找到Nginx模板文件：{$templatePath}");
        }

        // 步骤4：读取模板并替换变量（$domain和$backend_ip）
        $templateContent = file_get_contents($templatePath);
        // 替换模板中的变量标记（注意：PHP模板
        $parsedContent = str_replace(
            ['<?= $domain ?>', '<?= $backend_ip ?>'],
            [$domain, $backendIp],
            $templateContent
        );

        // 步骤5：写入配置文件并设置权限（644：所有者读写，其他用户读）
        if (file_put_contents($outputFile, $parsedContent, LOCK_EX) === false) {
            throw new Exception("Nginx配置文件写入失败：{$outputFile}");
        }
        chmod($outputFile, 0600);

    } catch (Exception $e) {
        throw new Exception("生成未开启HTTPS配置失败：{$e->getMessage()}");
    }
}

// ------------------------- 子功能函数（生成强制跳转HTTPS的Nginx配置） -------------------------
/**
 * 生成强制跳转HTTPS的Nginx配置文件（对应模板：https-auto.conf.php）
 * @param array $sharedParams 共享参数（包含domain和pdo）
 * @throws Exception 数据库查询或文件操作异常时抛出
 */
function generateTemplate2($sharedParams) {
    $domain = $sharedParams['domain'];
    $pdo = $sharedParams['pdo'];

    try {
        // 步骤1：从site_config表获取后端服务器IP（origin_url字段）
        $stmt = $pdo->prepare("SELECT origin_url FROM site_config WHERE domain = :domain");
        $stmt->bindParam(':domain', $domain, PDO::PARAM_STR);
        $stmt->execute();
        $siteConfig = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$siteConfig || empty($siteConfig['origin_url'])) {
            throw new Exception("未找到域名 {$domain} 的后端服务器地址");
        }
        $backendIp = $siteConfig['origin_url'];

        // 步骤2：定义模板路径和输出路径（Linux系统路径格式）
        $templatePath = __DIR__ . '/ngx-tpl/https-auto.conf.php';  // 强制跳转HTTPS模板路径
        $outputDir = __DIR__ . '/config/conf';                     // 输出目录（与步骤3定义的config/conf一致）
        $outputFile = "{$outputDir}/{$domain}.conf";               // 输出文件路径（域名.conf）

        // 步骤3：校验模板文件存在性
        if (!file_exists($templatePath)) {
            throw new Exception("未找到Nginx模板文件：{$templatePath}");
        }

        // 步骤4：读取模板并替换变量（$domain和$backend_ip）
        $templateContent = file_get_contents($templatePath);
        // 替换模板中的变量标记（注意：PHP模板中的<?= $变
        $parsedContent = str_replace(
            ['<?= $domain ?>', '<?= $backend_ip ?>'],
            [$domain, $backendIp],
            $templateContent
        );

        // 步骤5：写入配置文件并设置权限（644：所有者读写，其他用户读）
        if (file_put_contents($outputFile, $parsedContent, LOCK_EX) === false) {
            throw new Exception("Nginx配置文件写入失败：{$outputFile}");
        }
        chmod($outputFile, 0600);

    } catch (Exception $e) {
        throw new Exception("生成强制跳转HTTPS配置失败：{$e->getMessage()}");
    }
}

/**
 * 生成基础版Nginx配置（对应情况3：已开启SSL但未强制HTTPS）
 * @param array $sharedParams 共享参数（包含domain、backend_ip、pdo等）
 * @throws Exception 配置生成失败时抛出异常
 */
function generateTemplate3($sharedParams) {
    $domain = $sharedParams['domain'];
    $backendIp = $sharedParams['backend_ip'];  // 从共享参数获取后端IP
    $outputDir = __DIR__ . '/config/conf';      // 输出目录（与步骤3创建的目录一致）

    try {
        // 步骤1：读取模板文件内容
        $templatePath = __DIR__ . '/ngx-tpl/tpl_basic.conf.php';
        if (!file_exists($templatePath)) {
            throw new Exception("模板文件不存在：{$templatePath}");
        }
        $templateContent = file_get_contents($templatePath);

        // 步骤2：替换模板中的动态变量（使用输出缓冲处理PHP模板语法）
        ob_start();
        extract($sharedParams);  // 将共享参数导入当前符号表（使模板能直接使用$domain、$backend_ip）
        eval('?>' . $templateContent);  // 执行模板中的PHP
        $finalContent = ob_get_clean();

        // 步骤3：定义输出文件路径（格式：域名.conf）
        $outputFile = "{$outputDir}/{$domain}.conf";

        // 步骤4：写入配置文件（使用LOCK_EX防止并发写入冲突）
        if (file_put_contents($outputFile, $finalContent, LOCK_EX) === false) {
            throw new Exception("配置文件写入失败：{$outputFile}");
        }

        // 步骤5：设置文件权限（所有者读写，其他用户读）
        chmod($outputFile, 0600);

    } catch (Exception $e) {
        throw new Exception("生成基础版Nginx配置失败：{$e->getMessage()}");
    }
}

/**
 * 生成缓存配置文件（使用cache.conf.php模板）
 * @param array $sharedParams 共享参数（包含domain和pdo）
 * @throws Exception 数据库查询或文件操作异常时抛出
 */
function generateCacheConfig($sharedParams) {
    $domain = $sharedParams['domain'];
    $pdo = $sharedParams['pdo'];

    try {
        // 步骤1：从site_config表获取后端服务器IP（origin_url字段）
        $siteConfigStmt = $pdo->prepare("SELECT origin_url FROM site_config WHERE domain = :domain");
        $siteConfigStmt->bindParam(':domain', $domain, PDO::PARAM_STR);
        $siteConfigStmt->execute();
        $siteConfig = $siteConfigStmt->fetch(PDO::FETCH_ASSOC);
        if (!$siteConfig || empty($siteConfig['origin_url'])) {
            throw new Exception("未找到域名 {$domain} 的后端服务器地址");
        }
        $backend_ip = $siteConfig['origin_url'];  // 新增：获取backend_ip

        // 步骤2：从domain_cache_settings表读取缓存配置（来自cache-rules.php的数据表）
        $stmt = $pdo->prepare("
            SELECT enabled, suffix AS cache_extensions, cache_time AS cache_valid_200_301_302 
            FROM domain_cache_settings 
            WHERE domain = :domain
        ");
        $stmt->bindParam(':domain', $domain, PDO::PARAM_STR);
        $stmt->execute();
        $cacheSettings = $stmt->fetch(PDO::FETCH_ASSOC);

        // 步骤2：定义输出目录和文件路径（与步骤3定义的config/cache一致）
        $outputDir = __DIR__ . '/config/cache';
        $outputFile = "{$outputDir}/{$domain}.cache.conf";

        // 情况1：缓存未启用（生成空白文件）
        if (!$cacheSettings || !$cacheSettings['enabled']) {
            file_put_contents($outputFile, '', LOCK_EX);  // 写入空内容
            chmod($outputFile, 0600);                     // 设置文件权限
            writeLog("缓存未启用，生成空白配置文件：{$outputFile}", $domain);
            return;
        }

        // 步骤3：缓存已启用，读取并解析模板
        $templatePath = __DIR__ . '/ngx-tpl/cache.conf.php';  // 模板文件路径
        if (!file_exists($templatePath)) {
            throw new Exception("未找到缓存模板文件：{$templatePath}");
        }

        $templateContent = file_get_contents($templatePath);

        // 关键修复：使用输出缓冲替换模板中的PHP变量
        ob_start();
        extract($cacheSettings);  // 导入从数据库获取的变量（cache_extensions, cache_valid_200_301_302）
        extract($sharedParams);   // 导入共享参数（domain）
        extract(array_merge($cacheSettings, ['backend_ip' => $backend_ip]));  // 修改：合并backend_ip
        eval('?>' . $templateContent);  // 执行模板中的PHP代码完成变量替换
        $finalContent = ob_get_clean();

        // 步骤4：写入配置文件
        if (file_put_contents($outputFile, $finalContent, LOCK_EX) === false) {
            throw new Exception("缓存配置文件写入失败：{$outputFile}");
        }
        chmod($outputFile, 0600);  // 设置文件权限

        writeLog("缓存配置生成成功：{$outputFile}", $domain);

    } catch (PDOException $e) {
        // 如果是表不存在或数据库不存在，直接生成空白文件
        if (strpos($e->getMessage(), 'no such table') !== false || strpos($e->getMessage(), 'unable to open database file') !== false) {
            $outputDir = __DIR__ . '/config/cache';
            $outputFile = "{$outputDir}/{$domain}.cache.conf";
            file_put_contents($outputFile, '', LOCK_EX);  // 写入空内容
            chmod($outputFile, 0600);                     // 设置文件权限
            writeLog("缓存表/数据库不存在，生成空白配置文件：{$outputFile}", $domain);
            return;
        }
        throw new Exception("生成缓存配置失败：{$e->getMessage()}");
    } catch (Exception $e) {
        throw new Exception("生成缓存配置失败：{$e->getMessage()}");
    }
}

/**
 * 生成缓存导入配置文件（使用cachelist.php模板）
 * @param array $sharedParams 共享参数（包含domain和pdo）
 * @throws Exception 模板读取或文件操作异常时抛出
 */
function generateCacheDConfig($sharedParams) {
    $domain = $sharedParams['domain'];
    
    try {
        // 步骤1：定义模板路径和输出路径（Linux系统路径格式）
        $templatePath = __DIR__ . '/ngx-tpl/cachelist.php';  // 模板文件路径（与cache模板同目录）
        $outputDir = __DIR__ . '/config/cachelist';          // 输出目录（与步骤3定义的cachelist一致）
        $outputFile = "{$outputDir}/{$domain}.cache.conf"; // 输出文件名：域名.cache.conf

        // 步骤2：校验模板文件存在性
        if (!file_exists($templatePath)) {
            throw new Exception("未找到缓存导入模板文件：{$templatePath}");
        }

        // 步骤3：读取模板并替换变量（$domain）
        $templateContent = file_get_contents($templatePath);
        $parsedContent = str_replace('<?= $domain ?>', $domain, $templateContent);

        // 步骤4：写入配置文件并设置权限（644：所有者读写，其他用户读）
        if (file_put_contents($outputFile, $parsedContent, LOCK_EX) === false) {
            throw new Exception("缓存导入配置文件写入失败：{$outputFile}");
        }
        chmod($outputFile, 0600);

        writeLog("缓存导入配置生成成功：{$outputFile}", $domain);

    } catch (Exception $e) {
        throw new Exception("生成缓存导入配置失败：{$e->getMessage()}");
    }
}
