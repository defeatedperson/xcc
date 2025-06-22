<?php
// 节点页：包含公共头部和节点管理模块（安全方式与manage.php一致）
include 'header.php'; // 集成中心验证文件（核心安全控制）
?>  
<!-- 新增/修改表格及容器样式 -->
    <meta id="csrf-token" name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <link rel="stylesheet" href="./assets/node.css">
    <link rel="stylesheet" href="./assets/domains.css">
    <script src="/dc-admin/assets/jquery-3.6.0.min.js" nonce="<?= $_SESSION['csp_nonce'] ?? '' ?>"></script>
    <script src="./assets/node.js" nonce="<?= $_SESSION['csp_nonce'] ?? '' ?>"></script>
    <script src="./assets/node-management.js" nonce="<?= $_SESSION['csp_nonce'] ?? '' ?>"></script>
    <div class="main-content">
        <div class="domain-title-container">
            <h2 class="domain_title2">节点列表</h2>
            <div class="pagination">
                <button class="page-btn" id="prevBtn" disabled>上一页</button>
                <span id="currentPage">1</span>/<span id="totalPages">0</span>
                <button class="page-btn" id="nextBtn">下一页</button>
            </div>
        </div>
        <div class="table-container">
            <!-- 节点列表表格 -->
            <table class="node-list">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>节点名称</th>
                        <th>修改时间</th>
                        <th>节点IP</th>
                        <th>API密钥</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="nodeList">
                    <tr><td colspan="6" class="loading">加载中...</td></tr>
                </tbody>
            </table>
        </div>
        <div class="pagination2">
                <button class="page-btn add-node-btn">新增</button>
                <button class="page-btn" id="syncBtn">同步</button>
                <button class="page-btn" id="installCmdBtn">安装命令</button>
        </div>
         <!-- 新增：默认隐藏的表单区域 -->
         <div class="form-section">
            <div class="node-url">
                <h3>新增节点</h3> <!-- 大标题（第一行） -->
                <a href="https://re.xcdream.com/links/qiafan">✈️购买服务器</a>
            </div>
             <form id="nodeAddForm">
                 <div class="node-adds-2">
                     <!-- 每个小标题+输入框为一个div（.form-group） -->
                     <div class="form-group">
                        <label>节点名称（1-5位纯字母）：</label>  <!-- 小标题（第二行） -->
                        <input type="text" name="node_name" required 
                               pattern="[A-Za-z]{1,5}" 
                               placeholder="如：NodeA">  <!-- 输入框 -->
                    </div>
                    <div class="form-group">
                        <label>节点IP：</label>  <!-- 小标题（第二行） -->
                        <input type="text" name="node_ip" required 
                               placeholder="如：192.168.1.100">  <!-- 输入框 -->
                       </div>
                    <div class="form-group">
                        <label>节点密钥：</label>
                        <div class="key-input-group">  <!-- 新增容器控制输入框+按钮布局 -->
                            <input type="text" name="node_key" required 
                                   placeholder="如：sk-abc123def456">
                            <button type="button" class="generate-key-btn">随机密钥</button>
                        </div>
                    </div>
                 </div>
                 <button type="submit" class="submit-btn">提交</button>
                 <button type="button" class="cancel-btn">取消</button>
             </form>  
         </div>
    </div>
    <!-- 节点管理页面模块 -->
    <div class="main-content">
        <div class="domain-sets">
            <h2 class="domain_title">节点管理</h2>
            <select class="select-domain" id="domainSelect">
                <option value="">选择节点</option>
            </select>
        </div>
        <!-- 表单容器（修改：添加操作区域和默认提示的互斥结构） -->
        <div class="form-container" id="formContainer">
            <!-- 默认提示（初始显示） -->
            <div class="default-prompt" id="defaultPrompt">
                请选择一个已有的节点进行管理操作
            </div>
            <!-- 操作区域（初始隐藏） -->
            <div class="operation-area" id="operationArea">
                <div class="button-group">
                    <div>快捷操作</div>
                    <button class="btn btn-start">启动</button>
                    <button class="btn btn-stop">停止</button>
                    <button class="btn btn-restart">重启</button>
                </div>
                <hr class="operation-divider">  <!-- 新增分割线 -->
                <div class="log-container">
                    <div class="log-buttons">
                        <label><input type="radio" name="log-type" value="go" checked> 被控日志</label>
                        <label><input type="radio" name="log-type" value="error"> 错误日志</label>
                    </div>
                    <div class="log-nodes">
                        <div class="log-node">
                            <!-- 日志内容动态加载区域 -->
                            点击【刷新】即可获取日志内容
                        </div>
                    </div>
                    <div class="button-node">
                        <button class="btn-new">刷新</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="main-content">
        <div class="domain-title-container">
            <h2 class="domain_title2">封禁列表</h2>
            <div class="pagination">
                <button class="page-btn" id="blockPrevBtn" disabled>上一页</button>
                <span id="blockCurrentPage">1</span>/<span id="blockTotalPages">0</span>
                <button class="page-btn" id="blockNextBtn">下一页</button>
            </div>
        </div>
        <!-- 新增表格容器 -->
        <div class="table-container">
            <!-- 封禁列表表格（添加tbody唯一ID） -->
            <table class="block-list">
                <thead>
                    <tr>
                        <th>封禁时间</th>
                        <th>封禁IP</th>
                    </tr>
                </thead>
                <tbody id="blockList">
                    <tr><td colspan="2" class="loading">加载中...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <!-- 提示模块 -->
    <div class="main-content">
        <div class="tutorial-box">
            <h3>① 如何添加节点</h3>
            <ol>
                <li>点击“<b>新增</b>”按钮，填写名称、ip等信息，提交。</li>
            </ol>
            <h3>② 如何安装节点</h3>
            <ol>
                <li>点击上方“<b>安装</b>”按钮，在跳转页面开启安装模式。</li>
                <li>节点系统“<b>Debian11/12</b>”，粘贴安装命令，根据提示安装即可。</li>
            </ol>
            <h3>③ 如何强制同步配置到节点</h3>
            <ol>
                <li>点击“<b>同步</b>”按钮，系统会自动强制推送到所有已部署节点。</li>
            </ol>
            <h3>④ 如何查看同步结果</h3>
            <ol>
                <li>在“<b>日志</b>”区域，可查看每个域名的配置生成、同步提交及更新时间。</li>
                <li>如有异常，可根据提示进行排查。</li>
            </ol>
        </div>
    </div>

    <div class="main-content2"></div>

<?php include 'footer.php'; ?> <!-- 复用公共底部 -->