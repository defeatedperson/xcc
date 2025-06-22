<?php
// 步骤3必须检测 set2.txt 存在，否则跳转回步骤2
$flagFile = __DIR__ . '/set2.txt';
if (!is_file($flagFile)) {
    header("Location: setup2.php");
    exit;
}

// 删除步骤标记文件
@unlink(__DIR__ . '/set1.txt');
@unlink(__DIR__ . '/set2.txt');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>初始化完成 - XCC主控安装</title>
    <style>
        body { background: #f6f8fa; font-family: "Segoe UI", "PingFang SC", "Hiragino Sans GB", "Arial", sans-serif; }
        .setup-container { max-width: 420px; margin: 80px auto 0 auto; background: #fff; border-radius: 10px; box-shadow: 0 2px 12px rgba(0,0,0,0.07); padding: 32px 32px 24px 32px; text-align: center; }
        .setup-title { font-size: 22px; color: #2563eb; margin-bottom: 18px; }
        .setup-desc { color: #444; font-size: 15px; margin-bottom: 32px; line-height: 1.7; }
        .setup-btn { background: #2563eb; color: #fff; border: none; border-radius: 5px; font-size: 16px; padding: 10px 38px; cursor: pointer; transition: background 0.2s; }
        .setup-btn:hover { background: #1d4fd7; }
        /* 新增：协议与支持区域样式 */
        .support-section {
            margin-top: 24px;
        }
        .license-text {
            color: #666;
            font-size: 14px;
            margin-bottom: 12px;
        }
        .license-link {
            color: #2563eb;
            text-decoration: none;
        }
        .license-link:hover {
            text-decoration: underline;
        }
        .support-buttons {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .setup-btn-secondary {
            background: #e5e7eb;
            color: #374151;
            padding: 8px 24px;
            font-size: 14px;
            text-decoration: none;
        }
        .setup-btn-secondary:hover {
            background: #d1d5db;
        }
    </style>
    <link rel="stylesheet" href="./back.css">
</head>
<body class="background-anim-container">
    <div class="setup-container">
        <div class="setup-title">初始化完成</div>
        <div class="setup-desc">
            恭喜您，XCC主控系统已初始化完成！<br>
            现在可以使用管理员账号登录系统后台。
        </div>
        <form action="/auth/login.php" method="get">
            <button type="submit" class="setup-btn">立即登录</button>
        </form>
        <!-- 新增：协议与支持区域 -->
        <div class="support-section">
            <p class="license-text">本软件基于 <a href="https://www.apache.org/licenses/LICENSE-2.0" target="_blank" class="license-link">Apache 2.0 协议</a> 开源</p>
            <div class="support-buttons">
                <a href="https://github.com/defeatedperson/xcc" target="_blank" class="setup-btn setup-btn-secondary">点个 Star</a>
                <a href="https://re.xcdream.com/zhichiwtx" target="_blank" class="setup-btn setup-btn-secondary">支持我们</a>
            </div>
        </div>
    </div>
</body>
</html>