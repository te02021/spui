# 08 — Servidor: VM de la intranet y entorno de desarrollo

**SPUI — Sistema de Pantallas Informativas Universitarias (UNRaf)**

La instalación de la Raspberry Pi está en `09_instalacion_raspberry.md`; la carga de datos, en `07_manual_uso_cms.md`.

---

## 1. La VM de la intranet frente a lo que pide SPUI

SPUI va a la VM donde ya corren las apps de la intranet. **La fecha todavía no está definida:** esta sección compara, no instala nada. Relevado el 04/11/2026 por línea de comando. Es Debian 12, y Apache atiende PHP por **PHP-FPM**: los valores de abajo son los de `/etc/php/8.4/fpm/`, no los de consola.

| Requisito | En la VM | Estado |
|---|---|---|
| PHP 8.2 o superior (Symfony 7.4) | 8.4.27 | ✅ |
| `pdo_mysql`, `gd`, `curl`, `fileinfo`, `sockets`, `zip` | Están | ✅ |
| **`intl`** | No está | ❌ **Rompe la vista previa del TV:** `PantallaTvService::fechaLegible()` usa `IntlDateFormatter('es_AR', …)`, y sin la extensión el polyfill de Symfony sólo acepta `en` y lanza una excepción. **Decisión de Mateo (04/11/2026): el código sigue usando `intl`; se instala en la VM** |
| `mbstring` | No está | 🟡 El polyfill de Symfony la reemplaza; conviene instalarla |
| Subida de archivos: hasta 200 MB (`MediaStorageService::TAMANIO_MAXIMO`) | `upload_max_filesize` 20M, `post_max_size` 20M | ❌ El CMS toma el menor: **menos de 20 MB por archivo**. Un video institucional no entra. `post_max_size` tiene que ser mayor que `upload_max_filesize` |
| Memoria por pedido | `memory_limit` 128M | 🟡 Los videos ya se entregan de a partes, sin cargarlos en memoria (04/11/2026). Queda `ImagenNormalizerService`, que necesita unos 5 bytes por píxel: una foto de 50 megapíxeles no entra. Conviene subir el límite a 256M |
| CPU y memoria | 2 núcleos; 3,8 GB, 2,0 GB disponibles y swap en uso | 🟡 Compartidos con las otras apps. Las pruebas de carga se midieron en una PC de 8 núcleos y 15,7 GB: **no se pueden trasladar**, hay que repetirlas acá |
| Disco | 61 GB, 5,4 GB libres (91 %) | 🟡 Los medios van a S3, así que SPUI ocupa poco, pero el margen es chico para toda la VM |
| Zona horaria | `date.timezone` UTC | ✅ La compensa `TimezoneSubscriber` |
| MySQL | 8.0.21, la misma versión que declara `doctrine.yaml` | ✅ Falta crear el schema `spui` |
| Configuración de MySQL | `World-writable config file … is ignored` en `conf.d/mysql.cnf` y `mysql.conf.d/mysqld.cnf` | ⚠️ **No es de SPUI y afecta a toda la intranet:** MySQL ignora esos dos archivos porque cualquier usuario puede escribirlos. Corregir los permisos (644) hace que empiecen a aplicarse en el próximo reinicio, así que hay que revisarlos antes |
| Apache con HTTPS | Activo, 80 y 443 escuchando | ✅ |
| Mosquitto 2.x con Dynamic Security | Inactivo o sin instalar; sin 1883, 8883 ni 9001 | ❌ Hay que instalarlo |
| Daemon `spui:mqtt:subscribe` como servicio | No está | ❌ `config/servicios/instalar-servicio.sh` |

## 2. Lo que falta relevar

**Descargas:** cada descarga ocupa un proceso de PHP mientras dura, y el tráfico pasa por la VM. Con pocos procesos, un video nuevo que muchas pantallas bajen a la vez puede demorar al resto de las apps: por eso importa `pm.max_children`. Si hiciera falta, la mejora es que la Pi baje directo de S3, y eso exige cambiar el cliente y el cortafuegos del §9.9 del Informe.

Los módulos de Apache no se pudieron ver: `apache2ctl -M` no mostró ninguno, ni siquiera `ssl`, aunque el 443 escucha. Seguramente hace falta `sudo`. Son sólo consultas:

```bash
sudo apache2ctl -M 2>/dev/null | grep -E 'proxy_wstunnel|proxy_http|proxy_fcgi|proxy_module|ssl|rewrite'
grep -E '^pm(\.max_children)? ' /etc/php/8.4/fpm/pool.d/www.conf
```

También hace falta saber si la VM tiene salida hacia el bucket S3 y si las Pi del piloto llegan al 443 y al 8883.

## 3. Pasos para la migración, cuando se defina la fecha

1. **PHP:** `sudo apt install php8.4-intl php8.4-mbstring`; subir `upload_max_filesize` a 200M y `post_max_size` por encima de eso en `/etc/php/8.4/fpm/php.ini`; reiniciar con `sudo systemctl restart php8.4-fpm`. Afecta a todas las apps de la VM: acordarlo antes.
2. **Base:** crear el schema `spui` y correr `php bin/console doctrine:migrations:migrate --id=spui`.
3. **Mosquitto:** instalarlo y adaptar `config/mosquitto/mosquitto.conf`, que hoy tiene rutas de Windows (certificados y plugin). Generar certificados con el nombre del servidor.
4. **Puertos:** 1883 sólo en `127.0.0.1`; 8883 abierto únicamente a las Pi; 9001 sólo en `127.0.0.1`, sin abrirlo al firewall. Por HTTPS, el navegador entra con `wss://` por el puerto del sitio (ruta `/spui-mqtt`, `MqttSecurityService::RUTA_WEBSOCKET`), así que Apache necesita `proxy_wstunnel` y una regla de esa ruta hacia `ws://127.0.0.1:9001`.
5. **Servicio:** `config/servicios/instalar-servicio.sh`. Un solo servicio cubre telemetría, mantenimiento y rollup.
6. **Entorno:** en la configuración de la VM, `SPUI_MQTT_SALT` (idéntico al de cada Pi), `MQTT_*` y `SPUI_CORS_ORIGINS` con el dominio real. Compilar los assets con `php bin/console asset-map:compile --id=spui`.
7. **Pi:** apuntar `SPUI_API_URL` y `MQTT_HOST` al servidor nuevo y repetir §5.
8. **Pruebas:** repetir CP11 y CP12 en la VM (`10_pruebas.md` §1.1).

## 4. Entorno de desarrollo

Hoy el CMS corre en la PC con WampServer. Apache escucha en el 80 y Mosquitto se instala como servicio de Windows.

```
listener 1883 127.0.0.1     sin TLS, sólo local
listener 8883 0.0.0.0       TLS, lo que usan el CMS y las Pi
listener 9001 0.0.0.0       WebSocket, para el navegador
allow_anonymous false       usuarios y permisos con Dynamic Security
```

⚠️ **La copia de la configuración en el directorio de instalación se pierde al reinstalar Mosquitto.** Ya pasó dos veces. Si dejan de andar las alertas o la telemetría después de reinstalar algo, empezar por `config/mosquitto/README.md`, *Recuperación ante reinstalación de Mosquitto*.

**La IP del host cambia por DHCP.** El reproductor usa el nombre `intranet` de su `/etc/hosts`, y si la dirección cambia deja de sincronizar: se corrige esa línea. En producción corresponde un nombre DNS real.

**Abrirle el paso a una Pi** (placeholders en `README.md` §4). En `httpd-vhosts.conf`, bloque `<Directory "c:/wamp64/www/intranet/public/">`, agregar `Require ip <ip_de_la_pi>` y reiniciar los servicios de WAMP. Ese bloque lo comparten todas las apps: va la IP exacta, nunca la subred. Y en PowerShell como administrador:

```powershell
netsh advfirewall firewall add rule name="SPUI - Apache desde Pi" dir=in action=allow protocol=TCP localport=80 remoteip=<ip_de_la_pi>
netsh advfirewall firewall add rule name="SPUI - Mosquitto desde Pi" dir=in action=allow protocol=TCP localport=8883 remoteip=<ip_de_la_pi>
```

`config/servicios/abrir-firewall-desarrollo.ps1` es otra cosa: abre el 80 a toda la subred para probar QR desde un celular. Se quita con `-Quitar` al terminar.

**Comprobar** desde la Pi con `curl -i -X POST http://<ip_del_host>/api/spui/reproductores/sync`. Tiene que dar **401**. Un timeout es firewall o Apache; un 403 es que falta `Require ip`.

**Daemon de telemetría** (PowerShell como administrador, requiere NSSM):

```powershell
cd c:\wamp64\www\intranet\apps\spui\config\servicios
.\instalar-servicio-telemetria.ps1
```

Sin este proceso la telemetría no llega a la base, y tampoco corren el mantenimiento ni el rollup, que viven dentro de él.

## 5. Cómo saber que funciona

| # | Qué probar | Qué tiene que pasar |
|---|---|---|
| 1 | `curl` con la API key al `/sync` desde la Pi | JSON con `pantallas[]` |
| 2 | `journalctl -u spui -f` | `Sync OK`, prefetch de archivos y reproducción |
| 3 | Pantalla conectada a la Pi | Contenido a pantalla completa |
| 4 | Dashboard del CMS | El reproductor pasa a **Conectado** |
| 5 | Activar una alerta desde `/spui/alertas` | Aparece en el log de la Pi casi al instante |
| 6 | `/spui/reproductores/{id}/telemetria` | Los gráficos se llenan |
| 7 | Energía con la hora de apagado ya pasada | El log dice `Fuera de horario: pantalla apagada` |
| 8 | Cortar la red de la Pi | Sigue con la caché y, al volver, sincroniza sola |

## 6. Antes de una demo o de la defensa

Lo que está bien para desarrollo y no para mostrar en serio:

| Tema | Qué hacer |
|---|---|
| HTTP sin cifrar en el CMS y la API | HTTPS en la VM (§3) |
| CORS abierto | `SPUI_CORS_ORIGINS` con los dominios reales |
| IP del host por DHCP | Nombre DNS en la VM |
| Hardening de la Pi | `README.md` §6 |
