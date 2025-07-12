package main

import (
	"encoding/json"
	"fmt"
	"log"
	"os"
	"path/filepath"
	"strconv" // 用于字符串转数字
	"strings" // 用于字符串操作
	"time"

	"github.com/tencentcloud/tencentcloud-sdk-go/tencentcloud/common"
	"github.com/tencentcloud/tencentcloud-sdk-go/tencentcloud/common/errors"
	"github.com/tencentcloud/tencentcloud-sdk-go/tencentcloud/common/profile"
	"github.com/tencentcloud/tencentcloud-sdk-go/tencentcloud/common/regions"

	// 修改导入路径，使用正确的版本
	dnspod "github.com/tencentcloud/tencentcloud-sdk-go/tencentcloud/dnspod/v20210323"
)

// 配置文件结构
type Config struct {
	Nodekey   string `json:"nodekey"`
	Domains   string `json:"domains"`
	Secretid  string `json:"Secretid"`
	SecretKey string `json:"SecretKey"`
	Time      string `json:"time"`
	TTL       string `json:"TTL"` // 添加TTL字段
}

// DNS记录结构
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

// DNS记录映射
type DomainRecords map[string]DomainRecord

// DNS记录ID映射
type DomainLineMap map[string]string

// DNSPod服务
type DNSPodService struct {
	Client     *dnspod.Client
	Domain     string
	DNSListMap DomainLineMap
	TTL        uint64
	BasePath   string
}

// 创建DNSPod服务
func NewDNSPodService(secretID, secretKey, domain string, ttl uint64) *DNSPodService {
	// 创建认证对象
	credential := common.NewCredential(secretID, secretKey)

	// 创建客户端配置
	clientProfile := profile.NewClientProfile()
	clientProfile.HttpProfile.Endpoint = "dnspod.tencentcloudapi.com"
	clientProfile.HttpProfile.ReqTimeout = 30
	clientProfile.Language = "zh-CN"

	// 创建客户端
	client, _ := dnspod.NewClient(credential, regions.Guangzhou, clientProfile)

	// 返回服务对象
	return &DNSPodService{
		Client:     client,
		Domain:     domain,
		DNSListMap: make(DomainLineMap),
		TTL:        ttl, // 使用传入的TTL值
		BasePath:   filepath.Join("conf"),
	}
}

// 加载配置文件
func LoadConfig(path string) (*Config, error) {
	data, err := os.ReadFile(path) // 使用 os.ReadFile 替代 ioutil.ReadFile
	if err != nil {
		return nil, fmt.Errorf("读取配置文件失败: %v", err)
	}

	var config Config
	err = json.Unmarshal(data, &config)
	if err != nil {
		return nil, fmt.Errorf("解析配置文件失败: %v", err)
	}

	return &config, nil
}

// 加载域名记录
func LoadDomainRecords(path string) (DomainRecords, error) {
	// 检查文件是否存在
	if _, err := os.Stat(path); os.IsNotExist(err) {
		// 文件不存在，创建一个空的记录
		emptyRecords := make(DomainRecords)
		// 确保目录存在
		if err := os.MkdirAll(filepath.Dir(path), 0755); err != nil {
			return nil, fmt.Errorf("创建目录失败: %v", err)
		}
		// 写入空记录
		data, _ := json.MarshalIndent(emptyRecords, "", "    ")
		if err := os.WriteFile(path, data, 0644); err != nil {
			return nil, fmt.Errorf("创建域名记录文件失败: %v", err)
		}
		return emptyRecords, nil
	}

	data, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("读取域名记录文件失败: %v", err)
	}

	// 检查文件内容是否为空
	if len(data) == 0 || string(data) == "" {
		emptyRecords := make(DomainRecords)
		// 写入空记录
		newData, _ := json.MarshalIndent(emptyRecords, "", "    ")
		if err := os.WriteFile(path, newData, 0644); err != nil {
			log.Printf("写入空域名记录文件失败: %v", err)
		}
		return emptyRecords, nil
	}

	var records DomainRecords
	err = json.Unmarshal(data, &records)
	if err != nil {
		// 解析失败，创建空记录
		emptyRecords := make(DomainRecords)
		// 写入空记录
		newData, _ := json.MarshalIndent(emptyRecords, "", "    ")
		if err := os.WriteFile(path, newData, 0644); err != nil {
			log.Printf("写入空域名记录文件失败: %v", err)
		}
		return emptyRecords, fmt.Errorf("解析域名记录文件失败: %v", err)
	}

	return records, nil
}

// 加载DNS记录ID映射
func (s *DNSPodService) LoadDNSListMap() error {
	path := filepath.Join(s.BasePath, "domains-line.json")

	// 如果文件不存在，返回空映射
	if _, err := os.Stat(path); os.IsNotExist(err) {
		s.DNSListMap = make(DomainLineMap)
		return nil
	}

	data, err := os.ReadFile(path) // 使用 os.ReadFile 替代 ioutil.ReadFile
	if err != nil {
		return fmt.Errorf("读取DNS记录ID映射文件失败: %v", err)
	}

	// 如果文件为空，返回空映射
	if len(data) == 0 {
		s.DNSListMap = make(DomainLineMap)
		return nil
	}

	err = json.Unmarshal(data, &s.DNSListMap)
	if err != nil {
		return fmt.Errorf("解析DNS记录ID映射文件失败: %v", err)
	}

	return nil
}

// 保存DNS记录ID映射
func (s *DNSPodService) SaveDNSListMap() error {
	path := filepath.Join(s.BasePath, "domains-line.json")

	data, err := json.MarshalIndent(s.DNSListMap, "", "    ")
	if err != nil {
		return fmt.Errorf("序列化DNS记录ID映射失败: %v", err)
	}

	err = os.WriteFile(path, data, 0644) // 使用 os.WriteFile 替代 ioutil.WriteFile
	if err != nil {
		return fmt.Errorf("保存DNS记录ID映射文件失败: %v", err)
	}

	return nil
}

// 添加或修改DNS记录
func (s *DNSPodService) AddOrModifyRecord(subdomain, recordType, line, value string) (string, error) {
	// 构建记录键
	recordKey := fmt.Sprintf("%s_%s_%s", subdomain, recordType, line)

	// 检查记录是否存在
	recordID, exists := s.DNSListMap[recordKey]

	if exists {
		// 修改记录
		request := dnspod.NewModifyRecordRequest()
		request.Domain = common.StringPtr(s.Domain)
		request.RecordId = common.Uint64Ptr(uint64(stringToUint64(recordID)))
		request.SubDomain = common.StringPtr(subdomain)
		request.RecordType = common.StringPtr(recordType)
		request.RecordLine = common.StringPtr(line)
		request.Value = common.StringPtr(value)
		request.TTL = common.Uint64Ptr(s.TTL)

		_, err := s.Client.ModifyRecord(request)
		if err != nil {
			// 处理API错误
			sdkErr, ok := err.(*errors.TencentCloudSDKError)
			if ok {
				if sdkErr.Code == "ResourceNotFound.RecordNotExists" {
					// 记录不存在，删除映射
					delete(s.DNSListMap, recordKey)
					// 尝试创建新记录
					return s.createRecord(subdomain, recordType, line, value, recordKey)
				}
			}
			return "", fmt.Errorf("修改DNS记录失败: %v", err)
		}

		log.Printf("修改解析记录成功: %s %s %s -> %s\n", subdomain, recordType, line, value)
		return recordID, nil
	} else {
		// 创建新记录
		return s.createRecord(subdomain, recordType, line, value, recordKey)
	}
}

// 创建新记录（内部方法）
func (s *DNSPodService) createRecord(subdomain, recordType, line, value, recordKey string) (string, error) {
	request := dnspod.NewCreateRecordRequest()
	request.Domain = common.StringPtr(s.Domain)
	request.SubDomain = common.StringPtr(subdomain)
	request.RecordType = common.StringPtr(recordType)
	request.RecordLine = common.StringPtr(line)
	request.Value = common.StringPtr(value)
	request.TTL = common.Uint64Ptr(s.TTL)

	response, err := s.Client.CreateRecord(request)
	if err != nil {
		return "", fmt.Errorf("创建DNS记录失败: %v", err)
	}

	// 获取新记录ID
	newRecordID := fmt.Sprintf("%d", *response.Response.RecordId)

	// 更新映射
	s.DNSListMap[recordKey] = newRecordID

	log.Printf("添加解析记录成功: %s %s %s -> %s\n", subdomain, recordType, line, value)
	return newRecordID, nil
}

// 删除DNS记录
func (s *DNSPodService) DeleteRecord(recordKey string) error {
	recordID, exists := s.DNSListMap[recordKey]
	if !exists {
		log.Printf("记录 %s 不存在，无需删除\n", recordKey)
		return nil
	}

	request := dnspod.NewDeleteRecordRequest()
	request.Domain = common.StringPtr(s.Domain)
	request.RecordId = common.Uint64Ptr(uint64(stringToUint64(recordID)))

	_, err := s.Client.DeleteRecord(request)
	if err != nil {
		// 处理API错误
		sdkErr, ok := err.(*errors.TencentCloudSDKError)
		if ok {
			if sdkErr.Code == "ResourceNotFound.RecordNotExists" {
				// 记录不存在，仅删除映射
				delete(s.DNSListMap, recordKey)
				log.Printf("记录 %s 在DNSPod中已不存在，已清理本地映射\n", recordKey)
				return nil
			}
		}
		return fmt.Errorf("删除DNS记录失败: %v", err)
	}

	// 删除映射
	delete(s.DNSListMap, recordKey)
	log.Printf("删除解析记录成功: %s\n", recordKey)
	return nil
}

// 自动同步DNS记录
func (s *DNSPodService) AutoSyncDNSRecords(records DomainRecords) error {
	// 跟踪需要保留的记录键
	recordsToKeep := make(map[string]bool)

	// 跟踪已处理的子域名，防止重复
	processedHosts := make(map[string]bool)

	// 跟踪子域名到记录类型的映射，用于检测冲突
	hostTypeMap := make(map[string]string)

	// 第一步：扫描配置，确定每个子域名需要的记录类型
	for recordID, record := range records {
		host := record.Host

		// 跳过重复的子域名配置
		if processedHosts[host] {
			log.Printf("警告: 子域名 %s 在配置中重复出现，跳过ID为 %s 的配置\n", host, recordID)
			continue
		}

		// 确定当前需要的记录类型
		var currentType string
		if record.Status {
			currentType = record.MainType
		} else {
			currentType = record.BackupType
		}

		// 记录该子域名需要的记录类型
		processedHosts[host] = true
		hostTypeMap[host] = currentType
	}

	// 第二步：删除冲突记录
	for host, neededType := range hostTypeMap {
		// 检查是否存在其他类型的记录需要先删除
		conflictTypes := []string{"A", "CNAME"}
		for _, otherType := range conflictTypes {
			if otherType != neededType {
				conflictKey := fmt.Sprintf("%s_%s_%s", host, otherType, "默认") // 假设使用默认线路，可以扩展为遍历所有线路
				if _, exists := s.DNSListMap[conflictKey]; exists {
					log.Printf("检测到记录类型冲突，删除 %s 记录以便添加 %s 记录\n", otherType, neededType)
					err := s.DeleteRecord(conflictKey)
					if err != nil {
						log.Printf("删除冲突记录 %s 失败: %v\n", conflictKey, err)
					}
				}
			}
		}
	}

	// 第三步：添加或修改记录
	processedHosts = make(map[string]bool) // 重置已处理标记
	for _, record := range records {
		host := record.Host

		// 跳过重复的子域名配置
		if processedHosts[host] {
			continue
		}

		processedHosts[host] = true
		line := record.Line
		status := record.Status

		// 根据status决定使用main还是backup
		var recordType, value string
		if status {
			recordType = record.MainType
			value = record.MainValue
		} else {
			recordType = record.BackupType
			value = record.BackupValue
		}

		// 构建记录键
		recordKey := fmt.Sprintf("%s_%s_%s", host, recordType, line)
		recordsToKeep[recordKey] = true

		// 添加或修改记录
		_, err := s.AddOrModifyRecord(host, recordType, line, value)
		if err != nil {
			log.Printf("处理记录 %s 失败: %v\n", recordKey, err)
			// 如果是冲突错误，可以尝试特殊处理
			if strings.Contains(err.Error(), "A 记录和 CNAME 记录有冲突") {
				log.Printf("检测到 A 和 CNAME 记录冲突，尝试再次删除冲突记录...\n")

				// 尝试删除可能的冲突记录
				conflictType := "CNAME"
				if recordType == "CNAME" {
					conflictType = "A"
				}

				conflictKey := fmt.Sprintf("%s_%s_%s", host, conflictType, line)
				s.DeleteRecord(conflictKey)

				// 重试添加记录
				time.Sleep(2 * time.Second) // 等待DNS系统处理删除操作
				_, retryErr := s.AddOrModifyRecord(host, recordType, line, value)
				if retryErr != nil {
					log.Printf("重试处理记录 %s 仍然失败: %v\n", recordKey, retryErr)
				}
			}
		}
	}

	// 删除多余的记录
	for recordKey := range s.DNSListMap {
		if !recordsToKeep[recordKey] {
			err := s.DeleteRecord(recordKey)
			if err != nil {
				log.Printf("删除多余记录 %s 失败: %v\n", recordKey, err)
			}
		}
	}

	return nil
}

// 创建锁文件
func CreateLockFile(path string) bool {
	// 检查锁文件是否存在
	if _, err := os.Stat(path); err == nil {
		// 检查锁文件是否过期（超过10分钟）
		info, _ := os.Stat(path)
		modTime := info.ModTime()
		currentTime := time.Now()

		if currentTime.Sub(modTime).Minutes() < 10 {
			log.Println("发现有效的锁文件，程序已在运行中，退出执行")
			return false
		}

		log.Println("发现过期的锁文件，删除并重新创建")
		os.Remove(path)
	}

	// 创建锁文件
	f, err := os.Create(path)
	if err != nil {
		log.Printf("创建锁文件失败: %v\n", err)
		return false
	}
	defer f.Close()

	// 写入当前时间
	f.WriteString(fmt.Sprintf("%d", time.Now().Unix()))
	return true
}

// 删除锁文件
func RemoveLockFile(path string) {
	if _, err := os.Stat(path); err == nil {
		os.Remove(path)
		log.Println("已删除锁文件")
	}
}

// 字符串转uint64
func stringToUint64(s string) uint64 {
	var result uint64
	fmt.Sscanf(s, "%d", &result)
	return result
}

func main() {
	// 设置日志输出
	log.SetFlags(log.LstdFlags | log.Lshortfile)

	// 加载配置文件
	config, err := LoadConfig("conf/conf.json")
	if err != nil {
		log.Fatalf("加载配置文件失败: %v\n", err)
	}

	// 检查必要参数
	if config.Domains == "" || config.SecretKey == "" || config.Secretid == "" {
		log.Fatalf("配置文件缺少必要参数: domains, SecretId, SecretKey\n")
	}

	// 解析TTL值
	ttl := uint64(600) // 默认值
	if config.TTL != "" {
		parsedTTL, err := strconv.ParseUint(config.TTL, 10, 64)
		if err == nil {
			ttl = parsedTTL
		} else {
			log.Printf("解析TTL值失败，使用默认值600: %v\n", err)
		}
	}

	// 创建DNSPod服务
	dnsService := NewDNSPodService(config.Secretid, config.SecretKey, config.Domains, ttl)

	// 加载DNS记录ID映射
	err = dnsService.LoadDNSListMap()
	if err != nil {
		log.Printf("加载DNS记录ID映射失败: %v\n", err)
	}

	// 创建锁文件
	lockPath := "dnspod.lock"
	if !CreateLockFile(lockPath) {
		return
	}

	defer RemoveLockFile(lockPath)

	try := func() {
		// 加载域名记录
		records, err := LoadDomainRecords("conf/domains.json")
		if err != nil {
			log.Printf("加载域名记录失败: %v\n", err)
			return
		}

		// 自动同步DNS记录
		err = dnsService.AutoSyncDNSRecords(records)
		if err != nil {
			log.Printf("自动同步DNS记录失败: %v\n", err)
			return
		}

		// 保存DNS记录ID映射
		err = dnsService.SaveDNSListMap()
		if err != nil {
			log.Printf("保存DNS记录ID映射失败: %v\n", err)
		}
	}

	// 执行同步操作并捕获异常
	func() {
		defer func() {
			if r := recover(); r != nil {
				log.Printf("程序发生异常: %v\n", r)
			}
		}()
		try()
	}()

	log.Println("DNS记录同步完成")
}
