# Diagramas de Flujo — SPUI

## Flujo 1 — Sincronización de Contenido (Nodo ↔ CMS)

El ciclo normal de operación de un nodo: pide al CMS qué reproducir, descarga los archivos necesarios y actualiza su cache local.

```mermaid
flowchart TD
    A([Nodo arranca / timer de sync]) --> B{¿Hay conexión\ncon el CMS?}
    B -- NO --> C[Modo Offline\nVer Flujo 4]
    B -- SÍ --> D[GET /api/nodo/sync\nHeader: X-Node-Api-Key]
    D --> E{¿Auth válida?}
    E -- NO --> F[Log: clave inválida\nReintentar en 5 min]
    E -- SÍ --> G[CMS calcula playlist activa\nsegún fecha/hora/prioridad]
    G --> H{¿Hay programación\npara este nodo ahora?}
    H -- NO --> I[CMS responde: reproducir\nplaylist de fallback]
    H -- SÍ --> J[CMS responde: playlist + lista\nde contenidos con hash SHA-256]
    I --> K
    J --> K[Nodo compara hashes\ncon archivos locales]
    K --> L{¿Algún archivo\ncambió o falta?}
    L -- NO --> M[Sin descargas necesarias]
    L -- SÍ --> N[Descarga archivos faltantes\nGET /api/nodo/media/archivo]
    N --> O[Verifica SHA-256\ndel archivo descargado]
    O --> P{¿Hash correcto?}
    P -- NO --> Q[Descartar archivo\nLog: error de integridad]
    P -- SÍ --> R[Guardar en\ncache local]
    Q --> R
    R --> S[Actualiza SQLite local\ncon nueva programación]
    M --> S
    S --> T[POST /api/nodo/heartbeat\nestado: ok / timestamp]
    T --> U([Player usa SQLite\npara reproducir])
```

---

## Flujo 2 — Emisión de Alerta de Emergencia

El flujo más crítico del sistema. Debe completarse en segundos desde que el admin activa la alerta.

```mermaid
flowchart TD
    A([Admin accede al\nMódulo de Alertas]) --> B[Crea alerta:\ntítulo, mensaje, media opcional]
    B --> C[Presiona 'Activar Alerta']
    C --> D[CMS: alerta.activa = true\nalerta.activada_en = NOW]
    D --> E[CMS publica en MQTT\ntopic: spui/alerts\npayload: JSON con datos de alerta]
    D --> F[CMS emite evento\nMercure SSE → Dashboard admin]

    E --> G{¿Broker MQTT\ndisponible?}
    G -- SÍ --> H[Broker reenvía a todos\nlos nodos suscritos\ntopic: spui/alerts]
    G -- NO --> I[CMS registra error MQTT\nLos nodos recibirán alerta\nen próximo sync HTTP]

    H --> J[Nodo recibe mensaje MQTT\nen tiempo real]
    J --> K[Nodo interrumpe reproducción actual]
    K --> L{¿La alerta\ntiene media adjunta?}
    L -- SÍ --> M[Descarga media si no está\nen cache local]
    L -- NO --> N[Muestra pantalla de\nmensaje de texto urgente]
    M --> O[Muestra media con\nmensaje de alerta superpuesto]
    N --> P{¿alerta.expira_en\npasó?}
    O --> P
    P -- NO --> Q[Mantiene alerta en pantalla\nLoop hasta nueva instrucción]
    Q --> P
    P -- SÍ --> R[Nodo desactiva alerta\nvuelve a programación normal]

    F --> S([Dashboard admin muestra\nalerta como activa])
    S --> T{Admin desactiva\nmanualmente?}
    T -- SÍ --> U[CMS: alerta.activa = false\nPublica en MQTT: alerta desactivada]
    U --> R
    T -- NO --> T
```

---

## Flujo 3 — Ciclo de Telemetría MQTT

El nodo envía métricas de hardware cada 60 segundos para monitoreo preventivo.

```mermaid
flowchart TD
    A([Daemon telemetría arranca\nen la Raspberry Pi]) --> B[paho-mqtt: connect\nal broker Mosquitto]
    B --> C{¿Conexión exitosa?}
    C -- NO --> D[Reintentar en 30s\nBuffer métricas localmente]
    D --> B
    C -- SÍ --> E([Loop cada 60 segundos])

    E --> F[Medir temperatura SoC\nvcgencmd measure_temp]
    F --> G[Medir uso RAM\npsutil.virtual_memory]
    G --> H[Medir latencia de red\nping al CMS]
    H --> I[Medir espacio disco libre\npsutil.disk_usage]
    I --> J[Construir JSON payload:\nnodo_id, temp, ram, latencia, disco, timestamp]
    J --> K[Publicar en MQTT\ntopic: spui/telemetria/nodo_id]

    K --> L{¿Publicación exitosa?}
    L -- NO --> M[Buffer el mensaje\nReintentar en próximo ciclo]
    L -- SÍ --> N[CMS suscripto recibe\nel mensaje MQTT]

    M --> E
    N --> O[CMS inserta en tabla\ntelemetria]
    O --> P{¿Temperatura > 80°C?}
    P -- SÍ --> Q[CMS emite alerta\nen dashboard via Mercure]
    P -- NO --> R{¿Sin heartbeat\nhace > 5 min?}
    Q --> R
    R -- SÍ --> S[CMS marca nodo como\ndesconectado en pantalla]
    R -- NO --> E
    S --> E
```

---

## Flujo 4 — Modo Offline / Fail-Safe

El nodo pierde conexión con el CMS. El sistema debe seguir operando sin interrupciones visibles.

```mermaid
flowchart TD
    A([Nodo intenta sync\ncon el CMS]) --> B{¿CMS responde\ndentro del timeout?}
    B -- SÍ --> C([Flujo 1 normal])
    B -- NO --> D[Nodo detecta modo offline\nRegistra timestamp de pérdida de red]
    D --> E{¿SQLite local\ntiene contenido?}
    E -- SÍ --> F[Player continúa reproduciendo\ndesde cache SQLite]
    E -- NO --> G[Mostrar pantalla de stand-by\ncon logo institucional UNRaf]

    F --> H([Loop de reproducción\nnormal desde cache local])
    H --> I{¿Pasaron 30 segundos?}
    I -- NO --> H
    I -- SÍ --> J[Intento de reconexión\nGET /api/nodo/ping]
    J --> K{¿CMS responde?}
    K -- NO --> H
    K -- SÍ --> L[CMS disponible\nSync inmediato]
    L --> M[Actualiza SQLite\ncon cambios acumulados]
    M --> N{¿Hay alerta\nactiva en el CMS?}
    N -- SÍ --> O[Mostrar alerta\nVer Flujo 2]
    N -- NO --> C

    G --> P{¿Pasaron 30 segundos?}
    P -- NO --> G
    P -- SÍ --> J
```

---

## Flujo 5 — Determinación de Playlist Activa (Lógica del CMS)

Algoritmo que el CMS ejecuta para responder a cada solicitud de sync de un nodo.

```mermaid
flowchart TD
    A([Nodo solicita sync\n¿qué reproducir ahora?]) --> B[CMS consulta tabla programacion\nfiltro: pantalla_id = este_nodo\nO ubicacion_id = ubicacion de este nodo]
    B --> C[Filtra por:\n- activo = true\n- fecha_inicio <= HOY <= fecha_fin\n- hora_inicio <= AHORA < hora_fin\n- dias_semana tiene bit del día actual]
    C --> D{¿Existen reglas\nque apliquen?}
    D -- NO --> E[Retornar playlist de fallback\no lista vacía si no hay fallback]
    D -- SÍ --> F{¿Hay alerta\nemergencia activa?}
    F -- SÍ --> G[Override total:\nretornar datos de la alerta\nignora toda programación regular]
    F -- NO --> H[De las reglas que aplican\nordenar por prioridad DESC]
    H --> I[Tomar la regla de mayor prioridad]
    I --> J{¿Empate de prioridad?}
    J -- SÍ --> K[Tomar la más específica:\npantalla_id > ubicacion_id]
    J -- NO --> L
    K --> L[Retornar la playlist\nde la regla seleccionada]
    L --> M[Incluir en la respuesta:\n- items de playlist con orden y duración\n- hash SHA-256 de cada archivo\n- horario energético del nodo\n- timestamp de próximo sync recomendado]
    M --> N([Respuesta JSON al nodo])
    E --> N
    G --> N
```
