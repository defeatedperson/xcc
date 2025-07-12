window.onload = function() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    if (!csrfToken) {
        showAlert('error', 'CSRF令牌未获取，请刷新页面或重新登录');
        return;
    }

    // 上传表单事件绑定
    const uploadForm = document.getElementById('uploadForm');
    if (uploadForm) {
        uploadForm.onsubmit = function(e) {
            e.preventDefault();
            const fileInput = document.getElementById('zipFileInput');
            const status = document.getElementById('uploadStatus');
            status.textContent = '';
            status.style.color = '#888';

            if (!fileInput.files.length) {
                status.textContent = '请选择zip文件';
                status.style.color = '#e53e3e';
                return;
            }
            const file = fileInput.files[0];
            if (!file.name.toLowerCase().endsWith('.zip')) {
                status.textContent = '只允许上传zip文件';
                status.style.color = '#e53e3e';
                return;
            }
            if (file.size > 50 * 1024 * 1024) {
                status.textContent = '文件大小不能超过50MB';
                status.style.color = '#e53e3e';
                return;
            }

            status.textContent = '上传中...';

            const formData = new FormData();
            formData.append('file', file);
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);

            fetch('/sys-upload.php', {
                method: 'POST',
                body: formData
            }).then(res => res.json()).then(data => {
                if (data.success) {
                    status.textContent = '上传成功';
                    status.style.color = '#22b573';
                } else {
                    status.textContent = data.message || '上传失败';
                    status.style.color = '#e53e3e';
                }
            }).catch(() => {
                status.textContent = '上传失败';
                status.style.color = '#e53e3e';
            });
        };
    }

    // 删除按钮事件绑定
    const deleteBtn = document.querySelector('.pagination .btn-new:nth-child(2)');
    if (deleteBtn) {
        deleteBtn.onclick = function() {
            if (!confirm('确定要删除上传的zip文件吗？')) return;
            const status = document.getElementById('uploadStatus');
            status.textContent = '正在删除...';
            status.style.color = '#888';

            fetch('/sys-delete.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'csrf_token=' + encodeURIComponent(document.querySelector('meta[name="csrf-token"]').content)
            })
            .then(res => res.json())
            .then(data => {
                status.textContent = data.message;
                status.style.color = data.success ? '#22b573' : '#e53e3e';
            })
            .catch(() => {
                status.textContent = '删除失败';
                status.style.color = '#e53e3e';
            });
        };
    }

    // 更新按钮事件绑定
    const updateBtn = document.getElementById('updateZipBtn');
    if (updateBtn) {
        updateBtn.onclick = function() {
            // 添加确认弹窗，二次确认更新操作
            showConfirm('确定要更新系统吗？此操作将覆盖现有系统文件（清空日志）', function() {
                const status = document.getElementById('uploadStatus');
                status.textContent = '正在更新...';
                status.style.color = '#888';

                fetch('/sys-update.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'csrf_token=' + encodeURIComponent(document.querySelector('meta[name="csrf-token"]').content)
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        status.textContent = '第一步更新成功，正在完成收尾...';
                        // 第二步，调用 sys-update-over.php
                        fetch('/sys-update-over.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: 'csrf_token=' + encodeURIComponent(document.querySelector('meta[name="csrf-token"]').content)
                        })
                        .then(res2 => res2.json())
                        .then(data2 => {
                            if (data2.success) {
                                status.textContent = '更新完成，正在刷新页面...';
                                status.style.color = '#22b573';
                                setTimeout(() => location.reload(), 1200);
                            } else {
                                status.textContent = data2.message || '收尾操作失败';
                                status.style.color = '#e53e3e';
                            }
                        })
                        .catch(() => {
                            status.textContent = '收尾操作失败';
                            status.style.color = '#e53e3e';
                        });
                    } else {
                        status.textContent = data.message || '更新失败';
                        status.style.color = '#e53e3e';
                    }
                })
                .catch(() => {
                    status.textContent = '更新失败';
                    status.style.color = '#e53e3e';
                });
            });
        };
    }

    // 获取版本信息
    function getVersionInfo() {
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        fetch('/sys-version.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'csrf_token=' + encodeURIComponent(csrfToken)
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.data) {
                const versionBadge = document.getElementById('versionBadge');
                if (versionBadge) {
                    versionBadge.textContent = 'v' + data.data.version;
                    versionBadge.dataset.version = data.data.version;
                    versionBadge.dataset.changelog = JSON.stringify(data.data.changelog || []);
                }
            }
        })
        .catch(err => {
            console.error('获取版本信息失败:', err);
            const versionBadge = document.getElementById('versionBadge');
            if (versionBadge) {
                versionBadge.textContent = '获取失败';
            }
        });
    }
    
    // 初始化获取版本
    getVersionInfo();
    
    // 点击版本显示更新内容
    const versionBadge = document.getElementById('versionBadge');
    if (versionBadge) {
        versionBadge.onclick = function() {
            const version = this.dataset.version || '未知版本';
            let changelog = [];
            try {
                changelog = JSON.parse(this.dataset.changelog || '[]');
            } catch (e) {
                console.error('解析更新内容失败', e);
            }
            
            // 创建模态框
            const modal = document.createElement('div');
            modal.className = 'changelog-modal';
            modal.innerHTML = `
                <div class="changelog-content">
                    <div class="changelog-title">
                        <h3>版本 ${version} 更新内容</h3>
                        <span class="close-changelog">×</span>
                    </div>
                    <ul class="changelog-list">
                        ${changelog.length > 0 ? 
                            changelog.map(item => `<li>${item}</li>`).join('') : 
                            '<li>无更新内容记录</li>'}
                    </ul>
                </div>
            `;
            
            // 添加关闭事件
            modal.querySelector('.close-changelog').onclick = function() {
                document.body.removeChild(modal);
            };
            
            // 点击外部也可关闭
            modal.onclick = function(e) {
                if (e.target === modal) {
                    document.body.removeChild(modal);
                }
            };
            
            document.body.appendChild(modal);
        };
    }
    
    // 复制安装命令按钮
    document.getElementById('copyInstallCmdBtn').onclick = function() {
        const cmd = document.getElementById('installCommand').textContent;
        if (!cmd) {
            showAlert('error', '没有可复制的命令');
            return;
        }
        navigator.clipboard.writeText(cmd).then(() => {
            showAlert('success', '安装命令已复制');
        }).catch(() => {
            showAlert('error', '复制失败，请手动复制');
        });
    };

    // 初始禁用新增按钮
    document.getElementById('addQuestionBtn').disabled = true;

    // 点击刷新按钮
    document.getElementById('refreshQuestionsBtn').onclick = function() {
        document.getElementById('questionsTableTip').style.display = 'none';
        document.getElementById('questionsTableScroll').classList.remove('hidden');
        // 禁用新增按钮，直到数据加载完成
        document.getElementById('addQuestionBtn').disabled = true;
        loadQuestions(csrfToken);
    };

    // 保存按钮事件
    document.getElementById('saveQuestionsBtn').onclick = function() {
        saveQuestions(csrfToken);
    };
    // 新增题目
    document.getElementById('addQuestionBtn').onclick = function() {
        addQuestionRow();
    };

    // 动态检测安装模式
    checkInstallMode();

    // 绑定按钮事件
    document.getElementById('startInstallBtn').onclick = function() {
        setInstallMode('start_install');
    };
    document.getElementById('closeInstallBtn').onclick = function() {
        setInstallMode('close_install');
    };

    // 生成按钮事件
    document.getElementById('saveQuestionsBtn').onclick = function() {
        saveQuestions(csrfToken);
    };

    // 新增：生成（打包）按钮事件，调用确认弹窗
    document.getElementById('generateBtn').onclick = function() {
        showConfirm('确定要生成并打包题库吗？此操作会覆盖原有题库并生成新安装包。', function() {
            generatePackage(csrfToken);
        });
    };
};

// 新增：生成打包函数
function generatePackage(csrfToken) {
    fetch('/node/node-re.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            csrf_token: csrfToken
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showAlert('success', '打包成功！');
        } else {
            showAlert('error', data.message || '打包失败');
        }
    })
    .catch(() => showAlert('error', '网络异常'));
}

// 加载题库
function loadQuestions(csrfToken) {
    fetch('/node/set/set-question.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            csrf_token: csrfToken,
            action: 'get'
        })
    })
    .then(res => res.json())
    .then(data => {
        if (!data.success) {
            showAlert('error', data.message || '题库加载失败');
            return;
        }
        renderQuestions(data.data || []);
        // 加载完成后启用新增按钮
        document.getElementById('addQuestionBtn').disabled = false;
    })
    .catch(err => {
        showAlert('error', '题库加载异常：' + err);
        // 加载失败也保持禁用
        document.getElementById('addQuestionBtn').disabled = true;
    });
}

// 渲染题库到表格（每题固定3个选项）
function renderQuestions(questions) {
    const tbody = document.getElementById('questionsTbody');
    tbody.innerHTML = '';
    questions.forEach((q, idx) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input type="text" class="title-zh better-input" value="${q.title?.zh || ''}" placeholder="中文题干"></td>
            <td><input type="text" class="title-en better-input" value="${q.title?.en || ''}" placeholder="英文题干"></td>
            <td><input type="text" class="title-ja better-input" value="${q.title?.ja || ''}" placeholder="日文题干"></td>
            <td>
                <div class="options-area">
                    ${[0,1,2].map(i => {
                        const opt = q.options?.[i] || {};
                        return `
                        <div class="option-row">
                            <input type="text" class="opt-zh better-input" value="${opt.zh || ''}" placeholder="中文">
                            <input type="text" class="opt-en better-input" value="${opt.en || ''}" placeholder="英文">
                            <input type="text" class="opt-ja better-input" value="${opt.ja || ''}" placeholder="日文">
                            <input type="radio" name="answer${idx}" ${q.answer === i+1 ? 'checked' : ''} title="正确答案">
                        </div>
                        `;
                    }).join('')}
                </div>
            </td>
            <td>
                <button type="button" class="delQuestionBtn">删除（旧的）</button>
            </td>
        `;
        tbody.appendChild(tr);
    });

    // 绑定题目删除
    document.querySelectorAll('.delQuestionBtn').forEach((btn, i) => {
        btn.onclick = function() {
            btn.closest('tr').remove();
        };
    });
}

// 新增题目（固定3个选项）
function addQuestionRow() {
    const tbody = document.getElementById('questionsTbody');
    if (tbody.children.length >= 12) {
        showAlert('error', '最多只能有12道题');
        return;
    }
    const idx = tbody.children.length;
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="text" class="title-zh better-input" placeholder="中文题干"></td>
        <td><input type="text" class="title-en better-input" placeholder="英文题干"></td>
        <td><input type="text" class="title-ja better-input" placeholder="日文题干"></td>
        <td>
            <div class="options-area">
                ${[0,1,2].map(i => `
                <div class="option-row">
                    <input type="text" class="opt-zh better-input" placeholder="中文">
                    <input type="text" class="opt-en better-input" placeholder="英文">
                    <input type="text" class="opt-ja better-input" placeholder="日文">
                    <input type="radio" name="answer${idx}" title="正确答案">
                </div>
                `).join('')}
            </div>
        </td>
        <td>
            <button type="button" class="delQuestionBtn">删除（新增）</button>
        </td>
    `;
    tbody.appendChild(tr);

    // 绑定删除事件
    tr.querySelector('.delQuestionBtn').onclick = function() {
        tr.remove();
    };
}

// 保存题库
function saveQuestions(csrfToken) {
    const tbody = document.getElementById('questionsTbody');
    const questions = [];
    for (const tr of tbody.children) {
        const title = {
            zh: tr.querySelector('.title-zh').value.trim(),
            en: tr.querySelector('.title-en').value.trim(),
            ja: tr.querySelector('.title-ja').value.trim()
        };
        const options = [];
        let answer = 0;
        const optionRows = tr.querySelectorAll('.option-row');
        optionRows.forEach((optRow, i) => {
            const opt = {
                zh: optRow.querySelector('.opt-zh').value.trim(),
                en: optRow.querySelector('.opt-en').value.trim(),
                ja: optRow.querySelector('.opt-ja').value.trim()
            };
            options.push(opt);
            if (optRow.querySelector('input[type="radio"]').checked) {
                answer = i + 1;
            }
        });
        questions.push({ title, options, answer });
    }
    if (questions.length < 3) {
        showAlert('error', '题目数量不能少于3道');
        return;
    }
    fetch('/node/set/set-question.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            csrf_token: csrfToken,
            action: 'set',
            questions: JSON.stringify(questions)
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showAlert('success', '题库保存成功');
            loadQuestions(csrfToken);
        } else {
            showAlert('error', data.message || '题库保存失败');
        }
    })
    .catch(err => showAlert('error', '题库保存异常：' + err));
}

function checkInstallMode() {
    fetch('/node/node-setup.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            csrf_token: document.querySelector('meta[name="csrf-token"]').content
        })
    })
    .then(res => res.json())
    .then(data => {
        updateInstallModeUI(data.install_mode, data.install_command);
    })
    .catch(() => {
        updateInstallModeUI(0, '');
    });
}

function setInstallMode(action) {
    fetch('/node/node-setup.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            csrf_token: document.querySelector('meta[name="csrf-token"]').content,
            action: action
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showAlert('success', action === 'start_install' ? '安装模式已开启' : '安装模式已关闭');
            updateInstallModeUI(data.install_mode, data.install_command);
        } else {
            showAlert('error', data.message || '操作失败');
        }
    })
    .catch(() => showAlert('error', '网络异常'));
}

function updateInstallModeUI(mode, installCommand) {
    const dot = document.getElementById('installStatusDot');
    const text = document.getElementById('installStatusText');
    const startBtn = document.getElementById('startInstallBtn');
    const closeBtn = document.getElementById('closeInstallBtn');
    const cmdBlock = document.getElementById('installCommandBlock');
    const cmd = document.getElementById('installCommand');

    if (mode == 1) {
        dot.className = 'status-dot status-on';
        text.textContent = '安装模式已开启';
        startBtn.disabled = true;
        closeBtn.disabled = false;
        cmdBlock.style.display = '';
        cmd.textContent = installCommand || '';
    } else {
        dot.className = 'status-dot status-off';
        text.textContent = '安装模式已关闭';
        startBtn.disabled = false;
        closeBtn.disabled = true;
        cmdBlock.style.display = 'none';
        cmd.textContent = '';
    }
}