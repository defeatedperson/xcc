package main

import (
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"time"
)

// 配置文件路径
const (
	ConfigPath      = "conf/conf.json"
	DomainsPath     = "conf/domains.json"
	DomainsDataPath = "conf/domains-data.json"
	LogPath         = "conf/log.json"
	DomainsDayPath  = "conf/domains-day.json"
	CertDir         = "ssl"
	// 考虑到证书有效期缩短的趋势，将续签提前时间设为30天
	RenewalDays = 30
	// HTTP服务器端口
	HTTPPort = "8020"
	// ACME挑战文件目录
	ACMEChallengeDir = "/var/www/html/.well-known/acme-challenge"
)

// Config 主配置结构
type Config struct {
	Email        string `json:"email"`
	MasterServer string `json:"master_server"`
	MasterKey    string `json:"master_key"`
}

// DomainsConfig 域名配置结构
type DomainsConfig struct {
	Domains map[string]string `json:"domains"`
}

// Certificate 证书信息结构
type Certificate struct {
	Domains            []string `json:"domains"`
	ExpiryDate         string   `json:"expiry_date"`
	IssueDate          string   `json:"issue_date"`
	Status             string   `json:"status"`
	LastRenewalAttempt string   `json:"last_renewal_attempt"`
	AutoRenew          bool     `json:"auto_renew"`
	FailureReason      string   `json:"failure_reason,omitempty"`
}

// DomainsData 证书数据结构
type DomainsData struct {
	Certificates map[string]Certificate `json:"certificates"`
}

// LogEntry 日志条目结构
type LogEntry struct {
	Timestamp   string `json:"timestamp"`
	DomainID    string `json:"domain_id"`
	Domain      string `json:"domain"`
	Action      string `json:"action"` // "new" 或 "renew"
	Status      string `json:"status"` // "success" 或 "failed"
	ErrorDetail string `json:"error_detail,omitempty"`
}

// LogData 日志数据结构
type LogData struct {
	LastRun string     `json:"last_run"`
	Entries []LogEntry `json:"entries"`
}

// DomainsDayData 每日域名记录结构
type DomainsDayData struct {
	Domains []string `json:"domains"`
}

// 全局变量
var (
	config         Config
	domainsConfig  DomainsConfig
	domainsData    DomainsData
	logData        LogData
	domainsDayData DomainsDayData
	mutex          sync.Mutex
	// 存储ACME挑战令牌的映射
	acmeChallenges = make(map[string]string)
)

// 主函数
func main() {
	// 确保证书目录存在
	if err := os.MkdirAll(CertDir, 0755); err != nil {
		log.Fatalf("无法创建证书目录: %v", err)
	}

	// 加载配置
	loadConfig()

	// 启动HTTP服务器处理ACME挑战
	go startHTTPServer()

	// 首次运行立即处理证书
	processCertificates()

	// 设置每天定时任务
	ticker := time.NewTicker(24 * time.Hour)
	for range ticker.C {
		processCertificates()
	}
}

// 启动HTTP服务器处理ACME挑战
func startHTTPServer() {
	// 处理ACME挑战请求
	http.HandleFunc("/.well-known/acme-challenge/", func(w http.ResponseWriter, r *http.Request) {
		// 获取请求的令牌名称
		tokenName := strings.TrimPrefix(r.URL.Path, "/.well-known/acme-challenge/")
		if tokenName == "" {
			http.NotFound(w, r)
			return
		}

		// 尝试从文件系统读取令牌
		// 尝试从文件系统读取令牌
		filePath := filepath.Join(ACMEChallengeDir, tokenName)
		content, err := os.ReadFile(filePath)
		if err == nil {
			// 如果文件存在，返回文件内容
			w.Write(content)
			log.Printf("返回ACME挑战令牌 %s 的内容: %s", tokenName, string(content))
			return
		}

		// 如果文件不存在，检查内存中的映射
		if token, exists := acmeChallenges[tokenName]; exists {
			w.Write([]byte(token))
			log.Printf("返回内存中的ACME挑战令牌 %s 的内容: %s", tokenName, token)
			return
		}

		// 如果找不到令牌，返回404
		log.Printf("找不到ACME挑战令牌: %s", tokenName)
		http.NotFound(w, r)
	})

	// 启动HTTP服务器
	log.Printf("启动HTTP服务器在端口 %s", HTTPPort)
	if err := http.ListenAndServe(":"+HTTPPort, nil); err != nil {
		log.Fatalf("HTTP服务器启动失败: %v", err)
	}
}

// 加载配置文件
func loadConfig() {
	// 加载主配置
	configData, err := os.ReadFile(ConfigPath)
	if err != nil && !os.IsNotExist(err) {
		log.Printf("警告：无法读取配置文件: %v，将创建新文件", err)
		// 如果文件读取出错但不是因为不存在，初始化空结构
		config = Config{
			Email:        "",
			MasterServer: "",
			MasterKey:    "",
		}
		// 保存初始化的空结构到文件
		saveConfig()
		return // 跳过本次循环
	} else if err == nil {
		// 文件存在，尝试解析
		if len(configData) == 0 || string(configData) == "{" || string(configData) == "{\n" {
			// 文件为空或只有开括号，初始化空结构
			log.Printf("警告：配置文件为空或格式不正确，将初始化新文件")
			config = Config{
				Email:        "",
				MasterServer: "",
				MasterKey:    "",
			}
			// 保存初始化的空结构到文件
			saveConfig()
			return // 跳过本次循环
		} else {
			// 尝试解析文件
			if err := json.Unmarshal(configData, &config); err != nil {
				log.Printf("警告：无法解析配置文件: %v，将创建新文件", err)
				// 解析失败，初始化空结构
				config = Config{
					Email:        "",
					MasterServer: "",
					MasterKey:    "",
				}
				// 保存初始化的空结构到文件
				saveConfig()
				return // 跳过本次循环
			}
		}
	} else {
		// 如果文件不存在，初始化空结构
		log.Printf("配置文件不存在，将创建新文件")
		config = Config{
			Email:        "",
			MasterServer: "",
			MasterKey:    "",
		}
		// 保存初始化的空结构到文件
		saveConfig()
		return // 跳过本次循环
	}

	// 加载域名配置
	domainsFileData, err := os.ReadFile(DomainsPath)
	if err != nil && !os.IsNotExist(err) {
		log.Printf("警告：无法读取域名配置文件: %v，将创建新文件", err)
		// 如果文件读取出错但不是因为不存在，初始化空结构
		domainsConfig = DomainsConfig{
			Domains: make(map[string]string),
		}
		// 保存初始化的空结构到文件
		saveDomainsConfig()
		return // 跳过本次循环
	} else if err == nil {
		// 文件存在，尝试解析
		if len(domainsFileData) == 0 || string(domainsFileData) == "{" || string(domainsFileData) == "{\n" {
			// 文件为空或只有开括号，初始化空结构
			log.Printf("警告：域名配置文件为空或格式不正确，将初始化新文件")
			domainsConfig = DomainsConfig{
				Domains: make(map[string]string),
			}
			// 保存初始化的空结构到文件
			saveDomainsConfig()
			return // 跳过本次循环
		} else {
			// 尝试解析文件
			if err := json.Unmarshal(domainsFileData, &domainsConfig); err != nil {
				log.Printf("警告：无法解析域名配置文件: %v，将创建新文件", err)
				// 解析失败，初始化空结构
				domainsConfig = DomainsConfig{
					Domains: make(map[string]string),
				}
				// 保存初始化的空结构到文件
				saveDomainsConfig()
				return // 跳过本次循环
			}
		}
	} else {
		// 如果文件不存在，初始化空结构
		log.Printf("域名配置文件不存在，将创建新文件")
		domainsConfig = DomainsConfig{
			Domains: make(map[string]string),
		}
		// 保存初始化的空结构到文件
		saveDomainsConfig()
		return // 跳过本次循环
	}

	// 加载证书数据
	domainsDataFile, err := os.ReadFile(DomainsDataPath)
	if err != nil && !os.IsNotExist(err) {
		log.Printf("警告：无法读取证书数据文件: %v，将创建新文件", err)
		// 如果文件读取出错但不是因为不存在，也初始化空结构
		domainsData = DomainsData{
			Certificates: make(map[string]Certificate),
		}
		// 保存初始化的空结构到文件
		saveDomainsData()
	} else if err == nil {
		// 文件存在，尝试解析
		if len(domainsDataFile) == 0 || string(domainsDataFile) == "{" || string(domainsDataFile) == "{\n" {
			// 文件为空或只有开括号，初始化空结构
			log.Printf("警告：证书数据文件为空或格式不正确，将初始化新文件")
			domainsData = DomainsData{
				Certificates: make(map[string]Certificate),
			}
			// 保存初始化的空结构到文件
			saveDomainsData()
		} else {
			// 尝试解析文件
			if err := json.Unmarshal(domainsDataFile, &domainsData); err != nil {
				log.Printf("警告：无法解析证书数据文件: %v，将创建新文件", err)
				// 解析失败，初始化空结构
				domainsData = DomainsData{
					Certificates: make(map[string]Certificate),
				}
				// 保存初始化的空结构到文件
				saveDomainsData()
			}
		}
	} else {
		// 如果文件不存在，初始化空结构
		log.Printf("证书数据文件不存在，将创建新文件")
		domainsData = DomainsData{
			Certificates: make(map[string]Certificate),
		}
		// 保存初始化的空结构到文件
		saveDomainsData()
	}

	// 加载日志数据
	logDataFile, err := os.ReadFile(LogPath)
	if err != nil && !os.IsNotExist(err) {
		log.Printf("警告：无法读取日志文件: %v，将创建新文件", err)
		// 如果文件读取出错但不是因为不存在，也初始化空结构
		logData = LogData{
			LastRun: time.Now().Format(time.RFC3339),
			Entries: []LogEntry{},
		}
		// 保存初始化的空结构到文件
		saveLogData()
	} else if err == nil {
		// 文件存在，尝试解析
		if len(logDataFile) == 0 || string(logDataFile) == "{" || string(logDataFile) == "{\n" {
			// 文件为空或只有开括号，初始化空结构
			log.Printf("警告：日志文件为空或格式不正确，将初始化新文件")
			logData = LogData{
				LastRun: time.Now().Format(time.RFC3339),
				Entries: []LogEntry{},
			}
			// 保存初始化的空结构到文件
			saveLogData()
		} else {
			// 尝试解析文件
			if err := json.Unmarshal(logDataFile, &logData); err != nil {
				log.Printf("警告：无法解析日志文件: %v，将创建新文件", err)
				// 解析失败，初始化空结构
				logData = LogData{
					LastRun: time.Now().Format(time.RFC3339),
					Entries: []LogEntry{},
				}
				// 保存初始化的空结构到文件
				saveLogData()
			}
		}
	} else {
		// 如果文件不存在，初始化空结构
		log.Printf("日志文件不存在，将创建新文件")
		logData = LogData{
			LastRun: time.Now().Format(time.RFC3339),
			Entries: []LogEntry{},
		}
		// 保存初始化的空结构到文件
		saveLogData()
	}

	// 加载每日域名记录数据
	domainsDayFile, err := os.ReadFile(DomainsDayPath)
	if err != nil && !os.IsNotExist(err) {
		log.Printf("警告：无法读取每日域名记录文件: %v，将创建新文件", err)
		// 如果文件读取出错但不是因为不存在，也初始化空结构
		domainsDayData = DomainsDayData{
			Domains: []string{},
		}
		// 保存初始化的空结构到文件
		saveDomainsDayData()
	} else if err == nil {
		// 文件存在，尝试解析
		if len(domainsDayFile) == 0 || string(domainsDayFile) == "{" || string(domainsDayFile) == "{\n" {
			// 文件为空或只有开括号，初始化空结构
			log.Printf("警告：每日域名记录文件为空或格式不正确，将初始化新文件")
			domainsDayData = DomainsDayData{
				Domains: []string{},
			}
			// 保存初始化的空结构到文件
			saveDomainsDayData()
		} else {
			// 尝试解析文件
			if err := json.Unmarshal(domainsDayFile, &domainsDayData); err != nil {
				log.Printf("警告：无法解析每日域名记录文件: %v，将创建新文件", err)
				// 解析失败，初始化空结构
				domainsDayData = DomainsDayData{
					Domains: []string{},
				}
				// 保存初始化的空结构到文件
				saveDomainsDayData()
			}
		}
	} else {
		// 如果文件不存在，初始化空结构
		log.Printf("每日域名记录文件不存在，将创建新文件")
		domainsDayData = DomainsDayData{
			Domains: []string{},
		}
		// 保存初始化的空结构到文件
		saveDomainsDayData()
	}
}

// 处理证书申请和续签
func processCertificates() {
	log.Println("开始处理证书...")

	// 更新最后运行时间
	mutex.Lock()
	logData.LastRun = time.Now().Format(time.RFC3339)
	logData.Entries = []LogEntry{} // 清空之前的日志条目

	// 清空每日域名记录
	domainsDayData.Domains = []string{}
	mutex.Unlock()

	// 收集需要处理的域名
	domainsToProcess := make(map[string][]string) // 证书ID -> 域名列表
	domainIDMap := make(map[string]string)        // 域名 -> 域名ID

	// 遍历需要申请的域名
	for domainID, domain := range domainsConfig.Domains {
		domainIDMap[domain] = domainID

		// 检查是否需要申请或续签
		certID, _, needProcess := checkCertificateStatus(domain)
		if !needProcess {
			continue
		}

		// 如果是新证书，创建新的证书ID
		if certID == "" {
			// 修改这部分代码以兼容不同格式的domainID
			var certIDSuffix string

			// 检查domainID是否符合domain_XXX格式
			if len(domainID) > 7 && strings.HasPrefix(domainID, "domain_") {
				// 原有逻辑：从domain_001提取001
				certIDSuffix = domainID[7:]
			} else {
				// 新增逻辑：直接使用domainID作为后缀
				certIDSuffix = domainID
			}

			certID = "cert_" + certIDSuffix

			// 确保ID不重复
			for _, exists := domainsData.Certificates[certID]; exists; {
				// 如果ID已存在，添加后缀
				certID = certID + "_new"
				_, exists = domainsData.Certificates[certID]
			}
		}

		// 将域名添加到对应的证书中
		domainsToProcess[certID] = append(domainsToProcess[certID], domain)
	}

	// 处理每个证书（一次只处理一个）
	for certID, domains := range domainsToProcess {
		// 获取第一个域名作为主域名（用于日志记录）
		primaryDomain := domains[0]
		domainID := domainIDMap[primaryDomain]

		// 确定操作类型
		_, actionType, _ := checkCertificateStatus(primaryDomain)

		log.Printf("处理证书 %s，域名: %v，操作: %s", certID, domains, actionType)

		// 申请或续签证书
		success, errMsg := requestCertificate(certID, domains)

		// 记录日志
		logEntry := LogEntry{
			Timestamp: time.Now().Format(time.RFC3339),
			DomainID:  domainID,
			Domain:    primaryDomain,
			Action:    actionType,
			Status:    "success",
		}

		if !success {
			logEntry.Status = "failed"
			logEntry.ErrorDetail = errMsg
		} else {
			// 如果成功申请了证书，将域名添加到每日记录中
			mutex.Lock()
			domainsDayData.Domains = append(domainsDayData.Domains, domains...)
			saveDomainsDayData()
			mutex.Unlock()
		}

		mutex.Lock()
		logData.Entries = append(logData.Entries, logEntry)
		saveLogData()
		mutex.Unlock()

		// 每次只处理一个证书
		break
	}

	// 保存每日域名记录（即使为空，也会写入空数组）
	mutex.Lock()
	saveDomainsDayData()
	mutex.Unlock()

	log.Println("证书处理完成")
}

// 检查证书状态
func checkCertificateStatus(domain string) (string, string, bool) {
	// 查找域名对应的证书
	var certID string
	var cert Certificate
	var found bool

	for id, c := range domainsData.Certificates {
		for _, d := range c.Domains {
			if d == domain {
				certID = id
				cert = c
				found = true
				break
			}
		}
		if found {
			break
		}
	}

	// 如果没有找到证书，需要新申请
	if !found {
		return "", "new", true
	}

	// 检查证书状态
	if cert.Status != "success" {
		return certID, "new", true
	}

	// 检查是否需要续签
	expiry, err := time.Parse(time.RFC3339, cert.ExpiryDate)
	if err != nil {
		log.Printf("无法解析证书到期时间 %s: %v", cert.ExpiryDate, err)
		return certID, "renew", true
	}

	// 如果证书将在RenewalDays天内到期，需要续签
	if time.Until(expiry) < time.Duration(RenewalDays)*24*time.Hour {
		return certID, "renew", true
	}

	return certID, "", false
}

// 请求证书 - 使用acme.sh
func requestCertificate(certID string, domains []string) (bool, string) {
	// 在Linux环境中，acme.sh通常安装在~/.acme.sh目录下
	acmeShPath := filepath.Join("/root", ".acme.sh", "acme.sh")
	if _, err := os.Stat(acmeShPath); os.IsNotExist(err) {
		errMsg := "acme.sh未安装或未找到，请先安装acme.sh: https://github.com/acmesh-official/acme.sh"
		log.Println(errMsg)
		return false, errMsg
	}

	// 准备域名参数
	domainArgs := ""
	for _, domain := range domains {
		domainArgs += "-d " + domain + " "
	}

	// 确保ACME挑战目录存在
	if err := os.MkdirAll(ACMEChallengeDir, 0755); err != nil {
		errMsg := fmt.Sprintf("无法创建ACME挑战目录: %v", err)
		log.Println(errMsg)
		return false, errMsg
	}

	// 构建acme.sh命令 - 使用--webroot模式并添加--debug参数
	// 使用--webroot-path指定挑战文件的父目录
	webrootDir := filepath.Dir(filepath.Dir(ACMEChallengeDir)) // 获取/var/www/html
	cmd := fmt.Sprintf("%s --issue %s --webroot %s --server letsencrypt --debug",
		acmeShPath, domainArgs, webrootDir)

	// 执行命令
	execCmd := exec.Command("sh", "-c", cmd)
	output, err := execCmd.CombinedOutput()
	outputStr := string(output)

	if err != nil {
		errMsg := fmt.Sprintf("无法获取域名的证书: %v\n%s", err, outputStr)
		log.Println(errMsg)

		// 更新证书状态
		updateCertificateStatus(certID, domains, "failed", errMsg)
		return false, errMsg
	}

	// 检查证书文件是否存在
	for _, domain := range domains {
		// acme.sh证书路径 - Linux路径格式
		// 注意：acme.sh现在默认使用ECC证书，路径中包含_ecc
		acmeCertDir := filepath.Join("/root", ".acme.sh", domain+"_ecc")
		acmeCertPath := filepath.Join(acmeCertDir, domain+".cer")
		acmeKeyPath := filepath.Join(acmeCertDir, domain+".key")

		// 目标路径
		certPath := filepath.Join(CertDir, domain+".crt")
		keyPath := filepath.Join(CertDir, domain+".key")

		// 复制证书和密钥
		certData, err := os.ReadFile(acmeCertPath)
		if err != nil {
			errMsg := fmt.Sprintf("无法读取证书 %s: %v", acmeCertPath, err)
			log.Println(errMsg)
			continue
		}

		keyData, err := os.ReadFile(acmeKeyPath)
		if err != nil {
			errMsg := fmt.Sprintf("无法读取私钥 %s: %v", acmeKeyPath, err)
			log.Println(errMsg)
			continue
		}

		// 保存证书和密钥到文件
		if err := os.WriteFile(certPath, certData, 0644); err != nil {
			log.Printf("无法保存证书 %s: %v", certPath, err)
		}

		if err := os.WriteFile(keyPath, keyData, 0600); err != nil {
			log.Printf("无法保存私钥 %s: %v", keyPath, err)
		}

		log.Printf("证书已保存: %s, %s", certPath, keyPath)
	}

	// 更新证书状态
	updateCertificateStatus(certID, domains, "success", "")
	return true, ""
}

// 更新证书状态
func updateCertificateStatus(certID string, domains []string, status, failureReason string) {
	mutex.Lock()
	defer mutex.Unlock()

	// 更新或创建证书信息
	now := time.Now()
	// 考虑到证书有效期缩短的趋势，这里假设为90天
	expiry := now.AddDate(0, 0, 90)

	cert := Certificate{
		Domains:            domains,
		IssueDate:          now.Format(time.RFC3339),
		ExpiryDate:         expiry.Format(time.RFC3339),
		Status:             status,
		LastRenewalAttempt: now.Format(time.RFC3339),
		AutoRenew:          true,
	}

	if status == "failed" {
		cert.FailureReason = failureReason
	}

	domainsData.Certificates[certID] = cert

	// 保存证书数据
	saveDomainsData()
}

// 保存配置数据
func saveConfig() {
	data, err := json.MarshalIndent(config, "", "  ")
	if err != nil {
		log.Printf("无法序列化配置数据: %v", err)
		return
	}

	if err := os.WriteFile(ConfigPath, data, 0644); err != nil {
		log.Printf("无法保存配置数据: %v", err)
	}
}

// 保存域名配置数据
func saveDomainsConfig() {
	data, err := json.MarshalIndent(domainsConfig, "", "  ")
	if err != nil {
		log.Printf("无法序列化域名配置数据: %v", err)
		return
	}

	if err := os.WriteFile(DomainsPath, data, 0644); err != nil {
		log.Printf("无法保存域名配置数据: %v", err)
	}
}

// 保存证书数据
func saveDomainsData() {
	data, err := json.MarshalIndent(domainsData, "", "  ")
	if err != nil {
		log.Printf("无法序列化证书数据: %v", err)
		return
	}

	if err := os.WriteFile(DomainsDataPath, data, 0644); err != nil {
		log.Printf("无法保存证书数据: %v", err)
	}
}

// 保存日志数据
func saveLogData() {
	data, err := json.MarshalIndent(logData, "", "  ")
	if err != nil {
		log.Printf("无法序列化日志数据: %v", err)
		return
	}

	if err := os.WriteFile(LogPath, data, 0644); err != nil {
		log.Printf("无法保存日志数据: %v", err)
	}
}

// 保存每日域名记录数据
func saveDomainsDayData() {
	data, err := json.MarshalIndent(domainsDayData, "", "  ")
	if err != nil {
		log.Printf("无法序列化每日域名记录数据: %v", err)
		return
	}

	if err := os.WriteFile(DomainsDayPath, data, 0644); err != nil {
		log.Printf("无法保存每日域名记录数据: %v", err)
	}
}
