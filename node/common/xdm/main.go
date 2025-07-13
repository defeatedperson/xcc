package main

import (
	"encoding/json"
	"fmt"
	"io" // 替换 io/ioutil
	"log"
	"net/http"
	"os"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"time"

	"github.com/shirou/gopsutil/v3/cpu"
	"github.com/shirou/gopsutil/v3/mem"
	"github.com/shirou/gopsutil/v3/net"
	"gopkg.in/ini.v1"
)

// 配置结构体
type Config struct {
	CommunicationKey string
	TotalBandwidth   float64 // Mbps
	SSLCertPath      string
	SSLKeyPath       string
	NetIfaces        []string // 新增：需要统计的网卡名列表
}

// 请求结构体
type Request struct {
	Key string `json:"key"`
}

// 响应结构体
type Response struct {
	Success bool   `json:"success"`
	Message string `json:"message"`
	Status  bool   `json:"status,omitempty"`
}

// 全局配置
var config Config

// 初始化配置
func initConfig() error {
	// 获取当前执行文件所在目录
	execPath, err := os.Executable()
	if err != nil {
		return fmt.Errorf("获取执行路径失败: %v", err)
	}

	execDir := filepath.Dir(execPath)

	// 配置文件路径
	configPath := filepath.Join(execDir, "node.conf")

	// 检查配置文件是否存在
	if _, err := os.Stat(configPath); os.IsNotExist(err) {
		return fmt.Errorf("配置文件不存在: %s", configPath)
	}

	// 加载配置文件
	cfg, err := ini.Load(configPath)
	if err != nil {
		return fmt.Errorf("加载配置文件失败: %v", err)
	}

	// 读取配置项
	config.CommunicationKey = cfg.Section("Security").Key("communication_key").String()
	totalBandwidthStr := cfg.Section("Network").Key("total_bandwidth").String()

	// 读取网卡白名单
	ifacesStr := cfg.Section("Network").Key("ifaces").String()
	if ifacesStr != "" {
		config.NetIfaces = []string{}
		for _, n := range strings.Split(ifacesStr, ",") {
			config.NetIfaces = append(config.NetIfaces, strings.TrimSpace(n))
		}
	}

	// 检查必要配置项
	if config.CommunicationKey == "" {
		return fmt.Errorf("配置文件缺少通信密钥配置")
	}

	// 解析总带宽
	totalBandwidth, err := strconv.ParseFloat(totalBandwidthStr, 64)
	if err != nil || totalBandwidth <= 0 {
		return fmt.Errorf("总带宽配置无效: %s", totalBandwidthStr)
	}
	config.TotalBandwidth = totalBandwidth

	// 设置SSL证书路径
	config.SSLCertPath = filepath.Join(execDir, "ssl", "node.pem")
	config.SSLKeyPath = filepath.Join(execDir, "ssl", "node.key")

	// 检查SSL证书文件
	if _, err := os.Stat(config.SSLCertPath); os.IsNotExist(err) {
		return fmt.Errorf("SSL证书文件不存在: %s", config.SSLCertPath)
	}

	if _, err := os.Stat(config.SSLKeyPath); os.IsNotExist(err) {
		return fmt.Errorf("SSL密钥文件不存在: %s", config.SSLKeyPath)
	}

	return nil
}

// 验证密钥 - 直接比较，不再加密
func validateKey(inputKey string) bool {
	// 直接比较输入密钥与配置中的密钥
	return inputKey == config.CommunicationKey
}

// 获取CPU使用率
func getCPUUsage() (float64, error) {
	percentages, err := cpu.Percent(time.Second, false)
	if err != nil {
		return 0, err
	}

	if len(percentages) == 0 {
		return 0, fmt.Errorf("无法获取CPU使用率")
	}

	return percentages[0], nil
}

// 获取内存使用率
func getMemoryUsage() (float64, error) {
	memInfo, err := mem.VirtualMemory()
	if err != nil {
		return 0, err
	}

	return memInfo.UsedPercent, nil
}

// 判断是否为虚拟网卡
func isVirtualIface(name string) bool {
	virtualPrefixes := []string{"lo", "docker", "br-", "veth", "virbr", "vmnet", "vboxnet"}
	for _, prefix := range virtualPrefixes {
		if len(name) >= len(prefix) && name[:len(prefix)] == prefix {
			return true
		}
	}
	return false
}

// 获取带宽使用率（多网卡适配）
func getBandwidthUsage() (float64, error) {
	netIOBefore, err := net.IOCounters(true)
	if err != nil {
		return 0, err
	}
	time.Sleep(time.Second)
	netIOAfter, err := net.IOCounters(true)
	if err != nil {
		return 0, err
	}

	var totalBytes uint64
	for _, before := range netIOBefore {
		name := before.Name
		// 判断是否统计该网卡
		shouldCount := false
		if len(config.NetIfaces) > 0 {
			for _, n := range config.NetIfaces {
				if n == name {
					shouldCount = true
					break
				}
			}
		} else {
			if !isVirtualIface(name) {
				shouldCount = true
			}
		}
		if !shouldCount {
			continue
		}
		// 找到对应的after
		for _, after := range netIOAfter {
			if after.Name == name {
				bytesRecv := after.BytesRecv - before.BytesRecv
				bytesSent := after.BytesSent - before.BytesSent
				totalBytes += bytesRecv + bytesSent
				break
			}
		}
	}

	// 字节/秒转为 Mbps：1 Mbps = 125000 字节/秒
	currentBandwidthMbps := float64(totalBytes) / 125000
	if config.TotalBandwidth <= 0 {
		return 0, fmt.Errorf("总带宽配置无效")
	}
	bandwidthUsage := (currentBandwidthMbps / config.TotalBandwidth) * 100
	return bandwidthUsage, nil
}

// 检查服务器状态
func checkServerStatus() (bool, map[string]float64, error) {
	// 获取CPU使用率
	cpuUsage, err := getCPUUsage()
	if err != nil {
		return false, nil, err
	}

	// 获取内存使用率
	memoryUsage, err := getMemoryUsage()
	if err != nil {
		return false, nil, err
	}

	// 获取带宽使用率
	bandwidthUsage, err := getBandwidthUsage()
	if err != nil {
		return false, nil, err
	}

	// 记录各项指标
	metrics := map[string]float64{
		"cpu":       cpuUsage,
		"memory":    memoryUsage,
		"bandwidth": bandwidthUsage,
	}

	// 判断服务器状态
	serverOK := true

	// CPU使用率超过95%
	if cpuUsage > 95 {
		serverOK = false
	}

	// 内存使用率超过95%
	if memoryUsage > 95 {
		serverOK = false
	}

	// 带宽使用率超过80%
	if bandwidthUsage > 80 {
		serverOK = false
	}

	return serverOK, metrics, nil
}

// 处理状态检查请求
func handleStatusCheck(w http.ResponseWriter, r *http.Request) {
	// 设置响应头
	w.Header().Set("Content-Type", "application/json")

	// 只允许POST请求
	if r.Method != http.MethodPost {
		w.WriteHeader(http.StatusMethodNotAllowed)
		json.NewEncoder(w).Encode(Response{
			Success: false,
			Message: "只允许POST请求",
		})
		return
	}

	// 读取请求体 - 使用 io.ReadAll 替代 ioutil.ReadAll
	body, err := io.ReadAll(r.Body)
	if err != nil {
		w.WriteHeader(http.StatusBadRequest)
		json.NewEncoder(w).Encode(Response{
			Success: false,
			Message: "读取请求体失败",
		})
		return
	}
	defer r.Body.Close()

	// 解析请求
	var req Request
	if err := json.Unmarshal(body, &req); err != nil {
		w.WriteHeader(http.StatusBadRequest)
		json.NewEncoder(w).Encode(Response{
			Success: false,
			Message: "请求格式错误",
		})
		return
	}

	// 验证密钥
	if !validateKey(req.Key) {
		w.WriteHeader(http.StatusUnauthorized)
		json.NewEncoder(w).Encode(Response{
			Success: false,
			Message: "通信密钥验证失败",
		})
		return
	}

	// 检查服务器状态
	status, metrics, err := checkServerStatus()
	if err != nil {
		w.WriteHeader(http.StatusInternalServerError)
		json.NewEncoder(w).Encode(Response{
			Success: false,
			Message: fmt.Sprintf("检查服务器状态失败: %v", err),
		})
		return
	}

	// 构建响应消息
	message := fmt.Sprintf("服务器状态: %s (CPU: %.2f%%, 内存: %.2f%%, 带宽: %.2f%%)",
		map[bool]string{true: "正常", false: "异常"}[status],
		metrics["cpu"], metrics["memory"], metrics["bandwidth"])

	// 返回响应
	w.WriteHeader(http.StatusOK)
	json.NewEncoder(w).Encode(Response{
		Success: true,
		Message: message,
		Status:  status,
	})
}

func main() {
	// 设置日志格式
	log.SetFlags(log.Ldate | log.Ltime | log.Lshortfile)

	// 初始化配置
	if err := initConfig(); err != nil {
		log.Fatalf("初始化配置失败: %v", err)
	}

	// 输出系统信息
	log.Printf("系统信息: %s, %s, %d核心CPU",
		runtime.GOOS, runtime.GOARCH, runtime.NumCPU())

	// 注册处理函数
	http.HandleFunc("/status", handleStatusCheck)

	// 启动HTTPS服务器
	serverAddr := ":8081"
	log.Printf("启动HTTPS服务器，监听端口: %s", serverAddr)

	// 使用HTTPS服务
	err := http.ListenAndServeTLS(serverAddr, config.SSLCertPath, config.SSLKeyPath, nil)
	if err != nil {
		log.Fatalf("启动HTTPS服务器失败: %v", err)
	}
}
