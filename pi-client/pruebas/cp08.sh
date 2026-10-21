#!/bin/bash
# CP08 — Desconexión abrupta visible en el panel.
# Procedimiento y criterios: docs/10_pruebas.md.
#
# Uso, en la Pi, con el panel de Reproductores del CMS abierto en la PC:
#     bash /opt/spui/pruebas/cp08.sh colgado    # el proceso se congela (SIGSTOP)
#     bash /opt/spui/pruebas/cp08.sh red        # cable cortado: paquetes perdidos
#
# No se usa "matar el proceso": el servicio tiene Restart=always y systemd lo
# levanta a los 10 s, antes de que el panel llegue a notarlo. Congelarlo deja
# las conexiones abiertas pero sin heartbeats, como un equipo colgado; el corte
# con drop es lo que ve el CMS cuando se desenchufa la Pi o se cae el Wi-Fi.
#
# El script toma las horas; vos mirás el panel y apretás Enter cuando cambia.

set -u
DIR=$(dirname "$(readlink -f "$0")")
# shellcheck source=red.sh
source "$DIR/red.sh"

VARIANTE=${1:-}
PID=""

liberar() {
    [ -n "$PID" ] && sudo kill -CONT "$PID" 2> /dev/null
    restaurar
}
trap 'liberar' EXIT
trap 'echo; echo "Cortado a mano: se libera el reproductor."; exit 130' INT TERM

case "$VARIANTE" in
    colgado|red) ;;
    *) echo "Uso: bash $0 colgado | red"; exit 2 ;;
esac

encabezado "CP08 ($VARIANTE)"
echo "Comprobá que el panel muestre el reproductor Conectado y apretá Enter para provocar la caída."
read -r

T_CAIDA=$(ahora)
if [ "$VARIANTE" = colgado ]; then
    PID=$(systemctl show spui -p MainPID --value)
    [ "${PID:-0}" != 0 ] || { echo "El servicio spui no está corriendo."; exit 1; }
    sudo kill -STOP "$PID"
    # Red de seguridad, igual que el corte de red: lo libera solo a los 15 min.
    sudo systemd-run --quiet --collect --unit=spui-prueba-liberar --on-active=900s /bin/kill -CONT "$PID" || true
    echo "Proceso $PID congelado a las $(hora "$T_CAIDA")."
else
    cortar drop 900 || exit 1
fi

echo
echo "Esperado: Desconectado entre 150 y 210 s después del último heartbeat (≤ 60 s antes de la caída)."
echo "Refrescá el panel. Apretá Enter apenas muestre Desconectado."
inicio=$SECONDS
while ! read -r -t 15; do echo "  +$(( SECONDS - inicio )) s"; done
T_PANEL=$(ahora)

T_VUELTA=$(ahora)
if [ "$VARIANTE" = colgado ]; then
    sudo kill -CONT "$PID"; sudo systemctl stop spui-prueba-liberar.timer 2> /dev/null
    echo "Proceso liberado a las $(hora "$T_VUELTA")."
else
    restaurar
    echo "Red restaurada a las $(hora "$T_VUELTA")."
fi
echo "Apretá Enter apenas el panel vuelva a mostrar Conectado."
inicio=$SECONDS
while ! read -r -t 15; do echo "  +$(( SECONDS - inicio )) s"; done
T_CONECTADO=$(ahora)

echo
echo "| Variante | Caída | Desconectado en el panel | Demora | Restaurado | Conectado de nuevo | Demora |"
echo "|---|---|---|---|---|---|---|"
echo "| $VARIANTE | $(hora "$T_CAIDA") | $(hora "$T_PANEL") | **$(dif "$T_CAIDA" "$T_PANEL") s** |" \
     "$(hora "$T_VUELTA") | $(hora "$T_CONECTADO") | $(dif "$T_VUELTA" "$T_CONECTADO") s |"
echo
echo "Completar a mano la hora de 'LWT: caída detectada por el broker' del log del daemon (spui:mqtt:subscribe)."
D=$(dif "$T_CAIDA" "$T_PANEL")
if awk -v d="$D" 'BEGIN { exit !(d < 60) }'; then
    echo "⚠ $D s es imposible con el umbral de 150 s del panel: ¿se apretó Enter antes de tiempo? Repetir."
elif awk -v d="$D" 'BEGIN { exit !(d <= 210) }'; then
    echo "✅ El panel mostró la caída en $D s (dentro de lo esperado por diseño)."
else
    echo "❌ El panel tardó $D s, más que los 210 s esperados por diseño: revisar el daemon (mantenimiento cada 60 s)."
fi
