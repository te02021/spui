# Funciones comunes de las pruebas de docs/10_pruebas.md. No se ejecuta solo:
# lo cargan cp05_06.sh, cp07.sh y cp08.sh con "source".
#
# El corte de red no apaga la interfaz: bloquea sólo las conexiones SALIENTES
# de la Pi hacia el CMS y el broker (80, 443, 1883, 8883). El SSH sigue
# andando aunque venga de la misma PC que el CMS, porque sus respuestas salen
# del puerto 22 y no hacia esos puertos.
#
# Red de seguridad: cada corte programa además su propia restauración con un
# temporizador de systemd. Si el script se corta, se cierra la sesión SSH o
# algo sale mal, la red vuelve sola. Un reinicio de la Pi también la borra:
# la regla vive sólo en memoria.

SPUI_ENV=/etc/spui/spui.env
TABLA_PRUEBA=spui_prueba
UNIDAD_RESTAURAR=spui-prueba-restaurar

leer_env() {
    sudo grep -E "^$1=" "$SPUI_ENV" | tail -1 | cut -d= -f2- | tr -d "\"'"
}

_host_de_url() {
    echo "$1" | sed -E 's#^[a-zA-Z]+://##; s#[/:].*$##'
}

# IPs del CMS y del broker, tal como las resuelve la Pi (su /etc/hosts).
ips_destino() {
    local h
    for h in "$(_host_de_url "$(leer_env SPUI_API_URL)")" "$(leer_env MQTT_HOST)"; do
        [ -n "$h" ] && getent ahostsv4 "$h" | awk '{print $1}'
    done | sort -u
}

# cortar reject|drop [segundos_maximos]
#   reject: la conexión falla en el acto (como un servidor caído).
#   drop:   los paquetes se pierden sin respuesta (como un cable cortado).
cortar() {
    local modo=$1 maximo=${2:-900} ips verbo
    command -v nft > /dev/null || { echo "ERROR: falta nft (sudo apt install nftables)."; return 1; }
    ips=$(ips_destino | paste -sd, -)
    [ -n "$ips" ] || { echo "ERROR: no se pudo resolver la IP del CMS ni del broker."; return 1; }
    restaurar
    if [ "$modo" = drop ]; then verbo=drop; else verbo='reject with tcp reset'; fi
    sudo nft add table inet "$TABLA_PRUEBA" &&
    sudo nft "add chain inet $TABLA_PRUEBA salida { type filter hook output priority 0 ; }" &&
    sudo nft "add rule inet $TABLA_PRUEBA salida ip daddr { $ips } tcp dport { 80, 443, 1883, 8883 } $verbo" || {
        echo "ERROR: no se pudo instalar el bloqueo."; restaurar; return 1; }
    sudo systemd-run --quiet --collect --unit="$UNIDAD_RESTAURAR" --on-active="${maximo}s" \
        /usr/sbin/nft delete table inet "$TABLA_PRUEBA" ||
        echo "AVISO: no se pudo programar la restauración automática. Si el script se corta: sudo nft delete table inet $TABLA_PRUEBA"
    echo "Red cortada ($modo) hacia $ips a las $(date +%T). Se restaura sola en $maximo s si nada lo hace antes."
}

restaurar() {
    sudo nft delete table inet "$TABLA_PRUEBA" 2> /dev/null
    sudo systemctl stop "$UNIDAD_RESTAURAR.timer" 2> /dev/null
    true
}

red_cortada() {
    sudo nft list table inet "$TABLA_PRUEBA" > /dev/null 2>&1
}

# Hora actual con decimales (epoch), para comparar con journalctl -o short-unix.
ahora() { date +%s.%N; }

# log_desde EPOCH: líneas del servicio desde ese instante, con la hora en epoch
# como primer campo.
log_desde() {
    journalctl -u spui -o short-unix --no-pager --since "@${1%.*}" |
        awk -v t="$1" '$1 >= t'
}

# esperar_log EPOCH REGEX SEGUNDOS: espera la primera línea que coincida y
# devuelve su hora (epoch). Vacío si no apareció a tiempo.
esperar_log() {
    local desde=$1 patron=$2 limite=$(( SECONDS + $3 )) t
    while [ $SECONDS -lt $limite ]; do
        t=$(log_desde "$desde" | grep -E -- "$patron" | head -1 | awk '{print $1}')
        [ -n "$t" ] && { echo "$t"; return 0; }
        sleep 1
    done
    return 1
}

# dif A B: B - A en segundos, con un decimal.
dif() { awk -v a="$1" -v b="$2" 'BEGIN { printf "%.1f", b - a }'; }

hora() { date -d "@${1%.*}" +%T; }

version_cliente() {
    journalctl -u spui -o cat --no-pager -n 2000 | grep "SPUI Client iniciado" | tail -1 |
        sed -E 's/.*\(versión ([^)]*)\).*/\1/'
}

encabezado() {
    local v; v=$(version_cliente)
    echo "$1 — $(date '+%d/%m/%Y %H:%M:%S') · $(hostname) · versión ${v:-desconocida}"
}
