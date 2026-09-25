#!/usr/bin/env bash
#
# Bootstrap idempotente del entorno de desarrollo (Cloud Agent).
# Se ejecuta tras el checkout del repositorio. Prepara dependencias PHP, el
# shim de conexión local y la base de datos MariaDB con esquema + datos de
# ejemplo para poder ejecutar y demostrar la aplicación de extremo a extremo.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

DB_NAME="${JOYA_DB_NAME:-joya}"
DB_USER="${JOYA_DB_USER:-joya}"
DB_PASSWORD="${JOYA_DB_PASSWORD:-joya}"

echo ">> [0/4] Paquetes de sistema (PHP, MariaDB, Composer) si faltan"
if ! command -v php >/dev/null 2>&1 || ! command -v mariadbd >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive
    sudo apt-get update -y
    sudo apt-get install -y --no-install-recommends \
        php-cli php-mysql php-mbstring php-xml php-gd php-curl php-zip php-bcmath \
        mariadb-server mariadb-client unzip curl ca-certificates
fi
if ! command -v composer >/dev/null 2>&1; then
    TMP_COMPOSER="$(mktemp)"
    curl -fsSL https://getcomposer.org/installer -o "$TMP_COMPOSER"
    sudo php "$TMP_COMPOSER" --install-dir=/usr/local/bin --filename=composer
    rm -f "$TMP_COMPOSER"
fi

echo ">> [1/4] Dependencias PHP (composer install)"
composer install --no-interaction --no-progress

echo ">> [2/4] Shim de conexión local en /conexion_grs/conexion.php"
sudo mkdir -p /conexion_grs
sudo cp "$REPO_ROOT/.cursor/dev/conexion.php" /conexion_grs/conexion.php

echo ">> [3/4] Arranque de MariaDB"
sudo mkdir -p /run/mysqld
sudo chown mysql:mysql /run/mysqld
if ! sudo mariadb -e "SELECT 1" >/dev/null 2>&1; then
    sudo bash -c 'nohup mariadbd --user=mysql > /var/log/mariadbd.log 2>&1 &'
    for _ in $(seq 1 30); do
        if sudo mariadb -e "SELECT 1" >/dev/null 2>&1; then break; fi
        sleep 1
    done
fi
sudo mariadb -e "SELECT 1" >/dev/null 2>&1 || { echo "ERROR: MariaDB no arrancó"; sudo tail -20 /var/log/mariadbd.log || true; exit 1; }

echo ">> [3b/4] Tablas de zonas horarias (la app usa SET time_zone='America/Lima')"
if ! sudo mariadb -N -e "SELECT COUNT(*) FROM mysql.time_zone_name" 2>/dev/null | grep -q '[1-9]'; then
    sudo bash -c "mariadb-tzinfo-to-sql /usr/share/zoneinfo 2>/dev/null | mariadb mysql"
fi

echo ">> [4/4] Base de datos, usuario y datos de ejemplo"
sudo mariadb <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

mysql -u"$DB_USER" -p"$DB_PASSWORD" -h127.0.0.1 "$DB_NAME" < "$REPO_ROOT/.cursor/dev/schema.sql"
mysql -u"$DB_USER" -p"$DB_PASSWORD" -h127.0.0.1 "$DB_NAME" < "$REPO_ROOT/.cursor/dev/seed.sql"

echo ">> Entorno listo. Credenciales demo -> usuario: ADMIN  contraseña: 1234"
