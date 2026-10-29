#!/bin/bash
# SPUI — Espejo remoto de la pantalla (sólo desarrollo)
#
# Transmite lo que la Pi manda por HDMI (imagen y sonido) para mirarlo desde
# la PC por Tailscale, cuando la Pi no está a mano. No es parte del sistema en
# producción y NUNCA arranca solo: se prende y se apaga a mano.
#
#   ./espejo.sh instalar    una vez (sin sudo; lo pide donde hace falta)
#   ./espejo.sh on          prende la transmisión
#   ./espejo.sh probar      con el espejo prendido: tono por el camino copiado
#   ./espejo.sh off         la apaga y deja el sonido como estaba
#   ./espejo.sh estado      qué está prendido y la dirección para abrir
#
# En la PC: VLC → Medio → Abrir ubicación de red → http://<ip_tailscale_de_la_pi>:8090
#
# Cómo llega el sonido. VLC sólo sabe mandarlo a un dispositivo, así que con
# el espejo prendido lo manda a una placa de sonido virtual (snd-aloop) y de
# ahí lo leen dos programas a la vez:
#
#   VLC ─► spui_espejo (dmix) ─► Loopback ─► spui_espejo_captura (dsnoop) ─┬─► alsaloop ─► HDMI
#                                                                          └─► ffmpeg ──► PC
#
# alsaloop es el que hace sonar el monitor y compensa que la placa virtual y
# el HDMI tienen relojes distintos. (La primera versión copiaba a las dos
# placas con el plugin "multi" de ALSA, que no compensa nada: daba underruns
# en cadena, 28/10/2026.) Apagado, el reproductor vuelve exactamente al
# SPUI_AOUT_DEVICE de spui.env, el que se validó en CP04.
#
# Las pruebas de la tesis se corren con el espejo APAGADO: la captura usa CPU
# y la Pi decodifica el video por software (docs/10).

set -euo pipefail

PUERTO=8090
ENV_SPUI=/etc/spui/spui.env
ENV_ESPEJO=/etc/spui/espejo.env
DROPIN_DIR=/etc/systemd/system/spui.service.d
DROPIN=$DROPIN_DIR/espejo.conf
ALSA_CONF=/etc/alsa/conf.d/60-spui-espejo.conf
UNIDAD=/etc/systemd/system/spui-espejo.service
UNIDAD_SONIDO=/etc/systemd/system/spui-espejo-sonido.service
AQUI="$(cd "$(dirname "$0")" && pwd)"

error() { echo "ERROR: $*" >&2; exit 1; }

dispositivo_hdmi() {
    local d
    d=$(sudo grep -E '^SPUI_AOUT_DEVICE=' "$ENV_SPUI" | tail -n1 | cut -d= -f2- | tr -d '"' || true)
    [ -n "$d" ] || error "no hay SPUI_AOUT_DEVICE en $ENV_SPUI (docs/09 §8.6): sin él no se sabe a qué HDMI mandar el sonido"
    echo "$d"
}

ip_tailscale() {
    tailscale ip -4 2>/dev/null | head -n1
}

instalar() {
    [ "$(whoami)" != root ] || error "correlo sin sudo: ./espejo.sh instalar"
    local usuario hdmi
    usuario=$(whoami)
    hdmi=$(dispositivo_hdmi)
    command -v alsaloop >/dev/null || error "falta alsaloop (paquete alsa-utils)"

    echo "[1/3] ffmpeg..."
    sudo apt-get install -y ffmpeg

    echo "[2/3] Dispositivos de sonido del espejo (salida al monitor: $hdmi)..."
    sudo mkdir -p "$(dirname "$ALSA_CONF")"
    # El formato es fijo (48 kHz, 16 bits, estéreo) en las dos puntas de la
    # placa virtual: tienen que coincidir. ipc_perm 0666 porque escriben y
    # leen procesos distintos (el reproductor, alsaloop, ffmpeg, la prueba).
    sudo tee "$ALSA_CONF" > /dev/null <<EOF
# SPUI — espejo remoto (pi-client/remoto/espejo.sh). Sólo define dispositivos
# nuevos: no cambia el "default" ni el HDMI que usa el reproductor normalmente.
pcm.spui_espejo_mezcla {
    type dmix
    ipc_key 52801
    ipc_perm 0666
    slave { pcm "hw:Loopback,0,0"; format S16_LE; rate 48000; channels 2; period_size 1024; buffer_size 8192; }
}
pcm.spui_espejo { type plug; slave.pcm "spui_espejo_mezcla"; }
pcm.spui_espejo_captura {
    type dsnoop
    ipc_key 52802
    ipc_perm 0666
    slave { pcm "hw:Loopback,1,0"; format S16_LE; rate 48000; channels 2; period_size 1024; buffer_size 8192; }
}
pcm.spui_espejo_hdmi { type plug; slave.pcm "$hdmi"; }
EOF

    echo "[3/3] Servicios spui-espejo y spui-espejo-sonido (no se habilitan: nunca arrancan solos)..."
    sudo tee "$UNIDAD_SONIDO" > /dev/null <<EOF
[Unit]
Description=SPUI espejo remoto: sonido de la placa virtual al HDMI (desarrollo)

[Service]
Type=simple
User=$usuario
# -S 1: corrige la diferencia de reloj entre la placa virtual y el HDMI
# agregando o salteando alguna muestra; -t: 100 ms de margen.
ExecStart=/usr/bin/alsaloop -C spui_espejo_captura -P spui_espejo_hdmi -f S16_LE -r 48000 -c 2 -t 100000 -S 1
Restart=always
RestartSec=2
SyslogIdentifier=spui-espejo-sonido
EOF
    sudo tee "$UNIDAD" > /dev/null <<EOF
[Unit]
Description=SPUI espejo remoto de la pantalla (desarrollo)
After=spui.service

[Service]
Type=simple
User=$usuario
Environment=DISPLAY=:0
ExecStart=/bin/bash $AQUI/espejo.sh transmitir
Restart=always
RestartSec=2
SyslogIdentifier=spui-espejo
EOF
    sudo systemctl daemon-reload
    echo
    echo "Listo. Prenderlo: bash $AQUI/espejo.sh on   y después: bash $AQUI/espejo.sh probar"
}

on() {
    [ -f "$UNIDAD" ] && [ -f "$UNIDAD_SONIDO" ] && grep -q dsnoop "$ALSA_CONF" 2>/dev/null \
        || error "falta instalar (o es la versión anterior): bash $AQUI/espejo.sh instalar"
    [ -n "$(ip_tailscale)" ] || error "Tailscale no está conectado (tailscale status): la transmisión sólo escucha por ahí"
    sudo modprobe snd-aloop
    echo 'SPUI_AOUT_DEVICE=spui_espejo' | sudo tee "$ENV_ESPEJO" > /dev/null
    sudo mkdir -p "$DROPIN_DIR"
    # Un EnvironmentFile de un drop-in se lee después del de la unidad, así
    # que este SPUI_AOUT_DEVICE pisa al de spui.env sólo mientras exista.
    printf '[Service]\nEnvironmentFile=%s\n' "$ENV_ESPEJO" | sudo tee "$DROPIN" > /dev/null
    sudo systemctl daemon-reload
    # Primero el reproductor (suelta el HDMI), después alsaloop (lo toma).
    sudo systemctl restart spui
    sudo systemctl start spui-espejo-sonido spui-espejo
    sleep 2
    systemctl is-active --quiet spui-espejo-sonido \
        || error "el sonido al monitor no arrancó: journalctl -u spui-espejo-sonido -n 30 --no-pager (y después: espejo.sh off)"
    echo "Espejo prendido. En la PC, VLC: http://$(ip_tailscale):$PUERTO"
    echo "Probar el sonido: bash $AQUI/espejo.sh probar · Apagarlo: bash $AQUI/espejo.sh off"
}

off() {
    sudo systemctl stop spui-espejo spui-espejo-sonido 2>/dev/null || true
    sudo rm -f "$DROPIN" "$ENV_ESPEJO"
    sudo systemctl daemon-reload
    sudo systemctl restart spui
    # Puede tardar en soltarse si algo la tiene abierta; no es grave que quede.
    sleep 1; sudo modprobe -r snd-aloop 2>/dev/null || true
    echo "Espejo apagado: el reproductor volvió al SPUI_AOUT_DEVICE de spui.env."
}

estado() {
    echo "Transmisión:  $(systemctl is-active spui-espejo 2>/dev/null || true)"
    echo "Sonido:       $([ -f "$DROPIN" ] && echo "copiado (alsaloop: $(systemctl is-active spui-espejo-sonido 2>/dev/null || true))" || echo 'normal (spui.env)')"
    echo "Tailscale:    $(ip_tailscale || true)"
    [ -f "$DROPIN" ] && echo "Abrir en VLC: http://$(ip_tailscale):$PUERTO"
    true
}

probar() {
    [ -f "$DROPIN" ] || error "primero prendé el espejo: bash $AQUI/espejo.sh on"
    echo "Tono por el camino del espejo: tiene que sonar en el monitor (y en la PC si VLC está abierto)."
    # Una vuelta por los dos canales, ~5 s en total. Sólo se muestran las
    # últimas líneas: si algo falla, el error queda al final.
    timeout 15 speaker-test -D spui_espejo -c 2 -t sine -f 440 -l 1 2>&1 | tail -n 6
}

# Lo corre el servicio spui-espejo, no una persona.
transmitir() {
    local ip tam xauth codec
    ip=$(ip_tailscale)
    [ -n "$ip" ] || error "sin IP de Tailscale"
    xauth=$(ls -t /tmp/serverauth.* 2>/dev/null | head -n1 || true)
    [ -n "$xauth" ] && export XAUTHORITY="$xauth"
    tam=$(xdpyinfo 2>/dev/null | awk '/dimensions:/{print $2}')
    [ -n "$tam" ] || error "no se pudo leer la pantalla :0 (¿X11 arriba?)"
    # Codificador por hardware de la Pi 4 si está; si no, x264 lo más liviano.
    if ffmpeg -hide_banner -encoders 2>/dev/null | grep -q h264_v4l2m2m; then
        codec=(-c:v h264_v4l2m2m -b:v 2M)
    else
        codec=(-c:v libx264 -preset ultrafast -tune zerolatency -b:v 2M)
    fi
    # -listen 1: ffmpeg atiende a un visor por vez; cuando el visor se cierra,
    # ffmpeg termina y systemd lo vuelve a dejar esperando en 2 s.
    exec nice -n 10 ffmpeg -hide_banner -loglevel warning \
        -thread_queue_size 512 -f x11grab -framerate 12 -video_size "$tam" -i :0 \
        -thread_queue_size 512 -f alsa -ac 2 -ar 48000 -i spui_espejo_captura \
        -vf 'scale=1280:-2,format=yuv420p' "${codec[@]}" -g 24 \
        -c:a aac -b:a 128k \
        -f mpegts -listen 1 "http://$ip:$PUERTO"
}

case "${1:-}" in
    instalar|on|off|estado|probar|transmitir) "$1" ;;
    *) sed -n '2,30p' "$0"; exit 1 ;;
esac
