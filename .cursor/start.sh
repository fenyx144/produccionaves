#!/usr/bin/env bash
#
# Reconciliación por arranque del entorno. Garantiza que MariaDB esté activa y
# que el shim de conexión local exista antes de que el agente use la app.
# Idempotente: no duplica procesos.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Shim de conexión (por si el snapshot base no lo incluye todavía).
if [ ! -f /conexion_grs/conexion.php ]; then
    sudo mkdir -p /conexion_grs
    sudo cp "$REPO_ROOT/.cursor/dev/conexion.php" /conexion_grs/conexion.php
fi

# MariaDB
sudo mkdir -p /run/mysqld
sudo chown mysql:mysql /run/mysqld
if ! sudo mariadb -e "SELECT 1" >/dev/null 2>&1; then
    sudo bash -c 'nohup mariadbd --user=mysql > /var/log/mariadbd.log 2>&1 &'
    for _ in $(seq 1 30); do
        if sudo mariadb -e "SELECT 1" >/dev/null 2>&1; then break; fi
        sleep 1
    done
fi
sudo mariadb -e "SELECT 1" >/dev/null 2>&1 || { echo "ERROR: MariaDB no arrancó"; exit 1; }

echo "MariaDB lista."
