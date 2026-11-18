#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
raiz=$(pwd -P)
usuario=$(id -un)
proyecto=${COMPOSE_PROJECT_NAME:-kalma-snkrs-php}
[[ "$raiz" =~ ^/[a-zA-Z0-9_./-]+$ && "$usuario" =~ ^[a-zA-Z0-9_-]+$ && "$proyecto" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || { echo "Revisa la ruta, el usuario y el proyecto Compose." >&2; exit 1; }
systemctl is-active --quiet cron || { echo "Activa el servicio cron antes de continuar." >&2; exit 1; }
export PATH=/usr/local/bin:/usr/bin:/bin
db=$(docker compose -p "$proyecto" ps -q db)
[[ -n "$db" ]] || { echo "Arranca la base del proyecto $proyecto antes de continuar." >&2; exit 1; }
umask 077
mkdir -p backups
touch backups/cron.log
temporal=$(mktemp)
trap 'rm -f "$temporal"' EXIT
cat > "$temporal" <<EOF
SHELL=/bin/bash
PATH=/usr/local/bin:/usr/bin:/bin
COMPOSE_PROJECT_NAME=$proyecto
0 3 * * * $usuario /bin/bash "$raiz/scripts/backup.sh" >> "$raiz/backups/cron.log" 2>&1
EOF
sudo install -o root -g root -m 0644 "$temporal" /etc/cron.d/kalma-backup
echo "Copia diaria instalada a las 03:00 para $usuario."
