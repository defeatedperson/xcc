<?php
header('Content-Type: application/json');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('max_execution_time', 60); // 1分钟

// 定义日志函数
function write_update_log($message) {
    $logFile = __DIR__ . '/update/update.log';
    $date = date('Y-m-d H:i:s');
    $logMsg = "[$date] [OVER] $message" . PHP_EOL;
    file_put_contents($logFile, $logMsg, FILE_APPEND | LOCK_EX);
}

// 安全检查
try {
    // 认证检查
    define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
    include ROOT_PATH . 'auth.php';

    // 请求方法检查
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => '仅允许POST方法']);
        exit;
    }

    // CSRF令牌校验
    $receivedCsrfToken = $_POST['csrf_token'] ?? '';
    if ($receivedCsrfToken !== $_SESSION['csrf_token']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
        exit;
    }

    // ========== 更新完成后的收尾工作 ==========
    $rootDir = __DIR__; // 网站根目录
    $updateDir = $rootDir . '/update';
    $newDir = $updateDir . '/new';
    $cacheDir = $newDir . '/cache';
    
    write_update_log("开始执行后续更新操作...");
    
    // 1. 检查缓存目录中是否有更新的系统文件
    $newUpdateScriptPath = $cacheDir . '/sys-update.php';
    if (file_exists($newUpdateScriptPath)) {
        // 2. 更新sys-update.php文件
        $currentUpdateScriptPath = $rootDir . '/sys-update.php';
        
        // 确保备份当前版本
        $backupUpdateScriptPath = $updateDir . '/backup/sys-update.php.bak';
        if (!is_dir(dirname($backupUpdateScriptPath))) {
            mkdir(dirname($backupUpdateScriptPath), 0755, true);
        }
        
        if (copy($currentUpdateScriptPath, $backupUpdateScriptPath)) {
            write_update_log("已备份当前sys-update.php");
            
            if (copy($newUpdateScriptPath, $currentUpdateScriptPath)) {
                write_update_log("已更新sys-update.php到最新版本");
            } else {
                throw new Exception("更新sys-update.php失败");
            }
        } else {
            write_update_log("警告: 无法备份当前sys-update.php");
        }
    } else {
        write_update_log("缓存中未找到新版本sys-update.php，跳过更新");
    }
    
    // 3. 清理缓存目录
    function clear_dir($dir) {
        if (!is_dir($dir)) return;
        
        try {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            
            foreach ($files as $fileinfo) {
                if ($fileinfo->isDir()) {
                    rmdir($fileinfo->getRealPath());
                } else {
                    unlink($fileinfo->getRealPath());
                }
            }
        } catch (Exception $e) {
            write_update_log("清理目录异常: " . $e->getMessage());
        }
    }
    
    write_update_log("正在清理缓存目录...");
    clear_dir($cacheDir);
    if (is_dir($cacheDir)) {
        rmdir($cacheDir);
    }
    write_update_log("缓存目录已清理");
    
    // 4. 清理更新包
    $zipFiles = glob($newDir . '/*.zip');
    foreach ($zipFiles as $zipFile) {
        if (unlink($zipFile)) {
            write_update_log("已删除更新包: " . basename($zipFile));
        } else {
            write_update_log("警告: 无法删除更新包: " . basename($zipFile));
        }
    }
    
    // 5. 更新完成
    write_update_log("后续更新操作完成!");
    echo json_encode([
        'success' => true,
        'message' => '系统更新完成，请刷新页面应用最新版本'
    ]);

} catch (Exception $e) {
    write_update_log("后续更新失败: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;