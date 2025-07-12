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

// Config 主配置结构
type Config struct {
	Master    string `json:"master"`
	MasterKey string `json:"master_key"`
}

// DomainsDayData 每日域名记录结构
type DomainsDayData struct {
	Domains []string `json:"domains"`
}

const (
	// 配置文件路径
	ConfigPath     = "conf.json"
	DomainsDayPath = "ssl/conf/domains-day.json"
	CertDir        = "ssl/ssl"
)

// 上传证书到主控服务器
func uploadCertificate(masterURL, apiKey, domain, certPath, keyPath string) error {
	// 创建一个multipart writer
	body := &bytes.Buffer{}
	writer := multipart.NewWriter(body)

	// 添加API密钥
	if err := writer.WriteField("apikey", apiKey); err != nil {
		return fmt.Errorf("写入API密钥失败: %v", err)
	}

	// 添加域名
	if err := writer.WriteField("domain", domain); err != nil {
		return fmt.Errorf("写入域名失败: %v", err)
	}

	// 添加证书文件
	certFile, err := os.Open(certPath)
	if err != nil {
		return fmt.Errorf("无法打开证书文件: %v", err)
	}
	defer certFile.Close()

	certPart, err := writer.CreateFormFile("cert", filepath.Base(certPath))
	if err != nil {
		return fmt.Errorf("创建证书表单字段失败: %v", err)
	}
	if _, err := io.Copy(certPart, certFile); err != nil {
		return fmt.Errorf("复制证书文件失败: %v", err)
	}

	// 添加密钥文件
	keyFile, err := os.Open(keyPath)
	if err != nil {
		return fmt.Errorf("无法打开密钥文件: %v", err)
	}
	defer keyFile.Close()

	keyPart, err := writer.CreateFormFile("key", filepath.Base(keyPath))
	if err != nil {
		return fmt.Errorf("创建密钥表单字段失败: %v", err)
	}
	if _, err := io.Copy(keyPart, keyFile); err != nil {
		return fmt.Errorf("复制密钥文件失败: %v", err)
	}

	// 完成multipart写入
	if err := writer.Close(); err != nil {
		return fmt.Errorf("完成表单写入失败: %v", err)
	}

	// 创建HTTP请求
	req, err := http.NewRequest("POST", masterURL+"/xdm-ssl.php", body)
	if err != nil {
		return fmt.Errorf("创建HTTP请求失败: %v", err)
	}

	// 设置Content-Type
	req.Header.Set("Content-Type", writer.FormDataContentType())

	// 发送请求
	client := &http.Client{}
	resp, err := client.Do(req)
	if err != nil {
		return fmt.Errorf("发送HTTP请求失败: %v", err)
	}
	defer resp.Body.Close()

	// 读取响应
	respBody, err := io.ReadAll(resp.Body)
	if err != nil {
		return fmt.Errorf("读取响应失败: %v", err)
	}

	// 解析响应
	var result struct {
		Success bool   `json:"success"`
		Message string `json:"message"`
	}
	if err := json.Unmarshal(respBody, &result); err != nil {
		return fmt.Errorf("解析响应失败: %v", err)
	}

	// 检查上传结果
	if !result.Success {
		return fmt.Errorf("上传失败: %s", result.Message)
	}

	log.Printf("域名 %s 的证书上传成功", domain)
	return nil
}

// 清空每日域名记录
func clearDomainsDayData() error {
	// 创建空的域名记录
	domainsDayData := DomainsDayData{
		Domains: []string{},
	}

	// 序列化为JSON
	data, err := json.MarshalIndent(domainsDayData, "", "  ")
	if err != nil {
		return fmt.Errorf("序列化域名记录失败: %v", err)
	}

	// 保存到文件
	if err := os.WriteFile(DomainsDayPath, data, 0644); err != nil {
		return fmt.Errorf("保存域名记录失败: %v", err)
	}

	log.Println("每日域名记录已清空")
	return nil
}

func main() {
	// 读取主配置
	configData, err := os.ReadFile(ConfigPath)
	if err != nil {
		log.Fatalf("无法读取主配置文件: %v", err)
	}

	var config Config
	if err := json.Unmarshal(configData, &config); err != nil {
		log.Fatalf("无法解析主配置文件: %v", err)
	}

	// 读取每日域名记录
	domainsDayData, err := os.ReadFile(DomainsDayPath)
	if err != nil {
		if os.IsNotExist(err) {
			log.Println("每日域名记录文件不存在，无需上传证书")
			return
		}
		log.Fatalf("无法读取每日域名记录文件: %v", err)
	}

	var domainsDay DomainsDayData
	if err := json.Unmarshal(domainsDayData, &domainsDay); err != nil {
		log.Fatalf("无法解析每日域名记录文件: %v", err)
	}

	// 如果没有需要上传的域名，直接返回
	if len(domainsDay.Domains) == 0 {
		log.Println("没有需要上传的证书")
		return
	}

	// 上传每个域名的证书
	for _, domain := range domainsDay.Domains {
		// 构建证书和密钥文件路径
		certPath := filepath.Join(CertDir, domain+".crt")
		keyPath := filepath.Join(CertDir, domain+".key")

		// 检查证书文件是否存在
		if _, err := os.Stat(certPath); os.IsNotExist(err) {
			log.Printf("域名 %s 的证书文件不存在，跳过上传", domain)
			continue
		}

		// 检查密钥文件是否存在
		if _, err := os.Stat(keyPath); os.IsNotExist(err) {
			log.Printf("域名 %s 的密钥文件不存在，跳过上传", domain)
			continue
		}

		// 上传证书
		if err := uploadCertificate(config.Master, config.MasterKey, domain, certPath, keyPath); err != nil {
			log.Printf("上传域名 %s 的证书失败: %v", domain, err)
			continue
		}
	}

	// 清空每日域名记录
	if err := clearDomainsDayData(); err != nil {
		log.Printf("清空每日域名记录失败: %v", err)
	}
}
