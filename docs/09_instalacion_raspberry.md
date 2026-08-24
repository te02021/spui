# 09 — Instalación de la Raspberry Pi desde cero

**SPUI — Sistema de Pantallas Informativas Universitarias (UNRaf)**

Guía operativa para dejar una Raspberry Pi funcionando como **reproductor** SPUI: formateo de la microSD, instalación del SO, red, y puesta en marcha del cliente Python.

**Antes de empezar, leer:**
- `README.md` — nomenclatura y hechos operativos que se olvidan seguido
- `08_pendientes_vm.md` — estado real del servidor (Apache, Mosquitto, firewall) y qué falta abrirle al reproductor
- `07_manual_uso_cms.md` — cómo cargar los datos en el CMS

> **Nota sobre `08_pendientes_vm.md`:** ese documento planteaba probar con una VM x86 porque no había hardware. **Ahora hay una Raspberry Pi real**, así que el camino de VirtualBox/Raspberry Pi Desktop x86 ya no aplica. Lo que **sí sigue vigente** de ese doc: la configuración del servidor (§3), el problema de la IP del host (§4), los pasos 4, 5 y 7, y las tablas de verificación (§6). Con hardware real, además, sí funcionan la temperatura del SoC y el HDMI, que en VM no se podían probar.

---

## 0. Datos de tu entorno — completar antes de empezar

Las direcciones IP de esta instalación **cambian**: tanto el host del CMS como la Pi las reciben por DHCP, y además dependen de en qué red estés (casa, campus, otra). Por eso en toda la guía se usan **placeholders**, nunca IPs concretas.

Completá esta tabla una vez y usala como referencia cada vez que aparezca un placeholder:

| Placeholder | Qué es | Cómo obtenerlo | Tu valor |
|---|---|---|---|
| `<tu_usuario>` | Usuario del sistema de la Pi | El que definiste en el Imager (paso 5). Confirmar con `whoami` | |
| `<ip_de_la_pi>` | IP de la Raspberry Pi | En la Pi: `hostname -I` · o escaneando la red con Fing | |
| `<ip_del_host>` | IP de la PC donde corre WAMP (el CMS y Mosquitto) | En Windows: `ipconfig` → IPv4 de la interfaz activa | |
| `<mac_de_la_pi>` | MAC de la Pi (para reserva DHCP) | En la Pi: `cat /sys/class/net/wlan0/address` — devuelve solo la MAC, nada más. Si estás por cable, cambiar `wlan0` por `eth0` | |

**Para obtener la MAC sin saber de antemano qué interfaz estás usando**, este comando lista todas las interfaces con su MAC:

```bash
for i in /sys/class/net/*/address; do echo "$(basename $(dirname $i)): $(cat $i)"; done
```

Salida típica: `eth0: dc:a6:32:...`, `wlan0: dc:a6:32:...`, `lo: 00:00:00:00:00:00` (ignorar `lo`, es la interfaz local). Usá la MAC de la interfaz por la que la Pi está conectada — confirmala con `ip route show default`, que indica cuál se está usando.

> **Ninguna de estas IPs es estable.** Si el sistema "deja de andar" sin haber tocado nada, lo primero a revisar es si alguna cambió — ver paso 11.1 (mapeo por nombre) y el troubleshooting del paso 14.

**Estrategia para no depender de la IP del host:** en la Pi se mapea el nombre `intranet` a `<ip_del_host>` en `/etc/hosts` (paso 11.1). Así toda la configuración del cliente apunta a `intranet`, y cuando la IP cambie solo hay que corregir **una línea**, no la configuración entera. Lo definitivo en producción es un nombre DNS real resoluble en la red.

---

## 1. Requisitos de hardware

| Ítem | Especificación | Notas |
|---|---|---|
| Placa | Raspberry Pi 4 Model B — 4 GB RAM | Definido en `00_investigacion_tecnologias.md` |
| microSD | 16 GB mínimo, clase 10 / A1 o A2 | 32 GB recomendado por la caché de media |
| Fuente | 5V / 3A USB-C | Fuentes flojas causan reinicios y corrupción de SD |
| Cable video | Micro-HDMI a HDMI | La Pi 4 tiene dos micro-HDMI; usar **HDMI0** (el más cercano al USB-C) |
| Pantalla | Monitor o TV con HDMI | 1920×1080 recomendado |
| Red | Ethernet (preferido) o WiFi | Ethernet evita caídas y reautenticación |
| Disipación | Case con ventilador o disipadores | Umbral de alerta del sistema: 80 °C |

---

## 2. Requisitos de software

| Componente | Versión | Dónde se instala |
|---|---|---|
| Raspberry Pi OS Lite 64-bit (Bookworm) | — | En la SD (paso 4) |
| X11 mínimo + Openbox | — | En la Pi (paso 8) — necesario para VLC |
| Python | 3.11+ | Viene con Bookworm |
| VLC | 3.x | Lo instala `install.sh` |
| `requests`, `paho-mqtt`, `python-vlc`, `psutil` | ver `pi-client/requirements.txt` | Lo instala `install.sh` |
| Raspberry Pi Imager | última | En tu PC Windows |

---

## 3. Formatear / limpiar la microSD

Si la tarjeta ya fue usada, conviene borrarla del todo antes de grabar.

### Opción A — Raspberry Pi Imager (recomendada)

1. Instalá **Raspberry Pi Imager** desde [raspberrypi.com/software](https://www.raspberrypi.com/software/).
2. Insertá la microSD en el lector.
3. **Choose OS** → bajar hasta **"Erase"** (en *Utility images*) → formateo completo.
4. **Choose Storage** → seleccioná la microSD (verificá bien cuál es).
5. **Write** → confirmá. Esto borra todo.

### Opción B — SD Card Formatter

Descargar de [sdcard.org](https://www.sdcard.org/downloads/formatter/), elegir la unidad y usar **"Overwrite Format"**.

### Opción C — `diskpart` (si las anteriores no la reconocen)

```
diskpart
list disk            ← identificá la microSD POR SU TAMAÑO
select disk N        ← ¡verificar dos veces antes de confirmar!
clean
convert mbr
exit
```

> `select disk` mal elegido borra el disco de tu PC. Confirmá el tamaño en `list disk`.

---

## 4. Grabar el sistema operativo

1. Abrí Raspberry Pi Imager con la SD ya limpia.
2. **Choose Device** → Raspberry Pi 4.
3. **Choose OS** → *Raspberry Pi OS (other)* → **Raspberry Pi OS Lite (64-bit)**.
4. **Choose Storage** → la microSD.
5. Click en el **engranaje ⚙ / "Edit Settings"** — configurá acá el arranque headless (paso 5) antes de escribir.
6. **Write** → confirmá.

---

## 5. Configuración inicial (en el Imager, antes de escribir)

En **"Edit Settings"**, pestaña *General*:

- **Hostname:** `spui-pi-01` (único por reproductor; es el que vas a registrar en el CMS)
- **Username / Password:** definí tu usuario y contraseña propios.
  > Raspberry Pi OS Bookworm **no crea un usuario `pi`**. El usuario es el que definas acá — anotalo, es el que vas a usar por SSH y el que va a correr el servicio. En esta guía aparece como `<tu_usuario>`.
- **Enable SSH** (pestaña *Services*) → autenticación por contraseña
- **WiFi:** SSID, contraseña y país — solo si no vas a usar cable
- **Locale:** zona horaria `America/Argentina/Buenos_Aires`, teclado `es`

Guardá y recién ahí escribí la imagen.

---

## 6. Primer arranque y acceso por SSH

1. Poné la SD en la Pi, conectá HDMI, red y alimentación.
2. Esperá 1–2 minutos (el primer boot expande el filesystem y reinicia).
3. Averiguá la IP con un escáner de red (Fing) o desde el router.
4. Conectate:
   ```
   ssh <tu_usuario>@<ip_de_la_pi>
   ```
   Los dos valores salen de la tabla del paso 0.

   La contraseña **no va en el comando**: SSH la pide de forma interactiva y no muestra nada mientras la escribís (ni asteriscos). Es normal.

   **Primera conexión:** SSH muestra el fingerprint del host y pregunta `Are you sure you want to continue connecting (yes/no/[fingerprint])?`. Hay que escribir **`yes` completo** (no `y`, no Enter vacío) — cualquier otra cosa corta con `Host key verification failed`.

> `ssh <usuario>@spui-pi-01.local` puede fallar: depende de mDNS, que Windows no resuelve sin Bonjour y que muchas redes WiFi institucionales bloquean. Conectar por IP es lo confiable. Ver `tips_conectividad_acceso.md`.

5. Actualizá el sistema:
   ```bash
   sudo apt update && sudo apt full-upgrade -y
   sudo reboot
   ```

### 6.1. Sobre la IP de la Pi

La Pi toma IP por DHCP. Para que no cambie, las opciones están en `tips_conectividad_acceso.md` (Tips 4 y 5): reserva DHCP por MAC si tenés acceso al router (lo correcto), o IP estática desde la Pi con `nmcli` si no lo tenés.

Anotá la IP que quede — **la vas a necesitar en el paso 7** para abrirle el paso en el firewall del servidor.

---

## 7. Abrirle el paso a la Pi en el servidor del CMS

**Esto se hace en la PC donde corre WAMP, no en la Pi.** Sin esto, la Pi no puede llegar al CMS ni al broker MQTT.

Contexto: `08_pendientes_vm.md` §3 dejó reglas para la subred `192.168.56.0/24` (host-only de VirtualBox), que **no aplican** a una Pi física en la red local. Hay que agregar la IP real de la Pi.

**a) Averiguá la IP del host (donde corre WAMP)** — en Windows:
```powershell
ipconfig
```
Anotá la IPv4 de la interfaz activa — ese es tu `<ip_del_host>` (tabla del paso 0).

> `08_pendientes_vm.md` menciona una IP concreta como ejemplo de aquel momento. **No la uses**: era la de otra red y ya cambió. Ese documento mismo advierte que la IP del host cambia por DHCP.

**b) Apache** — editar `c:\wamp64\bin\apache\apache2.4.59\conf\extra\httpd-vhosts.conf`, en el bloque `<Directory "c:/wamp64/www/intranet/public/">`:
```apache
Require local
Require ip 192.168.56.0/24
Require ip <ip_de_la_pi>        ← agregar esta línea
```
> Ese archivo lo comparten todas las apps del monolito. Poner la IP exacta de la Pi, no la subred entera.

Reiniciar Apache: ícono de WAMP → *Restart All Services*.

**c) Firewall de Windows** — PowerShell **como administrador**:
```powershell
netsh advfirewall firewall add rule name="SPUI - Apache desde Pi" dir=in action=allow protocol=TCP localport=80 remoteip=<ip_de_la_pi>
netsh advfirewall firewall add rule name="SPUI - Mosquitto desde Pi" dir=in action=allow protocol=TCP localport=1883 remoteip=<ip_de_la_pi>
```

**d) Verificá desde la Pi** (por SSH):
```bash
curl -i -X POST http://<ip_del_host>/api/spui/reproductores/sync
```
Tiene que devolver **401** con un JSON pidiendo la API key. Eso confirma que la petición llegó a Symfony.
- **Timeout** → firewall o Apache no escucha
- **403** → falta la línea `Require ip`
- **401** → ✅ todo bien, seguí

---

## 8. Entorno gráfico mínimo (necesario para VLC)

Raspberry Pi OS Lite no trae entorno gráfico. `python-vlc` necesita X11 para dibujar; sin él, el cliente arranca igual pero en **modo simulación** (loguea lo que reproduciría, pantalla vacía).

### 8.1. Instalar paquetes

```bash
sudo apt install -y xserver-xorg xinit x11-xserver-utils openbox unclutter x11-utils
```

| Paquete | Para qué |
|---|---|
| `xserver-xorg` | El servidor gráfico X11 |
| `xinit` | Provee `startx`, que lo arranca |
| `x11-xserver-utils` | Trae `xset` (desactivar salvapantallas) |
| `openbox` | Gestor de ventanas mínimo — VLC necesita uno para ir a fullscreen |
| `unclutter` | Esconde el cursor del mouse |
| `x11-utils` | Trae `xdpyinfo`, que usa `diagnostico.py` para comprobar la pantalla |

**Y los módulos de salida de vídeo de VLC:**

```bash
sudo apt install -y vlc vlc-plugin-base
```

> **Por qué explícito:** en Raspberry Pi OS **Lite** el paquete `vlc` no siempre arrastra
> los módulos de salida de vídeo, porque el sistema base no tiene entorno gráfico. Sin
> ellos VLC abre el archivo, no encuentra dónde dibujarlo y lo cierra al instante: el
> cliente informa "Reproduciendo" y **la pantalla queda en negro**, sin ningún error.
> `install.sh` ya los instala y avisa si faltan.
>
> Para comprobar que están:
> ```bash
> ls /usr/lib/*/vlc/plugins/video_output/ | head
> ```
> Tiene que listar varios `.so` (`libxcb_x11_plugin.so`, `libgles2_plugin.so`, …). Si el
> directorio no existe o está vacío:
> ```bash
> sudo apt install --reinstall vlc-plugin-base
> ```

### 8.2. Autologin en consola (`raspi-config`)

```bash
sudo raspi-config
```

Se navega con flechas y Enter. Ruta verificada en Bookworm Lite:

1. `1 System Options` → Enter
2. `S5 Boot` → elegir **`Console`** (*Text console*). **No** elegir `Desktop`: en Lite no hay escritorio instalado.
3. Volver atrás (`Esc`) → `S6 Auto Login` → responder **`Yes`** a *"Would you like to automatically login to the console?"*
4. Salir con `Finish`. Si ofrece reiniciar, elegí **No** (reiniciamos al final).

> Si tu menú no coincide con esto, pasá lo que ves antes de seguir — la estructura de `raspi-config` cambia entre versiones.

**Cómo verificar que quedó activo** (sin reiniciar):

```bash
systemctl cat getty@tty1 | grep -i autologin
```

Tiene que aparecer una línea `ExecStart=... --autologin <tu_usuario> ...`. Si no aparece
nada, el autologin **no** está configurado: tras un reinicio nadie inicia sesión en la
consola, `~/.bashrc` no se ejecuta, `startx` no arranca y **el monitor queda sin señal**.

Otra comprobación útil, ésta después de reiniciar:

```bash
pgrep -a Xorg
```

Si devuelve un proceso, X11 arrancó solo y está todo bien. Si no devuelve nada, revisá el
autologin y el bloque de `~/.bashrc` del paso 8.3.

### 8.3. Arrancar X11 automáticamente al iniciar sesión

Esto va en `~/.bashrc`, que Linux ejecuta al iniciar sesión en la terminal. `~` es tu carpeta personal (`/home/<tu_usuario>`).

> **Ojo:** `~/.bashrc` es un archivo de configuración, no un programa. Si escribís `~/.bashrc` solo en la terminal, bash intenta *ejecutarlo* y da `Permission denied`. Se edita o se le agrega contenido, nunca se "corre".

Copiá y pegá este bloque completo (incluidas las líneas `EOF`) y Enter:

```bash
cat >> ~/.bashrc << 'EOF'

if [ -z "$DISPLAY" ] && [ "$(tty)" = "/dev/tty1" ]; then
  startx -- -nocursor
fi
EOF
```

`cat >> archivo << 'EOF' … EOF` agrega el texto al final del archivo (`>>` = agregar sin borrar lo existente). No abre ningún editor.

Verificá:
```bash
tail -5 ~/.bashrc
```

Qué hace: al iniciar sesión en la consola física (`tty1`, la del HDMI) y si no hay entorno gráfico activo, arranca X11 sin cursor. Combinado con el autologin de 8.2, todo el arranque pasa solo.

### 8.4. Crear `~/.xinitrc`

`startx` busca este archivo para saber qué ejecutar:

```bash
cat > ~/.xinitrc << 'EOF'
#!/bin/sh
xset s off
xset s noblank
xset -dpms
unclutter -idle 0.5 -root &
exec openbox-session
EOF
```

Acá va un solo `>` porque **creamos** el archivo desde cero (con `>>` agregaríamos a uno existente).

| Línea | Qué hace |
|---|---|
| `xset s off` / `xset s noblank` | Desactivan el salvapantallas |
| `xset -dpms` | Desactiva el ahorro de energía del HDMI |
| `unclutter -idle 0.5 -root &` | Esconde el cursor; `&` lo deja en segundo plano |
| `exec openbox-session` | Arranca Openbox — acá VLC podrá abrir su ventana |

Permiso de ejecución:
```bash
chmod +x ~/.xinitrc
cat ~/.xinitrc      # verificar contenido
```

### 8.5. Quitarle el marco de ventana a VLC (Openbox)

Sin esto el contenido se ve **dentro de una ventana**, con su barra de título y los
botones de minimizar y cerrar — como si alguien hubiera abierto un visor de imágenes.
En una cartelera eso no puede verse.

El cliente ya le pide a VLC pantalla completa y sin bordes, pero el marco lo dibuja
Openbox, así que hay que decírselo también a él:

```bash
mkdir -p ~/.config/openbox
cat > ~/.config/openbox/rc.xml << 'EOF'
<?xml version="1.0" encoding="UTF-8"?>
<openbox_config xmlns="http://openbox.org/3.4/rc">
  <applications>
    <!-- Cualquier ventana de VLC: sin decoración, pantalla completa,
         siempre al frente y sin aparecer en la barra de tareas. -->
    <application class="vlc">
      <decor>no</decor>
      <fullscreen>yes</fullscreen>
      <maximized>yes</maximized>
      <layer>above</layer>
      <skip_taskbar>yes</skip_taskbar>
      <skip_pager>yes</skip_pager>
    </application>
  </applications>
</openbox_config>
EOF
```

Aplicar los cambios. Openbox corre en la sesión gráfica de la Pi, no en la de SSH,
así que hay que indicarle a qué pantalla hablarle:

```bash
DISPLAY=:0 openbox --reconfigure
```

```bash
sudo systemctl restart spui
```

> Si `openbox --reconfigure` (sin el `DISPLAY=:0`) devuelve
> **`Failed to open the display from the DISPLAY environment variable`**, es exactamente
> esto: por SSH no hay pantalla asociada. Con el prefijo funciona.
>
> Si además aparece **`Unable to make directory '/home/<usuario>/.cache/openbox'`**, es un
> aviso de permisos que no impide que la regla se aplique. Para que no vuelva a salir:
> ```bash
> mkdir -p ~/.cache && sudo chown -R "$USER:$USER" ~/.cache
> ```

**La alternativa más simple y segura:** reiniciar la Pi. Openbox lee `rc.xml` al arrancar,
así que no hace falta ningún comando.
```bash
sudo reboot
```

> **Cómo saber si funcionó:** el contenido tiene que ocupar **toda** la pantalla, sin
> barra de título arriba ni bordes. Una imagen más chica que la pantalla se agranda
> conservando su proporción; si no coincide con la relación de aspecto del monitor,
> las franjas que sobran quedan en negro (nunca recortada).

---

## 9. Cargar los datos mínimos en el CMS

**En el navegador de tu PC**, no en la Pi. Detalle completo en `07_manual_uso_cms.md`; el camino corto:

1. **Edificio** (`/spui/edificios`)
2. **Ubicación** (`/spui/ubicaciones`) — dentro del edificio
3. **Pantalla** (`/spui/pantallas`) — nombre, ubicación, MAC, resolución
4. **Reproductor** (`/spui/reproductores`) → *Registrar*:
   - Solo pide **hostname** (el mismo del paso 5, ej. `spui-pi-01`) y **versión de firmware** (opcional).
   - Al guardar, un modal muestra la **API key de 64 caracteres una sola vez**. **Copiala ahora** — solo se guarda el hash, no es recuperable. Si la perdés: botón *Regenerar clave* (invalida la anterior).
5. **Volver a Pantallas** → editar la pantalla → campo **"Reproductor (Raspberry Pi)"** → asignarle el reproductor recién creado.
   > La asignación se hace **desde la Pantalla**, no desde el Reproductor. El formulario de Reproductor no tiene selector de pantalla.
6. **Contenido** (`/spui/contenidos`) → crear y **publicarlo** (en borrador no se puede usar).
7. **Playlist** (`/spui/playlists`) → crearla y agregarle el contenido.
8. **Volver a la Pantalla** → asignar esa playlist como **playlist de respaldo (fallback)**.
   > Para la primera prueba es más simple que crear una regla de programación: el fallback se reproduce siempre que no haya programación activa.

---

## 10. Instalar el cliente en la Pi

Copiá la carpeta `pi-client/` a la Pi. **Desde tu PC** (PowerShell):

```powershell
cd C:\wamp64\www\intranet\apps\spui
scp -r pi-client <tu_usuario>@<ip_de_la_pi>:~/pi-client
```

`scp -r origen usuario@ip:destino` copia recursivamente por SSH; pide la misma contraseña que el login.

**En la Pi** (por SSH):

```bash
cd ~/pi-client
chmod +x install.sh
./install.sh
```

`chmod +x` da permiso de ejecución; el `./` indica "este archivo, en la carpeta actual".

> **No usar `sudo ./install.sh`.** El script detecta tu usuario con `whoami` para configurar permisos y el servicio systemd; corriéndolo como root se configuraría mal. Pide `sudo` internamente donde hace falta.

El script:
1. Instala VLC, Python 3 y venv vía `apt`
2. Crea el entorno virtual e instala `requirements.txt`
3. Copia el cliente a `/opt/spui`
4. Crea `/etc/spui/spui.env` con placeholders
5. Instala y habilita el servicio `spui.service`

---

## 11. Configurar la conexión al CMS

### 11.1. Resolver el nombre del servidor

La IP del host cambia (DHCP). Para no tener que editar la config cada vez, mapear un nombre en la Pi:

```bash
echo "<ip_del_host>  intranet" | sudo tee -a /etc/hosts
```

Así `SPUI_API_URL` apunta a `intranet` y cuando la IP del host cambie solo hay que corregir esta línea. (Lo definitivo en producción es un nombre DNS real — ver `08_pendientes_vm.md` §4.)

### 11.2. Editar el archivo de entorno

```bash
sudo nano /etc/spui/spui.env
```

`sudo` hace falta porque el archivo pertenece a root. `nano` es el editor de terminal: se mueve con las flechas, se borra con Backspace.

Valores a completar:

```
SPUI_API_URL=http://intranet/api/spui
SPUI_API_KEY=<la API key copiada en el paso 9>
MQTT_HOST=<ip_del_host>
MQTT_PORT=1883
```

> ⚠️ **`SPUI_API_URL` termina en `/api/spui`, sin `/reproductores`** — el cliente concatena el resto solo (`sync.py`).
>
> ⚠️ **`MQTT_HOST` es la IP del servidor donde corre Mosquitto**, no `127.0.0.1`. El default `127.0.0.1` apuntaría a la propia Pi, donde no hay broker.

**Guardar y salir de nano:** `Ctrl+O` → `Enter` → `Ctrl+X`.
Para salir sin guardar: `Ctrl+X` y responder `N`.

Verificar:
```bash
sudo cat /etc/spui/spui.env
```

---

## 12. Levantar el consumidor de telemetría (en el servidor)

**En la PC del CMS**, en una consola aparte:

```
cd c:\wamp64\www\intranet
php bin/console spui:mqtt:subscribe --id=spui
```

⚠️ **Sin este proceso corriendo, la telemetría no llega a la base de datos** aunque la Pi la publique correctamente. El flag `--id=spui` es obligatorio (el monolito no sabe qué app cargar por consola).

Dejá esa consola abierta mientras probás.

---

## 13. Arrancar el cliente y verificar

En la Pi:

```bash
sudo systemctl start spui       # arranca el servicio ahora
sudo systemctl status spui      # estado: 'active (running)' en verde; salir con q
journalctl -u spui -f           # logs en vivo; salir con Ctrl+C
```

Confirmar que arranca solo en cada boot (el instalador ya lo habilitó):
```bash
sudo systemctl is-enabled spui  # debe responder: enabled
```

### Checklist de verificación end-to-end

Adaptado de `08_pendientes_vm.md` §6 — con hardware real, ahora **sí** aplican las pruebas de temperatura y HDMI:

| # | Qué probar | Qué tiene que pasar |
|---|---|---|
| 1 | `curl` con la API key al `/sync` desde la Pi | JSON con `pantallas[]` |
| 2 | `journalctl -u spui -f` | "Sync OK", prefetch de archivos, player reproduciendo |
| 3 | Monitor conectado a la Pi | Contenido en pantalla, fullscreen, sin cursor |
| 4 | Dashboard del CMS (`/spui`) | El reproductor pasa a **conectado**, heartbeat cada ~60 s |
| 5 | Activar una alerta desde `/spui/alertas` | Aparece en el log de la Pi casi al instante |
| 6 | Consola de `spui:mqtt:subscribe` | Líneas con temperatura, RAM y disco cada ~60 s |
| 7 | `/spui/reproductores/{id}/telemetria` | Los gráficos se llenan. **Con Pi real la temperatura es un valor verdadero** (en VM venía vacía) |
| 8 | Configurar energía con hora de apagado ya pasada | El log dice "Fuera de horario: pantalla apagada" |
| 9 | Desconectar la red de la Pi | Sigue reproduciendo desde caché; al reconectar sincroniza solo |

La prueba 9 valida el requisito de resiliencia offline exigido por el jurado.

### Reinicio final

```bash
sudo reboot
```

Confirmá que todo levanta solo: autologin → X11 → Openbox → servicio spui → VLC en pantalla, sin intervención manual.

---

## 14. Troubleshooting

| Síntoma | Causa probable | Solución |
|---|---|---|
| `curl` al `/sync` da **timeout** | Firewall de Windows o Apache no acepta la IP de la Pi | Paso 7 (b) y (c) |
| `curl` al `/sync` da **403** | Falta `Require ip <ip_de_la_pi>` en `httpd-vhosts.conf` | Paso 7 (b) + reiniciar Apache |
| `curl` al `/sync` da **401** | Es lo esperado sin API key — no es un error | Seguir adelante |
| **429** en los logs del cliente | Rate limit: 2 sync/min, 3 heartbeat/min por reproductor | Esperar; no lanzar `curl` repetidos mientras el servicio corre |
| Servicio se reinicia en loop | `/etc/spui/spui.env` con placeholders sin completar | `sudo cat /etc/spui/spui.env` y completar (paso 11) |
| `SPUI_API_KEY no configurada` en el log | Falta la key en el env | Paso 11.2 |
| Sync OK, log dice **`Modo SIMULACIÓN`** | X11 no está corriendo → el cliente ni intenta usar VLC | Paso 8; verificar con `pgrep -a Xorg` |
| Sync OK, log dice **`VLC listo`** pero la pantalla sigue negra | VLC eligió una salida de vídeo que no dibuja | Ver §14.1 |
| Reproductor **conectado** pero telemetría vacía | `spui:mqtt:subscribe` no está corriendo, o Mosquitto no acepta conexiones externas | Paso 12; revisar `listener 1883 0.0.0.0` y `allow_anonymous true` en `mosquitto.conf` |
| Alertas no llegan al instante (sí en el próximo sync) | Sin conexión MQTT desde la Pi | Verificar `MQTT_HOST` (no `127.0.0.1`) y la regla de firewall del puerto 1883 |
| Dejó de sincronizar sin haber tocado nada | La IP del host cambió (DHCP) | Actualizar la línea de `/etc/hosts` (paso 11.1) y las reglas del paso 7 |
| Pantalla en negro con servicio `active (running)` | Ahorro de energía HDMI | Verificar que `~/.xinitrc` tenga `xset -dpms` y `xset s off` |
| `Permission denied` al escribir `~/.bashrc` en la terminal | Se intentó ejecutar un archivo de configuración | No se "corre": se edita con `nano` o se le agrega con `cat >>` (paso 8.3) |
| `Host key verification failed` | Se respondió algo distinto de `yes` al fingerprint | Reintentar y escribir `yes` completo |
| `REMOTE HOST IDENTIFICATION HAS CHANGED` | Se reflasheó la SD: clave SSH nueva | `ssh-keygen -R <ip>` en tu PC y reconectar |
| Temperatura > 80 °C sostenida | Disipación insuficiente | Ventilador/disipadores; revisar ventilación del case |

### 14.1. Pantalla negra aunque el log diga que está reproduciendo

El caso más engañoso: el log muestra `VLC listo` y `Reproduciendo [imagen] ... (permanente)`,
sin ningún error, y el monitor está en negro.

**Cómo distinguir las dos causas posibles, en un solo comando:**

```bash
DISPLAY=:0 xwininfo -root -children | head -20
```

| Qué muestra | Qué significa | Solución |
|---|---|---|
| Una ventana de **1920x1080** (o el tamaño de tu pantalla) | VLC creó la ventana pero **dibuja en un destino inválido** | Es la salida de vídeo: seguir abajo |
| **Ninguna** ventana de ese tamaño | VLC no llegó a crear la ventana | Revisar X11 (paso 8) y el gestor de ventanas |

**Si la ventana existe pero está negra**, VLC eligió un módulo de salida que no funciona en esta
Pi. Por defecto el cliente fuerza `xcb_x11`, que es el más compatible; si aun así falla, probá
otro agregando esta línea a `/etc/spui/spui.env`:

```
SPUI_VOUT=xcb_xv
```

```bash
sudo systemctl restart spui
```

Alternativas en orden de compatibilidad: `xcb_x11` (por defecto) → `xcb_xv` → `gl` → `glx`.
El log dice cuál se está usando:

```
VLC listo (salida de vídeo: xcb_x11) — el contenido se va a mostrar en pantalla.
```

> **Por qué pasa:** VLC trae más de veinte módulos de salida y, sin `--vout` explícito, elige
> uno solo. En la Pi 4 elegía uno basado en OpenGL (`gl` / `egl_x11`) que crea la ventana pero
> no llega a dibujar — e informa `State.Playing` igual, sin ningún error. Por eso el log decía
> que todo estaba bien.

---

## 15. Rutas en la Pi

| Ruta | Contenido |
|---|---|
| `/opt/spui` | Código del cliente Python |
| `/etc/spui/spui.env` | Configuración (API URL, API key, MQTT) |
| `/var/cache/spui` | Caché de media descargada |
| `/var/cache/spui/spui.db` | SQLite: último sync (contenido, playlist, programación) |
| `/var/log/spui-client.log` | Log del cliente |
| `/etc/systemd/system/spui.service` | Unit de systemd |
| `~/.xinitrc` | Qué ejecuta X11 al arrancar |
| `/etc/hosts` | Mapeo `intranet` → IP del CMS |
