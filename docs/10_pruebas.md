# 10 — Protocolo y registro de pruebas (CP01–CP13)

Este documento manda sobre **cómo se ejecuta cada caso de prueba y dónde queda su resultado**. Cada caso tiene su criterio de aceptación, su procedimiento paso a paso, la evidencia que lo prueba (qué línea de log, qué pantalla del CMS) y una planilla de registro que se completa en cada corrida. Un caso cuenta como ejecutado cuando su planilla tiene las repeticiones indicadas y un veredicto, no antes.

Las pruebas de carga (CP11, CP12) las corre el simulador de `tools/simulador/` y dejan su informe en `pruebas/`. Acá sólo se registra el resumen y el enlace.

---

## 1. Estado

| Caso | Criterio | Necesita ver la pantalla | Estado |
|---|---|---|---|
| CP01 | Publicación y sincronización en < 60 s | No | ✅ 5 de 10: ≤ 2,5 s (28/10/2026) |
| CP02 | Segmentación por pantalla, ubicación y edificio | No (necesita 2 reproductores) | Sin ejecutar |
| CP03 | Orden de prioridad | No | ✅ 1 de 2 vueltas, 6 pasos correctos (28/10/2026) |
| CP04 | Alerta global con sonido, < 2 s (p95) | La latencia no; el sonido sí | ✅ 5 de 10: ≤ 0,5 s, sonó en todas (28/10/2026) |
| CP05 | Corte de red sin interrupción | No (se ve en el log) | ✅ Cumple (3 de 3, 25 y 27/10/2026) |
| CP06 | Resincronización tras el corte, < 60 s | No | ✅ Cumple (3 de 3: 0,8–38,6 s) |
| CP07 | Descarga interrumpida o archivo alterado no entra a caché | No | ✅ Cumple (descarga 2 de 2, alterado 3 de 3) |
| CP08 | Desconexión abrupta visible en el panel | No | ✅ Cumple (6 de 6: 113–155 s) |
| CP09 | Solicitud o mensaje sin credenciales, rechazado | No | ✅ Cumple desde la Pi (25/10/2026, 5 repeticiones) |
| CP10 | Encendido y apagado programado | **Sí** | ✅ Monitor Philips: apagado, encendido y brillo real al 30 % (28/10/2026); falta el Smart TV |
| CP11 | Carga con 50 simulados: error < 1 %, API < 500 ms (p95) | No | ✅ Cumple (13/10/2026, entorno de desarrollo) |
| CP12 | Alerta simultánea a 100 simulados, < 5 s | No | ✅ Cumple (13/10 y 15/10/2026, entorno de desarrollo) |
| CP13 | Usabilidad SUS ≥ 70 | — (participantes) | Sin ejecutar |

---

## 2. Reglas comunes

### 2.1. Condiciones de cada corrida

Cada planilla registra:

- **Fecha y hora.**
- **Versión en la Pi:** la que muestra el log al arrancar (`SPUI Client iniciado (versión <commit> <fecha>)`). Sale del `/opt/spui/VERSION` que escribe `deploy.ps1`.
- **Salida de la Pi:** monitor o TV conectado, o **sin pantalla**.
- **Red:** la misma LAN que el CMS o el campus.

**Sin pantalla conectada** el reproductor puede entrar en modo simulación (`Modo SIMULACIÓN (sin salida de pantalla)` en el log). En ese modo registra con `[SIM] imagen: …`, `[SIM] video: …` lo que mostraría, sin dibujarlo. Las decisiones (qué se muestra, cuándo llega cada mensaje, qué pasa sin red) son las mismas, así que los casos marcados "No" en §1 valen igual. Hay que anotarlo en la planilla como "verificado por log, sin pantalla".

### 2.2. Relojes

Los casos que miden latencia comparan la hora del log del CMS con la hora del log de la Pi. Antes de medir, los dos relojes tienen que estar sincronizados:

```bash
# En la Pi: "System clock synchronized: yes"
timedatectl
```

```powershell
# En la PC: "Origen" distinto de "Local CMOS Clock" y desviación chica
w32tm /query /status
```

Si alguno no está sincronizado, la latencia medida no vale: se anota y se mide igual sólo como referencia.

### 2.3. Dónde mirar

| Qué | Dónde |
|---|---|
| Log de la Pi, con milisegundos | `journalctl -u spui -o short-precise --since "HH:MM"` (en vivo: `-f`). Cada línea del cliente trae además su propia hora con milisegundos (`2026-10-19 10:00:00,123`) |
| Log del CMS | `var/log/spui/dev.log` del monorepo (en producción, `prod.log`) |
| Log del daemon MQTT | La consola o el log del servicio `spui-telemetria` (`spui:mqtt:subscribe`) |
| Estado en el panel | CMS → Reproductores (columna de conexión) y Dashboard |

### 2.4. Repeticiones y métricas

- Latencias: **10 repeticiones**. Con 10 muestras, el p95 es prácticamente el máximo, así que se informa el **máximo** como valor conservador, además de la mediana.
- Casos de sí/no: las repeticiones que indica cada caso. Una sola falla alcanza para que no cumpla.
- Límite de sync: el CMS acepta **4 syncs por minuto por reproductor** (`config/packages/rate_limiter.yaml`). Las repeticiones que disparan un sync van separadas al menos **35 s**. Si no, la medición choca con un 429 en vez de medir el sistema.

### 2.5. Scripts de la Pi

Las pruebas que se hacen en la Pi tienen un script en `pi-client/pruebas/`. `deploy.ps1` los copia a `/opt/spui/pruebas/`. Imprimen la planilla y el veredicto listos para pegar en §4.

| Caso | Comando, en la Pi |
|---|---|
| CP05 y CP06 | `bash /opt/spui/pruebas/cp05_06.sh [segundos]` |
| CP07 | `bash /opt/spui/pruebas/cp07.sh alterado` · `bash /opt/spui/pruebas/cp07.sh descarga` |
| CP08 | `bash /opt/spui/pruebas/cp08.sh colgado` · `bash /opt/spui/pruebas/cp08.sh red` |
| CP09 | `sudo /opt/spui/venv/bin/python3 /opt/spui/pruebas/cp09.py` |

Se corren con `bash` porque `scp` desde Windows no conserva el permiso de ejecución.

---

## 3. Casos

### CP01 — Publicación y sincronización (< 60 s)

**Qué prueba.** Un cambio guardado en el CMS llega al reproductor en menos de 60 s. El camino normal es el aviso `sync_ahora` por MQTT seguido de un sync HTTP. El camino de respaldo es el sync periódico.

**Procedimiento.**
1. Dejar el log de la Pi en vivo: `journalctl -u spui -f -o short-precise`.
2. En el CMS, editar un contenido de la playlist que muestra la pantalla de la Pi (por ejemplo, el texto de un contenido de tipo texto) y **Guardar**. Anotar la hora.
3. Esperar en el log de la Pi `MQTT: sync inmediato pedido (motivo=…)` y luego `Sync OK (…)`.
4. Comprobar en el log que se reproduce la versión nueva: `Reproduciendo [texto] "<título>"` o `[SIM] texto: <texto nuevo>`.
5. Repetir 10 veces, con 35 s o más entre una y otra.

**Medida.** Desde `MQTT comando publicado.` en `dev.log` hasta `Sync OK` en la Pi. El aviso se demora a propósito entre 0 y 2 s al azar.

**Variante sin MQTT** (1 repetición, opcional). Detener el listener no es posible sin detener el cliente, así que esta variante se hace cuando corresponda C2: con el broker inaccesible para la Pi, el cambio tiene que llegar en el próximo sync periódico (el intervalo configurado en el reproductor).

| # | Hora Guardar | `MQTT comando publicado` | `Sync OK` en la Pi | Latencia | Contenido nuevo en el log |
|---|---|---|---|---|---|
| 1 | | | | | |

### CP02 — Segmentación por pantalla, ubicación y edificio

**Qué prueba.** Una programación con alcance de pantalla, ubicación o edificio la recibe sólo quien corresponde.

**Requisito.** Dos reproductores en pantallas distintas. Con una sola Pi se puede usar un reproductor del simulador como segundo, pero el simulador no aplica la programación como el player: sirve para comprobar qué devuelve `/sync` a cada uno, no qué se reproduce. **Se deja para cuando haya dos reproductores reales.**

### CP03 — Orden de prioridad

**Qué prueba.** El orden de §4 de `06_funcionamiento_del_sistema.md`: alerta activa → programación vigente de mayor prioridad → playlist de fallback de la pantalla → pantalla de espera.

**Procedimiento.** Cada paso se comprueba en el log de la Pi tras el sync (`Sync OK … programacion=<id> alerta=<id>` y las líneas `Reproduciendo` o `[SIM]`):

1. **Sin reglas ni fallback:** `[FALLBACK] Sin programación — se muestra la pantalla de espera`.
2. **Sólo la playlist de fallback de la pantalla:** se reproduce esa playlist; `programacion=None`.
3. **Una regla vigente A, prioridad 5, con la playlist P1:** `programacion=<A>` y contenidos de P1.
4. **Se suma una regla vigente B, prioridad 10, con P2, en la misma franja:** `programacion=<B>` y contenidos de P2.
5. **Se desactiva B:** vuelve a A.
6. **Se activa una alerta dirigida a la pantalla:** `MQTT: Alerta de emergencia recibida` y `ALERTA: <título>`. Al desactivarla, `Alerta desactivada — volviendo a programación normal` y vuelve a A.

Cada paso es una repetición. Se corre la secuencia completa 2 veces.

| Paso | Configuración | Esperado | Línea del log | ¿Cumple? |
|---|---|---|---|---|
| 1 | Sin reglas ni fallback | Pantalla de espera | | |

### CP04 — Alerta global con sonido (< 2 s, p95)

**Qué prueba.** Desde que se presiona **Activar** en una alerta de alcance "Todas" hasta que la Pi la recibe, menos de 2 s. Además, que suene.

**Procedimiento.**
1. Relojes sincronizados (§2.2). Log de la Pi en vivo.
2. En el CMS, crear una alerta de prueba con alcance Todas, sin vencimiento. **Activar** y anotar la hora.
3. En la Pi: `MQTT: Alerta de emergencia recibida — id=<id>` y luego `ALERTA: <título>`. Sin pantalla aparecen además `[SIM] ALERTA (imagen compuesta)` y `[SIM] sonido de alerta`.
4. **Desactivar.** En la Pi: `MQTT: Alerta desactivada.`
5. Repetir 10 veces.

**Medida.** Desde `MQTT alerta publicada. {"accion":"activada"…}` en `dev.log` hasta `Alerta de emergencia recibida` en la Pi. El tiempo que tarda Activar en responder se anota aparte.

**El sonido** (que salga por el HDMI y se escuche) **necesita la pantalla.** Sin ella sólo se comprueba en el log que se intentó reproducir. Va en una columna aparte, que se completa el día que haya monitor.

| # | `alerta publicada` (CMS) | `Alerta recibida` (Pi) | Latencia | Desactivación recibida | Sonido (oído) |
|---|---|---|---|---|---|
| 1 | | | | | |

### CP05 y CP06 — Corte de red sin interrupción y resincronización (< 60 s)

**Qué prueban.**
- **CP05:** sin red hacia el CMS y el broker, la Pi sigue mostrando lo que tenía en caché, sin errores.
- **CP06:** al volver la red, se resincroniza en menos de 60 s.

**Script:** `bash /opt/spui/pruebas/cp05_06.sh [segundos]` (300 por defecto). Corta, espera, restaura, espera el `Sync OK` y saca la tabla y el veredicto del log.

**Cómo corta la red sin perder el SSH** (`pruebas/red.sh`):
- No apaga la interfaz. Con `nft`, bloquea sólo las conexiones salientes de la Pi hacia las IPs del CMS y del broker, en los puertos 80, 443, 1883 y 8883. Las respuestas del SSH salen del puerto 22 y no se tocan, aunque vengan de la misma PC que el CMS.
- Cada corte programa su propia restauración con un temporizador de systemd. Si el script se corta o se cae la sesión, la red vuelve sola. Un reinicio de la Pi también la borra.
- A mano: `sudo nft delete table inet spui_prueba`.

**Antes de correrlo**, en el CMS:
- **El intervalo de sync del reproductor en 1 minuto.** El cliente recién nota el corte en su próximo ciclo: con 5 minutos podría no llegar a reintentar.
- **Una playlist con dos ítems o más con duración** (imagen y video). Un único contenido permanente no vuelve a registrarse en el log, y el script no tendría evidencia de que siguió reproduciendo.

**Qué mira el script:**
- **CP05 cumple** si después del corte el cliente usó la caché (`Usando caché de sync`), registró reproducciones y no hubo errores (`No hay una copia válida`, `Archivo no cacheado y sin red`, `Traceback`, `Error reproduciendo`).
- **El jitter:** el reintento sin red es cada 30 ± 10 s más ~3 s del chequeo de red, sin importar qué se esté mostrando: desde el 24/10/2026 el sync corre en su propio hilo y no espera a que termine la vuelta de la playlist. Los intervalos tienen que variar entre ~23 y ~43 s; si son todos iguales, el cliente corre código anterior al 15/10.
- **CP06 cumple** con un `Sync OK` en menos de 60 s desde la restauración. Por diseño es como mucho ~43 s: el próximo reintento más el chequeo.

**Repeticiones:** 3.

| # | Corte | Primer uso de caché | Intervalos (s) | Reproducciones / errores | CP05 | CP06 latencia | CP06 |
|---|---|---|---|---|---|---|---|
| 1 | | | | | | | |

### CP07 — Descarga interrumpida o archivo alterado no entra a caché

**Qué prueba.** Un archivo incompleto o modificado nunca se reproduce. El caché vive en `SPUI_CACHE_DIR` de `/etc/spui/spui.env`. `install.sh` lo deja en `/var/cache/spui`, así que los archivos están en `/var/cache/spui/media/`.

**A. Archivo alterado:** `bash /opt/spui/pruebas/cp07.sh alterado`, 3 repeticiones.
1. El script ofrece la lista de archivos del caché. Elegir uno de la playlist que se está mostrando: si no se reproduce, nunca se verifica.
2. Le agrega un byte y espera (hasta 15 min) la línea `Archivo del caché alterado o incompleto (hash distinto): <archivo> — se descarta.` y la descarga nueva.
3. Al final compara el SHA-256 del archivo con el que tenía antes de alterarlo: tienen que coincidir.

**B. Descarga interrumpida:** `bash /opt/spui/pruebas/cp07.sh descarga`, 2 repeticiones.
1. Con el script esperando, subir al CMS un video grande (cientos de MB) y agregarlo a la playlist de la pantalla.
2. Apenas aparece `Descargando media: <archivo>`, el script corta la red y comprueba que el archivo **no** quedó con su nombre final. A lo sumo queda el `.part`, y si la descarga falló el cliente lo borra.
3. Después de 60 s restaura y espera hasta 10 min a que la descarga se complete.
4. Si el archivo terminó de bajar antes del corte, la prueba no vale: el script lo avisa, y hay que repetir con un archivo más grande.

Cubierto además por los tests de `pi-client/tests/`.

| # | Variante | Archivo | Evidencia | ¿Se usó la copia mala? | ¿Cumple? |
|---|---|---|---|---|---|
| 1 | A | | | | |

### CP08 — Desconexión abrupta visible en el panel

**Qué prueba.** Si un reproductor cae sin aviso, el panel lo muestra como desconectado.

**Cuánto se espera.**
- El estado del panel se calcula **sólo por el heartbeat HTTP** (`SPUI_UMBRAL_CONEXION_SEG`, 150 s). El mantenimiento del daemon lo marca cada 60 s.
- Por eso lo esperable es **entre 150 y 210 s desde el último heartbeat**, que fue como mucho 60 s antes de la caída.
- El aviso de caída del broker (LWT) llega mucho antes y queda registrado en el log del daemon (`LWT: caída detectada por el broker`). El panel no lo usa a propósito (`Reproductor.php`, comentario de `marcarLwtOffline()`).

**Script:** `bash /opt/spui/pruebas/cp08.sh colgado|red`, con el panel de Reproductores abierto en la PC. El script toma las horas. Vos refrescás el panel y apretás Enter cuando cambia el estado: primero a Desconectado y, después de restaurar, a Conectado. Una demora de menos de 60 s la marca como sospechosa: con el umbral de 150 s es imposible, así que seguramente se apretó Enter antes de tiempo.

**Dos variantes**, 3 repeticiones cada una:
- **`colgado`:** congela el proceso (`SIGSTOP`) y después lo libera (`SIGCONT`). Las conexiones quedan abiertas, pero sin heartbeats, como un equipo colgado.
- **`red`:** el bloqueo de CP05 con `drop` en lugar de `reject`. Los paquetes se pierden sin respuesta, que es lo que ve el CMS cuando se desenchufa la Pi o se cae el Wi-Fi. El broker se entera por el keepalive (60 s × 1,5).

No se usa matar el proceso: el servicio tiene `Restart=always`, y systemd lo levanta a los 10 s, antes de que el panel llegue a notarlo.

| # | Variante | Caída | LWT (daemon) | Desconectado en el panel | Demora | Volvió a Conectado |
|---|---|---|---|---|---|---|
| 1 | colgado | | | | | |

### CP09 — Solicitud o mensaje sin credenciales, rechazado

**Qué prueba.** Ni la API ni el broker aceptan pedidos sin credenciales válidas, y un reproductor no puede emitir alertas.

**Script:** `sudo /opt/spui/venv/bin/python3 /opt/spui/pruebas/cp09.py [--repeticiones 5]`. Usa la configuración real de la Pi (`/etc/spui/spui.env`) y el mismo `paho-mqtt` del cliente, así que no hace falta instalar `mosquitto-clients`. Se conecta con su propio client id, así que no le corta la sesión al servicio.

| Vector | Esperado |
|---|---|
| Control: API con la clave real | 200 (sirve además para obtener el id del reproductor) |
| API sin clave | 401 |
| API con clave inventada | 401 |
| Media sin clave | 401 |
| MQTT anónimo en el puerto configurado (8883 con TLS) | Rechazado |
| MQTT anónimo en el 1883 sin TLS (mientras siga abierto, hasta C2) | Rechazado |
| MQTT con el usuario del reproductor y una clave incorrecta | Rechazado |
| El reproductor publica en `spui/alertas/emergencia` con sus credenciales reales | No llega: un suscriptor no la recibe |

El último vector manda un mensaje sin `retain` con una marca propia. Si la ACL estuviera mal y pasara, las pantallas lo descartan como "payload desconocido": la prueba no puede disparar una alerta real.

`sin conexión` en el resultado significa que no hubo respuesta: es un problema de red, no un rechazo, y la corrida no vale.

### CP10 — Encendido y apagado programado

**Necesita la pantalla.** El log muestra la decisión (`Fuera de horario: pantalla apagada, sin reproducir.`), pero el caso prueba el efecto: que el monitor o el TV se apague y vuelva. Se ejecuta con el monitor DDC/CI y después con el Smart TV (CEC), según `06` §7.

### CP11 y CP12 — Carga y alerta simultánea

Los corre el simulador (`tools/simulador/simulador.py carga` y `alerta`), y cada corrida deja su informe en `pruebas/`.

| Corrida | Resultado | Informe |
|---|---|---|
| CP11, 13/10/2026, 50 simulados × 10 min | 0 % de error, p95 344 ms ✅ | `pruebas/2026-10-13_0947_carga.md` |
| CP12, 13/10/2026, 100 × 10 rondas | Último reproductor a 3,27 s ✅ | `pruebas/2026-10-13_0959_alerta.md` |
| CP12, 15/10/2026, 100 × 10 rondas, después de las mejoras | Último reproductor a 0,29 s ✅ | `pruebas/2026-10-15_1314_alerta.md` |

**Pendiente:** repetirlas en el servidor del piloto (`pruebas/2026-10-13_analisis.md` §5, recomendación 4).

### CP13 — Usabilidad (SUS ≥ 70)

Cuestionario SUS estándar (10 ítems, escala 1–5) a operadores del CMS después de una tarea guiada: crear un contenido, armar una playlist, programarla y activar y desactivar una alerta. Se define la muestra con el piloto.

---

## 4. Registro de corridas

Las planillas completas de cada corrida se agregan debajo, con la fecha. La más reciente va primero.

### 28/10/2026 — Tanda 3, con el monitor Philips (DDC/CI, sin CEC)

**CP10 y brillo (B2) — ✅ cumplen (1 repetición).**

| Paso | Configuración | Esperado | Resultado |
|---|---|---|---|
| Brillo | Regla de 17:20 a 17:25 con brillo 30 % | Brillo real del monitor al 30 % de la referencia (73) ≈ 22 | `ddcutil getvcp 10` → **22** de 100 ✅ |
| Apagado | Se pasa la hora de apagado (17:25) | El monitor entra en reposo | Se apagó del todo ✅ |
| Encendido | Encendido 2 min después, brillo 100 | El monitor se prende solo a esa hora | Se prendió a la hora ✅ |

Con este monitor el apagado es por corte de señal HDMI (no responde a CEC) y el brillo es el real, por DDC/CI, relativo al que tenía configurado. Falta el Smart TV (CEC y oscurecido por software).

**Aviso de cambio manual (VCP 0x02) — el Philips lo implementa.** Antes de tocar nada, `getvcp 02` → `0x01` ("No new control values"). Después de cambiar el brillo con los botones del monitor → `0x02` ("One or more new control values have been saved"). Es lo que usa `ddc.hubo_cambio_manual()` para tomar un brillo manual como nueva referencia en los momentos clave, sin consultar el monitor periódicamente.

**B6 YouTube — ❌ no se reproduce.** VLC no abre la URL de YouTube: queda en `State.Opening` y el log informa `El contenido no llegó a mostrarse en pantalla`. Además, la pantalla quedaba trabada los 10 s del contenido antes de pasar al siguiente. Esa espera se corrigió el mismo día: un contenido que VLC no logra mostrar se saltea en el acto. Después se probó bajarlo en la Pi con `yt-dlp` (con Deno y ffmpeg): desde la PC anduvo, pero YouTube frenó a la Pi en todos sus modos de acceso (429 y "Sign in to confirm you're not a bot"). **Decisión de Mateo: se saca el tipo YouTube;** un video de YouTube se baja desde la PC y se sube como video (`06` §5).

### 28/10/2026 — Tanda 2, desde el CMS con la Pi y el monitor Philips

**Condiciones:**
- Pi `spui-pi-01` con el monitor conectado, versión `ad78d0b`; `PRUEBA PRINCIPAL` (imagen 10 s, texto 10 s, video 23 s) y sync cada 1 min.
- Las latencias salen de cruzar dos fuentes: la hora de llegada de cada POST al Apache del CMS (`c:\wamp64\logs\access.log`, resolución de 1 s) y la hora del evento en el journal de la Pi (`journalctl -o short-unix`, con microsegundos).
- Como el POST llegó en algún momento dentro de ese segundo, cada valor es una **cota superior**. La PC no sincroniza su reloj, y en 2 de los 15 eventos la Pi registró el evento unas décimas antes del segundo de Apache. Para criterios de 2 y 60 s esa incertidumbre no cambia el veredicto.

**CP01 — ✅ cumple en 5 de 10 repeticiones** (Mateo hizo 5; las 5 se vieron en el monitor).

| # | Guardar (Apache) | Sync OK en la Pi | Latencia | Texto nuevo en pantalla |
|---|---|---|---|---|
| 1 | 16:43:00 | 16:43:01,589 | ≤ 1,6 s | sí |
| 2 | 16:43:30 | 16:43:30,225 | ≤ 0,2 s | sí |
| 3 | 16:43:51 | 16:43:53,528 | ≤ 2,5 s | sí |
| 4 | 16:44:06 | 16:44:08,025 | ≤ 2,0 s | sí |
| 5 | 16:44:25 | 16:44:26,159 | ≤ 1,2 s | sí |

Máximo ≤ 2,5 s, frente a un criterio de 60 s. Incluye los 0 a 2 s de azar a propósito del `sync_ahora`. En cada caso la playlist se cortó y volvió a empezar, y el texto nuevo apareció en su turno, 10 s después.

**CP04 — ✅ cumple en 5 de 10 repeticiones** (Mateo hizo 5; la quinta es el paso 6 de CP03).

| # | Activar (Apache) | Alerta recibida en la Pi | Latencia | Desactivar (Apache) | Baja recibida | Latencia | Sonido |
|---|---|---|---|---|---|---|---|
| 1 | 16:45:51 | 16:45:51,519 | ≤ 0,5 s | 16:46:12 | 16:46:12,617 | ≤ 0,6 s | sí (oído y en el log) |
| 2 | 16:46:25 | 16:46:25,520 | ≤ 0,5 s | 16:47:19 | 16:47:19,181 | ≤ 0,2 s | sí |
| 3 | 16:48:19 | 16:48:19,387 | ≤ 0,4 s | 16:48:49 | 16:48:49,499 | ≤ 0,5 s | sí |
| 4 | 16:48:53 | 16:48:52,975 | ≈ 0 s | 16:49:09 | 16:49:08,815 | ≈ 0 s | sí |
| 5 | 16:57:12 | 16:57:12,085 | ≤ 0,1 s | 16:57:18 | 16:57:18,229 | ≤ 0,2 s | sí |

Máximo ≤ 0,5 s, frente a un criterio de 2 s. La imagen de la alerta aparece entre 0,02 y 0,15 s después de recibirla. El sonido arranca entre 0,3 y 0,9 s después, y se repite al terminar mientras la alerta sigue activa. Mateo la escuchó en todas las activaciones.

**CP03 — ✅ cumple en 1 de 2 vueltas** (Mateo hizo una; los 6 pasos dieron lo esperado).

| Paso | Configuración | Esperado | Visto en el monitor | En el log de la Pi |
|---|---|---|---|---|
| 1 | Sin respaldo ni reglas | Pantalla de espera | Pantalla de espera ⚠ | 16:51:15 |
| 2 | Respaldo `PRUEBA PRINCIPAL` | Playlist principal | Ni bien se guarda | 16:53:23 |
| 3 | Regla A, prioridad 5 | REGLA A | REGLA A | `programacion=7`, 16:55:06 |
| 4 | + Regla B, prioridad 10 | REGLA B | REGLA B | `programacion=8`, 16:56:31 |
| 5 | Se desactiva B | REGLA A | REGLA A | `programacion=7`, 16:56:51 |
| 6 | Alerta y baja | Alerta, después REGLA A | Alerta, después REGLA A | 16:57:12 → 16:57:18 |

Cada cambio llegó a la pantalla en 1 a 2 s.

**Observaciones de Mateo que no hacen fallar el caso, pero hay que corregir:**
- **Pantalla de espera ilegible de lejos.** El texto "UNRaf / Sistema de Pantallas Informativas" se ve chiquito en el centro: la imagen se genera con la fuente de mapa de bits de Pillow, que no escala. **Corregido el 28/10:** "UNRaf" en grande con la fuente de marca y el nombre del sistema debajo (`_fallback_v2.png`).
- **El formulario de reglas de Programación se ve desprolijo,** en el alta y en la edición. **Corregido el 28/10:** etiquetas a la izquierda, los 7 días en una grilla pareja (4 + 3 en celular) y Prioridad, Estado y Repetir alineados. La sangría de Bootstrap (`.form-check` y `.form-switch`) corría los interruptores.

### 25 y 27/10/2026 — Tanda 1, con la Pi y el monitor Philips

**Condiciones:**
- Pi `spui-pi-01` por Wi-Fi en la misma LAN que el CMS, con el monitor conectado. Versión `943b3aa` el 25/10 y `ad78d0b` el 27/10 (sólo cambió el listado de `cp07.sh`; el cliente es el mismo).
- Playlist de respaldo `PRUEBA PRINCIPAL` (imagen 10 s, texto 10 s, video 1080p de 23 s) y sync cada 1 min.
- Los scripts de `pi-client/pruebas/` corrieron en la Pi. Las horas del LWT salen de `C:\ProgramData\spui\telemetria.log`, en la PC.

**CP09 — ✅ cumple (5 repeticiones por vector, desde la Pi).**

| Vector | Esperado | Rep. 1–5 | ¿Cumple? |
|---|---|---|---|
| Control: API con la clave real | 200 | 200 | ✅ |
| API sin clave | 401 | 401 ×5 | ✅ |
| API con clave inválida | 401 | 401 ×5 | ✅ |
| Media sin clave | 401 | 401 ×5 | ✅ |
| MQTT anónimo, puerto 8883 | rechazado | rechazado ×5 | ✅ |
| MQTT anónimo, puerto 1883 sin TLS | rechazado | rechazado ×5 | ✅ |
| MQTT con clave incorrecta | rechazado | rechazado ×5 | ✅ |
| Reproductor publica una alerta | no llegó | no llegó ×5 | ✅ |

**CP07 A — ✅ cumple (3 de 3).**

| # | Archivo | Alterado | Descartado (log) | Vuelto a bajar | Hash final = original |
|---|---|---|---|---|---|
| 1 | `ContenidoPrueba` (`IMG-20241020-155457-…-6add45e291f90.jpg`) | 25/10 20:41:13 | 20:41:41 (+28,3 s) | 20:41:41 | sí |
| 2 | ídem | 27/10 22:10:45 | 22:10:59 (+14,2 s) | 22:10:59 | sí |
| 3 | ídem | 27/10 22:12:30 | 22:12:51 (+21,5 s) | 22:12:51 | sí |

Un primer intento de la repetición 2 (25/10) se cortó a mano. El script listaba todo el caché por fecha, el orden había cambiado y el número elegido ya era una foto que no estaba en la playlist, así que nunca se mostró. Se corrigió el script: ahora lista sólo las imágenes y videos de la playlist en pantalla, con su título. Esa foto quedó alterada en el caché; la Pi la va a descartar y volver a bajar la próxima vez que la muestre, que es justamente lo que prueba el caso.

**CP07 B — ✅ cumple (2 de 2).**

| # | Archivo | Descarga empezó | Corte | Nombre final durante el corte | `.part` durante el corte | Excepciones | Completo tras restaurar |
|---|---|---|---|---|---|---|---|
| 1 | `videoplayback-1-6ade9619d9018.mp4` | 20:57:56 | +1 s | no | no (descarga fallida, borrado) | 0 | 20:59:34 (+30,2 s) |
| 2 | `videoplayback-2-6ade9857281dd.mp4` | 21:02:12 | +1 s | no | no | 0 | 21:03:49 (+30,2 s) |

La descarga cortada nunca quedó con el nombre que usa el player, y se completó en el primer reintento tras volver la red.

**CP08 — ✅ cumple (6 de 6: 3 colgado, 3 red).**

| # | Variante | Caída | LWT (broker) | Desconectado en el panel | Demora | Volvió a Conectado |
|---|---|---|---|---|---|---|
| 1 | colgado | 21:05:24 | 21:06:54 (+90 s) | 21:07:25 | **120,7 s** | +14,9 s |
| 2 | colgado | 21:08:03 | 21:08:56 (+53 s) | 21:10:26 | **142,5 s** | +13,0 s |
| 3 | red | 21:10:57 | 21:11:58 (+61 s) | 21:12:59 | **122,8 s** | +39,2 s |
| 4 | red | 21:13:46 | 21:14:57 (+71 s) | 21:16:20 | **154,6 s** | +19,0 s |
| 5 | colgado | 27/10 22:17:59 | 22:18:31 (+32 s) | 22:19:52 | **112,7 s** | +6,5 s |
| 6 | red | 27/10 22:20:13 | 22:21:24 (+71 s) | 22:22:43 | **150,6 s** | +14,9 s |

Todas dentro de lo esperado por diseño: 150 s desde el último heartbeat, que es como mucho 60 s antes de la caída, más hasta 60 s del mantenimiento. El broker detecta la caída por el keepalive (60 s × 1,5) entre 53 y 90 s antes de que lo muestre el panel, que usa sólo el heartbeat (ver §3, CP08).

**CP05 y CP06 — ✅ cumplen (3 de 3).**

| # | Corte | Primer uso de caché | Intervalos (s) | Reproducciones / errores | CP05 | CP06 latencia | CP06 |
|---|---|---|---|---|---|---|---|
| 1 | 25/10 21:17:22 → 21:22:22 (300,6 s) | +45,0 s | 23,5 · 28,4 · 33,4 · 35,5 · 21,3 · 39,3 · 28,9 · 38,3 | 21 / 0 | ✅ | **33,8 s** | ✅ |
| 2 | 27/10 22:23:07 → 22:28:08 (300,7 s) | +47,4 s | 38,2 · 33,9 · 25,8 · 28,9 · 24,7 · 25,7 · 26,9 · 38,3 | 21 / 0 | ✅ | **38,6 s** | ✅ |
| 3 | 27/10 22:30:41 → 22:35:41 (300,6 s) | +5,8 s | 38,4 · 22,3 · 25,3 · 35,0 · 31,5 · 39,0 · 35,6 · 34,5 | 21 / 0 | ✅ | **0,8 s** | ✅ |

En la repetición 3, el primer uso de la caché (+5,8 s) y la resincronización (0,8 s) salieron cortos porque un reintento cayó justo después del corte y otro justo después de restaurar: es el azar de los reintentos, no otra cosa. Con las tres, CP06 queda entre 0,8 y 38,6 s, siempre por debajo de los ~43 s del diseño. Los intervalos varían entre 21 y 39 s: el jitter funciona, y desde el 24/10 lo hace aunque la playlist incluya un video de 23 s. El primer uso de la caché llegó a los 45 s porque el cliente nota el corte en su próximo sync, que con intervalo de 1 minuto puede tardar hasta 60 s.

### 21/10/2026 — CP09 en banco, con un reproductor simulado desde la PC

**Condiciones:**
- `pruebas/cp09.py` corrido en la PC del CMS con las credenciales de un reproductor `[SIM]`, creado con `tools/simulador/sim.php preparar 1` y borrado al terminar.
- Broker real: 8883 con TLS y verificación de hostname contra `spui-broker.unraf.local`. API real: `http://intranet/api/spui`.
- 3 repeticiones por vector. No reemplaza la corrida desde la Pi: falta la red real entre la Pi y el CMS.

| Vector | Esperado | Rep. 1 | Rep. 2 | Rep. 3 | ¿Cumple? |
|---|---|---|---|---|---|
| Control: API con la clave real | 200 | 200 | — | — | ✅ |
| API sin clave | 401 | 401 | 401 | 401 | ✅ |
| API con clave inválida | 401 | 401 | 401 | 401 | ✅ |
| Media sin clave | 401 | 401 | 401 | 401 | ✅ |
| MQTT anónimo, puerto 8883 | rechazado | rechazado | rechazado | rechazado | ✅ |
| MQTT anónimo, puerto 1883 sin TLS | rechazado | rechazado | rechazado | rechazado | ✅ |
| MQTT con clave incorrecta | rechazado | rechazado | rechazado | rechazado | ✅ |
| Reproductor publica una alerta | no llegó | no llegó | no llegó | no llegó | ✅ |

**Control positivo del último vector** (3 de 3): el CMS, que sí tiene permiso, publicó una marca sin `retain` en `spui/alertas/emergencia`, y el mismo suscriptor del reproductor la recibió las 3 veces. El suscriptor puede leer ese topic, así que "no llegó" en el vector se debe a la ACL de publicación del broker, no a un suscriptor que no recibe nada. Desde esta corrida, el script también comprueba que el broker acepte la suscripción (SUBACK distinto de 128). Si no la acepta, el vector da "no se pudo probar".

**Veredicto en banco:** ✅ cumple. **Pendiente:** la corrida desde la Pi, con 5 repeticiones.

### 19/10/2026 — preparación, con la Pi por SSH y sin pantalla

**Condiciones:**
- Pi `spui-pi-01` por Wi-Fi en la misma LAN que el CMS, sin monitor conectado.
- Reloj de la Pi sincronizado por NTP. La PC no sincroniza (`Local CMOS Clock`), pero su desfase medido es de **+0,03 s, estable** (5 muestras contra `pool.ntp.org` y 5 contra `time.windows.com`). Para los 2 s de CP04 es despreciable, así que no se corrige.

**Resultados:**
- **Tests del cliente en la Pi:** 54 de 54 OK.
- **Puerto MQTT de la Pi:** 8883 con TLS (`MQTT_TLS=true`), host `spui-broker.unraf.local`. La parte de la Pi de C2 ya está hecha; falta cerrar el 1883 en el broker.
- **CP09 (API):** no se pudo ejecutar. Los 15 pedidos dieron `000`, sin conexión. La Pi hacía ping al CMS, pero el puerto 80 no respondía ni pidiendo por IP. La causa estaba en el host: ESET manejaba el firewall e ignoraba las reglas de Windows (`docs/README.md` §3, hecho 18). Se desinstaló ESET el 21/10. **Repetir CP09.**
- **Observación, no cuenta como corrida de CP05:** con el CMS inaccesible, el reproductor siguió funcionando con la caché del 10/10, sin caerse, y reintentó solo. Los reintentos salieron cada 33,0 s fijos: 30 s más ~3 s del chequeo de red. Es el código anterior al jitter: el servicio seguía con el PID del arranque (773) y no se había reiniciado después del deploy, aunque el código nuevo ya estaba en disco y sus tests pasaban (hecho 19). Desde el 21/10, `deploy.ps1` comprueba el reinicio y falla si no ocurrió, y el log de arranque muestra la versión desplegada. Al repetir CP05 hay que ver intervalos variables entre ~23 y ~43 s.
