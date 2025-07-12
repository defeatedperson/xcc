$(document).ready(function() {
    // 获取CSRF令牌
    const csrfToken = $('#csrf-token').attr('content');
    
    // 初始加载SSL域名列表
    loadSslDomainList();
    
    // 新增域名按钮点击事件
    $('#addSslDomainBtn').click(function() {
        // 加载已开启自动SSL的域名列表
        loadAutoSslEnabledDomains();
        showSslForm('add');
    });
    
    // 取消按钮点击事件
    $('#sslCancelBtn').click(function() {
        hideSslForm();
    });
    
    // 表单提交事件
    $('#sslForm').submit(function(e) {
        e.preventDefault();
        saveSslDomain();
    });
    
    // 加载已开启自动SSL的域名列表
    function loadAutoSslEnabledDomains() {
        $.post('/data/ssl-certs.php', {
            action: 'list_auto_ssl',
            csrf_token: csrfToken
        }, function(res) {
            if (res.success) {
                populateDomainDropdown(res.data);
            } else {
                showAlert('error', '加载已开启自动SSL的域名列表失败: ' + res.message);
            }
        }, 'json');
    }
    
    // 填充域名下拉框
    function populateDomainDropdown(domains) {
        const $select = $('#sslDomain');
        // 清空除了第一个选项外的所有选项
        $select.find('option:not(:first)').remove();
        
        // 获取已添加的域名列表
        const existingDomains = getExistingSslDomains();
        
        // 添加未被添加过的域名到下拉框
        let availableDomains = 0;
        domains.forEach(function(domain) {
            // 检查域名是否已经添加过
            if (!existingDomains.includes(domain)) {
                $select.append(`<option value="${domain}">${domain}</option>`);
                availableDomains++;
            }
        });
        
        // 如果没有可用域名，显示提示
        if (availableDomains === 0) {
            $select.append('<option value="" disabled>没有可添加的域名</option>');
            showAlert('info', '所有已开启自动SSL的域名都已添加');
        }
    }
    
    // 获取已添加的SSL域名列表
    function getExistingSslDomains() {
        const domains = [];
        $('#sslDomainList tr').each(function() {
            const domain = $(this).find('td:eq(1)').text().trim();
            if (domain && domain !== '暂无数据') {
                domains.push(domain);
            }
        });
        return domains;
    }
    
    // 加载SSL域名列表
    function loadSslDomainList() {
        $.post('/data/xdm-set-ssl.php', {
            action: 'get',
            csrf_token: csrfToken
        }, function(res) {
            if (res.success) {
                renderSslDomainList(res.data);
                checkCertificates(res.data);
            } else {
                showAlert('error', '加载SSL域名列表失败: ' + res.message);
            }
        }, 'json');
    }
    
    // 渲染SSL域名列表
    function renderSslDomainList(data) {
        const $list = $('#sslDomainList');
        $list.empty();
        
        if (Object.keys(data).length === 0) {
            $list.html('<tr><td colspan="5" class="loading">暂无数据</td></tr>');
            return;
        }
        
        for (const id in data) {
            const domain = data[id];
            
            const row = `
                <tr>
                    <td>${id}</td>
                    <td>${domain}</td>
                    <td id="cert-status-${id}">检查中...</td>
                    <td id="cert-expiry-${id}">--</td>
                    <td>
                        <button class="btn-delete" data-id="${id}">删除</button>
                    </td>
                </tr>
            `;
            
            $list.append(row);
        }
        
        // 绑定删除按钮点击事件
        $('.btn-delete').click(function() {
            const id = $(this).data('id');
            deleteSslDomain(id);
        });
    }
    
    // 检查证书状态
    function checkCertificates(domains) {
        for (const id in domains) {
            const domain = domains[id];
            
            $.post('/data/ssl-read.php', {
                domain: domain,
                csrf_token: csrfToken
            }, function(res) {
                if (res.success) {
                    const statusCell = $(`#cert-status-${id}`);
                    const expiryCell = $(`#cert-expiry-${id}`);
                    
                    if (res.has_cert) {
                        statusCell.html('<span class="status-normal">已安装</span>');
                        expiryCell.text(res.expiry_time);
                    } else {
                        statusCell.html('<span class="status-fault">未安装</span>');
                        expiryCell.text('--');
                    }
                } else {
                    $(`#cert-status-${id}`).html('<span class="status-fault">检查失败</span>');
                }
            }, 'json');
        }
    }
    
    // 显示SSL表单
    function showSslForm(type, id = null, domain = null) {
        // 重置表单
        $('#sslForm')[0].reset();
        
        if (type === 'edit' && id && domain) {
            // 填充表单数据
            $('#sslDomainId').val(id);
            $('#sslDomain').val(domain);
        } else {
            // 新增时清空ID
            $('#sslDomainId').val('');
        }
        
        // 显示表单
        $('#sslFormContainer').removeClass('hidden');
    }
    
    // 隐藏SSL表单
    function hideSslForm() {
        $('#sslFormContainer').addClass('hidden');
    }
    
    // 删除SSL域名
    function deleteSslDomain(id) {
        showConfirm('确定要删除此SSL域名配置吗？', function() {
            $.post('/data/xdm-set-ssl.php', {
                action: 'delete',
                csrf_token: csrfToken,
                id: id
            }, function(res) {
                if (res.success) {
                    showAlert('success', 'SSL域名配置删除成功');
                    loadSslDomainList();
                } else {
                    showAlert('error', '删除失败: ' + res.message);
                }
            }, 'json');
        });
    }
    
    // 保存SSL域名
    function saveSslDomain() {
        const domain = $('#sslDomain').val();
        
        // 基本表单验证
        if (!domain) {
            showAlert('error', '请选择域名');
            return;
        }
        
        const id = $('#sslDomainId').val() || findAvailableId();
        // 如果没有可用ID，直接返回
        if (!id) return;
        
        // 提交数据
        $.post('/data/xdm-set-ssl.php', {
            action: 'update',
            csrf_token: csrfToken,
            id: id,
            domain: domain
        }, function(res) {
            if (res.success) {
                showAlert('success', res.message);
                hideSslForm();
                loadSslDomainList();
            } else {
                showAlert('error', '保存失败: ' + res.message);
            }
        }, 'json');
    }
    
    // 查找可用的ID（1-20之间）
    function findAvailableId() {
        const $rows = $('#sslDomainList tr');
        const usedIds = new Set();
        
        // 收集已使用的ID
        $rows.each(function() {
            const id = $(this).find('td:first').text();
            if (id && !isNaN(id)) {
                usedIds.add(parseInt(id));
            }
        });
        
        // 查找1-20之间第一个未使用的ID
        for (let i = 1; i <= 20; i++) {
            if (!usedIds.has(i)) {
                return i.toString();
            }
        }
        
        // 如果1-20都已使用，返回错误
        showAlert('error', '无法添加更多配置，已达到最大限制（20个）');
        return null;
    }
});