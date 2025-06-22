<?php
header('Content-Type: application/json');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('max_execution_time', 300); // 5分钟
ini_set('memory_limit', '256M');    // 256MB

// 定义函数
function clear_dir($dir) {
    if (!is_dir($dir)) return;
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
}

function write_update_log($message) {
    $logFile = __DIR__ . '/update/update.log';
    $date = date('Y-m-d H:i:s');
    $logMsg = "[$date] $message" . PHP_EOL;
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

    // ========== 超简化更新流程 ==========
    
    // 1. 先定义所有路径 - 顺序很重要!
    $rootDir = __DIR__; // 网站根目录
    $updateDir = $rootDir . '/update';
    $backupDir = $updateDir . '/backup';
    $originalBackupDir = $backupDir; // 保存原始备份目录
    $newDir = $updateDir . '/new';
    $cacheDir = $newDir . '/cache';
    $targetVersionFile = $updateDir . '/version.json';

    // 2. 清空backup文件夹 - 只保留最新备份
    write_update_log("清空backup文件夹...");
    clear_dir($backupDir);
    if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true)) {
        throw new Exception('/update/backup 文件夹不存在且创建失败');
    }
    
    // 3. 检查更新包
    if (!is_dir($newDir)) {
        throw new Exception('/update/new 文件夹不存在');
    }
    
    $zipFiles = glob($newDir . '/*.zip');
    if (!$zipFiles || count($zipFiles) === 0) {
        throw new Exception('不存在更新包（zip文件）');
    }
    if (count($zipFiles) > 1) {
        throw new Exception('只能存在一个更新包（zip文件），请检查后重试');
    }
    $zipFile = $zipFiles[0];
    
    // 4. 准备和清空缓存目录
    if (!is_dir($cacheDir)) {
        if (!mkdir($cacheDir, 0755, true)) {
            throw new Exception('cache文件夹创建失败');
        }
    } else {
        clear_dir($cacheDir);
    }
    
    // 5. 解压ZIP包到缓存目录
    // 检查服务器是否支持ZIP扩展
    if (!extension_loaded('zip')) {
        throw new Exception('服务器未启用ZIP扩展，无法解压更新包，请联系服务器管理员启用ZIP扩展');
    }

    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== TRUE) {
        throw new Exception('zip文件解压失败');
    }

    // 添加这两行关键代码
    $zip->extractTo($cacheDir);
    $zip->close();

    write_update_log("已解压更新包到缓存目录");

    // 解压后添加
    $importantFiles = ['index.php', 'update/version.json', 'sys-update.php'];
    write_update_log("检查关键文件:");
    foreach ($importantFiles as $file) {
        $filePath = $cacheDir . '/' . $file;
        write_update_log(" - $file: " . (file_exists($filePath) ? '存在' : '不存在'));
    }
    
    // 6. 定义需要保留的关键文件
    $preserveFiles = [
        // 配置文件
        '/auth/data/user_data.json',
        // 数据库文件
        '/api/db/logs.db',
        '/api/db/nodes.db',
        '/data/db/site_config.db',
        // 更新脚本自身
        '/sys-update.php',
    ];
    
    // 7. 备份这些关键文件
    write_update_log("备份关键文件...");
    foreach ($preserveFiles as $file) {
        $sourcePath = $rootDir . $file;
        if (file_exists($sourcePath)) {
            // 创建扁平结构文件名
            $flatFilename = str_replace(['/', '\\'], '_', ltrim($file, '/\\'));
            $backupPath = $originalBackupDir . '/' . $flatFilename;
            
            // 直接复制到备份根目录，并验证备份是否成功
            if (!copy($sourcePath, $backupPath)) {
                throw new Exception("备份关键文件失败: $file，请检查目录权限");
            }
            
            // 验证备份文件是否完整
            if (filesize($sourcePath) !== filesize($backupPath)) {
                throw new Exception("备份文件大小不匹配: $file，备份可能已损坏");
            }
            
            write_update_log("已备份: $file");
        }
    }
    
    // 8. 复制更新包中的所有文件到网站根目录
    write_update_log("开始复制新文件...");
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cacheDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($files as $file) {
        $relativePath = substr($file->getPathname(), strlen($cacheDir));
        $targetPath = $rootDir . $relativePath;
        
        if ($file->isDir()) {
            if (!is_dir($targetPath)) {
                mkdir($targetPath, 0755, true);
            }
        } else {
            // 检查是否是需要保留的文件
            $shouldSkip = false;
            foreach ($preserveFiles as $preserveFile) {
                if (strcasecmp($relativePath, $preserveFile) === 0) {
                    $shouldSkip = true;
                    break;
                }
            }
            
            if (!$shouldSkip) {
                $targetDir = dirname($targetPath);
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }
                copy($file->getPathname(), $targetPath);
            }
        }
    }
    
    // 9. 恢复备份的关键文件
    write_update_log("恢复关键文件...");
    foreach ($preserveFiles as $file) {
        // 使用相同的扁平文件名规则
        $flatFilename = str_replace(['/', '\\'], '_', ltrim($file, '/\\'));
        $backupPath = $originalBackupDir . '/' . $flatFilename;
        $targetPath = $rootDir . $file;
        
        if (file_exists($backupPath)) {
            // 确保目标目录存在
            $targetDir = dirname($targetPath);
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            
            copy($backupPath, $targetPath);
            write_update_log("已恢复: $file");
        }
    }
    
    // 10. 更新版本号
    $newVersionFile = $cacheDir . '/update/version.json'; // 首选路径
    $alternateVersionFile = $cacheDir . '/version.json';  // 备选路径

    write_update_log("检查版本文件...");
    // 如果首选路径不存在，尝试备选路径
    if (!file_exists($newVersionFile) && file_exists($alternateVersionFile)) {
        $newVersionFile = $alternateVersionFile;
        write_update_log("使用备选版本文件路径");
    }

    if (file_exists($newVersionFile) && file_exists($targetVersionFile)) {
        $newVersionData = json_decode(file_get_contents($newVersionFile), true) ?? [];
        $oldVersionData = json_decode(file_get_contents($targetVersionFile), true) ?? [];
        $newVersion = $newVersionData['version'] ?? '';
        $oldVersion = $oldVersionData['version'] ?? '';
        
        // 确保存在有效版本号
        if (!empty($newVersion)) {
            $oldVersionData['version'] = $newVersion;
            file_put_contents($targetVersionFile, json_encode($oldVersionData, JSON_PRETTY_PRINT));
            write_update_log("版本已更新: $oldVersion → $newVersion");
        } else {
            write_update_log("警告: 新版本信息无效");
        }
    } else {
        write_update_log("警告: 未找到版本文件");
    }
    
    // 11. 完成更新
    write_update_log("更新完成!");
    echo json_encode([
        'success' => true,
        'message' => '系统更新成功'
    ]);

} catch (Exception $e) {
    write_update_log("更新失败: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;