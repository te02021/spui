#!/usr/bin/env python3
"""
Diagnóstico de la conexión MQTT del reproductor SPUI.

Responde una sola pregunta: **¿por qué no llegan las alertas?**

Desde que el broker exige credenciales, un reproductor puede estar sincronizando
perfectamente por HTTP y aun así no recibir ni una alerta, porque el broker lo
rechaza. Eso no rompe nada visible: la pantalla sigue mostrando su playlist. Sin
esta herramienta, el diagnóstico es leer el journal a ojo.

Uso, desde la Pi — con sudo, porque lee /etc/spui/spui.env (640, root):

    sudo /opt/spui/venv/bin/python3 /opt/spui/diagnostico_mqtt.py

La contraseña MQTT se deriva de la API key, así que sirve además para verificar
que el salt del reproductor coincida con el del CMS.
"""

import hashlib
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))


def titulo(texto: str) -> None:
    print(f'\n=== {texto} ===')


def resultado(ok: bool, etiqueta: str, detalle: str = '') -> None:
    print(f'  [{"OK " if ok else "MAL"}] {etiqueta}' + (f' — {detalle}' if detalle else ''))


def _cargar_env_del_servicio() -> bool:
    """
    Carga /etc/spui/spui.env si las variables no están ya en el entorno.

    Ejecutar el script a mano no hereda el entorno del servicio systemd, así que
    sin esto el diagnóstico comprobaría una configuración distinta a la real —
    justo el error que haría perder más tiempo.

    El archivo es 640 y pertenece a root (contiene la API key), así que sin sudo
    no se puede leer. Devuelve False en ese caso para que el resumen avise en
    vez de reportar que falta toda la configuración: los "FALTA" serían falsos
    y mandarían a buscar el problema donde no está.
    """
    ruta = '/etc/spui/spui.env'
    if not os.path.exists(ruta):
        return True

    try:
        with open(ruta, encoding='utf-8') as fh:
            for linea in fh:
                linea = linea.strip()
                if not linea or linea.startswith('#') or '=' not in linea:
                    continue
                clave, _, valor = linea.partition('=')
                clave = clave.strip()
                if clave and clave not in os.environ:
                    os.environ[clave] = valor.strip().strip('"').strip("'")
        return True
    except PermissionError:
        print(
            f'\n  ERROR: no se puede leer {ruta} (permisos).\n'
            f'  Volvé a ejecutar con sudo, o el diagnóstico va a informar\n'
            f'  que falta configuración que en realidad está presente:\n\n'
            f'      sudo /opt/spui/venv/bin/python3 /opt/spui/diagnostico_mqtt.py\n'
        )
        return False
    except OSError as exc:
        print(f'  aviso: no se pudo leer {ruta}: {exc}')
        return False


def main() -> int:
    if not _cargar_env_del_servicio():
        return 2

    import config

    problemas = []

    # ── Configuración ─────────────────────────────────────────────────────────
    titulo('Configuración')

    hay_key = bool(config.API_KEY)
    resultado(hay_key, 'SPUI_API_KEY definida',
              f'{len(config.API_KEY)} caracteres' if hay_key else 'FALTA')
    if not hay_key:
        problemas.append('Sin SPUI_API_KEY no hay comunicación posible, ni HTTP ni MQTT.')

    hay_salt = bool(config.MQTT_SALT)
    resultado(hay_salt, 'SPUI_MQTT_SALT definida',
              f'{len(config.MQTT_SALT)} caracteres' if hay_salt else 'FALTA')
    if not hay_salt:
        problemas.append(
            'Sin SPUI_MQTT_SALT la contraseña MQTT no coincide con la que el CMS '
            'creó en el broker. Copiala del .env.local.php del CMS.'
        )

    print(f'  Broker configurado: {config.MQTT_HOST}:{config.MQTT_PORT}')

    # ── Identidad ─────────────────────────────────────────────────────────────
    titulo('Identidad ante el broker')

    # El usuario depende del reproductor_id, que llega en el sync y se guarda en
    # el caché. Leerlo de ahí evita tener que consultar al CMS.
    reproductor_id = None
    try:
        from spui.cache import Cache
        datos = Cache(config.DB_PATH).obtener_sync()
        if datos:
            reproductor_id = datos.get('reproductor_id')
    except Exception as exc:
        print(f'  aviso: no se pudo leer el caché ({exc})')

    if reproductor_id:
        usuario = config.mqtt_usuario(reproductor_id)
        resultado(True, 'reproductor_id conocido', str(reproductor_id))
        print(f'  Usuario MQTT: {usuario}')
    else:
        resultado(False, 'reproductor_id conocido',
                  'todavía no hubo un sync exitoso')
        problemas.append(
            'Sin reproductor_id el cliente no puede conectarse al broker: el usuario '
            'MQTT depende de él. Se resuelve solo en cuanto el primer sync funcione.'
        )
        usuario = None

    if hay_key:
        derivada = hashlib.sha256(
            (config.API_KEY + config.MQTT_SALT).encode('utf-8')
        ).hexdigest()
        print(f'  Contraseña derivada: {derivada[:16]}... ({len(derivada)} chars)')
        print('  (comparar con lo que muestra el CMS en la ficha del reproductor)')

    # ── Conexión real ─────────────────────────────────────────────────────────
    titulo('Conexión al broker')

    try:
        import paho.mqtt.client as mqtt  # noqa: F401
        resultado(True, 'paho-mqtt instalado')
    except ImportError:
        resultado(False, 'paho-mqtt instalado', 'pip install paho-mqtt')
        problemas.append('Sin paho-mqtt el listener MQTT no arranca y no llegan alertas.')
        return _resumen(problemas)

    if not usuario:
        print('  (se omite: hace falta el reproductor_id)')
        return _resumen(problemas)

    from spui.mqtt_listener import nuevo_cliente_mqtt

    estado = {'rc': None}

    def on_connect(client, userdata, flags, rc):
        estado['rc'] = rc

    try:
        cliente = nuevo_cliente_mqtt(
            f'spui-diagnostico-{reproductor_id}',
            reproductor_id=reproductor_id,
        )
        cliente.on_connect = on_connect
        cliente.connect(config.MQTT_HOST, config.MQTT_PORT, keepalive=10)
        cliente.loop_start()

        import time
        for _ in range(50):          # hasta 5 s
            if estado['rc'] is not None:
                break
            time.sleep(0.1)

        cliente.loop_stop()
        cliente.disconnect()

        rc = estado['rc']
        if rc == 0:
            resultado(True, 'Autenticación aceptada', f'como {usuario}')
        elif rc is None:
            resultado(False, 'Autenticación', 'el broker no respondió en 5 s')
            problemas.append(
                f'El broker no respondió. ¿Está corriendo Mosquitto en '
                f'{config.MQTT_HOST}:{config.MQTT_PORT}?'
            )
        elif rc in (4, 5):
            resultado(False, 'Autenticación rechazada', f'rc={rc}')
            problemas.append(
                'El broker rechazó las credenciales. Las dos causas habituales: '
                '(1) se regeneró la API key en el CMS y no se actualizó acá, o '
                '(2) el SPUI_MQTT_SALT no coincide con el del CMS.'
            )
        else:
            resultado(False, 'Conexión', f'rc={rc}')
            problemas.append(f'El broker respondió rc={rc}. Ver el log de Mosquitto.')

    except Exception as exc:
        resultado(False, 'Conexión al broker', str(exc))
        problemas.append(
            f'No se pudo conectar a {config.MQTT_HOST}:{config.MQTT_PORT} — {exc}'
        )

    return _resumen(problemas)


def _resumen(problemas: list) -> int:
    titulo('Resumen')
    if not problemas:
        print('  Todo en orden: este reproductor puede recibir alertas por MQTT.')
        return 0

    for i, p in enumerate(problemas, 1):
        print(f'  {i}. {p}')

    print(
        '\n  Nota: aunque MQTT falle, el reproductor sigue funcionando por HTTP.\n'
        '  Lo que se pierde son las alertas de emergencia instantáneas y la\n'
        '  telemetría; el contenido programado se sigue mostrando.'
    )
    return 1


if __name__ == '__main__':
    sys.exit(main())
