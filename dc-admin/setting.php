<?php
// 设置页：包含公共头部和系统设置表单
include 'header.php';
?>
    <meta id="csrf-token" name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <link rel="stylesheet" href="./assets/domains.css">
    <script src="./assets/setting.js"></script>
    <link rel="stylesheet" href="./assets/setting.css">
    <div class="main-content">
        <div class="domain-title-container">
            <h2 class="domain_title2">系统更新</h2>
            <div class="pagination">
                <button class="btn-new" id="updateZipBtn">更新</button>
                <button class="btn-new" id="deleteZipBtn">删除</button>
                <span class="version-badge" id="versionBadge" title="点击查看更新内容">加载中...</span>
            </div>
        </div>
        <div class="upload-section">
            <form id="uploadForm" enctype="multipart/form-data">
                <input type="file" id="zipFileInput" name="file" accept=".zip" required>
                <button type="submit" class="upload-btn">上传</button>
                <span id="uploadStatus" class="upload-status"></span>
            </form>
            <div class="upload-tip">
                <div>⚠️ <b>建议先完整备份</b>，更新后-备份文件会存放在：<code>站点目录/update/backup</code> 文件夹。前往
                    <a href="https://github.com/defeatedperson/xcc/releases" target="_blank" class="release-link">发布页</a>
                    可获取最新版本</div>
            </div>
        </div>
    </div>
    <div class="main-content">
        <div class="domain-title-container">
            <h2 class="domain_title2">节点安装</h2>
            <div class="pagination">
                <button class="page-btn" id="startInstallBtn">启动</button>
                <button class="page-btn" id="closeInstallBtn">关闭</button>
                <button class="page-btn" id="generateBtn">生成</button>
            </div>
        </div>
        <div class="install-mode-panel">
            <div class="install-mode-status">
                <span class="status-dot" id="installStatusDot"></span>
                <span class="status-text" id="installStatusText">检测中...</span>
            </div>
            <div class="install-command-block" id="installCommandBlock">
                <div class="install-command-label">节点安装命令（建议安装结束后，重启服务器or在节点管理里面启动一下）：</div>
                <div class="install-command">
                    <code id="installCommand"></code>
                    <button id="copyInstallCmdBtn" class="copy-btn ml-10">复制</button>
                </div>
                <div class="install-warning">建议关闭安装模式！自定义题目需点击生成按钮生效！不懂不建议自定义！自定义题目对新安装节点生效！</div>
            </div>
        </div>
    </div>
    <div class="main-content">
        <div class="domain-title-container">
            <h2 class="domain_title2">自定义题目</h2>
            <div class="pagination">
                <button class="btn-new" id="refreshQuestionsBtn">刷新</button>
                <button class="btn-new" id="addQuestionBtn">新增</button>
                <button class="btn-dw" id="saveQuestionsBtn">保存</button>
            </div>
        </div>
        <div id="questionsTableScroll" class="table-scroll-x hidden">
            <div id="questionsTableContainer">
                <table id="questionsTable">
                    <thead>
                        <tr>
                            <th>中文题干</th>
                            <th>英文题干</th>
                            <th>日文题干</th>
                            <th>选项（多语言，单选正确）</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody id="questionsTbody"></tbody>
                </table>
            </div>
            <div id="questionsTableTip" class="questions-tip">
                请点击“刷新”按钮获取题目
            </div>
        </div>
    </div>
    <!-- 提示模块 -->
    <div class="main-content">
        <div class="tutorial-box">
            <h3>① 如何重置密码</h3>
            <ol>
                <li>登录服务器进入“<b>文件管理</b>”（宝塔/1Panel）。进入站点文件夹</li>
                <li>删除站点目录/auth/data文件夹当中的json文件，即可重新进入初始化页面（已有数据不影响）</li>
            </ol>
            <h3>② 如何让自定义题目生效</h3>
            <ol>
                <li>点击上方“<b>生成</b>”按钮，生成最新的文件。之后安装的节点就是新题目了。</li>
            </ol>
            <h3>③ 为何不建议自定义题目</h3>
            <ol>
                <li>因为部分题目可能存在争议，导致用户体验问题，以及玄学bug导致边缘节点崩溃。</li>
            </ol>
        </div>
    </div>
    <div class="main-content2"></div>

<?php include 'footer.php'; ?>
