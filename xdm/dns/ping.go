package main

import (
	"encoding/json"
	"fmt"
	"log"
	"net"
	"os"
	"path/filepath"
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

// Ping测试器
type PingTester struct {
	BasePath      string
	DomainsPath   string
	LockFilePath  string
	BadNodePath   string
	BadNodeExpire int64
}

// 创建新的Ping测试器
func NewPingTester() *PingTester {
	return &PingTester{
		BasePath:      filepath.Join("conf"),
		DomainsPath:   filepath.Join("conf", "domains.json"),
		LockFilePath:  "ping.lock",
		BadNodePath:   filepath.Join("conf", "bad-node.json"),
		BadNodeExpire: 10 * 60, // 10分钟，单位秒
	}
}

// 加载故障节点
func (p *PingTester) loadBadNodes() BadNodes {
	// 检查文件是否存在
	path := filepath.Join(p.BadNodePath)
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
func (p *PingTester) saveBadNodes(badNodes BadNodes) error {
	path := filepath.Join(p.BadNodePath)
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
func (p *PingTester) createLock() bool {
	// 检查锁文件是否存在
	if _, err := os.Stat(p.LockFilePath); err == nil {
		// 检查锁文件是否过期（超过10分钟）
		info, _ := os.Stat(p.LockFilePath)
		modTime := info.ModTime()
		currentTime := time.Now()

		if currentTime.Sub(modTime).Minutes() < 10 {
			log.Println("检测到已存在运行中的实例，本次任务终止")
			return false
		}

		log.Println("发现过期的锁文件，删除并重新创建")
		os.Remove(p.LockFilePath)
	}

	// 创建锁文件
	f, err := os.Create(p.LockFilePath)
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
func (p *PingTester) removeLock() {
	if _, err := os.Stat(p.LockFilePath); err == nil {
		os.Remove(p.LockFilePath)
		log.Println("已删除锁文件")
	}
}

// 使用TCP连接检测主机是否可达
func (p *PingTester) pingHost(host string, timeoutSeconds int) bool {
	// 尝试解析主机名
	addrs, err := net.LookupHost(host)
	if err != nil {
		log.Printf("解析主机名 %s 失败: %v\n", host, err)
		return false
	}

	// 如果解析成功但没有返回地址，也视为失败
	if len(addrs) == 0 {
		log.Printf("主机名 %s 解析未返回任何地址\n", host)
		return false
	}

	// 设置超时时间
	timeout := time.Duration(timeoutSeconds) * time.Second

	// 尝试建立TCP连接到常用端口
	ports := []string{"80", "443"}
	for _, addr := range addrs {
		for _, port := range ports {
			conn, err := net.DialTimeout("tcp", net.JoinHostPort(addr, port), timeout)
			if err == nil {
				conn.Close()
				return true
			}
		}
	}

	// 如果所有连接尝试都失败，尝试简单的连接测试
	conn, err := net.DialTimeout("tcp", net.JoinHostPort(addrs[0], "80"), timeout)
	if err == nil {
		conn.Close()
		return true
	}

	return false
}

// 加载域名记录
func (p *PingTester) loadDomainRecords() (DomainRecords, error) {
	data, err := os.ReadFile(p.DomainsPath)
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
func (p *PingTester) saveDomainRecords(records DomainRecords) error {
	data, err := json.MarshalIndent(records, "", "    ")
	if err != nil {
		return fmt.Errorf("序列化域名记录失败: %v", err)
	}

	err = os.WriteFile(p.DomainsPath, data, 0644)
	if err != nil {
		return fmt.Errorf("保存域名记录文件失败: %v", err)
	}

	return nil
}

// 运行测试
func (p *PingTester) RunTest() {
	// 步骤1：创建文件锁（防止并发）
	if !p.createLock() {
		return
	}

	defer p.removeLock()

	try := func() {
		// 步骤2：读取配置文件
		records, err := p.loadDomainRecords()
		if err != nil {
			log.Printf("加载域名记录失败: %v\n", err)
			return
		}

		// 加载故障节点
		badNodes := p.loadBadNodes()
		nowTs := time.Now().Unix()

		// 清理过期故障节点
		expiredIDs := []string{}
		for nodeID, ts := range badNodes {
			if nowTs-ts >= p.BadNodeExpire {
				expiredIDs = append(expiredIDs, nodeID)
			}
		}
		for _, nodeID := range expiredIDs {
			delete(badNodes, nodeID)
		}

		// 步骤3：遍历所有记录，筛选jk_type为ping的条目
		for recordID, record := range records {
			if record.JkType != "ping" {
				continue
			}

			// 跳过故障节点
			if _, exists := badNodes[recordID]; exists {
				log.Printf("跳过故障节点 %s，等待冷却时间结束\n", recordID)
				continue
			}

			mainValue := record.MainValue
			if mainValue == "" {
				continue
			}

			// 执行两次ping测试（间隔5秒）
			successCount := 0
			for i := 0; i < 2; i++ {
				if p.pingHost(mainValue, 3) {
					successCount++
				}
				time.Sleep(5 * time.Second) // 间隔5秒
			}

			// 步骤4：根据结果更新status
			if successCount == 2 {
				record.Status = true
				records[recordID] = record
				log.Printf("主机 %s (%s) 可达，设置状态为在线\n", mainValue, recordID)
			} else if successCount == 0 {
				record.Status = false
				records[recordID] = record
				// 记录故障节点和当前时间戳
				badNodes[recordID] = nowTs
				log.Printf("主机 %s (%s) 不可达，设置状态为离线并标记为故障节点\n", mainValue, recordID)
			} else {
				log.Printf("主机 %s (%s) 部分可达，保持原状态\n", mainValue, recordID)
			}
		}

		// 步骤5：写回更新后的配置文件
		err = p.saveDomainRecords(records)
		if err != nil {
			log.Printf("保存域名记录失败: %v\n", err)
		}

		// 保存故障节点
		err = p.saveBadNodes(badNodes)
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

	log.Println("Ping测试完成")
}

func main() {
	// 设置日志输出
	log.SetFlags(log.LstdFlags | log.Lshortfile)
	log.Println("开始执行Ping测试...")

	// 创建并运行Ping测试器
	tester := NewPingTester()
	tester.RunTest()
}
