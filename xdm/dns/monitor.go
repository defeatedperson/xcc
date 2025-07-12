package main

import (
	"bytes"
	"crypto/tls"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// 域名记录结构
type DomainRecord struct {
	Host        string `json:"host"`
	MainType    string `json:"main_type"`
	MainValue   string `json:"main_value"`
	BackupType  string `json:"backup_type"`
	BackupValue string `json:"backup_value"`
	Line        string `json:"line"`
	JkType      string `json:"jk_type"`
	Status      bool   `json:"status"`
}

// 域名记录映射
type DomainRecords map[string]DomainRecord

// 故障节点映射 - 记录ID到时间戳的映射
type BadNodes map[string]int64

// 配置文件结构
type Config struct {
	Nodekey   string `json:"nodekey"`
	Domains   string `json:"domains"`
	Secretid  string `json:"Secretid"`
	SecretKey string `json:"SecretKey"`
	Time      string `json:"time"`
	TTL       string `json:"TTL"`
	SlaveKey  string `json:"slave_key"` // 添加SlaveKey字段
}

// 请求体结构
type RequestBody struct {
	Key string `json:"key"`
}

// 响应体结构
type ResponseBody struct {
	Status bool `json:"status"`
}

// Monitor测试器
type MonitorTester struct {
	BasePath      string
	DomainsPath   string
	LockFilePath  string
	BadNodePath   string
	ConfPath      string
	BadNodeExpire int64
	RetryCount    int
	SlaveKey      string
}

// 创建新的Monitor测试器
func NewMonitorTester() *MonitorTester {
	return &MonitorTester{
		BasePath:      filepath.Join("conf"),
		DomainsPath:   filepath.Join("conf", "domains.json"),
		LockFilePath:  "monitor.lock",
		BadNodePath:   filepath.Join("conf", "bad-node.json"),
		ConfPath:      filepath.Join("conf", "conf.json"),
		BadNodeExpire: 30 * 60, // 30分钟，单位秒
		RetryCount:    2,       // 重试次数
	}
}

// 加载配置文件
func (m *MonitorTester) loadConfig() error {
	data, err := os.ReadFile(m.ConfPath)
	if err != nil {
		return fmt.Errorf("读取配置文件失败: %v", err)
	}

	var config Config
	err = json.Unmarshal(data, &config)
	if err != nil {
		return fmt.Errorf("解析配置文件失败: %v", err)
	}

	// 设置SlaveKey
	m.SlaveKey = config.Nodekey // 使用nodekey作为slave_key
	return nil
}

// 加载故障节点
func (m *MonitorTester) loadBadNodes() BadNodes {
	// 检查文件是否存在
	path := filepath.Join(m.BadNodePath)
	if _, err := os.Stat(path); os.IsNotExist(err) {
		return make(BadNodes)
	}

	// 读取文件内容
	data, err := os.ReadFile(path)
	if err != nil {
		log.Printf("读取故障节点文件失败: %v\n", err)
		return make(BadNodes)
	}

	// 如果文件为空，返回空映射
	if len(data) == 0 {
		return make(BadNodes)
	}

	// 解析JSON
	var badNodes BadNodes
	err = json.Unmarshal(data, &badNodes)
	if err != nil {
		log.Printf("解析故障节点文件失败: %v\n", err)
		return make(BadNodes)
	}

	return badNodes
}

// 保存故障节点
func (m *MonitorTester) saveBadNodes(badNodes BadNodes) error {
	path := filepath.Join(m.BadNodePath)
	data, err := json.MarshalIndent(badNodes, "", "    ")
	if err != nil {
		return fmt.Errorf("序列化故障节点失败: %v", err)
	}

	err = os.WriteFile(path, data, 0644)
	if err != nil {
		return fmt.Errorf("保存故障节点文件失败: %v", err)
	}

	return nil
}

// 创建锁文件
func (m *MonitorTester) createLock() bool {
	// 检查锁文件是否存在
	if _, err := os.Stat(m.LockFilePath); err == nil {
		// 检查锁文件是否过期（超过10分钟）
		info, _ := os.Stat(m.LockFilePath)
		modTime := info.ModTime()
		currentTime := time.Now()

		if currentTime.Sub(modTime).Minutes() < 10 {
			log.Println("检测到已存在运行中的实例，本次任务终止")
			return false
		}

		log.Println("发现过期的锁文件，删除并重新创建")
		os.Remove(m.LockFilePath)
	}

	// 创建锁文件
	f, err := os.Create(m.LockFilePath)
	if err != nil {
		log.Printf("创建锁文件失败: %v\n", err)
		return false
	}
	defer f.Close()

	// 写入当前时间和PID
	f.WriteString(fmt.Sprintf("PID: %d\nTime: %d", os.Getpid(), time.Now().Unix()))
	return true
}

// 删除锁文件
func (m *MonitorTester) removeLock() {
	if _, err := os.Stat(m.LockFilePath); err == nil {
		os.Remove(m.LockFilePath)
		log.Println("已删除锁文件")
	}
}

// 格式化URL
func (m *MonitorTester) formatURL(value string) string {
	if value == "" {
		return ""
	}

	value = strings.TrimSpace(value)
	if strings.HasPrefix(value, "http://") || strings.HasPrefix(value, "https://") {
		return value // 已经是完整URL
	}

	// 判断是否为IPv6
	if strings.Contains(value, ":") && !strings.HasPrefix(value, "[") {
		return fmt.Sprintf("https://[%s]:8081/status", value)
	} else {
		return fmt.Sprintf("https://%s:8081/status", value)
	}
}

// 检查节点状态
func (m *MonitorTester) checkNodeStatus(url string) bool {
	// 创建请求体
	reqBody := RequestBody{
		Key: m.SlaveKey,
	}

	// 序列化请求体
	reqData, err := json.Marshal(reqBody)
	if err != nil {
		log.Printf("序列化请求体失败: %v\n", err)
		return false
	}

	// 创建HTTP客户端，忽略SSL证书验证
	tr := &http.Transport{
		TLSClientConfig: &tls.Config{InsecureSkipVerify: true},
	}
	client := &http.Client{
		Timeout:   5 * time.Second,
		Transport: tr,
	}

	// 创建请求
	req, err := http.NewRequest("POST", url, bytes.NewBuffer(reqData))
	if err != nil {
		log.Printf("创建请求失败: %v\n", err)
		return false
	}
	req.Header.Set("Content-Type", "application/json")

	// 发送请求
	resp, err := client.Do(req)
	if err != nil {
		log.Printf("发送请求失败: %v\n", err)
		return false
	}
	defer resp.Body.Close()

	// 检查状态码
	if resp.StatusCode != http.StatusOK {
		log.Printf("请求返回非200状态码: %d\n", resp.StatusCode)
		return false
	}

	// 解析响应
	var respBody ResponseBody
	err = json.NewDecoder(resp.Body).Decode(&respBody)
	if err != nil {
		log.Printf("解析响应失败: %v\n", err)
		return false
	}

	return respBody.Status
}

// 加载域名记录
func (m *MonitorTester) loadDomainRecords() (DomainRecords, error) {
	data, err := os.ReadFile(m.DomainsPath)
	if err != nil {
		return nil, fmt.Errorf("读取域名记录文件失败: %v", err)
	}

	var records DomainRecords
	err = json.Unmarshal(data, &records)
	if err != nil {
		return nil, fmt.Errorf("解析域名记录文件失败: %v", err)
	}

	return records, nil
}

// 保存域名记录
func (m *MonitorTester) saveDomainRecords(records DomainRecords) error {
	data, err := json.MarshalIndent(records, "", "    ")
	if err != nil {
		return fmt.Errorf("序列化域名记录失败: %v", err)
	}

	err = os.WriteFile(m.DomainsPath, data, 0644)
	if err != nil {
		return fmt.Errorf("保存域名记录文件失败: %v", err)
	}

	return nil
}

// 运行测试
func (m *MonitorTester) RunTest() {
	// 步骤1：创建文件锁（防止并发）
	if !m.createLock() {
		return
	}

	defer m.removeLock()

	try := func() {
		// 加载配置
		err := m.loadConfig()
		if err != nil {
			log.Printf("加载配置失败: %v\n", err)
			return
		}

		// 步骤2：读取配置文件
		records, err := m.loadDomainRecords()
		if err != nil {
			log.Printf("加载域名记录失败: %v\n", err)
			return
		}

		// 加载故障节点
		badNodes := m.loadBadNodes()
		nowTs := time.Now().Unix()

		// 清理过期故障节点
		expiredIDs := []string{}
		for nodeID, ts := range badNodes {
			if nowTs-ts >= m.BadNodeExpire {
				expiredIDs = append(expiredIDs, nodeID)
			}
		}
		for _, nodeID := range expiredIDs {
			delete(badNodes, nodeID)
		}

		// 步骤3：遍历所有记录，筛选jk_type为monitor的条目
		for recordID, record := range records {
			if record.JkType != "monitor" {
				continue
			}

			// 跳过故障节点
			if _, exists := badNodes[recordID]; exists {
				log.Printf("跳过故障节点 %s，等待冷却时间结束\n", recordID)
				continue
			}

			rawValue := record.MainValue
			url := m.formatURL(rawValue)
			if url == "" {
				continue
			}

			log.Printf("测试节点 %s (%s)\n", rawValue, url)

			// 重试机制
			successCount := 0
			for i := 0; i < m.RetryCount; i++ {
				if m.checkNodeStatus(url) {
					successCount++
				}
				time.Sleep(2 * time.Second) // 间隔2秒
			}

			// 更新status
			if successCount == m.RetryCount {
				record.Status = true
				records[recordID] = record
				log.Printf("节点 %s (%s) 可达，设置状态为在线\n", rawValue, recordID)
			} else if successCount == 0 {
				record.Status = false
				records[recordID] = record
				// 记录故障节点和当前时间戳
				badNodes[recordID] = nowTs
				log.Printf("节点 %s (%s) 不可达，设置状态为离线并标记为故障节点\n", rawValue, recordID)
			} else {
				log.Printf("节点 %s (%s) 部分可达，保持原状态\n", rawValue, recordID)
			}
		}

		// 步骤5：写回更新后的配置文件
		err = m.saveDomainRecords(records)
		if err != nil {
			log.Printf("保存域名记录失败: %v\n", err)
		}

		// 保存故障节点
		err = m.saveBadNodes(badNodes)
		if err != nil {
			log.Printf("保存故障节点失败: %v\n", err)
		}
	}

	// 执行测试操作并捕获异常
	func() {
		defer func() {
			if r := recover(); r != nil {
				log.Printf("程序发生异常: %v\n", r)
			}
		}()
		try()
	}()

	log.Println("Monitor测试完成")
}

func main() {
	// 设置日志输出
	log.SetFlags(log.LstdFlags | log.Lshortfile)
	log.Println("开始执行Monitor测试...")

	// 创建并运行Monitor测试器
	tester := NewMonitorTester()
	tester.RunTest()
}
