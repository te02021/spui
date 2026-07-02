# Cronograma — SPUI (Diagrama de Gantt)

## Parámetros del cronograma

| Parámetro | Valor |
|-----------|-------|
| Carga horaria L-V | 4 horas/día (12:00 a 16:00) |
| Carga horaria fin de semana | 1-2 horas (opcional, no crítico) |
| Capacidad semanal base | ~20h/semana (L-V) |
| Inicio Fase 0 (documentación) | 2026-06-30 |
| Inicio Fase 1 (desarrollo) | A confirmar al finalizar Fase 0 |
| Estimación de cierre | **Octubre - Noviembre 2026** |
| Total horas estimadas | ~280h (con buffer 25%) |

> **Nota:** Las fechas de las Fases 1-10 en el diagrama son orientativas basadas en un inicio de desarrollo el **2026-07-07**. Ajustar la sección `section` de cada fase una vez confirmada la fecha de arranque.

---

## Diagrama Gantt

```mermaid
gantt
    title SPUI — Cronograma de Desarrollo
    dateFormat  YYYY-MM-DD
    axisFormat  %d/%m

    section Fase 0 — Documentación
    Investigación tecnológica        :done,    f0a, 2026-06-29, 2026-07-01
    ERD y diseño de BD               :done,    f0b, 2026-07-01, 2026-07-02
    Casos de uso y flujos            :done,    f0c, 2026-07-02, 2026-07-03
    Gantt y arquitectura             :done,    f0d, 2026-07-03, 2026-07-04

    section Fase 1 — Setup Backend (22h ~1 sem)
    Namespace SPUI en composer.json  :done,    f1a, 2026-07-07, 2026-07-08
    EntityManager spui en doctrine   :done,    f1b, 2026-07-08, 2026-07-09
    11 Entidades Doctrine            :done,    f1c, 2026-07-09, 2026-07-11
    Migración inicial BD spui        :done,    f1d, 2026-07-11, 2026-07-12

    section Fase 2 — API REST Core (30h ~1.5 sem)
    CRUD Ubicacion + Pantalla + Nodo :done,    f2a, 2026-07-14, 2026-07-16
    CRUD Contenido + upload archivo  :done,    f2b, 2026-07-16, 2026-07-19
    CRUD Playlist + PlaylistItem     :done,    f2c, 2026-07-19, 2026-07-21
    Endpoint sync nodo               :done,    f2d, 2026-07-21, 2026-07-23
    Endpoint heartbeat + media serve :done,    f2e, 2026-07-23, 2026-07-25

    section Fase 3 — Auth & Hardening (15h ~1 sem)
    API Key hash para nodos          :done,    f3a, 2026-07-28, 2026-07-30
    Rate limiting (symfony/rate-limiter):done, f3b, 2026-07-30, 2026-08-01
    Validaciones + CORS + HTTPS      :done,    f3c, 2026-08-01, 2026-08-02

    section Fase 4 — Módulo Alertas (15h ~1 sem)
    Entidad + API alerta emergencia  :done,    f4a, 2026-08-04, 2026-08-06
    Publicación MQTT en activación   :done,    f4b, 2026-08-06, 2026-08-08
    Mercure SSE para dashboard       :done,    f4c, 2026-08-08, 2026-08-09

    section Fase 5 — Módulo QR (8h ~0.5 sem)
    Generación QR con endroid/qr-code:done,   f5a, 2026-08-11, 2026-08-12
    Endpoint redirect + contador     :done,    f5b, 2026-08-12, 2026-08-14

    section Fase 6 — Cliente Python / Pi (38h ~2 sem)
    Estructura modular del cliente   :done,    f6a, 2026-08-14, 2026-08-17
    Módulo sync REST + caché SQLite  :done,    f6b, 2026-08-17, 2026-08-19
    Player VLC + modo simulación     :done,    f6c, 2026-08-19, 2026-08-22
    Heartbeat + MQTT listener        :done,    f6d, 2026-08-22, 2026-08-26
    Autostart systemd + pruebas Pi   :active,  f6e, 2026-08-26, 2026-08-29

    section Fase 7 — Offline / Fail-Safe (15h ~1 sem)
    SQLite local en Pi               :done,    f7a, 2026-09-01, 2026-09-03
    Detección pérdida de red         :done,    f7b, 2026-09-03, 2026-09-05
    Reconexión automática + re-sync  :done,    f7c, 2026-09-05, 2026-09-06

    section Fase 8 — Telemetría MQTT (15h ~1 sem)
    Instalación broker Mosquitto     :done,    f8a, 2026-09-08, 2026-09-09
    Daemon paho-mqtt en Pi           :done,    f8b, 2026-09-09, 2026-09-11
    Ingest MQTT en CMS + tabla       :done,    f8c, 2026-09-11, 2026-09-13
    Alertas por temperatura alta     :done,    f8d, 2026-09-13, 2026-09-14

    section Fase 9 — Frontend CMS / Twig (30h ~1.5 sem)
    Dashboard estado nodos           :done,    f9a, 2026-09-15, 2026-09-17
    Gestor de contenidos + upload UI :done,    f9b, 2026-09-17, 2026-09-21
    Playlist builder drag-and-drop   :done,    f9c, 2026-09-21, 2026-09-25
    Calendario de programación       :done,    f9d, 2026-09-25, 2026-09-28
    Panel de alertas + energía       :done,    f9e, 2026-09-28, 2026-10-01

    section Fase 10 — Integración & QA (22h ~1 sem)
    Pruebas end-to-end (Pi real)     :         f10a, 2026-10-01, 2026-10-05
    Ajustes de performance           :         f10b, 2026-10-05, 2026-10-08
    Documentación técnica final      :         f10c, 2026-10-08, 2026-10-12
    Entrega del prototipo            :milestone,f10d, 2026-10-12, 0d
```

---

## Resumen de fases

| # | Fase | Inicio | Fin | Horas |
|---|------|--------|-----|-------|
| 0 | Documentación y diseño | 30/06/2026 | 04/07/2026 | 15h |
| 1 | Setup backend | 07/07/2026 | 12/07/2026 | 22h |
| 2 | API REST core | 14/07/2026 | 25/07/2026 | 30h |
| 3 | Auth & hardening | 28/07/2026 | 02/08/2026 | 15h |
| 4 | Módulo alertas | 04/08/2026 | 09/08/2026 | 15h |
| 5 | Módulo QR | 11/08/2026 | 14/08/2026 | 8h |
| 6 | Cliente Python Pi | 14/08/2026 | 29/08/2026 | 38h |
| 7 | Offline / fail-safe | 01/09/2026 | 06/09/2026 | 15h |
| 8 | Telemetría MQTT | 08/09/2026 | 14/09/2026 | 15h |
| 9 | Frontend CMS | 15/09/2026 | 01/10/2026 | 30h |
| 10 | Integración & QA | 01/10/2026 | 12/10/2026 | 22h |
| | **Total** | | | **225h netas** |
| | **Con buffer 25%** | | | **~280h** |

---

## Hitos clave (milestones)

| Hito | Fecha estimada | Descripción |
|------|---------------|-------------|
| M1 | 04/07/2026 | Documentación completa aprobada — inicio de desarrollo |
| M2 | 12/07/2026 | BD creada y migraciones corriendo en local |
| M3 | 25/07/2026 | API REST funcional (testeable con Postman/curl) |
| M4 | 09/08/2026 | Alertas de emergencia funcionando end-to-end vía MQTT |
| M5 | 29/08/2026 | Primera Raspberry Pi reproduciendo contenido desde el CMS |
| M6 | 06/09/2026 | Nodo opera correctamente sin conexión de red (modo offline) |
| M7 | 14/09/2026 | Dashboard de telemetría mostrando datos reales del Pi |
| M8 | **12/10/2026** | **Entrega del prototipo completo** |

---

## Riesgos y contingencias

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|-------------|---------|-----------|
| Demoras por compromisos académicos/laborales | Alta | Medio | Buffer del 25% en el cronograma |
| Incompatibilidad de python-vlc con Pi OS Bookworm | Baja | Alto | Fallback a mpv; probarlo en Fase 6 temprano |
| Configuración de Mercure más compleja de lo esperado | Media | Bajo | La alternativa es long-polling HTTP como fallback |
| Acceso físico limitado a la Raspberry Pi para pruebas | Media | Alto | Usar QEMU o Pi emulada para Fases 6-7; pruebas en Pi real en Fase 10 |
| Cambios de requerimientos del jurado | Baja | Alto | Documentación como evidencia del proceso de diseño |
