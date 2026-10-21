#!/bin/bash
# CP07 — Un archivo alterado o una descarga interrumpida no entran al caché.
# Procedimiento y criterios: docs/10_pruebas.md.
#
# Uso, en la Pi:
#     bash /opt/spui/pruebas/cp07.sh alterado [archivo]   # variante A
#     bash /opt/spui/pruebas/cp07.sh descarga             # variante B
#
# A: altera un archivo del caché (agrega un byte) y espera a que el cliente lo
#    descarte al ir a reproducirlo y lo vuelva a bajar. Sin [archivo] ofrece la
#    lista. Tiene que ser uno de la playlist que se está mostrando.
# B: espera a que empiece una descarga (subir un video grande al CMS y
#    agregarlo a la playlist de esta pantalla mientras el script espera), corta
#    la red en medio de la descarga y comprueba que el archivo no quedó con su
#    nombre final. Después restaura y espera a que la descarga se complete.

set -u
DIR=$(dirname "$(readlink -f "$0")")
# shellcheck source=red.sh
source "$DIR/red.sh"

VARIANTE=${1:-}
CACHE=$(leer_env SPUI_CACHE_DIR); CACHE=${CACHE:-/var/cache/spui}
MEDIA="$CACHE/media"
[ -d "$MEDIA" ] || { echo "No existe $MEDIA (SPUI_CACHE_DIR en $SPUI_ENV)."; exit 1; }

trap 'restaurar' EXIT
trap 'echo; echo "Cortado a mano: se restaura la red."; exit 130' INT TERM

variante_alterado() {
    local archivo=${1:-} t0 t_detecta t_baja h0 h1 limite=900
    if [ -z "$archivo" ]; then
        echo "Archivos en $MEDIA (el más reciente primero):"
        mapfile -t lista < <(ls -t "$MEDIA" | grep -v '\.part$')
        [ ${#lista[@]} -gt 0 ] || { echo "El caché está vacío."; exit 1; }
        local i; for i in "${!lista[@]}"; do printf '  %2d) %s\n' $(( i + 1 )) "${lista[$i]}"; done
        read -r -p "Número de un archivo de la playlist actual: " i
        archivo=${lista[$(( i - 1 ))]:-}
    fi
    [ -f "$MEDIA/$archivo" ] || { echo "No existe $MEDIA/$archivo"; exit 1; }

    encabezado "CP07 A (archivo alterado)"
    h0=$(sha256sum "$MEDIA/$archivo" | cut -d' ' -f1)
    t0=$(ahora)
    printf 'x' | sudo tee -a "$MEDIA/$archivo" > /dev/null
    echo "Alterado $archivo a las $(hora "$t0"). Esperando a que le toque reproducirse (hasta $limite s)..."

    t_detecta=$(esperar_log "$t0" "hash distinto\).*$archivo" "$limite")
    if [ -z "$t_detecta" ]; then
        echo "❌ En $limite s el cliente no informó el archivo alterado."
        echo "   ¿Es de la playlist actual? Si no se reproduce, nunca se verifica."
        exit 1
    fi
    echo "Descartado a las $(hora "$t_detecta"). Esperando la descarga nueva..."
    t_baja=$(esperar_log "$t_detecta" "Descargando media: $archivo" 300)
    sleep 5
    h1=$(sha256sum "$MEDIA/$archivo" 2> /dev/null | cut -d' ' -f1)

    echo
    echo "| Archivo | Alterado | Descartado (log) | Vuelto a bajar | Hash final = original |"
    echo "|---|---|---|---|---|"
    local bajado=no; [ -n "$t_baja" ] && bajado=$(hora "$t_baja")
    echo "| $archivo | $(hora "$t0") | $(hora "$t_detecta") (+$(dif "$t0" "$t_detecta") s) |" \
         "$bajado | $([ "$h1" = "$h0" ] && echo sí || echo NO) |"
    echo
    if [ "$h1" = "$h0" ]; then
        echo "✅ La copia alterada se descartó antes de reproducirse y se reemplazó por la original."
    else
        echo "❌ El archivo del caché no coincide con el original (¿no hubo red para bajarlo?)."
    fi
}

variante_descarga() {
    local t0 t_desc linea archivo limite=1800 t_rest t_ok final_corte part_corte
    encabezado "CP07 B (descarga interrumpida)"
    t0=$(ahora)
    echo "Esperando que empiece una descarga (hasta $limite s)."
    echo "Ahora: subí un video grande al CMS y agregalo a la playlist de esta pantalla."
    t_desc=$(esperar_log "$t0" "Descargando media: " "$limite") || { echo "No empezó ninguna descarga."; exit 1; }
    linea=$(log_desde "$t0" | grep "Descargando media: " | head -1)
    archivo=${linea##*Descargando media: }
    sleep 1
    cortar reject 900 || exit 1
    sleep 5
    [ -f "$MEDIA/$archivo" ] && final_corte=sí || final_corte=no
    [ -f "$MEDIA/$archivo.part" ] && part_corte=sí || part_corte=no
    echo "Durante el corte: nombre final presente=$final_corte · .part presente=$part_corte"
    echo "60 s con la red cortada..."
    sleep 60
    local errores; errores=$(log_desde "$t_desc" | grep -cE "Traceback")

    t_rest=$(ahora)
    restaurar
    echo "Red restaurada a las $(hora "$t_rest"). Esperando a que se complete la descarga (hasta 600 s)..."
    t_ok=""
    local fin=$(( SECONDS + 600 ))
    while [ $SECONDS -lt $fin ]; do
        if [ -f "$MEDIA/$archivo" ] && [ ! -f "$MEDIA/$archivo.part" ]; then t_ok=$(ahora); break; fi
        sleep 2
    done

    echo
    echo "| Archivo | Descarga empezó | Corte | Nombre final durante el corte | Excepciones sin manejar | Completo tras restaurar |"
    echo "|---|---|---|---|---|---|"
    local completo=no; [ -n "$t_ok" ] && completo="$(hora "$t_ok") (+$(dif "$t_rest" "$t_ok") s)"
    echo "| $archivo | $(hora "$t_desc") | $(hora "$t_desc") + 1 s | $final_corte | $errores | $completo |"
    echo
    if [ "$final_corte" = no ] && [ -n "$t_ok" ] && [ "$errores" -eq 0 ]; then
        echo "✅ La descarga cortada nunca quedó con el nombre que usa el player, y se completó al volver la red."
    else
        echo "❌ Ver el detalle de la tabla."
    fi
    echo "Si la descarga terminó antes del corte (archivo chico o red rápida), la prueba no vale: usar un archivo más grande."
}

case "$VARIANTE" in
    alterado) variante_alterado "${2:-}" ;;
    descarga) variante_descarga ;;
    *) echo "Uso: bash $0 alterado [archivo] | descarga"; exit 2 ;;
esac
