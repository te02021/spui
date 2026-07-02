# SPUI — Manual del CMS

**Sistema de Pantallas Informativas Universitarias — UNRaf**  
Última actualización: julio 2026  
Estado del proyecto: Fase 9 (CMS funcional — API REST y funciones energéticas pendientes)

---

## Cómo está organizado el sistema

El CMS se organiza en dos grandes áreas:

- **Gestión de contenido:** Contenidos → Playlists → Programación → Alertas
- **Infraestructura:** Ubicaciones → Pantallas → Nodos (Raspberry Pi)

El flujo habitual es de abajo hacia arriba: primero registrás la infraestructura (dónde están las pantallas y quién las controla), después creás el contenido, lo organizás en playlists y finalmente programás cuándo se reproduce en cada lugar.

---

## Parte 1 — Lo que ya funciona en el CMS

---

### 1. Dashboard — `/spui`

**Para qué sirve:** Es la pantalla de inicio del CMS. Te da un vistazo rápido al estado de toda la red de pantallas sin tener que navegar a otro lado.

**Lo que podés hacer acá:**

- Ver cuatro tarjetas de resumen en la parte superior: total de nodos registrados, cuántos están conectados, cuántos están offline y cuántos llevan más de 5 minutos sin enviar señal (esto último puede indicar un problema de red o que la Pi se colgó).

- Ver la tabla principal con **todos los nodos** y su estado en tiempo real:
  - Hostname de la Raspberry Pi
  - Pantalla y ubicación que controla
  - Estado de conexión (conectado / desconectado / sin registrar)
  - Temperatura del procesador (SoC) en °C — si superara 80°C es señal de alerta
  - Porcentaje de uso de RAM
  - Espacio libre en disco en MB
  - Timestamp del último heartbeat recibido

- Si existe una **alerta de emergencia activa**, aparece un banner rojo en la parte superior con el título de la alerta y un botón para desactivarla directamente desde el dashboard sin tener que ir a la sección de alertas.

- Usar el botón **"Actualizar"** para refrescar los datos de la tabla y las tarjetas sin recargar toda la página (hace una petición AJAX en segundo plano).

---

### 2. Contenidos — `/spui/contenidos`

**Para qué sirve:** Es el repositorio de todo el material multimedia que puede mostrarse en las pantallas. Todo lo que eventualmente aparece en una pantalla pasó primero por acá.

**Lo que podés hacer acá:**

- **Crear un contenido nuevo** — Hay 5 tipos disponibles, y el formulario cambia según cuál elegís:
  - **Imagen:** Subís un archivo JPG, PNG, GIF o WebP desde tu computadora. El sistema calcula el hash SHA-256 del archivo para que las Raspberry Pi puedan detectar si el archivo cambió y solo descarguen lo nuevo.
  - **Video:** Subís un archivo MP4 o WebM. Mismo proceso de hash que las imágenes.
  - **Texto:** Escribís directamente el texto que querés que aparezca en la pantalla.
  - **YouTube:** Pegás la URL de un video de YouTube (ej: `https://www.youtube.com/watch?v=...`). La Pi lo reproduce en fullscreen.
  - **QR:** Pegás una URL y el sistema genera un código QR que se muestra en la pantalla para que los estudiantes lo escaneen con el celular.

  Para cada contenido también definís un **título** descriptivo y una **duración en segundos** (cuánto tiempo se muestra antes de pasar al siguiente ítem de la playlist).

- **Publicar** un contenido — Los contenidos nuevos quedan en estado *Borrador* hasta que los publicás explícitamente. Solo los contenidos en estado *Publicado* aparecen disponibles cuando armás una playlist. Esto te permite preparar material con anticipación sin que aparezca antes de tiempo.

- **Archivar** un contenido — Lo sacás de circulación sin eliminarlo. Un contenido archivado no aparece en el editor de playlists pero queda en el historial.

- **Eliminar** un contenido — Solo podés eliminarlo si no está siendo usado en ninguna playlist actualmente. Si está en uso, el sistema te avisa en cuántas playlists aparece.

**Flujo típico:**
1. Creás el contenido (queda en Borrador)
2. Lo revisás y lo Publicás
3. Vas a Playlists y lo agregás a la playlist correspondiente

---

### 3. Playlists — `/spui/playlists`

**Para qué sirve:** Las playlists son las "listas de reproducción" del sistema. Agrupan contenidos en un orden específico y determinan qué se ve en pantalla. Una playlist se reproduce en loop hasta que la programación indica que debe cambiar.

**Lo que podés hacer acá:**

- **Crear una playlist nueva** — Le ponés un nombre y opcionalmente una descripción. Después la completás con contenidos desde el editor.

- **Abrir el editor de playlist (Builder)** — Haciendo clic en "Editar" de cualquier playlist entrás al editor drag-and-drop. Tiene dos paneles:
  - **Panel izquierdo (la playlist):** Los ítems en el orden actual. Cada ítem muestra el número de orden, el tipo de contenido, el título y la duración. En la parte inferior ves la **duración total** sumada de todos los ítems.
  - **Panel derecho (contenidos disponibles):** Todos los contenidos publicados que aún no están en la playlist. Cada uno tiene un botón "+ Agregar".

  Desde el editor podés:
  - **Agregar contenidos** haciendo clic en "+ Agregar" en el panel derecho
  - **Eliminar ítems** con el botón de basura de cada ítem en el panel izquierdo
  - **Reordenar** arrastrando los ítems con el handle de 6 puntos a la izquierda de cada ítem — el nuevo orden se guarda automáticamente via AJAX (no necesitás hacer clic en ningún "Guardar")

- **Activar/desactivar** una playlist desde el listado

**Nota sobre duración:** La duración de cada ítem en la playlist puede ser diferente para cada aparición. Por ejemplo, el mismo video puede durar 30 segundos en una playlist y 60 segundos en otra — esto se configura directamente en el ítem de la playlist, sobreescribiendo la duración base del contenido.

> **Esa funcionalidad de override de duración por ítem está en la base de datos (`duracion_override_seg`) pero todavía no está expuesta en el editor UI.**

---

### 4. Programación — `/spui/programacion`

**Para qué sirve:** Acá definís el horario de cada playlist: en qué pantallas se reproduce, en qué días y en qué franjas horarias. Es el "director de orquesta" del sistema.

**Lo que podés hacer acá:**

- Ver la tabla de todas las reglas de programación con: destino, playlist, horario, días activos (mostrados como badges L/M/X/J/V/S/D), prioridad y estado.

- **Crear una nueva regla** — En el formulario completás:
  - **Playlist:** Seleccionás cuál querés programar
  - **Destino:** Tenés tres opciones excluyentes:
    - *Pantalla específica:* La regla aplica solo a esa pantalla
    - *Ubicación:* La regla aplica a todas las pantallas de ese edificio/aula
    - *Global:* Si no seleccionás ninguna de las dos anteriores, aplica a todas las pantallas del sistema
  - **Horario:** Hora de inicio y hora de fin de la franja
  - **Fechas:** Fecha de inicio (obligatoria) y fecha de fin (opcional — si no la ponés, la regla no tiene vencimiento)
  - **Días de la semana:** Checkboxes con los 7 días. Si no marcás ninguno, el sistema asume todos los días.
  - **Prioridad:** Un número entero. Si en el mismo horario y pantalla hay dos reglas activas, el nodo reproduce la de mayor prioridad.

- **Activar/desactivar** una regla sin eliminarla — útil para pausar temporalmente una programación y reactivarla después

- **Eliminar** una regla

**Regla de prioridad:** Las alertas de emergencia siempre tienen prioridad absoluta sobre cualquier programación, sin importar el número de prioridad que tenga la regla.

---

### 5. Alertas de Emergencia — `/spui/alertas`

**Para qué sirve:** Mecanismo de máxima urgencia para mostrar un mensaje en TODAS las pantallas del sistema en segundos, interrumpiendo lo que sea que estén reproduciendo. Pensado para evacuaciones, avisos urgentes, incidentes de seguridad.

**Lo que podés hacer acá:**

- Ver el listado de todas las alertas con su estado (Activa / Inactiva / Expirada), prioridad y fecha de expiración.

- **Crear una alerta** — Completás:
  - **Título:** Ej: "EVACUACIÓN — Edificio Central"
  - **Mensaje:** El texto completo del aviso
  - **Prioridad:** Si hay varias alertas activas simultáneamente, la de mayor prioridad tiene precedencia
  - **Expira en:** Fecha y hora en que la alerta se desactiva automáticamente (opcional — si no la ponés, solo se desactiva manualmente)
  - **Contenido adjunto:** Podés adjuntar una imagen o video de contenidos existentes para que se muestre junto al texto de la alerta

- **Activar** una alerta — Al hacer clic en "Activar" pasan tres cosas casi simultáneamente:
  1. El CMS marca la alerta como activa en la base de datos
  2. Publica el evento en el broker MQTT en el topic `spui/alerts`
  3. Todos los nodos suscritos reciben la alerta y cambian inmediatamente lo que muestran
  4. Aparece el banner rojo en el dashboard del CMS

- **Desactivar** una alerta — Lo inverso: el CMS notifica a todos los nodos que vuelvan a la programación normal.

- **Eliminar** una alerta inactiva — No podés eliminar una alerta que esté activa.

**Importante sobre nodos offline:** Si una Pi estaba sin conexión cuando se activó la alerta, en cuanto recupere la conexión y haga el próximo sync detecta que hay una alerta activa y la muestra. El sistema está diseñado para que ningún nodo "se pierda" una alerta urgente.

---

### 6. Infraestructura — Ubicaciones — `/spui/ubicaciones`

**Para qué sirve:** Representan los espacios físicos del campus donde están instaladas las pantallas. Son la base geográfica del sistema.

**Lo que podés hacer acá:**

- **Crear una ubicación** — Completás:
  - **Edificio:** Nombre del edificio o área (ej: "Edificio Central", "Aula Magna", "Biblioteca")
  - **Aula/Espacio:** Campo opcional para especificar el espacio dentro del edificio (ej: "Hall de entrada", "Aula 203"). Si la ubicación abarca todo el edificio, lo dejás en blanco.
  - **Descripción:** Texto libre para aclarar dónde está exactamente

- **Editar** los datos de una ubicación

- **Activar/desactivar** — Una ubicación desactivada no desactiva las pantallas que tiene, pero puede usarse como filtro en otros módulos

- **Eliminar** una ubicación — Solo podés hacerlo si no tiene ninguna pantalla asociada. Si tiene pantallas, primero tenés que reasignarlas o eliminarlas.

**Por qué importa para la programación:** Cuando creás una regla de programación, podés elegir "Ubicación" como destino. Así, una sola regla aplica automáticamente a todas las pantallas de ese edificio o sala — no necesitás crear una regla por pantalla.

---

### 7. Infraestructura — Pantallas — `/spui/pantallas`

**Para qué sirve:** Representan los monitores o displays físicos instalados en el campus. Cada pantalla necesita una Raspberry Pi (nodo) para funcionar.

**Lo que podés hacer acá:**

- **Crear una pantalla** — Completás:
  - **Nombre:** Nombre descriptivo (ej: "Pantalla Hall Entrada Edificio Central")
  - **Ubicación:** La seleccionás del listado de ubicaciones creadas
  - **Dirección MAC:** La dirección física de red de la Raspberry Pi (formato AA:BB:CC:DD:EE:FF). Debe ser única en todo el sistema.
  - **IP:** La dirección IP de la Pi en la red (opcional pero útil para diagnósticos)
  - **Resolución:** Ancho y alto en píxeles (ej: 1920 × 1080)
  - **Estado inicial:** Activo / Inactivo / Mantenimiento

- **Editar** sus datos

- **Cambiar estado** — Los estados posibles son:
  - *Activo:* Funcionando normalmente
  - *Inactivo:* Apagada o fuera de servicio
  - *Mantenimiento:* En reparación o actualización
  El toggle en la tabla cicla entre Activo e Inactivo. El estado Mantenimiento se puede asignar al editar.

- **Eliminar** una pantalla — Solo si no tiene un nodo (Raspberry Pi) asignado. Si tiene nodo, primero eliminás el nodo.

**Relación 1 a 1 con nodos:** Cada pantalla es controlada por exactamente una Raspberry Pi. No puede haber una pantalla con dos nodos ni un nodo controlando dos pantallas.

---

### 8. Infraestructura — Nodos — `/spui/nodos`

**Para qué sirve:** Representan las Raspberry Pi que físicamente reproducen el contenido. Es la conexión entre el CMS y el hardware.

**Lo que podés hacer acá:**

- Ver todos los nodos con: hostname, pantalla que controlan, ubicación, versión de firmware, estado de conexión y cuándo fue el último heartbeat.

- **Registrar un nodo nuevo** — Este proceso es crítico porque genera la API key:
  1. Seleccionás qué pantalla va a controlar esta Pi (el selector solo muestra pantallas sin nodo asignado)
  2. Ingresás el hostname de la Pi (ej: `spui-pi-01`)
  3. Opcionalmente, la versión de firmware del cliente Python
  4. Al guardar, el sistema genera automáticamente una **API key aleatoria de 64 caracteres**
  5. El sistema te redirige a una pantalla especial que muestra la clave **una sola vez** con un botón de copiar
  6. Tenés que copiar esa clave y configurarla en la Pi **antes de cerrar esa pantalla** — no hay forma de recuperarla después
  7. El sistema guarda únicamente el hash SHA-256 de la key, nunca la key en texto claro

- **Editar** un nodo — Podés cambiar la pantalla asignada, el hostname o la versión de firmware

- **Regenerar la API key** — Si perdiste la clave o si sospechás que fue comprometida, podés generar una nueva. Esto invalida la clave anterior de inmediato. La nueva clave se muestra una sola vez en la misma pantalla especial. Después de regenerarla, tenés que actualizar el archivo de configuración de la Pi.

- **Eliminar** un nodo — Al eliminarlo, la pantalla asociada queda "libre" y puede asignarse a otra Pi.

**Configurar la Pi después de registrar el nodo:**  
En la Raspberry Pi editás el archivo de entorno (ej: `/etc/spui-agent.env`) con:
```
SPUI_API_KEY=<la clave que copiaste>
SPUI_API_URL=https://intranet.unraf.edu.ar/api/v1
```
El cliente Python usa esa clave para autenticarse en la API y sincronizar contenido.

---

## Parte 2 — Lo que está planificado pero no implementado aún

Estas funcionalidades están en el diseño original del proyecto (casos de uso y modelo de datos), la base de datos tiene las tablas correspondientes, pero el CMS todavía no tiene sección ni controlador para gestionarlas.

---

### A. Programación Energética — Brillo, Encendido y Apagado

**Qué se planeó (CU-11):**  
Para cada pantalla, poder configurar por día de la semana: a qué hora se enciende, a qué hora se apaga y a qué nivel de brillo (0-100%) opera durante ese día.

Ejemplo de uso: Las pantallas del hall podrían encenderse a las 7:30, funcionar al 80% de brillo durante el día y apagarse a las 21:00. Los fines de semana podrían apagarse completamente para ahorrar energía.

**Estado actual:**  
La tabla `programacion_energetica` existe en la base de datos con los campos: pantalla, día de la semana, hora de encendido, hora de apagado y nivel de brillo. Pero no hay sección en el CMS para gestionarla ni lógica en la Pi para ejecutar los horarios.

**Lo que falta:**  
- Sección en el CMS (probablemente dentro de la edición de cada pantalla) para definir los 7 días con horario y brillo
- Incluir el horario energético en la respuesta de la API de sync que consume la Pi
- Lógica en el cliente Python que, a la hora programada, envíe la señal CEC a la pantalla o controle GPIO para encender/apagar y ajuste el brillo

---

### B. Gestión de Códigos QR con estadísticas

**Qué se planeó (CU-10):**  
Una sección de "Códigos QR" donde crear QRs dinámicos que tengan su propio URL destino, fecha de expiración y contador de cuántas veces fueron escaneados.

**Estado actual:**  
Podés crear un *contenido de tipo QR* pegando una URL directamente, y eso genera un QR que se muestra en pantalla. Pero no hay una sección independiente de gestión de QRs, no se cuentan los escaneos y no hay endpoint público que haga la redirección.

**Lo que falta:**  
- Sección `CodigoQr` en el CMS (CRUD de QRs con URL destino, etiqueta, fecha de expiración)
- Endpoint público `GET /qr/{id}/redirect` que incremente el contador `usos_count` y redirija al visitante
- Mostrar estadísticas de escaneos en el CMS

---

### C. API REST para que las Raspberry Pi sincronicen contenido

**Qué se planeó (CU-06, CU-07):**  
Una API REST que las Pi consumen para:
- `GET /api/nodo/sync` — Recibe qué playlist debe reproducir ahora, con los metadatos de cada archivo (nombre, ruta, hash SHA-256, duración)
- `POST /api/nodo/heartbeat` — La Pi avisa que está viva y envía su estado de conexión
- Descarga diferencial: la Pi solo descarga archivos cuyo hash SHA-256 difiera del que ya tiene cacheado

**Estado actual:**  
- La autenticación por API key está implementada (el hash se guarda, el nodo tiene la key)
- El cliente Python en `apps/spui/pi-client/` está escrito con toda la lógica de reproducción offline, cache SQLite, telemetría MQTT y modo fail-safe
- Pero no existe el controlador PHP que responda a las llamadas de la Pi

**Lo que falta:**  
- `ApiNodoController` con los endpoints de sync y heartbeat
- Lógica de resolución de schedules: dado un nodo y la hora actual, ¿qué playlist toca? (considerando programaciones por pantalla, por ubicación, con prioridades)
- Respuesta JSON de sync con lista de archivos + URLs de descarga

---

### D. Historial gráfico de telemetría

**Qué se planeó (CU-12):**  
Vista de detalle de cada nodo con gráficos históricos de temperatura, RAM, latencia y espacio en disco. Alertas automáticas si la temperatura supera 80°C.

**Estado actual:**  
El dashboard muestra el último dato de telemetría de cada nodo (un snapshot). La tabla `telemetria` acumula el historial completo (con política de retención de 90 días), pero no hay visualización de tendencias ni alertas automáticas.

**Lo que falta:**  
- Vista de detalle de nodo (`/spui/nodos/{id}`) con gráficos de Chart.js o similar
- Lógica de alerta automática cuando `temperatura_soc_celsius > 80` en el subscriber MQTT

---

## Parte 3 — Resumen del estado de implementación

| Funcionalidad | Tabla en BD | Sección en CMS | Estado |
|---|:---:|:---:|---|
| Ubicaciones — CRUD | ✅ | ✅ | Completo |
| Pantallas — CRUD + estados | ✅ | ✅ | Completo |
| Nodos — Registro + API key | ✅ | ✅ | Completo |
| Contenidos — 5 tipos + publicar/archivar | ✅ | ✅ | Completo |
| Playlists — Builder drag-and-drop | ✅ | ✅ | Completo |
| Programación de reproducción | ✅ | ✅ | Completo |
| Alertas de emergencia + MQTT push | ✅ | ✅ | Completo |
| Dashboard — telemetría en snapshot | ✅ | ✅ | Completo |
| Programación energética (brillo/encendido) | ✅ | ❌ | Pendiente de implementar |
| Códigos QR con contador de escaneos | ✅ | ❌ | Pendiente de implementar |
| API REST para sync de Pi | ✅ | ❌ | Pendiente de implementar |
| Historial gráfico de telemetría | ✅ | ❌ | Pendiente de implementar |
| Override de duración por ítem de playlist | ✅ | ❌ | BD lista, falta UI en el builder |
| Cliente Python Pi — reproducción offline | — | — | Escrito, pendiente de probar en hardware |
| Systemd autostart en Pi | — | — | Escrito, pendiente de instalar en hardware |
