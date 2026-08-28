"""
Configuración del cliente SPUI para Raspberry Pi.

Todas las variables se leen del entorno primero; los valores por defecto
son para desarrollo local en Windows. En producción (Pi), configurar via
/etc/spui/spui.env (cargado por el servicio systemd).

Este módulo se importa ANTES de que main.py configure el logging, así que un
error acá sale como traceback pelado por stderr y no queda en el archivo de
log: es el fallo más difícil de diagnosticar a distancia. Por eso ningún valor
mal escrito en spui.env puede tumbar el arranque — se avisa por stderr y se usa
el default.
"""

import hashlib
import os
import sys


def _texto(nombre: str, defecto: str) -> str:
    """
    Variable de texto.

    os.getenv(nombre, defecto) no alcanza: una línea 'SPUI_CACHE_DIR=' en
    spui.env define la variable como cadena vacía, y os.getenv devuelve ''
    en vez del default. Acá una variable vacía se trata como no definida.
    """
    valor = os.getenv(nombre)
    return valor.strip() if valor and valor.strip() else defecto


def _entero(nombre: str, defecto: int) -> int:
    """Variable numérica entera. Si el valor no es válido, avisa y usa el default."""
    crudo = os.getenv(nombre)
    if crudo is None or not crudo.strip():
        return defecto
    try:
        return int(crudo.strip())
    except ValueError:
        print(
            f'[SPUI][config] {nombre}={crudo!r} no es un número entero — '
            f'se usa el valor por defecto ({defecto}).',
            file=sys.stderr,
        )
        return defecto


def _decimal(nombre: str, defecto: float) -> float:
    """Variable numérica decimal. Si el valor no es válido, avisa y usa el default."""
    crudo = os.getenv(nombre)
    if crudo is None or not crudo.strip():
        return defecto
    try:
        return float(crudo.strip())
    except ValueError:
        print(
            f'[SPUI][config] {nombre}={crudo!r} no es un número — '
            f'se usa el valor por defecto ({defecto}).',
            file=sys.stderr,
        )
        return defecto


def _booleano(nombre: str, defecto: bool) -> bool:
    """Variable booleana. Acepta true/false/1/0, insensible a mayúsculas."""
    crudo = os.getenv(nombre)
    if crudo is None or not crudo.strip():
        return defecto
    valor = crudo.strip().lower()
    if valor in ('true', '1', 'si', 'sí'):
        return True
    if valor in ('false', '0', 'no'):
        return False
    print(
        f'[SPUI][config] {nombre}={crudo!r} no es un booleano válido — '
        f'se usa el valor por defecto ({defecto}).',
        file=sys.stderr,
    )
    return defecto


# ── API ──────────────────────────────────────────────────────────────────────
API_URL = _texto('SPUI_API_URL', 'http://localhost/api/spui')
API_KEY = _texto('SPUI_API_KEY', '')   # OBLIGATORIO en producción

# ── MQTT (alertas de emergencia en tiempo real) ───────────────────────────────
MQTT_HOST  = _texto('MQTT_HOST', '127.0.0.1')
MQTT_PORT  = _entero('MQTT_PORT', 1883)

# TLS (tarea 1.0.e) — false por defecto: el broker real todavía sirve por
# 1883 sin cifrar (1.0.d escrito, no desplegado). Para migrar esta Pi cuando
# el broker ya tenga 8883 activo: MQTT_TLS=true, MQTT_PORT=8883, y
# MQTT_CA_CERT apuntando al ca.crt copiado desde el CMS (mismo archivo que
# generar-certificados.ps1 deja en config/mosquitto/certs/ca.crt).
MQTT_TLS     = _booleano('MQTT_TLS', False)
MQTT_CA_CERT = _texto('MQTT_CA_CERT', '')

# El broker exige credenciales: sin esto, cualquiera en la red del campus podría
# publicar una alerta de emergencia en todas las pantallas.
#
# La contraseña se DERIVA de la API key en vez de ser un secreto aparte. Si la
# Pi tuviera dos credenciales independientes en spui.env, se podrían
# desincronizar por separado — que es exactamente lo que rompió la comunicación
# el 11/08: se regeneró la API key desde el CMS, el .env quedó con la vieja, y
# el reproductor devolvió 401 en silencio durante días. Así, regenerar la API
# key regenera todo el vínculo de una sola vez.
#
# El salt tiene que ser IDÉNTICO al SPUI_MQTT_SALT del CMS, que es quien crea
# estas credenciales en el broker. Si no coinciden, el broker rechaza la
# conexión y se pierden las alertas (el sync y el heartbeat siguen andando por
# HTTP, así que la pantalla no se queda en negro).
MQTT_SALT = _texto('SPUI_MQTT_SALT', '')


def _mqtt_password() -> str:
    """
    Contraseña MQTT derivada de la API key. Espejo de
    Reproductor::mqttPassword() del CMS — si cambia una, cambia la otra.
    """
    if not API_KEY:
        return ''
    return hashlib.sha256((API_KEY + MQTT_SALT).encode('utf-8')).hexdigest()


MQTT_PASS = _mqtt_password()

# El usuario real lo asigna el CMS (spui-repro-{id}) y el id llega recién en el
# primer sync. Hasta entonces no se puede conectar al broker: se resuelve en
# mqtt_listener/telemetria, que esperan a tener el id.
MQTT_USER_PREFIJO = 'spui-repro-'


def mqtt_usuario(reproductor_id: int) -> str:
    """Usuario MQTT de este reproductor. Espejo de Reproductor::mqttUsuario()."""
    return f'{MQTT_USER_PREFIJO}{reproductor_id}'

# ── Intervalos (segundos) ─────────────────────────────────────────────────────
# Mínimo de 5 s: un intervalo de 0 o negativo dejaría los hilos girando sin
# pausa y saturaría al CMS (que además responde 429).
SYNC_INTERVAL        = max(5, _entero('SPUI_SYNC_INTERVAL', 300))     # 5 min
HEARTBEAT_INTERVAL   = max(5, _entero('SPUI_HEARTBEAT_INTERVAL', 60))
TELEMETRIA_INTERVAL  = max(5, _entero('SPUI_TELEMETRIA_INTERVAL', 60))  # 1 min

# ── Sonido de alerta ──────────────────────────────────────────────────────────
# El CMS puede mandar un sonido personalizado por alerta (sonido_url en el
# payload). Si no hay red, no está cacheado todavía, o el admin no cargó
# ninguno, el reproductor usa un tono generado localmente — nunca queda mudo.
# ACTIVO=false es para una pantalla sin parlante conectado: evita que VLC
# intente abrir un dispositivo de audio que no existe en cada alerta.
ALERTA_SONIDO_ACTIVO      = _booleano('SPUI_ALERTA_SONIDO_ACTIVO', True)
# Pausa entre el FIN de una repetición y el INICIO de la siguiente — no un
# intervalo fijo de reloj: el player detecta cuándo VLC termina de reproducir
# (vlc.State.Ended/Stopped) y recién ahí cuenta esta pausa, así un sonido de
# 1 segundo no espera 20 segundos completos para repetirse. 0 = pegado, sin
# pausa.
ALERTA_SONIDO_PAUSA_SEG = max(0, _entero('SPUI_ALERTA_SONIDO_INTERVALO_SEG', 0))
# 0-100. Se fuerza en cada reproducción: una emergencia no debería depender de
# en qué volumen haya quedado el equipo de una prueba anterior.
ALERTA_SONIDO_VOLUMEN     = max(0, min(100, _entero('SPUI_ALERTA_SONIDO_VOLUMEN', 100)))

# ── Telemetría ────────────────────────────────────────────────────────────────
# 80 °C: valor documentado en la tesis y punto donde la Pi 4 hace throttling.
# Tiene que coincidir con spui_temp_alerta_default del CMS.
TEMP_ALERTA_CELSIUS = _decimal('SPUI_TEMP_ALERTA_CELSIUS', 80.0)

# ── Rutas locales ─────────────────────────────────────────────────────────────
# En Pi: /var/cache/spui
# En dev Windows: <raíz del cliente>/cache/
_BASE = os.path.dirname(os.path.abspath(__file__))
CACHE_DIR = _texto('SPUI_CACHE_DIR', os.path.join(_BASE, 'cache'))
MEDIA_DIR = os.path.join(CACHE_DIR, 'media')
DB_PATH   = os.path.join(CACHE_DIR, 'spui.db')

# ── Logging ──────────────────────────────────────────────────────────────────
LOG_LEVEL = _texto('SPUI_LOG_LEVEL', 'INFO')
# En Pi: /var/log/spui-client.log (requiere permisos).
# En dev: sólo stdout (el FileHandler ignora si no puede escribir).
LOG_FILE  = _texto('SPUI_LOG_FILE', '/var/log/spui-client.log')
