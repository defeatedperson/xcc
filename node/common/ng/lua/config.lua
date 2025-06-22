local cjson = require("cjson")
local ngx = ngx  -- 依赖 Nginx Lua 上下文

-- 默认全局配置（未指定域名时使用）
local default_config = {
    default = {
        -- 全局基础规则（统计相关）
        interval = 30,         -- 统计时间段（秒），需为正整数
        threshold = 100,         -- 时间段内最大请求数阈值，需为正整数
        valid_duration = 60,   -- 验证通过后 IP 有效期（秒），需为正整数
        max_try = 10,           -- 最大尝试次数（验证次数），需为正整数
        -- 个人规则（与全局模板一致，可自定义覆盖）
        custom_rules = {
            interval = 30,     -- 个人规则统计时间段（秒），需为正整数
            threshold = 30,      -- 个人规则请求数阈值，需为正整数（人机验证）
            max_threshold = 300    --个人请求数上限，达到直接封禁10分钟
        }
    },
    domains = {}  -- 动态加载的域名配置将存入此表
}

-- 定义域名配置目录（Linux 绝对路径，与业务系统环境一致）
local domains_dir = "/usr/local/openresty/nginx/conf/lua/domains/"

-- 读取目录下所有 JSON 文件（改进 ls 命令调用，处理特殊字符）
-- 使用双引号包裹路径，避免空格等特殊字符导致的问题；2>/dev/null 忽略目录不存在错误
local handle = io.popen('ls "' .. domains_dir .. '"*.json 2>/dev/null')
if handle then
    for full_path in handle:lines() do
        -- 提取文件名（格式："域名.json"），示例：/path/to/dp712.work.json → dp712.work.json
        local filename = full_path:match(".*/([^/]+)$")  -- 匹配最后一个 / 后的内容
        if not filename then
            ngx.log(ngx.WARN, "跳过无效路径: ", full_path)
            goto continue  -- 跳过无法提取文件名的路径
        end

        -- 校验文件是否为 .json 后缀（避免误加载其他类型文件）
        if not filename:match("%.json$") then
            ngx.log(ngx.WARN, "跳过非 JSON 文件: ", full_path)
            goto continue
        end

        -- 提取域名（去掉 .json 后缀），示例：dp712.work.json → dp712.work
        local domain = filename:gsub("%.json$", "")
        if domain == "" then
            ngx.log(ngx.WARN, "跳过无效文件名（无域名部分）: ", full_path)
            goto continue
        end

        -- 读取 JSON 文件内容
        local file, err = io.open(full_path, "r")
        if not file then
            ngx.log(ngx.WARN, "打开文件失败: ", full_path, " 错误: ", err)
            goto continue
        end
        local content = file:read("*a")
        file:close()

        -- 解析 JSON（防止格式错误导致程序崩溃）
        local ok, domain_config = pcall(cjson.decode, content)
        if not ok then
            ngx.log(ngx.WARN, "解析 JSON 失败: ", full_path, " 错误: ", domain_config)
            goto continue
        end

        -- 校验配置结构（核心字段类型和数值检查）
        if type(domain_config) ~= "table" then
            ngx.log(ngx.WARN, "无效配置格式（非 JSON 对象）: ", full_path)
            goto continue
        end
        -- 检查全局基础规则字段（示例校验，可扩展）
        if type(domain_config.interval) ~= "number" or domain_config.interval <= 0 then
            ngx.log(ngx.WARN, "无效 interval 值（需为正整数）: ", full_path)
            goto continue
        end
        if type(domain_config.threshold) ~= "number" or domain_config.threshold <= 0 then
            ngx.log(ngx.WARN, "无效 threshold 值（需为正整数）: ", full_path)
            goto continue
        end
        -- 检查 custom_rules 子字段（可选扩展）
        if domain_config.custom_rules and type(domain_config.custom_rules) == "table" then
            if type(domain_config.custom_rules.interval) ~= "number" or domain_config.custom_rules.interval <= 0 then
                ngx.log(ngx.WARN, "custom_rules.interval 无效（需为正整数）: ", full_path)
                goto continue
            end
        end

        -- 合并到主配置（覆盖默认 domains）
        default_config.domains[domain] = domain_config
        ngx.log(ngx.INFO, "成功加载域名配置: ", domain)

        ::continue::  -- 继续处理下一个文件
    end
    handle:close()
else
    ngx.log(ngx.WARN, "域名配置目录不存在或无权限访问: ", domains_dir)
end

return default_config