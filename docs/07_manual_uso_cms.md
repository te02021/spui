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

5. Contenido         ─┐ Contenido: QUÉ
6. Playlist          ─┘

7. Programación       ─  Operación: CUÁNDO
```

Los pasos 1 a 4 se hacen una vez por pantalla instalada. Del 5 en adelante es el trabajo del día a día. Un contenido se puede agregar a una playlist apenas se crea: no hay que publicarlo.

---

## 1. Dashboard — `/spui`

Es la pantalla de inicio (rehecha el 29 y el 30/10/2026). **El estado de cada reproductor cambia en el momento:** cuando un equipo vuelve o el sistema lo da por caído, el CMS avisa al navegador por MQTT y el panel se actualiza solo, sin recargar. El resto se refresca cada 20 s; el botón **Actualizar** lo fuerza. De arriba hacia abajo:

- **Alerta en curso** (rojo, sólo si hay alguna activa): título, mensaje, a cuántas pantallas va, desde cuándo y cuándo vence, con el botón **Desactivar**.
- **Avisos** (botón al lado de **Actualizar**): junta todo lo que requiere atención —cada reproductor desconectado o sobrecalentado, cada uno dado de alta que nunca dio señal (casi siempre su `spui.env` o el servicio), problemas que informan los equipos, accesos con clave inválida y tareas automáticas detenidas—. Verde con ✓ si no hay nada, ámbar con la cantidad si hay avisos, rojo (latiendo) si alguno es grave. Al tocarlo se abre un desplegable con la lista y el detalle de cada uno (con muchos, se desplaza por dentro); se cierra con la ×, tocando afuera o con Esc. Mientras está abierto, la lista se actualiza sola.
- **Cuatro tarjetas del mismo tamaño**, cada una con una cifra, una barra que la pone en contexto y una línea de detalle; tocarlas lleva a su sección:
  - **Pantallas:** cuántas están en línea (activas y con el reproductor conectado) de las activas. Barra verde = en línea, roja = activas sin señal, gris = inactivas.
  - **Contenidos:** cuántos hay disponibles, con la barra y el detalle por tipo (texto, imagen, video, QR, cronograma).
  - **Programación:** reglas activas de las creadas, cuántas playlists hay y cuántas pantallas activas no tienen playlist de respaldo (lo que muestran cuando ninguna regla aplica).
  - **Alertas:** cuántas hay en curso de las creadas, y la última activación.
- **Reproductores:** gráfico circular con conectados, desconectados y sin registrar, y el total en el centro. **Tocando un sector** (o su fila de la leyenda) se abre un globo encima, del color del sector, con los equipos de ese estado: pantallas, última señal y enlace a su telemetría. Se cierra con la ×, tocando afuera o con Esc. Un equipo cuenta como desconectado tras el umbral de conexión sin dar señal (`SPUI_UMBRAL_CONEXION_SEG`, 150 s por defecto).
- **Gráfico de la red** (desde el 03/11/2026 un solo gráfico para todos los reproductores, una línea de color por equipo; arranca en la temperatura de las últimas 24 h):
  - **Qué mirar:** los chips **Temperatura**, **CPU**, **RAM**, **Disco** (en uso, %) y **Latencia** (de la red con el CMS, ms). La temperatura y la CPU marcan su umbral de aviso.
  - **Período:** últimas 24 h, últimos 7 días o últimos 30 días.
  - **Reproductores:** un menú con casillas para elegir cuáles comparar; **Todos** marca o desmarca todos. Un equipo sin datos de esa variable dice "sin datos". El chip dice "Todos", el nombre del único elegido o "2 de 9".
  - **Lo elegido se recuerda** en ese navegador y no vuelve a cero con el refresco automático.
  - Para el detalle de un equipo, su **Ver telemetría**.
- **Con más de 6 equipos y todos marcados,** la leyenda pasa a una fila con flechas, se agrega la línea punteada **Promedio de la red** y de entrada se ven sólo los 5 de valor más alto entre los que están reportando; tocando un nombre en la leyenda se lo muestra u oculta (la elección se mantiene mientras la página se actualiza). Se arrastra, se acerca con la rueda y tiene los mismos botones (acercar, alejar, volver a las 24 h, descargar como imagen). Se actualiza solo: cada punto es el promedio de una hora (marcado al inicio de esa hora) y el último es la lectura más reciente de cada equipo, que llega cada minuto, así que la línea siempre llega hasta ahora. Si se movió o se acercó el gráfico, la vista se queda donde estaba.
- **Detalle por reproductor:** agrupado por gravedad —Desconectados, Sin registrar, Conectados, cada grupo con su subtítulo y cantidad, y por nombre dentro de cada uno—, una tarjeta por equipo con estado, pantallas que maneja, última señal, versión, y barras de temperatura, RAM y disco en uso, con el enlace a su telemetría. El borde izquierdo es verde si está conectado, rojo si no; en uno desconectado las métricas se ven en gris porque son las de su última señal.

Los avisos de **Requiere atención** aparecen sólo cuando hay algo que atender:

| Aviso | Cuándo aparece | Qué hacer |
|---|---|---|
| **Temperatura** (rojo) | Algún reproductor superó los 80 °C | Revisar la ventilación del equipo; lista cuáles y a cuánto están |
| **Reproductores con problemas** (ámbar) | Un reproductor está conectado pero informa algo que le impide mostrar bien (por ejemplo, la pantalla no responde al apagado programado) | Leer el detalle que lista debajo de cada equipo |
| **Intentos con API key inválida** (ámbar) | En la última hora, algún equipo pidió datos con una clave que no es la vigente | Si nombra un reproductor que "sigue usando la clave anterior", copiar la clave nueva a su `spui.env` y reiniciar el servicio en la Pi |
| **Tareas automáticas detenidas** (rojo) | El proceso del servidor que cada minuto marca reproductores caídos y vence alertas, y cada hora resume la telemetría, dejó de correr hace más de dos horas | Reiniciar el servicio `spui-telemetria` del servidor. Esas tareas corren solas: no hay nada que ejecutar a mano. Hasta el 29/10/2026 este aviso salía también, durante un rato de cada hora, con el servicio funcionando: era un error de cálculo, corregido |
La temperatura se pinta en ámbar a partir del 85 % del umbral y en rojo al superarlo; la RAM y el disco, en ámbar desde el 75 % y en rojo desde el 90 %.

---

## 2. Infraestructura

### 2.1 Edificios — `/spui/edificios`

Lo más general: un edificio o área del campus.

- **Crear:** nombre (ej. "Edificio Central") y descripción opcional (se ve en **Ver**, no en el listado).
- **Sectores:** el chip "N sectores" de cada edificio abre la lista de sus ubicaciones; tocar una abre su detalle.
- **Activar/desactivar:** un edificio inactivo no desactiva sus pantallas; sirve como filtro.
- **Eliminar:** solo si no tiene ubicaciones.

### 2.2 Ubicaciones — `/spui/ubicaciones`

Un sector dentro de un edificio.

- **Crear:** se elige el edificio y se pone el sector (ej. "Hall de entrada", "Pasillo PB"). El sector es opcional: si la ubicación abarca todo el edificio, se deja vacío.
- **Eliminar:** solo si no tiene pantallas.
- **Pantallas:** el chip "N pantallas" abre la lista; tocar una abre su detalle. La descripción se ve en **Ver**.

**Por qué importa:** al programar una playlist se puede elegir una ubicación como destino, y la regla aplica automáticamente a todas sus pantallas. Una sola regla en vez de una por pantalla.

### 2.3 Pantallas — `/spui/pantallas`

El monitor físico.

- **Crear:** nombre y ubicación (obligatorios) y estado.
- **Reproductor:** acá se asigna qué Raspberry Pi maneja esta pantalla. **La asignación se hace desde este formulario, no desde el de Reproductores.**

> **IP, MAC y resolución ya no existen** (30/10/2026, migración `Version20261030120000`). Se cargaban a mano y nada los usaba: el reproductor se identifica por su API key, reproduce a pantalla completa en lo que detecta por HDMI e informa su propia red.

- **Estado:** *Activa* o *Inactiva* (switch en el formulario).
- **Playlist de respaldo:** qué se reproduce cuando ninguna programación aplica en ese momento. Sin esto, la pantalla queda en espera.
- **Botón Energía:** abre la grilla de horarios de esa pantalla en una ventana emergente (ver 4.2).
- **Eliminar:** solo si no tiene reproductor asignado.
- Si el reproductor de la pantalla está **desconectado**, editarla, cambiarle el estado, eliminarla y tocar su energía quedan bloqueados hasta que vuelva (ver 6).

### 2.4 Reproductores — `/spui/reproductores`

La Raspberry Pi. **Acá se genera la clave que necesita el reproductor.**

**Al registrar uno:**

1. Se ingresa el hostname (ej. `spui-pi-01`), la versión de firmware si se sabe, y cada cuántos minutos sincroniza (ver abajo).
2. Al guardar, el sistema genera una **API key aleatoria de 64 caracteres**.
3. **La clave se muestra una sola vez**, en un cuadro con botón de copiar.
4. Hay que copiarla y ponerla en la Pi **antes de cerrar ese cuadro**. No se puede recuperar después: en la base solo queda su hash SHA-256.

Si se pierde, se usa **Regenerar clave** — eso invalida la anterior de inmediato y hay que actualizar la Pi.

**Sincronización.** La Pi le pregunta al CMS qué tiene que mostrar (el *sync*): la playlist que corresponde en ese momento, los archivos, el horario energético y la alerta activa. Lo hace en tres momentos:

- **Al instante**, cada vez que se guarda algo que afecta a sus pantallas (el CMS le avisa). Es lo normal.
- **Con el botón Sincronizar** del listado, para pedirlo a mano. Queda bloqueado con el reproductor desconectado y, si se aprieta dos veces seguidas, pide esperar unos segundos (el CMS acepta hasta 2 syncs por minuto por reproductor).
- **Cada cierto intervalo, por su cuenta**: el campo **Sincronizar cada** del formulario, en minutos enteros (1 o más, sin tope; 5 por defecto). Es el respaldo si el aviso instantáneo se pierde, y además fija cuánto puede tardar en arrancar una programación o un horario de energía, porque la Pi sólo sabe lo que va *ahora*. Al cambiarlo, la Pi lo toma en el momento.

En el listado, la columna **Última actividad** muestra el último heartbeat y el último sync (la hora exacta, al pasar el mouse); se marca en rojo si el sync lleva más del doble de su intervalo sin llegar. En **Ver** están el intervalo, el último sync y el próximo estimado.

**Botón Telemetría:** abre el histórico de ese reproductor (ver 5).

**Editar**, **Sincronizar** y **Regenerar clave** quedan bloqueados mientras el reproductor esté desconectado (ver 6). **Eliminar no**, a propósito: dar de baja un equipo es justamente lo que se hace cuando se rompió y no va a volver. Al eliminarlo se revoca también su usuario del broker MQTT.

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

El repositorio de todo lo que puede aparecer en una pantalla. Cinco tipos:

| Tipo | Qué se carga | Notas |
|---|---|---|
| **Texto** | El texto a mostrar | — |
| **Imagen** | Archivo JPG, PNG, GIF o WebP | Se adapta sola al guardarla: se gira según la foto y se achica a 1920×1080 como máximo, que es lo que muestra la pantalla. Una foto de celular se puede subir tal cual |
| **Video** | Archivo MP4 o MOV en H.264, hasta 1920×1080 y 30 cuadros por segundo | Un video más grande no se acepta: en la pantalla se vería trabado. El aviso aparece apenas se elige el archivo. **Un video de YouTube** se baja desde la PC (en 1080p o menos) y se sube como video: no hay un tipo para pegar el link |
| **QR** | URL destino (o los dos enlaces de tienda de una app) y vencimiento opcional, en el mismo formulario | El sistema genera la imagen automáticamente (ver §3.3) |
| **Cronograma** | Nada al crearlo | Al guardar se abre su listado de ítems (ver §3.2) |

Además se define **título** y **duración en segundos**:

- **Vacío = permanente:** si es el único contenido de la playlist queda fijo; si comparte playlist, se muestra 10 segundos.
- **Un video no pide duración:** dura lo que dura el archivo. El CMS la lee al guardarlo y la muestra al editarlo; la playlist lo muestra completo.
- **Un cronograma tampoco:** dura lo que tardan en pasar sus páginas (ver §3.2).

**Cómo se ve en pantalla** (desde noviembre 2026), en el mismo formulario de alta y de edición:

- **Plantilla:**
  - **Institucional:** arriba una franja blanca con el logo de la UNRaf y la fecha; abajo, el nombre del sistema y el lugar. El contenido ocupa el resto.
  - **Sin plantilla:** el contenido de borde a borde.
  - Al elegir el tipo se propone la que corresponde: institucional para texto, QR y cronograma; sin plantilla para imagen y video. Se puede cambiar.
  - **Un video va siempre sin plantilla:** ocupa toda la pantalla.
- **Tema:**
  - **Claro** u **Oscuro**.
  - **Automático** (el que viene elegido): claro de 07:00 a 19:00 y oscuro el resto del día.
- **Marca de agua:** sólo sin plantilla. Muestra el molino de la UNRaf chico en una esquina.

El modal **Ver** del contenido muestra lo elegido.

**Vista previa en el TV:**

- **Formulario:** el alta y la edición muestran, debajo de esas opciones, un TV achicado con el contenido tal como se va a ver.
  - Se actualiza solo con cada cambio: plantilla, tema, título, texto, segundos por página.
  - Una imagen o un video recién elegido se ve antes de guardarlo.
  - Así se puede comparar cada plantilla y tema antes de decidir.
- **Modal Ver:** arriba, los **Detalles**; después el **Código QR**, si lo es, con **Copiar** y **Abrir** en cada enlace; **Dónde se usa**, y abajo la única vista previa, a todo el ancho. En un cronograma propio, **Ver ítems** está al lado del título **Detalles**.
- **Cronograma:**
  - Se ve con las actividades de hoy que todavía no terminaron, y las páginas pasan deslizándose cada los segundos elegidos.
  - Uno "del edificio de la pantalla" tiene un selector **Como en…** para elegir en qué edificio mirarlo.
- **Junto al TV:** una línea dice a qué hora corresponde lo que se ve y qué tema tomó el automático. En el formulario va debajo; en el modal **Ver**, arriba, con **Ampliar** a la altura del título.
- **Ampliar:** la abre en grande. Desde el modal **Ver**, si el contenido tiene archivo, abre el visor con todas las acciones.

**Visor:** **Ampliar** en el modal **Ver**, o el ojo de un archivo en el formulario, lo abre a pantalla completa, sobre fondo oscuro.

- **Pestañas:** **Archivo** (lo que se subió, con su tamaño en píxeles o la duración del video) y **En pantalla** (cómo se ve en el TV, sin controles ni sonido).
- **Herramientas:** un video trae sus controles y se puede adelantar con la barra; en una imagen, zoom con los botones o la rueda del mouse, y tocar el porcentaje vuelve al 100%. Descargar y cerrar (también con Esc o tocando afuera).
- **Fondo a cuadros:** se ven las partes transparentes de una imagen.

**Estados:** sólo dos.

```
Disponible ──[Archivar]──► Archivado
    ▲                          │
    └──────[Desarchivar]───────┘
```

- **Disponible:** como queda todo contenido al crearlo. Se puede agregar a cualquier playlist.
- **Archivar:** lo saca **en el acto de todas las pantallas** donde se estaba mostrando, sin borrarlo y sin quitarlo de sus playlists. El aviso antes de archivar dice en cuántas playlists está. Un archivado no se ofrece para agregar a playlists ni para acompañar una alerta.
- **Desarchivar:** vuelve a estar disponible y **vuelve solo** a mostrarse en las playlists donde estaba, en el mismo lugar.
- **Eliminar:** solo si no está en ninguna playlist.

Hasta el 29/10/2026 había además **Borrador** y **Publicar/Despublicar**: un contenido recién creado no se podía usar hasta publicarlo. Se sacó para simplificar; los que estaban en borrador pasaron a disponibles.

### 3.2 Cronogramas

Un contenido tipo cronograma es una tabla de horarios (materias, aulas, docentes). Se carga **una vez por semana**, como el Excel de horarios: cada clase lleva los días en que se dicta, y cada pantalla muestra sólo lo del día.

**Qué cronograma muestra** (al crearlo o editarlo):

- **Propio:**
  - Tiene sus clases, cargadas a mano o desde el Excel.
  - Opcionalmente, un **edificio** y una **vigencia** (desde / hasta, por ejemplo el cuatrimestre).
  - El edificio hace falta para que lo encuentren los cronogramas "del edificio de la pantalla". Sin vigencia, rige siempre.
- **Del edificio de la pantalla:**
  - No tiene clases propias.
  - En cada pantalla muestra el cronograma propio vigente del edificio donde está ubicada.
  - Si una pantalla se muda de edificio (se le cambia la Ubicación), cambia de cronograma sola.
- **Segundos por página:**
  - Cada página muestra las actividades que entran y pasa a la siguiente deslizándose. Por defecto 10 segundos, de 5 a 60.
  - **No se corta ni se achica nada:** una materia larga ocupa más de un renglón y la fila crece hacia abajo; el docente va debajo del nombre completo. Por eso una página puede tener más o menos actividades que otra.
  - La duración del cronograma en la playlist se calcula sola: páginas × segundos.

Sus ítems se administran en una **ventana emergente**, como el resto del panel:

- **Cómo se abre:**
  - con el botón **Ítems** del listado de Contenidos;
  - con **Ver ítems** desde el detalle o la edición del contenido;
  - sola, apenas se crea un cronograma propio.
- **Agregar ítem**, **Editar** y **Eliminar** se abren encima del listado. Agregar y Editar son el mismo formulario. Cada ítem pide:
  - **Tipo:**
    - **Clase** o **Actividad** se repiten los días marcados (al menos uno).
    - **Evento especial** ocurre una sola vez, en su **fecha**, y ese día aparece junto con las clases.
  - **Materia o actividad**.
  - **Aula**, **carrera**, **año** y **docente o responsable**, todos opcionales.
  - **Hora de inicio y fin**, y **estado**.
- **Se guarda al guardarlo:** al terminar se cierra esa ventana y el listado se actualiza solo. Las pantallas que muestran el cronograma reciben el cambio en segundos.
- Si alguna pantalla que lo muestra tiene su reproductor desconectado, las tres acciones quedan bloqueadas (ver 6); el listado se puede mirar igual.
- **Buscar y filtrar:** arriba de la lista hay un buscador por materia, aula o docente (no importan tildes ni mayúsculas) y tres selectores que se combinan:
  - **Día:** lo de ese día de la semana. Un evento especial aparece en el día en que cae su fecha.
  - **Fecha:** lo que se da un día puntual: las clases de ese día de la semana y los eventos de esa fecha.
  - **Hora:** lo que está en curso a esa hora exacta. Se elige con un selector de dos columnas, hora y minutos (de 1 en 1), que se desplazan; **Ahora** pone la hora actual, **Aplicar** filtra y **Todas las horas** quita el filtro. Con las flechas del teclado también se mueven.
  - Las opciones de Día dicen cuántos ítems quedarían.
- **De a 25 por página** (desde el 03/11/2026), con la misma barra que los listados: "26–50 de 226 ítems" a la izquierda y las páginas a la derecha, sobre lo que dejan los filtros. Con un filtro puesto, arriba dice cuántos coinciden ("21 de 226 ítems coinciden."). Cambiar un filtro o la búsqueda vuelve a la primera página.
- Lo elegido y la página se mantienen al volver de agregar o editar un ítem.

La Pi recibe **la semana entera** (desde el 02/11/2026): las clases con sus días y los eventos de los próximos 7 días. Elige sola lo de hoy y lo que todavía no terminó, con las mismas reglas que la vista previa, así pasa de un día al otro sin depender del CMS. Se ordenan por hora de inicio y aula.

Un evento especial puede no tener **hora de fin**: en la pantalla dice "Desde las 18:00" y sigue hasta que termina el día. Una clase o actividad la tiene siempre.

#### Importar el Excel de horarios

**Contenidos → Nuevo contenido → tipo Cronograma → Importar desde Excel** (desde el 03/11/2026 el botón está ahí y no en la cabecera de Contenidos) carga los horarios de todo el cuatrimestre desde el Excel (`.xlsx`) o un `.csv`, sin cargar clase por clase.

1. **Elegir el archivo.** Se lee apenas se elige.
   - Una hoja por edificio, con los títulos Día, Aula, Horario, Carrera, Año, Materia y Docente. El día y el aula pueden ir en celdas combinadas.
   - Opcional, una hoja **Eventos especiales** con Edificio, Fecha, Hora inicio, Hora final, Aula, Evento a realizar y Responsable.
   - Las columnas se reconocen por su título, no por su posición.
2. **Revisar.** Antes de guardar nada se ve, por cada hoja:
   - **Qué se leyó:** cuántas actividades y cuántas por día. Una misma clase que se dicta varios días queda como una sola actividad con todos sus días.
   - **Qué se corrigió solo**, con el número de fila:
     - carrera y año invertidos;
     - años escritos de distintas formas ("1 ERO", "2DO/4TO" → "1.º", "2.º y 4.º");
     - horarios como "18:00 20:00" o "18.30 a 22:30";
     - aulas ("6A" → "6 A", sin la capacidad entre paréntesis).
   - **Qué no se pudo leer y por qué.** Nada se descarta sin avisar.
   - **A dónde va:** un cronograma nuevo (con su título), reemplazar las clases de uno existente (avisa cuántas actividades y eventos se pierden) o no importar esa hoja.
   - **Edificio:** se propone por nombre.
   - **Vigencia:** la del cuatrimestre, desde y hasta. Una sola para todo el archivo.
   - **Eventos especiales:**
     - Se agrupan por su columna Edificio, y cada grupo se suma a la hoja que se elija. Por ejemplo, "Campus E1" va con Campus LAB.
     - Los eventos que ya pasaron no se importan.
     - El responsable pasa a ser el docente. La cantidad de personas y los elementos adicionales no se importan.
     - Las horas escritas con punto se leen como hora y minutos (9.3 = 9:30).
3. **Guardar.**
   - Se guarda todo junto: si algo falla, no cambia nada.
   - Las pantallas que muestran esos cronogramas reciben el cambio en segundos. Las desconectadas lo reciben al reconectarse: la importación no se bloquea por un reproductor apagado, y la vista previa lo avisa.

Volver a importar el mismo Excel con correcciones, eligiendo "reemplazar", deja cada cronograma igual al Excel, sin duplicar nada.

### 3.3 Códigos QR — dentro de Contenidos

**No hay una sección aparte de códigos QR.** Un QR es un tipo de contenido más y se administra
como cualquier otro: Contenidos → Nuevo → tipo **QR**.

- **Crear:** el formulario se adapta al elegir el tipo y pide **URL destino** (a dónde llega quien
  escanea) y un **vencimiento opcional**. Nada más: el nombre del código es el título del contenido.
- **QR de una app** (desde el 03/11/2026): en **URL destino** se pegan los dos enlaces, el de Google
  Play y el del App Store, uno por renglón y en cualquier orden. El CMS reconoce solo cuál es cuál.
  - **Android** va directo a Google Play y **iPhone** al App Store, con un solo código y un solo contador.
  - **Una computadora o un iPad** (Safari en iPad se presenta como una Mac) ven una página de la UNRaf
    con los dos botones de las tiendas, en vez de una tienda elegida a ciegas.
  - **Con dos enlaces** tiene que ser uno de cada tienda; con más de dos, o dos que no son de tienda,
    el formulario lo dice debajo del campo.
  - **Para probarlo sin iPhone:** en Chrome, F12 → el ícono de dispositivo → elegir un iPhone, y abrir
    la dirección del QR (`/api/spui/qr/{id}/r`): tiene que llevar al App Store.
- **La imagen se genera sola.** El QR no codifica la URL destino sino un redirect del CMS: por eso
  se pueden contar los escaneos **y cambiar el destino sin reimprimir nada**.
- **Editar:** desde Editar del contenido, igual que se edita el archivo de una imagen o el texto de
  un cartel. Cambiar el destino **no invalida** los QR ya impresos ni el que está en pantalla.
- **Escaneos:** el contador se ve en el modal **Ver** del contenido, junto con su destino y su
  vencimiento, y **se actualiza solo, en el momento del escaneo**: si tenés el modal abierto, el
  número cambia sin tocar nada ni recargar la página. La imagen del código se abre con **Ampliar**,
  igual que la de una imagen o un video.
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
  - abajo, todos los contenidos disponibles (los no archivados), para **agregar**. Un mismo contenido **se puede agregar más de una vez** (por ejemplo, para que un aviso salga más seguido dentro del loop); el catálogo indica "En la lista ×N".
- **Guardar y Cancelar de la ventana de ítems aparecen sólo cuando cambiaste algo.** **Guardar** confirma la lista y cierra la ventana (al reabrirla, sigue ahí); **Cancelar** vuelve a lo que estaba y cierra.
- **Lo que se guarda de verdad es la playlist:** los ítems confirmados se persisten recién con el **Guardar** del formulario de la playlist, junto con el nombre y la descripción. Si cancelás la playlist, no queda nada de lo que hiciste en los ítems. Al guardar, las pantallas que usan esa playlist reciben el cambio en segundos.
- **La duración la define el contenido**, no la playlist: un mismo contenido dura lo mismo en todas las playlists donde esté (y en cada una de sus repeticiones). La ventana de ítems muestra al lado de cada uno **cuánto va a durar de verdad** en esa playlist ("10 s", "23 s", "En bucle", "Permanente"), y se actualiza al agregar o quitar ítems.
- **Un contenido archivado sigue en la lista**, marcado "Archivado" y con la duración "No se muestra": no sale en pantalla, no cuenta para las duraciones de los demás y vuelve solo a su lugar al desarchivarlo. Si se lo quita de la lista, ya no vuelve.
- **Si todos los contenidos de una playlist están archivados**, no hay nada que mostrar: una pantalla que la tenía por programación pasa a su playlist de respaldo, y si no tiene, a la pantalla de espera.
- **No hay Activar/Desactivar** (se sacó el 29/10/2026: no frenaba la reproducción). Una playlist se muestra si está asignada a una pantalla como respaldo o a una regla de Programación vigente; para dejar de mostrarla, se la saca de ahí.
- Desde **Ver** se consulta la lista de ítems en modo sólo lectura.
- **Eliminar** borra la playlist con sus ítems; los contenidos se conservan (desde el 03/11/2026 no hace falta quitar los ítems antes). No se puede eliminar mientras la use una regla de Programación o sea la playlist de respaldo de una pantalla: el aviso dice cuáles, y primero hay que cambiar eso.
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

Cada cambio le llega a la Pi en segundos (el CMS le avisa que sincronice), y la Pi actúa sola. Qué puede hacer depende de la pantalla, y la Pi lo **detecta** sola al arrancar (y cada media hora):

- **TV que responde a HDMI-CEC:** se apaga y se enciende de verdad.
- **Pantalla sin CEC** (un monitor de PC, o un TV sin CEC o con CEC desactivado): se corta la señal HDMI. Un monitor entra solo en reposo; un TV queda encendido mostrando "sin señal".
- **Brillo:** el porcentaje es **relativo al brillo que tiene configurado la pantalla** (el que dejó quien la instaló): con el monitor en 50, una regla de 80 % lo deja en 40, y sin regla vuelve a 50.
  - Si la pantalla permite controlar su brillo por el cable (DDC/CI, lo tienen la mayoría de los monitores), se cambia el brillo real y baja el consumo.
  - Si no (la mayoría de los TV), se oscurece la imagen que manda la Pi: se ve igual de tenue, pero no ahorra energía. El reproductor lo avisa.
  - Si alguien cambia el brillo con los botones del monitor, el reproductor lo toma en cuanto hace falta, sin estar consultando la pantalla: cuando empieza una regla, la aplica sobre ese brillo nuevo; si el cambio se hace **durante** una regla, vuelve el brillo de la regla en la próxima sincronización con el CMS (a lo sumo 5 minutos, o al instante si se guarda algo en el CMS) y el reproductor lo avisa en el panel: el CMS manda. Fuera de las reglas la pantalla no se consulta nunca.
  - Durante una alerta la pantalla va al máximo brillo.

Si la pantalla tiene horario energético y algo no se puede hacer del todo (no responde a CEC, no hay forma de cortar la señal, un apagado que falló), el reproductor lo informa: aparece como aviso en el listado de Reproductores, en su detalle y en el Dashboard. Sin horario energético no se avisa nada.

### 4.3 Alertas — `/spui/alertas`

Para avisos urgentes o de último momento: evacuaciones, incidentes, un cambio de aula.

El listado muestra arriba cuántas hay activas, listas para activar y expiradas, y una tarjeta por alerta: las activas primero, con la etiqueta roja **En curso**; las expiradas, atenuadas. Cada tarjeta trae el mensaje (dos renglones; completo en **Ver**), la prioridad, a qué pantallas va, el vencimiento, el adjunto y si tiene sonido propio.

**Ver** abre el detalle con **"Así se ve en las pantallas"**: una vista previa con el mismo diseño que dibuja el reproductor (banda oscura con el título, adjunto de imagen estirado debajo, mensaje centrado y pie institucional). Es aproximada: el reproductor ajusta el tamaño del mensaje para que entre y la vista previa lo imita por tramos de largo. Un video adjunto se indica con una etiqueta, no se reproduce.

- **Crear:** título, mensaje, prioridad, vencimiento opcional, contenido multimedia opcional para acompañar, y **sonido de alerta opcional** (MP3 o WAV, hasta 5 MB) — se puede escuchar antes de guardar con el reproductor que aparece al elegir el archivo.
- **Editar:** solo si está inactiva. Una alerta activa no se puede editar — hay que desactivarla primero, porque el aviso que ya salió a las pantallas no coincidiría con lo que muestra el panel. Desde acá también se reemplaza o se quita el sonido personalizado (casilla "Quitar sonido personalizado").
- **Activar:** el aviso llega a todas las pantallas **en segundos**, interrumpiendo lo que estén mostrando. También aparece arriba de todo en el Dashboard, en rojo.
- **Desactivar:** los reproductores vuelven a su programación normal (y el sonido se corta al instante).
- **Eliminar:** solo alertas inactivas.
- **Activar** y **Desactivar** quedan bloqueados si alguna pantalla a la que va la alerta tiene su reproductor desconectado (ver 6).

**Un reproductor que estaba apagado recibe la alerta apenas se enciende** — el broker guarda el último aviso. Y si MQTT no está disponible, llega igual en la próxima sincronización (tarda más, pero no se pierde).

**Sin sonido personalizado, cada reproductor reproduce un tono genérico propio** — así ninguna pantalla queda muda por no tener un archivo cargado. El sonido (personalizado o el tono genérico) vuelve a sonar apenas termina, mientras la alerta siga activa. La pausa entre repeticiones se configura en cada Pi (`SPUI_ALERTA_SONIDO_INTERVALO_SEG`, 0 por defecto — ver `09_instalacion_raspberry.md` §8.6).

**Una alerta enciende la pantalla aunque el horario energético diga que debe estar apagada.**

---

## 5. Telemetría de un reproductor — `/spui/reproductores/{id}/telemetria`

Se entra con el botón **Telemetría** del listado de reproductores.

- **Indicadores de arriba:** temperatura, uso de CPU (desde el 03/11/2026: el promedio de cada minuto), RAM y disco en uso, en círculos que se llenan hasta el valor actual (verde o azul, ámbar al acercarse al límite, rojo al pasarlo). El del disco cuenta lo **en uso** contra la capacidad total: debajo dice cuántos GB se usan y el total, y el máximo del período sólo si es distinto del actual. La capacidad la informa el cliente de la Pi desde la versión del 29/10/2026; con uno anterior se muestra el espacio libre. Debajo de cada uno, el promedio y el máximo del período elegido.
- **En vivo:** al abrir la pantalla, el botón **En vivo** se pone verde y temperatura, CPU y RAM se actualizan **cada segundo**, en un panel que se desliza solo (se ven los últimos 2 minutos; se guardan 10). Mientras llega el primer dato, el panel muestra "Esperando los datos del reproductor…" en su lugar, sin mover el resto de la página. Se puede arrastrar hacia atrás o acercar con la rueda: deja de deslizarse hasta que se vuelve al final o se toca **volver** (↺). No se guarda: es para mirar en el momento. Clic en el botón para pausarlo. **Con el reproductor desconectado no se le pide nada:** el botón dice **Desconectado** y el modo en vivo arranca solo cuando el CMS detecta que volvió. Si se cae mientras se lo mira, los gráficos en vivo se vacían y vuelven a empezar cuando reconecta. Si dice **Sin datos en vivo**, el equipo figura conectado pero no manda datos (por ejemplo, una versión vieja del cliente): se deja de pedir y el botón sirve para reintentar. El resto de la pantalla funciona igual.
- **Histórico por hora:** tres gráficos con escala fija: temperatura (con la línea roja del umbral), RAM y disco en uso (con la línea roja de la capacidad). Al pasar el cursor se ve el valor exacto y la hora.
  - **Últimas 24 h / 7 días / 30 días** eligen cuánto se ve a la vez.
  - **Arrastrar** el gráfico hacia la derecha muestra las horas anteriores, hasta 60 días atrás; hacia la izquierda se vuelve, hasta el momento actual (no más).
  - **La rueda del mouse** sobre un gráfico acerca o aleja, centrado en donde está el puntero. Los botones de cada gráfico hacen lo mismo (lupa + / −), **↺** vuelve al período elegido y **⤓** lo descarga como imagen.
  - Todo movimiento es suave. Cada gráfico es independiente: el zoom, el arrastre y sus botones afectan sólo a ese gráfico; los botones de período (24 h, 7 días, 30 días) cambian los cuatro (temperatura, CPU, RAM y disco). En vivo pasa lo mismo con temperatura, CPU y RAM: cada uno tiene sus botones y deja de seguir los datos nuevos por su cuenta.
- **Exportar** (botón al lado de En vivo): se abre una ventana para elegir el formato y el período, y **Descargar**.
  - **Excel:** planilla con filtros y fechas reales (se pueden ordenar y graficar).
  - **CSV:** texto separado por punto y coma, con coma decimal: se abre con doble clic en un Excel en castellano.
  - **PDF:** informe para imprimir o adjuntar, con el resumen del período y el detalle.
  - Una fila por hora con promedio y máximo de temperatura y RAM, el disco en uso máximo (en GB y %) con la capacidad, y la cantidad de lecturas. Se conservan los últimos **60 días**; la hora en curso entra recién cuando termina.

Los datos se agrupan por hora: con una lectura por minuto, 7 días serían unos 10 000 puntos imposibles de leer.

**Si dice que no hay datos:** verificar que el reproductor esté encendido y que el daemon `spui:mqtt:subscribe` esté corriendo en el servidor. Sin ese proceso, la telemetría que publica la Pi no llega a la base.

---

## 6. Cosas que conviene saber

**Todos los listados son tarjetas** (desde el 30/10/2026, el estilo que estrenó Alertas): una por registro, con una franja de color por estado (verde activo/conectado, rojo desconectado o alerta en curso, ámbar algo a revisar, gris inactivo, sin atenuar el contenido: una tarjeta inactiva se puede usar igual), el título, los datos en chips y las acciones a la derecha. Algunos chips se tocan y abren una lista corta encima (los sectores de un edificio, las pantallas de una ubicación, los ítems de una playlist, los días de una regla); se cierra tocando afuera o con Esc. Cada ítem de una playlist trae un botón 👁 que lo muestra sin salir del listado: una imagen, un video o un QR en el visor de pantalla completa; un texto o un cronograma, en su detalle. Si una búsqueda o un filtro no encuentran nada, el listado lo dice y ofrece **Limpiar búsqueda** o **Quitar filtros**. En **Reproductores**, el último chip de cada tarjeta abre su diagnóstico (ámbar con la cantidad de problemas, verde si no hay); al lado, **Última señal** (el aviso de vida que manda cada 60 s) y **Sincronizado** (la última vez que bajó su programación). En **Programación**, el chip de días resume la semana ("Lun a vie", "Todos los días") y al tocarlo la muestra completa.

**Buscador:** arriba a la izquierda de cada listado. Filtra a medida que se escribe, sin botón (la × lo limpia, también Esc), y se combina con los chips y la paginación. Busca en: Edificios, nombre y descripción; Ubicaciones, sector, edificio y descripción; Reproductores, nombre y el de sus pantallas; Pantallas, nombre, ubicación y reproductor; Contenidos, título y texto; Playlists, nombre y descripción; Programación, playlist y destino; Alertas, título y mensaje. Las cantidades de los chips cuentan sólo lo que coincide con la búsqueda. Va en la dirección (`?q=`), como los filtros.

**Filtros:** al lado del buscador (salvo en Playlists), un chip por grupo con la opción elegida y su cantidad (por defecto **Todos**); tocarlo abre las demás opciones, con su cantidad y una tilde en la elegida. Si hay un filtro puesto, el chip queda en azul. Cuando el buscador y los chips no entran en una fila, se centran. Edificios, Ubicaciones y Pantallas: activos / inactivos. Reproductores: conectados / desconectados / sin registrar (por la última señal, en el momento). Contenidos: disponibles / archivados y, aparte, un chip por tipo; los dos grupos se combinan. Programación y Alertas: activas / inactivas y, aparte, el orden por prioridad (mayor primero, por defecto, o menor primero); en Alertas, las activas van siempre arriba. El filtro va en la dirección (`?estado=activos`), así que se puede compartir o volver con "atrás".

**Acciones de cada tarjeta** (desde el 03/11/2026): queda a la vista **Ver** y el resto está en el botón **⋮ Más**, que abre una lista: Editar, Activar o Desactivar, Ítems, Energía, Telemetría, Sincronizar, Clave, según la sección, y **Eliminar** al final, en rojo.
- **Al elegir una acción**, el menú se cierra y la acción sigue como siempre: su confirmación o su ventana.
- **Una acción bloqueada** porque el reproductor está desconectado se ve atenuada y dice el motivo debajo.
- **El menú se cierra** tocando afuera o con Esc.
- **Ver** es azul y **Más** es blanco con borde. Si no entran al lado de los datos de la tarjeta, bajan a otro renglón, centrados.

**Ventanas (modales):**

- **Ancho:** todas las que muestran información (formularios, vistas, listas de ítems, importar) tienen el mismo ancho y aprovechan los costados. Las confirmaciones ("¿Eliminar…?") son angostas.
- **Botones del pie:** Guardar y Cancelar llevan ícono y texto; el botón que confirma una acción lleva el mismo ícono que el que la abrió.
- **Acciones de cada fila dentro de una ventana** (los ítems de un cronograma, la grilla de energía, el catálogo de una playlist): sólo el ícono; al pasar el mouse aparece su nombre, o por qué está bloqueada.
- **En celular:** los botones son un poco más chicos y Guardar y Cancelar se reparten el ancho.
- **Al elegir el tipo de un contenido nuevo,** sus campos aparecen desplegándose de a poco.

**Encabezado:** a la derecha, el círculo con tus iniciales abre tu cuenta (nombre, email y **Volver a la Intranet**). El CMS no tiene cierre de sesión propio: la sesión es la de la Intranet.

**Menú lateral plegable** (escritorio): el botón « a la derecha de "Principal" lo oculta y el contenido toma todo el ancho; queda un botón flotante arriba a la izquierda para volver a abrirlo. La elección se recuerda al cambiar de sección. En celular sigue el menú de hamburguesa.

**Paginación:** cada listado muestra 10 por página; con más, aparece abajo "1–10 de N" y los botones de página. La página va en la dirección (`?pagina=2`), así que el refresco automático y los avisos en vivo no te mueven de ella.

**El panel funciona en celular.** Por debajo de 768 px el menú pasa a hamburguesa y las acciones de cada tarjeta bajan debajo de sus datos.

**Casi todo se hace en ventanas emergentes** sin salir del listado, incluidos los ítems de una playlist, los ítems de un cronograma y la grilla de energía. La única excepción es el histórico de telemetría, que necesita la pantalla entera para los gráficos.

**Los borrados están protegidos.** El sistema no deja eliminar algo que otra cosa esté usando; avisa qué lo está bloqueando.

**No se puede cambiar lo que muestra un reproductor desconectado.** Si una acción afecta a alguna pantalla cuyo reproductor está desconectado, el botón aparece atenuado y, al pasar el mouse, dice qué equipo está caído y hace cuánto. Así nadie guarda un cambio creyendo que llegó a una pantalla que no lo va a recibir. Se bloquean:

- Pantallas: editar, activar/desactivar, eliminar y energía.
- Reproductores: editar y regenerar la clave (eliminar **no**, ver 2.4).
- Contenidos: editar, archivar, desarchivar, eliminar y los ítems de un cronograma.
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
| Un contenido no aparece al armar la playlist | Está archivado: hay que desarchivarlo desde Contenidos |
| Un contenido dejó de verse en las pantallas pero sigue en la playlist | Está archivado (la ventana de ítems lo marca): desarchivarlo |
| La alerta tarda en llegar | MQTT no disponible; llega igual en el próximo sync (según el intervalo del reproductor, 5 min por defecto) |
| No hay datos de telemetría | Falta el daemon `spui:mqtt:subscribe --id=spui` |
| El QR no cuenta escaneos | Se cargó la URL como contenido de tipo texto en vez de crear un contenido de tipo **QR** |
| Un comando de consola falla con un error sobre `apps/guess` | Falta el flag `--id=spui` |
