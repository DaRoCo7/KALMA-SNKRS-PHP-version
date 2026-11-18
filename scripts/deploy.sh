#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

modo=${1:-produccion}
for programa in docker curl; do
    command -v "$programa" >/dev/null || { echo "Falta $programa" >&2; exit 1; }
done
docker compose version >/dev/null
docker info >/dev/null

: "${APP_VERSION:?Indica APP_VERSION antes de ejecutar el despliegue}"
export APP_VERSION

compose=(docker compose -f compose.yaml)
case "$modo" in
    desarrollo)
        compose+=(-p "${COMPOSE_PROJECT_NAME:-kalma-php-actual}" -f compose.dev.yaml)
        origen=https://localhost:8443
        ;;
    produccion)
        origen=https://kalma.local
        ;;
    *)
        echo "Uso: bash scripts/deploy.sh [desarrollo|produccion]" >&2
        exit 2
        ;;
esac

for archivo in docker/nginx/certs/kalma.local.pem docker/nginx/certs/kalma.local-key.pem; do
    [[ -s "$archivo" ]] || { echo "Falta $archivo" >&2; exit 1; }
done

"${compose[@]}" config --quiet
echo "Construyendo las imágenes de KALMA con versión $APP_VERSION"
"${compose[@]}" build app proxy
"${compose[@]}" up -d --wait --wait-timeout 120 db

# Cada migración y su registro se confirman en la misma transacción.
# El bloqueo evita que dos despliegues apliquen a la vez el mismo archivo.
shopt -s nullglob
export LC_ALL=C
for archivo in database/migraciones/*.sql; do
    nombre=${archivo##*/}
    [[ "$nombre" =~ ^[a-zA-Z0-9_.-]+$ ]] || {
        echo "Nombre de migración inválido: $nombre" >&2
        exit 1
    }

    # Los SQL del proyecto llevan BEGIN; y COMMIT; exteriores.
    # Se comprueban antes de sustituirlos por la transacción del ejecutor.
    primera=$(awk 'NF {print; exit}' "$archivo")
    ultima=$(awk 'NF {linea=$0} END {print linea}' "$archivo")
    if [[ "$primera" != 'BEGIN;' || "$ultima" != 'COMMIT;' ]]; then
        echo "Revisa BEGIN; y COMMIT; en $archivo" >&2
        exit 1
    fi

    {
        cat <<'SQL'
BEGIN;
SELECT pg_advisory_xact_lock(853, 0);
CREATE TABLE IF NOT EXISTS public.migraciones_aplicadas (
    nombre TEXT PRIMARY KEY,
    aplicada_en TIMESTAMPTZ NOT NULL DEFAULT now()
);
SELECT NOT EXISTS (
    SELECT 1 FROM public.migraciones_aplicadas WHERE nombre = :'nombre'
) AS pendiente \gset
\if :pendiente
\echo Aplicando :nombre
SQL
        # Elimina solo la primera y la última línea con contenido.
        awk 'NF {if (previa != "") print previa; previa=$0}' "$archivo" | sed '1d'
        cat <<'SQL'
INSERT INTO public.migraciones_aplicadas (nombre) VALUES (:'nombre');
\else
\echo Ya aplicada: :nombre
\endif
COMMIT;
SQL
    } | "${compose[@]}" exec -T db sh -c \
        'exec psql -X -q -v ON_ERROR_STOP=1 -v nombre="$1" -U "$POSTGRES_USER" -d "$POSTGRES_DB"' \
        sh "$nombre"
done

"${compose[@]}" up -d --wait --wait-timeout 120 app proxy

curl_args=(--silent --show-error --noproxy '*' --max-time 10)
if [[ -n "${KALMA_CA_CERT:-}" ]]; then
    curl_args+=(--cacert "$KALMA_CA_CERT")
fi
if [[ "$(curl --version)" == *Schannel* ]]; then
    curl_args+=(--ssl-revoke-best-effort)
fi

codigo=$(curl "${curl_args[@]}" --output /dev/null --write-out '%{http_code}' "$origen/health")
[[ "$codigo" == 200 ]] || { echo "/health devolvió HTTP $codigo" >&2; exit 1; }
echo "KALMA $APP_VERSION disponible en $origen · /health HTTP $codigo"
