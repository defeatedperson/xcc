package main

import (
	"bytes"
	"crypto/tls"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"mime/multipart"
	"net"
	"net/http"
	"os"
	"os/exec"
	"os/user"
	"path/filepath"
	"strings"
	"time"
)

// 新增：配置文件结构体（对应./conf/conf.json格式）
type Config struct {
	NodeID        string `json:"node_id"`        // 节点ID
	SecretKey     string `json:"secret_key"`     // 节点密钥
	MasterAddress string `json:"master_address"` // 主控地址
}

// 主控指令结构体（对应主控发送的JSON格式）
type MasterCommand struct {
	Domains []struct {
		Domain     string `json:"domain"`
		UpdateTime string `json:"update_time"`
	} `json:"domains"`
}

// 域名状态结果结构体（记录需要更新/删除的域名）
type DomainStatus struct {
	ToUpdate []string `json:"to_update"` // 需要更新的域名（本地不存在或时间不一致）
	ToDelete []string `json:"to_delete"` // 需要删除的域名（本地存在但主控未提供）
}

// 新增：文件下载配置结构体（对应./conf/file.json格式）
type FileTypeConfig struct {
	APIPoint  string `json:"apipoint"`   // 文件下载接口地址
	LocalPath string `json:"local_path"` // 文件存储绝对路径
}

type FileConfig struct {
	Cache     FileTypeConfig `json:"cache"`
	CacheList FileTypeConfig `json:"cachelist"`
	Certs     FileTypeConfig `json:"certs"`
	Conf      FileTypeConfig `json:"conf"`
	List      FileTypeConfig `json:"list"`
	Lua       FileTypeConfig `json:"lua"`
}

// 新增：日志清理配置结构体（对应./conf/logs.json格式）
type CleanLogConfig struct {
	CleanLogPaths  []string `json:"clean_log_paths"`  // 需清理的日志路径列表
	SubmitLogPaths []string `json:"submit_log_paths"` // 需提交的日志路径列表
	SelfLogPaths   []struct {
		Go    string `json:"go"`    // 程序日志路径
		Error string `json:"error"` // 错误日志路径
	} `json:"self_log_paths"` // 程序自身日志路径（新增）
}

// 程序自身生成的日志路径（硬编码，不通过配置文件读取）
var selfGeneratedLogs = []string{
	filepath.Join("./logs", "app.log"), // 程序自身日志路径
}

// 需要清理的日志文件路径（合并硬编码路径和配置文件路径）
var logFilesToClean []string

// SSL证书路径配置（Linux相对路径）
const (
	sslCertPath = "/xcc/go/ssl/node.cert" // 绝对路径
	sslKeyPath  = "/xcc/go/ssl/node.key"  // 绝对路径
)

// 新增：加载配置文件（节点ID/密钥/主控地址）
func loadConfig(configPath string) (*Config, error) {
	// 1. 检查配置文件是否存在
	if _, err := os.Stat(configPath); os.IsNotExist(err) {
		return nil, fmt.Errorf("配置文件不存在: %s", configPath)
	}

	// 2. 读取配置文件内容
	data, err := os.ReadFile(configPath)
	if err != nil {
		return nil, fmt.Errorf("读取配置文件失败: %v", err)
	}

	// 3. 解析JSON配置
	var config Config
	if err := json.Unmarshal(data, &config); err != nil {
		return nil, fmt.Errorf("解析配置文件失败: %v（请检查JSON格式）", err)
	}

	// 4. 校验必填字段（可选增强）
	if config.NodeID == "" || config.SecretKey == "" || config.MasterAddress == "" {
		return nil, fmt.Errorf("配置文件缺少必填字段（node_id/secret_key/master_address）")
	}

	return &config, nil
}

// 初始化日志系统（封装为独立函数）
func initLog() error {
	// 定义日志目录相对路径（当前文件夹下的logs目录）
	logDir := "./logs"

	// 1. 创建日志目录（递归创建，权限0755）
	if err := os.MkdirAll(logDir, 0755); err != nil {
		return fmt.Errorf("创建日志目录失败: %v", err)
	}

	// 2. 构造日志文件路径（当前目录/logs/app.log）
	logFilePath := filepath.Join(logDir, "app.log")

	// 3. 打开日志文件（追加模式/自动创建/读写权限）
	logFile, err := os.OpenFile(logFilePath, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0644)
	if err != nil {
		return fmt.Errorf("打开日志文件失败: %v", err)
	}

	// 4. 设置日志输出（同时输出到文件和控制台）
	log.SetOutput(io.MultiWriter(logFile, os.Stdout))
	log.SetFlags(log.LstdFlags | log.Lshortfile) // 显示时间+文件名+行号

	return nil
}

// 新增：加载日志清理配置文件
func loadLogConfig() (*CleanLogConfig, error) { // 修正函数名和返回类型
	configPath := "./conf/logs.json" // Linux相对路径（当前目录/conf/logs.json）

	// 1. 检查配置文件是否存在
	if _, err := os.Stat(configPath); os.IsNotExist(err) {
		return nil, fmt.Errorf("日志清理配置文件不存在: %s", configPath)
	}

	// 2. 读取配置文件内容
	data, err := os.ReadFile(configPath)
	if err != nil {
		return nil, fmt.Errorf("读取日志清理配置文件失败: %v", err)
	}

	// 3. 解析JSON配置（返回完整 CleanLogConfig 结构体）
	var config CleanLogConfig
	if err := json.Unmarshal(data, &config); err != nil {
		return nil, fmt.Errorf("解析日志清理配置文件失败: %v（请检查JSON格式）", err)
	}

	return &config, nil // 返回完整结构体
}

// 检查SSL证书是否存在（新增函数）
func checkSSLCerts() error {
	// 检查证书文件是否存在
	if _, err := os.Stat(sslCertPath); os.IsNotExist(err) {
		return fmt.Errorf("SSL证书文件不存在: %s", sslCertPath)
	}
	// 检查私钥文件是否存在
	if _, err := os.Stat(sslKeyPath); os.IsNotExist(err) {
		return fmt.Errorf("SSL私钥文件不存在: %s", sslKeyPath)
	}
	return nil
}

// 原子性清理日志文件（截断内容）
func cleanLogFiles() {
	for _, logPath := range logFilesToClean {
		// 检查文件是否存在
		if _, err := os.Stat(logPath); os.IsNotExist(err) {
			log.Printf("日志清理跳过：文件 %s 不存在", logPath)
			continue
		}

		// 原子性截断文件（清空内容）
		if err := os.Truncate(logPath, 0); err != nil {
			log.Printf("日志清理失败：文件 %s 截断失败，错误：%v", logPath, err)
			continue
		}

		// 重新打开文件（确保后续日志写入到新内容起始位置）
		newLogFile, err := os.OpenFile(logPath, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0644)
		if err != nil {
			log.Printf("日志清理警告：文件 %s 重新打开失败，错误：%v", logPath, err)
			continue
		}

		// 仅对程序自身生成的日志文件更新全局日志输出
		if isSelfGeneratedLog(logPath) {
			// 替换全局日志输出（避免旧文件句柄继续写入截断前的位置）
			log.SetOutput(io.MultiWriter(newLogFile, os.Stdout))
			log.Printf("程序自身日志清理成功：文件 %s 已清空（截断+重定向写入）", logPath)
		} else {
			// 外部日志清理后无需修改全局输出，仅记录清理结果
			log.Printf("外部日志清理成功：文件 %s 已清空（仅截断）", logPath)
			// 外部日志文件句柄无需保留，直接关闭
			_ = newLogFile.Close()
		}
	}
}

// 检查是否为程序自身生成的日志路径
func isSelfGeneratedLog(path string) bool {
	for _, selfPath := range selfGeneratedLogs {
		if filepath.Clean(path) == filepath.Clean(selfPath) {
			return true
		}
	}
	return false
}

// 启动每日0点日志清理任务
func startDailyLogCleaner() {
	// 计算到次日0点的时间间隔
	now := time.Now()
	nextMidnight := time.Date(now.Year(), now.Month(), now.Day()+1, 0, 0, 0, 0, now.Location())
	delay := nextMidnight.Sub(now)

	// 首次执行清理
	time.AfterFunc(delay, func() {
		cleanLogFiles()
		// 递归启动下一次清理（每日循环）
		startDailyLogCleaner()
	})

	log.Printf("日志清理任务已启动，首次执行时间：%s", nextMidnight.Format("2006-01-02 15:04:05"))
}

// 提交日志到主控接口（修改为文件上传方式）
func submitLogsToMaster(config *Config, submitPaths []string) {
	log.Printf("开始提交日志，共 %d 个文件...", len(submitPaths))

	// 添加重试配置
	maxRetries := 3
	retryDelay := 5 * time.Second

	for _, logPath := range submitPaths {
		// 1. 检查日志文件是否存在并且非空
		fileInfo, err := os.Stat(logPath)
		if err != nil {
			log.Printf("日志文件不存在或无法访问: %s, 错误: %v", logPath, err)
			continue
		}

		if fileInfo.Size() == 0 {
			log.Printf("日志文件为空，跳过提交: %s", logPath)
			continue
		}

		log.Printf("正在处理日志文件: %s (大小: %d 字节)", logPath, fileInfo.Size())

		// 添加重试逻辑
		var lastErr error
		success := false

		for attempt := 1; attempt <= maxRetries; attempt++ {
			if attempt > 1 {
				log.Printf("第 %d 次重试提交日志文件: %s", attempt, logPath)
				time.Sleep(retryDelay)
			}

			// 2. 打开要上传的日志文件
			file, err := os.Open(logPath)
			if err != nil {
				log.Printf("打开日志文件 %s 失败: %v", logPath, err)
				lastErr = err
				continue
			}

			// 确保文件会被关闭
			func() {
				defer file.Close()

				// 3. 创建 multipart 表单
				body := &bytes.Buffer{}
				writer := multipart.NewWriter(body)

				// 4. 添加文件字段（对应 PHP 的 $_FILES['log_file']）
				part, err := writer.CreateFormFile("log_file", filepath.Base(logPath))
				if err != nil {
					log.Printf("创建文件表单字段失败: %v", err)
					lastErr = err
					return
				}
				if _, err := io.Copy(part, file); err != nil {
					log.Printf("复制文件内容失败: %v", err)
					lastErr = err
					return
				}

				// 5. 添加表单参数（对应 PHP 的 $_POST['node_id'] 和 $_POST['node_key']）
				_ = writer.WriteField("node_id", config.NodeID)
				_ = writer.WriteField("node_key", config.SecretKey)

				// 正确关闭writer
				err = writer.Close()
				if err != nil {
					log.Printf("关闭multipart writer失败: %v", err)
					lastErr = err
					return
				}

				// 6. 发送 POST 请求
				url := config.MasterAddress + "/api/log_to_sqlite.php"
				log.Printf("准备发送请求到: %s", url)

				req, err := http.NewRequest("POST", url, body)
				if err != nil {
					log.Printf("创建请求失败: %v", err)
					lastErr = err
					return
				}
				req.Header.Set("Content-Type", writer.FormDataContentType())
				// 添加连接关闭头，避免keep-alive可能引起的问题
				req.Header.Set("Connection", "close")

				// 增强TLS配置
				transport := &http.Transport{
					TLSClientConfig: &tls.Config{
						InsecureSkipVerify: false,            // 允许自签名证书
						MinVersion:         tls.VersionTLS12, // 指定最低TLS版本
						MaxVersion:         tls.VersionTLS13, // 指定最高TLS版本
					},
					// 增加连接超时设置
					DialContext: (&net.Dialer{
						Timeout:   10 * time.Second,
						KeepAlive: 30 * time.Second,
					}).DialContext,
					IdleConnTimeout:     90 * time.Second,
					TLSHandshakeTimeout: 10 * time.Second,
				}

				// 添加自定义HTTP客户端，设置超时
				client := &http.Client{
					Timeout:   30 * time.Second,
					Transport: transport,
				}

				log.Printf("发送HTTP请求中...")
				resp, err := client.Do(req)
				if err != nil {
					log.Printf("日志提交失败（%s）: %v", logPath, err)
					lastErr = err
					return
				}
				defer resp.Body.Close()

				// 关键修改：先检查HTTP状态码是否为200
				if resp.StatusCode != http.StatusOK {
					respBody, _ := io.ReadAll(resp.Body)
					log.Printf("日志提交失败（%s）: 非200状态码，状态码=%d，响应体=%s", logPath, resp.StatusCode, string(respBody))
					lastErr = fmt.Errorf("非200状态码: %d", resp.StatusCode)
					return
				}

				// 7. 读取完整响应并记录
				respBody, _ := io.ReadAll(resp.Body)
				log.Printf("收到服务器响应: 状态码=%d, 响应体=%s", resp.StatusCode, string(respBody))

				// 8. 解析响应并记录 PHP 后台处理中的日志
				var respData map[string]interface{}
				if err := json.Unmarshal(respBody, &respData); err != nil {
					log.Printf("解析响应失败: %v，原始响应: %s", err, string(respBody))
					lastErr = err
					return
				}

				// 关键修改：同时检查success字段是否为true
				if successFlag, ok := respData["success"].(bool); ok && successFlag {
					log.Printf("日志提交成功：%s", logPath)
					success = true // 设置成功标志以跳出重试循环
				} else {
					errorMsg := "未知错误"
					if msg, ok := respData["message"].(string); ok {
						errorMsg = msg
					}
					log.Printf("服务器返回失败: %s", errorMsg)
					lastErr = fmt.Errorf("服务器返回失败: %s", errorMsg)
					return
				}
			}()

			if success {
				break // 成功提交，跳出重试循环
			}
		}

		if !success {
			log.Printf("经过 %d 次尝试后，日志 %s 提交仍然失败，最后错误: %v", maxRetries, logPath, lastErr)
		}
	}

	log.Printf("日志提交任务完成")
}

// 校验请求的节点ID和密钥是否合法（新增）
func verifyRequest(config *Config, r *http.Request) error {
	// 解析表单数据（支持 multipart/form-data 和 application/x-www-form-urlencoded）
	if err := r.ParseMultipartForm(10 << 20); err != nil { // 修复条件判断语法
		return fmt.Errorf("解析请求表单失败: %v", err)
	}

	// 从请求中获取 node_id 和 node_key
	reqNodeID := r.FormValue("node_id")
	reqNodeKey := r.FormValue("node_key")

	// 校验字段是否存在
	if reqNodeID == "" || reqNodeKey == "" {
		return fmt.Errorf("请求缺少必要参数（node_id或node_key）")
	}

	// 校验与本地配置是否一致
	if reqNodeID != config.NodeID || reqNodeKey != config.SecretKey {
		return fmt.Errorf("节点ID或密钥不匹配（本地ID: %s，本地密钥: %s）", config.NodeID, config.SecretKey)
	}

	return nil
}

// 执行Nginx控制命令（支持reload/stop/start）
// 返回：执行结果信息，错误（若有）
func execNginxCommand(command string) (string, error) {
	// 硬编码允许的指令（防止任意命令执行）
	allowedCommands := map[string][]string{
		"reload": {"sudo", "/usr/local/openresty/nginx/sbin/nginx", "-s", "reload"},
		"stop":   {"sudo", "/usr/local/openresty/nginx/sbin/nginx", "-s", "stop"},
		"start":  {"sudo", "/usr/local/openresty/nginx/sbin/nginx"},
	}

	// 校验指令有效性
	cmdArgs, ok := allowedCommands[command]
	if !ok {
		return "", fmt.Errorf("不支持的指令，允许值：reload/stop/start")
	}

	// 执行系统命令
	cmd := exec.Command(cmdArgs[0], cmdArgs[1:]...)
	output, err := cmd.CombinedOutput() // 获取标准输出+错误输出

	return string(output), err
}

// 处理Nginx控制接口（POST）
// 接口参数：command（reload/stop/start）
func handleNginxControl(w http.ResponseWriter, r *http.Request, config *Config) {
	w.Header().Set("Content-Type", "application/json")

	// 步骤1：验证请求合法性（使用传入的config）
	if err := verifyRequest(config, r); err != nil {
		w.WriteHeader(http.StatusUnauthorized)
		json.NewEncoder(w).Encode(map[string]string{
			"status":  "error",
			"message": "请求验证失败: " + err.Error(),
		})
		return
	}

	// 步骤2：解析请求指令（示例使用POST表单，可根据需求改为JSON）
	if err := r.ParseForm(); err != nil {
		w.WriteHeader(http.StatusBadRequest)
		json.NewEncoder(w).Encode(map[string]string{
			"status":  "error",
			"message": "解析请求参数失败: " + err.Error(),
		})
		return
	}
	command := r.FormValue("command")
	if command == "" {
		w.WriteHeader(http.StatusBadRequest)
		json.NewEncoder(w).Encode(map[string]string{
			"status":  "error",
			"message": "缺少必要参数：command",
		})
		return
	}

	// 步骤3：执行Nginx命令
	output, err := execNginxCommand(command)
	if err != nil {
		w.WriteHeader(http.StatusInternalServerError)
		json.NewEncoder(w).Encode(map[string]string{
			"status":  "error",
			"message": "执行命令失败: " + err.Error(),
			"output":  output,
		})
		return
	}

	// 步骤4：返回成功结果
	json.NewEncoder(w).Encode(map[string]string{
		"status":  "success",
		"message": "命令执行完成",
		"output":  output,
	})
}

// 处理主控指令接口（POST）
// 接口功能：接收主控指令，验证节点身份，对比本地domains.json并返回需要更新/删除的域名
func handleMasterCommand(w http.ResponseWriter, r *http.Request, config *Config) {
	w.Header().Set("Content-Type", "application/json")

	// 步骤1：验证节点身份（复用现有验证逻辑）
	if err := verifyRequest(config, r); err != nil {
		w.WriteHeader(http.StatusUnauthorized)
		json.NewEncoder(w).Encode(map[string]string{
			"status":  "error",
			"message": "节点验证失败: " + err.Error(),
		})
		return
	}

	// 步骤2：从表单中获取并解析domains_json（关键修改）
	domainsJson := r.FormValue("domains_json")
	if domainsJson == "" {
		w.WriteHeader(http.StatusBadRequest)
		json.NewEncoder(w).Encode(map[string]string{
			"status":  "error",
			"message": "缺少必要参数domains_json",
		})
		return
	}

	var masterCmd MasterCommand
	if err := json.Unmarshal([]byte(domainsJson), &masterCmd); err != nil {
		w.WriteHeader(http.StatusBadRequest)
		json.NewEncoder(w).Encode(map[string]string{
			"status":  "error",
			"message": "解析domains_json失败: " + err.Error(),
		})
		return
	}

	// 步骤3：读取或创建本地domains.json文件
	domainsPath := filepath.Join("./conf", "domains.json") // Linux相对路径
	localDomains := make(map[string]string)                // 本地域名:update_time映射

	// 读取本地文件（若不存在则创建空文件）
	if _, err := os.Stat(domainsPath); os.IsNotExist(err) {
		// 本地文件不存在，创建空JSON数组
		if err := os.WriteFile(domainsPath, []byte("[]"), 0644); err != nil {
			w.WriteHeader(http.StatusInternalServerError)
			json.NewEncoder(w).Encode(map[string]string{
				"status":  "error",
				"message": "创建本地domains.json失败: " + err.Error(),
			})
			return
		}
	} else {
		// 文件存在，读取并解析
		data, err := os.ReadFile(domainsPath)
		if err != nil {
			w.WriteHeader(http.StatusInternalServerError)
			json.NewEncoder(w).Encode(map[string]string{
				"status":  "error",
				"message": "读取本地domains.json失败: " + err.Error(),
			})
			return
		}

		// 解析为本地域名列表
		var localDomainList []struct {
			Domain     string `json:"domain"`
			UpdateTime string `json:"update_time"`
		}
		if err := json.Unmarshal(data, &localDomainList); err != nil {
			w.WriteHeader(http.StatusInternalServerError)
			json.NewEncoder(w).Encode(map[string]string{
				"status":  "error",
				"message": "解析本地domains.json失败: " + err.Error(),
			})
			return
		}

		// 转换为map方便对比
		for _, d := range localDomainList {
			localDomains[d.Domain] = d.UpdateTime
		}
	}

	// 步骤4：对比主控指令与本地域名状态
	status := DomainStatus{
		ToUpdate: []string{},
		ToDelete: []string{},
	}

	// 处理主控指令中的域名（标记需要更新的）
	masterDomainSet := make(map[string]bool)
	for _, d := range masterCmd.Domains {
		masterDomainSet[d.Domain] = true // 记录主控存在的域名
		localTime, exists := localDomains[d.Domain]
		if !exists || localTime != d.UpdateTime {
			status.ToUpdate = append(status.ToUpdate, d.Domain)
		}
	}

	// 处理本地存在但主控未提供的域名（标记需要删除的）
	for domain := range localDomains {
		if !masterDomainSet[domain] {
			status.ToDelete = append(status.ToDelete, domain)
		}
	}

	// 步骤5：返回对比结果
	json.NewEncoder(w).Encode(map[string]interface{}{
		"status":  "success",
		"message": "域名状态对比完成",
		"result":  status,
	})

	// 新增：更新本地domains.json为当前主控指令中的最新域名列表
	newDomainList := make([]struct {
		Domain     string `json:"domain"`
		UpdateTime string `json:"update_time"`
	}, 0, len(masterCmd.Domains))
	for _, d := range masterCmd.Domains {
		newDomainList = append(newDomainList, struct {
			Domain     string `json:"domain"`
			UpdateTime string `json:"update_time"`
		}{
			Domain:     d.Domain,
			UpdateTime: d.UpdateTime,
		})
	}

	// 序列化新域名列表为JSON
	domainJSON, err := json.MarshalIndent(newDomainList, "", "  ")
	if err != nil {
		log.Printf("序列化domains.json失败: %v", err)
	} else {
		// 写入本地文件（覆盖原有内容）
		if err := os.WriteFile(domainsPath, domainJSON, 0644); err != nil {
			log.Printf("更新domains.json失败: %v", err)
		} else {
			log.Printf("成功更新domains.json，新域名数量: %d", len(newDomainList))
		}
	}

	// 新增：清理需要删除的域名文件
	if len(status.ToDelete) > 0 {
		if err := cleanDomainFiles(status.ToDelete); err != nil {
			log.Printf("清理域名文件失败：%v", err)
		}
	}

	// 新增：域名对比完成后触发文件下载（需更新的域名）
	if len(status.ToUpdate) > 0 {
		if err := downloadFilesForDomains(config, status.ToUpdate); err != nil {
			log.Printf("文件下载任务执行失败: %v", err)
		} else {
			log.Printf("文件下载任务执行完成，更新域名数量: %d", len(status.ToUpdate))
		}
	}
}

// 检查用户是否存在
func userExists(username string) bool {
	_, err := user.Lookup(username)
	return err == nil
}

func downloadFilesForDomains(config *Config, toUpdateDomains []string) error {
	// 检查www用户是否存在
	if !userExists("www") {
		return fmt.Errorf("www用户不存在，请先创建www用户")
	}

	// 硬编码文件类型和本地存储路径（不再读取file.json）
	fileTypes := map[string]struct {
		LocalPath string
	}{
		"cache":     {LocalPath: "/usr/local/openresty/nginx/conf/sites-enabled/cache"},
		"cachelist": {LocalPath: "/usr/local/openresty/nginx/conf/cachelist"},
		"certs":     {LocalPath: "/usr/local/openresty/nginx/conf/certs"},
		"conf":      {LocalPath: "/usr/local/openresty/nginx/conf/sites-enabled"},
		"list":      {LocalPath: "/usr/local/openresty/nginx/conf/sites-enabled/list"},
		"lua":       {LocalPath: "/usr/local/openresty/nginx/conf/lua/domains"},
	}

	// 构建API端点（使用主控地址 + node-file.php）
	apiEndpoint := fmt.Sprintf("%s/node-file.php", config.MasterAddress)

	// 步骤：为每个需要更新的域名下载所有文件类型
	for _, domain := range toUpdateDomains {
		for fileType, pathConfig := range fileTypes {
			// 特殊处理证书文件（需要分别下载key和crt）
			if fileType == "certs" {
				// 下载key文件
				if err := downloadCertFile(domain, fileType, "key", pathConfig.LocalPath, apiEndpoint, config); err != nil {
					log.Printf("下载证书key文件失败（域名: %s）: %v", domain, err)
				}

				// 下载crt文件
				if err := downloadCertFile(domain, fileType, "crt", pathConfig.LocalPath, apiEndpoint, config); err != nil {
					log.Printf("下载证书crt文件失败（域名: %s）: %v", domain, err)
				}
				continue
			}

			// 构造POST请求参数
			reqBody, err := json.Marshal(map[string]string{
				"domain":    domain,
				"node_id":   config.NodeID,
				"node_key":  config.SecretKey,
				"file_type": fileType,
			})
			if err != nil {
				log.Printf("构造%s文件请求体失败（域名: %s）: %v", fileType, domain, err)
				continue
			}

			// 发送POST请求到统一的API端点
			resp, err := http.Post(apiEndpoint, "application/json", bytes.NewBuffer(reqBody))
			if err != nil {
				log.Printf("下载%s文件失败（域名: %s）: %v", fileType, domain, err)
				continue
			}
			defer resp.Body.Close()

			// 检查响应状态码
			if resp.StatusCode != http.StatusOK {
				if resp.StatusCode == http.StatusNotFound {
					log.Printf("文件不存在：%s文件（域名: %s）", fileType, domain)
				} else if resp.StatusCode == http.StatusForbidden {
					log.Printf("节点验证失败：%s文件（域名: %s）", fileType, domain)
				} else {
					log.Printf("下载%s文件失败（域名: %s）: 状态码=%d", fileType, domain, resp.StatusCode)
				}
				continue
			}

			// 读取响应内容
			fileContent, err := io.ReadAll(resp.Body)
			if err != nil {
				log.Printf("读取%s文件响应失败（域名: %s）: %v", fileType, domain, err)
				continue
			}

			// 保存到本地路径（确保目录存在）
			if err := os.MkdirAll(pathConfig.LocalPath, 0755); err != nil {
				log.Printf("创建%s文件目录失败（路径: %s）: %v", fileType, pathConfig.LocalPath, err)
				continue
			}

			// 根据文件类型构造正确的文件名（匹配PHP端的命名规则）
			var fileName string
			switch fileType {
			case "cache":
				fileName = fmt.Sprintf("%s.cache.conf", domain)
			case "cachelist":
				fileName = fmt.Sprintf("%s_cache.conf", domain)
			case "conf":
				fileName = fmt.Sprintf("%s.conf", domain)
			case "list":
				fileName = fmt.Sprintf("%s.list.conf", domain)
			case "lua":
				fileName = fmt.Sprintf("%s.json", domain)
			default:
				fileName = fmt.Sprintf("%s_%s.dat", domain, fileType)
			}

			savePath := filepath.Join(pathConfig.LocalPath, fileName)

			if err := os.WriteFile(savePath, fileContent, 0644); err != nil {
				log.Printf("保存%s文件失败（路径: %s）: %v", fileType, savePath, err)
				continue
			}

			log.Printf("下载成功：%s文件（域名: %s，保存路径: %s）", fileType, domain, savePath)
		}
	}

	return nil
}

// 修改：下载证书文件（分别处理key和crt）
func downloadCertFile(domain, fileType, subType, localPath, apiEndpoint string, config *Config) error {
	// 构造POST请求参数（增加sub_type参数区分key和crt）
	reqBody, err := json.Marshal(map[string]string{
		"domain":    domain,
		"node_id":   config.NodeID,
		"node_key":  config.SecretKey,
		"file_type": fileType,
		"sub_type":  subType, // 区分key和crt
	})
	if err != nil {
		return fmt.Errorf("构造证书%s文件请求体失败: %v", subType, err)
	}

	// 发送POST请求到统一的API端点
	resp, err := http.Post(apiEndpoint, "application/json", bytes.NewBuffer(reqBody))
	if err != nil {
		return fmt.Errorf("下载证书%s文件失败: %v", subType, err)
	}
	defer resp.Body.Close()

	// 检查响应状态码
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("下载证书%s文件失败: 状态码=%d", subType, resp.StatusCode)
	}

	// 读取响应内容
	fileContent, err := io.ReadAll(resp.Body)
	if err != nil {
		return fmt.Errorf("读取证书%s文件响应失败: %v", subType, err)
	}

	// 保存到本地路径（确保目录存在）
	if err := os.MkdirAll(localPath, 0755); err != nil {
		return fmt.Errorf("创建证书目录失败: %v", err)
	}

	// 构造文件名（匹配PHP端的命名规则）
	fileName := fmt.Sprintf("%s.%s", domain, subType)
	savePath := filepath.Join(localPath, fileName)

	if err := os.WriteFile(savePath, fileContent, 0644); err != nil {
		return fmt.Errorf("保存证书%s文件失败: %v", subType, err)
	}

	log.Printf("下载成功：证书%s文件（域名: %s，保存路径: %s）", subType, domain, savePath)
	return nil
}

// 修改：清理指定域名的关联文件（不再依赖file.json）
func cleanDomainFiles(domainsToDelete []string) error {
	pathsToClean := []string{
		"/usr/local/openresty/nginx/conf/sites-enabled/cache",
		"/usr/local/openresty/nginx/conf/cachelist",
		"/usr/local/openresty/nginx/conf/certs",
		"/usr/local/openresty/nginx/conf/sites-enabled",
		"/usr/local/openresty/nginx/conf/sites-enabled/list",
		"/usr/local/openresty/nginx/conf/lua/domains",
	}

	uniquePaths := make(map[string]bool)
	for _, path := range pathsToClean {
		uniquePaths[path] = true
	}

	for _, domain := range domainsToDelete {
		// 1. 先递归删除缓存文件夹
		cacheFolder := filepath.Join("/usr/local/openresty/nginx/cache", domain)
		if _, err := os.Stat(cacheFolder); err == nil {
			if err := os.RemoveAll(cacheFolder); err != nil {
				log.Printf("删除缓存文件夹失败：%s，错误：%v", cacheFolder, err)
			} else {
				log.Printf("成功删除缓存文件夹：%s", cacheFolder)
			}
		}

		// 2. 再清理配置相关文件
		for path := range uniquePaths {
			if _, err := os.Stat(path); os.IsNotExist(err) {
				log.Printf("目录不存在，跳过清理：%s", path)
				continue
			}
			err := filepath.WalkDir(path, func(filePath string, d os.DirEntry, walkErr error) error {
				if walkErr != nil {
					log.Printf("遍历目录失败：%s，错误：%v", filePath, walkErr)
					return nil
				}
				if !d.IsDir() {
					match, _ := filepath.Match(domain+".*", d.Name())
					if match {
						if err := os.Remove(filePath); err != nil {
							log.Printf("删除文件失败：%s，错误：%v", filePath, err)
							return nil
						}
						log.Printf("成功删除文件：%s", filePath)
					}
				}
				return nil
			})
			if err != nil {
				log.Printf("目录遍历异常：%s，错误：%v", path, err)
			}
		}
	}
	return nil
}

// 新增：读取文件最后N行日志
func readLastNLogs(logPath string, n int) ([]string, error) {
	// 1. 检查文件是否存在
	if _, err := os.Stat(logPath); os.IsNotExist(err) {
		return nil, fmt.Errorf("日志文件不存在: %s", logPath)
	}

	// 2. 打开日志文件
	file, err := os.Open(logPath)
	if err != nil {
		return nil, fmt.Errorf("打开日志文件失败: %v", err)
	}
	defer file.Close()

	// 3. 读取整个文件内容（对于大文件可能需要优化为只读取尾部）
	content, err := io.ReadAll(file)
	if err != nil {
		return nil, fmt.Errorf("读取日志文件失败: %v", err)
	}

	// 4. 按行分割
	lines := strings.Split(string(content), "\n")

	// 5. 去除空行
	var nonEmptyLines []string
	for _, line := range lines {
		if line != "" {
			nonEmptyLines = append(nonEmptyLines, line)
		}
	}

	// 6. 获取最后N行（如果行数不足则返回全部）
	startIndex := len(nonEmptyLines) - n
	if startIndex < 0 {
		startIndex = 0
	}

	return nonEmptyLines[startIndex:], nil
}

// 处理日志查询接口（仅支持POST）
func handleLogQuery(w http.ResponseWriter, r *http.Request, config *Config) {
	w.Header().Set("Content-Type", "application/json")

	// 仅允许POST请求（保持原逻辑）
	if r.Method != http.MethodPost {
		w.WriteHeader(http.StatusMethodNotAllowed)
		json.NewEncoder(w).Encode(map[string]string{
			"status":  "error",
			"message": "仅支持POST请求",
		})
		return
	}

	// 节点验证（保持原逻辑）
	if err := verifyRequest(config, r); err != nil {
		w.WriteHeader(http.StatusUnauthorized)
		json.NewEncoder(w).Encode(map[string]string{
			"status":  "error",
			"message": "节点验证失败: " + err.Error(),
		})
		return
	}

	// 解析 log_type（保持原逻辑）
	logType := r.URL.Query().Get("log_type")
	if logType != "go" && logType != "error" {
		w.WriteHeader(http.StatusBadRequest)
		json.NewEncoder(w).Encode(map[string]string{
			"status":  "error",
			"message": "参数错误，log_type 必须为 go 或 error",
		})
		return
	}

	// 加载日志配置（保持原逻辑）
	logConfig, err := loadLogConfig()
	if err != nil {
		w.WriteHeader(http.StatusInternalServerError)
		json.NewEncoder(w).Encode(map[string]string{
			"status":  "error",
			"message": "加载日志配置失败: " + err.Error(),
		})
		return
	}

	// 步骤3：获取对应日志路径（关键修复）
	var logPath string
	if len(logConfig.SelfLogPaths) == 0 {
		w.WriteHeader(http.StatusInternalServerError)
		json.NewEncoder(w).Encode(map[string]string{
			"status":  "error",
			"message": "self_log_paths 配置为空，请检查 ./conf/logs.json",
		})
		return
	}

	// 遍历所有 self_log_paths 条目，寻找第一个有效路径（避免索引越界）
	found := false
	for _, pathConf := range logConfig.SelfLogPaths {
		switch logType {
		case "go":
			if pathConf.Go != "" {
				logPath = pathConf.Go
				found = true
			}
		case "error":
			if pathConf.Error != "" {
				logPath = pathConf.Error
				found = true
			}
		}
		if found {
			break
		}
	}

	if !found {
		w.WriteHeader(http.StatusInternalServerError)
		json.NewEncoder(w).Encode(map[string]string{
			"status":  "error",
			"message": fmt.Sprintf("未找到 %s 类型的日志路径配置（self_log_paths）", logType),
		})
		return
	}

	// 新增：检查日志文件是否实际存在
	if _, err := os.Stat(logPath); os.IsNotExist(err) {
		w.WriteHeader(http.StatusInternalServerError)
		json.NewEncoder(w).Encode(map[string]string{
			"status":  "error",
			"message": fmt.Sprintf("日志文件不存在: %s", logPath),
		})
		return
	}

	// 读取日志（保持原逻辑）
	logs, err := readLastNLogs(logPath, 100)
	if err != nil {
		w.WriteHeader(http.StatusInternalServerError)
		json.NewEncoder(w).Encode(map[string]string{
			"status":  "error",
			"message": "读取日志失败: " + err.Error(),
		})
		return
	}

	// 返回成功响应（保持原逻辑）
	json.NewEncoder(w).Encode(map[string]interface{}{
		"status":   "success",
		"message":  "日志读取成功",
		"log_type": logType,
		"log_path": logPath,
		"logs":     logs,
		"count":    len(logs),
	})
}

// 处理删除缓存文件夹接口（POST）
// 接口功能：验证节点身份后，删除指定域名在/usr/local/openresty/nginx/cache下的文件夹
func handleDeleteCacheFolder(w http.ResponseWriter, r *http.Request, config *Config) {
	w.Header().Set("Content-Type", "application/json")

	// 步骤1：验证节点身份（复用现有验证逻辑）
	if err := verifyRequest(config, r); err != nil {
		w.WriteHeader(http.StatusUnauthorized)
		json.NewEncoder(w).Encode(map[string]string{
			"status":  "error",
			"message": "节点验证失败: " + err.Error(),
		})
		return
	}

	// 步骤2：解析请求中的域名参数（支持表单或JSON）
	var req struct {
		Domain string `json:"domain" form:"domain"` // 支持JSON和表单两种传参方式
	}

	// 优先尝试解析JSON请求体
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		// 若JSON解析失败，尝试解析表单数据
		if err := r.ParseForm(); err != nil {
			w.WriteHeader(http.StatusBadRequest)
			json.NewEncoder(w).Encode(map[string]string{
				"status":  "error",
				"message": "解析请求参数失败: " + err.Error(),
			})
			return
		}
		req.Domain = r.FormValue("domain") // 从表单获取域名
	}

	// 校验域名参数是否存在
	if req.Domain == "" {
		w.WriteHeader(http.StatusBadRequest)
		json.NewEncoder(w).Encode(map[string]string{
			"status":  "error",
			"message": "缺少必要参数：domain",
		})
		return
	}

	// 步骤3：构造要删除的文件夹路径（Linux绝对路径）
	cacheDir := "/usr/local/openresty/nginx/cache"
	targetFolder := filepath.Join(cacheDir, req.Domain) // 自动处理路径分隔符

	// 步骤4：检查文件夹是否存在
	if _, err := os.Stat(targetFolder); os.IsNotExist(err) {
		w.WriteHeader(http.StatusNotFound)
		json.NewEncoder(w).Encode(map[string]string{
			"status":  "error",
			"message": "文件夹不存在: " + targetFolder,
		})
		return
	}

	// 步骤5：删除文件夹（递归删除）
	if err := os.RemoveAll(targetFolder); err != nil {
		w.WriteHeader(http.StatusInternalServerError)
		json.NewEncoder(w).Encode(map[string]string{
			"status":  "error",
			"message": "删除文件夹失败: " + err.Error(),
			"path":    targetFolder,
		})
		return
	}

	// 步骤6：返回成功结果
	json.NewEncoder(w).Encode(map[string]string{
		"status":  "success",
		"message": "文件夹删除成功",
		"path":    targetFolder,
	})
}

func main() {
	// 初始化日志系统
	if err := initLog(); err != nil {
		log.Fatalf("日志初始化失败: %v", err)
	}

	// 加载节点配置（新增）
	configPath := "./conf/conf.json" // Linux相对路径（当前目录/conf/conf.json）
	config, err := loadConfig(configPath)
	if err != nil {
		log.Fatalf("服务启动失败: %v", err) // 配置缺失时终止程序
	}
	log.Printf("节点配置加载成功：ID=%s，主控地址=%s", config.NodeID, config.MasterAddress)

	// 加载完整日志配置（清理+提交路径）
	logConfig, err := loadLogConfig() // 调用修正后的函数
	if err != nil {
		log.Fatalf("服务启动失败: %v", err)
	}
	log.Printf("已加载提交日志路径：%v", logConfig.SubmitLogPaths)

	// 合并程序自身日志路径和配置文件路径（修正 configPaths 为 logConfig.CleanLogPaths）
	logFilesToClean = append(selfGeneratedLogs, logConfig.CleanLogPaths...) // 关键修复
	log.Printf("已加载日志清理路径：%v", logFilesToClean)

	// 新增：检查SSL证书是否存在（启动服务前关键校验）
	if err := checkSSLCerts(); err != nil {
		log.Fatalf("服务启动失败: %v", err) // 证书不存在时终止程序并记录日志
	}

	// 启动每日日志清理任务
	startDailyLogCleaner()

	// 新增：启动每5分钟日志提交任务
	ticker := time.NewTicker(5 * time.Minute)

	// 先立即执行一次日志提交（不等待ticker第一次触发）
	go submitLogsToMaster(config, logConfig.SubmitLogPaths)

	go func() {
		for range ticker.C {
			submitLogsToMaster(config, logConfig.SubmitLogPaths)
		}
	}()
	log.Println("日志提交任务已启动，立即执行首次提交，后续每5分钟执行一次")

	// 注册路由
	http.HandleFunc("/nginx-control", func(w http.ResponseWriter, r *http.Request) {
		handleNginxControl(w, r, config)
	})
	http.HandleFunc("/master-command", func(w http.ResponseWriter, r *http.Request) {
		handleMasterCommand(w, r, config)
	})
	// 主函数中注册日志查询接口
	http.HandleFunc("/api/log_query", func(w http.ResponseWriter, r *http.Request) {
		handleLogQuery(w, r, config) // 传递config用于验证
	})
	// 注册HTTP接口
	http.HandleFunc("/api/delete_cache", func(w http.ResponseWriter, r *http.Request) {
		handleDeleteCacheFolder(w, r, config) // 假设config已加载
	})

	// 启动服务
	port := ":8080"
	log.Printf("服务启动中，监听端口 %s...", port)

	// 新增：自定义TLS配置（仅支持TLS 1.2和1.3）
	tlsConfig := &tls.Config{
		MinVersion: tls.VersionTLS12, // 最低支持TLS 1.2
		MaxVersion: tls.VersionTLS13, // 最高支持TLS 1.3
	}

	// 创建HTTP服务器实例并绑定TLS配置
	server := &http.Server{
		Addr:      port,
		TLSConfig: tlsConfig,
	}

	// 使用自定义配置启动HTTPS服务
	if err := server.ListenAndServeTLS(sslCertPath, sslKeyPath); err != nil {
		log.Fatalf("HTTPS服务启动失败: %v", err)
	}
}
