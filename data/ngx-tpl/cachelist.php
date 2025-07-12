# 为域名单独配置缓存
# 变量说明：$domain=域名
proxy_cache_path /usr/local/openresty/nginx/cache/<?= $domain ?> levels=1:2 keys_zone=<?= $domain ?>_cache:100m inactive=1h;