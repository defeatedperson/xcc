function initSlider() {
    const sliderContainer = document.querySelector('.slider-container');
    const slider = document.querySelector('.slider');
    // 新增：获取目标区域元素并设置 left 样式
    const targetArea = document.querySelector('.target-area');
    if (targetArea) {
        const targetLeft = targetArea.dataset.targetLeft; // 从 data 属性读取值（如 "120px"）
        targetArea.style.left = targetLeft; // 动态设置 left 样式
    }
    
    // 移除 targetLeft 的PHP输出，仅传递滑块位置到服务端
    let isDragging = false;
    let startX;
    let initialLeft = 0;
    let dragStartTime; // 新增：记录拖动开始时间

    // 统一获取坐标的函数（兼容鼠标和触摸）
    function getClientX(e) {
        return e.touches ? e.touches[0].clientX : e.clientX;
    }

    // 鼠标/触摸按下事件
    slider.addEventListener('mousedown', handleStart);
    slider.addEventListener('touchstart', handleStart);
    function handleStart(e) {
        isDragging = true;
        dragStartTime = Date.now(); // 记录开始时间
        startX = getClientX(e);
        initialLeft = parseInt(window.getComputedStyle(slider).left) || 0;
        e.preventDefault(); // 阻止默认触摸行为（如滚动）
    }

    // 鼠标/触摸移动事件
    document.addEventListener('mousemove', handleMove);
    document.addEventListener('touchmove', handleMove);
    function handleMove(e) {
        if (!isDragging) return;
        const offsetX = getClientX(e) - startX;
        let newLeft = initialLeft + offsetX;

        // 限制滑块在容器内（兼容动态宽度）
        const containerWidth = sliderContainer.offsetWidth;
        const maxLeft = containerWidth - slider.offsetWidth;
        newLeft = Math.max(0, Math.min(newLeft, maxLeft));

        slider.style.left = newLeft + 'px';
    }

    // 鼠标/触摸松开事件
    document.addEventListener('mouseup', handleEnd);
    document.addEventListener('touchend', handleEnd);

    // 新增：移动端弹窗宽度适配
    function adjustPopupWidth() {
        const popupWrapper = document.querySelector('.popup-wrapper');
        const isMobile = /Mobile|Android|iOS|iPhone|iPad/i.test(navigator.userAgent);
        
        if (isMobile) {
            const viewportWidth = window.innerWidth;
            // 设置弹窗宽度为视口宽度的95%（保留左右2.5%边距）
            popupWrapper.style.width = `${Math.min(viewportWidth * 0.95, 400)}px`; // 最大不超过400px
            popupWrapper.style.padding = '20px 16px'; // 移动端减少内边距
        }
    }

    function handleEnd() {
        if (isDragging) {
            const dragDuration = Date.now() - dragStartTime;
            if (dragDuration < 1000) { // 滑动时间小于1秒视为异常
                alert("操作过快，请重新滑动");
                return;
            }
            isDragging = false;
            const sliderLeft = parseInt(window.getComputedStyle(slider).left);
            
            // 仅提交滑块位置，不包含目标位置信息
            const sliderVerifyEl = document.getElementById('slider_verify');
            if (sliderVerifyEl) {
                sliderVerifyEl.value = sliderLeft;
                document.getElementById('sliderForm').submit();
            }
        }
    }

    // 页面加载时执行宽度调整
    adjustPopupWidth();
    // 窗口尺寸变化时重新调整（如横屏切换）
    window.addEventListener('resize', adjustPopupWidth);
    
}

window.onload = initSlider;