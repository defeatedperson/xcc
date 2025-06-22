<?php
// 检查 /auth/data 目录下是否存在 json 文件，若存在则跳转到网站根目录并终止执行
$authDataDir = $_SERVER['DOCUMENT_ROOT'] . '/auth/data';
if (is_dir($authDataDir)) {
    foreach (glob($authDataDir . '/*.json') as $jsonFile) {
        if (is_file($jsonFile)) {
            header("Location: /");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>XCC主控安装</title>
    <link rel="stylesheet" href="./back.css">
    <style>
        body {
            background: #f6f8fa;
            font-family: "Segoe UI", "PingFang SC", "Hiragino Sans GB", "Arial", sans-serif;
        }
        .setup-container {
            max-width: 420px;
            margin: 80px auto 0 auto;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
            padding: 36px 32px 28px 32px;
            text-align: center;
        }
        .setup-title {
            font-size: 22px;
            color: #2563eb;
            margin-bottom: 18px;
            letter-spacing: 1px;
        }
        .setup-desc {
            color: #444;
            font-size: 15px;
            margin-bottom: 32px;
            line-height: 1.7;
        }
        .setup-btn {
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            padding: 10px 38px;
            cursor: pointer;
            transition: background 0.2s;
        }
        .setup-btn:disabled {
            background: #b3b3b3;
            cursor: not-allowed;
        }
        .agreement-area {
            margin: 18px 0 0 0;
            font-size: 14px;
            color: #555;
            text-align: left;
        }
        .agreement-link {
            color: #2563eb;
            cursor: pointer;
            text-decoration: underline;
        }
        /* 弹窗样式 */
        .modal-mask {
            display: none;
            position: fixed;
            left: 0; top: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.25);
            z-index: 1000;
        }
        .modal-box {
            display: none;
            position: fixed;
            left: 50%; top: 50%;
            transform: translate(-50%, -50%);
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.13);
            padding: 28px 32px 18px 32px;
            max-width: 420px;
            width: 90%;
            z-index: 1001;
        }
        .modal-title {
            font-size: 18px;
            color: #2563eb;
            margin-bottom: 12px;
        }
        .modal-content {
            font-size: 14px;
            color: #333;
            max-height: 260px;
            overflow-y: auto;
            margin-bottom: 18px;
            line-height: 1.7;
        }
        .modal-close {
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 5px;
            font-size: 15px;
            padding: 7px 28px;
            cursor: pointer;
        }
    </style>
</head>

<body class="background-anim-container">
    <div class="setup-container">
        <div class="setup-title">欢迎使用 XCC 防御系统</div>
        <div class="setup-desc">
            本向导将帮助您完成 XCC 初始化配置。<br>
            请根据提示完成每一步操作。
        </div>
        <form action="setup1.php" method="get" id="setupForm">
            <div class="agreement-area">
                <input type="checkbox" id="agree" name="agree">
                <label for="agree">
                    我已阅读并同意
                    <a class="agreement-link" href="/pact.php" target="_blank">《XCC产品使用协议》</a>
                </label>
            </div>
            <button type="submit" class="setup-btn" id="nextBtn" disabled>下一步</button>
        </form>
    </div>
    <script>
        document.getElementById('agree').addEventListener('change', function() {
            document.getElementById('nextBtn').disabled = !this.checked;
        });
    </script>
</body>
</html>