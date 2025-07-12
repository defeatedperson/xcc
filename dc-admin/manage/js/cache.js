$(function() {
    const currentDomain = $('#subFormContainer').data('domain');
    const csrfToken = $('#csrf-token').attr('content');

    // 1. 加载当前域名的缓存规则
    function loadCacheRules(domain) {
        if (!domain) return;
        
        $.post('/data/cache-rules.php', {
            action: 'get',
            domain: domain,
            csrf_token: csrfToken
        }).done(res => {
            if (res.success && res.data) {
                // 渲染启用状态（仅保留正确的布尔值判断）
                $('#cache-enabled').prop('checked', parseInt(res.data.enabled) === 1);
                // 渲染缓存时间
                $('#cache-time1').val(res.data.cache_time || '1h');
                // 渲染文件后缀（按|分割成标签）
                const $tagContainer = $('#cache-content').siblings('.tag-container');
                $tagContainer.empty();
                (res.data.suffix || 'jpg|jpeg|png|gif|bmp|ico').split('|').forEach(suffix => {
                    if (suffix) {
                        const $tag = $(`
                            <div class="cache-tag">
                                <span class="tag-text">${suffix}</span>
                                <span class="tag-delete">×</span>
                            </div>
                        `);
                        $tag.find('.tag-delete').click(() => $tag.remove());
                        $tagContainer.append($tag);
                    }
                });
            }
        }).fail(xhr => {
            showAlert('error',`加载缓存规则失败：${xhr.statusText}`);
        });
    }

    // 2. 保存按钮逻辑
  $('.btn-save').click(() => {
    if (!currentDomain) {
        showAlert('error','未获取到当前域名');
        return;
    }

    // 收集数据（修改：将enabled转换为0/1）
    const enabled = $('#cache-enabled').prop('checked') ? 1 : 0; // 关键修复：布尔值转整数
    const suffixes = $('.tag-text').map((i, el) => $(el).text()).get();
    const cacheTime = $('#cache-time1').val().trim();

    // 校验：仅当启用缓存时检查后缀和缓存时间
    if (enabled) {
        if (suffixes.length === 0) {
            showAlert('error','请至少添加一个文件后缀');
            return;
        }
        if (!/^[a-zA-Z0-9|]+$/.test(suffixes.join('|'))) {
            showAlert('error','文件后缀仅允许字母、数字和|符号');
            return;
        }
        if (!cacheTime) {
            showAlert('error','请输入缓存时间');
            return;
        }
    }

    // 调用保存接口（原有逻辑不变）
    $.post('/data/cache-rules.php', {
        action: 'save',
        domain: currentDomain,
        enabled: enabled,
        suffix: suffixes.join('|'),
        cache_time: cacheTime,
        csrf_token: csrfToken
    }, res => {
        if (res.success) {
            showAlert('success', '缓存设置保存成功');
        } else {
            showAlert('error', `保存失败：${res.message}`);
        }
    }, 'json').fail(xhr => {
        showAlert('error', `请求失败：${xhr.statusText}`);
    });
});

    // 3. 回车添加标签（修改校验规则允许|）
    $('#cache-content').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            const inputVal = $(this).val().trim();
            const $tagContainer = $(this).siblings('.tag-container');
            
            // 允许字母、数字和|，且非空
            if (inputVal && /^[a-zA-Z0-9]+$/.test(inputVal)) { 
                // 分割多个后缀（如用户输入"jpg|png"）
                inputVal.split('|').forEach(suffix => {
                    if (suffix) {
                        const $tag = $(`
                            <div class="cache-tag">
                                <span class="tag-text">${suffix}</span>
                                <span class="tag-delete">×</span>
                            </div>
                        `);
                        $tag.find('.tag-delete').click(() => $tag.remove());
                        $tagContainer.append($tag);
                    }
                });
                $(this).val('');
            }
        }
    });

    // 4. 恢复默认逻辑（保持与数据库默认一致）
    $('.btn-reset').click(() => {
        const $tagContainer = $('#cache-content').siblings('.tag-container');
        const defaultSuffixes = ['jpg','jpeg','png','gif','bmp','ico'];
        const defaultTime = '1h';

        $tagContainer.empty();
        defaultSuffixes.forEach(suffix => {
            const $tag = $(`
                <div class="cache-tag">
                    <span class="tag-text">${suffix}</span>
                    <span class="tag-delete">×</span>
                </div>
            `);
            $tag.find('.tag-delete').click(() => $tag.remove());
            $tagContainer.append($tag);
        });
        $('#cache-time1').val(defaultTime);
        $('#cache-enabled').prop('checked', true); // 可选：根据业务需求设置默认启用状态
    });

    // 初始化加载
    if (currentDomain) {
        loadCacheRules(currentDomain);
    }
});