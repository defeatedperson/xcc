<?php
// 扩展页：包含公共头部和系统设置表单
include 'header.php';
?>
    <meta id="csrf-token" name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <link rel="stylesheet" href="./assets/domains.css">
    <link rel="stylesheet" href="./assets/extension.css">
    <link rel="stylesheet" href="./assets/manage.css">
    <script src="/dc-admin/assets/jquery-3.6.0.min.js" nonce="<?= $_SESSION['csp_nonce'] ?? '' ?>"></script>
    <script src="./assets/extension.js" nonce="<?= $_SESSION['csp_nonce'] ?? '' ?>"></script>
    <script src="./assets/failover.js" nonce="<?= $_SESSION['csp_nonce'] ?? '' ?>"></script>
    <script src="./assets/autossl.js" nonce="<?= $_SESSION['csp_nonce'] ?? '' ?>"></script>

    <div class="main-content">
        <div class="domain-title-container">
            <h2 class="domain_title2">故障节点</h2>
            <div class="pagination">
                <div class="update-time" id="updateTime">最后更新: --</div>
            </div>
        </div>
        <div class="node-mo">
            <div class="node-status-section">
                <div class="node-status-list" id="faultNodesList">
                    <!-- 故障节点将通过JavaScript动态加载 -->
                </div>
            </div>
        </div>
    </div>
    <div class="main-content">
        <div class="domain-title-container">
            <h2 class="domain_title2">XDM设置</h2>
            <div class="pagination">
                <button id="toggleApiKey" class="btn-new">设置</button>
            </div>
        </div>
        <div class="node-mo2">
            <div class="update-time">本页面功能依赖XDM服务。API密钥用于节点与控制中心之间的通信验证</div>
            
                        <form id="apikeyForm" class="apikey-form hidden">
                <div class="form-group">
                    <label for="apikey">API密钥</label>
                    <div class="input-with-button">
                        <input type="text" id="apikey" placeholder="请输入API密钥（至少8个字符）">
                        <button type="button" id="generateApiKey" class="btn-new">随机生成</button>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="nodekey">节点密钥</label>
                    <div class="input-with-button">
                        <input type="text" id="nodekey" placeholder="请输入节点密钥（至少8个字符）">
                        <button type="button" id="generateNodeKey" class="btn-new">随机生成</button>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="serviceUrl">XDM服务地址</label>
                    <input type="text" id="serviceUrl" placeholder="请输入XDM服务地址，例如：https://example.com[:端口号]">
                </div>
                
                <div class="apikey-actions">
                    <button type="submit" class="apikey-submit">保存</button>
                </div>
            </form>
        </div>
    </div>
        <div class="main-content">
        <div class="domain-title-container">
            <h2 class="domain_title2">故障转移</h2>
            <div class="pagination">
                <button class="btn-new" id="addDomainBtn">新增</button>
            </div>
        </div>
        <div class="manage-section">
            <table class="domain-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>HOST</th>
                        <th>主记录</th>
                        <th>备用记录</th>
                        <th>线路</th>
                        <th>监控方式</th>
                        <th>状态</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="failoverList">
                    <!-- 动态数据在此渲染 -->
                    <tr><td colspan="8" class="loading">加载中...</td></tr>
                </tbody>
            </table>
            <div class="update-time">Tips:下线具有更高优先级。节点下线之后会持续10-30分钟。数据5分钟同步一次。请不要同时在本页面+腾讯云控制台创建相同的解析！</div>
        </div>
        <!-- 表单容器 -->
        <div class="form-container hidden" id="failoverFormContainer">
            <form id="failoverForm" class="apikey-form">
                <input type="hidden" id="domainId" value="">
                <div class="form-group">
                    <label for="host">host值(请勿添加腾讯云已添加的host值)</label>
                    <input type="text" id="host" placeholder="请输入host值（字母数字和小数点，最多5位）">
                </div>
                <div class="form-group">
                    <label for="mainType">主记录类型</label>
                    <select id="mainType">
                        <option value="A">A</option>
                        <option value="AAAA">AAAA</option>
                        <option value="CNAME">CNAME</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="mainValue">主记录值</label>
                    <input type="text" id="mainValue" placeholder="请输入主记录值">
                </div>
                <div class="form-group">
                    <label for="backupType">备用记录类型</label>
                    <select id="backupType">
                        <option value="A">A</option>
                        <option value="AAAA">AAAA</option>
                        <option value="CNAME">CNAME</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="backupValue">备用记录值</label>
                    <input type="text" id="backupValue" placeholder="请输入备用记录值">
                </div>
                <div class="form-group">
                    <label for="line">线路</label>
                    <select id="line">
                        <option value="默认">默认</option>
                        <option value="境外">境外</option>
                        <option value="电信">电信</option>
                        <option value="联通">联通</option>
                        <option value="移动">移动</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="jkType">监控方式</label>
                    <select id="jkType">
                        <option value="ping">PING</option>
                        <option value="monitor">XDM被控</option>
                    </select>
                </div>
                <div class="apikey-actions">
                    <button type="submit" class="apikey-submit">保存</button>
                    <button type="button" id="cancelBtn" class="btn-clear">取消</button>
                </div>
            </form>
        </div>
    </div>
    <div class="main-content">
        <div class="domain-title-container">
            <h2 class="domain_title2">自动SSL</h2>
            <div class="pagination">
                <button class="btn-new" id="addSslDomainBtn">新增</button>
            </div>
        </div>
        
        <div class="manage-section">
            <table class="domain-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>域名</th>
                        <th>证书状态</th>
                        <th>到期时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="sslDomainList">
                    <!-- 动态数据在此渲染 -->
                    <tr><td colspan="5" class="loading">加载中...</td></tr>
                </tbody>
            </table>
            <div class="update-time">Tips:需要先开启域名“自动SSL”,才能添加自动SSL域名。对于“未安装/未使用”的证书,请尽快删除自动SSL域名(避免增加XDM负载)</div>
        </div>
        <div class="form-container hidden" id="sslFormContainer">
            <form id="sslForm" class="apikey-form">
                <input type="hidden" id="sslDomainId" value="">
                <div class="form-group">
                    <label for="sslDomain">域名</label>
                    <div class="select-wrapper">
                        <select id="sslDomain" class="form-control">
                            <option value="">-- 请选择已开启自动SSL的域名 --</option>
                            <!-- 域名选项将通过JavaScript动态加载 -->
                        </select>
                    </div>
                </div>
                <div class="apikey-actions">
                    <button type="submit" class="apikey-submit">保存</button>
                    <button type="button" id="sslCancelBtn" class="btn-clear">取消</button>
                </div>
            </form>
        </div>
    </div>
    <!-- 提示模块 -->
    <div class="main-content">
        <div class="tutorial-box">
            <h3>① 自动SSL什么时候触发？</h3>
            <ol>
                <li>每天执行一次，一次申请一个证书，到期前30天会尝试自动续签。</li>
                <li>建议先绑定一个正常的SSL证书，之后程序会自动运行。</li>
            </ol>
            <h3>① 什么是XDM调度节点？</h3>
            <ol>
                <li><b>XDM</b>全名“星尘DNS监控”服务</li>
                <li>自动SSL/自动故障转移需要依赖这个额外服务。</li>
            </ol>
            <h3>② XDM的配置要求是什么？</h3>
            <ol>
                <li>有IPV4公网的服务器（含NAT共享IP），内存建议1G起步。</li>
            </ol>
            <h3>③ 如何安装XDM调度节点？</h3>
            <ol>
                <li>教程请访问<a href="https://re.xcdream.com/9311.html" target="_blank">https://re.xcdream.com/9311.html</a>查看XDM教程</li>
            </ol>
        </div>
    </div>

    
    <div class="main-content2"></div>

<?php include 'footer.php'; ?>
