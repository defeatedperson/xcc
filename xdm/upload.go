package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"mime/multipart"
	"net/http"
	"os"
	"path/filepath"
)

// 主配置结构
type MasterConfig struct {
	Master    string `json:"master"`
	MasterKey string `json:"master_key"`
}

// 响应结构
type Response struct {
	Code    int         `json:"code"`
	Message string      `json:"message"`
	Data    interface{} `json:"data,omitempty"`
}

func main() {
	// 设置日志格式
	log.SetFlags(log.LstdFlags | log.Lshortfile)
	log.Println("开始上传节点状态...")

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

	// 构建上传URL
	uploadURL := fmt.Sprintf("%s/data/xdm-upload.php", masterConfig.Master)

	// 读取bad-node.json文件
	badNodePath := filepath.Join(workDir, "dns", "conf", "bad-node.json")

	// 检查文件是否存在
	if _, err := os.Stat(badNodePath); os.IsNotExist(err) {
		// 文件不存在，创建一个空的JSON对象
		if err := os.MkdirAll(filepath.Dir(badNodePath), 0755); err != nil {
			log.Printf("创建目录失败: %v", err)
		}
		if err := os.WriteFile(badNodePath, []byte("{}"), 0644); err != nil {
			log.Printf("创建bad-node.json文件失败: %v", err)
		}
	}

	badNodeData, err := os.ReadFile(badNodePath)
	if err != nil {
		log.Printf("读取bad-node.json文件失败: %v", err)
		// 使用空的JSON对象继续
		badNodeData = []byte("{}")
	}

	// 检查文件内容是否为空
	if len(badNodeData) == 0 || string(badNodeData) == "" {
		badNodeData = []byte("{}")
	}

	// 解析JSON确保格式正确
	var badNodeJSON map[string]interface{}
	if err := json.Unmarshal(badNodeData, &badNodeJSON); err != nil {
		log.Printf("解析bad-node.json文件失败: %v，使用空JSON对象继续", err)
		badNodeJSON = make(map[string]interface{})
		badNodeData = []byte("{}")
	}

	// 上传节点状态
	if err := uploadNodeStatus(uploadURL, masterConfig.MasterKey, string(badNodeData)); err != nil {
		log.Fatalf("上传节点状态失败: %v", err)
	}

	log.Println("节点状态上传完成")
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

// 上传节点状态
func uploadNodeStatus(url, key, statusData string) error {
	// 创建一个buffer用于构建multipart/form-data请求
	var requestBody bytes.Buffer
	writer := multipart.NewWriter(&requestBody)

	// 添加master_key字段
	if err := writer.WriteField("master_key", key); err != nil {
		return fmt.Errorf("添加master_key字段失败: %w", err)
	}

	// 添加action字段
	if err := writer.WriteField("action", "update_status"); err != nil {
		return fmt.Errorf("添加action字段失败: %w", err)
	}

	// 添加status_data字段
	if err := writer.WriteField("status_data", statusData); err != nil {
		return fmt.Errorf("添加status_data字段失败: %w", err)
	}

	// 完成multipart/form-data的写入
	if err := writer.Close(); err != nil {
		return fmt.Errorf("完成multipart/form-data写入失败: %w", err)
	}

	// 创建HTTP请求
	req, err := http.NewRequest("POST", url, &requestBody)
	if err != nil {
		return fmt.Errorf("创建HTTP请求失败: %w", err)
	}

	// 设置Content-Type
	req.Header.Set("Content-Type", writer.FormDataContentType())

	// 发送请求
	client := &http.Client{}
	resp, err := client.Do(req)
	if err != nil {
		return fmt.Errorf("发送HTTP请求失败: %w", err)
	}
	defer resp.Body.Close()

	// 读取响应内容
	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return fmt.Errorf("读取响应内容失败: %w", err)
	}

	// 解析响应JSON
	var response Response
	if err := json.Unmarshal(body, &response); err != nil {
		return fmt.Errorf("解析响应JSON失败: %w, 响应内容: %s", err, string(body))
	}

	// 检查响应状态
	if response.Code != 0 {
		return fmt.Errorf("服务器返回错误: %s", response.Message)
	}

	log.Printf("服务器响应: %s", string(body))
	return nil
}
