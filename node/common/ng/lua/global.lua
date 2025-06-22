local _M = {}
local config = require("config")
local ip_counter = require("ip_counter")
local blacklist_counter = require("blacklist_counter")
local black_ip_counter = require("black_ip_counter")
local shm = ngx.shared.verification_data  -- 共享内存（需在 nginx.conf 中声明）
local cjson = require("cjson")  -- 引入JSON解析库

-- 从JSON文件加载题目库（保持不变）
local function load_questions()
    local file_path = "/usr/local/openresty/nginx/conf/lua/questions.json"  -- Linux 实际路径
    local file, err = io.open(file_path, "r")
    if not file then
        ngx.log(ngx.ERR, "加载题目文件失败: ", err)
        return {}  -- 返回空数组避免程序崩溃
    end
    local content = file:read("*a")
    file:close()
    
    local ok, questions = pcall(cjson.decode, content)
    if not ok then
        ngx.log(ngx.ERR, "解析题目JSON失败: ", questions)  -- questions此时是错误信息
        return {}
    end
    return questions
end

-- 初始化多语言题目库（保持不变）
local questions = load_questions()



-- 初始化随机种子（在 worker 启动时执行一次，避免重复序列）
local function init_random_seed()
    math.randomseed(ngx.time())  -- 使用 Nginx 时间戳作为种子（更均匀）
end

_M.init_random_seed = init_random_seed   --2025.5.14.19新增


-- 修改存储和获取的方法，使用序列化/反序列化
function _M.pass_verification_params(ip, url, domain)
    if not ip or not url or not domain then
        return false
    end
    
    -- 保存为JSON字符串
    local ok, data_json = pcall(cjson.encode, {
        url = url,
        domain = domain,
        question_index = 1
    })
    if not ok then
        return false
    end
    
    local ok, err = shm:set(ip, data_json, 300)
    if not ok then
        return false
    end
    return true
end

-- 修改获取方法
function _M.get_verification_question(ip)
    -- 新增：检查IP是否已验证（关键修改）
    local ip_counter = require("ip_counter")
    if ip_counter.check_valid(ip) then
        return nil, "IP已验证"  -- 返回特定错误标识
    end
    
    local data_json = shm:get(ip)
    
    if not data_json then
        return nil, "未找到验证会话"
    end
    
    -- 解析JSON
    local ok, data = pcall(cjson.decode, data_json)
    if not ok then
        shm:delete(ip) -- 清理无效数据
        return nil, "会话数据无效"
    end
    
    -- 检查题目库是否为空
    if #questions == 0 then
        return nil, "题目库为空，请联系管理员"
    end
    
    -- 生成随机索引（1到题目数量之间）
    local random_index = math.random(#questions)
    local question = questions[random_index]
    
    -- 更新会话数据，记录当前题目的正确答案
    data.current_answer = question.answer  -- 新增：记录正确答案
    shm:set(ip, cjson.encode(data))  -- 将会话数据重新写入共享内存
    
    -- 返回多语言结构
    return {
        title = question.title,
        options = question.options,
        url = data.url
    }
end

-- 修改验证答案方法（调整会话数据删除逻辑）
function _M.verify_answer(ip, user_answer)
    local data_json = shm:get(ip)
    
    if not data_json then
        return nil, "未找到验证会话"
    end
    
    -- 解析JSON
    local ok, data = pcall(cjson.decode, data_json)
    if not ok then
        shm:delete(ip) -- 清理无效数据
        return nil, "会话数据无效"
    end

    -- 优化后：减少重复调用 tonumber
    local answer_num = tonumber(user_answer)
    if not answer_num or answer_num < 1 or answer_num > 3 then
        return false, "无效答案"
    end
    
    -- 检查答案长度（限制为1-2位数字）
    if #tostring(user_answer) > 2 then
        return false, "答案过长（最多2位数字）"
    end

    -- 验证答案（直接比对会话中记录的 current_answer）
    local verification_result, msg = false, "答案错误"
    if tonumber(user_answer) == data.current_answer then
        -- 验证成功逻辑
        local ok = ip_counter.mark_verified(ip, data.domain)
        if not ok then
            return nil, "标记IP验证失败"
        end
        verification_result = true
        msg = data.url
    end

    -- 无论验证成功/失败，都增加验证次数并检查封禁（关键修改）
    local is_trigger_ban = blacklist_counter.increment_verification(ip, data.domain)

    -- 仅在验证成功或触发封禁时删除会话数据
    if verification_result or is_trigger_ban then
        shm:delete(ip)
    end

    if is_trigger_ban then
        black_ip_counter.ban_ip(ip)  -- 触发封禁
        return nil, "IP因频繁验证被临时封禁"  -- 优先返回封禁信息
    end
    return verification_result, msg
end

return _M