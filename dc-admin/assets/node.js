$(function() {
    // 获取CSRF令牌（假设header.php已输出meta标签）
    const csrfToken = $('meta[name="csrf-token"]').attr('content');

    // 新增：初始化表单区域的jQuery对象
    const $formSection = $('.form-section');  // 关键修复：定义$formSection变量

    // 新增：加载节点数据的核心函数
    function loadNodes(currentPage) {
        $.ajax({
            url: '../api/node-read.php',  // 指向后端接口
            type: 'POST',
            dataType: 'json',
            data: {
                csrf_token: csrfToken,
                page: currentPage,  // 当前页码
                pageSize: 3  // 与后端默认分页数量一致
            },
            success: (res) => {
                if (!res.success) {
                    showAlert('error','加载失败：' + res.message);
                    return;
                }
                
                // 渲染节点列表
                const $nodeList = $('#nodeList');
                $nodeList.empty();
                if (res.nodes.length === 0) {
                    $nodeList.html('<tr><td colspan="6">无节点数据</td></tr>');
                    return;
                }

                res.nodes.forEach(node => {
                    $nodeList.append(`
                        <tr data-id="${node.id}">  <!-- 添加data-id存储节点ID -->
                            <td>
                                ${node.id}
                                <button class="btn-copy" data-type="id" data-value="${node.id}">复制</button>
                            </td>
                            <td>${node.node_name}</td>
                            <td>${node.update_time}</td>
                            <td>${node.node_ip}</td>
                            <td>
                                <span class="key-display" data-original="${node.node_key}">*****</span>
                                <button class="btn-copy" data-type="key" data-value="${node.node_key}">复制</button>
                            </td>
                            <td>
                                <button class="btn-edit">编辑</button>
                                <button class="btn-delete">删除</button>
                            </td>
                        </tr>
                    `);
                });

                // 更新分页信息
                const totalPages = Math.ceil(res.total / res.pageSize);
                $('#currentPage').text(res.page);
                $('#totalPages').text(totalPages);
                
                // 控制翻页按钮状态
                $('#prevBtn').prop('disabled', res.page === 1);
                $('#nextBtn').prop('disabled', res.page === totalPages);
            },
            error: () => showAlert('error','网络请求失败，请重试')
        });
    }

    // 初始化加载第一页数据
    loadNodes(1);

    // 上一页点击事件（保持原有弹窗逻辑）
    $('#prevBtn').click(() => {
        const currentPage = parseInt($('#currentPage').text());
        if (currentPage > 1) loadNodes(currentPage - 1);
    });

    // 下一页点击事件
    $('#nextBtn').click(() => {
        const currentPage = parseInt($('#currentPage').text());
        const totalPages = parseInt($('#totalPages').text());
        if (currentPage < totalPages) loadNodes(currentPage + 1);
    });

    // 新增：密钥显示切换功能
    $(document).on('click', '.key-display', function() {
        const $this = $(this);
        const originalKey = $this.data('original');
        $this.text($this.text() === '*****' ? originalKey : '*****');
    });

    // 新增：复制按钮功能
    $(document).on('click', '.btn-copy', function() {
        const copyValue = $(this).data('value');
        navigator.clipboard.writeText(copyValue)
            .then(() => showAlert('success', '复制成功'))
            .catch(() => showAlert('error', '复制失败，请手动复制'));
    });
    
    // 修改：打开新增表单（改为显示表单区域）
    $('.add-node-btn').click(() => {
        $formSection.show();  // 现在使用已初始化的变量
        $formSection.find('h3').text('新增节点');
        $('#nodeAddForm')[0].reset();
        $('input[name="node_id"]').remove();
    });

    // 安装命令按钮点击事件
    $('#installCmdBtn').click(function() {
        window.location.href = '/dc-admin/setting.php';
    });

    // 同步按钮点击事件
    $('#syncBtn').click(function() {
        const btn = this;
        btn.disabled = true; // 防止重复点击
        showConfirm(
            '确定要同步配置到所有节点吗？',
            function() { // 确认回调
                $.ajax({
                    url: '../update-api.php',
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        csrf_token: csrfToken
                    },
                    success: function(res) {
                        if (res.success) {
                            showAlert('success', res.message || '同步任务已提交，请稍后查看日志');
                        } else {
                            showAlert('error', res.message || '同步失败');
                        }
                        btn.disabled = false;
                    },
                    error: function() {
                        showAlert('error', '网络请求失败，请重试');
                        btn.disabled = false;
                    }
                });
            },
            function() { // 取消回调
                btn.disabled = false;
            }
        );
    });

    // 修改：编辑按钮点击事件（改为填充数据后显示表单区域）
    $(document).on('click', '.btn-edit', function() {
        const $tr = $(this).closest('tr');
        const nodeId = $tr.find('td:eq(0)').text().trim();
        const nodeName = $tr.find('td:eq(1)').text().trim();
        const nodeIp = $tr.find('td:eq(3)').text().trim();
        const nodeKey = $tr.find('td:eq(4) .key-display').data('original');

        // 填充表单并显示区域
        $('input[name="node_name"]').val(nodeName);
        $('input[name="node_ip"]').val(nodeIp);
        $('input[name="node_key"]').val(nodeKey);
        $('#nodeAddForm').append(`<input type="hidden" name="node_id" value="${nodeId}">`);
        $formSection.find('h3').text('编辑节点');
        $formSection.show();  // 使用已初始化的变量
    });

    // 新增：取消按钮点击事件
    $(document).on('click', '.cancel-btn', function() {
        $formSection.hide();          // 隐藏表单区域
        $('#nodeAddForm')[0].reset(); // 重置表单输入
        $('input[name="node_id"]').remove(); // 移除隐藏的节点ID字段
    });


    // 新增：生成随机密钥函数（改为固定16位，包含字母、数字、特殊符号）
    function generateRandomKey() {
        const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
        const length = 16;  // 固定生成16位密钥
        let key = '';
        for (let i = 0; i < length; i++) {
            key += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return `sk-${key}`;  // 添加固定前缀保持格式统一
    }

    // 新增：生成密钥按钮点击事件
    $(document).on('click', '.generate-key-btn', function() {
        const $keyInput = $(this).siblings('input[name="node_key"]');
        $keyInput.val(generateRandomKey());  // 生成并填充密钥
    });

    // 新增：删除按钮点击事件
    $(document).on('click', '.btn-delete', function() {
        const $tr = $(this).closest('tr');
        const nodeId = $tr.data('id');  // 从tr的data-id属性获取节点ID
        const nodeName = $tr.find('td:eq(1)').text().trim();  // 获取节点名称用于提示

        // 调用公共确认弹窗（来自header.js）
        showConfirm(`确认删除节点【${nodeName}】吗？删除后无法恢复！`, async () => {
            try {
                const response = await $.ajax({
                    url: '../api/node-delete.php',  // 指向删除接口
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        csrf_token: csrfToken,  // 使用已获取的CSRF令牌
                        id: nodeId  // 传递节点ID
                    }
                });

                if (response.success) {
                    showAlert('success', '节点删除成功');
                    loadNodes($('#currentPage').text());  // 刷新当前页数据
                } else {
                    showAlert('error', '删除失败：' + response.message);
                }
            } catch (error) {
                showAlert('error', '网络请求失败，请重试');
            }
        });
    });

    // 修改：表单提交逻辑（支持新增/编辑）
    $('#nodeAddForm').submit(async function(e) {
        e.preventDefault();
        const formData = {
            csrf_token: csrfToken,
            node_name: $('input[name="node_name"]').val().trim(),
            node_ip: $('input[name="node_ip"]').val().trim(),
            node_key: $('input[name="node_key"]').val().trim(),
            node_id: $('input[name="node_id"]').val()
        };

        // 新增前端输入校验
        if (!/^[a-zA-Z]{1,5}$/.test(formData.node_name)) {
            showAlert('error', '节点名称需为1-5位纯字母');
            return;
        }
        if (!/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/.test(formData.node_ip)) {  // 简单IP格式校验
            showAlert('error', 'IP地址格式无效');
            return;
        }
        if (formData.node_key.length < 16 || formData.node_key.length > 32) {  // 修改为16-32位校验
            showAlert('error', 'API密钥长度需为16-32位');
            return;
        }

        try {
            const url = formData.node_id ? '../api/node-update.php' : '../api/node-add.php';
            const response = await $.ajax({ url, type: 'POST', dataType: 'json', data: formData });

            if (response.success) {
                showAlert('success', formData.node_id ? '节点更新成功' : '节点新增成功');
                $formSection.hide();  // 隐藏表单区域
                $('#nodeAddForm')[0].reset();
                $('input[name="node_id"]').remove();
                loadNodes($('#currentPage').text()); // 刷新数据
            } else {
                showAlert('error', formData.node_id ? '更新失败：' : '新增失败：' + response.message);
            }
        } catch (error) {
            showAlert('error', '网络请求失败，请重试');
        }
    });

    


    // 新增：加载封禁列表的核心函数（每页5条）
    function loadBlockList(currentPage) {
        const csrfToken = $('meta[name="csrf-token"]').attr('content');
        $.ajax({
            url: '../api/read_sql.php',  // 指向后端接口
            type: 'POST',
            dataType: 'json',
            data: {
                csrf_token: csrfToken,
                action: 'get_blocked_ips',  // 新增action标识
                page: currentPage,
                pageSize: 5  // 每页5条
            },
            success: (res) => {
                if (!res.success) {
                    showAlert('error','加载封禁列表失败：' + res.message);
                    return;
                }
                
                // 渲染封禁列表
                const $blockList = $('#blockList');
                $blockList.empty();
                if (res.blocked_ips.length === 0) {
                    $blockList.html('<tr><td colspan="2">无封禁记录</td></tr>');
                    return;
                }

                res.blocked_ips.forEach(item => {
                    $blockList.append(`
                        <tr>
                            <td>${item.log_time}</td>
                            <td>${item.ip}</td>
                        </tr>
                    `);
                });

                // 更新分页信息
                const totalPages = Math.ceil(res.total / res.pageSize);
                $('#blockCurrentPage').text(res.page);
                $('#blockTotalPages').text(totalPages);
                
                // 控制翻页按钮状态
                $('#blockPrevBtn').prop('disabled', res.page === 1);
                $('#blockNextBtn').prop('disabled', res.page === totalPages);
            },
            error: () => showAlert('error','网络请求失败，请重试')
        });
    }

    // 初始化加载第一页封禁数据
    loadBlockList(1);

    // 封禁列表上一页点击事件
    $('#blockPrevBtn').click(() => {
        const currentPage = parseInt($('#blockCurrentPage').text());
        if (currentPage > 1) loadBlockList(currentPage - 1);
    });

    // 封禁列表下一页点击事件
    $('#blockNextBtn').click(() => {
        const currentPage = parseInt($('#blockCurrentPage').text());
        const totalPages = parseInt($('#blockTotalPages').text());
        if (currentPage < totalPages) loadBlockList(currentPage + 1);
    });


});
