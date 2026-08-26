# 07 — Manual de uso del CMS

**SPUI — Sistema de Pantallas Informativas Universitarias (UNRaf)**
Guía paso a paso del panel. Para cómo funciona por dentro, ver `06_funcionamiento_del_sistema.md`.

> Reemplaza a `MANUAL_CMS.md` (raíz del proyecto), que quedó desactualizado: describe como pendientes varias cosas que ya están implementadas.

**Acceso:** `/spui` — requiere sesión de la intranet y rol `ROLE_SPUI` o `ROLE_SUPERADMIN`.

---

## El orden en que hay que cargar las cosas

El sistema tiene dependencias reales: no se puede crear una pantalla sin ubicación, ni programar una playlist vacía. El camino más corto de cero a una pantalla funcionando es este:

```
1. Edificio          ─┐
2. Ubicación (sector) ─┤ Infraestructura: DÓNDE
3. Pantalla          ─┤
4. Reproductor       ─┘  ← acá se genera la API key de la Pi

5. Contenido         ─┐
6. Publicarlo         ─┤ Contenido: QUÉ
7. Playlist          ─┘

8. Programación       ─  Operación: CUÁNDO
```

Los pasos 1 a 4 se hacen una vez por pantalla instalada. Del 5 en adelante es el trabajo del día a día.

---

## 1. Dashboard — `/spui`

Es la pantalla de inicio. Muestra:

- **Cuatro tarjetas:** total de reproductores, conectados, desconectados, y cuántos llevan más de 5 minutos sin dar señal.
- **Banner rojo** si hay una alerta de emergencia activa, con un botón para desactivarla ahí mismo.
- **Banner de temperatura** si algún nodo superó los 80 °C, listando cuáles y a cuánto están.
- **Tabla de reproductores** con estado, último heartbeat, temperatura, RAM y disco libre.
- Botón **Actualizar** que refresca los datos sin recargar la página.

La temperatura se pinta en ámbar a partir del 85 % del umbral y en rojo al superarlo.

---

## 2. Infraestructura

### 2.1 Edificios — `/spui/edificios`

Lo más general: un edificio o área del campus.

- **Crear:** nombre (ej. "Edificio Central") y descripción opcional.
- **Activar/desactivar:** un edificio inactivo no desactiva sus pantallas; sirve como filtro.
- **Eliminar:** solo si no tiene ubicaciones.

### 2.2 Ubicaciones — `/spui/ubicaciones`

Un sector dentro de un edificio.

- **Crear:** se elige el edificio y se pone el sector (ej. "Hall de entrada", "Pasillo PB"). El sector es opcional: si la ubicación abarca todo el edificio, se deja vacío.
- **Eliminar:** solo si no tiene pantallas.

**Por qué importa:** al programar una playlist se puede elegir una ubicación como destino, y la regla aplica automáticamente a todas sus pantallas. Una sola regla en vez de una por pantalla.

### 2.3 Pantallas — `/spui/pantallas`

El monitor físico.

- **Crear:** nombre y ubicación (obligatorios), resolución y estado. MAC e IP son **opcionales** y solo informativas para diagnóstico — ver nota abajo.
- **Reproductor:** acá se asigna qué Raspberry Pi maneja esta pantalla. **La asignación se hace desde este formulario, no desde el de Reproductores.**

> **Sobre el campo MAC:** es opcional y **no lo usa ninguna función del sistema** (sync, heartbeat y telemetría identifican al equipo por su API key, no por MAC). Viene del diseño original, cuando `pantalla` representaba al dispositivo; con el refactor a `Reproductor` (migración `Version20260702133532`) dejó de ser obligatoria y se quitó su índice único. Si querés completarla, lo útil es poner la MAC de la Raspberry Pi que maneja la pantalla, para pedir reservas DHCP e identificarla en la red.
>
> El endpoint `POST /api/spui/pantallas` acepta `mac_address` como opcional (sólo valida el formato si viene con valor) y también admite `reproductor_id` y `playlist_fallback_id`.
- **Estados:** *Activo*, *Inactivo*, *Mantenimiento*.
- **Playlist de respaldo:** qué se reproduce cuando ninguna programación aplica en ese momento. Sin esto, la pantalla queda en espera.
- **Botón Energía:** abre la grilla de horarios de esa pantalla (ver 4.2).
- **Eliminar:** solo si no tiene reproductor asignado.

### 2.4 Reproductores — `/spui/reproductores`

La Raspberry Pi. **Acá se genera la clave que necesita el nodo.**

**Al registrar uno:**

1. Se ingresa el hostname (ej. `spui-pi-01`) y la versión de firmware si se sabe.
2. Al guardar, el sistema genera una **API key aleatoria de 64 caracteres**.
3. **La clave se muestra una sola vez**, en un cuadro con botón de copiar.
4. Hay que copiarla y ponerla en la Pi **antes de cerrar ese cuadro**. No se puede recuperar después: en la base solo queda su hash SHA-256.

Si se pierde, se usa **Regenerar clave** — eso invalida la anterior de inmediato y hay que actualizar la Pi.

**Botón Telemetría:** abre el histórico de ese nodo (ver 5).

**Configurar la Pi** — en `/etc/spui/spui.env`:

```
SPUI_API_URL=http://tu-servidor/api/spui
SPUI_API_KEY=<la clave que copiaste>
```

⚠️ `SPUI_API_URL` termina en `/api/spui`, **sin** `/reproductores`: el cliente arma el resto solo.

---

## 3. Contenido

### 3.1 Contenidos — `/spui/contenidos`

El repositorio de todo lo que puede aparecer en una pantalla. Seis tipos:

| Tipo | Qué se carga | Notas |
|---|---|---|
| **Texto** | El texto a mostrar | — |
| **Imagen** | Archivo JPG, PNG, GIF o WebP | Se calcula su hash para que la Pi detecte cambios |
| **Video** | Archivo MP4 o WebM | Ídem |
| **YouTube** | La URL del video | Se reproduce a pantalla completa |
| **QR** | Se elige un código de la sección Códigos QR | El sistema genera la imagen automáticamente |
| **Cronograma** | Nada al crearlo | Al guardar redirige al editor de ítems |

Además se define **título** y **duración en segundos** (vacío = permanente).

**Estados y flujo:**

```
Borrador ──[Publicar]──► Publicado ──[Archivar]──► Archivado
    ▲                         │
    └────[Despublicar]────────┘
```

- **Borrador:** recién creado. No aparece al armar playlists.
- **Publicado:** disponible para usar.
- **Despublicar:** vuelve a borrador. Si ya estaba en playlists, el sistema avisa en cuántas — **hay que sacarlo a mano**, despublicar no lo quita.
- **Archivar:** lo retira de circulación sin borrarlo.
- **Eliminar:** solo si no está en ninguna playlist.

### 3.2 Cronogramas

Un contenido tipo cronograma es una tabla de horarios (materias, aulas). Al crearlo, el sistema lleva al **editor de ítems**, donde se agrega cada fila: nombre, aula, hora de inicio y fin, y en qué días aplica.

La Pi recibe **solo los ítems del día actual**, ya filtrados. Se ordenan por hora de inicio, igual que en el CMS.

### 3.3 Códigos QR — `/spui/qr`

QR dinámicos con estadística de escaneos.

- **Crear:** etiqueta (nombre interno, no se muestra en pantalla), URL destino, y vencimiento opcional.
- **La imagen se genera sola.** El QR no codifica la URL destino sino un redirect del CMS: por eso se pueden contar los escaneos **y cambiar el destino sin reimprimir nada**.
- **Escaneos:** el contador sube cada vez que alguien lo escanea.
- **Desactivar:** quien lo escanee ve un aviso de no disponible; el contador deja de subir.
- **Eliminar:** solo si ningún contenido lo usa.

**Para que aparezca en una pantalla:** crear el código acá, después ir a Contenidos → Nuevo → tipo QR y elegirlo de la lista.

### 3.4 Playlists — `/spui/playlists`

Listas ordenadas que se reproducen en loop.

- **Crear:** nombre y descripción.
- **Editor (builder):** se entra con "Ver". Ahí se puede:
  - **Agregar contenido** — solo aparecen los publicados que todavía no están en la lista.
  - **Reordenar** arrastrando por el ícono de seis puntos. Se guarda solo.
  - **Cambiar la duración de un ítem** — clic en el número de segundos. El mismo contenido puede durar distinto en cada playlist. Un asterisco indica que la duración está pisada.
  - **Quitar ítems.**
- Abajo se ve la duración total de la vuelta completa.

---

## 4. Operación

### 4.1 Programación — `/spui/programacion`

Define **qué playlist va, dónde y cuándo**.

Al crear una regla:

- **Playlist:** cuál se reproduce.
- **Destino** — cuatro alcances, del más específico al más general:
  - *Pantalla:* solo esa
  - *Ubicación:* todas las de ese sector
  - *Edificio:* todas las de todos sus sectores
  - *Global:* si no se elige ninguno, aplica a todas
- **Horario:** hora de inicio y fin.
- **Fechas:** desde (obligatoria) y hasta (opcional; vacío = sin vencimiento).
- **Días:** los siete, con checkboxes.
- **Prioridad:** un número. Si dos reglas aplican al mismo tiempo en la misma pantalla, **gana la de mayor prioridad**.

Las reglas se pueden desactivar sin borrarlas, para pausarlas temporalmente.

> Las alertas de emergencia tienen prioridad absoluta sobre cualquier programación, sin importar el número.

### 4.2 Energía — `/spui/pantallas/{id}/energia`

Se entra con el botón **Energía** del listado de pantallas. Es una grilla de los siete días.

Para cada día se configura:
- **Hora de encendido** y **hora de apagado** (el apagado tiene que ser posterior)
- **Nivel de brillo** (0–100 %)

**Un día sin horario deja la pantalla siempre encendida.**

El botón **Copiar a L-V** replica el horario de ese día al resto de los días hábiles, que es el caso más común: mismo horario de lunes a viernes y algo distinto el fin de semana.

La Pi recibe este horario en cada sincronización y actúa sola: apaga por HDMI-CEC (apaga el televisor de verdad) o cortando la salida de video, y ajusta el brillo donde el panel lo permita.

### 4.3 Alertas de emergencia — `/spui/alertas`

Para avisos urgentes: evacuaciones, incidentes, cambios de último momento.

- **Crear:** título, mensaje, prioridad, vencimiento opcional, contenido multimedia opcional para acompañar, y **sonido de alerta opcional** (MP3 o WAV, hasta 5 MB) — se puede escuchar antes de guardar con el reproductor que aparece al elegir el archivo.
- **Editar:** solo si está inactiva. Una alerta activa no se puede editar — hay que desactivarla primero, porque el aviso que ya salió a las pantallas no coincidiría con lo que muestra el panel. Desde acá también se reemplaza o se quita el sonido personalizado (casilla "Quitar sonido personalizado").
- **Activar:** el aviso llega a todas las pantallas **en segundos**, interrumpiendo lo que estén mostrando. También aparece el banner rojo en el dashboard.
- **Desactivar:** los nodos vuelven a su programación normal (y el sonido se corta al instante).
- **Eliminar:** solo alertas inactivas.

**Un nodo que estaba apagado recibe la alerta apenas se enciende** — el broker guarda el último aviso. Y si MQTT no está disponible, llega igual en la próxima sincronización (tarda más, pero no se pierde).

**Sin sonido personalizado, cada nodo reproduce un tono genérico propio** — así ninguna pantalla queda muda por no tener un archivo cargado. El sonido (personalizado o el tono genérico) se repite cada 20 segundos mientras la alerta siga activa.

**Una alerta enciende la pantalla aunque el horario energético diga que debe estar apagada.**

---

## 5. Telemetría de un nodo — `/spui/reproductores/{id}/telemetria`

Se entra con el botón **Telemetría** del listado de reproductores.

- **Rangos:** últimas 24 h, 7 días o 30 días.
- **Cuatro tarjetas:** temperatura promedio y máxima, RAM máxima, disco libre mínimo.
- **Gráfico de temperatura** con dos líneas (promedio y máxima por hora) y una línea punteada roja en el umbral.
- **Gráfico de RAM.**
- **Tabla por hora** con los mismos datos — la misma información sin depender del color ni de la forma.

Los datos se agrupan por hora: con una lectura por minuto, 7 días serían unos 10 000 puntos imposibles de leer.

**Si dice que no hay datos:** verificar que el nodo esté encendido y que el daemon `spui:mqtt:subscribe` esté corriendo en el servidor. Sin ese proceso, la telemetría que publica la Pi no llega a la base.

---

## 6. Cosas que conviene saber

**El panel funciona en celular.** Por debajo de 768 px el menú pasa a hamburguesa y las tablas se convierten en tarjetas apiladas.

**Casi todo se hace en ventanas emergentes** sin salir del listado. Las excepciones son las pantallas que necesitan espacio: los editores de playlist y cronograma, la grilla de energía y el histórico de telemetría.

**Los borrados están protegidos.** El sistema no deja eliminar algo que otra cosa esté usando; avisa qué lo está bloqueando.

**Requiere JavaScript.** El CMS es una aplicación de panel, no un sitio de contenido; sin JS los formularios no se abren.

---

## 7. Problemas frecuentes

| Síntoma | Causa probable |
|---|---|
| El nodo figura "conectado" pero está apagado | El comando `spui:mantenimiento` no está programado en el cron |
| La pantalla no muestra nada | Sin programación vigente y sin playlist de respaldo |
| Un contenido no aparece al armar la playlist | Está en borrador o archivado: hay que publicarlo |
| La alerta tarda en llegar | MQTT no disponible; llega igual en el próximo sync (hasta 5 min) |
| No hay datos de telemetría | Falta el daemon `spui:mqtt:subscribe --id=spui` |
| El QR no cuenta escaneos | Se cargó la URL como texto en vez de elegir un código de la sección Códigos QR |
| Un comando de consola falla con un error sobre `apps/guess` | Falta el flag `--id=spui` |
