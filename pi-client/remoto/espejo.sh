#!/bin/bash
# SPUI — Espejo remoto de la pantalla (sólo desarrollo)
#
# Transmite lo que la Pi manda por HDMI (imagen y sonido) para mirarlo desde
# la PC por Tailscale, cuando la Pi no está a mano. No es parte del sistema en
# producción y NUNCA arranca solo: se prende y se apaga a mano.
#
#   ./espejo.sh instalar    una vez (sin sudo; lo pide donde hace falta)
#   ./espejo.sh on          prende la transmisión
#   ./espejo.sh off         la apaga y deja el sonido como estaba
#   ./espejo.sh estado      qué está prendido y la dirección para abrir
#   ./espejo.sh probar      tono de prueba por el camino copiado
#
# En la PC: VLC → Medio → Abrir ubicación de red → http://<ip_tailscale_de_la_pi>:8090
#
# Cómo llega el sonido: VLC sólo sabe mandarlo a un dispositivo. Con el espejo
# prendido, el reproductor usa "spui_espejo", un dispositivo ALSA que copia
# cada muestra al HDMI de siempre y a una placa de sonido virtual (snd-aloop),
# de donde lo toma ffmpeg. Apagado, el reproductor vuelve exactamente al
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
AQUI="$(cd "$(dirname "$0")" && pwd)"

error() { echo "ERROR: $*" >&2; exit 1; }

dispositivo_hdmi() {
    local d
    d=$(sudo grep -E '^SPUI_AOUT_DEVICE=' "$ENV_SPUI" | tail -n1 | cut -d= -f2- | tr -d '"' || true)
    [ -n "$d" ] || error "no hay SPUI_AOUT_DEVICE en $ENV_SPUI (docs/09 §8.6): sin él no se sabe a qué HDMI copiar"
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

    echo "[1/3] ffmpeg..."
    sudo apt-get install -y ffmpeg

    echo "[2/3] Dispositivo de sonido copiado ($hdmi + placa virtual)..."
    sudo mkdir -p "$(dirname "$ALSA_CONF")"
    sudo tee "$ALSA_CONF" > /dev/null <<EOF
# SPUI — espejo remoto (pi-client/remoto/espejo.sh). Sólo define dispositivos
# nuevos: no cambia el "default" ni el HDMI que usa el reproductor normalmente.
pcm.spui_espejo_hdmi { type plug; slave.pcm "$hdmi"; }
# Formato fijo: las dos puntas de la placa virtual tienen que coincidir, y la
# otra la abre ffmpeg en 48 kHz / 16 bits / estéreo.
pcm.spui_espejo_loop {
    type plug
    slave { pcm "hw:Loopback,0,0"; format S16_LE; rate 48000; channels 2; }
}
pcm.spui_espejo_multi {
    type multi
    slaves.a.pcm "spui_espejo_hdmi"
    slaves.a.channels 2
    slaves.b.pcm "spui_espejo_loop"
    slaves.b.channels 2
    bindings.0 { slave a; channel 0; }
    bindings.1 { slave a; channel 1; }
    bindings.2 { slave b; channel 0; }
    bindings.3 { slave b; channel 1; }
}
pcm.spui_espejo_ruta {
    type route
    slave.pcm "spui_espejo_multi"
    slave.channels 4
    ttable.0.0 1
    ttable.1.1 1
    ttable.0.2 1
    ttable.1.3 1
}
pcm.spui_espejo { type plug; slave.pcm "spui_espejo_ruta"; }
EOF

    echo "[3/3] Servicio spui-espejo (no se habilita: nunca arranca solo)..."
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
    echo "Listo. Probar el sonido copiado:  $AQUI/espejo.sh probar"
}

on() {
    [ -f "$UNIDAD" ] && [ -f "$ALSA_CONF" ] || error "falta instalar: $AQUI/espejo.sh instalar"
    [ -n "$(ip_tailscale)" ] || error "Tailscale no está conectado (tailscale status): la transmisión sólo escucha por ahí"
    sudo modprobe snd-aloop
    echo 'SPUI_AOUT_DEVICE=spui_espejo' | sudo tee "$ENV_ESPEJO" > /dev/null
    sudo mkdir -p "$DROPIN_DIR"
    # Un EnvironmentFile de un drop-in se lee después del de la unidad, así
    # que este SPUI_AOUT_DEVICE pisa al de spui.env sólo mientras exista.
    printf '[Service]\nEnvironmentFile=%s\n' "$ENV_ESPEJO" | sudo tee "$DROPIN" > /dev/null
    sudo systemctl daemon-reload
    sudo systemctl restart spui
    sudo systemctl start spui-espejo
    echo "Espejo prendido. En la PC, VLC: http://$(ip_tailscale):$PUERTO"
    echo "Para apagarlo: $AQUI/espejo.sh off"
}

off() {
    sudo systemctl stop spui-espejo 2>/dev/null || true
    sudo rm -f "$DROPIN" "$ENV_ESPEJO"
    sudo systemctl daemon-reload
    sudo systemctl restart spui
    # Puede tardar en soltarse si algo la tiene abierta; no es grave que quede.
    sleep 1; sudo modprobe -r snd-aloop 2>/dev/null || true
    echo "Espejo apagado: el reproductor volvió al SPUI_AOUT_DEVICE de spui.env."
}

estado() {
    echo "Transmisión:  $(systemctl is-active spui-espejo 2>/dev/null || true)"
    echo "Sonido:       $([ -f "$DROPIN" ] && echo 'copiado (spui_espejo)' || echo 'normal (spui.env)')"
    echo "Tailscale:    $(ip_tailscale || true)"
    [ -f "$DROPIN" ] && echo "Abrir en VLC: http://$(ip_tailscale):$PUERTO"
    true
}

probar() {
    sudo modprobe snd-aloop
    echo "Tono de 2 s por el dispositivo copiado: tiene que sonar en el monitor."
    speaker-test -D spui_espejo -c2 -t sine -f 440 -l 1 -p 2 || error "el dispositivo copiado no abrió: el espejo andaría sin sonido (pasame la salida)"
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
        -thread_queue_size 512 -f alsa -ac 2 -ar 48000 -i hw:Loopback,1,0 \
        -vf 'scale=1280:-2,format=yuv420p' "${codec[@]}" -g 24 \
        -c:a aac -b:a 128k \
        -f mpegts -listen 1 "http://$ip:$PUERTO"
}

case "${1:-}" in
    instalar|on|off|estado|probar|transmitir) "$1" ;;
    *) sed -n '2,25p' "$0"; exit 1 ;;
esac
