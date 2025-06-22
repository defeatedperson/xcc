// 表单提交逻辑（保持原有功能）
$(function() {
    const formType = $('#formContainer').data('form-type');
    const currentDomain = $('#formContainer').data('current-domain'); 
    const csrfToken = $('#csrf-token').attr('content'); // 从meta标签获取CSRF Token（保持原有获取方式）

    // 控制「更多设置」按钮的状态
    if (formType === 'add') {
        $('.function-radio').prop('disabled', true);
        $('#subFormContainer').html('<div class="default-prompt">请先填写域名/源站信息并保存</div>');
    } else {
        $('.function-radio').prop('disabled', false);
    }

    // 编辑模式时加载域名数据
    if (formType === 'edit' && currentDomain) {
        $.post('/data/read.php', { 
            action: 'get_domain_detail', 
            domain: currentDomain,
            csrf_token: csrfToken // 新增：携带CSRF令牌
        }, function(res) {
            if (res.success) {
                $('#domain').val(res.data.domain).prop('readonly', true);
                $('#originUrl').val(res.data.origin_url);
                if (res.data.ssl_enabled === "1") {
                    $('#ssl').prop('checked', true); // 移除.trigger('change')
                }
            } else {
            showAlert('error','加载域名信息失败：' + res.message);
            }
        }, 'json');
    }

    // 功能按钮切换逻辑
    $('.function-radio').change(function() {
        const functionType = $(this).val();
        const currentDomain = $('#formContainer').data('current-domain');
        if (functionType) {
            const subFormUrl = `/dc-admin/manage/forms/${functionType}_form.php`;
            $.post(subFormUrl, { domain: currentDomain }, function(html) {
                $('#subFormContainer')
                    .html(html)
                    .data('domain', currentDomain);
            });
        } else {
            $('#subFormContainer').html('<div class="default-prompt">请选择需要设置的功能</div>');
        }
    });
    
    $('#domainForm').submit(function(e) {
        e.preventDefault();
        const $submitBtn = $(this).find('.submit-btn');
        $submitBtn.prop('disabled', true).text('保存中...');

        // 前端校验逻辑
        const domain = $('#domain').val().trim();
        const originUrl = $('#originUrl').val().trim();
        const domainPattern = /^(xn--)?[a-zA-Z0-9-]+(?:\.[a-zA-Z0-9-]+)*\.[a-zA-Z]{2,}$/;
        const originUrlPattern = /^https?:\/\/(?:[a-zA-Z0-9-]+(?:\.[a-zA-Z0-9-]+)*(?:\:[0-9]+)?|(?:[0-9]{1,3}\.){3}[0-9]{1,3}(?:\:[0-9]+)?)$/;

        if (!domainPattern.test(domain)) {
           showAlert('error','域名格式错误（示例：example.com，不允许协议头/中文域名（需转换）');
            $submitBtn.prop('disabled', false).text('保存设置');
            return;
        }

        if (!originUrlPattern.test(originUrl)) {
           showAlert('error','原站地址格式错误（示例：http://origin.example.com 或 https://192.168.1.1:8080）');
            $submitBtn.prop('disabled', false).text('保存设置');
            return;
        }
    
        const csrfToken = $('#csrf-token').attr('content'); // 从meta标签获取CSRF Token
        const formData = {
            domain: $('#domain').val(),
            originUrl: $('#originUrl').val(),
            type: formType,
            csrf_token: csrfToken // 添加CSRF Token
        };

        if (formType === 'add') {
            formData.ssl_enabled = 0;
            formData.protection_enabled = 0;
        }

        $.post('/data/write.php?action=save_domain', formData, function(res) {
            if (res.success) {
               showAlert('success','保存成功！');
                $(document).trigger('domainListRefresh');

                // 新增：调用admin-update.php初始化/更新状态
                $.post('/data/admin-update.php', {
                    action: 'add',  // 使用admin-update.php的add操作
                    domain: domain, // 当前保存的域名
                    csrf_token: csrfToken
                }, function(adminRes) {
                    if (!adminRes.success) {
                        showAlert('warning', '管理状态初始化失败：' + adminRes.message);
                    }
                }, 'json');

                if (formType === 'add') {
                    window.location.reload();
                }
            } else {
               showAlert('error','保存失败：' + res.message);
            }
        }, 'json').always(function() {
            $submitBtn.prop('disabled', false).text('保存设置');
        });
    });
});
