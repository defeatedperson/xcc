// 节点管理相关JS逻辑（独立文件）
window.onload = function() {
    // 初始化选中节点ID存储变量（全局可访问）
    window.selectedNodeId = null;
    // 从meta标签获取CSRF令牌（与dashboard.js一致）
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    // CSRF令牌空值校验（安全增强）
    if (!csrfToken) {
        showError('CSRF令牌未获取，请刷新页面或重新登录');
        return;
    }
    // 加载节点ID列表
    loadNodeIds(csrfToken);
};

// 加载节点ID列表函数
function loadNodeIds(csrfToken) {
    const selectEl = document.getElementById('domainSelect');
    // 调用node-ctrl-read.php接口（POST请求）
    fetch('/api/node-ctrl-read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams({ csrf_token: csrfToken })
    })
    .then(response => {
        if (!response.ok) throw new Error(`HTTP错误：${response.status}`);
        return response.json();
    })
    .then(data => {
        if (!data.success) throw new Error(data.message);
        // 清空原有选项（保留默认提示）
        selectEl.innerHTML = '<option value="">选择节点</option>';
        // 动态添加节点ID选项
        if (data.node_ids.length > 0) {
            data.node_ids.forEach(nodeId => {
                const option = document.createElement('option');
                option.value = nodeId;
                option.textContent = `节点ID: ${nodeId}`;
                selectEl.appendChild(option);
            });
        } else {
            selectEl.innerHTML += '<option value="" disabled>无可用节点</option>';
        }
        // 在select的change事件监听中添加逻辑
        selectEl.addEventListener('change', (e) => {
            window.selectedNodeId = e.target.value || null;
            const defaultPrompt = document.getElementById('defaultPrompt');
            const operationArea = document.getElementById('operationArea');
            if (window.selectedNodeId) {
                // 选中节点：隐藏提示，显示操作区域
                defaultPrompt.style.display = 'none';
                operationArea.style.display = 'block';
            } else {
                // 未选中节点：显示提示，隐藏操作区域
                defaultPrompt.style.display = 'block';
                operationArea.style.display = 'none';
            }
        });
    })
    .catch(error => {
        showError(`节点加载失败：${error.message}`);
        selectEl.innerHTML = '<option value="" disabled>节点加载失败</option>';
    });
}
// 替换 showError 为 showAlert
function showError(message) {
    showAlert('error', message);
}

// 节点操作按钮事件
document.addEventListener('DOMContentLoaded', function() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const operationArea = document.getElementById('operationArea');
    const logNode = document.querySelector('.log-node');
    const logTypeRadios = document.getElementsByName('log-type');
    const refreshBtn = document.querySelector('.btn-new');

    // 节点操作（启动/停止/重启）
    operationArea.addEventListener('click', function(e) {
        if (e.target.classList.contains('btn')) {
            let command = '';
            if (e.target.classList.contains('btn-start')) command = 'start';
            if (e.target.classList.contains('btn-stop')) command = 'stop';
            if (e.target.classList.contains('btn-restart')) command = 'reload';
            if (!command || !window.selectedNodeId) return;

            e.target.disabled = true;
            showAlert('info', '正在操作，请稍等10秒...');
            fetch('/api/node-ctrl.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    csrf_token: csrfToken,
                    node_id: window.selectedNodeId,
                    command: command
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success || data.status === 'success') {
                    showAlert('success', '操作成功');
                } else {
                    showAlert('error', '操作失败：' + (data.message || '未知错误'));
                }
            })
            .catch(err => showAlert('error', '请求失败：' + err))
            .finally(() => { e.target.disabled = false; });
        }
    });

    // 日志刷新按钮
    refreshBtn.addEventListener('click', function() {
        if (!window.selectedNodeId) {
            logNode.textContent = '请先选择节点';
            return;
        }
        // 获取当前选中的日志类型
        let logType = 'go';
        for (const radio of logTypeRadios) {
            if (radio.checked) {
                logType = radio.value === 'error' ? 'error' : 'go';
                break;
            }
        }
        logNode.textContent = '日志加载中...';
        showAlert('info', '正在获取日志，请稍等10秒...');
        fetch('/api/node-ctrl.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                csrf_token: csrfToken,
                node_id: window.selectedNodeId,
                action: 'log_query',
                log_type: logType
            })
        })
        .then(res => res.json().catch(() => null))
        .then(data => {
            if (!data) {
                logNode.textContent = '日志解析失败';
                showAlert('error', '日志解析失败');
            } else if (data.success === false && data.message) {
                logNode.textContent = '日志获取失败：' + data.message;
                showAlert('error', '日志获取失败：' + data.message);
            } else if (typeof data === 'string') {
                logNode.textContent = data;
                showAlert('success', '日志获取成功');
            } else if (data.data && typeof data.data === 'string') {
                logNode.textContent = data.data;
                showAlert('success', '日志获取成功');
            } else {
                logNode.textContent = JSON.stringify(data, null, 2);
                showAlert('success', '日志获取成功');
            }
        })
        .catch(err => {
            logNode.textContent = '日志请求失败：' + err;
            showAlert('error', '日志请求失败：' + err);
        });
    });
});