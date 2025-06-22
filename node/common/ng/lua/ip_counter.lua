local _M = {}
local config = require("config")  -- 引入配置文件
local shm = ngx.shared.ip_verified  -- 共享内存区域（需在 nginx.conf 中声明）

-- 初始化（可选，用于共享内存清理等）
function _M.init()
    if not shm then
        error("共享内存 ip_verified 未在 nginx.conf 中声明")
    end
    ngx.log(ngx.INFO, "ip_counter 模块初始化完成，共享内存: ip_verified")
    -- 可添加定时清理过期IP的逻辑（如使用 ngx.timer.every）
end

-- 标记IP为已验证（记录当前时间戳）
-- 标记IP为已验证（记录当前时间戳）
function _M.mark_verified(ip, domain)
    if not ip or not domain then return false end  -- 校验必要参数
    local now = ngx.time()  -- 当前时间戳（秒）

    -- 动态获取域名对应的 valid_duration（优先使用域名配置，无则用默认）
    local domain_config = config.domains[domain] or config.default
    local valid_duration = domain_config.valid_duration or config.default.valid_duration
    valid_duration = math.max(valid_duration, 1)  -- 确保至少1秒（调整顺序）
    
    -- 将IP的验证时间存入共享内存，过期时间为 valid_duration（自动过期）
    local ok, err = shm:set(ip, now, valid_duration)
    if not ok then
        return false
    end

    return true
end

-- 检查IP是否在有效期内已验证（无需修改）
function _M.check_valid(ip)
    if not ip then return false end
    local verified_time = shm:get(ip)
    if not verified_time then
        return false  -- 未找到记录，未验证
    end
    -- 验证时间在有效期内（无需额外判断，因共享内存已设置过期时间）
    return true
end

-- 新增：移除IP的验证标记（主动删除共享内存中的记录）
function _M.invalidate_ip(ip)
    if not ip then return false end
    local ok, err = shm:delete(ip)
    if not ok then
        ngx.log(ngx.ERR, "移除IP验证标记失败: ", ip, " err: ", err)
        return false
    end
    return true
end

return _M