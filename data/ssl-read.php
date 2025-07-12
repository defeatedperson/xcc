<?php
// SSL证书查询接口（搜索/config/certs文件夹中的证书文件）
// 包含公共认证文件（校验登录状态和权限）
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';

// 安全增强：强制仅允许POST方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '仅允许POST方法']);
    exit;
}

// 安全增强：CSRF令牌校验
$receivedCsrfToken = $_POST['csrf_token'] ?? '';
if ($receivedCsrfToken !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
    exit;
}

// 设置时区和响应头
date_default_timezone_set('Asia/Shanghai');
header('Content-Type: application/json; charset=utf-8');

// 证书文件夹路径
$certsDir = __DIR__ . '/config/certs';

// 检查证书文件夹是否存在
if (!is_dir($certsDir)) {
    echo json_encode([
        'success' => false,
        'message' => '证书文件夹不存在'
    ]);
    exit;
}

// 功能1：获取所有存在证书的域名列表
if (!isset($_POST['domain'])) {
    $domains = [];
    $files = scandir($certsDir);
    
    foreach ($files as $file) {
        // 跳过目录和非.crt文件
        if (is_dir($certsDir . '/' . $file) || !preg_match('/(.+)\.crt$/', $file, $matches)) {
            continue;
        }
        
        $domain = $matches[1];
        // 检查是否同时存在.key文件
        if (file_exists($certsDir . '/' . $domain . '.key')) {
            // 尝试解析证书的到期时间
            $expiryTime = '未知';
            try {
                $certContent = file_get_contents($certsDir . '/' . $domain . '.crt');
                if ($certContent) {
                    $certInfo = openssl_x509_parse($certContent);
                    if ($certInfo && isset($certInfo['validTo_time_t'])) {
                        $expiryTime = date('Y-m-d H:i:s', $certInfo['validTo_time_t']);
                    }
                }
            } catch (Exception $e) {
                // 解析失败，保持默认值"未知"
            }
            
            $domains[] = [
                'domain' => $domain,
                'expiry_time' => $expiryTime
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'domains' => $domains,
        'count' => count($domains)
    ]);
    exit;
}

// 功能2：检查指定域名是否存在证书
$domain = trim($_POST['domain']);
if (empty($domain)) {
    echo json_encode([
        'success' => false,
        'message' => '域名参数不能为空'
    ]);
    exit;
}

// 检查域名格式是否合法（增加长度限制）
if (strlen($domain) > 253 || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-\.]+[a-zA-Z0-9]$/', $domain)) {
    echo json_encode([
        'success' => false,
        'message' => '域名格式不合法或长度超过限制'
    ]);
    exit;
}

// 检查每个标签（点之间的部分）长度是否超过63个字符
$labels = explode('.', $domain);
foreach ($labels as $label) {
    if (strlen($label) > 63) {
        echo json_encode([
            'success' => false,
            'message' => '域名标签长度超过限制（最大63个字符）'
        ]);
        exit;
    }
}

// 检查证书文件是否存在
$hasCert = file_exists($certsDir . '/' . $domain . '.crt') && 
           file_exists($certsDir . '/' . $domain . '.key');

// 添加证书有效期信息
$expiryTime = '未知';
if ($hasCert) {
    try {
        $certContent = file_get_contents($certsDir . '/' . $domain . '.crt');
        if ($certContent) {
            $certInfo = openssl_x509_parse($certContent);
            if ($certInfo && isset($certInfo['validTo_time_t'])) {
                $expiryTime = date('Y-m-d H:i:s', $certInfo['validTo_time_t']);
            }
        }
    } catch (Exception $e) {
        // 解析失败，保持默认值"未知"
    }
}

echo json_encode([
    'success' => true,
    'domain' => $domain,
    'has_cert' => $hasCert,
    'expiry_time' => $expiryTime
]);