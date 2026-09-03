# 06 — Funcionamiento del sistema

**SPUI — Sistema de Pantallas Informativas Universitarias (UNRaf)**
Estado del documento: refleja el código tal como está, verificado contra rutas, entidades y base de datos.

Este documento explica **qué hace el sistema y cómo funciona por dentro**. Para el uso concreto del panel, ver `07_manual_uso_cms.md`. Para lo que falta hacer con la Raspberry Pi, ver `08_pendientes_vm.md`.

---

## 1. Qué resuelve

Reemplazar las carteleras físicas del campus por pantallas que se actualizan solas desde un panel web central. El problema original: la información quedaba desactualizada, no había forma de avisar algo urgente, y cada cartel había que imprimirlo y pegarlo a mano.

Tres cosas lo diferencian de poner un Google Slides en loop:

- **Sigue funcionando sin internet.** Si se corta la red, la pantalla no muestra un error: sigue reproduciendo lo último que descargó.
- **Los nodos se monitorean solos.** Cada Raspberry Pi informa su temperatura, memoria y disco. Si una se está recalentando, se ve en el panel antes de que falle.
- **Una emergencia interrumpe todo.** Un aviso de evacuación aparece en todas las pantallas en segundos, sin esperar al próximo ciclo de actualización.

---

## 2. Las tres piezas

```
┌──────────────────────────────┐
│  CMS (Symfony)               │   El panel web donde se carga todo.
│  /spui/*                     │   Vive dentro del monolito "intranet".
│                              │
│  API REST  /api/spui/*       │   Lo que consumen las Raspberry Pi.
└──────────┬───────────────────┘
           │
     ┌─────┴──────┬────────────────┐
     │ HTTP       │ MQTT           │
     │ cada 5 min │ tiempo real    │
     ▼            ▼                ▼
┌──────────────────────────────────────┐
│  Raspberry Pi (cliente Python)       │
│  · descarga y cachea contenido       │
│  · reproduce en la pantalla (VLC)    │
│  · informa su estado                 │
│  · enciende/apaga según horario      │
└──────────────────────────────────────┘
```

**El CMS** no habla directo con las pantallas: publica información y espera. **La Pi** es la que pregunta ("¿qué tengo que mostrar ahora?"). La única excepción es la alerta de emergencia, que el CMS **empuja** por MQTT para que llegue al instante.

---

## 3. Modelo de datos

Base de datos propia (`intranet/spui`), con su propio EntityManager de Doctrine, separada del resto del monolito.

### Infraestructura — dónde está cada pantalla

| Entidad | Qué representa |
|---|---|
| **Edificio** | Un edificio del campus |
| **Ubicacion** | Un sector dentro de un edificio ("Hall de entrada", "Pasillo PB") |
| **Pantalla** | El monitor físico. Tiene resolución, MAC, IP y estado |
| **Reproductor** | La Raspberry Pi que controla una o más pantallas. Guarda el **hash** de su API key, nunca la clave |

Cadena: `Edificio → Ubicacion → Pantalla → Reproductor`.

### Contenido — qué se muestra

| Entidad | Qué representa |
|---|---|
| **Contenido** | Una pieza a mostrar. Seis tipos: `texto`, `imagen`, `video`, `youtube`, `qr`, `cronograma` |
| **CronogramaItem** | Las filas de un contenido tipo cronograma (materia, aula, horario, días) |
| **CodigoQr** | Un QR dinámico con URL destino, vencimiento y contador de escaneos. Pertenece al `Contenido` de tipo `qr` que lo muestra: se crea, se edita y se borra desde ahí, no desde una sección propia |
| **Playlist** | Una lista ordenada de contenidos |
| **PlaylistItem** | Un contenido dentro de una playlist, con su orden y duración propia |

Un contenido tiene tres estados: **borrador** (no se puede usar), **publicado** (disponible para playlists), **archivado** (retirado sin borrar).

### Operación — cuándo y cómo

| Entidad | Qué representa |
|---|---|
| **Programacion** | Regla de horario: qué playlist va, dónde y cuándo |
| **ProgramacionEnergetica** | Encendido, apagado y brillo por día para una pantalla |
| **AlertaEmergencia** | Aviso urgente que interrumpe todo |
| **Telemetria** | Cada lectura de temperatura, RAM, disco y latencia de un nodo |

---

## 4. Cómo se decide qué se muestra

Cuando una Pi pregunta, el CMS resuelve así, **en este orden**:

```
¿Hay una alerta de emergencia activa?
   SÍ → se muestra la alerta. Fin. (ignora todo lo demás)
   NO ↓

¿Hay una programación vigente para esta pantalla, ahora?
   Se buscan las reglas activas donde:
     · hoy está entre fecha_inicio y fecha_fin
     · la hora actual está entre hora_inicio y hora_fin
     · el día de la semana está en la máscara de días
     · el alcance aplica: esta pantalla, su ubicación, su edificio, o global
   De las que aplican, gana la de MAYOR prioridad.

   SÍ → se reproduce la playlist de esa regla.
   NO ↓

¿La pantalla tiene una playlist de respaldo (fallback)?
   SÍ → se reproduce esa.
   NO → pantalla de espera.
```

**El alcance de una regla** puede ser de cuatro niveles, del más específico al más general: una pantalla concreta, una ubicación (todas sus pantallas), un edificio (todas las de todos sus sectores), o global (todas las del campus).

---

## 5. El ciclo de la Raspberry Pi

El cliente Python corre como servicio (`systemd`) y levanta cuatro hilos:

| Hilo | Cada cuánto | Qué hace |
|---|---|---|
| principal | 5 min | Pide `/sync`, guarda en caché, descarga archivos, decide qué reproducir |
| heartbeat | 60 s | Avisa que sigue vivo |
| telemetría | 60 s | Publica temperatura, RAM y disco por MQTT |
| MQTT listener | — | Escucha alertas de emergencia; reacciona al instante |

### Un ciclo completo

1. **¿Hay red?** Se chequea con una conexión TCP rápida al host del CMS (más barato que un HTTP con timeout).
2. **Si hay red:** `POST /api/spui/reproductores/sync` con la API key en el header `X-Api-Key`. La respuesta trae la playlist, la alerta activa si la hay, y el horario energético.
3. **Se guarda todo en SQLite local** y se descargan los archivos que falten. Cada archivo se verifica por SHA-256: si el hash no coincide, se descarta.
4. **Si no hay red:** se usa lo último guardado y se reintenta a los 30 segundos en vez de esperar 5 minutos.
5. **Se aplica el horario energético:** si está fuera de hora, se apaga la pantalla y no se reproduce nada.
6. **Se decide qué mostrar** según la prioridad de la sección 4.

### Por qué sigue andando sin red

Tres mecanismos combinados:

- **Caché SQLite** — la última respuesta de sync completa queda guardada.
- **Prefetch** — al sincronizar se descargan *todos* los archivos de la playlist, no solo el que toca ahora. Cuando se corte la red, ya están.
- **Nunca falla en silencio** — si no hay red ni caché, muestra una pantalla de espera institucional, no un error.

---

## 6. La alerta de emergencia

Es el único caso donde el CMS no espera a que le pregunten.

```
Admin presiona "Activar"
        │
        ├──► Se marca activa en la base
        │
        └──► MQTT: topic spui/alertas/emergencia (con retain)
                  └─► cada Pi la recibe en el acto e interrumpe lo que esté mostrando
```

**`retain` es importante:** el broker guarda el último mensaje del topic, así que una Pi que estaba apagada y arranca después recibe la alerta apenas se conecta. Y si MQTT no está disponible, la alerta igual llega en el próximo `/sync` — solo tarda más.

**Sonido de alerta.** Cada alerta puede tener un sonido personalizado (MP3/WAV, hasta 5 MB, subido desde el formulario del CMS). El payload MQTT y el REST de `/sync` llevan `sonido_url`/`sonido_hash` (no el archivo en sí), así que el sonido correcto se conoce desde el primer instante, sin depender de un segundo round-trip. Si no hay sonido personalizado, o no se puede descargar (sin red, archivo corrupto, etc.), el reproductor genera y usa un tono propio (dos frecuencias tipo "beep-beep", vía el módulo estándar `wave` de Python) — la alerta nunca queda muda por falta de configuración o de conectividad. Se repite cada `SPUI_ALERTA_SONIDO_INTERVALO_SEG` (20s por defecto) mientras la alerta siga activa, y se corta de inmediato al desactivarse: la repetición vive dentro del mismo bucle que decide si la alerta sigue viva, no en un timer aparte, para que sea imposible que quede sonando después de que la alerta terminó.

**El dashboard del CMS se actualiza solo, pero no por Mercure.** El proyecto integró `symfony/mercure-bundle` en su momento, pero nunca se completó del lado del navegador: no hay ningún consumidor `EventSource` en el frontend, y `MERCURE_URL` sigue apuntando al valor por defecto. Lo que realmente refresca el panel es `auto-refresh.js` (tarea 1.7.b) — polling cada 20s a la misma ruta del dashboard, con pausa si la pestaña está oculta o hay un modal abierto. Alcanza para el caso de uso (el operador ve el estado actualizado sin recargar); Mercure quedó como dependencia sin usar. Ver `docs/README.md` para el resto de las decisiones de la tarea 1.7.

Una alerta activa **enciende la pantalla aunque el horario energético diga que debe estar apagada**: avisar una evacuación pesa más que el ahorro.

---

## 7. Seguridad

| Qué | Cómo |
|---|---|
| Panel CMS (`/spui/*`) | Sesión del monolito (Google OAuth) + rol `ROLE_SPUI` o `ROLE_SUPERADMIN` |
| API de administración (`/api/spui/*`) | Misma sesión y rol |
| Endpoints de la Pi (`/sync`, `/heartbeat`, `/telemetria`) | Header `X-Api-Key`, comparada contra un hash SHA-256 |
| Archivos de medios (`/api/spui/media/*`) | Público, con protección contra path traversal |
| Redirect y PNG de QR | Público por diseño: los abre el celular de un visitante |
| CORS | Lista blanca configurable (`SPUI_CORS_ORIGINS`) |
| Límite de peticiones | 2 sync/min y 3 heartbeat/min por reproductor |

**La API key se muestra una sola vez**, al registrar el nodo. En la base solo queda el hash SHA-256; no hay forma de recuperarla. Si se pierde, se regenera (lo que invalida la anterior).

---

## 8. Estado de los casos de uso

Contra los 12 casos de uso definidos en `02_casos_de_uso.md`:

| CU | Estado |
|---|---|
| CU-01 Gestionar contenido | ✅ Completo. Se agregó un 6º tipo (`cronograma`) que no estaba en el diseño original |
| CU-02 Crear playlist | ✅ Completo, con drag & drop y duración configurable por ítem |
| CU-03 Programar reproducción | ✅ Completo, y con un alcance más que lo planeado (por edificio) |
| CU-04 Alerta de emergencia | ✅ Completo vía MQTT (push a la Pi) + polling (dashboard del CMS, `auto-refresh.js`) — **no** usa Mercure, ver §6 |
| CU-05 Registrar nodo | ✅ Completo |
| CU-06 Sincronizar contenido | ✅ Completo |
| CU-07 Reproducir contenido | ✅ Completo. Incluye modo simulación para probar sin VLC |
| CU-08 Telemetría | ✅ Completo. Umbral configurable (80 °C por defecto) y aviso visible en el dashboard |
| CU-09 Modo offline | ✅ Completo |
| CU-10 Códigos QR | ✅ Completo. El QR codifica el redirect del CMS, por eso se cuentan los escaneos. Se administra desde Contenidos (tipo `qr`), sin sección propia — ver `README.md` §3.3 |
| CU-11 Programación energética | ✅ Completo (CMS + API + módulo en el cliente Pi) |
| CU-12 Monitorear nodos | ✅ Completo, con histórico y gráficos |

**Pendiente de probar en hardware/VM real:** todo el circuito con una Raspberry Pi. Ver `08_pendientes_vm.md`.

---

## 9. Decisiones técnicas y por qué

**El QR se guarda como archivo PNG, no se genera al vuelo.**
Así viaja por la misma tubería que una imagen (descarga, verificación por hash, caché offline) y se sigue viendo con la red caída. El PNG codifica la URL de redirect del CMS, no el destino final: por eso se pueden contar los escaneos y cambiar el destino sin reimprimir nada.

**Los gráficos de telemetría son SVG hecho a mano, sin librería.**
La red del campus puede no tener salida a internet. Una dependencia por CDN dejaría la pantalla vacía justo cuando se la quiere mostrar.

**Temperatura y RAM van en gráficos separados.**
Son escalas distintas (°C y %). Un eje doble haría que dos series se vean comparables sin serlo.

**El cronograma se ordena por hora en el CMS y en la Pi.**
Antes el CMS lo mostraba por hora y la Pi por orden de carga: lo que veía el admin no era lo que salía en pantalla.

**Dos convenciones de día conviven, a propósito.**
`Programacion` y `CronogramaItem` usan una máscara de bits (bit0 = lunes) porque una regla aplica a varios días a la vez. `ProgramacionEnergetica` usa ISO (1 = lunes … 7 = domingo) porque es una fila por día. Coincide con `date('N')` de PHP y con `isoweekday()` de Python, así que el nodo no convierte nada.

---

## 10. Deuda técnica conocida

Cosas identificadas y decididas conscientemente, no olvidos:

| Tema | Situación |
|---|---|
| **CSRF en acciones directas** | Los botones de activar/eliminar/toggle no llevan token. El ataque clásico ya está mitigado porque las cookies usan `SameSite=lax`, que impide que un POST desde otro sitio lleve la sesión. Queda como defensa en profundidad |
| **MQTT sin autenticación** | En desarrollo el broker acepta conexiones anónimas. En producción hay que configurar usuario/contraseña y TLS en el puerto 8883, y agregar soporte en `AlertaPublisherService` y en el cliente Python |
| **HTTP en vez de HTTPS** | Esperable en local. Producción necesita TLS |
| **Bundle `Shared` del monolito** | Las otras apps cargan 1642 imports (jQuery duplicado, DataTables duplicado, CSS legacy). SPUI ya no depende de eso, pero el problema sigue a nivel monolito. No se toca porque afecta a las demás apps |
| **Retención de telemetría** | El comando de purga existe (`spui:mantenimiento`) pero hay que programarlo en el cron del servidor |
| **Imagen de fallback** | `pi-client/assets/logo_fallback.png` está en `.gitignore` (no se versiona el binario). Si no existe, el player muestra un texto institucional en su lugar — nunca deja la pantalla en negro |

---

## 10.1. Correcciones de la auditoría de consistencia

Auditoría cruzada de código vs. documentación. Todo lo listado está **corregido**, no pendiente.

| # | Problema detectado | Corrección aplicada |
|---|---|---|
| 1 | `spui.service` ejecutaba `/usr/bin/python3`, pero `install.sh` instala las dependencias en `/opt/spui/venv`. El cliente moría con `ModuleNotFoundError` y systemd lo reiniciaba en loop | `ExecStart` usa `/opt/spui/venv/bin/python3`. `install.sh` agrega una verificación de imports al final para que un problema de dependencias se vea al instalar y no como un restart-loop |
| 2 | El player no tenía rama para el tipo `cronograma`: caía en "Tipo desconocido" y no mostraba nada, pese a que el CMS lo ofrece y `SyncController` le envía `cronograma_items` | `Player._reproducir_cronograma()` renderiza las actividades del día como texto tabulado. Si no hay actividades, lo informa en pantalla |
| 3 | `mostrar_fallback()` dependía de un PNG que no está versionado → pantalla en negro | Si el PNG no existe, se renderiza un texto institucional |
| 4 | `PantallaController::create()` exigía `mac_address` y validaba unicidad, contradiciendo el esquema (nullable, sin índice único desde `Version20260702133532`) y el formulario del CMS, donde es opcional | La MAC pasó a ser opcional en la API; sólo se valida el formato si viene con valor. Se eliminó la validación de unicidad |
| 5 | La API de pantallas no permitía asignar `reproductor_id` ni `playlist_fallback_id`, aunque el CMS sí y `serialize()` devolvía `reproductor_id` | Ambos campos son asignables en `create()` y `update()`, y `playlist_fallback_id` se incluye en la respuesta |
| 6 | La tabla `media_cache` de SQLite y sus métodos (`registrar_media`, `ruta_local`) no se llamaban desde ningún lado: los archivos se resuelven contra el filesystem | Se eliminó el código muerto. El módulo documenta que su alcance es sólo la respuesta del sync |
| 7 | El heartbeat esperaba el intervalo completo antes del primer envío: el reproductor figuraba desconectado el primer minuto tras arrancar | Primer heartbeat inmediato al iniciar el hilo |
| 8 | `requirements.txt` pedía `python-vlc>=3.0.20120801`, que no es una versión real: parece una fecha (2012-08-01) usada como build. Como pip compara numéricamente, `20120801 > 21203` (la última publicada), así que **ninguna versión satisfacía la restricción** y `install.sh` fallaba en el paso 2 | Fijado a `python-vlc>=3.0.18121,<4`. El esquema real es `3.0.<libvlc><build>`, y la serie 3.x es la que corresponde a VLC 3.x de Bookworm |
| 9 | `paho-mqtt>=1.6.1` instala hoy la 2.x, que cambió la API: `mqtt.Client(client_id=...)` sin `CallbackAPIVersion` lanza `ValueError`. Como ambos hilos MQTT capturan la excepción y sólo loguean un warning, **la telemetría y las alertas dejaban de funcionar en silencio** | `nuevo_cliente_mqtt()` en `mqtt_listener.py` detecta la versión de paho y pide `CallbackAPIVersion.VERSION1` cuando corresponde, conservando las firmas de callback. Lo usan el listener y el publisher de telemetría |

---

## 11. Puesta en marcha del lado del servidor

```bash
# Migraciones de la base
php bin/console doctrine:migrations:migrate --id=spui

# Daemon que recibe la telemetría por MQTT y la guarda
php bin/console spui:mqtt:subscribe --id=spui

# Mantenimiento: marca nodos caídos y purga telemetría vieja
# (programar cada minuto en cron / Programador de tareas)
php bin/console spui:mantenimiento --id=spui
```

⚠️ **El flag `--id=spui` es obligatorio.** Este monolito aloja varias apps y decide cuál cargar según la URL; por consola no hay URL, así que hay que decírselo. Sin el flag, el comando falla con un error confuso sobre `apps/guess`.

**Variables de entorno relevantes** (en `.env.local`):

| Variable | Para qué | Default |
|---|---|---|
| `SPUI_DATABASE_URL` | Base de datos propia de SPUI | — |
| `MQTT_HOST` / `MQTT_PORT` | Broker Mosquitto | `127.0.0.1` / `1883` |
| `SPUI_CORS_ORIGINS` | Orígenes permitidos en la API | `*` (solo aceptable en desarrollo) |
| `SPUI_TEMP_ALERTA_CELSIUS` | Umbral de temperatura | `80.0` |
