// 分页参数
let currentPage = 1;
const pageSize = 3;
let totalPages = 0;

// 获取 CSRF 令牌
const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

// 页面加载完成后初始化（关键修复）
window.addEventListener('DOMContentLoaded', () => {
    loadDomainList(); // 加载域名列表

    // 上一页按钮点击事件（移至 DOMContentLoaded 内）
    document.getElementById('prevBtn').addEventListener('click', () => {
        if (currentPage > 1) {
            currentPage--;
            loadDomainList();
        }
    });

    // 下一页按钮点击事件（移至 DOMContentLoaded 内）
    document.getElementById('nextBtn').addEventListener('click', () => {
        if (currentPage < totalPages) {
            currentPage++;
            loadDomainList();
        }
    });

    // 同步列表上一页点击事件
    $('#blockPrevBtn').click(() => {
        const currentPage = parseInt($('#blockCurrentPage').text());
        if (currentPage > 1) loadUpdateList(currentPage - 1);
    });

    // 同步列表下一页点击事件
    $('#blockNextBtn').click(() => {
        const currentPage = parseInt($('#blockCurrentPage').text());
        const totalPages = parseInt($('#blockTotalPages').text());
        if (currentPage < totalPages) loadUpdateList(currentPage + 1);
    });

    // 新增：同步配置按钮点击事件
    document.getElementById('syncConfigBtn').addEventListener('click', async function() {
        const btn = this;
        btn.disabled = true; // 禁用按钮防止重复提交
        try {
            // 先弹出确认弹窗
            showConfirm(
                '是否提交同步？',
                async () => { // 确认回调
                    try {
                        // 调用 sync.php 接口
                        const response = await fetch('/data/sync.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                csrf_token: csrfToken // 传递CSRF令牌
                            })
                        });

                        if (!response.ok) throw new Error('网络请求失败');
                        const data = await response.json();

                        if (data.success) {
                            showAlert('success', data.message);

                            // 新增判断：如果后端提示无需要同步的域名，则不触发 update-api.php
                            if (data.message.includes('不存在需要同步的域名')) {
                                return;
                            }

                            // 只有有需要同步的域名时才触发 update-api.php
                            const updateResponse = await fetch('/update-api.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: new URLSearchParams({
                                    csrf_token: csrfToken // 传递CSRF令牌验证
                                })
                            });

                            if (!updateResponse.ok) throw new Error('触发同步事件失败');
                            const updateData = await updateResponse.json();

                            if (updateData.success) {
                                showAlert('success', '同步事件触发成功：' + updateData.message);
                            } else {
                                showAlert('error', '同步事件触发失败：' + updateData.message);
                            }

                            loadDomainList(); // 刷新域名列表
                        } else {
                            showAlert('error', `同步失败：${data.message}`);
                        }
                    } catch (error) {
                        showAlert('error', `同步异常：${error.message}`);
                    } finally {
                        btn.disabled = false; // 恢复按钮状态
                    }
                },
                () => { // 取消回调
                    btn.disabled = false; // 用户取消时恢复按钮
                }
            );
        } catch (error) {
            showAlert('error', `同步异常：${error.message}`);
            btn.disabled = false;
        }
    });

    // 新增：生成配置按钮点击事件
    document.getElementById('generateConfigBtn').addEventListener('click', async function() { // 改为普通函数获取正确this
        const btn = this; // this指向按钮元素
        btn.disabled = true; // 立即禁用按钮防止重复提交
        try {
            // 请求 admin-take-update.php 获取待处理域名
            const response = await fetch('/data/admin-take-update.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'take_query',  // 固定action（与后端一致）
                    csrf_token: csrfToken  // 复用已获取的CSRF令牌
                })
            });

            if (!response.ok) throw new Error('网络请求失败');
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message);
            }

            // 处理无待生成域名的情况
            if (data.data.length === 0) {
                showAlert('info', data.message); // 使用后端返回的提示
                return; // 终止后续逻辑
            }

            // 显示确认弹窗（新增取消回调）
            showConfirm(
                `确认生成以下域名的配置？<br>${data.data.join('<br>')}`,
                async () => { // 确认回调
                    try {
                        for (const domain of data.data) {
                            try {
                                const confResponse = await fetch('/data/conf-center.php', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                    body: new URLSearchParams({
                                        domain: domain,
                                        csrf_token: csrfToken
                                    })
                                });

                                const confData = await confResponse.json();
                                if (confData.success) {
                                    showAlert('success', `域名 ${domain} 配置生成成功`);
                                } else {
                                    showAlert('error', `域名 ${domain} 配置生成失败：${confData.message}`);
                                    break; // 生成失败，终止后续域名生成
                                }
                            } catch (error) {
                                showAlert('error', `域名 ${domain} 配置生成异常：${error.message}`);
                                break; // 异常也终止后续域名生成
                            }
                        }
                    } finally {
                        btn.disabled = false; // 所有域名处理完成或遇到失败后恢复按钮
                    }
                },
                () => { // 取消回调
                    btn.disabled = false; // 用户取消时恢复按钮
                }
            );

        } catch (error) {
            showAlert('error', `获取待处理域名失败：${error.message}`);
            btn.disabled = false; // 接口请求失败时恢复按钮
        }
    });
});

// 加载同步列表的核心函数（每页3条）
function loadUpdateList(currentPage) {
    const csrfToken = $('meta[name="csrf-token"]').attr('content');
    $.ajax({
        url: '../data/admin-read-update.php',  // 接口路径需确认是否与manage.php上下文兼容
        type: 'POST',
        dataType: 'json',
        data: {
            csrf_token: csrfToken,
            action: 'list',  // 指定读取操作
            page: currentPage,
            pageSize: 3  // 与后端默认分页数量一致
        },
        success: (res) => {
            if (!res.success) {
                showAlert('error','同步列表加载失败：' + res.message);
                return;
            }
            const $updateList = $('#updateList');
            $updateList.empty();
            if (res.data.length === 0) {
                $updateList.html('<tr><td colspan="6">无同步数据</td></tr>');
                return;
            }

            // 检查是否存在未提交同步的域名
            const hasUnsubmitted = res.data.some(item => Number(item.sync_status) === 0);
            if (hasUnsubmitted) {
                $('#syncStatusTip').text('⚠未提交');
            } else {
                $('#syncStatusTip').text('✅已提交');
            }

            res.data.forEach(item => {
                $updateList.append(`
                    <tr data-domain="${item.domain}">
                        <td>${item.domain}</td> 
                        <td>${Number(item.config_status) === 1 ? '已生成' : '未生成'}</td>  
                        <td>${Number(item.sync_status) === 1 ? '已提交' : '未提交'}</td> 
                        <td>${item.update_time}</td> 
                    </tr>
                `);
            });
            const totalPages = Math.ceil(res.total / res.pageSize);
            $('#blockCurrentPage').text(res.page);
            $('#blockTotalPages').text(totalPages);
            $('#blockPrevBtn').prop('disabled', res.page === 1);
            $('#blockNextBtn').prop('disabled', res.page === totalPages);
        },
        error: () => showAlert('error','同步列表网络请求失败，请重试')
    });
}

// 初始化加载同步列表第一页数据
loadUpdateList(1);

function loadDomainList() {
    const domainList = document.getElementById('domainList');

    // 显示加载动画（关键修改）
    domainList.innerHTML = `<tr><td colspan="6" class="loading">加载中...</td></tr>`;
    
    // 调用 read.php 获取分页数据
    fetch('/data/read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            action: 'list',
            page: currentPage.toString(),
            pageSize: pageSize.toString(),
            csrf_token: csrfToken
        })
    })
        .then(response => {
            if (!response.ok) throw new Error('网络请求失败');
            return response.json();
        })
        .then(data => {
            if (!data.success) throw new Error(data.message);
            
            // 更新分页信息
            totalPages = Math.ceil(data.total / pageSize);
            document.getElementById('currentPage').textContent = currentPage;
            document.getElementById('totalPages').textContent = totalPages;
            
            // 控制按钮状态
            document.getElementById('prevBtn').disabled = currentPage === 1;
            document.getElementById('nextBtn').disabled = currentPage === totalPages;

            // 清空加载提示（数据加载完成后隐藏）
            domainList.innerHTML = '';
            
            // 动态生成表格行
            data.domains.forEach(domain => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${domain.id}</td>
                    <td>${domain.domain}</td>
                    <td>${domain.origin_url}</td>
                    <td>${domain.ssl_enabled === "0" ? "未启用" : `证书[${domain.ssl_enabled}]`}</td>
                    <td>${domain.protection_enabled === "1" ? "自定义" : "默认"}</td>
                    <td>
                        <button data-domain="${domain.domain}" class="delete-btn">删除</button>
                        <button data-domain="${domain.domain}" class="clear-cache-btn">清理缓存</button>
                    </td>
                `;
                // 删除按钮事件
                tr.querySelector('.delete-btn').addEventListener('click', function() {
                    const targetDomain = this.dataset.domain;
                    showConfirm(`确认删除域名 ${targetDomain}？`, () => {
                        fetch('/data/delet.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: new URLSearchParams({
                                domain: targetDomain,
                                csrf_token: csrfToken
                            })
                        })
                        .then(res => res.json())
                        .then(result => {
                            if (result.success) {
                                showAlert('success','删除成功');
                                loadDomainList();
                            } else {
                                showAlert('error',`删除失败：${result.message}`);
                            }
                        })
                        .catch(err => {
                            showAlert('error',`网络错误：${err.message}`);
                        });
                    });
                });
                // 清理缓存按钮事件
                tr.querySelector('.clear-cache-btn').addEventListener('click', function() {
                    const targetDomain = this.dataset.domain;
                    showConfirm(`确认清理域名 ${targetDomain} 的缓存？`, () => {
                        fetch('/clear-cache-api.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: new URLSearchParams({
                                domain: targetDomain,
                                csrf_token: csrfToken
                            })
                        })
                        .then(res => res.json())
                        .then(result => {
                            if (result.success) {
                                showAlert('success', result.message || '清理任务已提交');
                            } else {
                                showAlert('error', result.message || '清理任务提交失败');
                            }
                        })
                        .catch(err => {
                            showAlert('error',`网络错误：${err.message}`);
                        });
                    });
                });
                domainList.appendChild(tr);
            });
        })
        .catch(error => {
            domainList.innerHTML = `<tr><td colspan="6" class="error">${error.message}</td></tr>`;
        });
}
