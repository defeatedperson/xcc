<?php
// 黑白名单设置子表单（已设置规则列表 + 添加表单）
// 或先定义根目录常量，再引用（更推荐）
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/auth/');
include ROOT_PATH . 'auth.php';
?>
<!-- 添加CSRF令牌Meta标签，与domain_form.php保持一致 -->
<meta id="csrf-token" content="<?= $_SESSION['csrf_token'] ?? '' ?>" />
<!-- 引入外部样式和脚本（保留CSP nonce） -->
<link rel="stylesheet" href="/dc-admin/manage/css/ip.css">
<script nonce="<?= $_SESSION['csp_nonce'] ?? '' ?>" src="/dc-admin/manage/js/ip.js"></script>
<div class="sub-form">
    <div class="list-container">
        <!-- 左侧：已设置规则列表（动态渲染） -->
        <div class="existing-rules">
            <h4>已设置规则</h4>
            <ul class="rule-list" id="existingIpRuleList">
                <!-- 动态渲染区域，初始为空 -->
            </ul>
            <div class="no-rule-tip">
                暂无黑白名单规则
            </div>
        </div>

        <!-- 右侧：添加规则表单 -->
        <div class="add-form">
            <h4>添加新规则</h4>
            <div class="form-group">
                <label>类型：</label>
                <select class="list-type" name="type" id="type">
                    <option value="url">URL</option>
                    <option value="ip">IP</option>
                </select>
            </div>
            <div class="form-group">
                <label>名单类型：</label>
                <select class="list-type" name="list-type" id="listType">
                    <option value="black">黑名单</option>
                    <option value="white">白名单</option>
                </select>
            </div>
            <div class="form-group">
                <label for="content">内容：</label>
                <input type="text" id="content" name="content" 
                       class="list-input" 
                       placeholder="请输入内容（如：/test/* 或 192.168.1.100）">
            </div>
            <div class="form-group">
                <label for="remark">备注：</label>
                <input type="text" id="remark" name="remark" 
                       class="list-input" 
                       placeholder="可选：填写规则说明">
            </div>
            <div class="button-group">
                <button type="button" class="btn-add" id="btnAddRule">添加规则</button>
            </div>
        </div>
    </div>
</div>
