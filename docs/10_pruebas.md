# 10 — Protocolo y registro de pruebas (CP01–CP13)

Este documento manda sobre **cómo se ejecuta cada caso de prueba y dónde queda su resultado**. Cada caso tiene su criterio de aceptación, su procedimiento paso a paso, la evidencia que lo prueba (qué línea de log, qué pantalla del CMS) y una planilla de registro que se completa en cada corrida. Un caso cuenta como ejecutado cuando su planilla tiene las repeticiones indicadas y un veredicto, no antes.

Las pruebas de carga (CP11, CP12) las corre el simulador de `tools/simulador/` y dejan su informe en `pruebas/`. Acá sólo se registra el resumen y el enlace.

---

## 1. Estado

| Caso | Criterio | Necesita ver la pantalla | Estado |
|---|---|---|---|
| CP01 | Publicación y sincronización en < 60 s | No | Sin ejecutar |
| CP02 | Segmentación por pantalla, ubicación y edificio | No (necesita 2 reproductores) | Sin ejecutar |
| CP03 | Orden de prioridad | No | Sin ejecutar |
| CP04 | Alerta global con sonido, < 2 s (p95) | La latencia no; el sonido sí | Sin ejecutar |
| CP05 | Corte de red sin interrupción | No (se ve en el log) | Sin ejecutar |
| CP06 | Resincronización tras el corte, < 60 s | No | Sin ejecutar |
| CP07 | Descarga interrumpida o archivo alterado no entra a caché | No | Sin ejecutar |
| CP08 | Desconexión abrupta visible en el panel | No | Sin ejecutar |
| CP09 | Solicitud o mensaje sin credenciales, rechazado | No | Sin ejecutar |
| CP10 | Encendido y apagado programado | **Sí** | Sin ejecutar |
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
- Límite de sync: el CMS acepta **2 syncs por minuto por reproductor** (`config/packages/rate_limiter.yaml`). Las repeticiones que disparan un sync van separadas al menos **35 s**. Si no, la medición choca con un 429 en vez de medir el sistema.

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
- **CP05:** sin red hacia el CMS y el broker, la Pi sigue mostrando lo que tenía en caché, sin errores en pantalla.
- **CP06:** al volver la red, se resincroniza en menos de 60 s.

**Cómo se corta la red sin perder el SSH.** No se apaga la interfaz. En la Pi se bloquean sólo las conexiones salientes hacia el CMS y el broker (puertos 80, 443, 1883, 8883). Las respuestas del SSH salen del puerto 22 y no se tocan.

```bash
# IP con la que la Pi llega al CMS (el nombre "intranet" de su /etc/hosts)
CMS=$(getent hosts intranet | awk '{print $1}'); echo "$CMS"

# Bookworm trae nftables; si 'iptables' no existe: sudo apt install iptables
# Cortar
sudo iptables -I OUTPUT -p tcp -d "$CMS" -m multiport --dports 80,443,1883,8883 -j REJECT
# Restaurar (el mismo comando con -D en lugar de -I)
sudo iptables -D OUTPUT -p tcp -d "$CMS" -m multiport --dports 80,443,1883,8883 -j REJECT
# Comprobar que no quedó ninguna regla
sudo iptables -S OUTPUT
```

La regla no sobrevive a un reinicio: si algo sale mal, `sudo reboot` la borra.

**Procedimiento.**
1. Con el reproductor sincronizado y mostrando una playlist con imagen y video, cortar. Anotar la hora.
2. **CP05**, durante 5 minutos:
   - En el log: `Red PERDIDA — entrando en modo OFFLINE`.
   - La reproducción sigue (`Reproduciendo …` o `[SIM] …`) sin `No hay una copia válida` ni `Archivo no cacheado y sin red`.
   - Los reintentos son de 30 ± 10 s y nunca exactamente iguales entre sí.
3. Restaurar. Anotar la hora.
4. **CP06:** en el log, `Red RESTAURADA — volviendo al modo online. Re-sincronizando.` y `Sync OK`. Se mide desde que se restauró hasta `Sync OK`.
5. Repetir 3 veces.

| # | Hora corte | `Red PERDIDA` | ¿Siguió reproduciendo? | Reintentos observados (s) | Hora restaurado | `Sync OK` | CP06 latencia |
|---|---|---|---|---|---|---|---|
| 1 | | | | | | | |

### CP07 — Descarga interrumpida o archivo alterado no entra a caché

**Qué prueba.** Un archivo incompleto o modificado nunca se reproduce. El caché vive en `SPUI_CACHE_DIR` de `/etc/spui/spui.env`, que `install.sh` deja en `/var/cache/spui`, así que los archivos están en `/var/cache/spui/media/` (confirmarlo con `sudo grep SPUI_CACHE_DIR /etc/spui/spui.env`).

**A. Archivo alterado** (3 repeticiones):
1. Elegir un archivo de la playlist actual: `ls -la /var/cache/spui/media/`.
2. Alterarlo: `sudo sh -c 'printf x >> /var/cache/spui/media/<archivo>'`.
3. Cuando le toque reproducirse, en el log: `Archivo del caché alterado o incompleto (hash distinto): <archivo> — se descarta.`, seguido de `Descargando media: <archivo>` (si hay red).
4. Comprobar que el archivo nuevo coincide con el del CMS: `sha256sum` en la Pi contra el hash de la ficha del contenido.

**B. Descarga interrumpida** (2 repeticiones):
1. Subir al CMS un video grande (cientos de MB) y agregarlo a la playlist de la Pi.
2. Cuando aparezca `Descargando media: <archivo>`, cortar la red como en CP05.
3. Comprobar que el archivo **no** quedó con su nombre final: `ls /var/cache/spui/media/` sólo muestra, a lo sumo, un `<archivo>.part`.
4. En el log, el ítem no se reproduce: `Archivo no cacheado y sin red` o `No hay una copia válida`.
5. Restaurar la red. La descarga se completa y el video entra a la rotación. Al reiniciar el servicio con un `.part` presente, `Se borraron N descarga(s) a medias de una ejecución anterior.`

Cubierto además por los tests de `pi-client/tests/`.

| # | Variante | Archivo | Evidencia en el log | ¿Se reprodujo la copia mala? |
|---|---|---|---|---|
| 1 | A | | | |

### CP08 — Desconexión abrupta visible en el panel

**Qué prueba.** Si un reproductor cae sin aviso, el panel lo muestra como desconectado.

**Cuánto se espera.** El estado del panel se calcula **sólo por el heartbeat HTTP** (`SPUI_UMBRAL_CONEXION_SEG`, 150 s). El mantenimiento del daemon lo marca cada 60 s, así que lo esperable es **entre 150 y 210 s** desde el último heartbeat. El aviso de caída del broker (LWT) llega mucho antes y queda registrado en el log del daemon (`LWT: caída detectada por el broker`). El panel no lo usa a propósito (`Reproductor.php`, comentario de `marcarLwtOffline()`).

**Dos variantes:**
- **A. Proceso muerto:** `sudo systemctl kill -s KILL spui`. El sistema cierra las conexiones, así que el broker se entera en el acto.
- **B. Cable cortado:** el bloqueo de CP05 cambiando `REJECT` por `DROP`. No se cierra nada, y el broker se entera por el keepalive (60 s × 1,5).

**Procedimiento.** Para cada variante, 3 repeticiones:
1. Anotar la hora del último `Heartbeat` exitoso. Si el nivel de log no lo muestra, usar la hora del corte.
2. Provocar la caída y anotar la hora.
3. Refrescar el panel de Reproductores hasta que muestre Desconectado. Anotar la hora.
4. Anotar también la hora de `LWT: caída detectada` en el daemon.
5. Restaurar: `sudo systemctl start spui`, o quitar la regla. Esperar a que vuelva a Conectado y anotar cuánto tardó.

| # | Variante | Caída | LWT (daemon) | Desconectado en el panel | Demora | Volvió a Conectado |
|---|---|---|---|---|---|---|
| 1 | A | | | | | |

### CP09 — Solicitud o mensaje sin credenciales, rechazado

**Qué prueba.** Ni la API ni el broker aceptan pedidos sin credenciales válidas, y un reproductor no puede emitir alertas.

Desde la Pi, 5 repeticiones de cada vector:

```bash
# 1. API sin clave → 401
curl -s -o /dev/null -w '%{http_code}\n' -X POST http://intranet/api/spui/reproductores/sync
# 2. API con clave inventada → 401
curl -s -o /dev/null -w '%{http_code}\n' -X POST -H 'X-Api-Key: clave-invalida' http://intranet/api/spui/reproductores/sync
# 3. Media sin clave → 401
curl -s -o /dev/null -w '%{http_code}\n' http://intranet/api/spui/media/cualquiera.jpg
```

Para el broker hace falta `mosquitto-clients` en la Pi (`sudo apt install mosquitto-clients`). Usar el puerto que tenga `MQTT_PORT` en `/etc/spui/spui.env`. Con TLS, agregar `--cafile` con la CA de `MQTT_CA_CERT`.

```bash
# 4. Conexión anónima → "Not authorized" / "Connection Refused"
mosquitto_pub -h intranet -p 1883 -t spui/alertas/emergencia -m '{"prueba":1}'
# 5. Un reproductor intentando publicar una alerta con sus propias credenciales.
#    El broker acepta la conexión pero descarta la publicación (ACL); la prueba
#    es que ninguna Pi ni el CMS la reciban: escuchar en paralelo con
#    mosquitto_sub con las mismas credenciales y comprobar que no llega nada.
mosquitto_pub -h intranet -p 1883 -u spui-repro-<id> -P '<clave_mqtt>' -t spui/alertas/emergencia -m '{"prueba":1}' -d
```

La clave MQTT del reproductor es `sha256(api_key + SPUI_MQTT_SALT)`, la misma que calcula el cliente. No se escribe en la planilla.

| Vector | Esperado | Rep. 1 | 2 | 3 | 4 | 5 |
|---|---|---|---|---|---|---|
| API sin clave | 401 | | | | | |
| API con clave inválida | 401 | | | | | |
| Media sin clave | 401 | | | | | |
| MQTT anónimo | Rechazado | | | | | |
| Reproductor publica alerta | No llega a nadie | | | | | |

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

### 19/10/2026 — preparación, con la Pi por SSH y sin pantalla

**Condiciones:**
- Pi `spui-pi-01` por Wi-Fi en la misma LAN que el CMS, sin monitor conectado.
- Reloj de la Pi sincronizado por NTP. La PC no sincroniza (`Local CMOS Clock`), pero su desfase medido es de **+0,03 s, estable** (5 muestras contra `pool.ntp.org` y 5 contra `time.windows.com`). Para los 2 s de CP04 es despreciable, así que no se corrige.

**Resultados:**
- **Tests del cliente en la Pi:** 54 de 54 OK.
- **Puerto MQTT de la Pi:** 8883 con TLS (`MQTT_TLS=true`), host `spui-broker.unraf.local`. La parte de la Pi de C2 ya está hecha; falta cerrar el 1883 en el broker.
- **CP09 (API):** no se pudo ejecutar. Los 15 pedidos dieron `000`, sin conexión. La Pi hacía ping al CMS, pero el puerto 80 no respondía ni pidiendo por IP. La causa estaba en el host: ESET manejaba el firewall e ignoraba las reglas de Windows (`docs/README.md` §3, hecho 18). Se desinstaló ESET el 21/10. **Repetir CP09.**
- **Observación, no cuenta como corrida de CP05:** con el CMS inaccesible, el reproductor siguió funcionando con la caché del 10/10, sin caerse, y reintentó solo. Los reintentos salieron cada 33,0 s fijos: 30 s más ~3 s del chequeo de red. Es el código anterior al jitter: el servicio seguía con el PID del arranque (773) y no se había reiniciado después del deploy, aunque el código nuevo ya estaba en disco y sus tests pasaban (hecho 19). Desde el 21/10, `deploy.ps1` comprueba el reinicio y falla si no ocurrió, y el log de arranque muestra la versión desplegada. Al repetir CP05 hay que ver intervalos variables entre ~23 y ~43 s.
