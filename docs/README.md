# Documentación SPUI — Índice y fuente de verdad

**Este archivo es el punto de entrada.** Antes de escribir o modificar cualquier documento de `docs/`, leer esto para saber qué documento manda sobre cada tema y cuál es la nomenclatura correcta.

---

## 1. Qué documento manda sobre qué

| Documento | Alcance | Autoridad |
|---|---|---|
| `00_investigacion_tecnologias.md` | Comparativas y decisiones de stack | Histórico de decisiones (por qué se eligió cada cosa) |
| `01_mer_base_datos.md` | Modelo entidad-relación | Diseño de datos |
| `02_casos_de_uso.md` | Los 12 casos de uso | Requerimientos — verificado contra el código (agosto 2026) |
| `03_diagramas_flujo.md` | Diagramas de flujo | Diseño |
| `04_gantt.md` | Planificación temporal | Gestión |
| `05_arquitectura_sistema.md` | Diagramas C4 y despliegue | Diseño — verificado contra el código (agosto 2026) |
| `06_funcionamiento_del_sistema.md` | **Cómo funciona el sistema por dentro** | ✅ **Autoridad. Verificado contra código** |
| `07_manual_uso_cms.md` | **Uso del panel CMS, pantalla por pantalla** | ✅ **Autoridad. Verificado contra código** |
| `08_pendientes_vm.md` | **Configuración del servidor y red para el reproductor** | ✅ Autoridad para §3-§8. Sus §2 y §5 pasos 1-3 (VirtualBox) quedaron obsoletos al haber hardware real |
| `09_instalacion_raspberry.md` | Instalación de la Pi desde cero (SO, red, cliente) | ✅ Guía operativa, alineada a 06/07/08 |
| `tips_conectividad_acceso.md` | Tips sueltos de red y acceso SSH | Referencia puntual, no secuencial |

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

> Los documentos `00`–`05` se actualizaron en agosto de 2026 y ya no usan "Nodo" salvo en notas históricas explícitas sobre el renombre. En **rutas, nombres de entidad, etiquetas de UI y pasos operativos** siempre va **Reproductor**.

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
| `spui/telemetria/{reproductor_id}` | Pi → CMS | `telemetria.py` y `MqttSubscribeCommand::TOPIC_TELEMETRIA` |

> **No hay topics de control** (`power` / `brightness`): el encendido y apagado los resuelve el cliente Pi con el horario energético que baja por `/sync`, así que sigue funcionando aunque el broker esté caído.

---

## 3. Hechos operativos que se olvidan seguido

Verificados contra código. Si algo "deja de andar", empezar por acá.

1. **La telemetría viaja solo por MQTT.** El Pi la publica al broker; el CMS la persiste con el daemon `php bin/console spui:mqtt:subscribe --id=spui`. **Sin ese proceso corriendo, el dashboard no muestra métricas** aunque la Pi esté publicando bien. (Existe además un endpoint REST `/api/spui/reproductores/telemetria`, pero el cliente Python actual **no lo usa**.)

2. **El flag `--id=spui` es obligatorio** en todos los comandos de consola. El monolito aloja varias apps y por consola no hay URL que le indique cuál cargar.

3. **`SPUI_API_URL` termina en `/api/spui`**, sin `/reproductores`. El cliente arma el resto (`sync.py` concatena `/reproductores/sync`).

4. **El formulario de Reproductor solo pide hostname y versión de firmware.** La pantalla NO se asigna desde ahí — se asigna desde el formulario de **Pantalla**, campo "Reproductor (Raspberry Pi)". La relación es `Pantalla → Reproductor` (ManyToOne): un reproductor puede tener varias pantallas.

5. **La API key se muestra una sola vez**, tanto al registrar el reproductor como al regenerarla, con botón de copiar. No es recuperable: en la base sólo queda su hash. Al regenerarla, la anterior deja de servir en el acto y hay que actualizar `API_KEY` en `/etc/spui/spui.env` y reiniciar el servicio.

6. **Rate limiting activo:** 2 sync/min y 3 heartbeat/min por reproductor (`config/packages/rate_limiter.yaml`). Al hacer pruebas manuales con `curl` es fácil comerse un 429.

7. **La config de Mosquitto se pierde al reinstalar** (`listener 1883 0.0.0.0` + `allow_anonymous true`). Ya pasó dos veces — ver `08_pendientes_vm.md` §3.

8. **El player necesita entorno gráfico.** `python-vlc` no puede renderizar sobre Raspberry Pi OS Lite pelado; si no hay X11 activo, entra en modo simulación y loguea lo que reproduciría.

9. **El servicio corre con el Python del venv**, no el del sistema: `ExecStart=/opt/spui/venv/bin/python3`. En Bookworm el pip del sistema está bloqueado (PEP 668), por eso `install.sh` crea `/opt/spui/venv`. Si se edita el service a mano y se apunta a `/usr/bin/python3`, el cliente muere con `ModuleNotFoundError` y systemd lo reinicia en loop.

10. **La zona horaria la fija la aplicación**, no el php.ini: `TimezoneSubscriber` pone `America/Argentina/Buenos_Aires` en cada request y en cada comando. PHP arranca en UTC en este entorno; sin eso, toda comparación de fechas se corre 3 horas (las alertas figuraban vencidas apenas se creaban).

11. **El almacenamiento de media es conmutable**: `SPUI_STORAGE_DRIVER=local` (default) o `s3`. Con `s3` usa el bucket de la intranet, el mismo servicio que viáticos. El Pi no se entera: siempre descarga de `/api/spui/media/{filename}`.

12. **La MAC de `pantalla` es opcional e informativa.** No la consume ninguna función: la identidad del equipo la da el Reproductor por su API key. Es nullable y sin índice único desde `Version20260702133532`.

13. **El sonido de las alertas sale por HDMI (parlantes del TV), nunca por el jack de la Pi.** Necesita `SPUI_AOUT_DEVICE=hdmi:CARD=<id>,DEV=0` en `spui.env` — sin esa variable VLC usa el dispositivo ALSA "default", que no empaqueta el audio en IEC958 como HDMI lo requiere y queda mudo sin ningún error en el log. Procedimiento completo (cómo identificar `<id>` y verificarlo) en `09_instalacion_raspberry.md` §8.6.

14. **El journal de la Pi no es persistente por defecto** (`Storage=auto` sin `/var/log/journal`): un corte de luz o un `reboot` duro borra todo el historial de `journalctl`, incluida la evidencia de lo que acaba de fallar. Activarlo (`sudo mkdir -p /var/log/journal && sudo systemctl restart systemd-journald`) es un paso de instalación (`09_instalacion_raspberry.md` §6.1), no algo para hacer recién cuando ya hace falta. Relacionado: un micro-HDMI flojo puede generar hotplugs intermitentes que el driver de video no siempre recupera solo (monitor sin señal/standby, no solo pantalla negra) — ver §14.2 del mismo documento.

---

## 3.1. Registro de auditorías

| Fecha | Alcance | Resultado |
|---|---|---|
| Agosto 2026 | Auditoría cruzada de código vs. documentación: 13 entidades, 11 controllers API, 12 controllers CMS, formularios, migraciones y los 8 módulos del cliente Python | 9 inconsistencias detectadas y corregidas. Detalle en `06_funcionamiento_del_sistema.md` §10.1 |
| 10/08/2026 | Reescritura de `00`–`05` contra el código real + corrección de bugs reportados en pruebas de uso | Docs `00`–`05` actualizados (ERD con las 13 entidades, flujos reales, árbol de directorios real). Corregidos: zona horaria, expiración de alertas, API key al regenerar, errores de formulario en español, modales de detalle, alertas dirigidas, almacenamiento S3. Ver §3.2 |

Resumen de lo corregido (todo aplicado, nada pendiente):

1. `spui.service` apuntaba al Python del sistema en vez del venv → el cliente no arrancaba
2. El player ignoraba el tipo `cronograma` → no se mostraba nada en pantalla
3. El fallback dependía de un PNG no versionado → pantalla en negro
4. La API de pantallas exigía MAC y validaba unicidad contra un esquema que ya no lo requería
5. La API de pantallas no permitía asignar reproductor ni playlist de respaldo
6. Código muerto en la caché SQLite del cliente (`media_cache`)
7. El primer heartbeat tardaba 60 s → el reproductor figuraba desconectado al arrancar
8. `python-vlc>=3.0.20120801` en `requirements.txt` era una versión inexistente → `install.sh` fallaba al instalar dependencias
9. `paho-mqtt>=1.6.1` instalaba la 2.x, incompatible con el código → telemetría y alertas fallaban en silencio

---

## 3.2. Correcciones de agosto 2026 (pruebas de uso)

Bugs detectados usando el sistema, con su causa real. Se dejan documentados porque varios no eran
lo que parecían a simple vista.

| Síntoma | Causa real | Dónde se arregló |
|---|---|---|
| Una alerta creada 11:00 con vencimiento 11:05 figuraba **vencida al instante** | PHP corría en **UTC** y la universidad está en **UTC-3**: `haExpirado()` comparaba contra las 14:0x | `TimezoneSubscriber` fija la zona horaria en cada request y comando |
| Al **regenerar la API key** no aparecía la clave nueva | El backend sí la devolvía; el JS la descartaba: `openActionModal` no tenía la rama `data.apiKey` que sí tiene el envío de formularios | `assets/spui/js/core/modal.js` |
| El formulario de **regla de programación no se enviaba y no mostraba ningún error** | Symfony mapea los datos al objeto **antes** de validar: un campo de hora vacío escribía `null` en un setter tipado no-nullable y lanzaba `TypeError` (HTTP 500) antes de que corrieran los `NotBlank` | Setters nullable en `Programacion`, `CronogramaItem` y `ProgramacionEnergetica` + bucles de error en las plantillas |
| Mensajes de validación **en inglés** | `default_locale: en` en el config compartido de la intranet | `framework.yaml` propio de SPUI con `default_locale: "es"` (mismo patrón que viáticos) + mensaje explícito en los 20 constraints |
| Un modal podía quedar **en blanco al guardar** | Sólo 1 de 10 formularios renderizaba los errores de nivel raíz; con el CSRF vencido no se mostraba nada | Bloque `form.vars.errors` en los 10 formularios |
| Los modales **"Ver" se veían despareja la columna de valores** | `.spui-view-row` nunca tuvo `justify-content: space-between`: usaba `min-width` + `flex: 1` | `assets/spui/css/style.css` |
| Filas con `—` en los detalles | `?? '—'` no cubre strings vacíos, sólo `null` | Filas opcionales dentro de `{% if %}`: si no hay dato, no se muestra la fila |
| Valores en minúscula (`sin_registrar`, `youtube`) | Se imprimía `.value` del enum crudo | Método `etiqueta()` en los 4 enums ("Sin registrar", "YouTube", "QR") |
| El listado de **alertas** no era responsive | Usaba tarjetas propias en vez de la tabla estándar con `spui-tabla-cards` + `data-label` | `templates/alertas/index.html.twig` |
| Una alerta desactivada **reaparecía** al reiniciar la Pi | El borrado del mensaje retenido MQTT estaba mal: hay que publicar payload **vacío con `retain=true`**, no `retain=false` | `AlertaPublisherService::limpiarRetenido()` |
| Una alerta vencida **tapaba** a otra vigente de menor prioridad | `findActivaConMayorPrioridad()` filtraba la expiración en PHP, después de traer una sola fila | Filtro de expiración dentro de la consulta |
| Nada desactivaba las alertas vencidas | No existía ese paso | `spui:mantenimiento` las desactiva y limpia su mensaje retenido |
| En hardware real, la imagen adjunta de una alerta se veía **recortada** (una cara cortada) o como un **recuadro chico** con franjas rojas alrededor | Distintos intentos de encuadre (`contain` con letterbox, `cover` con recorte) no lograban ocupar todo el espacio sin perder contenido de la foto | `_generar_imagen_alerta()` en `pi-client/spui/player.py`: la imagen se **estira** al tamaño exacto de la región (sin mantener proporción) — ocupa el 100% del espacio, sin recortar ni dejar franjas |
| El velo rojo semitransparente sobre la imagen de alerta **le cambiaba los colores** a la foto | Se aplicaba sobre toda la región de contenido, incluida la imagen ya pegada | Ahora es una placa oscura acotada **solo** al bloque de texto del mensaje, no sobre la imagen |
| La pantalla de alerta decía **"Alerta de emergencia"** incluso para avisos cotidianos (cambio de aula) | Texto fijo genérico en vez de mostrar el título propio de la alerta | CMS y pantalla del reproductor renombrados a "Alerta"/"Alertas" (la entidad `AlertaEmergencia` no cambió, sólo la UI); la pantalla del Pi ahora muestra el **título real** de la alerta en la banda superior en vez de un rótulo fijo |
| El sonido de la alerta **no se escuchaba** en hardware real aunque el log confirmaba `reproductor.play()` sin error | El audio sale por HDMI (parlantes del TV), y el dispositivo ALSA "default"/`plughw:` no empaqueta el audio en las tramas IEC958 que HDMI exige a nivel de hardware | `SPUI_AOUT_DEVICE=hdmi:CARD=<id>,DEV=0` en `spui.env` — procedimiento completo en `09_instalacion_raspberry.md` §8.6 |
| El sonido de la alerta se repetía **cada 20 segundos fijos**, incluso para un clip de 1 segundo | `_esperar_alerta()` medía el intervalo desde el **inicio** de la reproducción anterior por reloj, no desde que terminaba | Ahora consulta el estado real de VLC (`get_state()`) y repite en cuanto pasa a `Ended`/`Stopped`; `SPUI_ALERTA_SONIDO_INTERVALO_SEG` pasó a ser la pausa **después** de terminar (default 0) |
| El monitor se quedaba **sin señal (standby)**, no solo en negro, y no se recuperaba solo | `Xorg.0.log.old` mostró varias renegociaciones de EDID en pleno funcionamiento (patrón de hotplug HDMI intermitente, probablemente por un micro-HDMI flojo) que el driver `vc4-hdmi` no siempre recupera solo | `hdmi_force_hotplug=1` en `config.txt` + asegurar físicamente el conector — `09_instalacion_raspberry.md` §14.2. De paso se detectó que el journal de la Pi no era persistente y se perdía la evidencia de estos incidentes — activado en §6.1 |

### Funcionalidad agregada en la misma tanda

- **Alertas dirigidas.** Una alerta puede apuntar a pantallas concretas (tabla `alerta_pantalla`).
  Sin ninguna elegida sigue siendo global, así que las alertas ya cargadas no cambian de
  comportamiento. Las dirigidas se publican en `spui/alertas/reproductor/{id}`.
- **Almacenamiento S3 conmutable.** `MediaStorageService` con `SPUI_STORAGE_DRIVER=local|s3`,
  reutilizando `Shared\Service\S3StorageService`. El Pi no se entera del cambio.
- **Validación de subidas**: tipo MIME y tamaño máximo (200 MB), con mensajes en español.
- **`/api/spui/media` pasa a requerir `X-Api-Key`**: antes cualquiera que adivinara un nombre de
  archivo podía descargarlo.
- **Los archivos se borran** al eliminar el contenido; antes quedaban huérfanos para siempre.
- **Detalle completo en los modales "Ver"**, y "Ver" propio para alertas (con el mensaje entero,
  que en el listado se corta a 80 caracteres).

> **Sobre playlists y contenidos:** un contenido **siempre pudo estar en varias playlists**
> — `playlist_item` es tabla puente y el filtro del selector sólo evita repetirlo dentro de la
> misma playlist. Dura lo mismo en todas: la duración la define el contenido y es su única
> fuente de verdad. No hizo falta ningún cambio.

---

## 4. Convención de placeholders — no escribir IPs concretas

Las direcciones IP de este sistema **no son estables**: host del CMS y reproductores toman IP por DHCP, y cambian según la red (desarrollo local, campus, producción). Documentar una IP concreta genera instrucciones que quedan mal a los pocos días.

**Regla:** en la documentación operativa se usan siempre estos placeholders, y cada guía incluye al principio una tabla para que el lector complete sus valores.

| Placeholder | Qué representa | Cómo se obtiene |
|---|---|---|
| `<ip_del_host>` | PC/servidor donde corre el CMS (Apache) y Mosquitto | Windows: `ipconfig` · Linux: `hostname -I` |
| `<ip_de_la_pi>` | Reproductor Raspberry Pi | En la Pi: `hostname -I` · o escaneo de red |
| `<mac_de_la_pi>` | MAC del reproductor (para reservas DHCP) | En la Pi: `ip link show wlan0` / `eth0` |
| `<tu_usuario>` | Usuario del sistema en la Pi | El definido en el Imager; confirmar con `whoami` |
| `<nombre_conexion>` | Perfil de NetworkManager | `nmcli -t -f NAME,DEVICE connection show --active` |

**Excepción — valores que sí son fijos y se escriben literal:** puertos (`80`, `1883`), rutas de API (`/api/spui`), `127.0.0.1` cuando se está señalando específicamente que ese default es incorrecto, y `0.0.0.0` en configuración de listeners.

**Para desacoplar la configuración de la IP del host:** el reproductor mapea el nombre `intranet` en su `/etc/hosts`, y `SPUI_API_URL` apunta a ese nombre. Cuando la IP del host cambia, se corrige una sola línea. En producción corresponde un nombre DNS real.

> Los documentos históricos (`08_pendientes_vm.md`) contienen IPs concretas del momento en que se escribieron. Sirven como registro de lo que se verificó entonces, **no como valores a copiar**.

---

## 5. Cómo mantener esta documentación

- Cuando se modifique el código, actualizar el documento con autoridad sobre ese tema (§1) **y** esta tabla de nomenclatura si cambió algún nombre.
- Antes de escribir un paso operativo (rutas, menús de `raspi-config`, nombres de campos de formulario), **verificarlo contra el código o contra la pantalla real** — no de memoria. Los menús de herramientas externas cambian entre versiones.
- Si un documento queda superado por otro, marcarlo en §1 como obsoleto en vez de dejar dos versiones compitiendo.
