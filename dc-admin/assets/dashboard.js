// 页面加载后初始化数据
window.onload = function() {
    // 初始化图表变量为null（解决建议3）
    window.requestChart = null;
    window.trafficChart = null;
    // 从 meta 标签中获取 CSRF 令牌（限制作用域）
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    // 新增：CSRF 令牌空值校验
    if (!csrfToken) {
        const errorEl = document.getElementById('errorMsg');
        const customError = document.getElementById('customError');
        errorEl.textContent = 'CSRF令牌未获取，请刷新页面或重新登录';
        customError.style.display = 'block';
        return; // 阻止后续逻辑执行
    }
    // 调用函数时传递 CSRF 令牌（替换原 window.CSRF_TOKEN）
    loadDomains(csrfToken);
    loadData('last_10min', '', csrfToken);
    loadNormalDomains(csrfToken);

    // 时间范围切换监听（修改：添加校验逻辑）
    document.querySelectorAll('input[name="time_range"]').forEach(radio => {
        radio.addEventListener('change', (e) => {
            const allowedTimeRanges = ['last_10min', 'today', 'yesterday', 'last_7days'];
            const selectedTime = e.target.value;
            if (!allowedTimeRanges.includes(selectedTime)) {
                // 显示错误提示
                const errorEl = document.getElementById('errorMsg');
                const customError = document.getElementById('customError');
                errorEl.textContent = '无效的时间范围（请选择：最近10分钟/今日/昨日/近7日）';
                customError.style.display = 'block';
                return;
            }
            // 修复：使用局部变量 csrfToken 传递令牌
            loadData(selectedTime, '', csrfToken);
        });
    });
};

// 加载正常域名数量函数（修改核心逻辑）
function loadNormalDomains(csrfToken) {
    // 提前声明变量，确保在 catch 中可访问
    const normalDomainsEl = document.querySelector('.card:last-child div:last-child');
    // 修改为POST请求，并传递必要参数（根据read.php的默认action为'count'）
    fetch('/data/read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            action: 'count', // 明确指定action（可选，根据read.php逻辑默认是'count'）
            csrf_token: csrfToken // 新增：传递CSRF令牌
        })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                normalDomainsEl.textContent = `${data.count}个`; // 直接使用data.count（关键修改）
            } else {
                normalDomainsEl.textContent = '加载失败';
            }
        })
        .catch(error => {
            normalDomainsEl.textContent = '加载失败'; // 现在可访问已声明的变量
        });
}

// 加载域名列表函数
function loadDomains(csrfToken) {
    // 修改为 POST 请求
    fetch('/api/read_sql.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded', // 指定表单数据格式
        },
        body: new URLSearchParams({
            action: 'get_domains', // 参数通过 body 传递
            csrf_token: csrfToken // 新增：传递CSRF令牌
        })
    })
        .then(response => response.json())
        .then(data => {
            const select = document.querySelector('.filter-select');
            if (data.success && data.domains.length > 0) {
                // 清空默认选项
                select.innerHTML = '<option value="">全部域名</option>';
                // 动态添加域名选项
                data.domains.forEach(domain => {
                    const option = document.createElement('option');
                    option.value = domain;
                    option.textContent = domain;
                    select.appendChild(option);
                });
            } else {
                // 明确区分"无数据"和"接口失败"（新增）
                select.innerHTML = data.message 
                    ? `<option value="" disabled>错误：${data.message}</option>` 
                    : '<option value="" disabled>无有效域名数据</option>';
            }
            // 添加域名选择事件监听
            select.addEventListener('change', (e) => {
                const selectedDomain = e.target.value;
                // 校验域名非空（可选：添加正则校验域名格式）
                if (selectedDomain && !/^([a-zA-Z0-9_-]+\.)+[a-zA-Z]{2,}$/.test(selectedDomain)) {
                    const errorEl = document.getElementById('errorMsg');
                   const customError = document.getElementById('customError');
                    errorEl.textContent = '域名格式无效（示例：xcdream.com）';
                    customError.style.display = 'block';
                    return;
                }
                // 重新加载数据（传递选中的域名）
                loadData(document.querySelector('input[name="time_range"]:checked').value, selectedDomain, csrfToken); // 新增csrfToken参数
            });
        })
        .catch(error => {
            // 捕获网络错误时显示更具体提示（新增）
            document.querySelector('.filter-select').innerHTML = `<option value="" disabled>域名加载失败：${error.message}</option>`;
        });
}

// 核心数据加载函数
function loadData(timeRange, selectedDomain = '', csrfToken) { // 新增参数
    const params = new URLSearchParams();
    params.append('action', 'default');
    params.append('time_range', timeRange);
    params.append('csrf_token', csrfToken); // 关键修复：无论是否有域名，都传递CSRF令牌
    if (selectedDomain) {
        params.append('domain', selectedDomain);
    }

    // 修改为 POST 请求
    fetch('/api/read_sql.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded', // 指定表单数据格式
        },
        body: params // 参数通过 body 传递
    })
        .then(response => response.json())
        .then(data => {
            if (!data.success) throw new Error(data.message);

            // 新增：检查数据是否为空
            if (data.data_points.length === 0) {
                const errorEl = document.getElementById('errorMsg');
                const customError = document.getElementById('customError');
                errorEl.textContent = '当前时间范围内无有效数据';
                customError.style.display = 'block'; 
                document.getElementById('totalRequests').textContent = '无数据';
                document.getElementById('totalTraffic').textContent = '无数据';
                document.getElementById('blockedIPs').textContent = '无数据';
                // 清空图表（优化：确保图表存在时再销毁）
                if (window.requestChart && typeof window.requestChart.destroy === 'function') window.requestChart.destroy();
                if (window.trafficChart && typeof window.trafficChart.destroy === 'function') window.trafficChart.destroy();
                return;
            }

            // 更新卡片数据
            const totalRequests = data.data_points.reduce((sum, item) => sum + parseInt(item.total_requests), 0);
            const totalRequestsK = (totalRequests / 1000).toFixed(2); // 转为千次，保留两位小数
            const totalTrafficGB = (data.data_points.reduce((sum, item) => sum + parseInt(item.total_traffic), 0) / 1024 / 1024 / 1024).toFixed(2);
            document.getElementById('totalRequests').textContent = totalRequestsK;
            document.getElementById('totalTraffic').textContent = totalTrafficGB + 'GB';
            document.getElementById('blockedIPs').textContent = data.blocked_ips.length;

            // 处理图表数据（提取时间槽和对应值） - 关键修改：增加空值校验
            const labels = data.data_points.map(item => {
                return item.time_slot ? item.time_slot.split(' ')[1] : '未知时间';
            });
            const requestData = data.data_points.map(item => (parseInt(item.total_requests) / 1000).toFixed(2));
            const trafficDataMB = data.data_points.map(item => (parseInt(item.total_traffic) / 1024 / 1024).toFixed(2));

            if (window.requestChart) { // 仅当存在实例时销毁（null时不执行）
                window.requestChart.destroy();
            }
            const requestCtx = document.getElementById('requestChart')?.getContext('2d');
            if (requestCtx) {
                try {
                    window.requestChart = new Chart(requestCtx, {
                        type: 'line',
                        data: { labels, datasets: [{
                            label: '请求数（千次/分钟）',
                            data: requestData,
                            borderColor: '#2196F3',
                            backgroundColor: 'rgba(33, 150, 243, 0.1)',
                            tension: 0.4,
                            borderWidth: 2
                        }]},
                        options: { responsive: true, maintainAspectRatio: false }
                    });
                } catch (error) {
                    const errorEl = document.getElementById('errorMsg');
                    const customError = document.getElementById('customError');
                    errorEl.textContent = `图表渲染失败：${error.message}`;
                    customError.style.display = 'block';
                }
            }

            // 初始化/更新流量图表（同理）
            if (window.trafficChart) { 
                window.trafficChart.destroy();
            }
            const trafficCtx = document.getElementById('trafficChart')?.getContext('2d');
            if (trafficCtx) {
                try {
                    window.trafficChart = new Chart(trafficCtx, {
                        type: 'line',
                        data: { labels, datasets: [{
                            label: '出站流量（MB/分钟）',
                            data: trafficDataMB,
                            borderColor: '#4CAF50',
                            backgroundColor: 'rgba(76, 175, 80, 0.1)',
                            tension: 0.4,
                            borderWidth: 2
                        }]},
                        options: { responsive: true, maintainAspectRatio: false }
                    });
                } catch (error) {
                    const errorEl = document.getElementById('errorMsg');
                    const customError = document.getElementById('customError');
                    errorEl.textContent = `图表渲染失败：${error.message}`;
                    customError.style.display = 'block';
                }
            }
        })
        .catch(error => {
            const errorEl = document.getElementById('errorMsg');
            const customError = document.getElementById('customError');
            errorEl.textContent = `数据加载失败：${error.message}`;
            customError.style.display = 'block';
            // 恢复默认提示
            document.getElementById('totalRequests').textContent = '加载失败';
            document.getElementById('totalTraffic').textContent = '加载失败';
            document.getElementById('blockedIPs').textContent = '加载失败';
        });
}