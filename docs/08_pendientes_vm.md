# 08 — Puesta en marcha del reproductor: estado del servidor y configuración de red

**SPUI — Sistema de Pantallas Informativas Universitarias (UNRaf)**

> ## ⚠️ Estado de este documento
>
> Se escribió cuando **no había hardware** y el plan era emular el nodo en una máquina virtual x86. **Ahora hay una Raspberry Pi real**, así que:
>
> - **§2 y §5 pasos 1-3 (VirtualBox, imagen x86, crear la VM) ya no aplican.** Para instalar la Pi real, seguir **`09_instalacion_raspberry.md`**.
> - **Lo que sigue plenamente vigente:** §3 (estado del servidor), §4 (la IP del host cambia), §5 pasos 4, 5 y 7 (firewall, datos del CMS, daemon de telemetría), §6 (cómo verificar) y §8 (antes de la defensa).
> - **Con hardware real** sí se pueden probar la temperatura del SoC y el HDMI-CEC, que en VM quedaban en modo simulación (ver §7).
>
> Las direcciones IP que aparecen acá son las del momento en que se verificó. **Cambian por DHCP** — usarlas como ejemplo de formato, no como valores a copiar (ver `README.md` §4).

Este documento se escribió verificando el estado del equipo, no de memoria. Todo lo marcado ✅ está comprobado.

---

## 1. Resumen

El CMS y la API están terminados y probados. **Lo que falta es poner el reproductor en marcha**: el circuito completo todavía no se ejecutó de punta a punta con hardware.

| Pieza | Estado |
|---|---|
| CMS y API | ✅ Funcionando |
| Broker MQTT (Mosquitto) | ✅ Instalado y escuchando |
| Apache accesible desde afuera | ⚠️ Configurado, pero para la subred equivocada (ver §4) |
| Cliente Python (`pi-client/`) | ✅ Escrito, auditado y corregido (ver `06_funcionamiento_del_sistema.md` §10.1) |
| Raspberry Pi física | ✅ Disponible — instalar con `09_instalacion_raspberry.md` |
| ~~VirtualBox~~ | Ya no se necesita: hay hardware real |
| Prueba end-to-end | ❌ Pendiente |

---

## 2. Sobre la VM — ya no aplica

> **Obsoleto.** Se mantiene como registro de la decisión tomada cuando no había hardware.

VirtualBox **no puede emular la arquitectura ARM** de una Raspberry Pi real: solo virtualiza x86/x86_64. Por eso la imagen a usar *era* **Raspberry Pi Desktop x86**, la versión oficial para PC — es Debian por dentro, así que `apt`, `systemd` y el cliente Python funcionan igual.

Lo que no se podía probar en una VM: la temperatura real del SoC, el GPIO, y el apagado por HDMI-CEC. **Con la Pi real esas tres cosas sí se prueban.**

---

## 3. Estado verificado del servidor

### ✅ Ya funcionando

```
Apache (wampapache64)   RUNNING, escuchando en 0.0.0.0:80
Mosquitto               RUNNING, escuchando en 0.0.0.0:1883
```

Mosquitto tiene la configuración necesaria para aceptar conexiones externas, en `D:\Program Files\Mosquitto\mosquitto.conf`:

```
listener 1883 0.0.0.0
allow_anonymous true
```

> ⚠️ **Esta configuración se pierde al reinstalar Mosquitto.** Ya pasó dos veces. Si las alertas o la telemetría dejan de funcionar después de reinstalar algo, revisar esto primero.

### ⚠️ Configurado pero apuntando a la subred equivocada

Cuando se planificó esto, la idea era usar red **Host-only** (`192.168.56.0/24`). Después se decidió usar **Bridged**, para acercarse más a cómo va a ser en producción. Pero las reglas quedaron con la subred vieja:

**Apache** — `c:\wamp64\bin\apache\apache2.4.59\conf\extra\httpd-vhosts.conf`:
```apache
Require local
Require ip 192.168.56.0/24     ← subred host-only, no aplica a Bridged
```

**Firewall de Windows:**
```
SPUI - Apache desde VM     -> remoto: 192.168.56.0/255.255.255.0
SPUI - Mosquitto desde VM  -> remoto: 192.168.56.0/255.255.255.0
```

Con Bridged, la VM recibe una IP de la red WiFi (`10.0.x.x`), que **no coincide con ninguna de esas reglas**. Hay que actualizarlas con la IP real de la VM — ver §5, paso 4.

---

## 4. Un problema a resolver antes: la IP del host cambia

El host toma su IP por DHCP de la red WiFi, y **ya cambió**: era `10.0.12.82` cuando se planificó esto, hoy es `10.0.24.103`. La subred es `10.0.0.0/19` (una red institucional grande, con unos 8000 hosts posibles).

Esto importa porque el nodo apunta al CMS por `SPUI_API_URL`. Si ahí va una IP y el host cambia de dirección, **el nodo deja de sincronizar sin ningún aviso claro**.

Tres formas de resolverlo, de menos a más definitiva:

| Opción | Cómo | Cuándo conviene |
|---|---|---|
| Anotar la IP a mano | En la VM, `/etc/hosts`: `10.0.24.103  intranet` | Para probar hoy. Hay que corregirlo cada vez que cambie |
| Reservar la IP del host | Pedir al área de redes una reserva DHCP por MAC | Si el prototipo va a vivir un tiempo en esta máquina |
| Nombre DNS real | Que el CMS tenga un nombre resoluble en la red | Es lo que corresponde en producción |

Para la primera prueba alcanza la primera opción, pero conviene tenerlo presente: si mañana "deja de andar" sin haber tocado nada, empezar por acá.

---

## 5. Pasos pendientes

### Pasos 1 a 3 — ~~Reinstalar VirtualBox, descargar imagen x86, crear la VM~~

> **Ya no aplican: hay una Raspberry Pi física.**
>
> Reemplazados por **`09_instalacion_raspberry.md`** (formateo de la microSD, grabado de Raspberry Pi OS Lite 64-bit, configuración headless, red y entorno gráfico).
>
> El resto de esta sección (pasos 4 en adelante) **sí sigue vigente** y se referencia desde esa guía.

### Paso 4 — Anotar la IP del reproductor y abrirle el paso

Placeholders según `README.md` §4: `<ip_de_la_pi>` y `<ip_del_host>`.

En la Raspberry Pi:

```bash
hostname -I     # → <ip_de_la_pi>
```

Con esa IP, en el host (la PC donde corre WAMP):

**a) Apache** — editar `httpd-vhosts.conf`, en el bloque `<Directory "c:/wamp64/www/intranet/public/">`:

```apache
Require local
Require ip 192.168.56.0/24
Require ip <ip_de_la_pi>       ← agregar esta línea
```

⚠️ Ese archivo lo comparten **todas** las apps del monolito (viáticos, sgp, sistemasat), no solo SPUI. Poner la IP exacta del reproductor, nunca la subred entera.

> La línea `192.168.56.0/24` quedó del plan con VirtualBox host-only. Con hardware real no aplica, pero dejarla no molesta.

Reiniciar Apache desde el ícono de WAMP → *Restart All Services*.

**b) Firewall** — en PowerShell **como administrador**:

```powershell
netsh advfirewall firewall add rule name="SPUI - Apache desde Pi" dir=in action=allow protocol=TCP localport=80 remoteip=<ip_de_la_pi>
netsh advfirewall firewall add rule name="SPUI - Mosquitto desde Pi" dir=in action=allow protocol=TCP localport=1883 remoteip=<ip_de_la_pi>
```

**c) Comprobar** desde el reproductor:

```bash
curl -i -X POST http://<ip_del_host>/api/spui/reproductores/sync
```

Tiene que devolver **401** con un JSON pidiendo la API key. Eso confirma que la petición llegó a Symfony. Un timeout significa firewall o Apache; un 403 significa que falta la regla `Require ip`.

### Paso 5 — Cargar datos mínimos en el CMS

Ver `07_manual_uso_cms.md`. El camino corto:

1. Edificio → Ubicación → Pantalla
2. Reproductor → **copiar la API key** (se muestra una sola vez). Sólo pide hostname y versión de firmware
3. Volver a **Pantallas** → editar la pantalla → asignarle el reproductor (la asignación se hace desde la Pantalla, no desde el Reproductor)
4. Contenido → publicarlo
5. Playlist → agregarle el contenido
6. Asignar esa playlist como **respaldo** de la pantalla (más simple que crear una regla de programación para la primera prueba)

### Paso 6 — Instalar el cliente en el reproductor

Copiar `apps/spui/pi-client/` a la Pi y dentro de ella:

```bash
echo "<ip_del_host>  intranet" | sudo tee -a /etc/hosts
cd pi-client
chmod +x install.sh
./install.sh
```

> No usar `sudo ./install.sh`: el script detecta el usuario con `whoami` para configurar permisos y el servicio systemd.

El instalador pone VLC y las dependencias Python en un entorno virtual (`/opt/spui/venv`), copia el cliente a `/opt/spui`, crea `/etc/spui/spui.env`, instala el servicio systemd y verifica que las dependencias estén disponibles.

Completar `/etc/spui/spui.env`:

```
SPUI_API_URL=http://intranet/api/spui
SPUI_API_KEY=<la clave copiada en el paso 5>
MQTT_HOST=<ip_del_host>
MQTT_PORT=1883
```

⚠️ `SPUI_API_URL` termina en `/api/spui`, **sin** `/reproductores`.
⚠️ `MQTT_HOST` es la IP del servidor donde corre Mosquitto, **no** `127.0.0.1` (eso apuntaría a la propia Pi).

Arrancar y mirar:

```bash
sudo systemctl start spui
journalctl -u spui -f
```

### Paso 7 — Levantar el consumidor de telemetría

En el host, en una consola aparte:

```bash
cd c:\wamp64\www\intranet
php bin/console spui:mqtt:subscribe --id=spui
```

**Sin este proceso la telemetría no llega a la base**, aunque la Pi la esté publicando. El flag `--id=spui` es obligatorio.

---

## 6. Cómo saber que funciona

| # | Qué probar | Qué tiene que pasar |
|---|---|---|
| 1 | `curl` con la API key al `/sync` desde la Pi | JSON con `pantallas[]` |
| 2 | `journalctl -u spui -f` | "Sync OK", prefetch de archivos, y el player reproduciendo |
| 3 | Monitor conectado a la Pi | Contenido en pantalla, fullscreen |
| 4 | Dashboard del CMS | El reproductor pasa a **conectado**; el primer heartbeat sale al arrancar, después cada ~60 s |
| 5 | Activar una alerta desde `/spui/alertas` | Aparece en el log de la Pi casi al instante e interrumpe lo que esté mostrando |
| 6 | Consola de `spui:mqtt:subscribe` | Líneas con temperatura, RAM y disco cada ~60 s |
| 7 | Telemetría en `/spui/reproductores/{id}/telemetria` | Los gráficos se llenan, con temperatura real del SoC |
| 8 | Contenido tipo **cronograma** en la playlist | Se muestran las actividades del día; si no hay, lo informa en pantalla |
| 9 | Configurar energía con hora de apagado ya pasada | El log dice "Fuera de horario: pantalla apagada" |
| 10 | Desconectar la red de la Pi | Sigue reproduciendo desde caché; al reconectar, sincroniza solo |

La prueba 10 es la que valida el requisito de resiliencia offline, que es uno de los tres puntos que pidió el jurado.

---

## 7. Qué se puede probar con hardware real (y qué no se podía en VM)

Con la Raspberry Pi física se prueba **todo**. Esta tabla queda como referencia de las limitaciones que tenía el plan de VM:

| Funciona en ambos | Sólo con Raspberry Pi real |
|---|---|
| Sincronización y caché offline | Temperatura real del SoC |
| Descarga y verificación de archivos | Encendido/apagado por HDMI-CEC |
| Lógica de horarios y programación | Control de brillo por hardware |
| Alertas por MQTT | GPIO |
| Heartbeat y estado de conexión | Rendimiento real de reproducción |
| Telemetría de RAM y disco | Salida de video HDMI |

En VM la telemetría de temperatura llegaba en cero porque `/sys/class/thermal/thermal_zone0/temp` no existe ahí. **Con la Pi real ese archivo existe y el valor es verdadero.**

---

## 8. Antes de una demo o defensa

Cosas que hoy están bien para desarrollo y **no** para mostrar en serio:

| Tema | Qué hacer |
|---|---|
| ~~MQTT anónimo~~ | ✅ Resuelto — Dynamic Security, `allow_anonymous false`, ACL por rol (tareas 1.0.a/1.0.b) |
| ~~MQTT sin cifrar~~ | ✅ Resuelto — certificado propio (1.0.c), listener 8883 con TLS activo en el broker (1.0.d) y CMS + reproductor 17 verificados conectando por TLS con verificación de hostname (1.0.e) |
| HTTP sin cifrar (API REST) | Falta HTTPS para `/api/spui/...` — no forma parte de las tareas 1.0.a-f (esas son solo MQTT) |
| 1883 sigue abierto sin TLS | **Pendiente a propósito (tarea 1.0.f)** — cerrarlo a `listener 1883 127.0.0.1` en `mosquitto.conf` una vez que 8883 lleve un tiempo probado en uso real. No se hizo apenas se desplegó 8883 para no perder el único canal de conexión si algo del TLS fallara en producción y hubiera que revertir rápido. Si en la demo/defensa las Pis ya conectan todas por 8883 sin problemas, cerrar 1883 antes de mostrarlo — dejarlo abierto sin necesidad es la única deuda de seguridad real que queda de toda la tarea 1.0 |
| CORS abierto | Definir `SPUI_CORS_ORIGINS` con los dominios reales |
| ~~`spui:mantenimiento` sin programar~~ | ✅ Resuelto — ya no hace falta cronearlo: `spui:mqtt:subscribe` lo dispara internamente cada minuto (y el rollup cada hora) mientras corre como servicio. Instalar **un solo** servicio deja las tres cosas funcionando; ver `config/servicios/` (`instalar-servicio.sh` en Linux, `instalar-servicio-telemetria.ps1` en Windows) |
| ~~`spui:mqtt:subscribe` a mano~~ | ✅ Resuelto — servicio (NSSM en Windows, systemd en Linux), mismo instalador que el punto anterior |
| IP del host por DHCP | Reserva DHCP o nombre DNS (ver §4) |
