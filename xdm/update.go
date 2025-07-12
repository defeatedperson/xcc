package main

import (
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http" // 新增
	"net/url"  // 添加这一行
	"os"
	"path/filepath"
	"strings" // 新增
)

// 主配置结构
type MasterConfig struct {
	Master    string `json:"master"`
	MasterKey string `json:"master_key"`
}

// DNS域名记录结构
type DNSRecord struct {
	Host        string `json:"host"`
	MainType    string `json:"main_type"`
	MainValue   string `json:"main_value"`
	BackupType  string `json:"backup_type"`
	BackupValue string `json:"backup_value"`
	Line        string `json:"line"`
	JkType      string `json:"jk_type"`
	Status      bool   `json:"status"`
}

// DNS配置结构
type DNSConfig map[string]DNSRecord

// SSL配置结构
type SSLConfig struct {
	Domains map[string]string `json:"domains"`
}

// 更新响应结构
type UpdateResponse struct {
	DNS struct {
		Domains map[string]DNSRecord `json:"domains"`
	} `json:"dns"`
	SSL struct {
		Domains interface{} `json:"domains"` // 改为interface{}类型以适应不同的返回格式
	} `json:"ssl"`
}

func main() {
	// 设置日志格式
	log.SetFlags(log.LstdFlags | log.Lshortfile)
	log.Println("开始更新配置...")

	// 获取工作目录
	workDir, err := os.Getwd()
	if err != nil {
		log.Fatalf("获取工作目录失败: %v", err)
	}

	// 加载主配置
	masterConfigPath := filepath.Join(workDir, "conf.json")
	masterConfig, err := loadMasterConfig(masterConfigPath)
	if err != nil {
		log.Fatalf("加载主配置失败: %v", err)
	}

	// 构建更新URL
	updateURL := fmt.Sprintf("%s/data/xdm-update.php", masterConfig.Master)

	// 从服务器获取更新数据
	updateData, err := fetchUpdateData(updateURL, masterConfig.MasterKey)
	if err != nil {
		log.Fatalf("获取更新数据失败: %v", err)
	}

	// 更新DNS配置
	dnsConfigPath := filepath.Join(workDir, "dns", "conf", "domains.json")
	if err := updateDNSConfig(dnsConfigPath, updateData.DNS.Domains); err != nil {
		log.Printf("更新DNS配置失败: %v", err)
	} else {
		log.Println("DNS配置更新成功")
	}

	// 更新SSL配置
	sslConfigPath := filepath.Join(workDir, "ssl", "conf", "domains.json")

	// 检查domains的类型并转换
	domainsMap := make(map[string]string)
	switch domains := updateData.SSL.Domains.(type) {
	case map[string]interface{}:
		// 如果是对象，转换为map[string]string
		for k, v := range domains {
			if strVal, ok := v.(string); ok {
				domainsMap[k] = strVal
			}
		}
	case []interface{}:
		// 如果是数组，创建一个空的map
		// 数组为空时不需要处理
	default:
		// 其他情况，使用空map
	}

	if err := updateSSLConfig(sslConfigPath, domainsMap); err != nil {
		log.Printf("更新SSL配置失败: %v", err)
	} else {
		log.Println("SSL配置更新成功")
	}

	log.Println("配置更新完成")
}

// 加载主配置
func loadMasterConfig(configPath string) (*MasterConfig, error) {
	data, err := os.ReadFile(configPath)
	if err != nil {
		return nil, fmt.Errorf("读取主配置文件失败: %w", err)
	}

	var config MasterConfig
	if err := json.Unmarshal(data, &config); err != nil {
		return nil, fmt.Errorf("解析主配置文件失败: %w", err)
	}

	return &config, nil
}

// 从服务器获取更新数据
func fetchUpdateData(serverURL, key string) (*UpdateResponse, error) {
	// 创建POST请求，而不是GET
	formData := url.Values{}
	formData.Set("master_key", key)

	req, err := http.NewRequest("POST", serverURL, strings.NewReader(formData.Encode()))
	if err != nil {
		return nil, fmt.Errorf("创建HTTP请求失败: %w", err)
	}

	// 设置Content-Type为application/x-www-form-urlencoded
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")

	// 发送请求
	client := &http.Client{}
	resp, err := client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("发送HTTP请求失败: %w", err)
	}
	defer resp.Body.Close()

	// 检查响应状态
	if resp.StatusCode != http.StatusOK {
		// 读取错误响应内容以便调试
		body, _ := io.ReadAll(resp.Body)
		return nil, fmt.Errorf("服务器返回错误状态码: %d, 响应内容: %s", resp.StatusCode, string(body))
	}

	// 读取响应内容
	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, fmt.Errorf("读取响应内容失败: %w", err)
	}

	// 打印响应内容以便调试
	log.Printf("服务器响应: %s", string(body))

	// 解析响应JSON
	var updateResp UpdateResponse
	if err := json.Unmarshal(body, &updateResp); err != nil {
		return nil, fmt.Errorf("解析响应JSON失败: %w, 响应内容: %s", err, string(body))
	}

	return &updateResp, nil
}

// 更新DNS配置
func updateDNSConfig(configPath string, newDomains map[string]DNSRecord) error {
	// 读取现有配置
	var currentConfig DNSConfig
	data, err := os.ReadFile(configPath)
	if err != nil && !os.IsNotExist(err) {
		return fmt.Errorf("读取DNS配置文件失败: %w", err)
	} else if err == nil {
		// 尝试解析JSON
		if len(data) == 0 || string(data) == "{" {
			// 文件为空或只有一个开括号，创建空配置
			currentConfig = make(DNSConfig)
		} else {
			if err := json.Unmarshal(data, &currentConfig); err != nil {
				// 解析失败，创建空配置
				log.Printf("解析现有DNS配置失败，将创建新配置: %v", err)
				currentConfig = make(DNSConfig)
			}
		}
	} else {
		// 如果文件不存在，创建空配置
		currentConfig = make(DNSConfig)
	}

	// 合并配置，保留本地的status值
	for id, newRecord := range newDomains {
		if currentRecord, exists := currentConfig[id]; exists {
			// 保留本地的status值
			newRecord.Status = currentRecord.Status
		}
		currentConfig[id] = newRecord
	}

	// 保存更新后的配置
	updatedData, err := json.MarshalIndent(currentConfig, "", "    ")
	if err != nil {
		return fmt.Errorf("序列化DNS配置失败: %w", err)
	}

	// 确保目录存在
	dir := filepath.Dir(configPath)
	if err := os.MkdirAll(dir, 0755); err != nil {
		return fmt.Errorf("创建目录失败: %w", err)
	}

	// 写入文件
	if err := os.WriteFile(configPath, updatedData, 0644); err != nil {
		return fmt.Errorf("写入DNS配置文件失败: %w", err)
	}

	return nil
}

// 更新SSL配置
func updateSSLConfig(configPath string, newDomains map[string]string) error {
	// 创建新的SSL配置
	newConfig := SSLConfig{
		Domains: newDomains,
	}

	// 序列化配置
	updatedData, err := json.MarshalIndent(newConfig, "", "  ")
	if err != nil {
		return fmt.Errorf("序列化SSL配置失败: %w", err)
	}

	// 确保目录存在
	dir := filepath.Dir(configPath)
	if err := os.MkdirAll(dir, 0755); err != nil {
		return fmt.Errorf("创建目录失败: %w", err)
	}

	// 写入文件
	if err := os.WriteFile(configPath, updatedData, 0644); err != nil {
		return fmt.Errorf("写入SSL配置文件失败: %w", err)
	}

	return nil
}
