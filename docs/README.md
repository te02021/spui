# Documentación SPUI — Índice y fuente de verdad

**Este archivo es el punto de entrada.** Antes de escribir o modificar cualquier documento de `docs/`, leer esto para saber qué documento manda sobre cada tema y cuál es la nomenclatura correcta.

---

## 1. Qué documento manda sobre qué

La documentación del repo sostiene al **Informe del Proyecto Final** (`Proyecto Final Mateo Rivero - Version Reglamento v9.docx`, local, fuera de git), que es la referencia del proyecto.

| Documento | Alcance | En el Informe |
|---|---|---|
| `06_funcionamiento_del_sistema.md` | **Cómo funciona el sistema por dentro.** Autoridad, verificado contra el código | §9.3 a §9.10 |
| `07_manual_uso_cms.md` | **Uso del panel, pantalla por pantalla.** Autoridad, verificado contra el código | Manual de uso (Tabla 3) |
| `08_pendientes_vm.md` | **Servidor:** qué pide SPUI a la VM de la intranet y entorno de desarrollo | §9.3, §9.9 |
| `09_instalacion_raspberry.md` | Instalación de la Pi desde cero | Anexo A |
| `10_pruebas.md` y `pruebas/` | **Protocolo y registro de CP01 a CP13**, e informes medidos del simulador | §9.11, Anexo B |
| `archivo/auditorias.md` | Historial de decisiones y causas de bugs (§3.1 a §3.6) | — |

`archivo/` guarda además, sólo en esta PC y sin versionar, lo que el Informe ya cubre: `00` investigación (§6 y §7), `01` modelo de datos (§9.6), `02` casos de uso (Tabla 4), `03` diagramas (figuras), `04` Gantt (§9.13), `05` arquitectura (§9.3), los tips de conectividad, el contexto inicial y `ESTADO_DESARROLLO.md`. No se mantienen al día.

---

## 2. Nomenclatura canónica (verificada contra el código)

Esta es la terminología real del sistema. **No usar sinónimos** en documentación nueva.

| Término correcto | Término obsoleto / incorrecto | Dónde está definido |
|---|---|---|
| **Reproductor** | ~~Nodo~~ | `src/Entity/Reproductor.php`, tabla `reproductor` |
| Pantalla | — | `src/Entity/Pantalla.php` |
| Ubicación | — | `src/Entity/Ubicacion.php` |
| Edificio | — | `src/Entity/Edificio.php` |
| **Alerta** / **Alertas** (CMS y pantalla del reproductor) | ~~Alerta de emergencia~~ (en UI) | Entidad y tabla siguen siendo `AlertaEmergencia`/`alerta_emergencia` — **no** se renombraron; solo cambió el texto que ve el usuario, porque "de emergencia" no encajaba con avisos cotidianos (cambio de aula) |

> En **rutas, nombres de entidad, etiquetas de UI y pasos operativos** siempre va **Reproductor**.

### Rutas reales

| Qué | Ruta | Origen |
|---|---|---|
| Panel CMS — listado de reproductores | `/spui/reproductores` | `ReproductorCmsController` |
| Panel CMS — telemetría de uno | `/spui/reproductores/{id}/telemetria` | `ReproductorCmsController` |
| API — sync (lo llama la Pi) | `POST /api/spui/reproductores/sync` | `SyncController` |
| API — heartbeat (lo llama la Pi) | `POST /api/spui/reproductores/heartbeat` | `SyncController` |
| API — media (requiere `X-Api-Key`) | `GET /api/spui/media/{archivo}` | `MediaController` |
| API — redirect de un QR (lo abre el celular) | `GET /api/spui/qr/{id}/r` | `CodigoQrController` |
| API — imagen PNG de un QR | `GET /api/spui/qr/{id}/imagen` | `CodigoQrController` |

### Autenticación de la Pi

- Header: **`X-Api-Key`**
- Valor: la API key en claro, 64 caracteres hex (`bin2hex(random_bytes(32))`)
- El CMS guarda solo `hash('sha256', key)` — ver `ReproductorAuthService`

### Topics MQTT reales

| Topic | Dirección | Origen en código |
|---|---|---|
| `spui/alertas/emergencia` | CMS → Pi (con `retain`) | `AlertaPublisherService::MQTT_TOPIC_ALERTA` y `mqtt_listener.py` |
| `spui/alertas/reproductor/{reproductor_id}` | CMS → Pi (con `retain`) | Alertas dirigidas a pantallas puntuales |
| `spui/comandos/reproductor/{reproductor_id}` | CMS → Pi (sin `retain`, QoS 0) | `ComandoPublisherService::pedirSyncAhora()` → `{"accion":"sync_ahora"}`, único comando que existe. Ver `06` §6.1 |
| `spui/telemetria/{reproductor_id}` | Pi → CMS | `telemetria.py` y `MqttSubscribeCommand::TOPIC_TELEMETRIA` |
| `spui/estado/reproductor/{reproductor_id}` | Pi → CMS | `mqtt_listener.py`: `online` al conectar, `offline` como LWT. Lo consume `MqttSubscribeCommand::procesarEstado()` (ver §3.2) |
| `spui/qr/escaneo/{codigo_qr_id}` | CMS → navegador (WebSocket, 9001) | `QrEscaneoPublisherService` → `qr-escaneos-vivo.js`. Ver §3.3 |
| `spui/cms/conexion/{reproductor_id}` | CMS → navegador (WebSocket, 9001, sin `retain`) | `ConexionPublisherService` → `conexion-vivo.js`: un reproductor cambió de estado de conexión, el navegador vuelve a pedir la tabla (Pantallas, Contenidos, Playlists, Programación y, desde el 30/10/2026, el Dashboard). Ver §3.4 y `06` §6.2 |

> **No hay topics de power/brightness:** el encendido, el apagado y el brillo los resuelve el cliente Pi con el horario energético que baja por `/sync`, así que siguen funcionando aunque el broker esté caído. El único comando que el CMS le manda a una Pi es `sync_ahora`, que no lleva datos: sólo adelanta el próximo sync.

---

## 3. Hechos operativos que se olvidan seguido

Verificados contra código. Si algo "deja de andar", empezar por acá.

1. **La telemetría viaja solo por MQTT.** El Pi la publica al broker; el CMS la persiste con el daemon `php bin/console spui:mqtt:subscribe --id=spui`. **Sin ese proceso corriendo, el dashboard no muestra métricas** aunque la Pi esté publicando bien — y tampoco corren el mantenimiento (reproductores caídos, alertas vencidas, purga) ni el rollup horario, que viven dentro del mismo proceso. Se instala como servicio con `config/servicios/` (ver `06` §11). (Existe además un endpoint REST `/api/spui/reproductores/telemetria`, pero el cliente Python actual **no lo usa**.)

2. **El flag `--id=spui` es obligatorio** en todos los comandos de consola. El monolito aloja varias apps y por consola no hay URL que le indique cuál cargar.

3. **`SPUI_API_URL` termina en `/api/spui`**, sin `/reproductores`. El cliente arma el resto (`sync.py` concatena `/reproductores/sync`).

4. **El formulario de Reproductor pide hostname, versión de firmware y cada cuántos minutos sincroniza** (entero, 1 o más, 5 por defecto; la Pi lo recibe en el sync como `proxima_sync_seg` y `SPUI_SYNC_INTERVAL` de `spui.env` queda sólo como valor de arranque). La pantalla NO se asigna desde ahí — se asigna desde el formulario de **Pantalla**, campo "Reproductor (Raspberry Pi)". La relación es `Pantalla → Reproductor` (ManyToOne): un reproductor puede tener varias pantallas.

5. **La API key se muestra una sola vez**, tanto al registrar el reproductor como al regenerarla, con botón de copiar. No es recuperable: en la base sólo queda su hash. Al regenerarla, la anterior deja de servir en el acto y hay que actualizar `SPUI_API_KEY` en `/etc/spui/spui.env` y reiniciar el servicio. La contraseña MQTT de la Pi se deriva de esa clave, así que se renueva sola con ella.

6. **Rate limiting activo:** 4 sync/min y 3 heartbeat/min por reproductor (`config/packages/rate_limiter.yaml`). Al hacer pruebas manuales con `curl` es fácil comerse un 429.

7. **La config de Mosquitto se pierde al reinstalar.** La que vale es la versionada en `config/mosquitto/mosquitto.conf` (listeners 1883 sólo local, 8883 con TLS para la red y 9001 WebSocket, `allow_anonymous false`, plugin Dynamic Security); la copia del directorio de instalación es descartable. Ya pasó dos veces — procedimiento en `config/mosquitto/README.md`, sección *Recuperación ante reinstalación de Mosquitto*.

8. **El player necesita entorno gráfico.** `python-vlc` no puede renderizar sobre Raspberry Pi OS Lite pelado; si no hay X11 activo, entra en modo simulación y loguea lo que reproduciría.

9. **El servicio corre con el Python del venv**, no el del sistema: `ExecStart=/opt/spui/venv/bin/python3`. En Bookworm el pip del sistema está bloqueado (PEP 668), por eso `install.sh` crea `/opt/spui/venv`. Si se edita el service a mano y se apunta a `/usr/bin/python3`, el cliente muere con `ModuleNotFoundError` y systemd lo reinicia en loop.

10. **La zona horaria la fija la aplicación**, no el php.ini: `TimezoneSubscriber` pone `America/Argentina/Buenos_Aires` en cada request y en cada comando. PHP arranca en UTC en este entorno; sin eso, toda comparación de fechas se corre 3 horas (las alertas figuraban vencidas apenas se creaban).

11. **El almacenamiento de media es conmutable, y S3 es el default** (agosto 2026, antes era `local`): `SPUI_STORAGE_DRIVER=s3` usa el bucket de la intranet, el mismo servicio que viáticos — `local` sigue soportado en `MediaStorageService` sólo como resguardo, no como el modo esperado ni siquiera en desarrollo. Dentro del bucket, `spui/media/` tiene una subcarpeta por tipo: `imagenes/`, `videos/`, `qr/`, `alertas/` (`MediaStorageService::CATEGORIAS` es la única lista que las conoce). `Contenido::rutaArchivo` y `AlertaEmergencia::sonidoArchivo` guardan la ruta completa con categoría (`uploads/spui/imagenes/foo.jpg`), y el Pi la pide en `/api/spui/media/{filename}` con la categoría incluida (`imagenes/foo.jpg`) — el Pi nunca arma esa URL él mismo, siempre la recibe completa del CMS, así que agregar categorías no le rompe nada. **Ojo**: la abstracción sólo la respetan los controllers que la usan explícitamente — antes de agosto 2026, `ContenidoCmsController` y `AlertaCmsController` escribían directo a disco local con `move()`/`hash_file()` ignorando el driver por completo (ver §3.2), así que si aparece un controller nuevo que suba archivos, hay que verificar que pase por `MediaStorageService::guardar()`/`guardarContenido()` con una categoría válida, no asumirlo.

12. **La MAC de `pantalla` es opcional e informativa.** No la consume ninguna función: la identidad del equipo la da el Reproductor por su API key. Es nullable y sin índice único desde `Version20260702133532`.

13. **El sonido de las alertas sale por HDMI (parlantes del TV), nunca por el jack de la Pi.** Necesita `SPUI_AOUT_DEVICE=hdmi:CARD=<id>,DEV=0` en `spui.env` — sin esa variable VLC usa el dispositivo ALSA "default", que no empaqueta el audio en IEC958 como HDMI lo requiere y queda mudo sin ningún error en el log. Procedimiento completo (cómo identificar `<id>` y verificarlo) en `09_instalacion_raspberry.md` §8.6.

14. **El journal de la Pi no es persistente por defecto** (`Storage=auto` sin `/var/log/journal`): un corte de luz o un `reboot` duro borra todo el historial de `journalctl`, incluida la evidencia de lo que acaba de fallar. Activarlo (`sudo mkdir -p /var/log/journal && sudo systemctl restart systemd-journald`) es un paso de instalación (`09_instalacion_raspberry.md` §6.1), no algo para hacer recién cuando ya hace falta. Relacionado: un micro-HDMI flojo puede generar hotplugs intermitentes que el driver de video no siempre recupera solo (monitor sin señal/standby, no solo pantalla negra) — ver §14.2 del mismo documento.

15. **El `php.ini` que de verdad usa Apache en este WAMP no es el que apunta `PHPIniDir`.** Apache corre con `mod_fcgid`, y `httpd-vhosts.conf` le fija `FcgidInitialEnv PHPRC` apuntando directo a `C:\wamp64\bin\php\<versión>\php.ini` — un archivo distinto del symlink `apache2.4.59\bin\php.ini` (→ `phpForApache.ini`) que referencia `PHPIniDir` en `httpd.conf`. `PHPRC` le gana a `PHPIniDir`, así que editar el symlink o `phpForApache.ini` no cambia nada en la práctica, ni siquiera reiniciando WAMP. Para confirmar cuál es el real (antes de tocar cualquier límite de subida), servir un script con `php_ini_loaded_file()` y pedirlo por HTTP — nunca asumir por el nombre del archivo. Los límites que le importan a SPUI (`upload_max_filesize`, `post_max_size`, `memory_limit`) viven en ese `php.ini` real, fuera del repo.

16. **`SPUI_MQTT_SALT` tiene que ser idéntico en el CMS y en cada Pi.** La contraseña MQTT de un reproductor es `sha256(api_key + salt)`; con un salt distinto el broker lo rechaza (`Not authorized`). El síntoma engaña: el sync y el heartbeat van por HTTP y siguen andando, así que la pantalla funciona, pero **las alertas no llegan al instante**, los cambios no llegan en segundos (`sync_ahora`) y la telemetría no se registra. Va en `/etc/spui/spui.env` — ver `09_instalacion_raspberry.md` §11.2.

17. **Con un reproductor desconectado, el CMS no deja cambiar lo que muestra** (botones atenuados + 409 del servidor, ver `06` §6.2). La única acción permitida sobre un equipo caído es darlo de baja. Si un botón "no hace nada", mirar su tooltip antes de buscar un bug. Los botones se destraban solos cuando el equipo vuelve; en Pantallas, Contenidos, Playlists y Programación eso depende del aviso MQTT `spui/cms/conexion/...`: si no se destraban, revisar que el broker y el listener WebSocket 9001 estén arriba — y que el daemon `spui:mqtt:subscribe` esté corriendo, que es quien avisa cuando un equipo se cae.

18. **Si la Pi hace ping al CMS pero no conecta al puerto 80, mirar si hay un antivirus con firewall propio en el host.** Productos como ESET reemplazan al firewall de Windows: las reglas "SPUI – …" de Windows existen y están activas, pero no se aplican, y el antivirus bloquea toda conexión entrante si clasificó la red como no confiable. El síntoma, el 19/10/2026: `Connection to intranet timed out` en el heartbeat y `000` en `curl`, con el ping respondiendo. Para ver quién maneja el firewall: `Get-CimInstance -Namespace root/SecurityCenter2 -ClassName FirewallProduct`. Se resolvió desinstalando ESET (21/10/2026). En el servidor del piloto hay que revisar lo mismo.

19. **El código en disco de la Pi puede ser más nuevo que el que corre.** Si el servicio no se reinicia después de copiar, los tests pasan (leen el disco) y el reproductor sigue con el comportamiento anterior. Pasó el 19/10/2026: el reintento sin red seguía en 30 s fijos con el código del ±10 s ya instalado, y el servicio tenía el PID del arranque. No se pudo determinar por qué no se reinició: la sospecha es un `ssh` del último paso que falló, que el script no revisaba. Desde el 21/10, `deploy.ps1` compara el PID antes y después del reinicio y falla si no cambió. Además, el cliente muestra al arrancar la versión desplegada (`SPUI Client iniciado (versión <commit> <fecha>)`, desde `/opt/spui/VERSION`). Para comprobarlo a mano: `journalctl -u spui -o cat | grep "SPUI Client iniciado" | tail -1`.

20. **La Pi 4 reproduce fluido video H.264 hasta 1920×1080 y 30 cuadros por segundo; el CMS no acepta otros.** Un 2560×1440 se trababa y llegaba a congelar la pantalla (24/10/2026). Ojo con el nombre del archivo: el de prueba se llamaba "uhd-3840-2160" y era de 2560×1440. `VideoInspectorService` lee el archivo real al subirlo y además toma su duración, que es la que usa la playlist; WebM y OGG ya no se aceptan. Si un video "no sube", el motivo está debajo del campo. Ver `06` §5, "Cómo muestra cada contenido".

21. **Las imágenes se adaptan solas al subirlas (derechas, 1920×1080 como máximo) porque una foto grande girada por EXIF cerraba VLC** con `free(): double free detected`, y el servicio entraba en un bucle de reinicios que además agotaba el límite de syncs (24/10/2026). Si igual aparece un contenido que cierra el reproductor, el cliente lo saltea después de dos caídas seguidas y lo informa en el diagnóstico (`spui/guardia.py`). En el journal: `El reproductor se cerró mostrando «…»`.

22. **En la Pi, sólo el hilo de reproducción usa VLC** (`spui/reproduccion.py`). El hilo principal sincroniza y MQTT avisa, pero ninguno de los dos toca VLC: le dicen al hilo de reproducción qué mostrar. Un código nuevo que llame a métodos del player que usan VLC desde otro hilo reabre la carrera que puede cerrar el proceso. Ver `06` §5, "Dos hilos".

23. **No hay tipo de contenido YouTube** (desde el 28/10/2026): VLC no abre el link y YouTube frena las descargas desde la Pi ("confirmá que no sos un bot"). Un video de YouTube se baja desde la PC y se sube como video. Ver `06` §5. Si una Pi tiene instalados Deno, ffmpeg o `yt-dlp` de la prueba de ese día, no molestan: el cliente ya no los usa.

---

## 4. Convención de placeholders — no escribir IPs concretas

Las direcciones IP de este sistema **no son estables**: host del CMS y reproductores toman IP por DHCP, y cambian según la red (desarrollo local, campus, producción). Documentar una IP concreta genera instrucciones que quedan mal a los pocos días.

**Regla:** en la documentación operativa se usan siempre estos placeholders, y cada guía incluye al principio una tabla para que el lector complete sus valores.

| Placeholder | Qué representa | Cómo se obtiene |
|---|---|---|
| `<ip_del_host>` | PC/servidor donde corre el CMS (Apache) y Mosquitto | Windows: `ipconfig` · Linux: `hostname -I` |
| `<ip_de_la_pi>` | Reproductor Raspberry Pi | En la Pi: `hostname -I` · o escaneo de red |
| `<ip_tailscale_del_host>` / `<ip_tailscale_de_la_pi>` | IP `100.x.y.z` que Tailscale le da a cada equipo (acceso remoto de desarrollo, `09` §16). No cambia con la red | En cada equipo: `tailscale ip -4` |
| `<mac_de_la_pi>` | MAC del reproductor (para reservas DHCP) | En la Pi: `ip link show wlan0` / `eth0` |
| `<tu_usuario>` | Usuario del sistema en la Pi | El definido en el Imager; confirmar con `whoami` |
| `<nombre_conexion>` | Perfil de NetworkManager | `nmcli -t -f NAME,DEVICE connection show --active` |

**Excepción — valores que sí son fijos y se escriben literal:** puertos (`80`, `1883`), rutas de API (`/api/spui`), `127.0.0.1` cuando se está señalando específicamente que ese default es incorrecto, y `0.0.0.0` en configuración de listeners.

**Para desacoplar la configuración de la IP del host:** el reproductor mapea el nombre `intranet` en su `/etc/hosts`, y `SPUI_API_URL` apunta a ese nombre. Cuando la IP del host cambia, se corrige una sola línea. En producción corresponde un nombre DNS real.


---

## 5. Cómo mantener esta documentación

- Cuando se modifique el código, actualizar el documento con autoridad sobre ese tema (§1) **y** esta tabla de nomenclatura si cambió algún nombre.
- Antes de escribir un paso operativo (rutas, menús de `raspi-config`, nombres de campos de formulario), **verificarlo contra el código o contra la pantalla real** — no de memoria. Los menús de herramientas externas cambian entre versiones.
- Si un documento queda superado por otro, marcarlo en §1 como obsoleto en vez de dejar dos versiones compitiendo.

---

## 6. Pendientes, según el Informe

Lo que falta para cerrar el Proyecto Final, con la sección del Informe que completa. Plazo: abril de 2027 (un año desde la aprobación del plan, 30/04/2026).

| Pendiente | Sección | Depende de |
|---|---|---|
| Repetir las pruebas sobre la versión final del reproductor (`10_pruebas.md` §1.2) | §9.11 | Mateo, por log |
| Simulador con 10, 25, 50 y 100: gráfico de latencia, recursos y ancho de banda por escenario | §9.11 (a, b) | Se puede en desarrollo; después en la VM |
| CP02, segmentación | Tabla 7 | Segundo reproductor real |
| CP10 con la pantalla del piloto | §9.5, Tabla 7 | Alguien mirando la pantalla |
| Hardening de la Pi: cortafuegos, SSH con clave, actualizaciones, usuario sin `sudo` | §9.9 | Mateo, con vuelta atrás si se corta el SSH |
| Migración a la VM con HTTPS, y CP11 y CP12 ahí (`08_pendientes_vm.md` §3) | §9.3, §9.9, §9.11 | Fecha que define Mateo en el trabajo |
| Piloto: espacios, instalación, período de operación y disponibilidad ≥ 99 % | §8.3, §9.11 (c), Anexo C | Migración |
| CP13, cuestionario SUS | §9.11 (d), Anexo D | Piloto y participantes |
| Medir consumo con vatímetro; costos reales | §11.6, §12.3 | Piloto |
| Constancia escrita de la titularidad y licencia de la fuente Museo | §11.2 | Mateo |
| Anexos A y B, a partir de `09` y `10` | §15 | Pruebas finales |
| Análisis de resultados, conclusiones y resumen | §4, §13 | Todo lo anterior |
| Defensa | — | Informe entregado |
