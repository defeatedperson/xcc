<?php
// 证书页：包含公共头部和系统设置表单
include 'header.php';
?>
    <meta id="csrf-token" name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <link rel="stylesheet" href="./assets/domains.css">
    <link rel="stylesheet" href="./assets/certs.css">
    <link rel="stylesheet" href="./assets/manage.css">
    <script src="/dc-admin/assets/jquery-3.6.0.min.js" nonce="<?= $_SESSION['csp_nonce'] ?? '' ?>"></script>
    <script src="./assets/certs.js" nonce="<?= $_SESSION['csp_nonce'] ?? '' ?>"></script>
    <div class="main-content">
        <div class="domain-title-container">
            <h2 class="domain_title2">SSL证书列表</h2>
        </div>
        <div class="manage-section">
            <div id="cert-loading" class="loading">正在加载证书信息...</div>
            <div id="cert-list" class="hidden">
                <table class="domain-table">
                    <thead>
                        <tr>
                            <th>序号</th>
                            <th>域名</th>
                            <th>到期时间</th>
                            <th>状态</th>
                        </tr>
                    </thead>
                    <tbody id="cert-table-body">
                        <!-- 证书数据将通过JavaScript动态加载 -->
                    </tbody>
                </table>
            </div>
            <div id="cert-empty" class="default-prompt hidden">暂无证书数据</div>
            <div id="cert-error" class="default-prompt hidden">加载证书数据失败</div>
        </div>
    </div>
    <div class="main-content">
        <div class="domain-title-container">
            <h2 class="domain_title2">上传SSL证书</h2>
        </div>
        <div class="manage-section">
            <form id="cert-upload-form" class="cert-form">
                <div class="form-group">
                    <label for="ssl_cert">SSL证书内容 (*.crt/*.pem)</label>
                    <textarea id="ssl_cert" name="ssl_cert" class="cert-textarea" placeholder="请粘贴证书内容，包含BEGIN CERTIFICATE和END CERTIFICATE标记" required></textarea>
                </div>
                <div class="form-group">
                    <label for="ssl_key">SSL私钥内容 (*.key)</label>
                    <textarea id="ssl_key" name="ssl_key" class="cert-textarea" placeholder="请粘贴私钥内容，包含BEGIN和END标记" required></textarea>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn-upload">上传证书</button>
                    <button type="button" class="btn-clear" id="clear-form">清空表单</button>
                </div>
                <div id="upload-status" class="upload-status hidden"></div>
            </form>
        </div>
    </div>

    <!-- 提示模块 -->
    <div class="main-content">
        <div class="tutorial-box">
            <h3>① 如何自动SSL证书？</h3>
            <ol>
                <li>先在本页面上传SSL证书，并且在<b>站点设置</b>当中启用HTTPS。</li>
                <li>点击“<b>扩展</b>”菜单，添加自动ssl证书域名即可（需设置XDM节点）。</li>
            </ol>
            <h3>② 为什么无法直接删除SSL证书？</h3>
            <ol>
                <li>为了保证程序的稳定性，<b>不允许直接删除</b>已上传的证书。</li>
            </ol>
            <h3>③ 如何删除SSL证书？</h3>
            <ol>
                <li>创建一个对应域名的站点，然后删除即可</li>
            </ol>
        </div>
    </div>
    
    <div class="main-content2"></div>

<?php include 'footer.php'; ?>