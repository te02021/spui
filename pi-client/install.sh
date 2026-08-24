#!/bin/bash
# SPUI Pi Client — Script de instalación para Raspberry Pi
# NOTA Pi-only: Ejecutar como el usuario configurado en el SO (no root),
# con sudo disponible. NO usar "sudo ./install.sh" — el script invoca
# sudo internamente donde hace falta y necesita detectar tu usuario real.
#
# Uso:
#   chmod +x install.sh
#   ./install.sh
#
# Qué hace:
#   1. Instala VLC y Python vía apt
#   2. Instala dependencias Python
#   3. Copia el cliente a /opt/spui (con dueño = tu usuario)
#   4. Crea /etc/spui/spui.env (editar antes de iniciar el servicio)
#   5. Instala y habilita el servicio systemd (corre como tu usuario)

set -euo pipefail

INSTALL_DIR="/opt/spui"
ENV_FILE="/etc/spui/spui.env"
SERVICE_SRC="$(dirname "$0")/spui.service"

# Usuario/grupo real que va a ejecutar el servicio. Bookworm ya no crea
# un usuario "pi" fijo — es el que se configuró al grabar la SD (Imager).
RUN_USER="$(whoami)"
RUN_GROUP="$(id -gn "$RUN_USER")"

if [ "$RUN_USER" = "root" ]; then
    echo "ERROR: no ejecutes este script con sudo/como root."
    echo "Corré: ./install.sh (sin sudo) — el script pide sudo cuando lo necesita."
    exit 1
fi

echo "Instalando para el usuario: $RUN_USER (grupo: $RUN_GROUP)"

echo "=== SPUI Pi Client — Instalador ==="
echo

# 1. Sistema
echo "[1/5] Instalando VLC y Python..."
sudo apt-get update -qq

# vlc-plugin-base va explícito: en Raspberry Pi OS Lite el paquete "vlc" no
# siempre arrastra los módulos de SALIDA DE VÍDEO (el sistema base no tiene
# entorno gráfico). Sin ellos VLC abre el archivo, no encuentra dónde dibujarlo
# y lo cierra al instante: el estado queda en "Ended" y la pantalla en negro,
# sin ningún error que lo explique.
#
# x11-utils trae xdpyinfo, que usa diagnostico.py para comprobar el display.
sudo apt-get install -y \
    vlc vlc-plugin-base \
    x11-utils \
    python3 python3-pip python3-venv

# Módulos de salida para X11 (xcb_x11/xcb_xv). Sin ellos quedan sólo salidas
# para Wayland o consola (drm_vout, egl_wl…) y la pantalla queda en negro
# aunque VLC informe que está reproduciendo.
# El paquete no existe con ese nombre en todas las versiones de Debian, así que
# no se usa -y a secas: si no está disponible, se sigue sin romper la instalación
# (la verificación de abajo avisa si realmente faltan los módulos).
sudo apt-get install -y vlc-plugin-video-output 2>/dev/null \
    || echo "  (vlc-plugin-video-output no disponible en este repo — se verifica abajo)"

# Verificación: sin módulos de salida de vídeo para X11 el reproductor no puede
# mostrar nada. Se avisa acá y no cuando la pantalla ya está en negro en el pasillo.
if ls /usr/lib/*/vlc/plugins/video_output/libxcb_*.so >/dev/null 2>&1 \
   || ls /usr/lib/vlc/plugins/video_output/libxcb_*.so >/dev/null 2>&1; then
    echo "  OK: VLC tiene salida de vídeo para X11 (xcb)."
else
    echo
    echo "  ADVERTENCIA: VLC no tiene módulos de salida para X11 (xcb_x11/xcb_xv)."
    echo "  El cliente va a sincronizar pero la pantalla quedará en negro."
    echo "  Probá:"
    echo "    sudo apt install --reinstall vlc-plugin-base"
    echo "    sudo apt install vlc-plugin-qt"
    echo
fi

# 2. Python deps
echo "[2/5] Instalando dependencias Python..."
if [ ! -d "$INSTALL_DIR/venv" ]; then
    sudo python3 -m venv "$INSTALL_DIR/venv"
fi
sudo "$INSTALL_DIR/venv/bin/pip" install --quiet -r "$(dirname "$0")/requirements.txt"

# 3. Copiar cliente
echo "[3/5] Copiando cliente a $INSTALL_DIR..."
sudo mkdir -p "$INSTALL_DIR"
sudo cp -r "$(dirname "$0")"/* "$INSTALL_DIR/"
sudo chown -R "$RUN_USER:$RUN_GROUP" "$INSTALL_DIR"

# 4. Crear archivo de entorno
echo "[4/5] Creando $ENV_FILE..."
sudo mkdir -p /etc/spui
if [ ! -f "$ENV_FILE" ]; then
    sudo tee "$ENV_FILE" > /dev/null << 'EOF'
# SPUI Pi Client — Variables de entorno
# EDITAR ANTES DE INICIAR EL SERVICIO

# URL del CMS (sin barra final)
SPUI_API_URL=http://TU_SERVIDOR/api/spui

# API Key del nodo (generada desde el CMS al crear el nodo)
SPUI_API_KEY=PEGAR_API_KEY_AQUI

# MQTT (mismo host que el CMS generalmente)
MQTT_HOST=127.0.0.1
MQTT_PORT=1883

# Salt para la credencial MQTT. TIENE QUE SER IDÉNTICO al SPUI_MQTT_SALT del
# CMS (está en su .env.local.php): la contraseña con la que este equipo se
# conecta al broker se deriva de sha256(SPUI_API_KEY + este salt).
# Si no coinciden, el broker rechaza la conexión y se pierden las alertas de
# emergencia — el sync y el heartbeat siguen andando por HTTP, así que la
# pantalla no se queda en negro y el fallo pasa desapercibido.
SPUI_MQTT_SALT=PEGAR_SALT_DEL_CMS_AQUI

# Intervalos en segundos
SPUI_SYNC_INTERVAL=300
SPUI_HEARTBEAT_INTERVAL=60

# Rutas
SPUI_CACHE_DIR=/var/cache/spui
SPUI_LOG_FILE=/var/log/spui-client.log

# Nivel de log: DEBUG | INFO | WARNING | ERROR
SPUI_LOG_LEVEL=INFO

# Salida de vídeo de VLC. Por defecto xcb_x11 (X11 clásica, la más compatible).
# Sólo cambiarlo si la pantalla queda en negro aunque el log diga "VLC listo":
# alternativas xcb_xv, gl, glx.
# SPUI_VOUT=xcb_x11
EOF
    sudo chmod 640 "$ENV_FILE"
    echo "  IMPORTANTE: editar $ENV_FILE con los valores reales antes de iniciar."
else
    echo "  $ENV_FILE ya existe — no sobreescrito."
fi

# Crear directorios de caché y logs con permisos para el usuario
sudo mkdir -p /var/cache/spui /var/cache/spui/media
sudo chown -R "$RUN_USER:$RUN_GROUP" /var/cache/spui
sudo touch /var/log/spui-client.log
sudo chown "$RUN_USER:$RUN_GROUP" /var/log/spui-client.log

# 5. Servicio systemd
echo "[5/5] Instalando servicio systemd..."
# Sustituye User=/Group= del template por el usuario real detectado arriba, y
# el UID en XDG_RUNTIME_DIR (el template trae 1000, que es el habitual pero no
# siempre el correcto).
RUN_UID="$(id -u "$RUN_USER")"
RUN_HOME="$(getent passwd "$RUN_USER" | cut -d: -f6)"

# Caché de shaders de Mesa: fuera del home del usuario para que el servicio
# pueda escribirla sin permisos especiales.
sudo mkdir -p /var/cache/spui/.cache
sudo chown -R "$RUN_USER:$RUN_GROUP" /var/cache/spui

sed -e "s/^User=.*/User=$RUN_USER/" \
    -e "s/^Group=.*/Group=$RUN_GROUP/" \
    -e "s#^Environment=XDG_RUNTIME_DIR=.*#Environment=XDG_RUNTIME_DIR=/run/user/$RUN_UID#" \
    -e "s#^Environment=HOME=.*#Environment=HOME=$RUN_HOME#" \
    "$SERVICE_SRC" | sudo tee /etc/systemd/system/spui.service > /dev/null
sudo systemctl daemon-reload

# disable antes de enable: si el WantedBy cambió respecto de una instalación
# anterior, "enable" a secas NO borra el enlace viejo y el servicio sigue
# colgado del target anterior. Pasó al migrar de graphical.target (que en Pi OS
# Lite nunca se alcanza) a multi-user.target: el servicio quedaba en
# "inactive (dead)" después de cada reinicio, sin ningún error.
sudo systemctl disable spui 2>/dev/null || true
sudo systemctl enable spui

# 6. Verificación: que el intérprete del servicio tenga todas las dependencias.
# Sin esto, un error de dependencias recién aparecería como un restart-loop de
# systemd, que es mucho más difícil de diagnosticar.
echo
echo "Verificando dependencias en el entorno virtual..."
if sudo "$INSTALL_DIR/venv/bin/python3" -c \
    "import requests, paho.mqtt.client, psutil, vlc" 2>/dev/null; then
    echo "  OK: requests, paho-mqtt, psutil y python-vlc disponibles."
else
    echo "  ADVERTENCIA: falta alguna dependencia en $INSTALL_DIR/venv."
    echo "  Detalle:"
    sudo "$INSTALL_DIR/venv/bin/python3" -c "import requests, paho.mqtt.client, psutil, vlc" || true
    echo "  El servicio va a fallar hasta resolver esto."
fi

echo
echo "=== Instalación completa ==="
echo
echo "Próximos pasos:"
echo "  1. Editar $ENV_FILE con la API key y URL del CMS"
echo "  2. sudo systemctl start spui"
echo "  3. journalctl -u spui -f   # ver logs en tiempo real"
