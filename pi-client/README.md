# SPUI — cliente Raspberry Pi

Player Python que corre en cada Raspberry Pi del sistema de cartelería SPUI. Independiente del CMS Symfony del resto del repo — su propio `requirements.txt`, su propio deploy, sin dependencias compartidas de código (solo habla con el CMS por HTTP y MQTT).

Cuatro hilos (`main.py`): sync HTTP con caché SQLite offline, heartbeat cada 60s, listener MQTT de alertas de emergencia, publicador de telemetría cada 60s. Detalle de arquitectura, instalación y troubleshooting en `../docs/09_instalacion_raspberry.md` y `../docs/06_funcionamiento_del_sistema.md`.

## Clonar solo esta carpeta

Este directorio vive en el mismo repositorio que el CMS, pero para instalar en una Raspberry Pi no hace falta traer el PHP/Symfony del CMS — `git` permite clonar sólo `pi-client/` con sparse-checkout:

```bash
git clone --filter=blob:none --sparse https://github.com/te02021/spui.git
cd spui
git sparse-checkout set pi-client
```

Con eso, `spui/pi-client/` queda con este directorio completo y nada más del CMS se descarga a disco.

## Instalación

Ver `install.sh` (Raspberry Pi OS Bookworm) y la guía completa en `docs/09_instalacion_raspberry.md`. Resumen: el venv va en `/opt/spui/venv` (el pip del sistema está bloqueado por PEP 668), y el instalador se corre **sin** `sudo` — invoca sudo por dentro donde hace falta.

## Modo simulación

Sin `python-vlc` instalado o sin entorno gráfico (X11), el player entra en modo simulación: loguea lo que reproduciría en vez de renderizar. Toda la lógica de scheduling, alertas y sincronización funciona igual — sólo falta la salida a pantalla. Es el modo en el que corre por default en cualquier máquina que no sea una Pi con X11 configurado.
