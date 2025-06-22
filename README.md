# 星尘CC防御系统(xcc)
开源、分布式、CDN加速、高性能CC防御软件

## 🌟 项目简介
XCC（星尘CC防御）是一款专注于应对DDoS/CC攻击的开源防护软件，基于分布式多节点架构设计，支持CDN加速，为中小站点提供轻量级、高性价比的流量防护能力。
拥抱极简PHP主控解决方案，可直接部署在原站服务器，无需占用独立80/443端口。

## 🌟 页面展示
[![监控页面](https://raw.githubusercontent.com/defeatedperson/xcc/refs/heads/main/img/1.jpeg "监控页面")](https://raw.githubusercontent.com/defeatedperson/xcc/refs/heads/main/img/1.jpeg "监控页面")
[![域名管理](https://raw.githubusercontent.com/defeatedperson/xcc/refs/heads/main/img/2.jpg "域名管理")](https://raw.githubusercontent.com/defeatedperson/xcc/refs/heads/main/img/2.jpg "域名管理")
[![节点管理](https://raw.githubusercontent.com/defeatedperson/xcc/refs/heads/main/img/3.jpg "节点管理")](https://raw.githubusercontent.com/defeatedperson/xcc/refs/heads/main/img/3.jpg "节点管理")
## ✨ 核心功能
- **分布式防护**：通过多节点协同部署，分散攻击流量压力
- **CDN加速**：支持缓存设置，提升网站加载速度
- **智能识别**：基于行为分析测机制，中英日三语言验证支持
- **可视化管理**：提供Web控制台，支持攻击日志查询、防护策略配置
- **PHP主控**：可部署在原站或任意php8+环境当中（需支持exec函数+sqlite）

## ✨ 模块说明
- **主控**：php语言开发+sqlite存储+json存储
- **节点控制**：go语言开发+json存储
- **节点反向代理**：基于OpenResty
- **安全防护**：lua脚本

## 🚀 快速开始
### 环境要求
- PHP 8+（管理端）
- Debian11/12（节点端）
- PHP支持exec函数（宝塔面板需手动移除禁止函数）

### 配置要求
- 主控无要求，能跑php就行
- 被控节点配置＞1核心+1G内存
（512m也行，请手动减少nginx.conf当中的连接数，默认5120）
- 被控需要独立占用80/443/8080端口

### 安装步骤
下载最新版本：[发布页面](https://github.com/defeatedperson/xcc/releases/tag/v0.0.2 "发布页面")

## 📖 使用文档
完善中，稍安勿躁

## 🤝 贡献指南
我们欢迎社区贡献！参与方式包括：
- 提交Issue反馈bug或需求
- 提交Pull Request修复代码或添加新功能
- 完善文档/翻译多语言版本

## 📜 许可证
本项目采用[Apache 2.0许可证](https://github.com/defeatedperson/xcc/blob/v0.0.2/LICENSE)，允许商业使用、修改和分发，但需保留原版权声明。

## 💬 联系我们
- 官方网站https://xcdream.com/
- 商务合作：发送邮件至dp712@qq.com
