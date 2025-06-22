<?php
// 继承主文件的会话状态
$error = $error ?? ''; // 允许主文件传递错误信息
$targetLeft = $_SESSION['target_left'] ?? null;
if (!$targetLeft) {
    $sliderWidth = 40;
    $targetWidth = 20;
    $containerWidth = 300;
    if (isset($_SERVER['HTTP_USER_AGENT']) && (strpos($_SERVER['HTTP_USER_AGENT'], 'Mobile') !== false)) {
        $containerWidth = min(300, max(200, (int)(0.9 * ($_SERVER['HTTP_X_DEVICE_WIDTH'] ?? 300))));
    }
    $minLeft = $sliderWidth;
    $maxLeft = $containerWidth - $sliderWidth - $targetWidth;
    $targetLeft = rand($minLeft, $maxLeft);
    $_SESSION['target_left'] = $targetLeft;
    $_SESSION['container_width'] = $containerWidth; // 新增：存储容器宽度到Session
}
?>
<div class="slider-widget">
    <?php if (!empty($error)): ?>
        <p class="error-message"><?= $error ?></p>
    <?php endif; ?>
    <div class="slider-container">
        <div class="target-area" data-target-left="<?= $targetLeft ?>px"></div>  
        <div class="slider"></div>
    </div>
    <form id="sliderForm" method="post" action="login.php">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
        <input type="hidden" id="slider_verify" name="slider_verify">
        <input type="hidden" id="verify_slider" name="verify_slider" value="true">  <!-- 新增id属性 -->
    </form>
</div>
<script nonce="<?= $_SESSION['csp_nonce'] ?>" src="./js/slider.js"></script>
<link rel="stylesheet" href="./css/slider.css">