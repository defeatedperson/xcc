// 加载CC防护规则（调用cc-robots.php的GET接口）
function loadCCRules(domain) {
    // 获取CSRF令牌（参考cache.js的实现）
    const csrfToken = $('#csrf-token').attr('content');
    // 修改：添加csrf_token参数
    $.post('/data/cc-robots.php', { 
        action: 'get', 
        domain: domain,
        csrf_token: csrfToken  // 新增CSRF令牌
    })
        .done(function(res) {
            if (res.success) {
                const rules = res.json_data;
                $('#global-stat-time').val(rules.interval);
                $('#global-req-limit').val(rules.threshold);
                $('#global-verify-expiry').val(rules.valid_duration);
                $('#global-try-limit').val(rules.max_try);
                $('#personal-stat-time').val(rules.custom_rules.interval);
                $('#personal-req-limit').val(rules.custom_rules.threshold);
                $('#personal-max-req').val(rules.custom_rules.max_threshold);
            }
        })
        .fail(function(xhr) {
            showshowAlert('error','error', `加载规则失败：${xhr.statusText}`); // 改为公共弹窗
        });
}

// 恢复默认值逻辑
document.querySelector('.btn-reset').addEventListener('click', function() {
    document.getElementById('global-stat-time').value = '10';
    document.getElementById('global-req-limit').value = '500';
    document.getElementById('global-verify-expiry').value = '300';
    document.getElementById('global-try-limit').value = '3';

    document.getElementById('personal-stat-time').value = '10';
    document.getElementById('personal-req-limit').value = '300';
    document.getElementById('personal-max-req').value = '1000';
});

// 输入框校验逻辑（新增）
function handleInputValidation(e) {
const input = e.target;
// 先过滤非数字字符（包含小数点）
let cleanedValue = input.value.replace(/\D|^0/g, '');
// 额外处理末尾小数点（如输入"12."会被替换为"12"）
if (input.value.endsWith('.')) {
    cleanedValue = cleanedValue.replace(/\.$/, '');
}
// 空值时重置为1
input.value = cleanedValue === '' ? '1' : cleanedValue;
}

/// 文档就绪后执行（避免重复声明）
$(function() {
const currentDomain = $('#subFormContainer').data('domain');

// 加载当前域名的规则
if (currentDomain) {
    loadCCRules(currentDomain);
}

// 保存按钮逻辑（增强输入校验）
document.querySelector('.btn-save').addEventListener('click', function() {
    if (!currentDomain) {
        showshowAlert('error','error', '未获取到当前域名'); 
        return;
    }

    // 获取所有输入值并校验（新增正则校验）
    const globalStatTimeVal = $('#global-stat-time').val().trim();
    const globalReqLimitVal = $('#global-req-limit').val().trim();
    const globalVerifyExpiryVal = $('#global-verify-expiry').val().trim();
    const globalTryLimitVal = $('#global-try-limit').val().trim();
    const personalStatTimeVal = $('#personal-stat-time').val().trim();
    const personalReqLimitVal = $('#personal-req-limit').val().trim();
    const personalMaxReqVal = $('#personal-max-req').val().trim();

    // 正则校验（所有输入必须为纯数字，无小数）
    const numericPattern = /^\d+$/; // 纯数字正则
    if (!numericPattern.test(globalStatTimeVal)) {
        showAlert('error','error','全局统计时间（秒）必须为正整数（禁止有小数点）');
        return;
    }
    if (!numericPattern.test(globalReqLimitVal)) {
        showAlert('error','全局触发请求数必须为正整数（禁止有小数点）');
        return;
    }
    if (!numericPattern.test(globalVerifyExpiryVal)) {
        showAlert('error','全局验证有效期（秒）必须为正整数（禁止有小数点）');
        return;
    }
    if (!numericPattern.test(globalTryLimitVal)) {
        showAlert('error','全局最大尝试次数必须为正整数（禁止有小数点）');
        return;
    }
    if (!numericPattern.test(personalStatTimeVal)) {
        showAlert('error','个人统计时间（秒）必须为正整数（禁止有小数点）');
        return;
    }
    if (!numericPattern.test(personalReqLimitVal)) {
        showAlert('error','个人触发请求数必须为正整数（禁止有小数点）');
        return;
    }
    if (!numericPattern.test(personalMaxReqVal)) {
        showAlert('error','个人最大封禁请求数必须为正整数（禁止有小数点）');
        return;
    }

    // 转换为整数并校验数值范围（≥1）
    const globalStatTime = parseInt(globalStatTimeVal);
    const globalReqLimit = parseInt(globalReqLimitVal);
    const globalVerifyExpiry = parseInt(globalVerifyExpiryVal);
    const globalTryLimit = parseInt(globalTryLimitVal);
    const personalStatTime = parseInt(personalStatTimeVal);
    const personalReqLimit = parseInt(personalReqLimitVal);
    const personalMaxReq = parseInt(personalMaxReqVal);

        if (globalStatTime < 1) {
            showAlert('error','全局统计时间（秒）必须为正整数（≥1）');
            return;
        }
        if (isNaN(globalReqLimit) || globalReqLimit < 1) {
            showAlert('error','全局触发请求数必须为正整数');
            return;
        }
        if (isNaN(globalVerifyExpiry) || globalVerifyExpiry < 1) {
            showAlert('error','全局验证有效期（秒）必须为正整数');
            return;
        }
        if (isNaN(globalTryLimit) || globalTryLimit < 1) {
            showAlert('error','全局最大尝试次数必须为正整数');
            return;
        }
        if (isNaN(personalStatTime) || personalStatTime < 1) {
            showAlert('error','个人统计时间（秒）必须为正整数');
            return;
        }
        if (isNaN(personalReqLimit) || personalReqLimit < 1) {
            showAlert('error','个人触发请求数必须为正整数');
            return;
        }
        if (isNaN(personalMaxReq) || personalMaxReq < 1) {
            showAlert('error','个人最大封禁请求数必须为正整数');
            return;
        }

        // 构造JSON数据
        const jsonData = {
            interval: globalStatTime,
            threshold: globalReqLimit,
            valid_duration: globalVerifyExpiry,
            max_try: globalTryLimit,
            custom_rules: {
                interval: personalStatTime,
                threshold: personalReqLimit,
                max_threshold: personalMaxReq
            }
        };

        // 调用后端接口时添加CSRF令牌（参考cache.js的保存逻辑）
        $.post('/data/cc-robots.php', {
            domain: currentDomain,
            json_data: JSON.stringify(jsonData),
            csrf_token: $('#csrf-token').attr('content')  // 新增CSRF令牌
        }, function(res) {
            if (res.success) {
                showAlert('success','保存成功！');
            } else {
                showAlert('error',`保存失败：${res.message}`);
            }
        }, 'json').fail(function(xhr) {
            showAlert('error',`请求失败：${xhr.statusText}`);
        });
    });
});
