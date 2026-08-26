#!/bin/bash
# =============================================================================
# SPUI - Instala el daemon spui:mqtt:subscribe como servicio systemd (Linux)
# =============================================================================
#
# Equivalente Linux de instalar-servicio-telemetria.ps1 (Windows/NSSM). El
# comando spui:mqtt:subscribe es un proceso de larga duracion que:
#   1. Se suscribe a spui/telemetria/+ y persiste cada mensaje en la base.
#   2. Cada minuto corre el mantenimiento de la red (reproductores caidos,
#      purga de telemetria, alertas vencidas).
#   3. Cada hora agrega la telemetria cruda en resumenes horarios.
#
# Las tres cosas viven en el mismo proceso a proposito: instalar este UNICO
# servicio deja todo funcionando. No hace falta (ni conviene) agregar cron ni
# systemd timers aparte para mantenimiento/rollup -- correrian una segunda
# vez lo que este proceso ya hace solo.
#
# Uso (con sudo):
#   sudo ./instalar-servicio.sh
#   sudo ./instalar-servicio.sh --raiz /var/www/intranet --usuario www-data
#   sudo ./instalar-servicio.sh --desinstalar
#
# No usar systemctl a mano para probar cambios de uno en uno: correr este
# script de nuevo es idempotente, reinstala la unit completa.
# =============================================================================

set -euo pipefail

RAIZ="/var/www/intranet"
USUARIO=""
NOMBRE_SERVICIO="spui-telemetria"
PHP=""
DESINSTALAR=0

while [ $# -gt 0 ]; do
    case "$1" in
        --raiz)            RAIZ="$2"; shift 2 ;;
        --usuario)         USUARIO="$2"; shift 2 ;;
        --nombre-servicio) NOMBRE_SERVICIO="$2"; shift 2 ;;
        --php)              PHP="$2"; shift 2 ;;
        --desinstalar)      DESINSTALAR=1; shift ;;
        *) echo "Argumento desconocido: $1" >&2; exit 1 ;;
    esac
done

if [ "$(id -u)" -ne 0 ]; then
    echo "ERROR: este script necesita sudo (escribe en /etc/systemd/system)." >&2
    echo "Correr: sudo ./instalar-servicio.sh" >&2
    exit 1
fi

UNIT_PATH="/etc/systemd/system/${NOMBRE_SERVICIO}.service"

# ---- Desinstalacion ---------------------------------------------------------
if [ "$DESINSTALAR" -eq 1 ]; then
    if systemctl list-unit-files "${NOMBRE_SERVICIO}.service" &>/dev/null; then
        systemctl stop "$NOMBRE_SERVICIO" || true
        systemctl disable "$NOMBRE_SERVICIO" || true
        rm -f "$UNIT_PATH"
        systemctl daemon-reload
        echo "Servicio '$NOMBRE_SERVICIO' eliminado."
    else
        echo "El servicio '$NOMBRE_SERVICIO' no existe."
    fi
    exit 0
fi

# ---- Validaciones previas ---------------------------------------------------
# Se comprueban antes de escribir la unit: systemd acepta rutas inexistentes
# sin quejarse y el fallo recien aparece al arrancar, como un servicio que
# entra en loop de reinicios sin explicacion clara en journalctl -xe.
if [ -z "$PHP" ]; then
    PHP="$(command -v php || true)"
    if [ -z "$PHP" ]; then
        echo "ERROR: no se encontro php en el PATH. Pasar la ruta con --php." >&2
        exit 1
    fi
    echo "  PHP detectado: $PHP"
fi
if [ ! -x "$PHP" ]; then
    echo "ERROR: '$PHP' no existe o no es ejecutable. Pasar la ruta correcta con --php." >&2
    exit 1
fi

CONSOLA="$RAIZ/bin/console"
if [ ! -f "$CONSOLA" ]; then
    echo "ERROR: no se encontro '$CONSOLA'. Pasar la raiz correcta con --raiz." >&2
    exit 1
fi

if [ -z "$USUARIO" ]; then
    # El servidor web (Apache/Nginx+PHP-FPM) ya corre como este usuario en la
    # mayoria de las distros; correr el daemon con el mismo usuario evita
    # problemas de permisos sobre archivos que ambos procesos puedan tocar
    # (uploads, cache de Symfony). Ajustar con --usuario si el server usa otro.
    if id www-data &>/dev/null; then
        USUARIO="www-data"
    else
        USUARIO="$(logname 2>/dev/null || echo root)"
    fi
    echo "  Usuario detectado: $USUARIO"
fi

# ---- Unit de systemd ---------------------------------------------------------
echo "Instalando '$NOMBRE_SERVICIO'..."

cat > "$UNIT_PATH" <<EOF
[Unit]
Description=SPUI - Ingestor MQTT + mantenimiento periodico (telemetria, reproductores caidos, alertas vencidas, rollup)
# Ordering, no dependencia dura: el comando reintenta la conexion a MQTT, y
# si la base no esta lista al arrancar, Restart=on-failure lo vuelve a
# intentar. Un Requires= aca dejaria el servicio sin arrancar nunca si el
# nombre exacto de la unit de MySQL/MariaDB no coincide en esta distro.
After=network.target mysql.service mariadb.service

[Service]
Type=simple
User=$USUARIO
WorkingDirectory=$RAIZ
ExecStart=$PHP $CONSOLA spui:mqtt:subscribe --id=spui
Restart=on-failure
RestartSec=10

# A journal, no a archivo: systemd/journald ya rota y retiene sin
# configuracion aparte (a diferencia del NSSM de Windows, que si necesita
# AppRotateFiles a mano). Ver con: journalctl -u $NOMBRE_SERVICIO -f
StandardOutput=journal
StandardError=journal
SyslogIdentifier=$NOMBRE_SERVICIO

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now "$NOMBRE_SERVICIO"

sleep 3
ESTADO="$(systemctl is-active "$NOMBRE_SERVICIO" || true)"

if [ "$ESTADO" = "active" ]; then
    echo ""
    echo "  Servicio '$NOMBRE_SERVICIO' corriendo."
    echo "  Log: journalctl -u $NOMBRE_SERVICIO -f"
    echo ""
    echo "  Verificar que entren filas (esperar ~60 s):"
    echo "    php bin/console dbal:run-sql --connection=spui --id=spui \"SELECT COUNT(*) FROM telemetria\""
else
    echo ""
    echo "  El servicio quedo en estado '$ESTADO'."
    echo "  Revisar: journalctl -u $NOMBRE_SERVICIO -xe"
    exit 1
fi
