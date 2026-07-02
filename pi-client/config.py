"""
Configuración del cliente SPUI para Raspberry Pi.

Todas las variables se leen del entorno primero; los valores por defecto
son para desarrollo local en Windows. En producción (Pi), configurar via
/etc/spui/spui.env (cargado por el servicio systemd).
"""

import os

# ── API ──────────────────────────────────────────────────────────────────────
API_URL = os.getenv('SPUI_API_URL', 'http://localhost/api/spui')
API_KEY = os.getenv('SPUI_API_KEY', '')   # OBLIGATORIO en producción

# ── MQTT (alertas de emergencia en tiempo real) ───────────────────────────────
MQTT_HOST  = os.getenv('MQTT_HOST', '127.0.0.1')
MQTT_PORT  = int(os.getenv('MQTT_PORT', '1883'))

# ── Intervalos (segundos) ─────────────────────────────────────────────────────
SYNC_INTERVAL        = int(os.getenv('SPUI_SYNC_INTERVAL', '300'))     # 5 min
HEARTBEAT_INTERVAL   = int(os.getenv('SPUI_HEARTBEAT_INTERVAL', '60'))
TELEMETRIA_INTERVAL  = int(os.getenv('SPUI_TELEMETRIA_INTERVAL', '60')) # 1 min

# ── Telemetría ────────────────────────────────────────────────────────────────
TEMP_ALERTA_CELSIUS = float(os.getenv('SPUI_TEMP_ALERTA_CELSIUS', '70.0'))

# ── Rutas locales ─────────────────────────────────────────────────────────────
# En Pi: /var/cache/spui
# En dev Windows: <raíz del cliente>/cache/
_BASE = os.path.dirname(os.path.abspath(__file__))
CACHE_DIR = os.getenv('SPUI_CACHE_DIR', os.path.join(_BASE, 'cache'))
MEDIA_DIR = os.path.join(CACHE_DIR, 'media')
DB_PATH   = os.path.join(CACHE_DIR, 'spui.db')

# ── Logging ──────────────────────────────────────────────────────────────────
LOG_LEVEL = os.getenv('SPUI_LOG_LEVEL', 'INFO')
# En Pi: /var/log/spui-client.log (requiere permisos).
# En dev: sólo stdout (el FileHandler ignora si no puede escribir).
LOG_FILE  = os.getenv('SPUI_LOG_FILE', '/var/log/spui-client.log')
