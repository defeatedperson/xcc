<?php
// 管理页：包含公共头部和管理功能模块
include 'header.php';
?>
    <!-- 新增/修改表格及容器样式 -->
    <meta id="csrf-token" name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <link rel="stylesheet" href="./assets/domains.css">
    <link rel="stylesheet" href="./assets/logs.css">
    <script src="/dc-admin/assets/jquery-3.6.0.min.js" nonce="<?= $_SESSION['csp_nonce'] ?? '' ?>"></script>
    <script src="/dc-admin/assets/logs.js" nonce="<?= $_SESSION['csp_nonce'] ?? '' ?>"></script>
    <!-- 控制台登录日志 -->
    <div class="main-content">
        <div class="domain-title-container">
            <h2 class="domain_title2">登录日志</h2>
            <div class="pagination">
                <button class="btn-new" id="refreshLoginLogBtn">刷新</button>
                <button class="btn-dw" id="dwLoginLogBtn">下载</button>
                <button class="btn-clear" id="clearLoginLogBtn">清空</button>
            </div>
        </div>
        <div class="domains-logs">
            <div class="log-container">
                <div class="log-content" id="loginLogContent"></div>
            </div>
        </div>
    </div>
     
    <!-- 模块日志容器 -->
    <div class="main-content">
        <div class="domain-title-container">
            <h2 class="domain_title2">配置生成</h2>
            <div class="pagination">
                <button class="btn-new" id="refreshGenerateLogBtn">刷新</button>
                <button class="btn-dw" id="dwGenerateLogBtn">下载</button>
            </div>
        </div>
        <div class="domains-logs">
            <div class="log-container">
                <div class="log-content" id="generateLogContent"></div>
            </div>
        </div>
    </div>
    <!-- 删除日志区域 -->
    <div class="main-content">
        <div class="domain-title-container">
            <h2 class="domain_title2">域名删除</h2>
            <div class="pagination">
                <button class="btn-new" id="refreshDeleteLogBtn">刷新</button>
                <!-- 修改下载按钮ID为唯一值 -->
                <button class="btn-dw" id="dwDeleteLogBtn">下载</button>
            </div>
        </div>
        <div class="domains-logs">
            <div class="log-container">
                <div class="log-content" id="deleteLogContent"></div>
            </div>
        </div>
    </div>
    <div class="main-content">
        <div class="domain-title-container">
            <h2 class="domain_title2">节点同步</h2>
            <!-- 日志操作按钮（下方左边） -->
            <div class="pagination">
                <button class="btn-new" id="refreshSyncLogBtn">刷新</button>
                <button class="btn-dw" id="dwSyncLogBtn">下载</button>  <!-- 修正下载按钮ID -->
                <button class="btn-clear" id="clearSyncLogBtn">清空</button>  <!-- 修正清空按钮ID -->
            </div>
        </div>
        <!-- 日志展示区域 -->
        <div class="domains-logs">
            <div class="log-container">
                <div class="log-content" id="syncLogContent"></div>
            </div>
        </div>
    </div>
    <!-- 缓存清理 -->
    <div class="main-content">
        <div class="domain-title-container">
            <h2 class="domain_title2">缓存清理</h2>
            <div class="pagination">
                <button class="btn-new" id="refreshClearLogBtn">刷新</button>
                <button class="btn-dw" id="dwClearLogBtn">下载</button>
                <button class="btn-clear" id="clearClearLogBtn">清空</button>
            </div>
        </div>
        <div class="domains-logs">
            <div class="log-container">
                <div class="log-content" id="clearLogContent"></div>
            </div>
        </div>
    </div>
    <div class="main-content">
        <div class="domain-title-container">
            <h2 class="domain_title2">拒绝任务</h2>
            <div class="pagination">
                <button class="btn-new" id="refreshRejectLogBtn">刷新</button>
                <button class="btn-dw" id="dwRejectLogBtn">下载</button>
                <button class="btn-clear" id="clearRejectLogBtn">清空</button>
            </div>
        </div>
        <div class="domains-logs">
            <div class="log-container">
                <div class="log-content" id="rejectLogContent"></div>
            </div>
        </div>
    </div>
    <!-- 节点提交日志区域 -->
    <div class="main-content">
        <div class="domain-title-container">
            <h2 class="domain_title2">节点提交</h2>
            <div class="pagination">
                <button class="btn-new" id="refreshSqlLogBtn">刷新</button>
                <button class="btn-dw" id="dwSqlLogBtn">下载</button>
                <button class="btn-clear" id="clearSqlLogBtn">清空</button>
            </div>
        </div>
        <div class="domains-logs">
            <div class="log-container">
                <div class="log-content" id="sqlLogContent"></div>
            </div>
        </div>
    </div>

    <div class="main-content2"></div>

<?php include 'footer.php'; ?>