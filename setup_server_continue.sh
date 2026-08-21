#!/usr/bin/env bash
# =============================================================================
#  setup_server_continue.sh — продолжение развёртывания со шага 11
#  (используется, если основной setup_server.sh прервался после шага 10)
#  Запуск: sudo bash setup_server_continue.sh
# =============================================================================
set -uo pipefail

GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'
info() { echo -e "${GREEN}[OK]${NC} $*"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $*"; }
step() { echo -e "\n${CYAN}=====> $*${NC}"; }

PROJECT_DIR="${SETUP_PROJECT_DIR:-/var/www/crmdtlv4}"
RUN_USER="${SUDO_USER:-$(id -un)}"
if [ "$RUN_USER" = "root" ]; then
    for cand in mdtl_admin admin deployer; do
        if getent passwd "$cand" >/dev/null 2>&1; then RUN_USER="$cand"; break; fi
    done
fi
RUN_HOME="$(getent passwd "$RUN_USER" | cut -d: -f6 2>/dev/null || echo "/home/${RUN_USER}")"
PROJECT_OWNER="${SETUP_PROJECT_OWNER:-$RUN_USER}"

run_as_owner() {
    if [ "$(id -u)" -eq 0 ] && [ "$PROJECT_OWNER" != "root" ]; then
        sudo -u "$PROJECT_OWNER" env HOME="$RUN_HOME" "$@"
    else
        "$@"
    fi
}

# -----------------------------------------------------------------------------
# Шаг 11. opencode (некритично)
# -----------------------------------------------------------------------------
step "11/12 Установка и настройка opencode"
if ! command -v opencode >/dev/null 2>&1 && [ ! -x "${RUN_HOME}/.opencode/bin/opencode" ]; then
    if [ "$(id -u)" -eq 0 ] && [ "$RUN_USER" != "root" ]; then
        sudo -u "$RUN_USER" env HOME="$RUN_HOME" bash -c 'curl -fsSL https://opencode.ai/install | bash' \
            || warn "opencode не установился — доустановите позже: curl -fsSL https://opencode.ai/install | bash"
    else
        curl -fsSL https://opencode.ai/install | bash \
            || warn "opencode не установился — доустановите позже."
    fi
fi
if [ -x "${RUN_HOME}/.opencode/bin/opencode" ]; then
    export PATH="${RUN_HOME}/.opencode/bin:${PATH}"
    if [ -f "${RUN_HOME}/.bashrc" ]; then
        grep -q '.opencode/bin' "${RUN_HOME}/.bashrc" 2>/dev/null || \
            echo 'export PATH="$HOME/.opencode/bin:$PATH"' >> "${RUN_HOME}/.bashrc"
    fi
    info "opencode установлен: ${RUN_HOME}/.opencode/bin/opencode"
fi

if [ ! -f "${RUN_HOME}/.config/opencode/opencode.json" ]; then
    if [ "$(id -u)" -eq 0 ] && [ "$RUN_USER" != "root" ]; then
        sudo -u "$RUN_USER" mkdir -p "${RUN_HOME}/.config/opencode"
    else
        mkdir -p "${RUN_HOME}/.config/opencode"
    fi
    cat > /tmp/opencode-config.json <<JSON
{
  "\$schema": "https://opencode.ai/config.json",
  "plugin": ["opencode-gemini-auth@latest"],
  "provider": {
    "google": {
      "api": "gemini",
      "options": {
        "projectId": "gen-lang-client-0581067356"
      }
    }
  },
  "mcp": {
    "filesystem": {
      "type": "local",
      "command": ["npx", "-y", "@modelcontextprotocol/server-filesystem", "${PROJECT_DIR}"]
    }
  }
}
JSON
    install -o "$RUN_USER" -g "$RUN_USER" -m 600 /tmp/opencode-config.json "${RUN_HOME}/.config/opencode/opencode.json"
    rm -f /tmp/opencode-config.json
    info "Конфиг opencode создан."
fi

# -----------------------------------------------------------------------------
# Шаг 12. systemd-сервисы + Nginx
# -----------------------------------------------------------------------------
step "12/12 Настройка systemd-сервисов: Horizon, Reverb, Nginx"
cd "$PROJECT_DIR"

if [ ! -f /etc/systemd/system/crm-horizon.service ]; then
    sudo tee /etc/systemd/system/crm-horizon.service > /dev/null <<SYSTEMD
[Unit]
Description=CRM Horizon queue worker
After=network.target redis-server.service
[Service]
User=${PROJECT_OWNER}
Group=${PROJECT_OWNER}
WorkingDirectory=${PROJECT_DIR}
ExecStart=/usr/bin/php ${PROJECT_DIR}/artisan horizon
Restart=on-failure
RestartSec=5
[Install]
WantedBy=multi-user.target
SYSTEMD
fi

if [ ! -f /etc/systemd/system/crm-reverb.service ]; then
    sudo tee /etc/systemd/system/crm-reverb.service > /dev/null <<SYSTEMD
[Unit]
Description=CRM Reverb WebSocket server
After=network.target redis-server.service
[Service]
User=${PROJECT_OWNER}
Group=${PROJECT_OWNER}
WorkingDirectory=${PROJECT_DIR}
ExecStart=/usr/bin/php ${PROJECT_DIR}/artisan reverb:start --host=0.0.0.0 --port=8080
Restart=on-failure
RestartSec=5
[Install]
WantedBy=multi-user.target
SYSTEMD
fi

sudo systemctl daemon-reload
sudo systemctl enable --now crm-horizon.service 2>/dev/null || warn "Horizon не запустился — проверьте журнал: journalctl -u crm-horizon -e"
sudo systemctl enable --now crm-reverb.service 2>/dev/null || warn "Reverb не запустился — проверьте журнал: journalctl -u crm-reverb -e"
info "systemd-сервисы: crm-horizon, crm-reverb."

# Nginx vhost по IP
IP_ADDRESS="${SETUP_IP_ADDRESS:-78.17.19.218}"
DOMAIN="${SETUP_DOMAIN:-}"
PHP_VERSION="8.4"
NGINX_AVAILABLE=""
if [ -n "$DOMAIN" ]; then
    NGINX_AVAILABLE="/etc/nginx/sites-available/${DOMAIN}"
    NGINX_SERVER_NAME="    server_name ${DOMAIN} *.${DOMAIN};"
elif [ -n "$IP_ADDRESS" ]; then
    NGINX_AVAILABLE="/etc/nginx/sites-available/crmdtlv4-ip"
    NGINX_SERVER_NAME="    server_name ${IP_ADDRESS};"
fi

if [ -n "$NGINX_AVAILABLE" ] && [ ! -f "$NGINX_AVAILABLE" ]; then
    sudo tee "$NGINX_AVAILABLE" > /dev/null <<NGINX
server {
    listen 80;
${NGINX_SERVER_NAME}
    root ${PROJECT_DIR}/public;
    index index.php;

    location /app {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_read_timeout 86400;
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm.sock;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
NGINX
    sudo ln -sf "${NGINX_AVAILABLE}" /etc/nginx/sites-enabled/
    sudo nginx -t && sudo systemctl reload nginx || warn "Nginx vhost не применился."
    info "Nginx: vhost по ${IP_ADDRESS}${DOMAIN:+ / $DOMAIN} создан."
else
    info "Nginx vhost уже существует или IP/DOMAIN не заданы."
fi

# -----------------------------------------------------------------------------
# Cron
# -----------------------------------------------------------------------------
step "Настройка cron планировщика"
if crontab -u "$PROJECT_OWNER" -l >/dev/null 2>&1; then
    CRON_LINE="* * * * * cd ${PROJECT_DIR} && php artisan schedule:run >> /dev/null 2>&1"
    ( crontab -u "$PROJECT_OWNER" -l 2>/dev/null | grep -Fv 'artisan schedule:run'; echo "$CRON_LINE" ) | crontab -u "$PROJECT_OWNER" - 2>/dev/null \
        && info "Cron установлен." || warn "Cron не установился."
else
    warn "crontab недоступен для ${PROJECT_OWNER} — пропускаю."
fi

# -----------------------------------------------------------------------------
# Git + gh
# -----------------------------------------------------------------------------
step "Настройка Git + GitHub CLI"
run_as_owner git config --global user.name  "${SETUP_GIT_USER_NAME:-ink64rus-crmdtl}" || true
run_as_owner git config --global user.email "${SETUP_GIT_USER_EMAIL:-ink64rus@gmail.com}" || true
run_as_owner git config --global core.editor nano 2>/dev/null || true

run_as_ssh() {
    if [ "$(id -u)" -eq 0 ] && [ "$RUN_USER" != "root" ]; then
        sudo -u "$RUN_USER" env HOME="$RUN_HOME" "$@"
    else
        "$@"
    fi
}
if ! run_as_ssh gh auth status >/dev/null 2>&1; then
    if [ -n "${SETUP_GH_TOKEN:-${GH_TOKEN:-}}" ]; then
        echo "${SETUP_GH_TOKEN:-${GH_TOKEN:-}}" | run_as_ssh gh auth login --with-token
    else
        warn "Интерактивная авторизация GitHub CLI..."
        run_as_ssh gh auth login
    fi
    run_as_ssh gh auth setup-git 2>/dev/null || true
fi

step "ГОТОВО!"
cat <<SUMMARY
============================================================
  Проект:   ${PROJECT_DIR}
  Сайт:     http://${IP_ADDRESS}${DOMAIN:+ (домен ${DOMAIN})}
  opencode: ${RUN_HOME}/.opencode/bin/opencode (пользователь ${RUN_USER})
  Сервисы:  sudo systemctl status crm-horizon crm-reverb
============================================================
SUMMARY
