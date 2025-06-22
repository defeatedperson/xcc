$(document).ready(function() {
    // 获取CSRF令牌（从manage.php的meta标签中读取）
    const csrfToken = $('#csrf-token').attr('content');
    
    // 加载域名列表（修改：添加csrf_token参数）
    $.post('/data/read.php', { 
        action: 'domain_list', 
        csrf_token: csrfToken  // 新增：传递CSRF令牌
    }, function(res) {
        if (res.success) {
            var $select = $('#domainSelect');
            $select.find('option:gt(0)').remove(); // 移除除第一个外的旧选项
            res.domains.forEach(function(domain) {
                $select.append('<option value="' + domain + '">' + domain + '</option>');
            });
        }
    }, 'json'); // 明确指定返回类型为JSON

    // 处理新增域名按钮点击（与domains.php一致）
    $('#addDomainBtn').click(function() {
        loadForm('add'); // 加载新增表单
    });

    // 处理域名选择变化（与domains.php一致）
    $('#domainSelect').change(function() {
        const selectedDomain = $(this).val();
        if (selectedDomain) {
            loadForm('edit', selectedDomain); // 加载编辑表单
        } else {
            showDefaultPrompt(); // 无选择时恢复默认提示
        }
    });

    // 核心：加载表单的函数（与domains.php一致）
    function loadForm(type, domain = null) {
        const formUrl = domain 
            ? `/dc-admin/manage/domain_form.php?domain=${encodeURIComponent(domain)}` 
            : '/dc-admin/manage/domain_form.php';
        
        $.get(formUrl, function(html) {
            // 传递操作类型和域名参数到子表单
            $('#formContainer')
                .html(html)
                .data('form-type', type)
                .data('current-domain', domain);
        });
    }

    // 恢复默认提示（与domains.php一致）
    function showDefaultPrompt() {
        $('#formContainer').html(`
            <div class="default-prompt">
                请选择一个已有的域名进行管理，或点击"新增域名"按钮添加新域名
            </div>
        `);
    }
});