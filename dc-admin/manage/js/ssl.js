$(function() {
    // 从 Meta 标签获取 CSRF 令牌（新增）
    const csrfToken = $('#csrf-token').attr('content');
    // 从父容器获取当前域名（通过 data-domain 属性传递）
    const currentDomain = $('#subFormContainer').data('domain');
    
    // 初始化时加载证书信息（若存在域名）
    if (currentDomain) {
        loadCertInfo(currentDomain);
    }

   // 加载证书信息函数（核心逻辑）
   function loadCertInfo(domain) {
    // 修改：添加 CSRF 令牌（关键修复）
    $.post('/data/ssl-certs.php', { 
        domain: domain,
        csrf_token: csrfToken  // 新增参数
    })
            .done(function(res) {
                if (res.success) {
                    // 填充证书内容并设置只读
                    $('#ssl-cert-content').val(res.cert).prop('readonly', true);
                    $('#ssl-key-content').val(res.key).prop('readonly', true);
                    // 新增：禁用复选框（保存后不可修改）
                    $('#force-https').prop('disabled', true);
                    
                    // 显示证书信息（新增空值校验）
                    $('.cert-info')
                        .show()
                        .find('#cert-domain').text(res.domain || '待解析')  // 防止 domain 为空
                        .end()
                        .find('#cert-expiry').text(res.expiry_time || '待解析');  // 关键修复：空值时显示默认提示

                    // 切换按钮状态
                    $('.save-btn').hide();
                    $('.edit-btn').show();
                    $('.delete-btn').show();// 填充新增字段
                    
                   // 填充强制HTTPS状态
                   $('#force-https').prop('checked', res.force_https == 1);  // 非严格比较兼容字符串和数字类型
                } else {
                    // 证书不存在时清空输入框并设置提示（仅保留输入框重置逻辑）
                    $('#ssl-cert-content')
                        .val('')
                        .prop('readonly', false)
                        .attr('placeholder', '证书不存在，请上传新证书');
                    $('#ssl-key-content')
                        .val('')
                        .prop('readonly', false)
                        .attr('placeholder', '请上传对应私钥');
                    
                    // 隐藏证书信息，显示保存按钮（删除强制HTTPS和TLS版本的重置代码）
                    $('.cert-info').hide();
                    $('.save-btn').show();
                    $('.edit-btn').hide();
                    $('.delete-btn').hide();

                    // 新增：启用复选框（未保存时可编辑）
                    $('#force-https').prop('disabled', false);
                }
            })
            .fail(function(xhr) {
            showAlert('error',`请求证书接口失败（状态码：${xhr.status}，错误信息：${xhr.statusText}）`);
        });
    }

    // 保存按钮点击事件（修改校验逻辑）
    $('.save-btn').click(function() {
        const $btn = $(this);  // 缓存按钮对象
        $btn.prop('disabled', true).text('保存中...');  // 禁用按钮并修改文本
        const certContent = $('#ssl-cert-content').val().trim();
        const keyContent = $('#ssl-key-content').val().trim();
        // 新增：获取强制HTTPS状态（关键修复）
        const forceHttps = $('#force-https').prop('checked') ? 1 : 0;
    
        // 基础校验逻辑（保持不变）
        if (!certContent.includes('-----BEGIN CERTIFICATE-----') || 
            !certContent.includes('-----END CERTIFICATE-----')) {
            showAlert('error','证书格式不完整：需包含 -----BEGIN CERTIFICATE----- 和 -----END CERTIFICATE----- 标记');
            $btn.prop('disabled', false).text('保存');  // 校验失败时恢复按钮
            return;
        }
        if (!keyContent.includes('-----BEGIN ') || 
            !keyContent.includes('-----END ')) {
            showAlert('error','私钥格式不完整：需包含 -----BEGIN ...----- 和 -----END ...----- 标记');
            $btn.prop('disabled', false).text('保存');  // 校验失败时恢复按钮
            return;
        }
        if (!certContent || !keyContent || !currentDomain) {
            showAlert('error','请填写完整证书和私钥内容，且确保当前域名有效');
            $btn.prop('disabled', false).text('保存');  // 校验失败时恢复按钮
            return;
        }

        // 调用证书写入接口（修改：移除 tls_version 参数）
        $.post('/data/ssl-write.php', {
            domain: currentDomain,
            ssl_cert: certContent,
            ssl_key: keyContent,
            force_https: forceHttps,
            csrf_token: csrfToken  // 新增参数（关键修复）
        }, function(res) {
            if (res.success) {
                // 保存成功后的逻辑（保持不变）
                $('#force-https').prop('disabled', true);
                $('#ssl-cert-content, #ssl-key-content').prop('readonly', true);
                $('.cert-info')
                    .show()
                    .find('#cert-domain').text(currentDomain || '待解析')
                    .end()
                    .find('#cert-expiry').text(res.expiry_time || '待解析');
                $('.save-btn').hide();
                $('.edit-btn').show();
                showAlert('success',`保存成功！更新时间：${res.update_time || '未知'}，到期时间：${res.expiry_time || '待解析'}`);
            } else {
                showAlert('error',`保存失败：${res.message}`);
            }
        }, 'json').fail(function(xhr) {
            showAlert('error',`请求保存接口失败（状态码：${xhr.status}，错误信息：${xhr.statusText}）`);
        }).always(function() {
        $btn.prop('disabled', false).text('保存');  // 请求完成后恢复按钮
    });
});

    // 新增：删除按钮点击事件
    $('.delete-btn').click(function() {
        const $btn = $(this);  // 缓存按钮对象
        $btn.prop('disabled', true).text('删除中...');  // 禁用按钮并修改文本

        if (!currentDomain) {
            showAlert('error','当前无有效域名，无法删除');
            $btn.prop('disabled', false).text('删除');  // 校验失败时恢复按钮
            return;
        }

        if (!confirm(`确认删除域名 ${currentDomain} 的SSL证书？`)) {
            $btn.prop('disabled', false).text('删除');  // 用户取消时恢复按钮
            return;
        }
    
        // 调用删除接口（修改：添加 .always() 恢复按钮）
        $.post('/data/ssl-delete.php', { 
            domain: currentDomain,
            csrf_token: csrfToken  // 新增参数（关键修复）
        }, function(res) {
            if (res.success) {
                // 删除成功后的逻辑（保持不变）
                $('#ssl-cert-content, #ssl-key-content').val('').prop('readonly', false);
                $('.cert-info').hide();
                $('.save-btn').show();
                $('.edit-btn').hide();
                $('.delete-btn').hide();
                showAlert('success','证书删除成功');
            } else {
                showAlert('error',`删除失败：${res.message}`);
            }
        }, 'json').fail(function(xhr) {
            showAlert('error',`请求删除接口失败（状态码：${xhr.status}，错误信息：${xhr.statusText}）`);
        }).always(function() {
            $btn.prop('disabled', false).text('删除');  // 请求完成后恢复按钮
        });
    });

    // 修改按钮点击事件
    $('.edit-btn').click(function() {
        // 解除只读并切换按钮状态
        $('#ssl-cert-content, #ssl-key-content').prop('readonly', false);
        // 新增：启用复选框
        $('#force-https').prop('disabled', false);
        $('.cert-info').hide();
        $('.save-btn').show();
        $('.edit-btn').hide();
    });
});