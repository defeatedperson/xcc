#!/bin/bash

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

# 创建 www 用户（如不存在）
if id "www" &>/dev/null; then
    echo "用户 www 已存在。"
else
    useradd -r -s /usr/sbin/nologin www
    echo "已创建 www 用户（系统用户，无登录权限）。"
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
echo "如需查看 go 节点日志："
echo "  tail -f /xcc/go/xccmain.log"
echo "如需管理 go 节点服务："
echo "  systemctl status xccmain"
echo "  systemctl restart xccmain"
echo "如需管理 OpenResty："
echo "  systemctl restart openresty"
echo " "
echo "感谢使用，祝您节点运行顺利！"
echo "======================================================"