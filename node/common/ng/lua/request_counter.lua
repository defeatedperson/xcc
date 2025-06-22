local config = require("config")
local ngx_shared = ngx.shared
local ngx = ngx  -- 显式引用 ngx 模块

local _M = {}
-- 缓存共享内存对象（仅初始化一次，避免重复查询）
local shm = nil

-- 初始化共享内存（需在 nginx.conf 中提前声明）
local function init_shm()
    if shm then return shm end  -- 已初始化则直接返回缓存对象
    local shm_name = "request_counter"
    shm = ngx_shared[shm_name]
    if not shm then
        error("共享内存 " .. shm_name .. " 未在 nginx.conf 中声明")
    end
    return shm
end

-- 工具函数：生成安全的键名（转义特殊字符）
local function safe_key(part)
    return part and ngx.escape_uri(part) or "default"
end

-- 新增：检查 IP 是否在缓存时间内
local function is_ip_in_verification_cache(ip)
    local shm = init_shm()
    local cache_key = "verification_cache_" .. safe_key(ip)
    local last_verification_time = shm:get(cache_key)
    if last_verification_time then
        local current_time = ngx.time()
        return (current_time - last_verification_time) < 30
    end
    return false
end

-- 新增：标记 IP 验证时间
local function mark_ip_verification_time(ip)
    local shm = init_shm()
    local cache_key = "verification_cache_" .. safe_key(ip)
    local current_time = ngx.time()
    shm:set(cache_key, current_time, 30)
end

-- 递增全局规则计数（域名维度）
function _M.increment_global(domain)
    local shm = init_shm()
    -- 生成安全键名（转义 domain 避免特殊字符）
    local safe_domain = safe_key(domain)
    local counter_key = "global_request_count_" .. safe_domain
    -- 递增计数器（初始值为0，无过期时间）
    local current, err = shm:incr(counter_key, 1, 0)
    if not current then
        ngx.log(ngx.ERR, "全局计数递增失败（域名：" .. (domain or "default") .. "）: ", err)
        return nil, err
    end
    return current
end

-- 检查全局规则阈值（域名维度）
function _M.check_global_threshold(domain)
    local shm = init_shm()
    local domain_config = config.domains[domain] or config.default
    local safe_domain = safe_key(domain)
    local counter_key = "global_request_count_" .. safe_domain
    local current = shm:get(counter_key) or 0
    local is_over = current >= domain_config.threshold


    -- 新增：触发后缓存验证状态（例如持续 5 分钟）
    if is_over then
        local verification_key = "global_verification_active_" .. safe_domain
        shm:set(verification_key, true, 300)  -- 触发后强制验证 5 分钟
    end

    -- 优先检查缓存的验证状态（若未过期则保持验证）
    local verification_active = shm:get("global_verification_active_" .. safe_domain)
    return verification_active or is_over
end

-- 递增个人规则计数（域名+IP维度）
function _M.increment_custom(domain, ip)
    local shm = init_shm()
    -- 生成安全键名（转义 domain 和 ip 避免特殊字符）
    local safe_domain = safe_key(domain)
    local safe_ip = safe_key(ip)
    local counter_key = "custom_request_count_" .. safe_domain .. "_" .. safe_ip
    
    -- 获取个人规则的统计周期（用于设置计数器过期时间）
    local domain_config = config.domains[domain] or config.default
    local custom_config = domain_config.custom_rules or config.default.custom_rules
    -- 修复：补充缺失的 `or` 运算符，确保层级回退逻辑正确
    local ttl = custom_config.interval or (config.default.custom_rules and config.default.custom_rules.interval) or 30 -- 默认30秒（与 config.default 一致）
    
    -- 递增计数器并设置过期时间（自动清理旧数据）
    local current, err = shm:incr(counter_key, 1, 0, ttl)
    if not current then
        ngx.log(ngx.ERR, "个人计数递增失败（域名：" .. (domain or "default") .. "，IP：" .. (ip or "default_ip") .. "）: ", err)
        return nil, err
    end
    return current
end

-- 检查个人规则阈值（域名+IP维度）
function _M.check_custom_threshold(domain, ip)


    -- 检查 IP 是否在缓存时间内
    if is_ip_in_verification_cache(ip) then
        ngx.log(ngx.INFO, "IP " .. ip .. " 在验证缓存时间内，不允许再次验证")
        return false
    end

    local shm = init_shm()
    local domain_config = config.domains[domain] or config.default
    local custom_config = domain_config.custom_rules or config.default.custom_rules
    local safe_domain = safe_key(domain)
    local safe_ip = safe_key(ip)
    local counter_key = "custom_request_count_" .. safe_domain .. "_" .. safe_ip
    local current = shm:get(counter_key) or 0

    -- 获取个人规则的 max_threshold（优先使用域名配置，无则用默认）
    local max_threshold = custom_config.max_threshold or config.default.custom_rules.max_threshold or 100  -- 默认值与 config.lua 一致
    
    -- 判断请求数是否超过 max_threshold（直接封禁）
    if current >= max_threshold then
        local black_ip_counter = require("black_ip_counter")
        local ban_success = black_ip_counter.ban_ip(ip)
        if ban_success then
            ngx.log(ngx.INFO, "IP " .. ip .. " 触发个人规则上限（" .. current .. "/" .. max_threshold .. "），已封禁10分钟")
            mark_ip_verification_time(ip)  -- 标记验证时间避免短时间重复触发
            return true  -- 标记为需要处理（可根据业务调整返回值）
        else
            ngx.log(ngx.ERR, "IP " .. ip .. " 封禁失败")
            return false
        end
    elseif current >= custom_config.threshold then
        local ip_counter = require("ip_counter")
        if ip_counter.check_valid(ip) then
            -- 调用 ip_counter 模块的方法移除 IP 验证信息
            ip_counter.invalidate_ip(ip)
            ngx.log(ngx.INFO, "IP " .. ip .. " 触发个人规则（" .. current .. "/" .. custom_config.threshold .. "），移除验证标记")
            -- 标记 IP 验证时间（避免短时间内重复验证）
            mark_ip_verification_time(ip)
        end
        return true  -- 标记为需要人机验证
    end

    return false  -- 未触发阈值
end

-- 定时重置全局计数器（供定时器调用）
local function reset_global_timer(premature)
    if premature then return end  -- 定时器被提前终止时退出

    -- 确保 shm 已经初始化
    local shm = init_shm()
    
    -- 初始化空域名配置（避免 nil 错误）
    local domains = config.domains or {}  -- 关键修改：处理 config.domains 为 nil 的情况

    -- 遍历所有域名配置，重置全局计数器
    for domain in pairs(domains) do
        local safe_domain = safe_key(domain)
        local counter_key = "global_request_count_" .. safe_domain
        shm:set(counter_key, 0)
        ngx.log(ngx.INFO, "全局计数器重置（域名：" .. domain .. "，周期：" .. (domains[domain].interval or config.default.interval) .. "秒）")  -- 补充默认周期
    end
    
    -- 重置默认域名的全局计数器（未指定域名时使用）
    local default_counter_key = "global_request_count_default"
    shm:set(default_counter_key, 0)
    ngx.log(ngx.INFO, "全局计数器重置（默认域名，周期：" .. config.default.interval .. "秒）")
end

-- 初始化定时器（在 nginx worker 启动时调用）
function _M.init_timers()
    -- 仅在第一个 worker 进程启动定时器（避免重复执行）
    if ngx.worker.id() ~= 0 then return end
    
    -- 启动全局计数器定时器（周期使用 config.default.interval）
    local global_interval = config.default.interval
    local ok, err = ngx.timer.every(global_interval, reset_global_timer)
    if not ok then
        ngx.log(ngx.ERR, "全局定时器启动失败: ", err)
    end
end

return _M