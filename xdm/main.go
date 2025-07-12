package main

import (
	"log"
	"os"
	"os/exec"
	"path/filepath"
	"sync"
	"time"
)

// 程序运行状态
var (
	isRunning bool
	runMutex  sync.Mutex
)

// 需要运行的程序列表
var binaries = []string{
	"ssls",   // SSL证书管理程序
	"update", // 更新程序
	"upload", // 上传程序
}

func main() {
	// 设置日志格式
	log.SetFlags(log.LstdFlags | log.Lshortfile)
	log.Println("启动程序管理器...")

	// 获取当前工作目录
	workDir, err := os.Getwd()
	if err != nil {
		log.Fatalf("获取工作目录失败: %v", err)
	}

	// 设置初始运行状态
	isRunning = false

	// 创建定时器，每5分钟运行一次
	ticker := time.NewTicker(5 * time.Minute)
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

			// 依次运行每个程序
			for _, binary := range binaries {
				// 构建完整的文件路径
				filePath := filepath.Join(workDir, binary)

				// 检查文件是否存在
				if _, err := os.Stat(filePath); os.IsNotExist(err) {
					log.Printf("程序不存在: %s，跳过", binary)
					continue
				}

				log.Printf("开始运行程序: %s", binary)
				cmd := exec.Command(filePath)
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

			log.Println("所有程序运行完成，等待下一轮循环")
		}()

		// 等待下一个定时器触发
		<-ticker.C
	}
}
