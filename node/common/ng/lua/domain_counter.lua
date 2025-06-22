local _M = {}
local shm_name = "domain_counter"  -- 共享内存名称（需在nginx.conf声明）
local flow_shm_name = "domain_flow"  -- 新增：定义流量共享内存名称（与nginx.conf一致）
local shm = ngx.shared[shm_name]
local flow_shm = ngx.shared[flow_shm_name]  -- 关联新增的流量共享内存
local log = require("log")  -- 引入日志模块

-- 初始化检查共享内存（修正后）
function _M.init()
    if not shm then
        error("共享内存 " .. shm_name .. " 未在nginx.conf中声明")
    end
    if not flow_shm then  -- 检查流量共享内存
        error("共享内存 " .. flow_shm_name .. " 未在nginx.conf中声明")
    end
end

-- 增加域名请求计数
function _M.increment(domain)
    if not domain then return false end
    local key = "domain_req_count_" .. domain  -- 生成唯一键（如：domain_req_count_dp712.work）
    
    -- 递增计数（初始值为0）
    local ok, err = shm:incr(key, 1, 0)
    if not ok then
        ngx.log(ngx.ERR, "域名计数递增失败（" .. domain .. "）: ", err)
        return false
    end
    return true
end

-- 新增：增加域名出站流量统计（参数：域名、出站字节数）
function _M.increment_flow(domain, bytes_sent)
    if not domain or not bytes_sent then return false end
    local key = "domain_flow_" .. domain  -- 键格式：domain_flow_<域名>
    
    -- 原子操作递增流量（初始值为0）
    local ok, err = flow_shm:incr(key, bytes_sent, 0)
    if not ok then
        ngx.log(ngx.ERR, "域名流量递增失败（" .. domain .. "）: ", err)
        return false
    end
    return true
end

-- 定时任务：统计并写入日志后重置计数
local function timer_handler(premature)
    if premature then return end  -- 定时器提前终止时退出
    
    -- 从配置中获取所有域名（复用现有config模块）
    -- 优化后（添加空值检查）
        local config = require("config")
        local domains = config.domains or {}  -- 避免 config.domains 为 nil
        for domain in pairs(domains) do
        local key = "domain_req_count_" .. domain
        local count = shm:get(key) or 0  -- 获取当前计数

        -- 统计流量（新增逻辑）
        local flow_key = "domain_flow_" .. domain
        local flow_count = flow_shm:get(flow_key) or 0
        
        -- 写入日志（同时记录请求数和流量）
        -- 修正后代码
        local ok, err = log.write(string.format("域名[%s] 每分钟请求数: %d，出站流量: %d bytes", domain, count, flow_count))
        if not ok then
            ngx.log(ngx.ERR, "写入域名流量日志失败（域名: ", domain, "）: ", err)
        end
        
        -- 重置请求数和流量统计（可选：若需保留历史数据，可注释此部分）
        shm:set(key, 0)  -- 修正：使用已定义的key变量
        flow_shm:set(flow_key, 0)
    end
end

-- 启动定时器（仅在第一个worker进程运行）
function _M.start_timer()
    if ngx.worker.id() ~= 0 then return end
    local ok, err = ngx.timer.every(60, timer_handler)  -- 每分钟触发一次
    if not ok then
        ngx.log(ngx.ERR, "域名计数定时器启动失败: ", err)
    end
end

return _M