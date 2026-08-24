#!/usr/bin/env python3
"""
Diagnóstico del subsistema de vídeo del reproductor SPUI.

Responde una sola pregunta: **¿por qué no se ve nada en la pantalla?**

Se ejecuta con el mismo intérprete y el mismo usuario que el servicio, así que
comprueba exactamente las mismas condiciones que el cliente real:

    sudo -u <usuario> DISPLAY=:0 /opt/spui/venv/bin/python3 /opt/spui/diagnostico.py

o, más simple, desde la Pi:

    /opt/spui/venv/bin/python3 /opt/spui/diagnostico.py
"""

import os
import subprocess
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))


def titulo(texto: str) -> None:
    print(f'\n=== {texto} ===')


def resultado(ok: bool, etiqueta: str, detalle: str = '') -> None:
    print(f'  [{"OK " if ok else "MAL"}] {etiqueta}' + (f' — {detalle}' if detalle else ''))


def _cargar_env_del_servicio() -> None:
    """
    Carga /etc/spui/spui.env en el entorno del proceso.

    Ejecutando el diagnóstico a mano no están las variables que systemd le pasa
    al servicio (EnvironmentFile), así que config.py caía a sus valores por
    defecto y el script buscaba el caché en /opt/spui/cache en vez de en
    /var/cache/spui: informaba "no hay archivos" mientras el cliente los tenía.
    """
    ruta = '/etc/spui/spui.env'
    if not os.path.exists(ruta):
        return

    try:
        with open(ruta, encoding='utf-8') as fh:
            for linea in fh:
                linea = linea.strip()
                if not linea or linea.startswith('#') or '=' not in linea:
                    continue
                clave, valor = linea.split('=', 1)
                valor = valor.strip()
                # Quitar comillas envolventes si las tuviera.
                if len(valor) >= 2 and valor[0] == valor[-1] and valor[0] in ('"', "'"):
                    valor = valor[1:-1]
                # Lo que ya esté definido en el entorno tiene prioridad.
                os.environ.setdefault(clave.strip(), valor)
    except OSError as exc:
        print(f'  (no se pudo leer {ruta}: {exc})')


def _buscar_media_cacheada():
    """
    Devuelve un archivo real del caché para probar la reproducción, o None.

    Se usa el mismo contenido que el cliente intenta mostrar: así la prueba
    reproduce las condiciones reales en vez de inventar un medio sintético.
    """
    candidatos = []

    try:
        import config
        candidatos.append(config.MEDIA_DIR)
    except Exception:
        pass

    # Respaldo: la ruta estándar en la Pi, por si config no se pudo importar.
    candidatos.append('/var/cache/spui/media')

    extensiones = ('.jpg', '.jpeg', '.png', '.gif', '.webp', '.mp4', '.webm', '.mov')

    for media_dir in candidatos:
        if not media_dir or not os.path.isdir(media_dir):
            continue
        for nombre in sorted(os.listdir(media_dir)):
            if nombre.lower().endswith(extensiones):
                return os.path.join(media_dir, nombre)

    print(f'  Directorios consultados: {", ".join(c for c in candidatos if c)}')
    return None


def main() -> int:
    problemas = []

    # Mismas variables que systemd le pasa al servicio: sin esto el script
    # miraría rutas distintas de las que usa el cliente real.
    _cargar_env_del_servicio()

    titulo('1. python-vlc')
    try:
        import vlc
        resultado(True, 'módulo importable', f'versión libvlc {vlc.libvlc_get_version().decode()}')
    except Exception as exc:
        resultado(False, 'módulo importable', str(exc))
        problemas.append('Falta python-vlc o la librería libvlc del sistema (sudo apt install vlc).')
        print('\n'.join(problemas))
        return 1

    titulo('2. Servidor gráfico X11')
    display = os.environ.get('DISPLAY')
    print(f'  DISPLAY del entorno: {display or "(no definida)"}')

    try:
        sockets = sorted(os.listdir('/tmp/.X11-unix'))
    except OSError:
        sockets = []
    print(f'  Sockets en /tmp/.X11-unix: {sockets or "(ninguno)"}')

    if not sockets and not display:
        resultado(False, 'hay un servidor X corriendo')
        problemas.append(
            'No hay servidor gráfico. Revisá el autologin y el startx del ~/.bashrc '
            '(docs/09_instalacion_raspberry.md §8.2 y §8.3).'
        )
    else:
        resultado(True, 'hay un servidor X corriendo')
        if not display:
            os.environ['DISPLAY'] = ':' + sockets[0][1:]
            print(f'  → se usará DISPLAY={os.environ["DISPLAY"]}')

    titulo('3. Autorización de X (cookie)')
    xauth = os.environ.get('XAUTHORITY')
    print(f'  XAUTHORITY: {xauth or "(no definida)"}')
    try:
        salida = subprocess.run(
            ['xdpyinfo'], capture_output=True, text=True, timeout=10,
            env={**os.environ},
        )
        if salida.returncode == 0:
            primera = next((l for l in salida.stdout.splitlines() if 'dimensions' in l), '')
            resultado(True, 'se puede abrir el display', primera.strip())
        else:
            resultado(False, 'se puede abrir el display', salida.stderr.strip()[:120])
            problemas.append(
                'El display existe pero no se puede abrir (falta la cookie de X). '
                'Suele arreglarse ejecutando el servicio como el mismo usuario que arrancó X.'
            )
    except FileNotFoundError:
        print('  (xdpyinfo no está instalado — se omite; sudo apt install x11-utils)')
    except Exception as exc:
        resultado(False, 'se puede abrir el display', str(exc))

    titulo('4. Módulos de salida de vídeo de VLC')
    # Sin un módulo de salida (xcb_x11, xcb_xv, gles2…), VLC abre el archivo y
    # lo cierra al instante: da State.Ended y la pantalla queda en negro.
    salidas = []
    for ruta in ('/usr/lib/arm-linux-gnueabihf/vlc/plugins/video_output',
                 '/usr/lib/aarch64-linux-gnu/vlc/plugins/video_output',
                 '/usr/lib/vlc/plugins/video_output'):
        if os.path.isdir(ruta):
            salidas = sorted(
                n.replace('lib', '').replace('_plugin.so', '')
                for n in os.listdir(ruta) if n.endswith('.so')
            )
            print(f'  Directorio: {ruta}')
            break

    if salidas:
        resultado(True, f'{len(salidas)} módulo(s) de salida', ', '.join(salidas[:10]))

        # Los que VLC usa por defecto sobre X11. Si no está ninguno, VLC no
        # tiene forma de dibujar en la pantalla aunque haya otros módulos
        # (drm_vout, egl_wl y compañía sirven para Wayland o consola, no X11).
        para_x11 = [s for s in salidas if s in ('xcb_x11', 'xcb_xv', 'xcb_window', 'gl', 'glx')]
        if para_x11:
            resultado(True, 'hay salida compatible con X11', ', '.join(para_x11))
        else:
            resultado(False, 'hay salida compatible con X11')
            problemas.append(
                'Falta el módulo de salida para X11 (xcb_x11/xcb_xv). VLC no puede dibujar '
                'en la pantalla: sudo apt install --reinstall vlc-plugin-video-output'
            )
    else:
        resultado(False, 'módulos de salida de vídeo')
        problemas.append(
            'VLC no tiene módulos de salida de vídeo instalados. Sin ellos no puede '
            'dibujar nada: sudo apt install --reinstall vlc-plugin-base'
        )

    titulo('5. Reproducción real de prueba')
    try:
        # Con verbose y sin --quiet: acá interesa ver los errores internos de
        # VLC, que son los que explican por qué no dibuja.
        instancia = vlc.Instance(
            '--autoscale', '--no-video-title-show', '--no-osd',
            '--mouse-hide-timeout=1000', '--verbose=2',
        )
        if instancia is None:
            raise RuntimeError('vlc.Instance() devolvió None')
        resultado(True, 'se creó la instancia de VLC')

        reproductor = instancia.media_player_new()
        reproductor.set_fullscreen(True)

        # Se prueba con un archivo REAL del caché, el mismo que el cliente
        # intenta mostrar. Antes acá se usaba 'fake://', un módulo de VLC que no
        # siempre viene compilado: daba State.Ended al instante y hacía parecer
        # que el entorno gráfico estaba roto cuando el problema era la prueba.
        archivo = _buscar_media_cacheada()
        if archivo is None:
            print('  (no hay archivos en el caché — no se puede probar la reproducción)')
            print('  Ejecutá primero el servicio para que descargue el contenido.')
            return 1

        print(f'  Archivo de prueba: {archivo}')
        media = instancia.media_new(archivo)
        media.add_option(':image-duration=-1')   # las imágenes no se cierran solas
        reproductor.set_media(media)

        if reproductor.play() == -1:
            resultado(False, 'play() arrancó')
            problemas.append('VLC no pudo iniciar la reproducción de prueba.')
        else:
            import time
            estado = None
            for _ in range(30):
                time.sleep(0.1)
                estado = reproductor.get_state()
                if estado in (vlc.State.Playing, vlc.State.Error, vlc.State.Ended):
                    break

            if estado == vlc.State.Playing:
                resultado(True, 'VLC está reproduciendo', f'fullscreen={reproductor.get_fullscreen()}')
                print('\n  >>> MIRÁ LA PANTALLA: durante 8 segundos tiene que verse el contenido.')
                time.sleep(8)

                if not reproductor.get_fullscreen():
                    problemas.append(
                        'Reproduce, pero no en pantalla completa: falta la regla de Openbox '
                        '(docs/09_instalacion_raspberry.md §8.5).'
                    )
            else:
                resultado(False, 'VLC está reproduciendo', f'estado={estado}')
                if estado == vlc.State.Ended:
                    problemas.append(
                        'VLC abrió el archivo y lo cerró de inmediato (State.Ended). Suele ser '
                        'que no hay salida de vídeo utilizable: probá "sudo apt install '
                        '--reinstall vlc-plugin-base vlc-plugin-video-output".'
                    )
                elif estado == vlc.State.Error:
                    problemas.append(
                        'VLC no pudo abrir el archivo (State.Error): puede estar corrupto o '
                        'faltar el códec.'
                    )
                else:
                    problemas.append(f'VLC no llegó a reproducir (estado {estado}).')

        reproductor.stop()
    except Exception as exc:
        resultado(False, 'prueba de reproducción', str(exc))
        problemas.append(f'Falló la prueba de VLC: {exc}')

    titulo('Resumen')
    if problemas:
        for p in problemas:
            print(f'  • {p}')
        return 1

    print('  Todo correcto: el reproductor puede dibujar en pantalla.')
    print('  Si aun así no ves contenido, revisá el gestor de ventanas')
    print('  (docs/09_instalacion_raspberry.md §8.5).')
    return 0


if __name__ == '__main__':
    sys.exit(main())
