// 页面加载完成后绑定关闭事件
document.addEventListener('DOMContentLoaded', function() {
    // 关闭按钮通用逻辑
    document.querySelectorAll('.close-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            this.parentElement.style.display = 'none';
        });
    });
});

// 公共弹窗显示函数（修改后）
function showAlert(type, message) {
    const errorEl = document.getElementById('customError');
    const successEl = document.getElementById('customSuccess');
    const infoEl = document.getElementById('customInfo'); // 新增：获取info弹窗

    // 先隐藏所有弹窗
    errorEl.style.display = 'none';
    successEl.style.display = 'none';
    infoEl.style.display = 'none'; // 新增：隐藏info弹窗

    // 根据类型显示对应弹窗
    if(type === 'error') {
        document.getElementById('errorMsg').textContent = message;
        errorEl.style.display = 'block';
    } else if(type === 'success') {
        document.getElementById('successMsg').textContent = message;
        successEl.style.display = 'block';
    } else if(type === 'info') { // 新增：处理info类型
        document.getElementById('infoMsg').textContent = message;
        infoEl.style.display = 'block';
    }
}

// 显示确认弹窗（message: 提示文本，onConfirm: 确认回调）
let confirmCallback = null; // 存储当前的确认回调函数

// 在DOMContentLoaded事件中初始化一次性事件监听
document.addEventListener('DOMContentLoaded', function() {
    // 关闭按钮通用逻辑
    document.querySelectorAll('.close-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            this.parentElement.style.display = 'none';
        });
    });
    
    // 确认弹窗按钮事件委托
    const confirmBox = document.getElementById('customConfirm');
    const confirmBtn = confirmBox.querySelector('.btn-confirm');
    const cancelBtn = confirmBox.querySelector('.btn-cancel');
    
    // 确认按钮只绑定一次事件
    confirmBtn.addEventListener('click', () => {
        if (typeof confirmCallback === 'function') {
            confirmCallback();
        }
        confirmBox.style.display = 'none';
    });
    
    // 取消按钮只绑定一次事件
    cancelBtn.addEventListener('click', () => {
        confirmBox.style.display = 'none';
    });
    
    // 点击遮罩层关闭（可选）
    confirmBox.addEventListener('click', (e) => {
        if (e.target === confirmBox) confirmBox.style.display = 'none';
    });
});

function showConfirm(message, onConfirm) {
    const confirmBox = document.getElementById('customConfirm');
    const messageEl = confirmBox.querySelector('.alert-message');
    
    // 设置提示内容
    messageEl.innerHTML = message;
    
    // 存储回调函数
    confirmCallback = onConfirm;
    
    // 显示弹窗
    confirmBox.style.display = 'block';
}