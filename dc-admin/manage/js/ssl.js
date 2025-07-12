$(function() {
    // 从 Meta 标签获取 CSRF 令牌
    const csrfToken = $('#csrf-token').attr('content');
    // 从父容器获取当前域名（通过 data-domain 属性传递）
    const currentDomain = $('#subFormContainer').data('domain');
    
    // 初始化时加载SSL配置和证书状态
    if (currentDomain) {
        loadSslConfig(currentDomain);
        checkCertStatus(currentDomain);
    }

    // 加载SSL配置函数
    function loadSslConfig(domain) {
        $.post('/data/ssl-certs.php', { 
            domain: domain,
            csrf_token: csrfToken
        })
        .done(function(res) {
            if (res.success) {
                // 填充配置并设置只读
                $('#https-enabled').prop('checked', res.https_enabled).prop('disabled', true);
                $('#force-https').prop('checked', res.force_https).prop('disabled', true);
                // 添加自动SSL相关代码
                $('#auto-ssl').prop('checked', res.auto_ssl).prop('disabled', true);
                
                // 切换按钮状态
                $('.save-btn').hide();
                $('.edit-btn').show();
            } else {
                // 配置不存在时启用编辑模式
                $('#https-enabled').prop('checked', false).prop('disabled', false);
                $('#force-https').prop('checked', false).prop('disabled', false);
                // 添加自动SSL相关代码 - 默认禁用，直到HTTPS被启用
                $('#auto-ssl').prop('checked', false).prop('disabled', true);
                
                $('.save-btn').show();
                $('.edit-btn').hide();
            }
        })
        .fail(function(xhr) {
            // 使用公共弹窗通知
            showAlert('error', `请求SSL配置接口失败（状态码：${xhr.status}，错误信息：${xhr.statusText}）`);
        });
    }

    // 检查证书状态函数
    function checkCertStatus(domain) {
        $.post('/data/ssl-read.php', { 
            domain: domain,
            csrf_token: csrfToken
        })
        .done(function(res) {
            if (res.success) {
                if (res.has_cert) {
                    // 证书存在
                    $('#cert-status-text').text('已安装').addClass('text-success').removeClass('text-danger');
                    
                    // 修复：直接使用返回的expiry_time字段
                    if (res.expiry_time) {
                        $('#cert-expiry').text(res.expiry_time);
                        
                        // 检查证书是否即将过期
                        const expiryDate = new Date(res.expiry_time);
                        const now = new Date();
                        const daysToExpiry = Math.floor((expiryDate - now) / (1000 * 60 * 60 * 24));
                        
                        if (daysToExpiry < 0) {
                            $('#cert-notice').text('证书已过期，请更新证书！').addClass('error').removeClass('warning success');
                        } else if (daysToExpiry < 30) {
                            $('#cert-notice').text(`证书将在 ${daysToExpiry} 天后过期，请及时更新！`).addClass('warning').removeClass('error success');
                        } else {
                            $('#cert-notice').text(`证书状态良好，距离过期还有 ${daysToExpiry} 天。`).addClass('success').removeClass('warning error');
                        }
                    }
                } else {
                    // 证书不存在
                    $('#cert-status-text').text('未安装').addClass('text-danger').removeClass('text-success');
                    $('#cert-expiry').text('未知');
                    $('#cert-notice').text('未检测到SSL证书').addClass('warning').removeClass('error success');
                }
            } else {
                $('#cert-status-text').text('检测失败').addClass('text-danger');
                $('#cert-notice').text(`证书状态检测失败：${res.message}`).addClass('error').removeClass('warning success');
            }
        })
        .fail(function(xhr) {
            $('#cert-status-text').text('检测失败').addClass('text-danger');
            $('#cert-notice').text(`证书状态检测失败（状态码：${xhr.status}）`).addClass('error').removeClass('warning success');
        });
    }

    // 添加HTTPS选项变更事件监听
    $('#https-enabled').on('change', function() {
        // 当HTTPS选项变更时，根据其状态启用或禁用自动SSL选项
        const httpsEnabled = $(this).prop('checked');
        if (httpsEnabled) {
            $('#auto-ssl').prop('disabled', false);
        } else {
            $('#auto-ssl').prop('checked', false).prop('disabled', true);
        }
    });

    // 保存按钮点击事件
    $('.save-btn').click(function() {
        const $btn = $(this);
        $btn.prop('disabled', true).text('保存中...');
        
        // 获取配置值
        const httpsEnabled = $('#https-enabled').prop('checked');
        let forceHttps = $('#force-https').prop('checked');
        // 添加自动SSL变量
        let autoSsl = $('#auto-ssl').prop('checked');
        
        // 添加逻辑验证：如果未启用HTTPS，则强制HTTPS选项无效
        if (!httpsEnabled && forceHttps) {
            forceHttps = false;
            $('#force-https').prop('checked', false);
            showAlert('warning', '未启用HTTPS时无法设置强制HTTPS，已自动取消勾选');
        }
        
        // 添加自动SSL相关检查代码
        if (!httpsEnabled && autoSsl) {
            autoSsl = false;
            $('#auto-ssl').prop('checked', false);
            showAlert('warning', '未启用HTTPS时无法启用自动SSL，已自动取消勾选');
        }
        
        // 保存配置
        saveConfig(httpsEnabled, forceHttps, autoSsl);
    });
    
    // 保存配置函数
    function saveConfig(httpsEnabled, forceHttps, autoSsl) {
        $.post('/data/ssl-certs.php', {
            domain: currentDomain,
            action: 'write',
            https_enabled: httpsEnabled,
            force_https: forceHttps,
            auto_ssl: autoSsl,
            csrf_token: csrfToken
        })
        .done(function(res) {
            if (res.success) {
                // 保存成功，禁用输入并显示编辑按钮
                $('#https-enabled').prop('disabled', true);
                $('#force-https').prop('disabled', true);
                // 添加自动SSL相关代码
                $('#auto-ssl').prop('disabled', true);
                $('.save-btn').hide();
                $('.edit-btn').show();
                
                // 使用公共弹窗通知
                showAlert('success', '域名HTTPS配置已更新');
                
                // 修复：保存成功后重新加载配置，确保显示最新状态
                loadSslConfig(currentDomain);
            } else {
                // 使用公共弹窗通知
                showAlert('error', `保存失败：${res.message}`);
            }
        })
        .fail(function(xhr) {
            // 使用公共弹窗通知
            showAlert('error', `请求保存接口失败（状态码：${xhr.status}，错误信息：${xhr.statusText}）`);
        })
        .always(function() {
            $('.save-btn').prop('disabled', false).text('保存');
        });
    }

    // 修改按钮点击事件
    $('.edit-btn').click(function() {
        // 启用编辑模式
        $('#https-enabled').prop('disabled', false);
        $('#force-https').prop('disabled', false);
        
        // 添加自动SSL相关代码 - 只有在HTTPS启用时才启用自动SSL选项
        const httpsEnabled = $('#https-enabled').prop('checked');
        $('#auto-ssl').prop('disabled', !httpsEnabled);
        
        // 切换按钮状态
        $('.save-btn').show();
        $('.edit-btn').hide();
    });
    
    // 证书管理按钮点击事件
    $('.cert-manage-btn').click(function() {
        // 跳转到证书管理页面
        window.location.href = '/dc-admin/certs.php';
    });
});