#!/bin/bash
# SPUI Pi Client — Script de instalación para Raspberry Pi
# NOTA Pi-only: Ejecutar como usuario pi con sudo disponible.
#
# Uso:
#   chmod +x install.sh
#   ./install.sh
#
# Qué hace:
#   1. Instala VLC y Python vía apt
#   2. Instala dependencias Python
#   3. Copia el cliente a /opt/spui
#   4. Crea /etc/spui/spui.env (editar antes de iniciar el servicio)
#   5. Instala y habilita el servicio systemd

set -euo pipefail

INSTALL_DIR="/opt/spui"
ENV_FILE="/etc/spui/spui.env"
SERVICE_SRC="$(dirname "$0")/spui.service"

echo "=== SPUI Pi Client — Instalador ==="
echo

# 1. Sistema
echo "[1/5] Instalando VLC y Python..."
sudo apt-get update -qq
sudo apt-get install -y vlc python3 python3-pip python3-venv

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
sudo chown -R pi:pi "$INSTALL_DIR"

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

# Intervalos en segundos
SPUI_SYNC_INTERVAL=300
SPUI_HEARTBEAT_INTERVAL=60

# Rutas
SPUI_CACHE_DIR=/var/cache/spui
SPUI_LOG_FILE=/var/log/spui-client.log

# Nivel de log: DEBUG | INFO | WARNING | ERROR
SPUI_LOG_LEVEL=INFO
EOF
    sudo chmod 640 "$ENV_FILE"
    echo "  IMPORTANTE: editar $ENV_FILE con los valores reales antes de iniciar."
else
    echo "  $ENV_FILE ya existe — no sobreescrito."
fi

# Crear directorios de caché y logs con permisos para pi
sudo mkdir -p /var/cache/spui /var/cache/spui/media
sudo chown -R pi:pi /var/cache/spui
sudo touch /var/log/spui-client.log
sudo chown pi:pi /var/log/spui-client.log

# 5. Servicio systemd
echo "[5/5] Instalando servicio systemd..."
sudo cp "$SERVICE_SRC" /etc/systemd/system/spui.service
sudo systemctl daemon-reload
sudo systemctl enable spui

echo
echo "=== Instalación completa ==="
echo
echo "Próximos pasos:"
echo "  1. Editar $ENV_FILE con la API key y URL del CMS"
echo "  2. sudo systemctl start spui"
echo "  3. journalctl -u spui -f   # ver logs en tiempo real"
