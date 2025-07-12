<?php
// 反向代理缓存设置子表单（动态版）
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';
?>
<meta id="csrf-token" content="<?= $_SESSION['csrf_token'] ?? '' ?>" />
<link rel="stylesheet" href="/dc-admin/manage/css/cache.css">
<script nonce="<?= $_SESSION['csp_nonce'] ?? '' ?>" src="/dc-admin/manage/js/cache.js"></script>
<!-- 新增：通过data属性传递当前域名 -->
<div class="sub-form" id="subFormContainer" data-domain="<?= htmlspecialchars($_GET['domain'] ?? '') ?>">
    <h4>缓存设置</h4>
    <div class="cache-container">
        <!-- 开关：是否开启缓存（修改id为cache-enabled） -->
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" id="cache-enabled" name="cache_enabled"> 是否启用缓存
            </label>
        </div>
        <div class="form-group">
            <label for="cache-content">文件后缀（支持|分隔，如jpg|png）：</label>
            <div class="tag-container"></div>
            <input type="text" id="cache-content" class="cache-input" 
                placeholder="输入文件后缀（如jpg）后按回车" 
                maxlength="100"
                pattern="[a-zA-Z0-9]+"
                title="仅允许输入字母（a-z/A-Z）和数字（0-9）">  <!-- 更新提示信息 -->
        </div>
        <div class="form-group">
            <label for="cache-time1">缓存时间（支持s/h/d）：</label>
            <input type="text" id="cache-time1" class="cache-input" 
                    placeholder="如：3600s（秒）、1h（小时）、1d（天）"
                    pattern="\d+[shd]?" 
                    title="请输入有效格式（示例：3600、1h、1d）">
        </div>
        <div class="button-group">
            <button type="button" class="btn-save">保存</button>
            <button type="button" class="btn-reset">恢复默认</button>
        </div>
    </div>
</div>