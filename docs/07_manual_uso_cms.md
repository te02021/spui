# 07 — Manual de uso del CMS

**SPUI — Sistema de Pantallas Informativas Universitarias (UNRaf)**
Guía paso a paso del panel. Para cómo funciona por dentro, ver `06_funcionamiento_del_sistema.md`.

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
- **Banner rojo** si hay una alerta activa, con un botón para desactivarla ahí mismo.
- **Banner de temperatura** si algún reproductor superó los 80 °C, listando cuáles y a cuánto están.
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

- **Estados:** *Activo*, *Inactivo*, *Mantenimiento*.
- **Playlist de respaldo:** qué se reproduce cuando ninguna programación aplica en ese momento. Sin esto, la pantalla queda en espera.
- **Botón Energía:** abre la grilla de horarios de esa pantalla en una ventana emergente (ver 4.2).
- **Eliminar:** solo si no tiene reproductor asignado.
- Si el reproductor de la pantalla está **desconectado**, editarla, cambiarle el estado, eliminarla y tocar su energía quedan bloqueados hasta que vuelva (ver 6).

### 2.4 Reproductores — `/spui/reproductores`

La Raspberry Pi. **Acá se genera la clave que necesita el reproductor.**

**Al registrar uno:**

1. Se ingresa el hostname (ej. `spui-pi-01`) y la versión de firmware si se sabe.
2. Al guardar, el sistema genera una **API key aleatoria de 64 caracteres**.
3. **La clave se muestra una sola vez**, en un cuadro con botón de copiar.
4. Hay que copiarla y ponerla en la Pi **antes de cerrar ese cuadro**. No se puede recuperar después: en la base solo queda su hash SHA-256.

Si se pierde, se usa **Regenerar clave** — eso invalida la anterior de inmediato y hay que actualizar la Pi.

**Botón Telemetría:** abre el histórico de ese reproductor (ver 5).

**Editar** y **Regenerar clave** quedan bloqueados mientras el reproductor esté desconectado (ver 6). **Eliminar no**, a propósito: dar de baja un equipo es justamente lo que se hace cuando se rompió y no va a volver. Al eliminarlo se revoca también su usuario del broker MQTT.

**Configurar la Pi** — en `/etc/spui/spui.env`:

```
SPUI_API_URL=http://tu-servidor/api/spui
SPUI_API_KEY=<la clave que copiaste>
SPUI_MQTT_SALT=<el mismo valor que tiene el CMS>
```

⚠️ `SPUI_API_URL` termina en `/api/spui`, **sin** `/reproductores`: el cliente arma el resto solo.
⚠️ Sin `SPUI_MQTT_SALT` (o con un valor distinto al del CMS) el broker rechaza al reproductor: sincroniza por HTTP pero **no recibe las alertas al instante**. La configuración completa está en `09_instalacion_raspberry.md` §11.2.

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
| **QR** | URL destino y vencimiento opcional, en el mismo formulario | El sistema genera la imagen automáticamente (ver §3.3) |
| **Cronograma** | Nada al crearlo | Al guardar se abre su listado de ítems (ver §3.2) |

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

Un contenido tipo cronograma es una tabla de horarios (materias, aulas). Sus ítems se administran en una **ventana emergente**, como el resto del panel: se abre con el botón **Ítems** del listado de Contenidos, con **Ver ítems** desde el detalle o la edición del contenido, y sola apenas se crea un cronograma.

- **Agregar ítem**, **Editar** y **Eliminar** se abren encima del listado. Cada ítem pide nombre, aula (opcional), hora de inicio y fin, días (al menos uno) y estado, y **se guarda al guardarlo**: al terminar se cierra esa ventana y el listado se actualiza solo. Las pantallas que muestran el cronograma reciben el cambio en segundos.
- Si alguna pantalla que lo muestra tiene su reproductor desconectado, las tres acciones quedan bloqueadas (ver 6); el listado se puede mirar igual.

La Pi recibe **solo los ítems del día actual**, ya filtrados. Se ordenan por hora de inicio, igual que en el CMS.

### 3.3 Códigos QR — dentro de Contenidos

**No hay una sección aparte de códigos QR.** Un QR es un tipo de contenido más y se administra
como cualquier otro: Contenidos → Nuevo → tipo **QR**.

- **Crear:** el formulario se adapta al elegir el tipo y pide **URL destino** (a dónde llega quien
  escanea) y un **vencimiento opcional**. Nada más: el nombre del código es el título del contenido.
- **La imagen se genera sola.** El QR no codifica la URL destino sino un redirect del CMS: por eso
  se pueden contar los escaneos **y cambiar el destino sin reimprimir nada**.
- **Editar:** desde Editar del contenido, igual que se edita el archivo de una imagen o el texto de
  un cartel. Cambiar el destino **no invalida** los QR ya impresos ni el que está en pantalla.
- **Escaneos:** el contador se ve en el modal **Ver** del contenido, junto con su destino y su
  vencimiento, y **se actualiza solo, en el momento del escaneo**: si tenés el modal abierto, el
  número cambia sin tocar nada ni recargar la página. La imagen del código se abre con el botón del
  ojo, igual que la de una imagen o un video.
- **Si alguien escanea un código eliminado o vencido** —queda impreso en un cartel, o guardado en el
  historial del teléfono— ve una pantalla que se lo explica, distinta según el caso, en vez de un
  error del navegador. Nunca se lo manda a otro destino sin avisar.
- **Cortar el redirect:** ponerle vencimiento, o eliminar el contenido. Al eliminar un contenido QR
  se elimina también su código.

Si un QR está vencido, quien lo escanee ve un aviso de no disponible y el contador deja de subir.

> **Si el número no se mueve solo**, el escaneo igual se contó: al cerrar y volver a abrir el modal
> aparece bien. Lo que falta en ese caso es el aviso instantáneo, que viaja por el broker MQTT —
> revisar que Mosquitto esté corriendo y que tenga el listener WebSocket del puerto 9001
> (`config/mosquitto/mosquitto.conf`). Nunca se pierde un escaneo por esto.

> **Si el CMS cambia de equipo o de dirección de red**, los códigos se arreglan solos: al entrar a
> esta pantalla, el sistema nota que la dirección cambió y rehace los PNG viejos, sin perder el
> contador de escaneos ni la URL destino. Puede tardar en notarlo si, por ejemplo, alguien entra por
> una dirección menos "pública" que la habitual (una IP en vez del dominio) — en ese caso, para no
> arriesgar que los códigos dejen de abrir por una entrada ocasional, no se actualizan solos. El botón
> **"Actualizar direcciones de los QR"** de esta pantalla fuerza la corrección con la dirección
> actual. No hace falta nada de esto para un cambio de destino: eso no toca la imagen.

### 3.4 Playlists — `/spui/playlists`

Listas ordenadas que se reproducen en loop.

- **Crear y editar:** nombre, descripción y sus ítems, todo desde el mismo formulario. El botón **Ver ítems · N** abre una segunda ventana, encima del formulario, con dos listas:
  - arriba, los ítems de la playlist: se **reordenan** arrastrando por el ícono de seis puntos y se **quitan** con el tacho;
  - abajo, todos los contenidos publicados, para **agregar**. Un mismo contenido **se puede agregar más de una vez** (por ejemplo, para que un aviso salga más seguido dentro del loop); el catálogo indica "En la lista ×N".
- **Guardar y Cancelar de la ventana de ítems aparecen sólo cuando cambiaste algo.** **Guardar** confirma la lista y cierra la ventana (al reabrirla, sigue ahí); **Cancelar** vuelve a lo que estaba y cierra.
- **Lo que se guarda de verdad es la playlist:** los ítems confirmados se persisten recién con el **Guardar** del formulario de la playlist, junto con el nombre y la descripción. Si cancelás la playlist, no queda nada de lo que hiciste en los ítems. Al guardar, las pantallas que usan esa playlist reciben el cambio en segundos.
- **La duración la define el contenido**, no la playlist: un mismo contenido dura lo mismo en todas las playlists donde esté (y en cada una de sus repeticiones).
- Desde **Ver** se consulta la lista de ítems en modo sólo lectura.
- Si alguna pantalla que usa la playlist tiene su reproductor **desconectado**, editarla y eliminarla quedan bloqueados (ver 6).

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
- **Días:** los siete, con checkboxes. Hay que marcar **al menos uno**: si se desmarcan todos, el formulario lo avisa debajo y no guarda (antes se guardaba en silencio como "todos los días"). Para todos los días, marcar los siete.
- **Prioridad:** un número. Si dos reglas aplican al mismo tiempo en la misma pantalla, **gana la de mayor prioridad**.

Las reglas se pueden desactivar sin borrarlas, para pausarlas temporalmente.

> Las alertas tienen prioridad absoluta sobre cualquier programación, sin importar el número.

### 4.2 Energía

Se abre con el botón **Energía** del listado de pantallas, en una ventana emergente — no tiene pantalla propia. Es una grilla de los siete días. Mientras el reproductor de esa pantalla esté desconectado, el botón queda deshabilitado (ver 6).

Para cada día se configura:
- **Hora de encendido** y **hora de apagado** (el apagado tiene que ser posterior)
- **Nivel de brillo** (0–100 %)

**Un día sin horario deja la pantalla siempre encendida.**

El botón **Copiar a L-V** replica el horario de ese día al resto de los días hábiles, que es el caso más común: mismo horario de lunes a viernes y algo distinto el fin de semana.

Cada cambio le llega a la Pi en segundos (el CMS le avisa que sincronice), y la Pi actúa sola: apaga por HDMI-CEC (apaga el televisor de verdad) o cortando la salida de video, y ajusta el brillo donde el panel lo permita.

### 4.3 Alertas — `/spui/alertas`

Para avisos urgentes o de último momento: evacuaciones, incidentes, un cambio de aula.

- **Crear:** título, mensaje, prioridad, vencimiento opcional, contenido multimedia opcional para acompañar, y **sonido de alerta opcional** (MP3 o WAV, hasta 5 MB) — se puede escuchar antes de guardar con el reproductor que aparece al elegir el archivo.
- **Editar:** solo si está inactiva. Una alerta activa no se puede editar — hay que desactivarla primero, porque el aviso que ya salió a las pantallas no coincidiría con lo que muestra el panel. Desde acá también se reemplaza o se quita el sonido personalizado (casilla "Quitar sonido personalizado").
- **Activar:** el aviso llega a todas las pantallas **en segundos**, interrumpiendo lo que estén mostrando. También aparece el banner rojo en el dashboard.
- **Desactivar:** los reproductores vuelven a su programación normal (y el sonido se corta al instante).
- **Eliminar:** solo alertas inactivas.
- **Activar** y **Desactivar** quedan bloqueados si alguna pantalla a la que va la alerta tiene su reproductor desconectado (ver 6).

**Un reproductor que estaba apagado recibe la alerta apenas se enciende** — el broker guarda el último aviso. Y si MQTT no está disponible, llega igual en la próxima sincronización (tarda más, pero no se pierde).

**Sin sonido personalizado, cada reproductor reproduce un tono genérico propio** — así ninguna pantalla queda muda por no tener un archivo cargado. El sonido (personalizado o el tono genérico) vuelve a sonar apenas termina, mientras la alerta siga activa. La pausa entre repeticiones se configura en cada Pi (`SPUI_ALERTA_SONIDO_INTERVALO_SEG`, 0 por defecto — ver `09_instalacion_raspberry.md` §8.6).

**Una alerta enciende la pantalla aunque el horario energético diga que debe estar apagada.**

---

## 5. Telemetría de un reproductor — `/spui/reproductores/{id}/telemetria`

Se entra con el botón **Telemetría** del listado de reproductores.

- **Rangos:** últimas 24 h, 7 días o 30 días.
- **Cuatro tarjetas:** temperatura promedio y máxima, RAM máxima, disco libre mínimo.
- **Gráfico de temperatura** con dos líneas (promedio y máxima por hora) y una línea punteada roja en el umbral.
- **Gráfico de RAM.**
- **Tabla por hora** con los mismos datos — la misma información sin depender del color ni de la forma.

Los datos se agrupan por hora: con una lectura por minuto, 7 días serían unos 10 000 puntos imposibles de leer.

**Si dice que no hay datos:** verificar que el reproductor esté encendido y que el daemon `spui:mqtt:subscribe` esté corriendo en el servidor. Sin ese proceso, la telemetría que publica la Pi no llega a la base.

---

## 6. Cosas que conviene saber

**El panel funciona en celular.** Por debajo de 768 px el menú pasa a hamburguesa y las tablas se convierten en tarjetas apiladas.

**Casi todo se hace en ventanas emergentes** sin salir del listado, incluidos los ítems de una playlist, los ítems de un cronograma y la grilla de energía. La única excepción es el histórico de telemetría, que necesita la pantalla entera para los gráficos.

**Los borrados están protegidos.** El sistema no deja eliminar algo que otra cosa esté usando; avisa qué lo está bloqueando.

**No se puede cambiar lo que muestra un reproductor desconectado.** Si una acción afecta a alguna pantalla cuyo reproductor está desconectado, el botón aparece atenuado y, al pasar el mouse, dice qué equipo está caído y hace cuánto. Así nadie guarda un cambio creyendo que llegó a una pantalla que no lo va a recibir. Se bloquean:

- Pantallas: editar, activar/desactivar, eliminar y energía.
- Reproductores: editar y regenerar la clave (eliminar **no**, ver 2.4).
- Contenidos: editar, publicar, despublicar, archivar, eliminar y los ítems de un cronograma.
- Playlists: editar (incluidos sus ítems) y eliminar.
- Programación: crear, editar, pausar y eliminar reglas.
- Alertas: activar y desactivar.

El bloqueo lo decide el servidor, no sólo el botón: si el equipo se cae entre que se abrió la página y el clic, la acción se rechaza igual y aparece el aviso. Los botones se habilitan solos cuando el reproductor vuelve a dar señal, y se atenúan solos cuando se cae, sin recargar la página: en **Reproductores** y **Alertas** porque esos listados se actualizan cada 20 segundos, y en **Pantallas, Contenidos, Playlists y Programación** porque el sistema avisa en el momento en que un equipo cambia de estado. Si el aviso no llega (broker MQTT caído), recargar la página muestra el estado real; y si el botón quedó habilitado por error, el servidor rechaza la acción igual.

> Al caerse un equipo, el botón tarda en atenuarse lo que el sistema tarda en darlo por desconectado: hasta unos 3 minutos sin señal (el umbral de 150 s más la revisión que corre cada minuto).

**Los cambios llegan en segundos.** Al guardar algo que afecta a una pantalla (programación, playlist, contenido, cronograma, energía), el CMS le avisa al reproductor que sincronice en ese momento, sin esperar su ciclo de 5 minutos. Si el broker MQTT no está disponible, el cambio llega igual en el próximo ciclo.

**Requiere JavaScript.** El CMS es una aplicación de panel, no un sitio de contenido; sin JS los formularios no se abren.

**Las subidas muestran su avance.** Al guardar con un archivo (imagen, video, sonido de alerta) aparece una barra con el porcentaje y los MB enviados ("Subiendo archivo… 25 % · 12,3 MB de 50 MB"). Cuando el archivo terminó de llegar pasa a "Procesando en el servidor…": es el momento en que el servidor lo guarda en el almacenamiento, y puede tardar unos segundos más en archivos grandes.

**Los errores de un dato aparecen debajo de ese campo**, en rojo y con el campo marcado: un nombre que falta, una URL mal escrita, una hora de apagado anterior a la de encendido, un archivo con formato no admitido. Muchos se avisan **antes de guardar**: un campo obligatorio que dejás vacío, al pasar al siguiente (Tab o clic en otro campo); las horas al revés (energía y cronograma), mientras las cambiás; y al elegir un archivo, si el formato no corresponde al tipo (una imagen no admite MP4) o si pesa más que el máximo (con su tamaño real: "pesa 2,1 GB y el máximo es 200 MB"). Al apretar Guardar con algo mal no se envía nada y se marcan todos los campos a corregir. Al empezar a corregir un campo marcado, el aviso se va. Si el servidor rechaza un archivo ya elegido, **el archivo queda cargado** con el aviso debajo, para quitarlo o reemplazarlo sin buscarlo de nuevo. El texto de ayuda del archivo muestra sólo los formatos del tipo elegido. Los mensajes flotantes de arriba quedan para lo que no es de un campo: un reproductor desconectado, algo que no se puede eliminar porque está en uso, la sesión vencida.

---

## 7. Problemas frecuentes

| Síntoma | Causa probable |
|---|---|
| El reproductor figura "conectado" pero está apagado | El servicio `spui:mqtt:subscribe` no está corriendo: es el que marca como desconectados a los equipos sin señal, cada minuto |
| Un botón aparece atenuado y no hace nada | Afecta a una pantalla con el reproductor desconectado — el motivo aparece al pasar el mouse (ver 6) |
| La pantalla no muestra nada | Sin programación vigente y sin playlist de respaldo |
| Un contenido no aparece al armar la playlist | Está en borrador o archivado: hay que publicarlo |
| La alerta tarda en llegar | MQTT no disponible; llega igual en el próximo sync (hasta 5 min) |
| No hay datos de telemetría | Falta el daemon `spui:mqtt:subscribe --id=spui` |
| El QR no cuenta escaneos | Se cargó la URL como contenido de tipo texto en vez de crear un contenido de tipo **QR** |
| Un comando de consola falla con un error sobre `apps/guess` | Falta el flag `--id=spui` |
