#!/bin/bash
# CP05 (corte de red sin interrupción) y CP06 (resincronización en < 60 s).
# Procedimiento y criterios: docs/10_pruebas.md.
#
# Uso, en la Pi:
#     bash /opt/spui/pruebas/cp05_06.sh [segundos_de_corte]     (300 por defecto)
#
# Antes de correrlo, en el CMS: el reproductor sincronizado, mostrando una
# playlist con al menos una imagen y un video, y con el intervalo de sync en
# 1 minuto. El cliente recién nota el corte en su próximo ciclo: con 5 minutos
# de intervalo, un corte de 5 minutos podría pasar casi sin reintentos.

set -u
DIR=$(dirname "$(readlink -f "$0")")
# shellcheck source=red.sh
source "$DIR/red.sh"

DURACION=${1:-300}
ESPERA_RESYNC=120

trap 'restaurar' EXIT
trap 'echo; echo "Cortado a mano: se restaura la red."; exit 130' INT TERM

encabezado "CP05/CP06"
echo "Corte de $DURACION s. Ctrl+C restaura la red y termina."
echo

T_CORTE=$(ahora)
cortar reject $(( DURACION + 600 )) || exit 1

fin=$(( SECONDS + DURACION ))
while [ $SECONDS -lt $fin ]; do
    resta=$(( fin - SECONDS )); sleep $(( resta < 30 && resta > 0 ? resta : 30 ))
    resta=$(( fin - SECONDS )); [ $resta -lt 0 ] && resta=0
    n=$(log_desde "$T_CORTE" | grep -c "Usando caché de sync")
    echo "  $(date +%T) · reintentos con caché: $n · faltan $resta s"
done

T_REST=$(ahora)
restaurar
echo "Red restaurada a las $(hora "$T_REST"). Esperando el primer sync (hasta $ESPERA_RESYNC s)..."
T_SYNC=$(esperar_log "$T_REST" "Sync OK" "$ESPERA_RESYNC")

# ── Análisis del log ────────────────────────────────────────────────────────
DURANTE=$(log_desde "$T_CORTE" | awk -v fin="$T_REST" '$1 < fin')
T_DETECTA=$(echo "$DURANTE" | grep -E "Usando caché de sync" | head -1 | awk '{print $1}')
REPRODUCCIONES=$(echo "$DURANTE" | grep -cE 'Reproduciendo \[|\[SIM\] (imagen|video|texto|qr|cronograma|youtube)')
ERRORES=$(echo "$DURANTE" | grep -E 'No hay una copia válida|Archivo no cacheado y sin red|Traceback|Error reproduciendo|Sin datos disponibles' )
N_ERRORES=$(printf '%s' "$ERRORES" | grep -c . )
INTERVALOS=$(echo "$DURANTE" | grep "Usando caché de sync" | awk '{print $1}' |
    awk 'NR > 1 { printf "%s%.1f", sep, $1 - prev; sep = ", " } { prev = $1 }')
DISPERSION=$(echo "$DURANTE" | grep "Usando caché de sync" | awk '{print $1}' |
    awk 'NR > 1 { d = $1 - prev; if (min == "" || d < min) min = d; if (d > max) max = d } { prev = $1 }
         END { if (min != "") printf "%.1f", max - min }')

echo
echo "## Resultado"
echo
echo "| Medida | Valor |"
echo "|---|---|"
echo "| Corte | $(hora "$T_CORTE") → $(hora "$T_REST") ($(dif "$T_CORTE" "$T_REST") s) |"
if [ -n "$T_DETECTA" ]; then
    echo "| Primer uso de la caché tras el corte | $(hora "$T_DETECTA") (+$(dif "$T_CORTE" "$T_DETECTA") s) |"
else
    echo "| Primer uso de la caché tras el corte | no ocurrió: el corte fue más corto que el intervalo de sync |"
fi
echo "| Intervalos entre reintentos (s) | ${INTERVALOS:-—} |"
echo "| Diferencia entre el más corto y el más largo | ${DISPERSION:-—} s (con vueltas cortas tiene que ser > 0; ver docs/10 CP05) |"
echo "| Reproducciones registradas durante el corte | $REPRODUCCIONES |"
echo "| Errores de reproducción durante el corte | $N_ERRORES |"
if [ -n "$T_SYNC" ]; then
    LAT=$(dif "$T_REST" "$T_SYNC")
    echo "| CP06: Sync OK tras restaurar | $(hora "$T_SYNC") (**$LAT s**) |"
else
    LAT=""
    echo "| CP06: Sync OK tras restaurar | **no llegó en $ESPERA_RESYNC s** |"
fi

echo
CP05_OK=0
[ "$N_ERRORES" -eq 0 ] && [ "$REPRODUCCIONES" -gt 0 ] && [ -n "$T_DETECTA" ] && CP05_OK=1
CP06_OK=0
[ -n "$LAT" ] && awk -v l="$LAT" 'BEGIN { exit !(l < 60) }' && CP06_OK=1
echo "CP05: $([ $CP05_OK = 1 ] && echo '✅ siguió reproduciendo desde la caché, sin errores' || echo '❌ ver el detalle')"
echo "CP06: $([ $CP06_OK = 1 ] && echo "✅ resincronizó en $LAT s" || echo '❌ no resincronizó en < 60 s')"

if [ "$N_ERRORES" -gt 0 ]; then
    echo; echo "Errores:"; echo "$ERRORES" | cut -d' ' -f4- | head -20
fi
if [ "$REPRODUCCIONES" -eq 0 ]; then
    echo; echo "No se registró ninguna reproducción durante el corte. Si el reproductor no tenía"
    echo "nada programado, la prueba no dice nada: revisar la playlist en el CMS y repetir."
fi
