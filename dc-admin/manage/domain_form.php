<?php
// 域名设置表单（拆分后版本）
// 定义根目录常量（关键安全设置，必须保留）
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php'; // 集成中心验证文件（核心安全控制）
?>
<!-- 引入外部样式和脚本（保留CSP nonce） -->
<link rel="stylesheet" href="/dc-admin/manage/css/form.css">
<script nonce="<?= $_SESSION['csp_nonce'] ?? '' ?>" src="/dc-admin/manage/js/form.js"></script>
<form id="domainForm" class="domain-form">
    <!-- 基础设置区域 -->
    <div class="more-settings-title">基础设置</div>
    <div class="domains-basic">
        <div class="form-group">
            <label for="domain">域名：</label>
            <input type="text" id="domain" name="domain" class="domain-input" 
                   placeholder="请输入域名（如：example.com）">
        </div>
        <div class="form-group">
            <label for="originUrl">源站地址：</label>
            <input type="url" id="originUrl" name="originUrl" class="origin-input" 
                   placeholder="请输入原站URL（如：http://origin.example.com）">
        </div>
    </div>
    <div class="form-group mt-20">
        <button type="submit" class="submit-btn">保存设置</button>
    </div>

    <hr>

    <!-- 更多设置区域 -->
    <div class="more-settings-title">更多设置</div>
    <div class="notice2">×注意！设置如下规则之后，请点击“基础设置”当中的【保存设置】按钮，否则无法同步更新状态！</div>
    <div class="domains-anniu">
        <input type="radio" id="ssl" name="function" class="function-radio" value="ssl">
        <label for="ssl" class="function-label">SSL证书</label>
        <input type="radio" id="cache" name="function" class="function-radio" value="cache">
        <label for="cache" class="function-label">缓存设置</label>
        <input type="radio" id="cc" name="function" class="function-radio" value="cc">
        <label for="cc" class="function-label">CC防护</label>
        <input type="radio" id="ip-list" name="function" class="function-radio" value="ip-list">
        <label for="ip-list" class="function-label">黑白名单</label>
    </div>

    <div id="subFormContainer">
        <div class="default-prompt">请选择需要设置的功能</div>
    </div>
</form>
