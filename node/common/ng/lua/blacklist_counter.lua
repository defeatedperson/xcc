local _M = {}
local shm = ngx.shared.blacklist_counter  -- 共享内存（需在nginx.conf中声明）
local cjson = require("cjson")
local config = require("config")

-- 时间常量定义（单位：秒）
local VERIFICATION_WINDOW = 60  -- 验证计数时间窗口（1分钟）
local DATA_TTL = 3600           -- 共享内存数据保留时间（1小时）

--- 获取或初始化IP的验证数据（带错误处理）
-- @param ip 客户端IP
-- @param domain 当前请求域名
-- @return table 验证数据（确保 first_verification_time 为数字）
local function get_ip_data(ip, domain)
    local data_str = shm:get(ip)
    if not data_str then
        return {
            verification_count = 0,
            first_verification_time = 0,  -- 显式初始化为0（数字）
            domain = domain
        }
    end

    -- 反序列化时添加错误处理，确保返回数据结构正确
    local ok, data = pcall(cjson.decode, data_str)
    if not ok then
        ngx.log(ngx.WARN, "IP数据反序列化失败，IP: ", ip, " 原始数据: ", data_str)
        return {
            verification_count = 0,
            first_verification_time = 0,  -- 反序列化失败时强制初始化为0
            domain = domain
        }
    end

    -- 防止数据结构异常（例如旧数据缺少 first_verification_time 字段）
    if type(data.first_verification_time) ~= "number" then
        ngx.log(ngx.WARN, "IP数据字段异常，IP: ", ip, " 修复 first_verification_time")
        data.first_verification_time = 0  -- 强制修正为数字
    end

    return data
end

--- 保存IP的验证数据到共享内存
-- @param ip 客户端IP
-- @param data 验证数据对象
local function save_ip_data(ip, data)
    local ok, err = shm:set(ip, cjson.encode(data), DATA_TTL)
    if not ok then
        ngx.log(ngx.ERR, "保存验证数据失败，IP: ", ip, " 错误: ", err)
    end
end

--- 增加验证次数并返回是否触发封禁
-- @param ip 客户端IP
-- @param domain 当前请求域名
-- @return boolean 是否触发封禁
function _M.increment_verification(ip, domain)
    if not ip or not domain then
        ngx.log(ngx.ERR, "缺少必要参数，ip: ", ip, " domain: ", domain)
        return false
    end

    local data = get_ip_data(ip, domain)
    local now = ngx.time()

    -- 清理过期的验证记录（超过时间窗口）
    if data.first_verification_time > 0 and (now - data.first_verification_time) > VERIFICATION_WINDOW then
        data.verification_count = 0
        data.first_verification_time = 0
    end

    -- 首次验证时记录时间戳
    if data.verification_count == 0 then
        data.first_verification_time = now
    end

    -- 增加验证次数（无论成功/失败）
    data.verification_count = data.verification_count + 1
    save_ip_data(ip, data)

    -- 获取域名配置（优化空值校验逻辑）
    local domain_config = config.domains[domain] or config.default
    local max_try = domain_config.max_try or config.default.max_try or 5  -- 默认5次

    -- 触发封禁判断
    local is_trigger_ban = data.verification_count >= max_try
    if is_trigger_ban then
        -- 调用黑IP计数器模块实际封禁（添加错误处理）
        local black_ip_counter = require("black_ip_counter")
        local ban_success = black_ip_counter.ban_ip(ip)
        if not ban_success then
            ngx.log(ngx.ERR, "调用 black_ip_counter.ban_ip 失败，IP: ", ip)
        end
        -- 记录封禁日志
        local log = require("log")
        log.write_ban_log(ip)  -- 传递IP
    end

    return is_trigger_ban
end

-- 初始化定时器（定期重置全局验证计数）
function _M.init_timers()
    -- 仅在第一个 worker 进程启动（避免重复执行）
    if ngx.worker.id() ~= 0 then return end

    -- 按配置中的全局重置周期启动定时器（默认24小时）
    local reset_interval = config.blacklist_reset_interval or 86400

    local function reset_global_counter(premature)
        if premature then return end
    
        -- 清理所有IP的验证计数（仅保留未过期的）
        local keys, err = shm:get_keys(0)
        if not keys then
            ngx.log(ngx.ERR, "获取验证共享内存键失败: ", err)
            return
        end
    
        for _, key in ipairs(keys) do
            local data_str = shm:get(key)
            if data_str then
                -- 添加反序列化错误处理
                local ok, data = pcall(cjson.decode, data_str)
                if not ok then
                    ngx.log(ngx.WARN, "重置时反序列化失败，IP: ", key, " 原始数据: ", data_str)
                    shm:delete(key)  -- 损坏数据直接删除
                    goto continue  -- 使用goto继续处理下一个key
                end
                -- 其他处理...
                ::continue::
    
                -- 若数据已过期（超过 DATA_TTL），则删除
                if data.first_verification_time > 0 and (ngx.time() - data.first_verification_time) > DATA_TTL then
                    shm:delete(key)
                    ngx.log(ngx.INFO, "清理过期验证数据，IP: ", key)
                else
                    -- 重置验证次数时同步重置首次验证时间（避免跨窗口累积）
                    data.verification_count = 0
                    data.first_verification_time = 0  -- 重置时间戳
                    save_ip_data(key, data)
                end
            end
        end
    end

    local ok, err = ngx.timer.every(reset_interval, reset_global_counter)
    if not ok then
        ngx.log(ngx.ERR, "验证计数器重置定时器启动失败: ", err)
    end
end

return _M