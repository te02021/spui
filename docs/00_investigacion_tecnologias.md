# Investigación Tecnológica — SPUI

## 1. Broker MQTT

### Opciones evaluadas

| Opción | Pros | Contras |
|--------|------|---------|
| **Eclipse Mosquitto** | Liviano, open source, madura, fácil de instalar en Linux, amplia documentación | Sin dashboard web nativo |
| **EMQX** | Dashboard web integrado, clustering, métricas avanzadas | Más pesado, más complejo de operar |
| **HiveMQ** | Robusto, muy usado en industria | Versión community con limitaciones, orientado a enterprise |

### ✅ Decisión: Eclipse Mosquitto 2.x

- Instalación: `sudo apt install mosquitto mosquitto-clients`
- Suficiente para 10-20 nodos en el campus de la UNRaf
- Sin overhead operacional innecesario
- Configuración básica de auth por archivo de contraseñas (suficiente para prototipo)
- En producción: habilitar TLS y autenticación por certificado de cliente

---

## 2. Player multimedia en Raspberry Pi

### Opciones evaluadas

| Opción | Pros | Contras |
|--------|------|---------|
| **python-vlc** | Soporta todos los formatos (MP4, JPG, PNG, GIF, texto overlay), API simple, bindings oficiales | Requiere VLC instalado en el SO |
| **pygame** | Control total del rendering, liviano para imágenes/texto | Setup complejo para video H.264, no soporta todos los formatos |
| **omxplayer** | Históricamente popular en Pi | **OBSOLETO** en Raspberry Pi OS Bookworm — descartar |
| **mpv** | Liviano, eficiente, CLI-friendly | Bindings Python menos maduros |

### ✅ Decisión: python-vlc

- Instalación: `sudo apt install vlc` + `pip install python-vlc`
- Soporte nativo: MP4 (H.264/H.265), JPG, PNG, texto renderizado via overlay
- API de control: `play()`, `pause()`, `stop()`, set media, fullscreen
- Permite integrar QR overlay sobre el contenido

---

## 3. Almacenamiento de media (servidor CMS)

### Opciones evaluadas

| Opción | Pros | Contras |
|--------|------|---------|
| **Filesystem local** (`public/uploads/spui/`) | Sin costo, sin dependencias externas, simple | No escala horizontalmente, requiere backup manual |
| **Amazon S3** (SDK ya instalado en el proyecto) | Escalable, CDN, redundancia automática, URLs firmadas | Costo mensual, dependencia externa, complejidad |
| **MinIO** (S3-compatible self-hosted) | Gratuito, self-hosted, compatible con AWS SDK | Requiere servidor adicional, operacional más complejo |

### ✅ Decisión: Filesystem local (con abstracción para migración futura)

- Para prototipo universitario: filesystem es suficiente y sin costo
- Se implementa un `StorageService` con interfaz que abstrae el origen del archivo
- La columna `ruta_archivo` en `contenido` almacena rutas relativas: `uploads/spui/media/<filename>`
- El CMS sirve los archivos en `GET /spui/media/{filename}` con auth de nodo
- Los Pi descargan vía HTTP + verifican SHA-256 del archivo antes de cachear
- **Migración futura a S3:** solo requiere cambiar la implementación de `StorageService`

---

## 4. Comunicación en tiempo real — Alertas de Emergencia

### Opciones evaluadas

| Opción | Pros | Contras |
|--------|------|---------|
| **Mercure** | Integración nativa Symfony, protocolo SSE estándar, hub open source | Hub separado a configurar, depende de proceso externo |
| **WebSockets raw** (Ratchet/ReactPHP) | Control total | Servidor WS adicional, más complejo |
| **Polling HTTP** (nodo consulta cada N seg) | Simplísimo de implementar | Latencia inaceptable para emergencias (10-30s de retraso posible) |
| **MQTT** (mismo broker de telemetría) | Reutiliza infraestructura existente, push inmediato | Requiere que el Pi tenga cliente MQTT activo (ya lo tiene) |

### ✅ Decisión: MQTT para alertas + Mercure para UI del CMS

- **Pi → alerta:** el broker MQTT publica en topic `spui/alerts` → el Pi ya tiene `paho-mqtt` activo → latencia mínima, sin conexión HTTP adicional
- **CMS UI (browser admin) → updates en tiempo real:** Mercure SSE para que el dashboard se actualice sin recargar
- Esta combinación evita configurar dos mecanismos distintos en el Pi y aprovecha lo que ya está

---

## 5. Hardware — Raspberry Pi

### Opciones evaluadas

| Modelo | Pros | Contras |
|--------|------|---------|
| **Raspberry Pi 4 Model B (4GB)** | Maduro, amplia documentación, estable en kiosk, cámara de soporte | PCIe Gen 2 (vs 3 en Pi 5) |
| **Raspberry Pi 5** | Más veloz, PCIe Gen 3, mejor rendimiento de video | Más reciente = menos tiempo en producción en kiosk, diferente ecosystem de HAT, más caliente |

### ✅ Decisión: Raspberry Pi 4 Model B — 4GB RAM

- OS: **Raspberry Pi OS Lite 64-bit (Bookworm)** — sin entorno gráfico, arranque rápido
- Pantalla: HDMI → TV/monitor universitario
- Autostart del player: systemd unit o crontab `@reboot`
- Control de encendido/apagado de pantalla: via CEC (`cec-client`) o GPIO + relay
- Temperatura normal bajo carga: 50-60°C (umbral de alerta a 80°C)

---

## 6. Base de datos local en Raspberry Pi (Cache Offline)

### Opciones evaluadas

| Opción | Pros | Contras |
|--------|------|---------|
| **SQLite** | Sin servidor, file-based, Python `sqlite3` built-in, queries SQL reales | Acceso concurrente limitado (no importa: un solo proceso escribe) |
| **JSON files** | Aún más simple | Sin queries, difícil mantener consistencia |
| **TinyDB** (Python) | API Python pura | No SQL estándar, menos flexible |

### ✅ Decisión: SQLite

- `sqlite3` viene en Python stdlib — sin dependencias adicionales
- Schema local espeja las tablas necesarias: `contenido`, `playlist`, `playlist_item`, `programacion`
- El sync daemon compara hash SHA-256 para evitar re-descargar archivos sin cambios
- Si hay red: sync cada X minutos. Sin red: reproduce desde SQLite local indefinidamente

---

## 7. Autenticación de nodos Raspberry Pi

### Opciones evaluadas

| Opción | Pros | Contras |
|--------|------|---------|
| **API Key estática (hash SHA-256)** | Simple, bajo overhead, sin expiración, idóneo para IoT | Si se compromete, renovación manual |
| **JWT (lexik/jwt-authentication-bundle)** | Estándar web, tokens con expiración | Refresh tokens = complejidad, overhead para IoT |
| **mTLS (certificado de cliente)** | Muy seguro, validado a nivel TLS | Complejo de gestionar certificados en Pi |

### ✅ Decisión: API Key con hash SHA-256

- El CMS genera una API key aleatoria (32 bytes, hex) al registrar el nodo
- El CMS almacena **solo** `hash_sha256(api_key)` — nunca la key en claro
- La Pi la envía en cada request: `X-Node-Api-Key: <raw_key>`
- El CMS hashea la key recibida y compara con la BD
- Sin expiración (renovación manual por admin si se compromete)
- En producción: combinar con HTTPS para que la key viaje cifrada

---

## Stack tecnológico definitivo

| Componente | Tecnología | Versión / Notas |
|------------|-----------|------------------|
| Backend / CMS | PHP + Symfony | 7.4 |
| ORM | Doctrine ORM | 2.9+ |
| BD principal | MySQL | 8.0+ |
| Auth CMS (administradores) | Symfony Security + `Shared\User` | existente en el monorepo |
| Auth nodos (Raspberry Pi) | API Key hash SHA-256 | header `X-Node-Api-Key` |
| Alertas push a Pi | MQTT via Mosquitto | topic `spui/alerts` |
| Alertas push a browser (CMS UI) | Symfony Mercure Bundle | SSE |
| QR codes | `endroid/qr-code` | 6.x |
| Storage media | Filesystem local `public/uploads/spui/` | migrable a S3 via `StorageService` |
| Broker MQTT | Eclipse Mosquitto | 2.x |
| OS Raspberry Pi | Raspberry Pi OS Lite 64-bit | Bookworm |
| Player Pi | Python + `python-vlc` | Python 3.11+ / VLC 3.x |
| Telemetría Pi | `paho-mqtt` (Python) | 2.x |
| Cache offline Pi | SQLite | `sqlite3` stdlib |
| Hardware | Raspberry Pi 4 Model B | 4GB RAM |
| Rate limiting API | `symfony/rate-limiter` | — |
