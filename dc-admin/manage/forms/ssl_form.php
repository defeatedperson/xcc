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
    <!-- HTTPS配置选项 -->
    <div class="form-group">
        <label class="checkbox-label">
            <input type="checkbox" id="https-enabled" name="https_enabled"> 启用HTTPS
        </label>
    </div>
    
    <div class="form-group">
        <label class="checkbox-label">
            <input type="checkbox" id="force-https" name="force_https"> 强制HTTPS
        </label>
    </div>
    
    <!-- 添加自动SSL选项 -->
    <div class="form-group">
        <label class="checkbox-label">
            <input type="checkbox" id="auto-ssl" name="auto_ssl"> 自动SSL(需前往“扩展”页面设置)
        </label>
    </div>
    
    <!-- 证书状态展示区域 -->
    <div class="cert-status">
        <p><strong>证书状态：</strong><span id="cert-status-text">未检测</span></p>
        <p><strong>到期时间：</strong><span id="cert-expiry">未知</span></p>
        <p class="cert-notice" id="cert-notice"></p>
    </div>

    <!-- 操作按钮组 -->
    <div class="form-actions">
        <button type="button" class="save-btn">保存</button>
        <button type="button" class="edit-btn">修改</button>
        <button type="button" class="cert-manage-btn">证书管理</button>
    </div>
</div>