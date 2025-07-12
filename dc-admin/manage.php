<?php
// 管理页：包含公共头部和管理功能模块
include 'header.php';
?>
    <!-- 新增/修改表格及容器样式 -->
    <meta id="csrf-token" name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <link rel="stylesheet" href="./assets/manage.css">
    <link rel="stylesheet" href="./assets/domains.css">
    <script src="/dc-admin/assets/jquery-3.6.0.min.js" nonce="<?= $_SESSION['csp_nonce'] ?? '' ?>"></script>
    <script src="./assets/manage.js" nonce="<?= $_SESSION['csp_nonce'] ?? '' ?>"></script>
    <script src="./assets/domains.js" nonce="<?= $_SESSION['csp_nonce'] ?? '' ?>"></script>
    <div class="main-content">
        <div class="domain-title-container">
            <h2 class="domain_title2">域名列表</h2>
            <div class="pagination">
                <button class="page-btn" id="prevBtn" disabled>上一页</button>
                <span id="currentPage">1</span>/<span id="totalPages">0</span>
                <button class="page-btn" id="nextBtn">下一页</button>
            </div>
        </div>
        <div class="manage-section">
            <table class="domain-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>域名</th>
                        <th>源站</th>
                        <th>SSL</th>
                        <th>防护</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="domainList">
                    <!-- 动态数据在此渲染 -->
                    <tr><td colspan="6" class="loading">加载中...</td></tr>
                </tbody>
            </table>
        </div>
        <div class="pagination2">
                <button class="page-btn" id="generateConfigBtn">生成配置</button>
                <button class="page-btn" id="syncConfigBtn">同步配置</button>
                <div id="syncStatusTip"></div>
        </div>
    </div>
    <!-- 域名管理页面模块 -->
    <div class="main-content">
        <div class="domain-sets">
            <h2 class="domain_title">域名管理</h2>
            <select class="select-domain" id="domainSelect">
                <option value="">选择需要修改的域名</option>
            </select>
            <button class="add-domain-btn" id="addDomainBtn">新增域名</button>
        </div>
        <!-- 表单容器（替换默认提示） -->
       <div class="form-container" id="formContainer">
            <div class="default-prompt">
                请选择一个已有的域名进行管理，或点击"新增域名"按钮添加新域名
            </div>
        </div>
    </div>
    <!-- 同步列表容器 -->
    <div class="main-content">
        <div class="domain-title-container">
            <h2 class="domain_title2">状态列表</h2>
            <div class="pagination">
                <button class="page-btn" id="blockPrevBtn" disabled>上一页</button>
                <span id="blockCurrentPage">1</span>/<span id="blockTotalPages">0</span>
                <button class="page-btn" id="blockNextBtn">下一页</button>
            </div>
        </div>
        <div class="manage-section">
            <table class="domain-table">
                <thead>
                    <tr>
                        <th>域名</th>
                        <th>配置生成</th>
                        <th>同步提交</th>
                        <th>更新时间</th>
                    </tr>
                </thead>
                <tbody id="updateList">
                    <tr><td colspan="6" class="loading">加载中...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <!-- 提示模块 -->
    <div class="main-content">
        <div class="tutorial-box">
            <h3>① 如何添加域名</h3>
            <ol>
                <li>点击“<b>新增域名</b>”按钮，填写域名、源站等信息，保存。</li>
                <li>在域名列表中可查看和管理已添加的域名。</li>
            </ol>
            <h3>② 如何让配置生效</h3>
            <ol>
                <li>点击上方“<b>生成配置</b>”按钮，生成最新的节点配置文件。</li>
            </ol>
            <h3>③ 如何同步配置到节点</h3>
            <ol>
                <li>点击“<b>同步配置</b>”按钮，系统会自动将配置推送到所有已部署节点。</li>
            </ol>
            <h3>④ 如何查看同步结果</h3>
            <ol>
                <li>在“<b>日志</b>”区域，可查看每个域名的配置生成、同步提交及更新时间。</li>
                <li>如有异常，可根据提示进行排查。</li>
            </ol>
        </div>
    </div>
    <div class="main-content2"></div>

<?php include 'footer.php'; ?>