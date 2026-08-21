#!/usr/bin/env bash
#
# =============================================================================
#  setup_server.sh — ПОЛНОЕ РАЗВЁРТЫВАНИЕ Детейлинг-CRM на чистом Ubuntu 24.04
# =============================================================================
#  Один прогон делает ВСЁ:
#    1. Базовая настройка системы (обновление пакетов, локали, часовой пояс)
#    2. Установка: PHP 8.4 + расширения, MySQL 8.0, Redis, Node.js 20, Nginx
#    3. Установка: Composer, Git, GitHub CLI (gh), dnsmasq (*.localhost)
#    4. Авторизация GitHub CLI (до клонирования — чтобы работал приватный репозиторий)
#    5. Клонирование проекта, composer install, npm install, npm run build
#    6. Создание БД и пользователя MySQL, генерация .env, миграции + сидеры
#    7. Установка и настройка opencode (некритично — при сбое продолжаем)
#    8. Настройка systemd-сервисов: Nginx (по IP/домену), Horizon, Reverb, cron
#
#  Запуск:
#      ssh <sudo_user>@<IP>
#      sudo bash setup_server.sh [--no-clone] [force-build]
#      (или просто bash setup_server.sh — sudo поднимется сам, если нужно)
#
#  РЕЖИМ "ВЫДЕЛЕННЫЙ IP БЕЗ ДОМЕНА" (по умолчанию):
#      Nginx настраивается на публичный IP 78.17.19.218, APP_URL=http://<IP>,
#      Reverb тоже привязывается к этому IP. Свой IP: SETUP_IP_ADDRESS=<ваш_IP>.
#
#  SSH/ГРАФИЧЕСКАЯ ОБОЛОЧКА:
#      Скрипт рассчитан на запуск по SSH от sudo-пользователя (не root!).
#      Все пользовательские настройки (opencode/git/gh) ложатся в домашнюю
#      директорию SSH-пользователя, systemd-сервисы работают от него же,
#      графическая сессия root НЕ затрагивается. Если скрипт запущен из
#      терминала в GUI root — он сам найдёт sudo-пользователя.
#
#  Переменные окружения (неинтерактивный прогон / CI):
#      SETUP_IP_ADDRESS=78.17.19.218
#      SETUP_DOMAIN=crm.example.com     # если позже появится домен
#      SETUP_GH_TOKEN=ghp_xxx           # токен GitHub (иначе интерактивный gh auth login)
#      SETUP_DB_USER=crm
#      SETUP_DB_PASS=secret
#      SETUP_DB_NAME=crmdtlv4
#      SETUP_APP_URL=https://crm.example.com
#      SETUP_GIT_USER_NAME="Имя"
#      SETUP_GIT_USER_EMAIL=user@example.com
#      SETUP_PROJECT_DIR=/var/www/crmdtlv4
#      SETUP_PROJECT_REPO=https://github.com/ink64rus-crmdtl/crmdtlv4.git
#      SETUP_PROJECT_BRANCH=main
#
#  Скрипт ИДЕМПОТЕНТЕН: безопасно перезапускать в любой момент.
#  (при повторном запуске уже сделанные шаги пропускаются)
# =============================================================================

set -uo pipefail
# ВНИМАНИЕ: НЕ используем `set -e` глобально. Вместо этого каждый "мягкий" шаг
# (opencode, cron, gh) оборачиваем в `|| warn ...`, чтобы одна несеть/пакет не
# останавливали весь развёртывание. Критичные шаги проверяем явно.

# -----------------------------------------------------------------------------
# Цвета для вывода
# -----------------------------------------------------------------------------
GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; CYAN='\033[0;36m'; NC='\033[0m'
info() { echo -e "${GREEN}[OK]${NC} $*"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $*"; }
err()  { echo -e "${RED}[ERR]${NC} $*" >&2; }
step() { echo -e "\n${CYAN}=====> $*${NC}"; }

# -----------------------------------------------------------------------------
# Конфигурация (переопределяется переменными окружения)
# -----------------------------------------------------------------------------
PROJECT_DIR="${SETUP_PROJECT_DIR:-/var/www/crmdtlv4}"
PROJECT_REPO="${SETUP_PROJECT_REPO:-https://github.com/ink64rus-crmdtl/crmdtlv4.git}"
PROJECT_BRANCH="${SETUP_PROJECT_BRANCH:-main}"

APP_NAME="${SETUP_APP_NAME:-Laravel}"
APP_ENV="${SETUP_APP_ENV:-local}"
APP_TIMEZONE="${SETUP_APP_TIMEZONE:-Europe/Moscow}"
DOMAIN="${SETUP_DOMAIN:-}"                       # если задан — Nginx vhost по домену
IP_ADDRESS="${SETUP_IP_ADDRESS:-78.17.19.218}"   # выделенный IP (режим без домена)
REVERB_PORT="${SETUP_REVERB_PORT:-8080}"
APP_URL="${SETUP_APP_URL:-}"
REVERB_HOST_VALUE="${SETUP_REVERB_HOST:-localhost}"
if [ -z "$APP_URL" ]; then
    if [ -n "$DOMAIN" ]; then
        APP_URL="https://${DOMAIN}"; REVERB_HOST_VALUE="${DOMAIN}"
    elif [ -n "$IP_ADDRESS" ]; then
        APP_URL="http://${IP_ADDRESS}"; REVERB_HOST_VALUE="${IP_ADDRESS}"
    else
        APP_URL="http://localhost:8000"
    fi
fi

# Хост центральной платформы — для tenancy.central_domains: домен или IP сервера.
CENTRAL_DOMAIN_HOST="${DOMAIN:-${IP_ADDRESS}}"

# Суффикс домена тенантов: при заданном DOMAIN — поддомены этого домена
# (mdtl.<DOMAIN>, требует wildcard-DNS *.DOMAIN -> IP). Без домена (только IP) —
# остаётся .localhost (доступ через /etc/hosts с рабочих машин).
TENANT_DOMAIN_SUFFIX="${SETUP_TENANT_DOMAIN_SUFFIX:-}"
if [ -z "$TENANT_DOMAIN_SUFFIX" ] && [ -n "$DOMAIN" ]; then
    TENANT_DOMAIN_SUFFIX=".${DOMAIN}"
fi
[ -z "$TENANT_DOMAIN_SUFFIX" ] && TENANT_DOMAIN_SUFFIX=".localhost"

DB_USER="${SETUP_DB_USER:-admin}"
DB_PASS="${SETUP_DB_PASS:-password}"
DB_NAME="${SETUP_DB_NAME:-crmdtlv4}"

GIT_USER_NAME="${SETUP_GIT_USER_NAME:-ink64rus-crmdtl}"
GIT_USER_EMAIL="${SETUP_GIT_USER_EMAIL:-ink64rus@gmail.com}"
GH_TOKEN="${SETUP_GH_TOKEN:-${GH_TOKEN:-}}"

PHP_VERSION="8.4"
PHP_EXTENSIONS=(bcmath cli curl fpm gd igbinary intl mbstring mysql opcache readline redis xml zip sqlite3)

# --- Кто выполняет скрипт (SSH-пользователь, НЕ root графической сессии) ---
RUN_USER="${SUDO_USER:-$(id -un)}"
if [ "$RUN_USER" = "root" ]; then
    # Запущено прямо под root (например, из терминала в GUI). Ищем sudo-пользователя.
    for cand in mdtl_admin admin deployer; do
        if getent passwd "$cand" >/dev/null 2>&1; then
            RUN_USER="$cand"
            warn "Скрипт запущен под root — переключаю пользовательские настройки на ${RUN_USER}."
            break
        fi
    done
fi
RUN_HOME="$(getent passwd "$RUN_USER" | cut -d: -f6 2>/dev/null || echo "/home/${RUN_USER}")"
PROJECT_OWNER="${SETUP_PROJECT_OWNER:-$RUN_USER}"

# --- Префикс для запуска команд от имени владельца проекта ---
RUN_AS_OWNER_CMD=()
if [ "$(id -u)" -eq 0 ] && [ "$PROJECT_OWNER" != "root" ]; then
    RUN_AS_OWNER_CMD=(sudo -u "$PROJECT_OWNER" env HOME="$RUN_HOME")
fi

run_as_owner() {
    if [ "${#RUN_AS_OWNER_CMD[@]}" -gt 0 ]; then
        "${RUN_AS_OWNER_CMD[@]}" "$@"
    else
        "$@"
    fi
}

run_as_ssh() {
    if [ "$(id -u)" -eq 0 ] && [ "$RUN_USER" != "root" ]; then
        sudo -u "$RUN_USER" env HOME="$RUN_HOME" "$@"
    else
        "$@"
    fi
}

# -----------------------------------------------------------------------------
# Параметры командной строки
# -----------------------------------------------------------------------------
NO_CLONE=""
FORCE_BUILD=""
for arg in "$@"; do
    case "$arg" in
        --help|-h)
            echo "Использование: sudo bash setup_server.sh [--no-clone] [force-build]"
            echo "  --no-clone   — не клонировать проект (если он уже развёрнут)"
            echo "  force-build  — принудительно пересобрать фронтенд (npm run build)"
            echo "Пример: ssh mdtl_admin@78.17.19.218 && sudo bash setup_server.sh"
            exit 0
            ;;
        --no-clone) NO_CLONE="yes" ;;
        force-build) FORCE_BUILD="yes" ;;
    esac
done

# -----------------------------------------------------------------------------
# Проверка ОС
# -----------------------------------------------------------------------------
. /etc/os-release 2>/dev/null || true
if [ "${VERSION_ID:-}" != "24.04" ] && [ "${VERSION_ID:-}" != "24.10" ]; then
    warn "Скрипт рассчитан на Ubuntu 24.04 (сейчас: ${PRETTY_NAME:-неизвестно}). Продолжаю на свой риск."
    read -r -p "Продолжить? [y/N] " ans; [[ "$ans" =~ ^[Yy]$ ]] || { err "Отмена."; exit 1; }
fi

# Убеждаемся, что sudo работает без пароля для текущей сессии (будет запрошен при необходимости)
if [ "$(id -u)" -ne 0 ]; then
    sudo -v || { err "Нет прав sudo. Войдите под sudo-пользователем."; exit 1; }
fi

# -----------------------------------------------------------------------------
# Шаг 1. Базовые системные пакеты
# -----------------------------------------------------------------------------
step "1/12 Установка базовых системных пакетов"
export DEBIAN_FRONTEND=noninteractive
sudo apt-get update -y
sudo apt-get install -y --no-install-recommends \
    curl wget git ca-certificates gnupg lsb-release software-properties-common \
    unzip zip jq tree htop net-tools dnsutils apt-transport-https \
    nginx dnsmasq dnsmasq-base \
    python3 python3-pip

sudo timedatectl set-timezone "$APP_TIMEZONE" 2>/dev/null || true
sudo locale-gen ru_RU.UTF-8 2>/dev/null || true

# -----------------------------------------------------------------------------
# Шаг 2. PHP 8.4 (репозиторий sury) + расширения
# -----------------------------------------------------------------------------
step "2/12 Установка PHP ${PHP_VERSION} и расширений"
if ! command -v php >/dev/null 2>&1 || ! php -v 2>/dev/null | grep -q "PHP ${PHP_VERSION}"; then
    sudo add-apt-repository -y ppa:ondrej/php
    sudo apt-get update -y
fi
PHP_PACKAGES=()
for ext in "${PHP_EXTENSIONS[@]}"; do
    PHP_PACKAGES+=("php${PHP_VERSION}-${ext}")
done
sudo apt-get install -y --no-install-recommends \
    "php${PHP_VERSION}" \
    "${PHP_PACKAGES[@]}"

PHP_INI_CLI="/etc/php/${PHP_VERSION}/cli/php.ini"
PHP_INI_FPM="/etc/php/${PHP_VERSION}/fpm/php.ini"
for ini in "$PHP_INI_CLI" "$PHP_INI_FPM"; do
    [ -f "$ini" ] || continue
    sudo sed -i "s/^memory_limit.*/memory_limit = 512M/" "$ini"
    sudo sed -i "s/^max_execution_time.*/max_execution_time = 120/" "$ini"
    sudo sed -i "s/^upload_max_filesize.*/upload_max_filesize = 32M/" "$ini"
    sudo sed -i "s/^post_max_size.*/post_max_size = 32M/" "$ini"
done

# -----------------------------------------------------------------------------
# Шаг 3. MySQL 8.0
# -----------------------------------------------------------------------------
step "3/12 Установка MySQL 8.0"
if ! command -v mysql >/dev/null 2>&1; then
    sudo apt-get install -y --no-install-recommends mysql-server mysql-client
fi
sudo systemctl enable --now mysql 2>/dev/null || true
# Ждём готовности MySQL (на чистой машине может подниматься пару секунд)
for _ in $(seq 1 30); do
    sudo mysqladmin ping --silent 2>/dev/null && break
    sleep 1
done

# -----------------------------------------------------------------------------
# Шаг 4. Redis
# -----------------------------------------------------------------------------
step "4/12 Установка Redis"
if ! command -v redis-server >/dev/null 2>&1; then
    sudo apt-get install -y --no-install-recommends redis-server
fi
sudo systemctl enable --now redis-server 2>/dev/null || true

# -----------------------------------------------------------------------------
# Шаг 5. Node.js 20 + npm
# -----------------------------------------------------------------------------
step "5/12 Установка Node.js 20"
if ! command -v node >/dev/null 2>&1 || ! node -v 2>/dev/null | grep -q "^v20"; then
    curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
    sudo apt-get install -y --no-install-recommends nodejs
fi

# -----------------------------------------------------------------------------
# Шаг 6. Composer
# -----------------------------------------------------------------------------
step "6/12 Установка Composer"
if ! command -v composer >/dev/null 2>&1; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

# -----------------------------------------------------------------------------
# Шаг 7. GitHub CLI (gh) + авторизация (ДО клонирования!)
# -----------------------------------------------------------------------------
step "7/12 Установка и авторизация GitHub CLI (gh)"
if ! command -v gh >/dev/null 2>&1; then
    curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg \
        | sudo dd of=/usr/share/keyrings/githubcli-archive-keyring.gpg 2>/dev/null
    sudo chmod go+r /usr/share/keyrings/githubcli-archive-keyring.gpg 2>/dev/null || true
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" \
        | sudo tee /etc/apt/sources.list.d/github-cli.list > /dev/null
    sudo apt-get update -y
    sudo apt-get install -y --no-install-recommends gh
fi

# Авторизация gh от имени SSH-пользователя (нужна для clone приватного репозитория)
if ! run_as_ssh gh auth status >/dev/null 2>&1; then
    if [ -n "$GH_TOKEN" ]; then
        echo "$GH_TOKEN" | run_as_ssh gh auth login --with-token
        warn "GH_TOKEN передан — токен в истории не оставляем."
    else
        warn "Запускаю интерактивную авторизацию GitHub CLI (от имени ${RUN_USER})."
        warn "Если репозиторий публичный — можно нажать Ctrl+C и пропустить."
        run_as_ssh gh auth login || warn "gh не авторизован — продолжим; клонирование может не сработать."
    fi
    run_as_ssh gh auth setup-git 2>/dev/null || true
fi
info "GitHub CLI: $(run_as_ssh gh auth status 2>&1 | grep -i 'account' | head -1 || echo 'не авторизован')"

# Настраиваем git для SSH-пользователя (до clone, чтобы credential-helper работал)
run_as_ssh git config --global user.name  "$GIT_USER_NAME" 2>/dev/null || true
run_as_ssh git config --global user.email "$GIT_USER_EMAIL" 2>/dev/null || true
run_as_ssh git config --global core.editor nano 2>/dev/null || true

# -----------------------------------------------------------------------------
# Шаг 8. dnsmasq — резолв *.localhost на 127.0.0.1
# -----------------------------------------------------------------------------
step "8/12 Настройка dnsmasq для доменов *.localhost"
if [ ! -f /etc/dnsmasq.d/localhost.conf ]; then
    echo "address=/.localhost/127.0.0.1" | sudo tee /etc/dnsmasq.d/localhost.conf > /dev/null
    sudo sed -i "s/#\?port=.*/port=5353/" /etc/dnsmasq.conf 2>/dev/null || true
fi
sudo systemctl enable dnsmasq 2>/dev/null || true
sudo systemctl restart dnsmasq 2>/dev/null || warn "dnsmasq не запустился (может конфликтовать с systemd-resolved) — не критично."

# -----------------------------------------------------------------------------
# Шаг 9. Клонирование проекта + зависимости
# -----------------------------------------------------------------------------
step "9/12 Клонирование проекта и установка зависимостей"
sudo mkdir -p "$(dirname "$PROJECT_DIR")"
sudo chown -R "${PROJECT_OWNER}":"${PROJECT_OWNER}" "$(dirname "$PROJECT_DIR")" 2>/dev/null || true

if [ -z "$NO_CLONE" ] && [ ! -d "$PROJECT_DIR/.git" ]; then
    # Клонируем от владельца проекта, чтобы не было проблем с правами
    if [ "${#RUN_AS_OWNER_CMD[@]}" -gt 0 ]; then
        "${RUN_AS_OWNER_CMD[@]}" git clone -b "$PROJECT_BRANCH" "$PROJECT_REPO" "$PROJECT_DIR" \
            || { warn "git clone не сработал от ${PROJECT_OWNER} — пробую от root.";
                 sudo git clone -b "$PROJECT_BRANCH" "$PROJECT_REPO" "$PROJECT_DIR" || exit 1; }
    else
        git clone -b "$PROJECT_BRANCH" "$PROJECT_REPO" "$PROJECT_DIR" || exit 1
    fi
fi
sudo chown -R "${PROJECT_OWNER}":"${PROJECT_OWNER}" "$PROJECT_DIR" 2>/dev/null || true
# Права на запись для storage и bootstrap/cache (нужны для кеша, логов, Horizon)
sudo mkdir -p "$PROJECT_DIR/storage/framework/cache/data" \
             "$PROJECT_DIR/storage/framework/sessions" \
             "$PROJECT_DIR/storage/framework/views" \
             "$PROJECT_DIR/storage/logs" \
             "$PROJECT_DIR/bootstrap/cache" 2>/dev/null || true
sudo chown -R "${PROJECT_OWNER}":"${PROJECT_OWNER}" \
    "$PROJECT_DIR/storage" "$PROJECT_DIR/bootstrap/cache" 2>/dev/null || true
sudo chmod -R u+rwX,go+rwX "$PROJECT_DIR/storage" "$PROJECT_DIR/bootstrap/cache" 2>/dev/null || true
cd "$PROJECT_DIR"

if [ ! -d vendor ]; then
    run_as_owner composer install --no-interaction --prefer-dist || warn "composer install дал ошибку"
fi
if [ ! -d node_modules ]; then
    run_as_owner npm install --no-audit --no-fund || warn "npm install дал ошибку"
fi

# Сборка фронтенда
if [ ! -d public/build ] || [ -n "$FORCE_BUILD" ]; then
    run_as_owner npm run build || warn "npm run build дал ошибку (фронт можно собрать позже: npm run build)"
fi

# -----------------------------------------------------------------------------
# Шаг 10. БД, .env, миграции и сидеры
# -----------------------------------------------------------------------------
step "10/12 Создание БД, .env, миграций и сидеров"
if ! sudo mysql -e "SELECT 1 FROM mysql.user WHERE user='${DB_USER}'" | grep -q 1; then
    sudo mysql <<SQL
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON *.* TO '${DB_USER}'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL
    warn "Создан пользователь MySQL ${DB_USER} (пароль — в переменной DB_PASS)."
fi
sudo mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# .env — не копируем sqlite-шаблон из коробки, генерируем под MySQL/Redis
if [ ! -f .env ]; then
    if [ -f .env.example ] && ! grep -q '^DB_CONNECTION=sqlite' .env.example; then
        cp .env.example .env 2>/dev/null || true
    fi
fi
if [ ! -f .env ]; then
    cat > .env <<ENV
APP_NAME="${APP_NAME}"
APP_ENV=${APP_ENV}
APP_KEY=
APP_DEBUG=true
APP_URL=${APP_URL}
LOG_CHANNEL=stack
LOG_LEVEL=debug
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PASS}
BROADCAST_CONNECTION=reverb
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REVERB_APP_ID=567162
REVERB_APP_KEY=sd5n9iymugkfj9ydhmfo
REVERB_APP_SECRET=yb7ma75c0cpmbfqjj0zj
REVERB_HOST="${REVERB_HOST_VALUE}"
REVERB_PORT=${REVERB_PORT}
REVERB_SCHEME=http
# Центральные домены платформы (лендинг, регистрация компаний). БЕЗ IP/домена
# сервера здесь корневой "/" уходит в tenancy-группу и падает с
# TenantCouldNotBeIdentifiedOnDomainException (см. config/tenancy.php).
TENANCY_CENTRAL_DOMAINS=127.0.0.1,localhost,${CENTRAL_DOMAIN_HOST}
# Суффикс домена тенантов: .localhost (dev) или .<DOMAIN> (wildcard-DNS).
TENANT_DOMAIN_SUFFIX=${TENANT_DOMAIN_SUFFIX}
VITE_REVERB_APP_KEY="\${REVERB_APP_KEY}"
VITE_REVERB_HOST="\${REVERB_HOST}"
VITE_REVERB_PORT="\${REVERB_PORT}"
VITE_REVERB_SCHEME="\${REVERB_SCHEME}"
ENV
fi

# Гарантируем TENANCY_CENTRAL_DOMAINS и TENANT_DOMAIN_SUFFIX в уже существующем .env
if [ -f .env ]; then
    if ! grep -q '^TENANCY_CENTRAL_DOMAINS=' .env; then
        echo "" >> .env
        echo "# Центральные домены платформы (см. config/tenancy.php)" >> .env
        echo "TENANCY_CENTRAL_DOMAINS=127.0.0.1,localhost,${CENTRAL_DOMAIN_HOST}" >> .env
        warn "В существующий .env добавлен TENANCY_CENTRAL_DOMAINS."
    fi
    if ! grep -q '^TENANT_DOMAIN_SUFFIX=' .env; then
        echo "TENANT_DOMAIN_SUFFIX=${TENANT_DOMAIN_SUFFIX}" >> .env
        warn "В существующий .env добавлен TENANT_DOMAIN_SUFFIX=${TENANT_DOMAIN_SUFFIX}."
    fi
fi

# APP_KEY обязателен
if ! grep -q '^APP_KEY=base64' .env 2>/dev/null; then
    run_as_owner php artisan key:generate --force 2>/dev/null || true
fi

# Миграции
run_as_owner php artisan migrate --force || warn "Миграция ядра дала ошибку — проверьте доступы MySQL и .env"
run_as_owner php artisan tenants:migrate --force 2>/dev/null || true
run_as_owner php artisan tenants:seed --force 2>/dev/null || true

# Кэширование (ускоряет отдачу; сбрасываем на каждом повторе — чтобы подхватить изменения .env)
run_as_owner php artisan config:clear 2>/dev/null || true
run_as_owner php artisan route:clear 2>/dev/null || true
run_as_owner php artisan view:clear 2>/dev/null || true
run_as_owner php artisan config:cache 2>/dev/null || true
run_as_owner php artisan route:cache 2>/dev/null || true
run_as_owner php artisan view:cache 2>/dev/null || true

# -----------------------------------------------------------------------------
# Cron для планировщика (некритичный шаг)
# -----------------------------------------------------------------------------
step "Планировщик cron"
if crontab -u "$PROJECT_OWNER" -l >/dev/null 2>&1; then
    CRON_LINE="* * * * * cd ${PROJECT_DIR} && php artisan schedule:run >> /dev/null 2>&1"
    ( crontab -u "$PROJECT_OWNER" -l 2>/dev/null | grep -Fv 'artisan schedule:run'; echo "$CRON_LINE" ) | crontab -u "$PROJECT_OWNER" - 2>/dev/null \
        && info "Cron установлен (${PROJECT_OWNER})." \
        || warn "Не удалось установить cron."
else
    warn "crontab недоступен для ${PROJECT_OWNER} — пропускаю."
fi

# -----------------------------------------------------------------------------
# Шаг 11. opencode + его конфигурация (НЕКРИТИЧНО)
# -----------------------------------------------------------------------------
step "11/12 Установка и настройка opencode"
opencode_install() {
    if [ "$(id -u)" -eq 0 ] && [ "$RUN_USER" != "root" ]; then
        sudo -u "$RUN_USER" env HOME="$RUN_HOME" bash -c 'curl -fsSL https://opencode.ai/install | bash'
    else
        curl -fsSL https://opencode.ai/install | bash
    fi
}
if ! command -v opencode >/dev/null 2>&1 && [ ! -x "${RUN_HOME}/.opencode/bin/opencode" ]; then
    opencode_install \
        && info "opencode установлен." \
        || warn "opencode не установился (сеть/порядок). Доустановите позже: curl -fsSL https://opencode.ai/install | bash"
fi
if [ -x "${RUN_HOME}/.opencode/bin/opencode" ]; then
    export PATH="${RUN_HOME}/.opencode/bin:${PATH}"
    if [ -f "${RUN_HOME}/.bashrc" ]; then
        grep -q '.opencode/bin' "${RUN_HOME}/.bashrc" 2>/dev/null || \
            echo 'export PATH="$HOME/.opencode/bin:$PATH"' >> "${RUN_HOME}/.bashrc"
    fi
fi

# Конфигурация opencode (только если ещё нет)
if [ ! -f "${RUN_HOME}/.config/opencode/opencode.json" ]; then
    run_as_ssh mkdir -p "${RUN_HOME}/.config/opencode"
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
    install -o "$RUN_USER" -g "$RUN_USER" -m 600 /tmp/opencode-config.json "${RUN_HOME}/.config/opencode/opencode.json" 2>/dev/null || true
    rm -f /tmp/opencode-config.json
fi

# Глобальные npm-инструменты (MCP-серверы opencode) — необязательно
if npm ls -g --depth=0 >/dev/null 2>&1; then
    if ! npm ls -g --depth=0 2>/dev/null | grep -q mcp-server-mysql; then
        sudo npm install -g mcp-server-mysql mcp-server-filesystem mcp-server-laravel-codebase opencode-browser-control 2>/dev/null \
            || warn "Глобальные MCP-серверы npm не установились (не критично)."
    fi
fi

# -----------------------------------------------------------------------------
# Шаг 12. systemd-сервисы: Horizon, Reverb, Nginx
# -----------------------------------------------------------------------------
step "12/12 Настройка systemd-сервисов: Horizon, Reverb, Nginx"
cd "$PROJECT_DIR"

# --- Horizon ---
HORIZON_SERVICE="/etc/systemd/system/crm-horizon.service"
if [ ! -f "$HORIZON_SERVICE" ]; then
    sudo tee "$HORIZON_SERVICE" > /dev/null <<SYSTEMD
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

# --- Reverb ---
REVERB_SERVICE="/etc/systemd/system/crm-reverb.service"
if [ ! -f "$REVERB_SERVICE" ]; then
    sudo tee "$REVERB_SERVICE" > /dev/null <<SYSTEMD
[Unit]
Description=CRM Reverb WebSocket server
After=network.target redis-server.service
[Service]
User=${PROJECT_OWNER}
Group=${PROJECT_OWNER}
WorkingDirectory=${PROJECT_DIR}
ExecStart=/usr/bin/php ${PROJECT_DIR}/artisan reverb:start --host=0.0.0.0 --port=${REVERB_PORT}
Restart=on-failure
RestartSec=5
[Install]
WantedBy=multi-user.target
SYSTEMD
fi

sudo systemctl daemon-reload
sudo systemctl enable --now crm-horizon.service 2>/dev/null \
    && info "Horizon запущен." || warn "Horizon не запустился (журнал: journalctl -u crm-horizon -e)"
sudo systemctl enable --now crm-reverb.service 2>/dev/null \
    && info "Reverb запущен." || warn "Reverb не запустился (журнал: journalctl -u crm-reverb -e)"

# --- Nginx vhost (по домену ИЛИ по IP) ---
NGINX_AVAILABLE=""
if [ -n "$DOMAIN" ]; then
    NGINX_AVAILABLE="/etc/nginx/sites-available/${DOMAIN}"
    NGINX_SERVER_NAME="    server_name ${DOMAIN} *.${DOMAIN};"
    NGINX_ID="vhost для ${DOMAIN}"
elif [ -n "$IP_ADDRESS" ]; then
    NGINX_AVAILABLE="/etc/nginx/sites-available/crmdtlv4-ip"
    NGINX_SERVER_NAME="    server_name ${IP_ADDRESS};"
    NGINX_ID="vhost по IP ${IP_ADDRESS}"
fi

if [ -n "$NGINX_AVAILABLE" ] && [ ! -f "$NGINX_AVAILABLE" ]; then
    sudo tee "$NGINX_AVAILABLE" > /dev/null <<NGINX
server {
    listen 80;
${NGINX_SERVER_NAME}
    root ${PROJECT_DIR}/public;
    index index.php;

    location /app {
        proxy_pass http://127.0.0.1:${REVERB_PORT};
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
    if sudo nginx -t 2>/dev/null; then
        sudo systemctl reload nginx 2>/dev/null || true
        info "Nginx: ${NGINX_ID} настроен."
    else
        warn "nginx -t не прошёл — проверьте конфиг вручную: nginx -t"
    fi
else
    info "Nginx: vhost уже существует или IP/DOMAIN не заданы."
fi

# -----------------------------------------------------------------------------
# Итог
# -----------------------------------------------------------------------------
step "ГОТОВО! Развёртывание завершено."
cat <<SUMMARY
============================================================
  Проект:        ${PROJECT_DIR}
  URL:           ${APP_URL}
  PHP:           $(php -v 2>/dev/null | head -1)
  MySQL:         $(mysql --version 2>/dev/null)
  Redis:         $(redis-server --version 2>/dev/null | grep -oE 'v=[0-9.]+' | head -1)
  Node.js:       $(node -v 2>/dev/null)  npm: $(npm -v 2>/dev/null)
  Composer:      $(composer --version 2>/dev/null | grep -oE '[0-9.]+' | head -1)
  opencode:      ${RUN_HOME}/.opencode/bin/opencode (пользователь ${RUN_USER})
  Сервисы:       crm-horizon, crm-reverb, nginx
============================================================

Проверка:
  sudo systemctl status crm-horizon crm-reverb nginx php8.4-fpm mysql redis
  curl -I ${APP_URL}

Режим разработки (горячая пересборка фронта):
  cd ${PROJECT_DIR} && composer run dev

Логи:
  tail -f ${PROJECT_DIR}/storage/logs/laravel.log
  sudo journalctl -u crm-horizon -f
  sudo journalctl -u crm-reverb -f
SUMMARY
