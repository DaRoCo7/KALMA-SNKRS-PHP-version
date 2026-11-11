#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

if (( $# < 1 || $# > 2 )); then
    echo "Uso: bash scripts/restaurar.sh AAAAMMDD-HHMM [--produccion]" >&2
    exit 2
fi

fecha=$1
modo=${2:-aislada}
[[ "$fecha" =~ ^[0-9]{8}-[0-9]{4}$ ]] || {
    echo "La copia debe identificarse con AAAAMMDD-HHMM." >&2
    exit 2
}
case "$modo" in
    aislada|--produccion) ;;
    *) echo "Modo desconocido: $modo" >&2; exit 2 ;;
esac

copia="backups/$fecha"
[[ -f "$copia/.completa" && -s "$copia/base.dump" ]] || {
    echo "Copia incompleta o inexistente: $copia" >&2
    exit 1
}
(cd "$copia" && sha256sum -c SHA256SUMS)

compose=(docker compose)
if [[ -n "${COMPOSE_PROJECT_NAME:-}" ]]; then
    compose+=(-p "$COMPOSE_PROJECT_NAME")
fi
db=("${compose[@]}" exec -T db)

usuario=$("${db[@]}" printenv POSTGRES_USER)
principal=$("${db[@]}" printenv POSTGRES_DB)
destino="kalma_restauracion_${fecha/-/_}"
opciones=(--exit-on-error --single-transaction --no-owner --no-privileges)

if [[ "$modo" == '--produccion' ]]; then
    destino=$principal
    echo "Se reemplazarán los objetos incluidos en el volcado dentro de '$destino'."
    read -r -p "Escribe RESTAURAR $destino para continuar: " confirmacion
    [[ "$confirmacion" == "RESTAURAR $destino" ]] || {
        echo "Restauración cancelada."
        exit 1
    }
    opciones+=(--clean --if-exists)
else
    # createdb falla si el destino ya existe y conserva la restauración anterior.
    [[ "$destino" != "$principal" ]] || {
        echo "El destino de ensayo coincide con la base principal." >&2
        exit 1
    }
    "${db[@]}" createdb -U "$usuario" --template=template0 "$destino"
fi

echo "Restaurando $copia/base.dump en $destino"
if ! "${db[@]}" pg_restore -U "$usuario" -d "$destino" \
        "${opciones[@]}" < "$copia/base.dump"; then
    # Solo se elimina la base de ensayo que esta ejecución acaba de crear.
    if [[ "$modo" == 'aislada' ]]; then
        "${db[@]}" dropdb -U "$usuario" "$destino"
    fi
    echo "Ha fallado la restauración de $destino." >&2
    exit 1
fi

echo "Restauración completada: $destino"
