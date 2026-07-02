# Arquitectura del Sistema — SPUI

## Visión general

El sistema SPUI sigue una arquitectura distribuida cliente-servidor con tres capas principales:
1. **CMS central** (servidor en la nube / LAMP) — gestión y distribución de contenidos
2. **Broker de mensajería** (MQTT) — telemetría y alertas en tiempo real
3. **Nodos cliente** (Raspberry Pi) — reproducción y monitoreo en campo

---

## Diagrama de Componentes (C4 — Nivel 2: Containers)

```mermaid
graph TB
    subgraph Internet["☁️ Servidor UNRaf (LAMP / Linux)"]
        subgraph CMS["🌐 CMS SPUI (Symfony 7.4)"]
            API["API REST\n/api/*"]
            ADMIN["Panel Admin\nTwig + AssetMapper"]
            SYNC["Servicio Sync\nScheduler"]
            MQTT_INGEST["Subscriber MQTT\npaho-mqtt Python\no extensión PHP"]
            MERCURE_HUB["Mercure Hub\nSSE / WebSocket"]
        end
        subgraph DB["🗄️ Base de Datos"]
            MYSQL[("MySQL 8\nBD: spui\n11 tablas")]
        end
        subgraph STORAGE["📁 Storage"]
            FILES["Filesystem\npublic/uploads/spui/\nimágenes, videos"]
        end
        subgraph MOSQUITTO["📡 Broker MQTT"]
            BROKER["Mosquitto 2.x\nPort 1883 / 8883 TLS\ntopics: spui/telemetria/#\nspui/alerts"]
        end
    end

    subgraph CAMPUS["🏫 Campus UNRaf (LAN / WiFi)"]
        subgraph PI1["🖥️ Nodo 1 (Raspberry Pi 4)"]
            PLAYER1["Player Python\npython-vlc"]
            SYNC1["Sync Daemon\nREST Client"]
            TELEM1["Telemetría\npaho-mqtt"]
            SQLITE1[("SQLite\ncache local")]
        end
        subgraph PI2["🖥️ Nodo 2 (Raspberry Pi 4)"]
            PLAYER2["Player Python\npython-vlc"]
            SYNC2["Sync Daemon\nREST Client"]
            TELEM2["Telemetría\npaho-mqtt"]
            SQLITE2[("SQLite\ncache local")]
        end
        subgraph PIN["🖥️ Nodo N ..."]
            PLAYERN["..."]
        end
    end

    subgraph USERS["👥 Usuarios"]
        ADMINUSER["👤 Administrador\nnavegador web"]
        STUDENT["🎓 Estudiante\ncelular"]
    end

    ADMINUSER -->|"HTTPS"| ADMIN
    ADMINUSER -->|"HTTPS SSE"| MERCURE_HUB

    ADMIN --> API
    API --> MYSQL
    API --> FILES
    SYNC --> MYSQL

    MQTT_INGEST -->|"subscribe"| BROKER
    MQTT_INGEST --> MYSQL

    API -->|"publish alert"| BROKER
    MERCURE_HUB -->|"push update"| ADMINUSER

    SYNC1 -->|"HTTPS GET /api/nodo/sync"| API
    SYNC1 -->|"HTTPS GET /api/nodo/media/*"| FILES
    SYNC1 <-->|"R/W"| SQLITE1
    PLAYER1 -->|"R"| SQLITE1
    TELEM1 -->|"MQTT publish\nspui/telemetria/1"| BROKER
    BROKER -->|"MQTT subscribe\nspui/alerts"| TELEM1
    TELEM1 -.->|"Alerta recibida"| PLAYER1

    SYNC2 -->|"HTTPS GET /api/nodo/sync"| API
    SYNC2 -->|"HTTPS GET /api/nodo/media/*"| FILES
    SYNC2 <-->|"R/W"| SQLITE2
    PLAYER2 -->|"R"| SQLITE2
    TELEM2 -->|"MQTT publish\nspui/telemetria/2"| BROKER
    BROKER -->|"MQTT subscribe\nspui/alerts"| TELEM2

    STUDENT -->|"HTTPS escanea QR"| API
```

---

## Diagrama de Despliegue

```mermaid
graph LR
    subgraph SERVER["Servidor Linux (LAMP)"]
        APACHE["Apache 2.4\nmod_php / php-fpm\nPort 443 HTTPS"]
        PHP["PHP 8.2\nSymfony 7.4"]
        MYSQLDB[("MySQL 8.0\nBD: Intranet\nBD: spui")]
        MOSQUITTOSVR["Mosquitto\nPort 1883 interno\nPort 8883 TLS externo"]
        MERCURESVR["Mercure Hub\nPort 3000"]
        DISK["Disco: /var/www/\npublic/uploads/spui/"]
    end

    subgraph RASPBERRY["Raspberry Pi 4 (cada nodo)"]
        OS["Raspberry Pi OS\nLite 64-bit Bookworm"]
        PYTHON["Python 3.11\nDaemon systemd"]
        VLC["VLC 3.x\nFullscreen HDMI"]
        SQLITEDB[("SQLite\n/var/spui/cache.db")]
        LOCALFILES["Archivos media\n/var/spui/media/"]
    end

    subgraph DISPLAY["Display"]
        MONITOR["Monitor / TV\nHDMI 1080p"]
    end

    APACHE --> PHP
    PHP --> MYSQLDB
    PHP --> DISK
    MOSQUITTOSVR <-->|"MQTT"| PYTHON
    MERCURESVR <-->|"SSE"| APACHE

    PYTHON --> SQLITEDB
    PYTHON --> LOCALFILES
    PYTHON --> VLC
    VLC --> MONITOR
    PYTHON -->|"HTTPS sync"| APACHE
```

---

## Protocolo de comunicación por flujo

| Flujo | Protocolo | Dirección | Frecuencia |
|-------|-----------|-----------|-----------|
| Sync de contenido | HTTPS REST (JSON) | Pi → CMS | Cada 5 min |
| Descarga de media | HTTPS (stream binario) | Pi → CMS | Solo cuando hay cambios |
| Heartbeat | HTTPS REST | Pi → CMS | Cada 5 min (con el sync) |
| Telemetría | MQTT (QoS 1) | Pi → Broker → CMS | Cada 60 seg |
| Alerta de emergencia | MQTT (QoS 2) | CMS → Broker → Pi | Inmediato (push) |
| Dashboard tiempo real | Mercure SSE | CMS → Browser | Reactivo a eventos |
| QR redirect | HTTPS | Celular → CMS | Por escaneo |

---

## Estructura de tópicos MQTT

```
spui/
├── telemetria/
│   ├── {nodo_id}          ← Métricas del nodo (publicado por el Pi)
├── alerts                  ← Alertas de emergencia (publicado por CMS)
└── control/
    └── {nodo_id}/
        ├── power           ← Comando encendido/apagado pantalla
        └── brightness      ← Comando nivel de brillo
```

---

## Seguridad por capa

| Capa | Mecanismo |
|------|-----------|
| HTTPS (CMS ↔ Pi) | TLS 1.3, certificado del servidor |
| Auth de nodo | Header `X-Node-Api-Key` con hash SHA-256 en BD |
| Auth de admin | Symfony Security (sesión, roles) — sistema Shared existente |
| Integridad de media | SHA-256 del archivo verificado por el Pi al descargar |
| MQTT | Autenticación por usuario/contraseña (Mosquitto ACL); TLS en port 8883 para producción |
| Rate limiting | `symfony/rate-limiter` en endpoints de API públicos |

---

## Estructura de directorios del proyecto (objetivo final)

```
apps/spui/
├── docs/                          ← Esta carpeta de documentación
│   ├── 00_investigacion_tecnologias.md
│   ├── 01_mer_base_datos.md
│   ├── 02_casos_de_uso.md
│   ├── 03_diagramas_flujo.md
│   ├── 04_gantt.md
│   └── 05_arquitectura_sistema.md
├── migrations/                    ← Migraciones Doctrine del EntityManager SPUI
│   └── Version202607XXXXXXXX.php
├── src/
│   ├── Controller/
│   │   ├── Api/                   ← Endpoints REST para nodos
│   │   │   ├── NodoSyncController.php
│   │   │   ├── AlertaController.php
│   │   │   └── QrController.php
│   │   └── Admin/                 ← Panel CMS (Twig)
│   │       ├── DashboardController.php
│   │       ├── ContenidoController.php
│   │       ├── PlaylistController.php
│   │       └── ProgramacionController.php
│   ├── Entity/
│   │   ├── Ubicacion.php
│   │   ├── Pantalla.php
│   │   ├── Nodo.php
│   │   ├── Contenido.php
│   │   ├── Playlist.php
│   │   ├── PlaylistItem.php
│   │   ├── Programacion.php
│   │   ├── AlertaEmergencia.php
│   │   ├── CodigoQr.php
│   │   ├── Telemetria.php
│   │   └── ProgramacionEnergetica.php
│   ├── Repository/
│   │   └── (un repo por entidad)
│   └── Service/
│       ├── StorageService.php      ← Abstracción de almacenamiento de archivos
│       ├── SchedulerService.php    ← Lógica de determinación de playlist activa
│       ├── MqttPublisherService.php
│       └── NodeAuthService.php     ← Validación de API keys de nodos
└── client/                        ← Código Python para Raspberry Pi
    ├── main.py
    ├── player.py                  ← python-vlc
    ├── sync_daemon.py             ← REST client
    ├── telemetry_daemon.py        ← paho-mqtt
    ├── offline_cache.py           ← SQLite
    └── requirements.txt
```
