local _M = {}
local ngx_shared = ngx.shared  -- 获取共享内存模块（新增）
-- 区分通用日志和封禁日志路径
local request_log_path = "/usr/local/openresty/nginx/logs/request.log"  -- 通用日志路径
local ban_log_path = "/usr/local/openresty/nginx/logs/ban.log"         -- 封禁日志路径

-- 关联共享内存（对应 nginx.conf 中声明的 ban_log_shm，新增）
local ban_log_shm = ngx_shared.ban_log_shm

-- 通用日志写入函数（写入 request.log）
function _M.write(message)
    local file, err = io.open(request_log_path, "a")
    if not file then
        ngx.log(ngx.ERR, "通用日志文件打开失败: ", err)
        return false
    end
    local timestamp = os.date("%Y-%m-%d %H:%M:%S")
    local log_line = string.format("[%s] %s\n", timestamp, message)
    file:write(log_line)
    file:close()
    return true
end

-- 新增：将封禁日志缓存到共享内存（替代原实时写入）
function _M.write_ban_log(ip)
    local timestamp = os.date("%Y-%m-%d %H:%M:%S")
    local log_line = string.format("封禁时间: %s | IP: %s\n", timestamp, ip)
    -- 生成唯一键（使用时间戳+IP的MD5避免重复）
    local key = ngx.md5(timestamp .. ip)
    -- 将日志内容存入共享内存（不设置过期时间，由定时任务主动清理）
    local ok, err = ban_log_shm:set(key, log_line)
    if not ok then
        ngx.log(ngx.ERR, "缓存封禁日志失败（IP: ", ip, "）: ", err)
        return false
    end
    return true
end

-- 新增：定时批量写入封禁日志（由定时器调用）
function _M.flush_ban_logs()
    -- 打开文件（追加模式）
    local file, err = io.open(ban_log_path, "a")
    if not file then
        ngx.log(ngx.ERR, "批量写入封禁日志时文件打开失败: ", err)
        -- 出错时清空缓存（避免重复写入失败数据）
        ban_log_shm:flush_all()  -- 标记所有键为过期
        ban_log_shm:flush_expired()  -- 立即释放内存
        return false
    end

    -- 获取共享内存中所有缓存的日志键
    local keys, err = ban_log_shm:get_keys()
    if not keys then
        ngx.log(ngx.ERR, "获取缓存日志键失败: ", err)
        file:close()
        return false
    end

    -- 遍历并写入所有日志
    for _, key in ipairs(keys) do
        local log_line = ban_log_shm:get(key)
        if log_line then
            file:write(log_line)
            ban_log_shm:delete(key)  -- 写入成功后删除缓存键
        end
    end

    file:close()
    return true
end

return _M