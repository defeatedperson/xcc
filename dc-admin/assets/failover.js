$(document).ready(function() {
    // 获取CSRF令牌
    const csrfToken = $('#csrf-token').attr('content');
    
    // 初始加载域名列表
    loadDomainList();
    
    // 新增域名按钮点击事件
    $('#addDomainBtn').click(function() {
        showForm('add');
    });
    
    // 取消按钮点击事件
    $('#cancelBtn').click(function() {
        hideForm();
    });
    
    // 表单提交事件
    $('#failoverForm').submit(function(e) {
        e.preventDefault();
        saveDomain();
    });
    
    // 记录类型变更事件
    $('#mainType').change(function() {
        updatePlaceholder($(this).val(), $('#mainValue'));
    });
    
    $('#backupType').change(function() {
        updatePlaceholder($(this).val(), $('#backupValue'));
    });
    
    // 更新输入框提示文本
    function updatePlaceholder(type, $input) {
        switch(type) {
            case 'A':
                $input.attr('placeholder', '请输入IPv4地址，例如：192.168.1.1');
                break;
            case 'AAAA':
                $input.attr('placeholder', '请输入IPv6地址，例如：2001:db8::1');
                break;
            case 'CNAME':
                $input.attr('placeholder', '请输入域名，例如：example.com');
                break;
        }
    }
    
    // 加载域名列表
    function loadDomainList() {
        $.post('/data/xdm-set-domains.php', {
            action: 'get',
            csrf_token: csrfToken
        }, function(res) {
            if (res.success) {
                renderDomainList(res.data);
            } else {
                showAlert('error', '加载域名列表失败: ' + res.message);
            }
        }, 'json');
    }
    
    // 渲染域名列表
    function renderDomainList(data) {
        const $list = $('#failoverList');
        $list.empty();
        
        if (Object.keys(data).length === 0) {
            $list.html('<tr><td colspan="8" class="loading">暂无数据</td></tr>');
            return;
        }
        
        for (const id in data) {
            const domain = data[id];
            const statusText = domain.status ? '正常' : '故障';
            const statusClass = domain.status ? 'status-normal' : 'status-fault';
            
            const row = `
                <tr>
                    <td>${id}</td>
                    <td>${domain.host}</td>
                    <td>${domain.main_type}: ${domain.main_value}</td>
                    <td>${domain.backup_type}: ${domain.backup_value}</td>
                    <td>${domain.line}</td>
                    <td>${domain.jk_type === 'ping' ? 'PING' : 'HTTP'}</td>
                    <td class="${statusClass}">${statusText}</td>
                    <td>
                        <button class="btn-edit" data-id="${id}">编辑</button>
                        <button class="btn-delete" data-id="${id}">删除</button>
                    </td>
                </tr>
            `;
            
            $list.append(row);
        }
        
        // 绑定编辑按钮点击事件
        $('.btn-edit').click(function() {
            const id = $(this).data('id');
            editDomain(id, data[id]);
        });
        
        // 绑定删除按钮点击事件
        $('.btn-delete').click(function() {
            const id = $(this).data('id');
            deleteDomain(id);
        });
    }
    
    // 显示表单
    function showForm(type, domain = null) {
        // 重置表单
        $('#failoverForm')[0].reset();
        
        if (type === 'edit' && domain) {
            // 填充表单数据
            $('#domainId').val(domain.id);
            $('#host').val(domain.host);
            $('#mainType').val(domain.main_type);
            $('#mainValue').val(domain.main_value);
            $('#backupType').val(domain.backup_type);
            $('#backupValue').val(domain.backup_value);
            $('#line').val(domain.line);
            $('#jkType').val(domain.jk_type);
            
            // 更新输入框提示
            updatePlaceholder(domain.main_type, $('#mainValue'));
            updatePlaceholder(domain.backup_type, $('#backupValue'));
        } else {
            // 新增时清空ID
            $('#domainId').val('');
            
            // 设置默认提示
            updatePlaceholder('A', $('#mainValue'));
            updatePlaceholder('A', $('#backupValue'));
        }
        
        // 显示表单
        $('#failoverFormContainer').removeClass('hidden');
    }
    
    // 隐藏表单
    function hideForm() {
        $('#failoverFormContainer').addClass('hidden');
    }
    
    // 编辑域名
    function editDomain(id, domain) {
        domain.id = id;
        showForm('edit', domain);
    }
    
    // 删除域名
    function deleteDomain(id) {
        showConfirm('确定要删除此域名配置吗？', function() {
            $.post('/data/xdm-set-domains.php', {
                action: 'delete',
                csrf_token: csrfToken,
                id: id
            }, function(res) {
                if (res.success) {
                    showAlert('success', '域名配置删除成功');
                    loadDomainList();
                } else {
                    showAlert('error', '删除失败: ' + res.message);
                }
            }, 'json');
        });
    }
    
    // 验证IPv4地址
    function isValidIPv4(ip) {
        const ipv4Regex = /^(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/;
        return ipv4Regex.test(ip);
    }
    
    // 验证IPv6地址
    function isValidIPv6(ip) {
        const ipv6Regex = /^(([0-9a-fA-F]{1,4}:){7,7}[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,7}:|([0-9a-fA-F]{1,4}:){1,6}:[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,5}(:[0-9a-fA-F]{1,4}){1,2}|([0-9a-fA-F]{1,4}:){1,4}(:[0-9a-fA-F]{1,4}){1,3}|([0-9a-fA-F]{1,4}:){1,3}(:[0-9a-fA-F]{1,4}){1,4}|([0-9a-fA-F]{1,4}:){1,2}(:[0-9a-fA-F]{1,4}){1,5}|[0-9a-fA-F]{1,4}:((:[0-9a-fA-F]{1,4}){1,6})|:((:[0-9a-fA-F]{1,4}){1,7}|:)|fe80:(:[0-9a-fA-F]{0,4}){0,4}%[0-9a-zA-Z]{1,}|::(ffff(:0{1,4}){0,1}:){0,1}((25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])\.){3,3}(25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])|([0-9a-fA-F]{1,4}:){1,4}:((25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])\.){3,3}(25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9]))$/;
        return ipv6Regex.test(ip);
    }
    
    // 验证域名
    function isValidDomain(domain) {
        const domainRegex = /^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/;
        return domainRegex.test(domain);
    }
    
    // 验证host值
    function isValidHost(host) {
        const hostRegex = /^[a-zA-Z0-9.]{1,5}$/;
        return hostRegex.test(host);
    }
    
    // 保存域名
    function saveDomain() {
        const id = $('#domainId').val() || findAvailableId();
        // 如果没有可用ID，直接返回
        if (!id) return;
        const host = $('#host').val();
        const main_type = $('#mainType').val();
        const main_value = $('#mainValue').val();
        const backup_type = $('#backupType').val();
        const backup_value = $('#backupValue').val();
        const line = $('#line').val();
        const jk_type = $('#jkType').val();
        
        // 基本表单验证
        if (!host || !main_value || !backup_value || !line) {
            showAlert('error', '请填写完整的域名信息');
            return;
        }
        
        // host值格式验证
        if (!isValidHost(host)) {
            showAlert('error', 'host值只能包含字母、数字和小数点，且长度不能超过5位');
            return;
        }
        
        // 记录值格式验证
        let isValid = true;
        let errorMsg = '';
        
        // 主记录验证
        if (main_type === 'A' && !isValidIPv4(main_value)) {
            isValid = false;
            errorMsg = 'A记录值必须是有效的IPv4地址';
        } else if (main_type === 'AAAA' && !isValidIPv6(main_value)) {
            isValid = false;
            errorMsg = 'AAAA记录值必须是有效的IPv6地址';
        } else if (main_type === 'CNAME' && !isValidDomain(main_value)) {
            isValid = false;
            errorMsg = 'CNAME记录值必须是有效的域名';
        }
        
        // 备用记录验证
        if (isValid) {
            if (backup_type === 'A' && !isValidIPv4(backup_value)) {
                isValid = false;
                errorMsg = '备用A记录值必须是有效的IPv4地址';
            } else if (backup_type === 'AAAA' && !isValidIPv6(backup_value)) {
                isValid = false;
                errorMsg = '备用AAAA记录值必须是有效的IPv6地址';
            } else if (backup_type === 'CNAME' && !isValidDomain(backup_value)) {
                isValid = false;
                errorMsg = '备用CNAME记录值必须是有效的域名';
            }
        }
        
        if (!isValid) {
            showAlert('error', errorMsg);
            return;
        }
        
        // 提交数据
        $.post('/data/xdm-set-domains.php', {
            action: 'update',
            csrf_token: csrfToken,
            id: id,
            host: host,
            main_type: main_type,
            main_value: main_value,
            backup_type: backup_type,
            backup_value: backup_value,
            line: line,
            jk_type: jk_type
        }, function(res) {
            if (res.success) {
                showAlert('success', res.message);
                hideForm();
                loadDomainList();
            } else {
                showAlert('error', '保存失败: ' + res.message);
            }
        }, 'json');
    }
    
    // 查找可用的ID（1-20之间）
    function findAvailableId() {
        const $rows = $('#failoverList tr');
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