// 从父容器动态获取当前域名（避免闭包导致的变量未更新）
function getCurrentDomain() {
    return $('#subFormContainer').data('domain');
}

// 初始化加载规则（使用动态获取的域名）
function loadIpRules() {
    const domain = getCurrentDomain();
    if (!domain) {
        showAlert('error', '未获取到当前域名');
        return;
    }
    const csrfToken = $('#csrf-token').attr('content'); // 获取CSRF令牌
    $.post('/data/ip-rules.php', { 
        action: 'get', 
        domain: domain,
        csrf_token: csrfToken  // 新增：携带CSRF令牌
    })
        .done(res => {
            const $list = $('#existingIpRuleList');
            const $noTip = $('.no-rule-tip');
            $list.empty();
            if (res.success && res.rules.length > 0) {
                $noTip.hide();
                res.rules.forEach(rule => {
                    const $item = $(`
                        <li class="rule-item" data-type="${rule.type}" data-list-type="${rule.list_type}" data-content="${rule.content}">
                            <div class="rule-info">
                                <div class="rule-content">
                                    <span class="type">${rule.type} | ${rule.list_type === 'black' ? '黑名单' : '白名单'}</span>
                                    <span class="content">${rule.content}</span>
                                    <span class="remark">${rule.remark || '无备注'}</span>
                                </div>
                                <button class="btn-delete">删除</button>
                            </div>
                        </li>
                    `);
                    $item.find('.btn-delete').click(deleteRule);
                    $list.append($item);
                });
            } else {
                $noTip.show();
            }
        })
        .fail(xhr => showAlert('error', '加载规则失败：' + xhr.statusText));
}

// 删除规则（使用动态域名 + 统一提示）
function deleteRule(e) {
    const ruleItem = $(e.target).closest('.rule-item');
    const domain = getCurrentDomain();
    if (!domain) {
        showAlert('error', '未获取到当前域名');
        return;
    }
    const csrfToken = $('#csrf-token').attr('content'); // 获取CSRF令牌

    const params = {
        action: 'delete',
        domain: domain,
        type: ruleItem.data('type') || '',
        list_type: ruleItem.data('list-type') || '',
        content: ruleItem.data('content') || '',
        csrf_token: csrfToken  // 新增：携带CSRF令牌
    };

    if (!params.type || !params.list_type || !params.content) {
        showAlert('error', '规则参数缺失，无法删除');
        return;
    }

    $.post('/data/ip-rules.php', params)
        .done(res => {
            if (res.success) {
                showAlert('success', '删除成功');
                loadIpRules(); // 刷新列表
            } else {
                showAlert('error', '删除失败：' + res.message);
            }
        })
        .fail(xhr => showAlert('error', '删除失败：' + xhr.statusText));
}

// 添加规则（增强输入校验 + 统一提示）
$('#btnAddRule').click(() => {
    const domain = getCurrentDomain();
    if (!domain) {
        showAlert('error', '未获取到当前域名');
        return;
    }
    const csrfToken = $('#csrf-token').attr('content'); // 获取CSRF令牌

    const type = $('#type').val();
    const listType = $('#listType').val();
    const content = $('#content').val().trim();
    const remark = $('#remark').val().trim();

    // 内容长度校验（参考cache_form的100字符限制）
    if (content.length > 100) {
        showAlert('error', '内容长度不能超过100字符');
        return;
    }

    // 格式校验（IP/URL针对性检查）
    let errorMsg = '';
    if (type === 'ip') {
        // IP格式校验（支持IPv4和CIDR）
        const ipPattern = /^((25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)(\/(3[0-2]|[12]?[0-9]))?$/;
        if (!ipPattern.test(content)) {
            errorMsg = 'IP格式无效（示例：192.168.1.100 或 10.0.0.0/8）';
        }
    } else if (type === 'url') {
        // URL格式校验（支持通配符*）
        const urlPattern = /^\/[\w-\/*]*$/; // 简单校验以/开头，允许字母数字-/*
        if (!urlPattern.test(content)) {
            errorMsg = 'URL格式无效（示例：/test/* 或 /api/）';
        }
    }

    if (errorMsg) {
        showAlert('error', errorMsg);
        return;
    }

    const params = {
        action: 'save',
        domain: domain,
        type: type,
        list_type: listType,
        content: content,
        remark: remark,
        csrf_token: csrfToken  // 新增：携带CSRF令牌
    };

    $.post('/data/ip-rules.php', params)
        .done(res => {
            if (res.success) {
                showAlert('success', '规则添加成功');
                loadIpRules(); // 刷新列表
                $('#content').val('');
                $('#remark').val('');
            } else {
                showAlert('error', '添加失败：' + res.message);
            }
        })
        .fail(xhr => showAlert('error', '请求失败：' + xhr.statusText));
});

// 文档就绪后初始化
$(function() {
    loadIpRules(); // 直接调用加载规则（已内置域名校验）
});