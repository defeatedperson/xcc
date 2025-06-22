
# 缓存配置模板
# 变量说明：$domain=域名，$backend_ip=后端服务器 IP，$cache_extensions=缓存文件后缀（如"jpg|jpeg|png"），$cache_valid_200_301_302=200/301/302状态码缓存时间（如"7d"）

# 缓存静态
location ~* \.(<?= $cache_extensions ?>)$ {
    access_by_lua_block {
        local counter = require("request_counter")
        local ip_counter = require("ip_counter")
        local black_ip_counter = require("black_ip_counter")
        local remote_addr = ngx.var.remote_addr
        local domain = ngx.var.host

        if black_ip_counter.is_banned(remote_addr) then
            ngx.exit(444)
            return
        end

        local global_current, err = counter.increment_global(domain)
        if not global_current then
            return ngx.exit(500)
        end
        
        local custom_current, err = counter.increment_custom(domain, remote_addr)
        if not custom_current then
            return ngx.exit(500)
        end

        local is_global_over = counter.check_global_threshold(domain)
        local is_custom_over = counter.check_custom_threshold(domain, remote_addr)
        local is_over = is_global_over or is_custom_over

        if not is_over then
            return 
        end

        local is_ip_valid = ip_counter.check_valid(remote_addr)
        if is_ip_valid then
            return  
        end

        local blacklist_counter = require("blacklist_counter")
        local is_trigger_ban = blacklist_counter.increment_verification(remote_addr, domain)
        if is_trigger_ban then
            ngx.exit(444)
            return
        end

        local global = require("global")
        local request_url = ngx.var.request_uri
        global.pass_verification_params(remote_addr, request_url, domain)
        ngx.redirect("/lua/verification_page.html?ip=" .. remote_addr)
    }

    proxy_pass <?= $backend_ip ?>;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;

    proxy_cache <?= $domain ?>_cache;
    proxy_cache_valid 200 301 302 <?= $cache_valid_200_301_302 ?>;
    proxy_cache_valid any 1h;
}