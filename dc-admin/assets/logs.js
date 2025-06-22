// 页面加载完成后初始化
window.addEventListener('DOMContentLoaded', () => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const generateLogContent = document.getElementById('generateLogContent');
    // 新增：删除日志容器
    const deleteLogContent = document.getElementById('deleteLogContent');
    const clearLogContent = document.getElementById('clearLogContent');
    const rejectLogContent = document.getElementById('rejectLogContent');
    const sqlLogContent = document.getElementById('sqlLogContent');
    const loginLogContent = document.getElementById('loginLogContent');
    
    // 初始化默认提示（替代自动加载）
    generateLogContent.textContent = '请点击刷新获取最新日志内容';
    deleteLogContent.textContent = '请点击刷新获取最新日志内容';
    syncLogContent.textContent = '请点击刷新获取最新日志内容';
    clearLogContent.textContent = '请点击刷新获取最新日志内容';
    rejectLogContent.textContent = '请点击刷新获取最新日志内容';
    sqlLogContent.textContent = '请点击刷新获取最新日志内容';
    loginLogContent.textContent = '请点击刷新获取最新日志内容';

    // 加载登录日志
    const loadLoginLog = async () => {
        loginLogContent.textContent = '登录日志加载中...';
        try {
            const response = await fetch('/auth/login-log/center.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    csrf_token: csrfToken,
                    log_type: 'login' // 这里必须是 log_type
                })
            });
            if (!response.ok) throw new Error('网络请求失败');
            const data = await response.json();
            if (!data.success) {
                const errorMsg = data.message === '日志过大，无法预览'
                    ? '警告：登录日志文件超过1MB，无法预览'
                    : `错误：${data.message}`;
                loginLogContent.textContent = errorMsg;
                return;
            }
            loginLogContent.textContent = data.log_content;
            loginLogContent.scrollTop = loginLogContent.scrollHeight;
        } catch (error) {
            loginLogContent.textContent = `加载异常：${error.message}`;
        }
    };

    // 下载登录日志
    const downloadLoginLog = async () => {
        try {
            const response = await fetch('/auth/login-log/center.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    csrf_token: csrfToken,
                    action: 'download',
                    log_type: 'login' // 这里必须是 log_type
                })
            });
            if (!response.ok) throw new Error('网络请求失败');
            const data = await response.json();
            if (!data.success) {
                alert(`下载失败：${data.message}`);
                return;
            }
            const blob = new Blob([data.log_content], { type: 'text/plain' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = data.filename || 'login.log';
            a.click();
            URL.revokeObjectURL(url);
        } catch (error) {
            alert(`下载异常：${error.message}`);
        }
    };

    // 清空登录日志
    const clearLoginLog = async () => {
        loginLogContent.textContent = '登录日志清理中...';
        try {
            const response = await fetch('/auth/login-log/center.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    csrf_token: csrfToken,
                    action: 'clear',
                    log_type: 'login'
                })
            });
            if (!response.ok) throw new Error('网络请求失败');
            const data = await response.json();
            loginLogContent.textContent = data.success ? '登录日志已清空' : `清理失败：${data.message}`;
        } catch (error) {
            loginLogContent.textContent = `清理异常：${error.message}`;
        }
    };

    // 生成日志加载函数（独立函数便于扩展）
    const loadGenerateLog = async () => {
        generateLogContent.textContent = '生成日志加载中...'; // 点击后显示加载状态

        try {
            const response = await fetch('/data/logs/center.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({ 
                    csrf_token: csrfToken,
                    log_type: 'conf-center'  // 新增：指定生成日志类型
                })
            });

            if (!response.ok) throw new Error('网络请求失败');
            const data = await response.json();

            // 处理中心接口返回的错误（包括文件过大）
            if (!data.success) {
                // 特殊处理大文件提示
                const errorMsg = data.message === '日志过大，无法预览'
                    ? '警告：日志文件超过1MB，无法预览'
                    : `错误：${data.message}`;
                generateLogContent.textContent = errorMsg;
                return;
            }

            // 成功加载日志内容
            generateLogContent.textContent = data.log_content;
            generateLogContent.scrollTop = generateLogContent.scrollHeight; // 自动滚动到底部
        } catch (error) {
            generateLogContent.textContent = `加载异常：${error.message}`;
        }
    };
    // 新增：删除日志加载函数
    const loadDeleteLog = async () => {
        deleteLogContent.textContent = '删除日志加载中...';
        try {
            const response = await fetch('/data/logs/center.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({ 
                    csrf_token: csrfToken,
                    log_type: 'delete'  // 指定删除日志类型
                })
            });

            if (!response.ok) throw new Error('网络请求失败');
            const data = await response.json();

            if (!data.success) {
                const errorMsg = data.message === '日志过大，无法预览'
                    ? '警告：删除日志文件超过1MB，无法预览'
                    : `错误：${data.message}`;
                deleteLogContent.textContent = errorMsg;
                return;
            }

            deleteLogContent.textContent = data.log_content;
            deleteLogContent.scrollTop = deleteLogContent.scrollHeight;
        } catch (error) {
            deleteLogContent.textContent = `加载异常：${error.message}`;
        }
    };

    // 新增：同步日志加载函数
    const loadSyncLog = async () => {
        const syncLogContent = document.getElementById('syncLogContent');
        syncLogContent.textContent = '同步日志加载中...';

        try {
            const response = await fetch('/api/logs/center.php', { 
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({ 
                    csrf_token: csrfToken,
                    log_type: 'update'  // 指定同步日志类型
                })
            });

            if (!response.ok) throw new Error('网络请求失败');
            const data = await response.json();

            if (!data.success) {
                const errorMsg = data.message === '日志过大，无法预览'
                    ? '警告：同步日志文件超过1MB，无法预览'
                    : `错误：${data.message}`;
                syncLogContent.textContent = errorMsg;
                return;
            }

            syncLogContent.textContent = data.log_content;
            syncLogContent.scrollTop = syncLogContent.scrollHeight;
        } catch (error) {
            syncLogContent.textContent = `加载异常：${error.message}`;
        }
    };

    // 新增：下载日志函数
    const downloadGenerateLog = async () => {
        try {
            const response = await fetch('/data/logs/center.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({ 
                    csrf_token: csrfToken,
                    action: 'download',
                    log_type: 'conf-center'  // 新增：指定生成日志类型
                })
            });

            if (!response.ok) throw new Error('网络请求失败');
            const data = await response.json();

            if (!data.success) {
                alert(`下载失败：${data.message}`);
                return;
            }

            // 创建Blob并触发文件下载
            const blob = new Blob([data.log_content], { type: 'text/plain' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = data.filename || 'request.log';  // 使用后端返回的文件名
            a.click();
            URL.revokeObjectURL(url);  // 释放内存
        } catch (error) {
            alert(`下载异常：${error.message}`);
        }
    };
    // 新增：删除日志下载函数
    const downloadDeleteLog = async () => {
        try {
            const response = await fetch('/data/logs/center.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({ 
                    csrf_token: csrfToken,
                    action: 'download',
                    log_type: 'delete'  // 指定删除日志类型
                })
            });

            if (!response.ok) throw new Error('网络请求失败');
            const data = await response.json();

            if (!data.success) {
                alert(`下载失败：${data.message}`);
                return;
            }

            const blob = new Blob([data.log_content], { type: 'text/plain' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = data.filename || 'delete.log';
            a.click();
            URL.revokeObjectURL(url);
        } catch (error) {
            alert(`下载异常：${error.message}`);
        }
    };
    // 修正下载同步日志函数（统一接口路径）
    const downloadSyncLog = async () => {
        try {
            const response = await fetch('/api/logs/center.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    csrf_token: csrfToken,
                    action: 'download',
                    log_type: 'update'  // 指定同步日志类型
                })
            });

            if (!response.ok) throw new Error('网络请求失败');
            const data = await response.json();

            if (!data.success) {
                alert(`下载失败：${data.message}`);
                return;
            }

            const blob = new Blob([data.log_content], { type: 'text/plain' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = data.filename || 'sync.log';
            a.click();
            URL.revokeObjectURL(url);
        } catch (error) {
            alert(`下载异常：${error.message}`);
        }
    };

    // 新增：清空同步日志函数
    const clearSyncLog = async () => {
        const syncLogContent = document.getElementById('syncLogContent');
        syncLogContent.textContent = '同步日志清理中...';

        try {
            const response = await fetch('/api/logs/center.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    csrf_token: csrfToken,
                    action: 'clear',
                    log_type: 'update'  // 指定同步日志类型
                })
            });

            if (!response.ok) throw new Error('网络请求失败');
            const data = await response.json();

            syncLogContent.textContent = data.success ? '同步日志已清空' : `清理失败：${data.message}`;
        } catch (error) {
            syncLogContent.textContent = `清理异常：${error.message}`;
        }
    };

    // 绑定刷新按钮事件（仅点击时触发加载）
    document.getElementById('refreshGenerateLogBtn').addEventListener('click', loadGenerateLog);
    // 新增：绑定下载按钮事件（注意对应logs.php中的按钮ID）
    document.getElementById('dwGenerateLogBtn').addEventListener('click', downloadGenerateLog);

    // 新增：绑定删除日志按钮
    document.getElementById('refreshDeleteLogBtn').addEventListener('click', loadDeleteLog);
    document.getElementById('dwDeleteLogBtn').addEventListener('click', downloadDeleteLog);

    // 绑定同步日志按钮（使用修正后的ID）
    document.getElementById('refreshSyncLogBtn').addEventListener('click', loadSyncLog);
    document.getElementById('dwSyncLogBtn').addEventListener('click', downloadSyncLog);  // 修正按钮ID绑定
    document.getElementById('clearSyncLogBtn').addEventListener('click', clearSyncLog);  // 新增清空按钮绑定




    //第四个日志（含之后）的内容
    // 缓存清理日志加载函数
    const loadClearLog = async () => {
        clearLogContent.textContent = '缓存清理日志加载中...';
        try {
            const response = await fetch('/api/logs/center.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    csrf_token: csrfToken,
                    log_type: 'clear'
                })
            });
            if (!response.ok) throw new Error('网络请求失败');
            const data = await response.json();
            if (!data.success) {
                const errorMsg = data.message === '日志过大，无法预览'
                    ? '警告：缓存清理日志文件超过1MB，无法预览'
                    : `错误：${data.message}`;
                clearLogContent.textContent = errorMsg;
                return;
            }
            clearLogContent.textContent = data.log_content;
            clearLogContent.scrollTop = clearLogContent.scrollHeight;
        } catch (error) {
            clearLogContent.textContent = `加载异常：${error.message}`;
        }
    };

    // 下载缓存清理日志
    const downloadClearLog = async () => {
        try {
            const response = await fetch('/api/logs/center.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    csrf_token: csrfToken,
                    action: 'download',
                    log_type: 'clear'
                })
            });
            if (!response.ok) throw new Error('网络请求失败');
            const data = await response.json();
            if (!data.success) {
                alert(`下载失败：${data.message}`);
                return;
            }
            const blob = new Blob([data.log_content], { type: 'text/plain' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = data.filename || 'clear.log';
            a.click();
            URL.revokeObjectURL(url);
        } catch (error) {
            alert(`下载异常：${error.message}`);
        }
    };

    // 清空缓存清理日志
    const clearClearLog = async () => {
        clearLogContent.textContent = '缓存清理日志清理中...';
        try {
            const response = await fetch('/api/logs/center.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    csrf_token: csrfToken,
                    action: 'clear',
                    log_type: 'clear'
                })
            });
            if (!response.ok) throw new Error('网络请求失败');
            const data = await response.json();
            clearLogContent.textContent = data.success ? '缓存清理日志已清空' : `清理失败：${data.message}`;
        } catch (error) {
            clearLogContent.textContent = `清理异常：${error.message}`;
        }
    };

    // 绑定按钮事件
    document.getElementById('refreshClearLogBtn').addEventListener('click', loadClearLog);
    document.getElementById('dwClearLogBtn').addEventListener('click', downloadClearLog);
    document.getElementById('clearClearLogBtn').addEventListener('click', clearClearLog);

    // 加载拒绝任务日志
    const loadRejectLog = async () => {
        rejectLogContent.textContent = '拒绝任务日志加载中...';
        try {
            const response = await fetch('/api/logs/center.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    csrf_token: csrfToken,
                    log_type: 'reject'
                })
            });
            if (!response.ok) throw new Error('网络请求失败');
            const data = await response.json();
            if (!data.success) {
                const errorMsg = data.message === '日志过大，无法预览'
                    ? '警告：拒绝任务日志文件超过1MB，无法预览'
                    : `错误：${data.message}`;
                rejectLogContent.textContent = errorMsg;
                return;
            }
            rejectLogContent.textContent = data.log_content;
            rejectLogContent.scrollTop = rejectLogContent.scrollHeight;
        } catch (error) {
            rejectLogContent.textContent = `加载异常：${error.message}`;
        }
    };

    // 下载拒绝任务日志
    const downloadRejectLog = async () => {
        try {
            const response = await fetch('/api/logs/center.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    csrf_token: csrfToken,
                    action: 'download',
                    log_type: 'reject'
                })
            });
            if (!response.ok) throw new Error('网络请求失败');
            const data = await response.json();
            if (!data.success) {
                alert(`下载失败：${data.message}`);
                return;
            }
            const blob = new Blob([data.log_content], { type: 'text/plain' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = data.filename || 'reject.log';
            a.click();
            URL.revokeObjectURL(url);
        } catch (error) {
            alert(`下载异常：${error.message}`);
        }
    };

    // 清空拒绝任务日志
    const clearRejectLog = async () => {
        rejectLogContent.textContent = '拒绝任务日志清理中...';
        try {
            const response = await fetch('/api/logs/center.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    csrf_token: csrfToken,
                    action: 'clear',
                    log_type: 'reject'
                })
            });
            if (!response.ok) throw new Error('网络请求失败');
            const data = await response.json();
            rejectLogContent.textContent = data.success ? '拒绝任务日志已清空' : `清理失败：${data.message}`;
        } catch (error) {
            rejectLogContent.textContent = `清理异常：${error.message}`;
        }
    };

    // 绑定按钮事件
    document.getElementById('refreshRejectLogBtn').addEventListener('click', loadRejectLog);
    document.getElementById('dwRejectLogBtn').addEventListener('click', downloadRejectLog);
    document.getElementById('clearRejectLogBtn').addEventListener('click', clearRejectLog);

    // 加载节点提交日志
    const loadSqlLog = async () => {
        sqlLogContent.textContent = '节点提交日志加载中...';
        try {
            const response = await fetch('/api/logs/center.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    csrf_token: csrfToken,
                    log_type: 'sql'
                })
            });
            if (!response.ok) throw new Error('网络请求失败');
            const data = await response.json();
            if (!data.success) {
                const errorMsg = data.message === '日志过大，无法预览'
                    ? '警告：节点提交日志文件超过1MB，无法预览'
                    : `错误：${data.message}`;
                sqlLogContent.textContent = errorMsg;
                return;
            }
            sqlLogContent.textContent = data.log_content;
            sqlLogContent.scrollTop = sqlLogContent.scrollHeight;
        } catch (error) {
            sqlLogContent.textContent = `加载异常：${error.message}`;
        }
    };

    // 下载节点提交日志
    const downloadSqlLog = async () => {
        try {
            const response = await fetch('/api/logs/center.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    csrf_token: csrfToken,
                    action: 'download',
                    log_type: 'sql'
                })
            });
            if (!response.ok) throw new Error('网络请求失败');
            const data = await response.json();
            if (!data.success) {
                alert(`下载失败：${data.message}`);
                return;
            }
            const blob = new Blob([data.log_content], { type: 'text/plain' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = data.filename || 'log_to_sql_error.log';
            a.click();
            URL.revokeObjectURL(url);
        } catch (error) {
            alert(`下载异常：${error.message}`);
        }
    };

    // 清空节点提交日志
    const clearSqlLog = async () => {
        sqlLogContent.textContent = '节点提交日志清理中...';
        try {
            const response = await fetch('/api/logs/center.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    csrf_token: csrfToken,
                    action: 'clear',
                    log_type: 'sql'
                })
            });
            if (!response.ok) throw new Error('网络请求失败');
            const data = await response.json();
            sqlLogContent.textContent = data.success ? '节点提交日志已清空' : `清理失败：${data.message}`;
        } catch (error) {
            sqlLogContent.textContent = `清理异常：${error.message}`;
        }
    };

    // 绑定按钮事件
    document.getElementById('clearLoginLogBtn').addEventListener('click', clearLoginLog);
    document.getElementById('refreshLoginLogBtn').addEventListener('click', loadLoginLog);
    document.getElementById('dwLoginLogBtn').addEventListener('click', downloadLoginLog);

    // 绑定按钮事件
    document.getElementById('refreshSqlLogBtn').addEventListener('click', loadSqlLog);
    document.getElementById('dwSqlLogBtn').addEventListener('click', downloadSqlLog);
    document.getElementById('clearSqlLogBtn').addEventListener('click', clearSqlLog);
});