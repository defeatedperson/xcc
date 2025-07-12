<?php
// CC防护设置子表单（包含全局规则和个人规则）
// 或先定义根目录常量，再引用（更推荐）
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';
?>
<!-- 添加CSRF令牌Meta标签，与domain_form.php保持一致 -->
<meta id="csrf-token" content="<?= $_SESSION['csrf_token'] ?? '' ?>" />
<!-- 引入外部样式和脚本（保留CSP nonce） -->
<link rel="stylesheet" href="/dc-admin/manage/css/cc.css">
<script nonce="<?= $_SESSION['csp_nonce'] ?? '' ?>" src="/dc-admin/manage/js/cc.js"></script>
<div class="sub-form">
    <!-- 新增规则容器（控制横向布局） -->
    <div class="rule-container">
        <!-- 全局规则区域 -->
        <div class="rule-section">
            <h4>全局规则设置</h4>
            <div class="form-group">
                <label for="global-stat-time">统计时间（秒）：</label>
                <input type="number" id="global-stat-time" name="global_stat_time" 
                       class="cc-input" 
                       placeholder="统计请求数的时间窗口（如：60）"
                       min="1" step="1">
            </div>
            <div class="form-group">
                <label for="global-req-limit">触发请求数：</label>
                <input type="number" id="global-req-limit" name="global_req_limit" 
                       class="cc-input" 
                       placeholder="统计时间内超过此数量触发防护（如：100）"
                       min="1" step="1">
            </div>
            <div class="form-group">
                <label for="global-verify-expiry">验证有效期（秒）：</label>
                <input type="number" id="global-verify-expiry" name="global_verify_expiry" 
                       class="cc-input" 
                       placeholder="验证码/验证页面的有效时长（如：300）"
                       min="1" step="1">
            </div>
            <div class="form-group">
                <label for="global-try-limit">最大尝试次数：</label>
                <input type="number" id="global-try-limit" name="global_try_limit" 
                       class="cc-input" 
                       placeholder="验证失败的最大次数（如：3）"
                       min="1" step="1">
            </div>
        </div>

        <!-- 个人规则区域 -->
        <div class="rule-section">
            <h4>个人规则设置</h4>
            <div class="form-group">
                <label for="personal-stat-time">统计时间（秒）：</label>
                <input type="number" id="personal-stat-time" name="personal_stat_time" 
                       class="cc-input" 
                       placeholder="针对单个IP的统计时间窗口（如：30）"
                       min="1" step="1">
            </div>
            <div class="form-group">
                <label for="personal-req-limit">触发请求数：</label>
                <input type="number" id="personal-req-limit" name="personal_req_limit" 
                       class="cc-input" 
                       placeholder="单个IP在统计时间内的最大请求数（如：50）"
                       min="1" step="1">
            </div>
            <div class="form-group">
                <label for="personal-max-req">最大封禁请求数：</label>
                <input type="number" id="personal-max-req" name="personal_max_req" 
                       class="cc-input" 
                       placeholder="超过此数量将封禁IP（如：200）"
                       min="1" step="1">
            </div>
        </div>
    </div>

    <!-- 操作按钮组 -->
    <div class="button-group">
        <button type="button" class="btn-save">保存</button>
        <button type="button" class="btn-reset">恢复默认</button>
    </div>
</div>