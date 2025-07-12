<?php
// SSL证书上传接口（将证书保存到/config/certs文件夹）
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

// 检查证书文件夹是否存在，不存在则创建
if (!is_dir($certsDir)) {
    if (!mkdir($certsDir, 0755, true)) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => '无法创建证书文件夹'
        ]);
        exit;
    }
}

// 检查必要参数
if (!isset($_POST['ssl_cert']) || !isset($_POST['ssl_key'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少必要参数：证书和私钥']);
    exit;
}

// 获取证书和私钥内容
$sslCert = trim($_POST['ssl_cert']);
$sslKey = trim($_POST['ssl_key']);

// 调整：仅检查证书包含PEM标记（不强制解析内容） 
if (!str_contains($sslCert, '-----BEGIN CERTIFICATE-----') || 
    !str_contains($sslCert, '-----END CERTIFICATE-----')) { 
    http_response_code(400); 
    echo json_encode(['success' => false, 'message' => '证书格式不完整（缺少PEM标记）']); 
    exit; 
} 

// 调整：仅检查私钥包含PEM标记（不强制解析类型） 
if (!str_contains($sslKey, '-----BEGIN ') || 
    !str_contains($sslKey, '-----END ')) { 
    http_response_code(400); 
    echo json_encode(['success' => false, 'message' => '私钥格式不完整（缺少PEM标记）']); 
    exit; 
} 

// 解析证书内容获取域名和有效期
try {
    $certInfo = openssl_x509_parse($sslCert);
    if (!$certInfo) {
        throw new Exception('证书无法解析');
    }
    
    // 提取域名（先尝试从CN中提取）
    $domain = null;
    
    // 方法1：从subject的CN字段提取
    if (isset($certInfo['subject']['CN'])) {
        $domain = $certInfo['subject']['CN'];
        // 如果是通配符域名，去掉星号和点
        if (strpos($domain, '*.') === 0) {
            $domain = substr($domain, 2);
        }
    } 
    // 方法2：使用正则表达式从证书中提取CN（备用方法）
    elseif (preg_match('/CN=([^,]+)/', $sslCert, $matches)) {
        $domain = trim($matches[1]);
        // 如果是通配符域名，去掉星号和点
        if (strpos($domain, '*.') === 0) {
            $domain = substr($domain, 2);
        }
    }
    // 方法3：尝试从SAN扩展中提取第一个DNS名称
    elseif (isset($certInfo['extensions']['subjectAltName'])) {
        if (preg_match('/DNS:([^,\s]+)/', $certInfo['extensions']['subjectAltName'], $matches)) {
            $domain = $matches[1];
            // 如果是通配符域名，去掉星号和点
            if (strpos($domain, '*.') === 0) {
                $domain = substr($domain, 2);
            }
        }
    }
    
    // 如果无法提取域名，返回错误
    if (!$domain) {
        throw new Exception('无法从证书中提取域名');
    }
    
    // 检查域名格式是否合法（增加长度限制）
    if (strlen($domain) > 253 || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-\.]+[a-zA-Z0-9]$/', $domain)) {
        throw new Exception('证书中的域名格式不合法或长度超过限制');
    }
    
    // 检查每个标签（点之间的部分）长度是否超过63个字符
    $labels = explode('.', $domain);
    foreach ($labels as $label) {
        if (strlen($label) > 63) {
            throw new Exception('证书中的域名标签长度超过限制（最大63个字符）');
        }
    }
    
    // 提取到期时间
    if (!isset($certInfo['validTo_time_t'])) {
        throw new Exception('证书无法解析有效期');
    }
    $expiryTime = date('Y-m-d H:i:s', $certInfo['validTo_time_t']);
    
    // 检查证书是否已过期
    if (time() > $certInfo['validTo_time_t']) {
        throw new Exception('证书已过期，有效期至：' . $expiryTime);
    }
    
    // 保存证书和私钥文件
    if (!file_put_contents($certsDir . '/' . $domain . '.crt', $sslCert)) {
        throw new Exception('保存证书文件失败');
    }
    
    if (!file_put_contents($certsDir . '/' . $domain . '.key', $sslKey)) {
        // 如果私钥保存失败，删除已保存的证书文件
        @unlink($certsDir . '/' . $domain . '.crt');
        throw new Exception('保存私钥文件失败');
    }
    
    // 设置文件权限（确保Web服务器可读）
    chmod($certsDir . '/' . $domain . '.crt', 0644);
    chmod($certsDir . '/' . $domain . '.key', 0644);
    
    // 返回成功信息
    echo json_encode([
        'success' => true,
        'message' => '证书上传成功',
        'domain' => $domain,
        'expiry_time' => $expiryTime
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}