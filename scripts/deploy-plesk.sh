#!/usr/bin/env bash
# MultiPanel ERP — Despliegue en VPS Arsys con Plesk (Ubuntu 24.04)
# Uso: sudo bash scripts/deploy-plesk.sh
set -euo pipefail

DOMAIN="${DOMAIN:-quizzical-beaver.212-227-98-60.plesk.page}"
APP_URL="${APP_URL:-https://${DOMAIN}}"
VHOST_ROOT="/var/www/vhosts/${DOMAIN}"
APP_DIR="${VHOST_ROOT}/multipanel"
REPO="${REPO:-https://github.com/guchumu/multipanel.git}"

echo "=== MultiPanel — Despliegue Plesk ==="
echo "Dominio: ${DOMAIN}"
echo "URL:     ${APP_URL}"
echo ""

if [[ $EUID -ne 0 ]]; then
    echo "Ejecuta como root: sudo bash $0"
    exit 1
fi

if [[ ! -d "${VHOST_ROOT}" ]]; then
    echo "No existe ${VHOST_ROOT}"
    echo "Crea el dominio en Plesk primero o ajusta la variable DOMAIN."
    exit 1
fi

# Dependencias mínimas (sin Docker — ahorra RAM en VPS 2 GB)
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq git curl unzip 2>/dev/null || true

# PHP 8.3 (Plesk suele tenerlo; si no, instalar extensiones vía Plesk)
if ! command -v php >/dev/null 2>&1; then
    echo "PHP no encontrado. Activa PHP 8.3 en Plesk → Dominios → ${DOMAIN} → Configuración de PHP."
    exit 1
fi

PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
echo "→ PHP ${PHP_VER}"

# Composer
if ! command -v composer >/dev/null 2>&1; then
    echo "→ Instalando Composer..."
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

# Clonar o actualizar
if [[ -d "${APP_DIR}/.git" ]]; then
    echo "→ Actualizando repositorio..."
    git -C "${APP_DIR}" pull --ff-only origin main
else
    echo "→ Clonando repositorio..."
    git clone "${REPO}" "${APP_DIR}"
fi

cd "${APP_DIR}"

# Dependencias PHP (sin dev en producción)
composer install --no-dev --no-interaction --optimize-autoloader

# .env
if [[ ! -f .env ]]; then
    cp .env.example .env
fi

# Propietario del dominio Plesk
DOMAIN_USER=$(stat -c '%U' "${VHOST_ROOT}" 2>/dev/null || echo "www-data")
DOMAIN_GROUP=$(stat -c '%G' "${VHOST_ROOT}" 2>/dev/null || echo "www-data")
echo "→ Propietario: ${DOMAIN_USER}:${DOMAIN_GROUP}"

# Pedir credenciales BD si no están en entorno
DB_NAME="${DB_NAME:-multipanel}"
DB_USER="${DB_USER:-multipanel}"
DB_PASS="${DB_PASS:-}"

if [[ -z "${DB_PASS}" ]]; then
    DB_PASS=$(openssl rand -base64 18 | tr -dc 'a-zA-Z0-9' | head -c 16)
    echo ""
    echo "=== CREA ESTA BASE DE DATOS EN PLESK ==="
    echo "  Plesk → Bases de datos → Añadir base de datos"
    echo "  Nombre:  ${DB_NAME}"
    echo "  Usuario: ${DB_USER}"
    echo "  Clave:   ${DB_PASS}"
    echo ""
    read -r -p "Pulsa Enter cuando hayas creado la BD en Plesk (o Ctrl+C para cancelar)..."
fi

# Actualizar .env
sed -i "s|^APP_URL=.*|APP_URL=${APP_URL}|" .env
sed -i "s|^APP_ENV=.*|APP_ENV=production|" .env
sed -i "s|^APP_DEBUG=.*|APP_DEBUG=false|" .env
sed -i "s|^SESSION_SECURE=.*|SESSION_SECURE=true|" .env
sed -i "s|^REDIS_ENABLED=.*|REDIS_ENABLED=false|" .env
sed -i "s|^DB_DATABASE=.*|DB_DATABASE=${DB_NAME}|" .env
sed -i "s|^DB_USERNAME=.*|DB_USERNAME=${DB_USER}|" .env
sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASS}|" .env

# Generar APP_KEY y JWT_SECRET si vacíos
php -r "
require 'vendor/autoload.php';
\$env = file_get_contents('.env');
if (!preg_match('/^APP_KEY=\\S+/m', \$env)) {
    \$k = bin2hex(random_bytes(32));
    \$env = preg_replace('/^APP_KEY=.*/m', 'APP_KEY='.\$k, \$env);
    file_put_contents('.env', \$env);
    echo \"→ APP_KEY generada\n\";
}
\$env = file_get_contents('.env');
if (!preg_match('/^JWT_SECRET=\\S+/m', \$env)) {
    \$j = bin2hex(random_bytes(32));
    \$env = preg_replace('/^JWT_SECRET=.*/m', 'JWT_SECRET='.\$j, \$env);
    file_put_contents('.env', \$env);
    echo \"→ JWT_SECRET generada\n\";
}
"

# Importar schema
echo "→ Importando base de datos..."
mysql -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" < database/schema.sql
mysql -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" < database/seeds/default.sql 2>/dev/null || true
for f in database/migrations/*.sql; do
    [[ -f "$f" ]] && mysql -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" < "$f" 2>/dev/null || true
done

# Permisos storage
mkdir -p storage/{logs,cache,sessions,backups,exports,invoices,realtime,uploads}
chown -R "${DOMAIN_USER}:${DOMAIN_GROUP}" "${APP_DIR}"
find storage -type d -exec chmod 775 {} \;
find storage -type f -exec chmod 664 {} \; 2>/dev/null || true

# Document root en Plesk → multipanel/public
if command -v plesk >/dev/null 2>&1; then
    echo "→ Configurando document root en Plesk..."
    plesk bin site --update "${DOMAIN}" -www-root multipanel/public 2>/dev/null \
        || plesk bin subscription --update "${DOMAIN}" -www-root multipanel/public 2>/dev/null \
        || echo "  (ajusta manualmente en Plesk: Raíz del documento → multipanel/public)"
fi

# Cron cada 5 minutos
CRON_CMD="*/5 * * * * ${DOMAIN_USER} /usr/bin/php ${APP_DIR}/cron/run.php all >> ${APP_DIR}/storage/logs/cron.log 2>&1"
(crontab -u "${DOMAIN_USER}" -l 2>/dev/null | grep -F "cron/run.php" || true) | grep -q "cron/run.php" \
    || (crontab -u "${DOMAIN_USER}" -l 2>/dev/null; echo "${CRON_CMD}") | crontab -u "${DOMAIN_USER}" -

echo ""
echo "=== Despliegue completado ==="
echo ""
echo "  1. Plesk → SSL/TLS → Let's Encrypt (activar para ${DOMAIN})"
echo "  2. Plesk → PHP → PHP 8.3, extensiones: mysql, curl, mbstring, openssl, json"
echo "  3. Abre: ${APP_URL}/install/"
echo "  4. Verifica: php ${APP_DIR}/scripts/verify-install.php"
echo ""
echo "  Admin por defecto (tras seeds): admin@multipanel.local / password"
echo "  ¡Cámbiala inmediatamente tras el primer login!"
echo ""

php "${APP_DIR}/scripts/verify-install.php" 2>/dev/null || true
