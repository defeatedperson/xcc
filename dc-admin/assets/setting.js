window.onload = function() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    if (!csrfToken) {
        showAlert('error', 'CSRF令牌未获取，请刷新页面或重新登录');
        return;
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
    document.querySelector('.pagination .page-btn:nth-child(3)').onclick = function() {
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