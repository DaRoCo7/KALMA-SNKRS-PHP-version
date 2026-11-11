#!/usr/bin/env bash
set -u
cd "$(dirname "$0")/.." || exit 1
origen=${KALMA_URL:-https://kalma.local}
origen=${origen%/}
compose=(docker compose)
if [[ -n "${COMPOSE_PROJECT_NAME:-}" ]]; then compose+=(-p "$COMPOSE_PROJECT_NAME"); fi
errores=0
temporal=$(mktemp) || exit 1
trap 'rm -f "$temporal"' EXIT
peticion=(curl --silent --show-error --noproxy "*" --connect-timeout 5 --max-time 15)
if [[ -n "${KALMA_CA_CERT:-}" ]]; then peticion+=(--cacert "$KALMA_CA_CERT"); fi
if codigo=$("${peticion[@]}" -o /dev/null -w "%{http_code}" "$origen/") && [[ "$codigo" == 200 ]]; then
    echo "OK    Portada HTTPS: HTTP 200"
else
    echo "FALLO Portada: $origen/ (HTTP $codigo)" >&2
    errores=1
fi
if codigo=$("${peticion[@]}" -o "$temporal" -w "%{http_code}" "$origen/health") && [[ "$codigo" == 200 ]] && python3 -c 'import json, sys; d=json.load(sys.stdin); sys.exit(0 if isinstance(d, dict) and d.get("app") == "disponible" and d.get("base_datos") == "disponible" else 1)' < "$temporal"; then
    echo "OK    /health: PHP y base de datos disponibles"
else
    echo "FALLO /health: HTTP $codigo; revisa el estado de app y db" >&2
    errores=1
fi
if valor=$("${compose[@]}" exec -T db sh -c 'exec psql -XAt -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT 1"') && [[ "$valor" == 1 ]]; then
    echo "OK    PostgreSQL: SELECT 1"
else
    echo "FALLO PostgreSQL: revisa el servicio db y el proyecto Compose" >&2
    errores=1
fi
exit "$errores"
