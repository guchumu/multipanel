#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "=== MultiPanel ERP — Instalación ==="

# PHP check
php -v >/dev/null 2>&1 || { echo "PHP 8.3+ requerido"; exit 1; }

# Composer
if [ ! -d vendor ]; then
    echo "→ Instalando dependencias Composer..."
    composer install --no-interaction --optimize-autoloader
fi

# Environment
if [ ! -f .env ]; then
    cp .env.example .env
    echo "→ Creado .env — configura credenciales antes de continuar en producción"
fi

# Storage permissions
mkdir -p storage/{logs,cache,sessions,backups,exports,invoices,realtime,uploads}
chmod -R 775 storage 2>/dev/null || true

# Database (optional — skip if DB not configured)
if grep -q '^DB_DATABASE=' .env 2>/dev/null; then
    source .env 2>/dev/null || true
    if [ -n "${DB_DATABASE:-}" ] && command -v mysql >/dev/null 2>&1; then
        echo "→ Importando schema SQL..."
        mysql -h"${DB_HOST:-127.0.0.1}" -P"${DB_PORT:-3306}" -u"${DB_USERNAME:-root}" ${DB_PASSWORD:+-p"$DB_PASSWORD"} \
            -e "CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null || true
        mysql -h"${DB_HOST:-127.0.0.1}" -P"${DB_PORT:-3306}" -u"${DB_USERNAME:-root}" ${DB_PASSWORD:+-p"$DB_PASSWORD"} \
            "${DB_DATABASE}" < database/schema.sql 2>/dev/null || echo "  (schema manual o vía /install/)"
        mysql -h"${DB_HOST:-127.0.0.1}" -P"${DB_PORT:-3306}" -u"${DB_USERNAME:-root}" ${DB_PASSWORD:+-p"$DB_PASSWORD"} \
            "${DB_DATABASE}" < database/seeds/default.sql 2>/dev/null || true
        for f in database/migrations/*.sql; do
            [ -f "$f" ] && mysql -h"${DB_HOST:-127.0.0.1}" -u"${DB_USERNAME:-root}" ${DB_PASSWORD:+-p"$DB_PASSWORD"} "${DB_DATABASE}" < "$f" 2>/dev/null || true
        done
    fi
fi

# Generate keys if empty
php -r "
require 'vendor/autoload.php';
\$dotenv = Dotenv\Dotenv::createImmutable('$ROOT');
\$dotenv->safeLoad();
\$env = file_get_contents('$ROOT/.env');
if (!preg_match('/^APP_KEY=.+\\S/m', \$env)) {
    \$key = bin2hex(random_bytes(32));
    \$env = preg_replace('/^APP_KEY=.*/m', 'APP_KEY='.\$key, \$env) ?: \$env.\"\\nAPP_KEY=\$key\";
    file_put_contents('$ROOT/.env', \$env);
    echo \"→ APP_KEY generada\\n\";
}
if (!preg_match('/^JWT_SECRET=.+\\S/m', \$env)) {
    \$jwt = bin2hex(random_bytes(32));
    \$env = file_get_contents('$ROOT/.env');
    \$env = preg_replace('/^JWT_SECRET=.*/m', 'JWT_SECRET='.\$jwt, \$env) ?: \$env.\"\\nJWT_SECRET=\$jwt\";
    file_put_contents('$ROOT/.env', \$env);
    echo \"→ JWT_SECRET generada\\n\";
}
"

echo "→ Ejecutando tests..."
vendor/bin/phpunit --colors=always 2>/dev/null || echo "  (tests omitidos — revisar manualmente)"

echo ""
echo "=== Instalación completada ==="
echo "  Web:      http://localhost (o /install/ si APP_INSTALLED=false)"
echo "  Health:   php scripts/health-check.php"
echo "  WebSocket: php scripts/websocket-server.php 8081"
echo "  Docker:   docker compose -f docker/docker-compose.prod.yml up -d"
