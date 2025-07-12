package main

import (
	"encoding/json"
	"fmt"
	"log"
	"os"
	"os/exec"
	"path/filepath"
	"sync"
	"time"
)

// 配置结构体
type Config struct {
	Nodekey   string `json:"nodekey"`
	Domains   string `json:"domains"`
	Secretid  string `json:"Secretid"`
	SecretKey string `json:"SecretKey"`
	Time      string `json:"time"`
	TTL       string `json:"TTL"`
}

// 程序运行状态
var (
	isRunning bool
	runMutex  sync.Mutex
)

func main() {
	// 设置日志格式
	log.SetFlags(log.LstdFlags | log.Lshortfile)
	log.Println("启动DNS管理程序...")

	// 获取当前工作目录
	workDir, err := os.Getwd()
	if err != nil {
		log.Fatalf("获取工作目录失败: %v", err)
	}

	// 配置文件路径
	configPath := filepath.Join(workDir, "conf", "conf.json")

	// 二进制程序列表 - 这里需要替换为实际的二进制程序路径（Linux环境）
	binaries := []string{
		filepath.Join(workDir, "dnspod"),  // Linux路径，无.exe后缀
		filepath.Join(workDir, "ping"),    // Linux路径，无.exe后缀
		filepath.Join(workDir, "monitor"), // Linux路径，无.exe后缀
	}

	// 设置初始运行状态
	isRunning = false

	// 创建定时器
	ticker := time.NewTicker(60 * time.Second) // 默认60秒检查一次
	defer ticker.Stop()

	for {
		// 检查是否有程序正在运行
		runMutex.Lock()
		if isRunning {
			log.Println("上一轮程序仍在运行中，跳过本次循环")
			runMutex.Unlock()
			<-ticker.C // 等待下一个定时器触发
			continue
		}

		// 标记为正在运行
		isRunning = true
		runMutex.Unlock()

		// 在新的goroutine中运行程序
		go func() {
			defer func() {
				runMutex.Lock()
				isRunning = false
				runMutex.Unlock()
			}()

			// 读取配置文件
			config, err := loadConfig(configPath)
			if err != nil {
				log.Printf("读取配置文件失败: %v，将在下次循环重试", err)
				return
			}

			// 根据time值设置间隔时间
			intervalSeconds := 300 // 默认为300秒
			if config.Time == "true" {
				intervalSeconds = 60
			}

			// 更新定时器间隔
			ticker.Reset(time.Duration(intervalSeconds) * time.Second)
			log.Printf("配置的循环间隔时间为: %d秒", intervalSeconds)

			// 依次运行每个二进制程序
			for _, binary := range binaries {
				if _, err := os.Stat(binary); os.IsNotExist(err) {
					log.Printf("二进制程序不存在: %s，跳过", binary)
					continue
				}

				log.Printf("开始运行程序: %s", binary)
				cmd := exec.Command(binary)
				cmd.Stdout = os.Stdout
				cmd.Stderr = os.Stderr

				// 运行程序并等待其完成
				err := cmd.Run()
				if err != nil {
					log.Printf("程序运行失败: %s, 错误: %v", binary, err)
				} else {
					log.Printf("程序运行完成: %s", binary)
				}
			}

			log.Printf("所有程序运行完成，将在%d秒后开始下一轮循环", intervalSeconds)
		}()

		// 等待下一个定时器触发
		<-ticker.C
	}
}

// 加载配置文件
func loadConfig(configPath string) (*Config, error) {
	// 读取配置文件
	data, err := os.ReadFile(configPath)
	if err != nil {
		return nil, fmt.Errorf("读取配置文件失败: %v", err)
	}

	// 解析JSON
	var config Config
	err = json.Unmarshal(data, &config)
	if err != nil {
		return nil, fmt.Errorf("解析配置文件失败: %v", err)
	}

	return &config, nil
}
