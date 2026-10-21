#!/usr/bin/env python3
"""
CP09 — Solicitud o mensaje sin credenciales, rechazado (docs/10_pruebas.md).

Prueba, desde la Pi y con su configuración real, que ni la API ni el broker
aceptan pedidos sin credenciales válidas, y que un reproductor no puede emitir
alertas. Imprime la planilla lista para pegar en docs/10.

Uso (con sudo porque lee /etc/spui/spui.env, que es 640 de root):

    sudo /opt/spui/venv/bin/python3 /opt/spui/pruebas/cp09.py [--repeticiones 5]

No necesita mosquitto-clients: usa el mismo paho-mqtt que el cliente. No toca
el caché ni la conexión del servicio (client id propio, sin escribir nada).

Si la ACL del broker estuviera mal y la publicación del vector 5 pasara, lo que
llega a las pantallas es un mensaje sin retain que el cliente descarta como
"payload desconocido": la prueba no puede disparar una alerta real.
"""

import argparse
import os
import secrets
import sys
import threading
import time

_RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, _RAIZ)

# El entorno del servicio se carga antes de importar config, que lo lee al importarse.
from diagnostico_mqtt import _cargar_env_del_servicio  # noqa: E402

if not _cargar_env_del_servicio():
    sys.exit(2)

import requests  # noqa: E402

import config  # noqa: E402
from spui.mqtt_listener import nuevo_cliente_mqtt  # noqa: E402

TOPIC_ALERTA = 'spui/alertas/emergencia'
ESPERA_CONEXION = 10
ESPERA_MENSAJE = 5


# ── API ──────────────────────────────────────────────────────────────────────

def _codigo(metodo: str, url: str, **kw) -> str:
    try:
        return str(requests.request(metodo, url, timeout=10, **kw).status_code)
    except requests.RequestException:
        return 'sin conexión'


def vectores_api(base: str) -> list[tuple[str, str, callable]]:
    sync = f'{base}/reproductores/sync'
    return [
        ('API sin clave', '401', lambda: _codigo('POST', sync)),
        ('API con clave inválida', '401',
         lambda: _codigo('POST', sync, headers={'X-Api-Key': 'clave-invalida-' + secrets.token_hex(4)})),
        ('Media sin clave', '401', lambda: _codigo('GET', f'{base}/media/cp09-inexistente.jpg')),
    ]


def reproductor_id(base: str) -> tuple[int | None, str]:
    """Control: con la clave real la API responde 200, y de paso da el id del reproductor."""
    try:
        r = requests.post(f'{base}/reproductores/sync', timeout=10,
                          headers={'X-Api-Key': config.API_KEY, 'Accept': 'application/json'})
    except requests.RequestException as exc:
        return None, f'sin conexión ({type(exc).__name__})'
    if r.status_code != 200:
        return None, str(r.status_code)
    try:
        return int(r.json().get('reproductor_id')), '200'
    except (ValueError, TypeError):
        return None, '200 sin reproductor_id'


# ── MQTT ─────────────────────────────────────────────────────────────────────

def _conectar(puerto: int, tls: bool, usuario: str | None, clave: str | None):
    """Devuelve (cliente o None, resultado legible). Nunca lanza."""
    tls_original = config.MQTT_TLS
    config.MQTT_TLS = tls  # nuevo_cliente_mqtt decide el TLS leyendo config
    try:
        cliente = nuevo_cliente_mqtt('spui-cp09-' + secrets.token_hex(4))
    finally:
        config.MQTT_TLS = tls_original
    if usuario is not None:
        cliente.username_pw_set(usuario, clave)

    listo = threading.Event()
    estado = {'rc': None}

    def on_connect(_c, _u, _f, rc, *_):
        estado['rc'] = rc
        listo.set()

    cliente.on_connect = on_connect
    try:
        cliente.connect(config.MQTT_HOST, puerto, keepalive=30)
    except Exception as exc:  # noqa: BLE001 — cualquier fallo de red cuenta como "no conectó"
        return None, f'sin conexión ({type(exc).__name__})'
    cliente.loop_start()
    if not listo.wait(ESPERA_CONEXION) or estado['rc'] != 0:
        cliente.loop_stop()
        cliente.disconnect()
        rc = estado['rc']
        return None, 'rechazado' if rc not in (None, 0) else 'sin respuesta'
    return cliente, 'conectado'


def _cerrar(cliente) -> None:
    if cliente is not None:
        cliente.disconnect()
        cliente.loop_stop()


def mqtt_anonimo(puerto: int, tls: bool) -> str:
    cliente, resultado = _conectar(puerto, tls, None, None)
    _cerrar(cliente)
    return resultado


def mqtt_clave_incorrecta(usuario: str) -> str:
    cliente, resultado = _conectar(config.MQTT_PORT, config.MQTT_TLS, usuario, 'clave-incorrecta')
    _cerrar(cliente)
    return resultado


def mqtt_publica_alerta(usuario: str) -> str:
    """
    El reproductor publica en el topic de alertas con sus credenciales reales.
    El broker acepta la conexión y descarta la publicación (ACL): la prueba es
    que un suscriptor no la reciba.
    """
    marca = 'cp09-' + secrets.token_hex(6)
    recibido = threading.Event()

    sub, res = _conectar(config.MQTT_PORT, config.MQTT_TLS, usuario, config.MQTT_PASS)
    if sub is None:
        return f'no se pudo probar (suscriptor {res})'
    pub, res = _conectar(config.MQTT_PORT, config.MQTT_TLS, usuario, config.MQTT_PASS)
    if pub is None:
        _cerrar(sub)
        return f'no se pudo probar (publicador {res})'

    def on_message(_c, _u, msg):
        if marca.encode() in msg.payload:
            recibido.set()

    # Control: si el broker no aceptara la suscripción, "no llegó" pasaría
    # aunque la publicación sí hubiera entrado. Un SUBACK con 128 es rechazo.
    suscripto = threading.Event()
    concedido = {'qos': None}

    def on_subscribe(_c, _u, _mid, granted, *_):
        concedido['qos'] = granted[0] if granted else None
        suscripto.set()

    sub.on_message = on_message
    sub.on_subscribe = on_subscribe
    sub.subscribe(TOPIC_ALERTA, qos=1)
    if not suscripto.wait(ESPERA_CONEXION) or concedido['qos'] in (None, 128):
        _cerrar(pub)
        _cerrar(sub)
        return 'no se pudo probar (suscripción rechazada)'

    pub.publish(TOPIC_ALERTA, f'{{"accion":"prueba_cp09","marca":"{marca}"}}', qos=1, retain=False)
    llego = recibido.wait(ESPERA_MENSAJE)
    _cerrar(pub)
    _cerrar(sub)
    return 'LLEGÓ' if llego else 'no llegó'


# ── Principal ────────────────────────────────────────────────────────────────

def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.split('\n\n')[0])
    ap.add_argument('--repeticiones', type=int, default=5)
    args = ap.parse_args()
    n = max(1, args.repeticiones)

    base = config.API_URL.rstrip('/')
    print(f'CP09 — {time.strftime("%d/%m/%Y %H:%M:%S")} · versión {config.VERSION}')
    print(f'API {base} · broker {config.MQTT_HOST}:{config.MQTT_PORT} (TLS {"sí" if config.MQTT_TLS else "no"})\n')

    rid, control = reproductor_id(base)
    print(f'Control: API con la clave real → {control}')
    if rid is None:
        print('Sin el id del reproductor no se pueden probar los vectores MQTT con credenciales.')
    usuario = config.mqtt_usuario(rid) if rid is not None else None

    filas: list[tuple[str, str, list[str]]] = []
    for nombre, esperado, f in vectores_api(base):
        filas.append((nombre, esperado, [f() for _ in range(n)]))

    filas.append((f'MQTT anónimo, puerto {config.MQTT_PORT}', 'rechazado',
                  [mqtt_anonimo(config.MQTT_PORT, config.MQTT_TLS) for _ in range(n)]))
    if config.MQTT_PORT != 1883:
        # El 1883 sin TLS sigue abierto hasta C2: se prueba que tampoco acepte anónimos.
        filas.append(('MQTT anónimo, puerto 1883 sin TLS', 'rechazado',
                      [mqtt_anonimo(1883, False) for _ in range(n)]))
    if usuario:
        filas.append(('MQTT con clave incorrecta', 'rechazado',
                      [mqtt_clave_incorrecta(usuario) for _ in range(n)]))
        filas.append(('Reproductor publica una alerta', 'no llegó',
                      [mqtt_publica_alerta(usuario) for _ in range(n)]))

    print('\n| Vector | Esperado | ' + ' | '.join(f'Rep. {i}' for i in range(1, n + 1)) + ' | ¿Cumple? |')
    print('|---|---|' + '---|' * n + '---|')
    fallas = 0
    for nombre, esperado, resultados in filas:
        ok = all(r == esperado for r in resultados)
        fallas += 0 if ok else 1
        print(f'| {nombre} | {esperado} | ' + ' | '.join(resultados) + f' | {"✅" if ok else "❌"} |')

    print()
    if fallas == 0:
        print('Veredicto: ✅ cumple.')
    else:
        print(f'Veredicto: ❌ {fallas} vector(es) no dieron lo esperado. "sin conexión" significa que'
              ' no hubo respuesta: es un problema de red, no un rechazo.')
    return 0 if fallas == 0 else 1


if __name__ == '__main__':
    sys.exit(main())
