local _M = {}
local shm = ngx.shared.anti_brute_counter  -- 共享内存（需在nginx.conf中声明）
local cjson = require("cjson")

-- 时间常量（单位：秒）
local ONE_MINUTE = 30
local TEN_MINUTES = 600
local ONE_HOUR = 3600
local SIXTY_MINUTES = 3600

-- 初始化或获取IP的计数数据
local function get_ip_data(ip)
    local data_str, flags = shm:get(ip)
    if not data_str then
        return {
            timestamps = {},  -- 存储1分钟内的请求时间戳
            ban_end = 0,      -- 封禁结束时间（时间戳）
            trigger_count = 0 -- 1小时内触发封禁次数
        }
    end
    return cjson.decode(data_str)
end

-- 保存IP的计数数据到共享内存
local function save_ip_data(ip, data)
    local ok, err = shm:set(ip, cjson.encode(data), ONE_HOUR)  -- 数据默认保留1小时
    if not ok then
        ngx.log(ngx.ERR, "保存防刷数据失败: ", err)
    end
    return ok  -- 新增返回值
end

-- 核心逻辑：递增计数并检查是否触发封禁
function _M.increment_and_check(ip)
    local now = ngx.time()
    local data = get_ip_data(ip)

    -- 检查当前是否处于封禁期
    if now < data.ban_end then
        return true, data.ban_end - now  -- 返回（是否封禁，剩余封禁时间）
    end

    -- 清理超过1分钟的旧时间戳
    local new_timestamps = {}
    for _, ts in ipairs(data.timestamps) do
        if now - ts < ONE_MINUTE then
            table.insert(new_timestamps, ts)
        end
    end
    data.timestamps = new_timestamps

    -- 新增当前请求时间戳
    table.insert(data.timestamps, now)

    -- 检查1分钟内请求次数是否超过5次
    if #data.timestamps > 5 then
        -- 触发封禁：10分钟
        data.ban_end = now + TEN_MINUTES
        data.trigger_count = data.trigger_count + 1

        -- 检查1小时内是否触发3次封禁
        if data.trigger_count >= 3 then
            data.ban_end = now + SIXTY_MINUTES  -- 升级为60分钟封禁
        end

        -- 触发封禁时保存数据
        local save_ok = save_ip_data(ip, data)
        if not save_ok then
            ngx.log(ngx.ERR, "触发封禁时保存数据失败，IP: ", ip)
        end

        -- 新增：记录封禁日志（计算封禁总时长）
        local log = require("log")
        local ban_duration = data.ban_end - now  -- 封禁总时长（秒）
        log.write_ban_log(ip)  -- 传递IP
        return true, data.ban_end - now
    end

    -- 未触发封禁，更新数据
    save_ip_data(ip, data)
    return false, 0
end

-- 新增：初始化定时器（清理长期未更新的IP数据）
function _M.init_timers()
    -- 仅在第一个 worker 进程启动（避免重复执行）
    if ngx.worker.id() ~= 0 then return end

    -- 每小时清理一次超过24小时未更新的IP数据（避免内存溢出）
    local clean_interval = 3600  -- 清理周期（1小时）
    local max_idle_time = 86400  -- 最大空闲时间（24小时）

    local function clean_expired_data(premature)
        if premature then return end

        local keys, err = shm:get_keys(0)  -- 获取所有键（0表示获取全部）
        if not keys then
            ngx.log(ngx.ERR, "获取防刷共享内存键失败: ", err)
            return
        end

        local now = ngx.time()
        for _, key in ipairs(keys) do
            local data_str = shm:get(key)
            if data_str then
                local data = cjson.decode(data_str)
                -- 若最后一次更新时间超过 max_idle_time，则删除
                local last_update = data.timestamps[#data.timestamps] or 0
                if now - last_update > max_idle_time then
                    shm:delete(key)
                    ngx.log(ngx.INFO, "清理过期防刷数据，IP: ", key)
                end
            end
        end
    end

    local ok, err = ngx.timer.every(clean_interval, clean_expired_data)
    if not ok then
        ngx.log(ngx.ERR, "防刷数据清理定时器启动失败: ", err)
    end
end

return _M