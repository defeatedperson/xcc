$(document).ready(function() {
    // 获取CSRF令牌
    const csrfToken = $('#csrf-token').attr('content');
    
    // 加载证书列表
    loadCertificates();
    
    // 加载证书列表函数
    function loadCertificates() {
        $.ajax({
            url: '/data/ssl-read.php',
            type: 'POST',
            data: {
                csrf_token: csrfToken
            },
            dataType: 'json',
            success: function(response) {
                $('#cert-loading').hide();
                
                if (response.success) {
                    if (response.domains && response.domains.length > 0) {
                        // 渲染证书列表
                        renderCertificates(response.domains);
                        $('#cert-list').show();
                    } else {
                        // 显示空数据提示
                        $('#cert-empty').show();
                    }
                } else {
                    // 显示错误信息
                    $('#cert-error').text('错误: ' + (response.message || '未知错误'));
                    $('#cert-error').show();
                }
            },
            error: function(xhr, status, error) {
                $('#cert-loading').hide();
                $('#cert-error').text('请求失败: ' + error);
                $('#cert-error').show();
            }
        });
    }
    
    // 渲染证书列表函数
    function renderCertificates(domains) {
        const tableBody = $('#cert-table-body');
        tableBody.empty();
        
        domains.forEach(function(cert, index) {
            // 计算证书状态
            const expiryDate = new Date(cert.expiry_time);
            const currentDate = new Date();
            const daysRemaining = Math.ceil((expiryDate - currentDate) / (1000 * 60 * 60 * 24));
            
            let status = '';
            let statusClass = '';
            
            if (cert.expiry_time === '未知') {
                status = '未知';
                statusClass = 'status-unknown';
            } else if (daysRemaining <= 0) {
                status = '已过期';
                statusClass = 'status-expired';
            } else if (daysRemaining <= 7) {
                status = '即将过期 (' + daysRemaining + '天)';
                statusClass = 'status-warning';
            } else if (daysRemaining <= 30) {
                status = '即将过期 (' + daysRemaining + '天)';
                statusClass = 'status-attention';
            } else {
                status = '有效 (' + daysRemaining + '天)';
                statusClass = 'status-valid';
            }
            
            // 创建表格行
            const row = $('<tr>');
            row.append($('<td>').text(index + 1));
            row.append($('<td>').text(cert.domain));
            row.append($('<td>').text(cert.expiry_time));
            row.append($('<td>').addClass(statusClass).text(status));
            
            tableBody.append(row);
        });
    }
    
    // 证书上传表单处理
    $('#cert-upload-form').on('submit', function(e) {
        e.preventDefault();
        
        // 获取表单数据
        const sslCert = $('#ssl_cert').val().trim();
        const sslKey = $('#ssl_key').val().trim();
        
        // 前端校验
        if (!validateCertificate(sslCert, sslKey)) {
            return;
        }
        
        // 显示上传中状态
        $('#upload-status').removeClass('success error').addClass('hidden');
        showAlert('info', '正在上传证书，请稍候...');
        
        // 发送AJAX请求
        $.ajax({
            url: '/data/ssl-write.php',
            type: 'POST',
            data: {
                csrf_token: csrfToken,
                ssl_cert: sslCert,
                ssl_key: sslKey
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // 上传成功
                    showAlert('success', '证书上传成功：' + response.domain);
                    $('#upload-status').text('证书上传成功，域名：' + response.domain + '，到期时间：' + response.expiry_time)
                        .removeClass('error').addClass('success').removeClass('hidden');
                    
                    // 清空表单
                    $('#cert-upload-form')[0].reset();
                    
                    // 重新加载证书列表
                    setTimeout(loadCertificates, 1000);
                } else {
                    // 上传失败
                    showAlert('error', '证书上传失败：' + (response.message || '未知错误'));
                    $('#upload-status').text('证书上传失败：' + (response.message || '未知错误'))
                        .removeClass('success').addClass('error').removeClass('hidden');
                }
            },
            error: function(xhr, status, error) {
                // 请求错误
                showAlert('error', '请求失败：' + error);
                $('#upload-status').text('请求失败：' + error)
                    .removeClass('success').addClass('error').removeClass('hidden');
            }
        });
    });
    
    // 清空表单按钮
    $('#clear-form').on('click', function() {
        $('#cert-upload-form')[0].reset();
        $('#upload-status').addClass('hidden');
    });
    
    // 证书和私钥格式校验函数
    function validateCertificate(cert, key) {
        // 检查证书格式
        if (!cert.includes('-----BEGIN CERTIFICATE-----') || !cert.includes('-----END CERTIFICATE-----')) {
            showAlert('error', '证书格式不正确，缺少BEGIN CERTIFICATE或END CERTIFICATE标记');
            return false;
        }
        
        // 检查私钥格式（支持多种私钥类型）
        if (!key.includes('-----BEGIN ') || !key.includes('-----END ')) {
            showAlert('error', '私钥格式不正确，缺少BEGIN或END标记');
            return false;
        }
        
        return true;
    }
});