#!/bin/bash
# 变量说明MASTER_ADDR是主控地址，MASTER_KEY是xdm的主控API密钥，NODE_KEY是xdm被控密钥，DOWNLOAD_URL是xcc压缩包下载地址。


# 颜色定义
RED="\033[0;31m"
GREEN="\033[0;32m"
YELLOW="\033[0;33m"
BLUE="\033[0;34m"
NC="\033[0m" # 恢复默认颜色

# 清屏并显示标题
clear
echo -e "${BLUE}==================================================${NC}"
echo -e "${BLUE}           XCC节点管理工具 - 功能选择           ${NC}"
echo -e "${BLUE}==================================================${NC}"

# 显示功能选项
echo -e "\n请选择要执行的功能："
echo -e "${GREEN}1. 安装XCC边缘节点${NC}"
echo -e "${GREEN}2. 更新XCC边缘节点${NC}"
echo -e "${GREEN}3. 安装XDM调度节点${NC}"
echo -e "${GREEN}4. 更新XDM调度节点${NC}"
echo -e "${GREEN}5. 安装或卸载XDM被控节点${NC}"
echo -e "${RED}0. 退出${NC}"

# 获取用户选择
read -p "请输入选项数字 [0-5]: " choice

# ===================== 功能执行区域 =====================
case $choice in
    1)
        echo -e "\n${YELLOW}您选择了: 安装XCC边缘节点${NC}"
        # 这里放置 xcc-node-template.php 的安装功能代码
        #!/bin/bash
        # 变量说明DOWNLOAD_URL是xcc压缩包下载地址，MASTER_ADDR是主控地址。

        echo "======================================================"
        echo "即将为您安装 xcc 被控节点环境。"
        echo "请确保当前系统为纯净的 Debian 11 或 12（64位），"
        echo "并且未占用 80、443、8080 端口。"
        echo "如继续安装，请输入 y 并回车，输入其他任意字符退出。"
        read -r CONFIRM
        if [[ "$CONFIRM" != "y" && "$CONFIRM" != "Y" ]]; then
            echo "已取消安装。"
            exit 0
        fi
        echo "======================================================"

        # 检查是否为 root 用户
        if [ "$(id -u)" -ne 0 ]; then
            echo "请以 root 用户运行此脚本。"
            exit 1
        fi

        # 检查系统架构
        ARCH=$(uname -m)
        if [[ "$ARCH" != "x86_64" ]]; then
            echo "仅支持 64 位系统 (x86_64)。"
            exit 1
        fi

        # 检查系统类型和版本
        if [ -f /etc/os-release ]; then
            . /etc/os-release
            if [[ "$ID" != "debian" ]]; then
                echo "仅支持 Debian 系统。"
                exit 1
            fi
            if [[ "$VERSION_ID" != "11" && "$VERSION_ID" != "12" ]]; then
                echo "仅支持 Debian 11 或 12。"
                exit 1
            fi
        else
            echo "无法检测系统类型，仅支持 Debian 11/12。"
            exit 1
        fi

        echo "系统检测通过：Debian $VERSION_ID 64位"

        # 创建 www 用户（如不存在）
        if id "www" &>/dev/null; then
            echo "用户 www 已存在。"
        else
            useradd -r -s /usr/sbin/nologin www
            echo "已创建 www 用户（系统用户，无登录权限）。"
        fi

        # 创建 /xcc 文件夹，仅 www 用户可读写和运行
        if [ ! -d /xcc ]; then
            mkdir /xcc
            chown www:www /xcc
            chmod 750 /xcc
            echo "已创建 /xcc 目录，并设置为 www 用户可读写和执行。"
        else
            chown www:www /xcc
            chmod 750 /xcc
            echo "/xcc 目录已存在，权限已设置为 www 用户可读写和执行。"
        fi

        # 检查 OpenResty 是否已安装
        NGINX_BIN="/usr/local/openresty/nginx/sbin/nginx"
        if [ -x "$NGINX_BIN" ] && command -v openresty &>/dev/null; then
            echo "检测到 OpenResty 已安装，跳过安装步骤。"
        else
            echo "开始安装 OpenResty ..."

            # 安装依赖
            apt-get update
            apt-get -y install --no-install-recommends wget gnupg ca-certificates

            # 导入 GPG 密钥
            if [[ "$VERSION_ID" == "11" ]]; then
                wget -O - https://openresty.org/package/pubkey.gpg | apt-key add -
            elif [[ "$VERSION_ID" == "12" ]]; then
                wget -O - https://openresty.org/package/pubkey.gpg | gpg --dearmor -o /etc/apt/trusted.gpg.d/openresty.gpg
            fi

            # 添加 OpenResty 官方 APT 源
            codename=$(grep -Po 'VERSION="[0-9]+ \(\K[^)]+' /etc/os-release)
            echo "deb http://openresty.org/package/debian $codename openresty" > /etc/apt/sources.list.d/openresty.list

            # 更新 APT 索引并安装 openresty
            apt-get -y install unzip
            apt-get update
            apt-get -y install openresty

            echo "OpenResty 安装完成。"
        fi

        # 检查 openresty/nginx 是否存在
        NGINX_BIN="/usr/local/openresty/nginx/sbin/nginx"
        if [ -x "$NGINX_BIN" ]; then
            echo "检测到 OpenResty nginx，尝试平滑重启..."
            $NGINX_BIN -t
            if [ $? -eq 0 ]; then
                $NGINX_BIN -s reload
                if [ $? -eq 0 ]; then
                    echo "OpenResty nginx 平滑重启成功。"
                    # 下载压缩包到 /xcc 目录
                    ZIP_URL="{{DOWNLOAD_URL}}"  # 请替换为实际下载地址
                    ZIP_PATH="/xcc/setup.zip"
                    echo "开始下载压缩包到 /xcc 目录..."
                    wget -O "$ZIP_PATH" "$ZIP_URL"
                    if [ $? -eq 0 ]; then
                        echo "压缩包下载完成：$ZIP_PATH"
                    else
                        echo "压缩包下载失败，请检查网络或下载地址。"
                        exit 1
                    fi
                else
                    echo "OpenResty nginx 平滑重启失败，请检查配置。"
                    exit 1
                fi
            else
                echo "nginx 配置检测失败，请检查配置文件。"
                exit 1
            fi
        else
            echo "未找到 OpenResty nginx 可执行文件，请确认安装是否成功。"
            exit 1
        fi



        # 解压 /xcc/setup.zip 到 /xcc 目录
        if [ -f /xcc/setup.zip ]; then
            echo "开始解压 /xcc/setup.zip 到 /xcc ..."
            unzip -o /xcc/setup.zip -d /xcc
            if [ $? -eq 0 ]; then
                echo "解压完成，删除压缩包。"
                rm -f /xcc/setup.zip
            else
                echo "解压失败，请检查压缩包内容。"
                exit 1
            fi
        else
            echo "/xcc/setup.zip 文件不存在，无法解压。"
            exit 1
        fi

        # 停止 nginx 服务
        echo "尝试停止 OpenResty nginx ..."
        NGINX_BIN="/usr/local/openresty/nginx/sbin/nginx"
        if [ -x "$NGINX_BIN" ]; then
            $NGINX_BIN -s stop
            if [ $? -eq 0 ]; then
                echo "OpenResty nginx 已停止。"
            else
                echo "停止 OpenResty nginx 失败，请检查进程状态。"
            fi
        else
            echo "未找到 OpenResty nginx 可执行文件，无法停止。"
        fi

        # 删除原有 nginx.conf 文件
        NGINX_CONF="/usr/local/openresty/nginx/conf/nginx.conf"
        if [ -f "$NGINX_CONF" ]; then
            rm -f "$NGINX_CONF"
            echo "已删除原有 nginx.conf 文件：$NGINX_CONF"
        else
            echo "未找到 nginx.conf 文件，无需删除。"
        fi

        # 移动 /xcc/ng 文件夹下的所有内容到 /usr/local/openresty/nginx/conf
        if [ -d /xcc/ng ]; then
            echo "正在移动 /xcc/ng 下的所有文件到 /usr/local/openresty/nginx/conf ..."
            mv -f /xcc/ng/* /usr/local/openresty/nginx/conf/
            if [ $? -eq 0 ]; then
                echo "文件移动完成。"
            else
                echo "文件移动失败，请检查权限或空间。"
                exit 1
            fi
        else
            echo "/xcc/ng 文件夹不存在，无法移动配置文件。"
            exit 1
        fi

        # 在 /usr/local/openresty/nginx 下创建 cache 文件夹，并设置权限为 www 用户
        CACHE_DIR="/usr/local/openresty/nginx/cache"
        if [ ! -d "$CACHE_DIR" ]; then
            mkdir "$CACHE_DIR"
            echo "已创建 $CACHE_DIR 目录。"
        else
            echo "$CACHE_DIR 目录已存在。"
        fi

        chown www:www "$CACHE_DIR"
        chmod 750 "$CACHE_DIR"
        echo "已将 $CACHE_DIR 权限设置为 www 用户读写执行。"

        # 在 /usr/local/openresty/nginx/logs 下创建 request.log 和 ban.log，并设置权限
        LOG_DIR="/usr/local/openresty/nginx/logs"
        REQUEST_LOG="$LOG_DIR/request.log"
        BAN_LOG="$LOG_DIR/ban.log"

        # 确保日志目录存在
        if [ ! -d "$LOG_DIR" ]; then
            mkdir -p "$LOG_DIR"
            echo "已创建 $LOG_DIR 目录。"
        fi

        # 创建日志文件并设置权限
        touch "$REQUEST_LOG" "$BAN_LOG"
        chown www:www "$REQUEST_LOG" "$BAN_LOG"
        chmod 640 "$REQUEST_LOG" "$BAN_LOG"
        echo "已创建 request.log 和 ban.log，并设置为 www 用户可读写。"

        # 删除 /usr/local/openresty/nginx/html 文件夹中的 50x.html 和 index.html
        HTML_DIR="/usr/local/openresty/nginx/html"
        if [ -d "$HTML_DIR" ]; then
            rm -f "$HTML_DIR/50x.html" "$HTML_DIR/index.html"
            echo "已删除 $HTML_DIR/50x.html 和 $HTML_DIR/index.html"
        else
            echo "$HTML_DIR 目录不存在，无需删除默认页面。"
        fi

        # 移动 /xcc/html 文件夹中的 index.html 和 50x.html 到 /usr/local/openresty/nginx/html
        HTML_SRC="/xcc/html"
        HTML_DST="/usr/local/openresty/nginx/html"
        if [ -d "$HTML_SRC" ]; then
            if [ -f "$HTML_SRC/index.html" ]; then
                mv -f "$HTML_SRC/index.html" "$HTML_DST/"
                echo "已移动 index.html 到 $HTML_DST/"
            fi
            if [ -f "$HTML_SRC/50x.html" ]; then
                mv -f "$HTML_SRC/50x.html" "$HTML_DST/"
                echo "已移动 50x.html 到 $HTML_DST/"
            fi
        else
            echo "$HTML_SRC 目录不存在，无法移动 index.html 和 50x.html。"
        fi

        # 递归设置 /usr/local/openresty 及其所有子文件、文件夹的属主为 www 用户
        chown -R www:www /usr/local/openresty
        echo "已将 /usr/local/openresty 及其所有子文件夹和文件的属主设置为 www 用户。"

        # 尝试启动 OpenResty nginx
        NGINX_BIN="/usr/local/openresty/nginx/sbin/nginx"
        echo "尝试启动 OpenResty nginx ..."
        $NGINX_BIN
        if [ $? -eq 0 ]; then
            echo "OpenResty nginx 启动成功。"
        else
            echo "OpenResty nginx 启动失败，正在输出错误信息："
            $NGINX_BIN -t
            exit 1
        fi

        # 检查 openssl 是否已安装，如未安装则自动安装
        if ! command -v openssl &>/dev/null; then
            echo "未检测到 openssl，正在安装..."
            apt-get update
            apt-get -y install openssl
            if ! command -v openssl &>/dev/null; then
                echo "openssl 安装失败，请手动检查。"
                exit 1
            fi
            echo "openssl 安装完成。"
        else
            echo "已检测到 openssl。"
        fi

        # 递归设置 /xcc 及其所有子文件、文件夹的属主为 www 用户
        chown -R www:www /xcc
        echo "已将 /xcc 及其所有子文件夹和文件的属主设置为 www 用户。"

        # 交互式获取节点通信IP（仅支持IPv4格式校验）
        while true; do
            echo "请输入本节点的通信IP地址（仅支持IPv4，用于主控与节点通信，此IP将写入自签SSL证书）："
            read -r NODE_IP
            # 简单IPv4正则校验
            if [[ "$NODE_IP" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; then
                # 检查每段是否在0-255
                VALID_IP=true
                IFS='.' read -ra ADDR <<< "$NODE_IP"
                for octet in "${ADDR[@]}"; do
                    if ((octet < 0 || octet > 255)); then
                        VALID_IP=false
                        break
                    fi
                done
                if $VALID_IP; then
                    break
                else
                    echo "IP地址每段必须在0-255之间，请重新输入。"
                fi
            else
                echo "IP地址格式不正确，请重新输入。"
            fi
        done
        echo "节点通信IP为：$NODE_IP"

        # 创建 /xcc/go/ssl 目录
        SSL_DIR="/xcc/go/ssl"
        mkdir -p "$SSL_DIR"

        # 生成自签名SSL证书（node.cert 和 node.key）
        openssl req -newkey rsa:2048 -nodes -keyout "$SSL_DIR/node.key" \
            -x509 -days 3650 -out "$SSL_DIR/node.cert" \
            -subj "/C=CN/ST=Node/L=Node/O=Node/CN=$NODE_IP" \
            -addext "subjectAltName=IP:$NODE_IP"

        # 设置证书权限为 www 用户读写执行
        chown www:www "$SSL_DIR/node.cert" "$SSL_DIR/node.key"
        chmod 750 "$SSL_DIR/node.cert" "$SSL_DIR/node.key"

        if [ $? -eq 0 ]; then
            echo "已为节点 $NODE_IP 生成自签名SSL证书："
            echo "  证书: $SSL_DIR/node.cert"
            echo "  密钥: $SSL_DIR/node.key"
        else
            echo "SSL证书生成失败，请检查 openssl 是否安装。"
            exit 1
        fi

        # 新增：直接赋值
        MASTER_ADDR="{{MASTER_ADDR}}"
        echo "主控地址已自动设置为：$MASTER_ADDR"

        while true; do
            echo "请输入节点ID（纯数字，5位数内）："
            read -r NODE_ID
            if [[ "$NODE_ID" =~ ^[0-9]{1,5}$ ]]; then
                break
            else
                echo "节点ID格式错误，必须为1-5位纯数字，请重新输入。"
            fi
        done

        while true; do
            echo "请输入节点密钥（16-32位）："
            read -r SECRET_KEY
            LEN=${#SECRET_KEY}
            if [[ $LEN -ge 16 && $LEN -le 32 ]]; then
                break
            else
                echo "节点密钥长度错误，必须为16-32位，请重新输入。"
            fi
        done

        # 生成 conf.json 文件
        CONF_DIR="/xcc/go/conf"
        mkdir -p "$CONF_DIR"
        CONF_FILE="$CONF_DIR/conf.json"
cat > "$CONF_FILE" <<EOF
{
    "node_id": "$NODE_ID",
    "secret_key": "$SECRET_KEY",
    "master_address": "$MASTER_ADDR"
}
EOF

        echo "节点配置已写入：$CONF_FILE"

        # 确保 go 程序可执行
        GO_BIN="/xcc/go/xccmain"
        chmod +x "$GO_BIN"

        # 启动 go 程序
        GO_BIN="/xcc/go/xccmain"
        if [ -x "$GO_BIN" ]; then
            echo "正在启动 go 节点程序 ..."
            nohup "$GO_BIN" > /xcc/go/xccmain.log 2>&1 &
            echo "go 节点程序已启动，日志输出到 /xcc/go/xccmain.log"
        else
            echo "未找到可执行的 go 程序：$GO_BIN"
            exit 1
        fi

        # 尝试启动或平滑重启 OpenResty nginx
        NGINX_BIN="/usr/local/openresty/nginx/sbin/nginx"

        # 先尝试平滑重启
        echo "尝试平滑重启 OpenResty nginx ..."
        $NGINX_BIN -s reload
        if [ $? -eq 0 ]; then
            echo "OpenResty nginx 平滑重启成功。"
        else
            # 平滑重启失败，尝试直接启动
            echo "平滑重启失败，尝试启动 OpenResty nginx ..."
            $NGINX_BIN
            if [ $? -eq 0 ]; then
                echo "OpenResty nginx 启动成功。"
            else
                echo "OpenResty nginx 启动失败，正在输出错误信息："
                $NGINX_BIN -t
                exit 1
            fi
        fi

        # 设置 systemd 服务实现 go 程序自启动
        SERVICE_FILE="/etc/systemd/system/xccmain.service"
cat > "$SERVICE_FILE" <<EOF
[Unit]
Description=XCC Node Go Service
After=network.target

[Service]
Type=simple
ExecStart=$GO_BIN
WorkingDirectory=/xcc/go
User=www
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF

        systemctl daemon-reload
        systemctl enable xccmain
        systemctl restart xccmain

        echo "go 节点程序已设置为 systemd 服务并开机自启（服务名：xccmain）"

        # 自动授权 www 用户可无密码执行 nginx 管理命令（仅限 reload/start/stop）
        SUDOERS_FILE="/etc/sudoers.d/xcc-nginx"
        NGINX_BIN="/usr/local/openresty/nginx/sbin/nginx"
        echo "www ALL=(root) NOPASSWD: $NGINX_BIN -s reload" > "$SUDOERS_FILE"
        echo "www ALL=(root) NOPASSWD: $NGINX_BIN -s stop"   >> "$SUDOERS_FILE"
        echo "www ALL=(root) NOPASSWD: $NGINX_BIN"          >> "$SUDOERS_FILE"
        chmod 440 "$SUDOERS_FILE"
        echo "已自动授权 www 用户可无密码执行 nginx reload/start/stop 命令（sudo）。"

        echo "======================================================"
        echo "✅ 节点安装与初始化已全部完成！"
        echo " "
        echo "1. OpenResty 已安装并启动，配置已替换。"
        echo "2. go 节点程序已启动，并已设置为 systemd 服务（xccmain），开机自启。"
        echo "3. 节点通信证书已生成，配置文件已写入。"
        echo "4. 所有相关目录和权限已设置。"
        echo " "
        echo "感谢使用，祝您节点运行顺利！"
        echo "======================================================"
        ;;
    2)
        echo -e "\n${YELLOW}您选择了: 更新XCC边缘节点${NC}"
        # 这里放置 xcc-update-template.php 的更新功能代码
        #!/bin/bash
        # 变量说明DOWNLOAD_URL是xcc压缩包下载地址。

        echo "======================================================"
        echo "即将为您更新 xcc 被控节点环境。"
        echo "⚠️ 警告：此更新将替换现有配置文件，更新后需要在主控重新下发配置。"
        echo "如继续更新，请输入 y 并回车，输入其他任意字符退出。"
        read -r CONFIRM
        if [[ "$CONFIRM" != "y" && "$CONFIRM" != "Y" ]]; then
            echo "已取消更新。"
            exit 0
        fi
        echo "======================================================"

        # 检查是否为 root 用户
        if [ "$(id -u)" -ne 0 ]; then
            echo "请以 root 用户运行此脚本。"
            exit 1
        fi

        # 检查 OpenResty 是否已安装
        NGINX_BIN="/usr/local/openresty/nginx/sbin/nginx"
        if [ ! -x "$NGINX_BIN" ] || ! command -v openresty &>/dev/null; then
            echo "未检测到 OpenResty 安装，请先运行完整安装脚本。"
            exit 1
        fi

        # 创建临时目录用于解压更新包
        TEMP_DIR="/xcc/update_temp_$(date +%Y%m%d%H%M%S)"
        echo "创建临时目录: $TEMP_DIR"
        mkdir -p "$TEMP_DIR"
        chown www:www "$TEMP_DIR"
        chmod 750 "$TEMP_DIR"

        # 下载压缩包到临时目录
        ZIP_URL="{{DOWNLOAD_URL}}"  # 请替换为实际下载地址
        ZIP_PATH="$TEMP_DIR/update.zip"
        echo "开始下载更新包到临时目录..."
        wget -O "$ZIP_PATH" "$ZIP_URL"
        if [ $? -ne 0 ]; then
            echo "更新包下载失败，请检查网络或下载地址。"
            rm -rf "$TEMP_DIR"
            exit 1
        fi
        echo "更新包下载完成：$ZIP_PATH"

        # 解压更新包到临时目录
        echo "开始解压更新包到临时目录..."
        unzip -o "$ZIP_PATH" -d "$TEMP_DIR"
        if [ $? -ne 0 ]; then
            echo "解压失败，请检查压缩包内容。"
            rm -rf "$TEMP_DIR"
            exit 1
        fi
        echo "解压完成，删除压缩包。"
        rm -f "$ZIP_PATH"

        # 检查解压后的文件结构
        if [ ! -d "$TEMP_DIR/ng" ]; then
            echo "更新包中未找到 ng 目录，无法继续更新。"
            rm -rf "$TEMP_DIR"
            exit 1
        fi

        # 停止 nginx 服务
        echo "尝试停止 OpenResty nginx ..."
        "$NGINX_BIN" -s stop
        sleep 2

        # 配置目录
        CONF_DIR="/usr/local/openresty/nginx/conf"

        # 移动临时目录中的 ng 文件夹下的所有内容到 OpenResty 配置目录
        echo "正在移动 $TEMP_DIR/ng 下的所有文件到 $CONF_DIR ..."
        cp -rf "$TEMP_DIR/ng"/* "$CONF_DIR"/
        if [ $? -ne 0 ]; then
            echo "文件移动失败，无法继续更新。"
            rm -rf "$TEMP_DIR"
            exit 1
        fi
        echo "配置文件移动完成。"

        # 设置 OpenResty 目录权限
        chown -R www:www "$CONF_DIR"

        # 尝试启动 OpenResty nginx
        echo "尝试启动 OpenResty nginx ..."
        "$NGINX_BIN" -t
        if [ $? -ne 0 ]; then
            echo "OpenResty nginx 配置测试失败，无法启动。"
            rm -rf "$TEMP_DIR"
            exit 1
        fi

        "$NGINX_BIN"
        if [ $? -ne 0 ]; then
            echo "OpenResty nginx 启动失败。"
            rm -rf "$TEMP_DIR"
            exit 1
        fi

        echo "OpenResty nginx 启动成功。"

        # 清理临时目录
        echo "清理临时目录..."
        rm -rf "$TEMP_DIR"
        echo "临时目录已清理。"

        echo "======================================================"
        echo "✅ 节点更新已完成！"
        echo " "
        echo "OpenResty 配置已更新并重启。"
        echo " "
        echo "⚠️ 重要提示：请在主控面板重新下发所有站点配置。"
        echo "======================================================"
        ;;
    3)
        echo -e "\n${YELLOW}您选择了: 安装XDM调度节点${NC}"
        # 这里放置 xdm-master-temple.php 的安装功能代码
        #!/bin/bash
        # 变量说明MASTER_ADDR是主控地址，MASTER_KEY是xdm的主控API密钥，NODE_KEY是xdm被控密钥。

        echo "======================================================"
        echo "即将为您安装 xdm 调度节点。"
        echo "请确保当前系统为纯净的 64位系统，"
        echo "有IPv4公网端口（例如Nat共享IP）"
        echo "如继续安装，请输入 y 并回车，输入其他任意字符退出。"
        read -r CONFIRM
        if [[ "$CONFIRM" != "y" && "$CONFIRM" != "Y" ]]; then
            echo "已取消安装。"
            exit 0
        fi
        echo "======================================================"

        # 检查是否为 root 用户
        if [ "$(id -u)" -ne 0 ]; then
            echo "请以 root 用户运行此脚本。"
            exit 1
        fi

        # 检查系统架构
        ARCH=$(uname -m)
        if [[ "$ARCH" != "x86_64" ]]; then
            echo "仅支持 64 位系统 (x86_64)。"
            exit 1
        fi

        echo "======================================================"

        # 检查Docker是否已安装
        if ! command -v docker &> /dev/null; then
            echo "检测到Docker未安装！"
            echo "======================================================"
            echo "您需要安装Docker才能继续。请使用以下命令安装Docker："
            echo ""
            echo "第三方脚本（推荐）："
            echo "bash <(curl -sSL https://linuxmirrors.cn/docker.sh)"
            echo "脚本官方网站：https://linuxmirrors.cn"
            echo ""
            echo "或者访问Docker官方文档：https://docs.docker.com/engine/install/"
            echo "根据您的系统选择合适的安装方法。"
            echo ""
            echo "安装完成后，请重新运行此脚本。"
            echo "======================================================"
            exit 1
        fi

        # 创建/xdm主目录（-p参数确保父目录不存在时自动创建）
        echo "开始创建/xdm目录..."
        if ! mkdir -p /xdm; then
            echo "错误：目录创建失败，请检查权限或路径是否合法" >&2
            exit 1
        fi

        # 验证目录创建结果
        echo "验证目录创建状态..."
        if [ -d "/xdm" ]; then
            echo "目录创建成功：/xdm 已存在" 
        else
            echo "错误：目录创建验证失败" >&2
            exit 1
        fi
        echo "======================================================"

        # 创建/xdm/conf目录（用于存放配置文件）
        echo "开始创建配置目录..."
        if ! mkdir -p /xdm/conf; then
            echo "错误：配置目录创建失败，请检查权限" >&2
            exit 1
        fi
        echo "配置目录创建成功：/xdm/conf"
        echo "======================================================"

        # 设置主控通信conf.json
        echo "正在设置主控通信配置..."
        echo "====================================================="

        # 使用PHP变量替换用户输入
        MASTER_ADDRESS="{{MASTER_ADDR}}"
        MASTER_KEY="{{MASTER_KEY}}"

        # 确保主控地址结尾没有斜杠
        MASTER_ADDRESS=${MASTER_ADDRESS%/}

        # 创建conf.json文件
        CONF_JSON_PATH="/xdm/conf.json"
        echo "{" > "$CONF_JSON_PATH"
        echo "  \"master\": \"$MASTER_ADDRESS\"," >> "$CONF_JSON_PATH"
        echo "  \"master_key\":\"$MASTER_KEY\"" >> "$CONF_JSON_PATH"
        echo "}" >> "$CONF_JSON_PATH"

        # 验证conf.json文件写入
        if [ -f "$CONF_JSON_PATH" ] && grep -q "$MASTER_ADDRESS" "$CONF_JSON_PATH"; then
            echo "conf.json配置文件写入成功，路径：$CONF_JSON_PATH"
        else
            echo "错误：conf.json配置文件写入失败" >&2
            exit 1
        fi
        echo "====================================================="


        echo "即将进行DNSPod设置，请根据提示输入以下信息："
        echo "密钥获取地址https://console.cloud.tencent.com/cam/capi"
        echo "====================================================="

        # 创建DNS配置目录
        if ! mkdir -p /xdm/dns/conf; then
            echo "错误：DNS配置目录创建失败，请检查权限" >&2
            exit 1
        fi

        # 使用PHP变量替换用户输入的被控监控程序通信密钥
        NODE_KEY="{{NODE_KEY}}"

        # 读取DNS操作的主域名
        read -p "请输入DNS操作的主域名(例如example.com，不能是www.example.com): " DOMAINS
        if [[ -z "$DOMAINS" || ! "$DOMAINS" =~ \. ]]; then
            echo "错误：域名格式无效（需包含点号且不能是二级域名）" >&2
            exit 1
        fi

        # 读取DNSPod API密钥ID
        read -p "请输入DNSPod API密钥ID(Secretid): " SECRET_ID
        if [[ -z "$SECRET_ID" ]]; then
            echo "错误：DNSPod API密钥ID不能为空" >&2
            exit 1
        fi

        # 读取DNSPod API密钥Key
        read -p "请输入DNSPod API密钥Key(SecretKey): " SECRET_KEY
        if [[ -z "$SECRET_KEY" ]]; then
            echo "错误：DNSPod API密钥Key不能为空" >&2
            exit 1
        fi

        # 读取监控间隔
        read -p "请输入监控间隔(true代表每分钟检测，false代表5分钟一次): " MONITOR_TIME
        if [[ "$MONITOR_TIME" != "true" && "$MONITOR_TIME" != "false" ]]; then
            echo "错误：监控间隔只能是true或false" >&2
            exit 1
        fi

        # 读取DNS解析TTL时间
        read -p "请输入DNS解析TTL时间(默认值600，推荐不修改，是否修改?[y/N]): " MODIFY_TTL
        TTL="600"
        if [[ "$MODIFY_TTL" == "y" || "$MODIFY_TTL" == "Y" ]]; then
            read -p "请输入新的TTL值: " NEW_TTL
            if [[ -n "$NEW_TTL" && "$NEW_TTL" =~ ^[0-9]+$ ]]; then
                TTL="$NEW_TTL"
            else
                echo "输入无效，使用默认值600"
            fi
        fi

        # 创建dns/conf/conf.json文件
        DNS_CONF_PATH="/xdm/dns/conf/conf.json"
        echo "{\"nodekey\":\"$NODE_KEY\"," > "$DNS_CONF_PATH"
        echo "\"domains\":\"$DOMAINS\"," >> "$DNS_CONF_PATH"
        echo "\"Secretid\":\"$SECRET_ID\"," >> "$DNS_CONF_PATH"
        echo "\"SecretKey\":\"$SECRET_KEY\"," >> "$DNS_CONF_PATH"
        echo "\"time\":\"$MONITOR_TIME\"," >> "$DNS_CONF_PATH"
        echo "\"TTL\":\"$TTL\"}" >> "$DNS_CONF_PATH"

        # 验证DNS配置文件写入
        if [ -f "$DNS_CONF_PATH" ] && grep -q "$NODE_KEY" "$DNS_CONF_PATH" && grep -q "$DOMAINS" "$DNS_CONF_PATH"; then
            echo "DNS配置文件写入成功，路径：$DNS_CONF_PATH"
        else
            echo "错误：DNS配置文件写入失败" >&2
            exit 1
        fi
        echo "======================================================"

        # 设置自动ssl申请邮箱
        echo "即将设置SSL证书申请邮箱，请根据提示输入以下信息："
        echo "====================================================="

        # 创建SSL配置目录
        if ! mkdir -p /xdm/ssl/conf; then
            echo "错误：SSL配置目录创建失败，请检查权限" >&2
            exit 1
        fi

        # 读取SSL证书申请邮箱
        read -p "请输入SSL证书申请邮箱(用于接收证书通知): " SSL_EMAIL
        if [[ -z "$SSL_EMAIL" || ! "$SSL_EMAIL" =~ @ ]]; then
            echo "错误：邮箱格式无效（需包含@符号）" >&2
            exit 1
        fi

        # 创建ssl/conf/conf.json文件
        SSL_CONF_PATH="/xdm/ssl/conf/conf.json"
        echo "{" > "$SSL_CONF_PATH"
        echo "  \"email\": \"$SSL_EMAIL\"" >> "$SSL_CONF_PATH"
        echo "}" >> "$SSL_CONF_PATH"

        # 验证SSL配置文件写入
        if [ -f "$SSL_CONF_PATH" ] && grep -q "$SSL_EMAIL" "$SSL_CONF_PATH"; then
            echo "SSL配置文件写入成功，路径：$SSL_CONF_PATH"
        else
            echo "错误：SSL配置文件写入失败" >&2
            exit 1
        fi

        echo "======================================================"

        # 拉取所需的Docker镜像
        echo "正在拉取所需的Docker镜像..."
        DOCKER_IMAGE="defeatedperson/xdm-app:latest"  # 使用变量存储镜像名称

        if ! docker pull "$DOCKER_IMAGE"; then
            echo "错误：拉取$DOCKER_IMAGE镜像失败" >&2
            echo "请检查网络连接或手动拉取镜像" >&2
            echo "您可以尝试手动执行: docker pull $DOCKER_IMAGE" >&2
            echo "如果持续失败，请联系管理员确认镜像名称是否正确" >&2
            exit 1  # 镜像拉取失败直接退出，因为后续步骤依赖此镜像
        else
            echo "镜像$DOCKER_IMAGE拉取成功！"
        fi

        # 运行Docker容器，并挂载必要的配置文件和目录
        echo "正在启动Docker容器..."
        if ! docker run -d \
            --name xdm \
            --restart always \
            -v /xdm/conf.json:/app/conf.json \
            -v /xdm/ssl/conf:/app/ssl/conf \
            -v /xdm/ssl/ssl:/app/ssl/ssl \
            -v /xdm/dns/conf:/app/dns/conf \
            -p 8020:8020 \
            "$DOCKER_IMAGE"; then
            echo "错误：Docker容器启动失败" >&2
            exit 1
        fi

        echo "====================================================="
        echo "xdm调度节点安装完成！"
        echo "Docker容器已成功启动，配置文件已持久化到相应目录"
        echo "可以通过以下命令查看容器状态："
        echo "docker ps | grep xdm"
        echo "可以通过以下命令查看容器日志："
        echo "docker logs xdm"
        echo "====================================================="
        echo "请确保在【主控】当中“扩展”页面设置的API密钥与刚才设置的“API主控密钥”相同！"
        echo "请在主控的“扩展页面”设置【XDM服务地址】为:http://当前服务器IP:8020"
        echo "例如：http://192.168.1.1:8020"
        echo "您可以使用NAT服务器。填写转发后的公网端口，能访问本机8020端口即可。"
        echo "====================================================="
        echo "感谢您使用本程序，祝您服务运行顺利！"
        echo "项目地址：https://github.com/defeatedperson/xcc"
        echo "官网：https://xcdream.com/xcc"
        echo "====================================================="
        echo "支持我们-好用的服务器/CDN/防护软件"
        echo "欢迎访问https://re.xcdream.com/links/qiafan"
        echo "====================================================================="
        echo "⚠️  警告：DNS解析冲突检查  ⚠️"
        echo "====================================================================="
        echo "请严格确保您的DNS配置不存在以下冲突："
        echo "  • DNSPod中添加的DNS解析记录与主控平台添加的解析记录不得重复"
        echo "  • 如果已在腾讯云控制台配置了解析记录，请勿在【主控】中添加相同内容"
        echo ""
        echo "【提示】DNS解析冲突可能导致系统工作异常，请仔细检查您的配置！"
        echo "====================================================================="
        ;;
    4)
        echo -e "\n${YELLOW}您选择了: 更新XDM调度节点${NC}"
        # 这里放置 xdm-update-temple.php 的更新功能代码
        #!/bin/bash
        # 变量说明MASTER_ADDR是主控地址，MASTER_KEY是xdm的主控API密钥，NODE_KEY是xdm被控密钥。

        echo "======================================================"
        echo "即将为您更新 xdm 调度节点。"
        echo "请选择更新类型："
        echo "1. 软件更新（配置不变，拉取最新版本docker镜像，更新容器）"
        echo "2. 配置更新（更新主控通信配置/DNSPod配置文件/自动ssl邮箱设置）"
        echo "3. 全部更新（同时更新软件和配置）"
        echo "请输入选项数字(1-3)并回车，输入其他任意字符退出。"
        read -r UPDATE_TYPE
        if [[ "$UPDATE_TYPE" != "1" && "$UPDATE_TYPE" != "2" && "$UPDATE_TYPE" != "3" ]]; then
            echo "已取消更新。"
            exit 0
        fi
        echo "======================================================"

        # 检查是否为 root 用户
        if [ "$(id -u)" -ne 0 ]; then
            echo "请以 root 用户运行此脚本。"
            exit 1
        fi

        # 检查系统架构
        ARCH=$(uname -m)
        if [[ "$ARCH" != "x86_64" ]]; then
            echo "仅支持 64 位系统 (x86_64)。"
            exit 1
        fi

        echo "======================================================"

        # 检查Docker是否已安装
        if ! command -v docker &> /dev/null; then
            echo "检测到Docker未安装！"
            echo "======================================================"
            echo "您需要安装Docker才能继续。请使用以下命令安装Docker："
            echo ""
            echo "第三方脚本（推荐）："
            echo "bash <(curl -sSL https://linuxmirrors.cn/docker.sh)"
            echo "脚本官方网站：https://linuxmirrors.cn"
            echo ""
            echo "或者访问Docker官方文档：https://docs.docker.com/engine/install/"
            echo "根据您的系统选择合适的安装方法。"
            echo ""
            echo "安装完成后，请重新运行此脚本。"
            echo "======================================================"
            exit 1
        fi

        # 检查xdm容器是否存在
        if ! docker ps -a | grep -q "xdm"; then
            echo "错误：未检测到xdm容器，请先运行安装脚本。"
            exit 1
        fi

        # 检查配置文件是否存在
        if [ ! -f "/xdm/conf.json" ]; then
            echo "错误：未找到主配置文件，请先运行安装脚本。"
            exit 1
        fi

        # 更新配置文件函数
        update_config() {
            echo "======================================================"
            echo "开始更新配置文件..."
            
            # 更新主控通信配置
            if [[ "$UPDATE_TYPE" == "2" || "$UPDATE_TYPE" == "3" ]]; then
                echo "正在更新主控通信配置..."
                echo "======================================================"
                
                # 使用PHP变量替换用户输入
                MASTER_ADDRESS="{{MASTER_ADDR}}"
                MASTER_KEY="{{MASTER_KEY}}"
                
                # 确保主控地址结尾没有斜杠
                MASTER_ADDRESS=${MASTER_ADDRESS%/}
                
                # 创建conf.json文件
                CONF_JSON_PATH="/xdm/conf.json"
                echo "{" > "$CONF_JSON_PATH"
                echo "  \"master\": \"$MASTER_ADDRESS\"," >> "$CONF_JSON_PATH"
                echo "  \"master_key\":\"$MASTER_KEY\"" >> "$CONF_JSON_PATH"
                echo "}" >> "$CONF_JSON_PATH"
                
                # 验证conf.json文件写入
                if [ -f "$CONF_JSON_PATH" ] && grep -q "$MASTER_ADDRESS" "$CONF_JSON_PATH"; then
                    echo "conf.json配置文件更新成功，路径：$CONF_JSON_PATH"
                else
                    echo "错误：conf.json配置文件更新失败" >&2
                    exit 1
                fi
                echo "======================================================"
                
                # 更新被控监控程序通信密钥
                NODE_KEY="{{NODE_KEY}}"
                
                # 检查DNS配置目录是否存在
                if [ ! -d "/xdm/dns/conf" ]; then
                    mkdir -p /xdm/dns/conf
                fi
                
                # 检查现有DNS配置文件
                DNS_CONF_PATH="/xdm/dns/conf/conf.json"
                if [ -f "$DNS_CONF_PATH" ]; then
                    # 读取现有配置
                    DOMAINS=$(grep -o '"domains":"[^"]*"' "$DNS_CONF_PATH" | cut -d '"' -f 4)
                    SECRET_ID=$(grep -o '"Secretid":"[^"]*"' "$DNS_CONF_PATH" | cut -d '"' -f 4)
                    SECRET_KEY=$(grep -o '"SecretKey":"[^"]*"' "$DNS_CONF_PATH" | cut -d '"' -f 4)
                    MONITOR_TIME=$(grep -o '"time":"[^"]*"' "$DNS_CONF_PATH" | cut -d '"' -f 4)
                    TTL=$(grep -o '"TTL":"[^"]*"' "$DNS_CONF_PATH" | cut -d '"' -f 4)
                    
                    echo "检测到现有DNS配置，是否需要修改？[y/N]"
                    read -r UPDATE_DNS
                    if [[ "$UPDATE_DNS" == "y" || "$UPDATE_DNS" == "Y" ]]; then
                        # 读取DNS操作的主域名
                        read -p "请输入DNS操作的主域名(例如example.com，不能是www.example.com) [当前值: $DOMAINS]: " NEW_DOMAINS
                        if [[ -n "$NEW_DOMAINS" ]]; then
                            if [[ ! "$NEW_DOMAINS" =~ \. ]]; then
                                echo "错误：域名格式无效（需包含点号且不能是二级域名）" >&2
                                exit 1
                            fi
                            DOMAINS="$NEW_DOMAINS"
                        fi
                        
                        # 读取DNSPod API密钥ID
                        read -p "请输入DNSPod API密钥ID(Secretid) [当前值: $SECRET_ID]: " NEW_SECRET_ID
                        if [[ -n "$NEW_SECRET_ID" ]]; then
                            SECRET_ID="$NEW_SECRET_ID"
                        fi
                        
                        # 读取DNSPod API密钥Key
                        read -p "请输入DNSPod API密钥Key(SecretKey) [当前值: $SECRET_KEY]: " NEW_SECRET_KEY
                        if [[ -n "$NEW_SECRET_KEY" ]]; then
                            SECRET_KEY="$NEW_SECRET_KEY"
                        fi
                        
                        # 读取监控间隔
                        read -p "请输入监控间隔(true代表每分钟检测，false代表5分钟一次) [当前值: $MONITOR_TIME]: " NEW_MONITOR_TIME
                        if [[ -n "$NEW_MONITOR_TIME" ]]; then
                            if [[ "$NEW_MONITOR_TIME" != "true" && "$NEW_MONITOR_TIME" != "false" ]]; then
                                echo "错误：监控间隔只能是true或false" >&2
                                exit 1
                            fi
                            MONITOR_TIME="$NEW_MONITOR_TIME"
                        fi
                        
                        # 读取DNS解析TTL时间
                        read -p "请输入DNS解析TTL时间 [当前值: $TTL]: " NEW_TTL
                        if [[ -n "$NEW_TTL" && "$NEW_TTL" =~ ^[0-9]+$ ]]; then
                            TTL="$NEW_TTL"
                        fi
                    fi
                else
                    # 如果不存在配置文件，则创建新的
                    echo "未检测到现有DNS配置，请输入新的配置信息："
                    
                    # 读取DNS操作的主域名
                    read -p "请输入DNS操作的主域名(例如example.com，不能是www.example.com): " DOMAINS
                    if [[ -z "$DOMAINS" || ! "$DOMAINS" =~ \. ]]; then
                        echo "错误：域名格式无效（需包含点号且不能是二级域名）" >&2
                        exit 1
                    fi
                    
                    # 读取DNSPod API密钥ID
                    read -p "请输入DNSPod API密钥ID(Secretid): " SECRET_ID
                    if [[ -z "$SECRET_ID" ]]; then
                        echo "错误：DNSPod API密钥ID不能为空" >&2
                        exit 1
                    fi
                    
                    # 读取DNSPod API密钥Key
                    read -p "请输入DNSPod API密钥Key(SecretKey): " SECRET_KEY
                    if [[ -z "$SECRET_KEY" ]]; then
                        echo "错误：DNSPod API密钥Key不能为空" >&2
                        exit 1
                    fi
                    
                    # 读取监控间隔
                    read -p "请输入监控间隔(true代表每分钟检测，false代表5分钟一次): " MONITOR_TIME
                    if [[ "$MONITOR_TIME" != "true" && "$MONITOR_TIME" != "false" ]]; then
                        echo "错误：监控间隔只能是true或false" >&2
                        exit 1
                    fi
                    
                    # 读取DNS解析TTL时间
                    read -p "请输入DNS解析TTL时间(默认值600，推荐不修改，是否修改?[y/N]): " MODIFY_TTL
                    TTL="600"
                    if [[ "$MODIFY_TTL" == "y" || "$MODIFY_TTL" == "Y" ]]; then
                        read -p "请输入新的TTL值: " NEW_TTL
                        if [[ -n "$NEW_TTL" && "$NEW_TTL" =~ ^[0-9]+$ ]]; then
                            TTL="$NEW_TTL"
                        else
                            echo "输入无效，使用默认值600"
                        fi
                    fi
                fi
                
                # 创建dns/conf/conf.json文件
                echo "{\"nodekey\":\"$NODE_KEY\"," > "$DNS_CONF_PATH"
                echo "\"domains\":\"$DOMAINS\"," >> "$DNS_CONF_PATH"
                echo "\"Secretid\":\"$SECRET_ID\"," >> "$DNS_CONF_PATH"
                echo "\"SecretKey\":\"$SECRET_KEY\"," >> "$DNS_CONF_PATH"
                echo "\"time\":\"$MONITOR_TIME\"," >> "$DNS_CONF_PATH"
                echo "\"TTL\":\"$TTL\"}" >> "$DNS_CONF_PATH"
                
                # 验证DNS配置文件写入
                if [ -f "$DNS_CONF_PATH" ] && grep -q "$NODE_KEY" "$DNS_CONF_PATH" && grep -q "$DOMAINS" "$DNS_CONF_PATH"; then
                    echo "DNS配置文件更新成功，路径：$DNS_CONF_PATH"
                else
                    echo "错误：DNS配置文件更新失败" >&2
                    exit 1
                fi
                echo "======================================================"
                
                # 更新SSL邮箱设置
                # 检查SSL配置目录是否存在
                if [ ! -d "/xdm/ssl/conf" ]; then
                    mkdir -p /xdm/ssl/conf
                fi
                
                # 检查现有SSL配置文件
                SSL_CONF_PATH="/xdm/ssl/conf/conf.json"
                if [ -f "$SSL_CONF_PATH" ]; then
                    # 读取现有配置
                    SSL_EMAIL=$(grep -o '"email": "[^"]*"' "$SSL_CONF_PATH" | cut -d '"' -f 4)
                    
                    echo "检测到现有SSL邮箱配置，是否需要修改？[y/N]"
                    read -r UPDATE_SSL
                    if [[ "$UPDATE_SSL" == "y" || "$UPDATE_SSL" == "Y" ]]; then
                        # 读取SSL证书申请邮箱
                        read -p "请输入SSL证书申请邮箱(用于接收证书通知) [当前值: $SSL_EMAIL]: " NEW_SSL_EMAIL
                        if [[ -n "$NEW_SSL_EMAIL" ]]; then
                            if [[ ! "$NEW_SSL_EMAIL" =~ @ ]]; then
                                echo "错误：邮箱格式无效（需包含@符号）" >&2
                                exit 1
                            fi
                            SSL_EMAIL="$NEW_SSL_EMAIL"
                        fi
                    fi
                else
                    # 如果不存在配置文件，则创建新的
                    echo "未检测到现有SSL邮箱配置，请输入新的配置信息："
                    
                    # 读取SSL证书申请邮箱
                    read -p "请输入SSL证书申请邮箱(用于接收证书通知): " SSL_EMAIL
                    if [[ -z "$SSL_EMAIL" || ! "$SSL_EMAIL" =~ @ ]]; then
                        echo "错误：邮箱格式无效（需包含@符号）" >&2
                        exit 1
                    fi
                fi
                
                # 创建ssl/conf/conf.json文件
                echo "{" > "$SSL_CONF_PATH"
                echo "  \"email\": \"$SSL_EMAIL\"" >> "$SSL_CONF_PATH"
                echo "}" >> "$SSL_CONF_PATH"
                
                # 验证SSL配置文件写入
                if [ -f "$SSL_CONF_PATH" ] && grep -q "$SSL_EMAIL" "$SSL_CONF_PATH"; then
                    echo "SSL配置文件更新成功，路径：$SSL_CONF_PATH"
                else
                    echo "错误：SSL配置文件更新失败" >&2
                    exit 1
                fi
            fi
            
            echo "配置文件更新完成！"
            echo "======================================================"
        }

        # 更新软件函数
        update_software() {
            echo "======================================================"
            echo "开始更新软件..."
            
            # 拉取最新的Docker镜像
            echo "正在拉取最新的Docker镜像..."
            DOCKER_IMAGE="defeatedperson/xdm-app:latest"  # 使用变量存储镜像名称
            
            if ! docker pull "$DOCKER_IMAGE"; then
                echo "错误：拉取$DOCKER_IMAGE镜像失败" >&2
                echo "请检查网络连接或手动拉取镜像" >&2
                echo "您可以尝试手动执行: docker pull $DOCKER_IMAGE" >&2
                echo "如果持续失败，请联系管理员确认镜像名称是否正确" >&2
                exit 1
            else
                echo "镜像$DOCKER_IMAGE拉取成功！"
            fi
            
            # 停止并删除旧容器
            echo "正在停止并删除旧容器..."
            if ! docker stop xdm; then
                echo "警告：停止xdm容器失败，可能容器已经停止" >&2
            fi
            
            if ! docker rm xdm; then
                echo "警告：删除xdm容器失败，可能容器已经被删除" >&2
            fi
            
            # 使用最新镜像启动新容器
            echo "正在使用最新镜像启动新容器..."
            if ! docker run -d \
                --name xdm \
                --restart always \
                -v /xdm/conf.json:/app/conf.json \
                -v /xdm/ssl/conf:/app/ssl/conf \
                -v /xdm/ssl/ssl:/app/ssl/ssl \
                -v /xdm/dns/conf:/app/dns/conf \
                -p 8020:8020 \
                "$DOCKER_IMAGE"; then
                echo "错误：Docker容器启动失败" >&2
                exit 1
            fi
            
            echo "软件更新完成！"
            echo "======================================================"
        }

        # 根据用户选择执行相应的更新操作
        if [[ "$UPDATE_TYPE" == "1" || "$UPDATE_TYPE" == "3" ]]; then
            update_software
        fi

        if [[ "$UPDATE_TYPE" == "2" || "$UPDATE_TYPE" == "3" ]]; then
            update_config
        fi

        # 如果同时更新了软件和配置，需要重启容器以应用新配置
        if [[ "$UPDATE_TYPE" == "3" ]]; then
            echo "正在重启容器以应用新配置..."
            if ! docker restart xdm; then
                echo "警告：重启xdm容器失败" >&2
                exit 1
            fi
        fi

        echo "===================================================="
        echo "xdm调度节点更新完成！"
        echo "可以通过以下命令查看容器状态："
        echo "docker ps | grep xdm"
        echo "可以通过以下命令查看容器日志："
        echo "docker logs xdm"
        echo "===================================================="
        echo "请确保在【主控】当中"扩展"页面设置的API密钥与更新后的"API主控密钥"相同！"
        echo "请在主控的"扩展页面"设置【XDM服务地址】为:http://当前服务器IP:8020"
        echo "例如：http://192.168.1.1:8020"
        echo "您可以使用NAT服务器。填写转发后的公网端口，能访问本机8020端口即可。"
        echo "===================================================="
        echo "感谢您使用本程序，祝您服务运行顺利！"
        echo "项目地址：https://github.com/defeatedperson/xcc"
        echo "官网：https://xcdream.com/xcc"
        echo "===================================================="
        echo "支持我们-好用的服务器/CDN/防护软件"
        echo "欢迎访问https://re.xcdream.com/links/qiafan"
        echo "====================================================================="
        echo "⚠️  警告：DNS解析冲突检查  ⚠️"
        echo "====================================================================="
        echo "请严格确保您的DNS配置不存在以下冲突："
        echo "  • DNSPod中添加的DNS解析记录与主控平台添加的解析记录不得重复"
        echo "  • 如果已在腾讯云控制台配置了解析记录，请勿在【主控】中添加相同内容"
        echo ""
        echo "【提示】DNS解析冲突可能导致系统工作异常，请仔细检查您的配置！"
        echo "====================================================================="
        ;;
    5)
        echo -e "\n${YELLOW}您选择了: 安装或卸载XDM被控节点${NC}"
        # 这里放置 xdm-node-template.php 的安装和卸载功能代码
        #!/bin/bash
        # 变量说明DOWNLOAD_URL是xcc压缩包下载地址，NODE_KEY是xdm被控密钥。

        # 显示菜单选项
        echo "======================================================"
        echo "XDM-Node 被控节点管理脚本"
        echo "======================================================"
        echo "请选择操作："
        echo "1. 安装 XDM-Node 被控节点"
        echo "2. 卸载 XDM-Node 被控节点"
        echo "请输入选项 (1 或 2)："
        read -r OPTION

        # 检查是否为 root 用户
        if [ "$(id -u)" -ne 0 ]; then
            echo "请以 root 用户运行此脚本。"
            exit 1
        fi

        # 卸载功能
        uninstall_xdmnode() {
            echo "====================================================="
            echo "即将卸载 XDM-Node 被控节点..."
            echo "此操作将："
            echo "1. 停止并禁用 xdmnode 服务"
            echo "2. 删除服务文件"
            echo "3. 删除 /xdmnode 目录及其所有内容"
            echo "请确认是否继续卸载？(y/n)"
            read -r CONFIRM
            
            if [[ "$CONFIRM" != "y" && "$CONFIRM" != "Y" ]]; then
                echo "已取消卸载。"
                exit 0
            fi
            
            # 停止并禁用服务
            echo "正在停止 xdmnode 服务..."
            systemctl stop xdmnode.service 2>/dev/null
            systemctl disable xdmnode.service 2>/dev/null
            
            # 删除服务文件
            echo "正在删除服务文件..."
            rm -f /etc/systemd/system/xdmnode.service
            systemctl daemon-reload
            
            # 删除程序目录
            echo "正在删除程序目录..."
            rm -rf /xdmnode
            
            echo "====================================================="
            echo "XDM-Node 被控节点已成功卸载！"
            echo "====================================================="
            exit 0
        }

        # 根据选项执行相应操作
        if [ "$OPTION" = "2" ]; then
            uninstall_xdmnode
        fi

        # 以下是原有的安装代码
        echo "====================================================="
        echo "即将为您安装 xdm-node 被控节点环境。"
        echo "请确保未占用8081 端口。"
        echo "如继续安装，请输入 y 并回车，输入其他任意字符退出。"
        read -r CONFIRM
        if [[ "$CONFIRM" != "y" && "$CONFIRM" != "Y" ]]; then
            echo "已取消安装。"
            exit 0
        fi
        echo "====================================================="

        # 检查系统架构
        ARCH=$(uname -m)
        if [[ "$ARCH" != "x86_64" ]]; then
            echo "仅支持 64 位系统 (x86_64)。"
            exit 1
        fi

        # 创建 www 用户（如不存在）
        if id "www" &>/dev/null; then
            echo "用户 www 已存在。"
        else
            useradd -r -s /usr/sbin/nologin www
            echo "已创建 www 用户（系统用户，无登录权限）。"
        fi

        # 创建 /xdmnode 文件夹，仅 www 用户可读写和运行
        if [ ! -d /xdmnode ]; then
            mkdir /xdmnode
            chown www:www /xdmnode
            chmod 750 /xdmnode
            echo "已创建 /xdmnode 目录，并设置为 www 用户可读写和执行。"
        else
            chown www:www /xdmnode
            chmod 750 /xdmnode
            echo "/xdmnode 目录已存在，权限已设置为 www 用户可读写和执行。"
        fi

        # 下载压缩包到 /xdmnode 目录
            ZIP_URL="{{DOWNLOAD_URL}}"  # 请替换为实际下载地址
            ZIP_PATH="/xdmnode/setup.zip"
            echo "开始下载压缩包到 /xdmnode 目录..."
            wget -O "$ZIP_PATH" "$ZIP_URL"
            if [ $? -eq 0 ]; then
                echo "压缩包下载完成：$ZIP_PATH"
            else
                echo "压缩包下载失败，请检查网络或下载地址。"
                exit 1
            fi

        # 解压 /xdmnode/setup.zip 到 /xdmnode 目录
        if [ -f /xdmnode/setup.zip ]; then
            echo "开始解压 /xdmnode/setup.zip 到 /xdmnode ..."
            unzip -o /xdmnode/setup.zip -d /xdmnode
            if [ $? -eq 0 ]; then
                echo "解压完成，删除压缩包。"
                rm -f /xdmnode/setup.zip
            else
                echo "解压失败，请检查压缩包内容。"
                exit 1
            fi
        else
            echo "/xdmnode/setup.zip 文件不存在，无法解压。"
            exit 1
        fi

        # 检查 openssl 是否已安装，如未安装则自动安装
        if ! command -v openssl &>/dev/null; then
            echo "未检测到 openssl，正在安装..."
            apt-get update
            apt-get -y install openssl
            if ! command -v openssl &>/dev/null; then
                echo "openssl 安装失败，请手动检查。"
                exit 1
            fi
            echo "openssl 安装完成。"
        else
            echo "已检测到 openssl。"
        fi

        # 交互式设置服务器IP地址并生成SSL证书
        echo "====================================================="
        echo "请输入本服务器的IP地址（用于生成SSL证书）："
        read -r SERVER_IP

        # 验证IP地址格式
        if [[ ! $SERVER_IP =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
            echo "IP地址格式不正确，请重新运行脚本。"
            exit 1
        fi

        echo "您输入的IP地址是: $SERVER_IP"
        echo "正在为此IP地址生成自签名SSL证书..."

        # 创建SSL证书目录
        SSL_DIR="/xdmnode/xdm/ssl"
        if [ ! -d "$SSL_DIR" ]; then
            mkdir -p "$SSL_DIR"
        fi

        # 生成自签名SSL证书
        openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
            -keyout "$SSL_DIR/node.key" \
            -out "$SSL_DIR/node.pem" \
            -subj "/CN=$SERVER_IP" \
            -addext "subjectAltName=IP:$SERVER_IP"

        # 设置证书权限
        chown www:www "$SSL_DIR/node.key" "$SSL_DIR/node.pem"
        chmod 640 "$SSL_DIR/node.key" "$SSL_DIR/node.pem"

        echo "SSL证书已生成："
        echo "- 证书文件: $SSL_DIR/node.pem"
        echo "- 密钥文件: $SSL_DIR/node.key"
        echo "====================================================="

        # 交互式设置 node.conf 配置文件
        echo "====================================================="
        echo "现在开始设置 node.conf 配置文件"

        # 创建配置文件目录
        CONF_DIR="/xdmnode/xdm"
        if [ ! -d "$CONF_DIR" ]; then
            mkdir -p "$CONF_DIR"
        fi

        COMM_KEY="{{NODE_KEY}}"  # 使用变量替换，不再需要交互式输入

        # 设置总带宽
        echo "请输入服务器总带宽（单位：Mbps，例如：1000）："
        read -r TOTAL_BW

        # 验证总带宽是否为数字
        if ! [[ "$TOTAL_BW" =~ ^[0-9]+$ ]]; then
            echo "总带宽必须是数字，将使用默认值 1000 Mbps"
            TOTAL_BW=1000
        fi

        # 创建 node.conf 文件
cat > "$CONF_DIR/node.conf" << EOF
[Security]
; 通信密钥（用于节点与主控服务器身份验证，需保密）
communication_key = $COMM_KEY

[Network]
; 服务器总带宽（单位：Mbps，示例为${TOTAL_BW}Mbps）
total_bandwidth = $TOTAL_BW
EOF

        # 设置配置文件权限
        chown www:www "$CONF_DIR/node.conf"
        chmod 640 "$CONF_DIR/node.conf"

        echo "node.conf 配置文件已创建：$CONF_DIR/node.conf"
        echo "- 通信密钥: $COMM_KEY"
        echo "- 总带宽: $TOTAL_BW Mbps"
        echo "====================================================="

        # 设置Go程序的开机自启动
        echo "====================================================="
        echo "正在设置Go程序开机自启动..."

        # 检查程序文件是否存在
        PROGRAM_FILE="/xdmnode/xdm/xdmnode"

        if [ ! -f "$PROGRAM_FILE" ]; then
            echo "错误：未找到程序文件 $PROGRAM_FILE"
            echo "请确保编译后的Go程序文件已放置在正确位置。"
            exit 1
        fi

        # 设置程序文件权限
        chown www:www "$PROGRAM_FILE"
        chmod 750 "$PROGRAM_FILE"

        echo "已设置 $PROGRAM_FILE 的适当权限。"

        # 创建systemd服务实现开机自启动
        echo "正在创建systemd服务以实现开机自启动..."

        # 创建服务文件
cat > /etc/systemd/system/xdmnode.service << EOF
[Unit]
描述=XDM Node Service
After=network.target

[Service]
类型=simple
User=www
Group=www
WorkingDirectory=/xdmnode/xdm
ExecStart=/xdmnode/xdm/xdmnode
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

        # 重新加载systemd配置
        systemctl daemon-reload

        # 启用并启动服务
        systemctl enable xdmnode.service
        systemctl start xdmnode.service

        # 检查服务状态
        echo "服务状态："
        systemctl status xdmnode.service --no-pager

        echo "====================================================="
        echo "XDM Node 安装完成！"
        echo "程序已设置为开机自启动，当前已启动运行。"
        echo "您可以使用以下命令管理服务："
        echo "- 查看状态：systemctl status xdmnode.service"
        echo "- 启动服务：systemctl start xdmnode.service"
        echo "- 停止服务：systemctl stop xdmnode.service"
        echo "- 重启服务：systemctl restart xdmnode.service"
        echo "====================================================="
        ;;
    0)
        echo -e "\n${BLUE}感谢使用，再见！${NC}"
        exit 0
        ;;
    *)
        echo -e "\n${RED}无效的选择，请重新运行脚本。${NC}"
        exit 1
        ;;
esac
