$(document).ready(function() {
    // 获取CSRF令牌
    const csrfToken = $('#csrf-token').attr('content');
    
    // 初始加载节点状态
    loadNodeStatus();
    
    // 切换API密钥显示/隐藏
    $('#toggleApiKey').click(function() {
        const isHidden = $('#apikeyForm').hasClass('hidden');
        
        if (isHidden) {
            // 显示表单并加载API密钥
            $('#apikeyForm').removeClass('hidden');
            $(this).text('取消');
            
            // 加载API密钥
            $.post('/data/xdm-set-xdm.php', {
                action: 'get_apikey',
                csrf_token: csrfToken
            }, function(res) {
                if (res.success) {
                    $('#apikey').val(res.data.apikey);
                    $('#nodekey').val(res.data.nodekey);
                    $('#serviceUrl').val(res.data.service_url);
                } else {
                    // 使用公共弹窗组件替换alert
                    showAlert('error', '获取API密钥失败: ' + res.message);
                }
            }, 'json');
        } else {
            // 隐藏表单
            $('#apikeyForm').addClass('hidden');
            $(this).text('设置');
        }
    });
    
    // 生成随机密钥函数
    function generateRandomKey(length = 16) {
        const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        let result = '';
        for (let i = 0; i < length; i++) {
            result += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return result;
    }
    
    // API密钥随机生成按钮点击事件
    $('#generateApiKey').click(function() {
        $('#apikey').val(generateRandomKey());
    });
    
    // 节点密钥随机生成按钮点击事件
    $('#generateNodeKey').click(function() {
        $('#nodekey').val(generateRandomKey());
    });
    
    // 提交API密钥表单
    $('#apikeyForm').submit(function(e) {
        e.preventDefault();
        
        const apikey = $('#apikey').val();
        const nodekey = $('#nodekey').val();
        const serviceUrl = $('#serviceUrl').val();
        
        if (!apikey || !nodekey) {
            // 使用公共弹窗组件替换alert
            showAlert('error', '请填写完整的API密钥信息');
            return;
        }
        
        if (apikey.length < 8 || nodekey.length < 8) {
            // 使用公共弹窗组件替换alert
            showAlert('error', '密钥长度必须大于等于8个字符');
            return;
        }
        
        // 验证服务地址格式（如果提供）
        if (serviceUrl && !isValidUrl(serviceUrl)) {
            showAlert('error', '服务地址格式无效，请使用正确的URL格式，例如：https://example.com[:端口号]');
            return;
        }
        
        // 使用确认弹窗替换直接提交
        showConfirm('确定要更新API密钥吗？这将影响所有节点的连接。', function() {
            // 提交更新
            $.post('/data/xdm-set-xdm.php', {
                action: 'set_apikey',
                csrf_token: csrfToken,
                apikey: apikey,
                nodekey: nodekey,
                service_url: serviceUrl
            }, function(res) {
                if (res.success) {
                    // 使用公共弹窗组件替换alert
                    showAlert('success', 'API密钥配置保存成功');
                    // 隐藏表单
                    $('#apikeyForm').addClass('hidden');
                    $('#toggleApiKey').text('设置');
                } else {
                    // 使用公共弹窗组件替换alert
                    showAlert('error', 'API密钥配置保存失败: ' + res.message);
                }
            }, 'json');
        });
    });
    
    // URL验证函数
    function isValidUrl(url) {
        try {
            new URL(url);
            return true;
        } catch (e) {
            return false;
        }
    }
    
    // 加载节点状态函数
    function loadNodeStatus() {
        $.post('/data/xdm-set-xdm.php', {
            action: 'get',
            csrf_token: csrfToken
        }, function(res) {
            if (res.success) {
                renderNodeStatus(res.data);
            } else {
                console.error('获取节点状态失败:', res.message);
                // 使用公共弹窗组件显示错误
                showAlert('error', '获取节点状态失败: ' + res.message);
            }
        }, 'json');
    }
    
    // 渲染节点状态函数
    function renderNodeStatus(data) {
        const statusData = data.default.status_data;
        const updateTime = data.default.update_time;
        
        // 清空现有列表
        const $faultList = $('#faultNodesList');
        $faultList.empty();
        
        // 获取故障节点
        const faultNodes = Object.keys(statusData).filter(nodeId => !statusData[nodeId]);
        
        // 渲染故障节点
        if (faultNodes.length === 0) {
            $faultList.append('<div class="node-item">暂无故障节点</div>');
        } else {
            faultNodes.sort().forEach(nodeId => {
                $faultList.append(`<div class="node-item">节点 ${nodeId}</div>`);
            });
        }
        
        // 更新时间
        $('#updateTime').text('最后更新: ' + updateTime);
    }
});