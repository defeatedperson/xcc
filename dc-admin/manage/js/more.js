$(function () {
    const $form = $('#subFormContainer');
    const domain = $form.data('domain');
    const csrfToken = $('meta[name="csrf-token"], #csrf-token').attr('content');
    const originEnabled = $('#origin-enabled');
    const originDomain = $('#origin-domain');
    const btnSave = $form.find('.btn-save');
    const btnReset = $form.find('.btn-reset');
    const hstsCheckbox = $('#hsts-enabled');

    // 只允许输入字母、数字、冒号，且最长100字符
    originDomain.on('input', function () {
        this.value = this.value.replace(/[^a-zA-Z0-9:.]/g, '').slice(0, 100);
    });

    // 勾选自定义回源时启用输入框，否则禁用
    originEnabled.on('change', function () {
        originDomain.prop('disabled', !this.checked);
        if (!this.checked) {
            originDomain.val('');
        }
    });

    // 初始化加载当前设置
    function loadSettings() {
        $.post('/data/more.php', {
            csrf_token: csrfToken,
            domain: domain,
            action: 'get'
        }, function (res) {
            if (res.success) {
                originDomain.val(res.data.origin_domain || '');
                originEnabled.prop('checked', !!Number(res.data.origin_enabled));
                hstsCheckbox.prop('checked', !!Number(res.data.hsts_enabled));
                originDomain.prop('disabled', !originEnabled.prop('checked'));
            }
        }, 'json');
    }

    // 保存
    btnSave.on('click', function () {
        if (originEnabled.prop('checked') && !originDomain.val().trim()) {
            showAlert('error', '请填写回源域名');
            originDomain.focus();
            return;
        }
        $.post('/data/more.php', {
            csrf_token: csrfToken,
            domain: domain,
            origin_domain: originDomain.val(),
            origin_enabled: originEnabled.prop('checked') ? 1 : 0,
            hsts_enabled: hstsCheckbox.prop('checked') ? 1 : 0,
            action: 'save'
        }, function (res) {
            if (res.success) {
                showAlert('success','保存成功');
            } else {
                showAlert('error',res.message || '保存失败');
            }
        }, 'json');
    });

    // 恢复默认
    btnReset.on('click', function () {
        originDomain.val('');
        originEnabled.prop('checked', false);
        hstsCheckbox.prop('checked', false);
        originDomain.prop('disabled', true);
    });

    loadSettings();
});