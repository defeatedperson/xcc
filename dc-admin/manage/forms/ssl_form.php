<?php
// 或先定义根目录常量，再引用（更推荐）
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';
// SSL证书设置子表单（支持保存/修改与信息展示）
?>

<!-- 添加CSRF令牌Meta标签，与domain_form.php保持一致 -->
<meta id="csrf-token" content="<?= $_SESSION['csrf_token'] ?? '' ?>" />
<!-- 引入外部样式和脚本（保留CSP nonce） -->
<link rel="stylesheet" href="/dc-admin/manage/css/ssl.css">
<script nonce="<?= $_SESSION['csp_nonce'] ?? '' ?>" src="/dc-admin/manage/js/ssl.js"></script>
<div class="sub-form">
    <!-- 优化：强制HTTPS复选框样式 -->
    <div class="form-group">
        <label class="checkbox-label">  <!-- 新增class统一样式 -->
            <input type="checkbox" id="force-https" name="force_https"> 是否强制HTTPS
        </label>
    </div>

    <!-- 移除：TLS版本复选框组，替换为提示 -->
    <div class="form-group">
        <label>默认从TLS 1.2版本起步，更高安全性，不支持自定义。</label>
    </div>

    <div class="form-group">
        <label for="ssl-cert-content">证书内容：</label>
        <textarea id="ssl-cert-content" name="ssl_cert" 
                  class="ssl-content" 
                  placeholder="请粘贴SSL证书内容（PEM格式）"
                  rows="6"></textarea>
    </div>
    <div class="form-group">
        <label for="ssl-key-content">私钥内容：</label>
        <textarea id="ssl-key-content" name="ssl_key" 
                  class="ssl-content" 
                  placeholder="请粘贴私钥内容（PEM格式）"
                  rows="6"></textarea>
    </div>
    
    <!-- 证书信息展示区域（默认隐藏） -->
    <div class="cert-info">
        <p><strong>证书域名：</strong><span id="cert-domain">待解析</span></p>
        <p><strong>有效期至：</strong><span id="cert-expiry">待解析</span></p>
    </div>

    <!-- 操作按钮组 -->
    <div class="form-actions">
        <button type="button" class="save-btn">保存</button>
        <button type="button" class="edit-btn">修改</button>
        <button type="button" class="delete-btn">删除</button>  <!-- 新增删除按钮 -->
    </div>
</div>