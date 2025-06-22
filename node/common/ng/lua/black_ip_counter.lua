local _M = {}
local shm = ngx.shared.black_ip_counter  -- 使用新增的共享内存

-- 封禁IP（10分钟后自动解封）
function _M.ban_ip(ip)
    local ban_duration = 600  -- 10分钟（秒）
    local ok, err = shm:set(ip, true, ban_duration)
    if not ok then
        ngx.log(ngx.ERR, "封禁IP失败: ", ip, " err: ", err)
        return false
    end
    return true
end

-- 检查IP是否被封禁（返回true表示封禁中）
function _M.is_banned(ip)
    return shm:get(ip) ~= nil
end

return _M