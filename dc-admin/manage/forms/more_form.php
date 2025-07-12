<?php
// 自定义回源域名设置子表单
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';
?>
<meta id="csrf-token" content="<?= $_SESSION['csrf_token'] ?? '' ?>" />
<link rel="stylesheet" href="/dc-admin/manage/css/more.css">
<script nonce="<?= $_SESSION['csp_nonce'] ?? '' ?>" src="/dc-admin/manage/js/more.js"></script>
<!-- 通过data属性传递当前域名 -->
<div class="sub-form" id="subFormContainer" data-domain="<?= htmlspecialchars($_GET['domain'] ?? '') ?>">
    <div class="origin-container">
        <!-- 开关：是否启用自定义回源 -->
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" id="origin-enabled" name="origin_enabled"> 自定义回源域名
            </label>
            <label class="checkbox-label">
                <input type="checkbox" id="hsts-enabled" name="hsts_enabled"> 启用HSTS(仅HTTPS生效)
            </label>
        </div>
        
        <!-- 回源域名输入框 -->
        <div class="form-group">
            <label for="origin-domain">回源域名：</label>
            <input type="text" id="origin-domain" class="origin-input" 
                placeholder="请输入回源域名（如：origin.example.com 或 cdn.example.com:8080）" 
                maxlength="253"
                disabled>
        </div>
        <!-- 按钮组 -->
        <div class="button-group">
            <button type="button" class="btn-save">保存</button>
            <button type="button" class="btn-reset">恢复默认</button>
        </div>
    </div>
</div>
