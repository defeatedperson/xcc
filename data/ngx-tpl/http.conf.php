
# 啥都没版 Nginx 配置模板（无 HTTPS ）
# 变量说明：$domain=域名，$backend_ip=后端服务器 IP

server {
    listen 80;
    server_name <?= $domain ?>;

    # 引入黑白名单（动态路径）
    include sites-enabled/list/<?= $domain ?>.list.conf;

    # 引入公共 Lua 模块
    include sites-enabled/common/common.conf;

    # 代理 favicon.ico（动态 IP）
    location = /favicon.ico {
        proxy_pass <?= $backend_ip ?>/favicon.ico;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        access_log off;
        log_not_found off;
    }

    # 引入静态资源缓存配置（动态域名）
    include sites-enabled/cache/<?= $domain ?>.cache.conf;

    # 反向代理（动态 IP）
    location @backend {
        proxy_pass <?= $backend_ip ?>;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    }
    
    # 全局流量统计
    log_by_lua_block {
        local domain_counter = require("domain_counter")
        local domain = ngx.var.host
        local bytes_sent = tonumber(ngx.var.bytes_sent) or 0
        domain_counter.increment_flow(domain, bytes_sent)
    }

    # 错误页面
    error_page 500 502 503 504 /50x.html;
    location = /50x.html {
        root html;
    }
}