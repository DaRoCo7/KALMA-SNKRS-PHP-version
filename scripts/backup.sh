#!/usr/bin/env bash
set -euo pipefail
umask 077
cd "$(dirname "$0")/.."

# En el servidor se usa el nombre de proyecto de compose.yaml.
# En desarrollo se indica kalma-php-actual al invocar el script.
compose=(docker compose)
if [[ -n "${COMPOSE_PROJECT_NAME:-}" ]]; then
    compose+=(-p "$COMPOSE_PROJECT_NAME")
fi

raiz=backups
mkdir -p "$raiz"
fecha=$(date '+%Y%m%d-%H%M')
destino="$raiz/$fecha"
registro="$raiz/backup.log"

registrar() {
    printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S %z')" "$*" >> "$registro"
}

# Evita que cron y una ejecución manual roten las mismas copias a la vez.
if ! mkdir "$raiz/.en-curso" 2>/dev/null; then
    registrar "ERROR bloqueo existente; destino=$destino"
    echo "Comprueba si ya hay una copia en curso: $raiz/.en-curso" >&2
    exit 1
fi

temporal=''
finalizar() {
    resultado=$?
    trap - EXIT
    if [[ -n "$temporal" && -d "$temporal" ]]; then
        rm -rf -- "$temporal"
    fi
    rmdir "$raiz/.en-curso"
    if (( resultado != 0 )); then
        registrar "ERROR código=$resultado destino=$destino"
    fi
    exit "$resultado"
}
trap finalizar EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

if [[ -e "$destino" ]]; then
    echo "Ya existe una copia para este minuto: $destino" >&2
    exit 1
fi
temporal=$(mktemp -d "$raiz/.parcial-XXXXXXXX")

# El volcado binario viaja directamente del contenedor al archivo mediante Bash.
"${compose[@]}" exec -T db sh -c \
    'exec pg_dump -Fc -U "$POSTGRES_USER" -d "$POSTGRES_DB"' \
    > "$temporal/base.dump"

"${compose[@]}" exec -T db pg_restore --list \
    < "$temporal/base.dump" > "$temporal/indice.txt"

# Configuración reproducible del commit. El .env real y las claves TLS
# se aprovisionan por separado al recuperar el servicio.
git archive --format=tar.gz --output="$temporal/configuracion.tar.gz" HEAD -- \
    compose.yaml compose.dev.yaml Dockerfile .dockerignore .env.example \
    docker/nginx scripts database

git rev-parse HEAD > "$temporal/commit.txt"
for servicio in app proxy db; do
    id=$("${compose[@]}" ps -q "$servicio")
    if [[ -z "$id" ]]; then
        echo "No se encuentra el servicio en ejecución: $servicio" >&2
        exit 1
    fi
    docker inspect --format '{{.Config.Image}} {{.Image}}' "$id" \
        >> "$temporal/imagenes.txt"
done

(
    cd "$temporal"
    sha256sum base.dump configuracion.tar.gz > SHA256SUMS
    sha256sum -c SHA256SUMS
)
touch "$temporal/.completa"
mv -- "$temporal" "$destino"
temporal=''
registrar "OK destino=$destino"

# Los nombres AAAAMMDD-HHMM quedan ordenados de más antiguo a más reciente.
export LC_ALL=C
shopt -s nullglob
copias=()
for directorio in "$raiz"/????????-????; do
    if [[ "${directorio##*/}" =~ ^[0-9]{8}-[0-9]{4}$ && -f "$directorio/.completa" ]]; then
        copias+=("$directorio")
    fi
done
for ((i=0; i<${#copias[@]}-5; i++)); do
    rm -rf -- "${copias[i]}"
    registrar "RETIRADA destino=${copias[i]}"
done

echo "Copia completada: $destino"
