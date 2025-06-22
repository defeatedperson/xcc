<?php

// 首页：包含公共头部和首页内容
include 'header.php'; // 引入公共头部

?>
    <script src="./assets/chart.js"></script>
    <script src="./assets/dashboard.js" nonce="<?= $_SESSION['csp_nonce'] ?? '' ?>"></script>
    <!-- 新增：通过 meta 标签存储 CSRF 令牌 -->
    <meta id="csrf-token" name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <link rel="stylesheet" href="./assets/dashboard.css"> 
    <div class="main-content">
         <div class="index">
            <div class="index-header">
                <h1 class="index_title">数据概览</h1>
                <!-- 域名筛选下拉框 -->
                <select class="filter-select">
                    <option value="">请选择域名</option>
                    <?php 
                    // 实际应从数据库获取域名列表，示例数据
                    $domains = ['xcdream.com', 'cdn.example.com', 'img.xyz.com'];
                    foreach ($domains as $domain): ?>
                        <option value="<?= $domain ?>"><?= $domain ?></option>
                    <?php endforeach; ?>
                </select>
                <!-- 时间范围单选组 -->
                <div class="time-range-group"> <!-- 移除内联 style 属性 -->
                    <label>
                        <input type="radio" name="time_range" value="last_10min" checked> 近10分钟
                    </label>
                    <label>
                        <input type="radio" name="time_range" value="today"> 今日
                    </label>
                    <label>
                        <input type="radio" name="time_range" value="yesterday"> 昨日
                    </label>
                    <label>
                        <input type="radio" name="time_range" value="last_7days"> 近7日
                    </label>
                </div>
            </div>
            <div class="cards">
                <!-- 卡片容器（修改为动态数据） -->
                <div class="card">
                    <div>请求数（千次）</div>
                    <div id="totalRequests">加载中...</div>
                </div>
                <div class="card">
                    <div>出站流量</div>
                    <div id="totalTraffic">加载中...</div>
                </div>
                <div class="card">
                    <div>拦截IP数</div>
                    <div id="blockedIPs">加载中...</div>
                </div>
                <div class="card">
                    <div>绑定域名</div>
                    <div>加载中...</div> <!-- 暂保持静态（后续可扩展） -->
                </div>
            </div>
            <!-- 拆分图表容器为两个独立容器 -->
            <div class="chart-container">
                <h3 class="chart-title">请求数（千次/分钟）</h3> <!-- 移除内联style，添加class -->
                <canvas id="requestChart"></canvas>
            </div>
            <div class="chart-container">
                <h3 class="chart-title">出站流量（MB/分钟）</h3> <!-- 移除内联style，添加class -->
                <canvas id="trafficChart"></canvas>
            </div>
        </div>
    </div>
    <div class="main-content2"></div>

    

<?php include 'footer.php'; // 引入公共底部 ?>