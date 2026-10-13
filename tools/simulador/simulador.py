"""
Simulador de reproductores SPUI: pruebas de carga y concurrencia contra el CMS
real, sin Raspberry Pi.

Cada reproductor simulado usa el MISMO código HTTP que la Pi
(pi-client/spui/sync.py, SyncClient) y se conecta al broker MQTT con los mismos
topics y credenciales que un reproductor real. No reproduce video: lo que se
mide es lo que el sistema le hace al servidor y a la red.

Escenarios:
  carga   CP11 — N reproductores durante X minutos con sync, heartbeat y
          telemetría a su ritmo real. Latencia por endpoint, errores, 429,
          bytes, consumo del servidor e integridad de la base.
  rafaga  N reproductores piden sync en el mismo instante (lo que pasa al
          volver la luz o la red en el campus), R veces.
  alerta  CP12 — N reproductores escuchando MQTT; se activa desde el CMS, por el
          mismo camino que el botón Activar, una alerta dirigida a ellos, R
          veces: cuánto tarda en llegarle a cada uno y cuántos no la reciben.
  todo    los tres, uno detrás del otro.

Cada escenario crea su estructura [SIM] (tools/simulador/sim.php), la borra al
terminar aunque falle, y deja en docs/pruebas/ un informe en Markdown y los
datos en JSON.

Uso (desde apps/spui):
  D:\\tools\\spui-python\\Scripts\\python.exe tools/simulador/simulador.py carga -n 50 --minutos 10
  ... simulador.py rafaga -n 50 -r 5
  ... simulador.py alerta -n 100 -r 10
"""

import argparse
import json
import os
import platform
import random
import re
import statistics
import subprocess
import sys
import tempfile
import threading
import time
from datetime import datetime
from pathlib import Path

import requests

# Se importan módulos de pi-client/: sin esto Python dejaría .pyc ahí y
# deploy.ps1 los copiaría a la Pi.
sys.dont_write_bytecode = True

AQUI = Path(__file__).resolve().parent
SPUI = AQUI.parent.parent
MONOREPO = SPUI.parent.parent
sys.path.insert(0, str(SPUI / 'pi-client'))
# OJO: no setear variables de entorno del cliente (MQTT_TLS, etc.) en este
# proceso: sim.php las hereda y el CMS les da prioridad sobre su propia
# configuración (pasó: con MQTT_TLS=false el CMS hablaba sin TLS con el 8883
# y no podía dar de alta las credenciales).

from spui.mqtt_listener import nuevo_cliente_mqtt  # noqa: E402
from spui.sync import SyncClient  # noqa: E402

try:
    import psutil
except ImportError:  # el consumo del servidor es opcional
    psutil = None

# Umbrales de los casos de prueba del Informe.
UMBRAL_CP11_ERROR_PCT = 1.0
UMBRAL_CP11_P95_MS = 500
UMBRAL_CP12_SEG = 5.0


# ── Medición ────────────────────────────────────────────────────────────────

class Medidor:
    """Registro thread-safe de cada pedido HTTP."""

    def __init__(self):
        self._lock = threading.Lock()
        self.pedidos: list[dict] = []

    def registrar(self, **dato):
        with self._lock:
            self.pedidos.append(dato)


class SesionMedida(requests.Session):
    """requests.Session que registra tiempo, estado y bytes de cada pedido."""

    def __init__(self, medidor: Medidor, reproductor: int):
        super().__init__()
        self._medidor, self._reproductor = medidor, reproductor

    def request(self, method, url, **kw):
        t0 = time.perf_counter()
        inicio = time.time()
        try:
            r = super().request(method, url, **kw)
        except Exception as exc:
            self._medidor.registrar(t=inicio, rep=self._reproductor, endpoint=_endpoint(url), estado=0,
                                    ms=(time.perf_counter() - t0) * 1000, bytes_resp=0, error=type(exc).__name__)
            raise
        self._medidor.registrar(t=inicio, rep=self._reproductor, endpoint=_endpoint(url), estado=r.status_code,
                                ms=(time.perf_counter() - t0) * 1000, bytes_resp=len(r.content),
                                bytes_env=len(kw.get('data') or b'') + len(json.dumps(kw['json']) if kw.get('json') is not None else ''),
                                error=None)
        return r


def _endpoint(url: str) -> str:
    return re.sub(r'/\d+', '/{id}', url.split('/api/spui', 1)[-1].split('?')[0]) or url


def percentil(valores, p):
    if not valores:
        return None
    v = sorted(valores)
    k = (len(v) - 1) * p / 100
    f, c = int(k), min(int(k) + 1, len(v) - 1)
    return v[f] + (v[c] - v[f]) * (k - f)


# ── Reproductor simulado ────────────────────────────────────────────────────

class ReproductorSimulado:
    def __init__(self, datos: dict, args, medidor: Medidor, carpeta_media: str):
        self.id = datos['id']
        self.hostname = datos['hostname']
        self._datos = datos
        self._args = args
        self.cliente = SyncClient(args.api, datos['api_key'], carpeta_media)
        sesion = SesionMedida(medidor, self.id)
        sesion.headers.update(self.cliente._session.headers)
        self.cliente._session = sesion
        self.mqtt = None
        self.conectado_mqtt = threading.Event()
        self.alertas_recibidas: dict[int, float] = {}   # alerta_id → time.time() de llegada
        self.telemetria_publicada = 0
        self.telemetria_fallida = 0

    # HTTP, con el mismo código de la Pi
    def sync(self) -> bool:
        try:
            self.cliente.sincronizar()
            return True
        except Exception:
            return False

    def heartbeat(self) -> bool:
        return self.cliente.heartbeat([])

    # MQTT, con los mismos topics que mqtt_listener.py / telemetria.py
    def conectar_mqtt(self):
        cliente = nuevo_cliente_mqtt(f'{self.hostname}-{random.randint(0, 99999)}')
        cliente.username_pw_set(self._datos['mqtt_user'], self._datos['mqtt_pass'])
        topic_estado = f'spui/estado/reproductor/{self.id}'
        cliente.will_set(topic_estado, json.dumps({'estado': 'offline'}), qos=1, retain=True)

        def al_conectar(c, _u, _f, rc):
            if rc != 0:
                return
            c.subscribe([('spui/alertas/emergencia', 1), (f'spui/alertas/reproductor/{self.id}', 1),
                         (f'spui/comandos/reproductor/{self.id}', 1)])
            c.publish(topic_estado, json.dumps({'estado': 'online'}), qos=1, retain=True)
            self.conectado_mqtt.set()

        def al_recibir(_c, _u, msg):
            if not msg.payload or '/alertas/' not in msg.topic:
                return
            try:
                datos = json.loads(msg.payload)
            except ValueError:
                return
            if datos.get('accion') == 'activada' and datos.get('id') is not None:
                self.alertas_recibidas.setdefault(int(datos['id']), time.time())

        cliente.on_connect, cliente.on_message = al_conectar, al_recibir
        cliente.connect_async(self._args.mqtt_host, self._args.mqtt_port, keepalive=60)
        cliente.loop_start()
        self.mqtt = cliente

    def publicar_telemetria(self):
        if self.mqtt is None:
            return
        carga = {
            'temperatura_soc_celsius': round(random.uniform(45, 70), 2),
            'uso_ram_porcentaje': round(random.uniform(20, 60), 1),
            'espacio_disco_libre_mb': random.randint(20000, 25000),
            'latencia_red_ms': round(random.uniform(2, 30), 1),
        }
        info = self.mqtt.publish(f'spui/telemetria/{self.id}', json.dumps(carga), qos=0)
        if getattr(info, 'rc', 0) == 0:
            self.telemetria_publicada += 1
        else:
            self.telemetria_fallida += 1

    def desconectar(self):
        if self.mqtt is not None:
            self.mqtt.disconnect()
            self.mqtt.loop_stop()


# ── Consumo del servidor ────────────────────────────────────────────────────

class MuestreadorServidor(threading.Thread):
    PROCESOS = {'httpd.exe': 'Apache', 'php-cgi.exe': 'PHP', 'mysqld.exe': 'MySQL', 'mosquitto.exe': 'Mosquitto',
                'httpd': 'Apache', 'php-cgi': 'PHP', 'mysqld': 'MySQL', 'mosquitto': 'Mosquitto'}

    def __init__(self, cada_seg=5):
        super().__init__(daemon=True)
        self._cada, self._fin = cada_seg, threading.Event()
        self.muestras: list[dict] = []

    def run(self):
        if psutil is None:
            return
        psutil.cpu_percent(None)
        procs = {}
        while not self._fin.wait(self._cada):
            muestra = {'t': time.time(), 'cpu_total': psutil.cpu_percent(None), 'ram_total_pct': psutil.virtual_memory().percent}
            por = {}
            for p in psutil.process_iter(['name']):
                nombre = self.PROCESOS.get((p.info['name'] or '').lower())
                if not nombre:
                    continue
                try:
                    if p.pid not in procs:
                        procs[p.pid] = p
                        p.cpu_percent(None)
                        continue
                    cpu, rss = procs[p.pid].cpu_percent(None), p.memory_info().rss
                except (psutil.NoSuchProcess, psutil.AccessDenied):
                    continue
                acc = por.setdefault(nombre, {'cpu': 0.0, 'ram_mb': 0.0})
                acc['cpu'] += cpu
                acc['ram_mb'] += rss / 1048576
            muestra['procesos'] = por
            self.muestras.append(muestra)

    def detener(self):
        self._fin.set()


# ── Helpers del CMS (sim.php) ───────────────────────────────────────────────

def sim_php(*args, tolerar_error: bool = False) -> dict:
    """Corre sim.php; si termina con error, corta la corrida con el motivo (nunca sigue de largo)."""
    r = subprocess.run(['php', str(AQUI / 'sim.php'), *map(str, args)], capture_output=True, text=True,
                       encoding='utf-8', cwd=str(SPUI))
    try:
        datos = json.loads(r.stdout)
    except ValueError:
        datos = None
    if r.returncode != 0 and not tolerar_error or datos is None:
        raise RuntimeError(f'sim.php {" ".join(map(str, args))} falló (código {r.returncode}): '
                           f'{r.stderr.strip() or r.stdout.strip()}')
    return datos


def sesion_cms() -> tuple[requests.Session, str]:
    """Sesión del panel (la misma de tools/verificacion) y el token CSRF de las acciones."""
    salida = subprocess.run(['php', str(SPUI / 'tools/verificacion/sesion.php')], capture_output=True, text=True,
                            cwd=str(SPUI)).stdout.strip().splitlines()
    s = requests.Session()
    # Como cabecera y no con s.cookies.set(): el cookiejar de Python no asocia
    # una cookie a un host sin punto ("intranet") y no la mandaba.
    s.headers['Cookie'] = f'PHPSESSID={salida[-1]}'
    r = s.get('http://intranet/spui/alertas', timeout=30, allow_redirects=False)
    if r.status_code in (301, 302, 303):
        raise RuntimeError(f'El CMS redirigió al login ({r.headers.get("Location")}): la sesión de prueba no sirvió.')
    html = r.text
    token = re.search(r'name="spui-csrf-token"\s+content="([^"]+)"', html)
    if not token:
        raise RuntimeError('No se encontró el token CSRF en /spui/alertas: ¿la sesión de prueba sirvió?')
    return s, token.group(1)


# ── Escenarios ──────────────────────────────────────────────────────────────

def preparar(args, n, medidor):
    print(f'Preparando {n} reproductores [SIM]…', flush=True)
    resumen = sim_php('preparar', n, *([f'--playlist={args.playlist}'] if args.playlist else []))
    estado = json.loads((AQUI / '.sim' / 'estado.json').read_text(encoding='utf-8'))
    carpeta = tempfile.mkdtemp(prefix='spui-sim-')
    reps = [ReproductorSimulado(d, args, medidor, os.path.join(carpeta, d['hostname'])) for d in estado['reproductores']]
    return reps, resumen


def conectar_todos(reps, timeout=60):
    for r in reps:
        r.conectar_mqtt()
    limite = time.time() + timeout
    for r in reps:
        r.conectado_mqtt.wait(max(0.1, limite - time.time()))
    return sum(1 for r in reps if r.conectado_mqtt.is_set())


def escenario_carga(args, reps, medidor):
    """Cada reproductor a su ritmo real, arrancando en momentos distintos (como en el campus)."""
    conectados = conectar_todos(reps)
    fin = time.time() + args.minutos * 60
    intervalo_sync, intervalo_hb, intervalo_tel = args.intervalo_sync, 60, 60
    syncs = {'ok': 0, 'fallidos': 0}
    lock = threading.Lock()

    def vida(r):
        desfase = random.uniform(0, intervalo_sync)
        proximo = {'sync': time.time() + desfase, 'hb': time.time() + random.uniform(0, intervalo_hb),
                   'tel': time.time() + random.uniform(0, intervalo_tel)}
        while time.time() < fin:
            ahora = time.time()
            if ahora >= proximo['sync']:
                ok = r.sync()
                with lock:
                    syncs['ok' if ok else 'fallidos'] += 1
                proximo['sync'] += intervalo_sync
            if ahora >= proximo['hb']:
                r.heartbeat()
                proximo['hb'] += intervalo_hb
            if ahora >= proximo['tel']:
                r.publicar_telemetria()
                proximo['tel'] += intervalo_tel
            time.sleep(min(0.5, max(0.05, min(proximo.values()) - time.time())))

    hilos = [threading.Thread(target=vida, args=(r,), daemon=True) for r in reps]
    print(f'Carga sostenida: {len(reps)} reproductores durante {args.minutos} min (sync cada {intervalo_sync} s)…', flush=True)
    for h in hilos:
        h.start()
    for h in hilos:
        h.join()
    return {'mqtt_conectados': conectados, 'syncs': syncs,
            'telemetria_publicada': sum(r.telemetria_publicada for r in reps),
            'telemetria_fallida': sum(r.telemetria_fallida for r in reps)}


def escenario_rafaga(args, reps, medidor):
    rondas = []
    for ronda in range(1, args.repeticiones + 1):
        barrera = threading.Barrier(len(reps))
        resultados = {}

        def golpe(r):
            barrera.wait()
            t0 = time.perf_counter()
            ok = r.sync()
            resultados[r.id] = (ok, (time.perf_counter() - t0) * 1000)

        print(f'Ráfaga {ronda}/{args.repeticiones}: {len(reps)} syncs a la vez…', flush=True)
        t0 = time.perf_counter()
        hilos = [threading.Thread(target=golpe, args=(r,)) for r in reps]
        for h in hilos:
            h.start()
        for h in hilos:
            h.join()
        total = (time.perf_counter() - t0) * 1000
        tiempos = [ms for _, ms in resultados.values()]
        rondas.append({'ronda': ronda, 'hasta_el_ultimo_ms': total, 'ok': sum(1 for ok, _ in resultados.values() if ok),
                       'fallidos': sum(1 for ok, _ in resultados.values() if not ok),
                       'p50_ms': percentil(tiempos, 50), 'p95_ms': percentil(tiempos, 95), 'max_ms': max(tiempos)})
        if ronda < args.repeticiones:
            time.sleep(35)   # el CMS acepta 2 syncs por minuto por reproductor
    return {'rondas': rondas}


def escenario_alerta(args, reps, medidor):
    conectados = conectar_todos(reps)
    # El CMS sólo deja activar una alerta si sus reproductores están conectados (heartbeat).
    for r in reps:
        r.heartbeat()
    latido_fin = threading.Event()

    def latir():
        while not latido_fin.wait(55):
            for r in reps:
                r.heartbeat()

    threading.Thread(target=latir, daemon=True).start()
    alerta_id = sim_php('alerta')['alerta_id']
    cms, token = sesion_cms()
    cabeceras = {'X-Requested-With': 'XMLHttpRequest'}
    rondas = []
    try:
        for ronda in range(1, args.repeticiones + 1):
            for r in reps:
                r.alertas_recibidas.pop(alerta_id, None)
            print(f'Alerta {ronda}/{args.repeticiones} a {len(reps)} reproductores…', flush=True)
            t0 = time.time()
            resp = cms.post(f'http://intranet/spui/alertas/{alerta_id}/activar', data={'_token': token}, headers=cabeceras, timeout=120, allow_redirects=False)
            t_resp = time.time()
            try:
                cuerpo = resp.json()
            except ValueError:
                cuerpo = {'success': False, 'message': resp.text[:200]}
            limite = t0 + args.timeout_alerta
            while time.time() < limite and sum(1 for r in reps if alerta_id in r.alertas_recibidas) < len(reps):
                time.sleep(0.05)
            llegadas = [r.alertas_recibidas[alerta_id] - t0 for r in reps if alerta_id in r.alertas_recibidas]
            rondas.append({'ronda': ronda, 'http_estado': resp.status_code, 'cms_success': cuerpo.get('success'),
                           'cms_mensaje': cuerpo.get('message'), 'respuesta_activar_s': t_resp - t0,
                           'recibieron': len(llegadas), 'no_recibieron': len(reps) - len(llegadas),
                           'primera_s': min(llegadas) if llegadas else None, 'p50_s': percentil(llegadas, 50),
                           'p95_s': percentil(llegadas, 95), 'ultima_s': max(llegadas) if llegadas else None})
            cms.post(f'http://intranet/spui/alertas/{alerta_id}/desactivar', data={'_token': token}, headers=cabeceras, timeout=120, allow_redirects=False)
            time.sleep(3)
    finally:
        latido_fin.set()
    return {'mqtt_conectados': conectados, 'alerta_id': alerta_id, 'rondas': rondas}


ESCENARIOS = {'carga': escenario_carga, 'rafaga': escenario_rafaga, 'alerta': escenario_alerta}
DEFECTOS_N = {'carga': 50, 'rafaga': 50, 'alerta': 100}


# ── Informe ─────────────────────────────────────────────────────────────────

def resumir_http(pedidos):
    por = {}
    for p in pedidos:
        por.setdefault(p['endpoint'], []).append(p)
    filas = []
    for ep, ps in sorted(por.items()):
        ms = [p['ms'] for p in ps]
        errores = [p for p in ps if p['estado'] == 0 or p['estado'] >= 400]
        filas.append({'endpoint': ep, 'pedidos': len(ps), 'errores': len(errores),
                      'error_pct': 100 * len(errores) / len(ps), 'http_429': sum(1 for p in ps if p['estado'] == 429),
                      'p50_ms': percentil(ms, 50), 'p95_ms': percentil(ms, 95), 'p99_ms': percentil(ms, 99), 'max_ms': max(ms),
                      'bytes_resp_prom': statistics.mean(p['bytes_resp'] for p in ps),
                      'estados': {str(k): sum(1 for p in ps if p['estado'] == k) for k in sorted({p['estado'] for p in ps})}})
    return filas


def resumir_servidor(muestras):
    if not muestras:
        return None
    nombres = sorted({n for m in muestras for n in m['procesos']})
    return {'muestras': len(muestras),
            'cpu_total_prom': statistics.mean(m['cpu_total'] for m in muestras),
            'cpu_total_max': max(m['cpu_total'] for m in muestras),
            'ram_total_max_pct': max(m['ram_total_pct'] for m in muestras),
            'procesos': {n: {'cpu_prom': statistics.mean(m['procesos'].get(n, {}).get('cpu', 0) for m in muestras),
                             'cpu_max': max(m['procesos'].get(n, {}).get('cpu', 0) for m in muestras),
                             'ram_max_mb': max(m['procesos'].get(n, {}).get('ram_mb', 0) for m in muestras)} for n in nombres}}


def _f(v, d=0):
    return '—' if v is None else f'{v:,.{d}f}'.replace(',', 'X').replace('.', ',').replace('X', '.')


def escribir_informe(escenario, args, n, preparacion, resultado, http, servidor, integridad, inicio, fin):
    carpeta = SPUI / 'docs' / 'pruebas'
    carpeta.mkdir(parents=True, exist_ok=True)
    base = carpeta / f'{inicio:%Y-%m-%d_%H%M}_{escenario}'
    commit = subprocess.run(['git', 'rev-parse', '--short', 'HEAD'], capture_output=True, text=True, cwd=str(SPUI)).stdout.strip()

    veredictos = []
    if escenario == 'carga':
        total = sum(f['pedidos'] for f in http)
        err = sum(f['errores'] for f in http)
        err_pct = 100 * err / total if total else 0
        p95_peor = max((f['p95_ms'] for f in http), default=None)
        veredictos.append(('CP11 — tasa de error < 1 %', f'{_f(err_pct, 2)} %', err_pct < UMBRAL_CP11_ERROR_PCT))
        veredictos.append(('CP11 — API p95 < 500 ms (peor endpoint)', f'{_f(p95_peor)} ms', p95_peor is not None and p95_peor < UMBRAL_CP11_P95_MS))
    if escenario == 'alerta':
        ultimas = [r['ultima_s'] for r in resultado['rondas'] if r['ultima_s'] is not None]
        perdidas = sum(r['no_recibieron'] for r in resultado['rondas'])
        peor = max(ultimas) if ultimas else None
        veredictos.append((f'CP12 — alerta a {n} reproductores en < 5 s (el último, peor ronda)', f'{_f(peor, 2)} s',
                           peor is not None and peor < UMBRAL_CP12_SEG and perdidas == 0))
        veredictos.append(('CP12 — ningún reproductor sin recibirla', f'{perdidas} sin recibir', perdidas == 0))

    L = [f'# Prueba de {"carga sostenida" if escenario == "carga" else "ráfaga de syncs" if escenario == "rafaga" else "alerta simultánea"} — {inicio:%d/%m/%Y %H:%M}', '']
    L += ['> Generado por `tools/simulador/simulador.py`. Datos completos en el JSON del mismo nombre.', '']
    if veredictos:
        L += ['## Resultado', '', '| Criterio | Medido | Cumple |', '|---|---|---|']
        L += [f'| {c} | {m} | {"✅ Sí" if ok else "❌ No"} |' for c, m, ok in veredictos]
        L += ['']
    L += ['## Escenario y entorno', '',
          f'- **Reproductores simulados:** {n} (estructura `[SIM]` temporal, borrada al terminar), cliente HTTP real de la Pi (`SyncClient`).',
          f'- **Contenido:** playlist «{preparacion["playlist"]["nombre"]}» ({preparacion["playlist"]["items"]} ítem/s), la misma de una pantalla real.']
    if escenario == 'carga':
        L.append(f'- **Duración:** {args.minutos} min · sync cada {args.intervalo_sync} s · heartbeat cada 60 s · telemetría MQTT cada 60 s · cada reproductor arranca en un momento al azar.')
    elif escenario == 'rafaga':
        L.append(f'- **Rondas:** {args.repeticiones}, todos los reproductores piden sync en el mismo instante; 35 s entre rondas (límite de 2 syncs/min por reproductor).')
    else:
        L.append(f'- **Rondas:** {args.repeticiones}. La alerta se activa con el mismo POST del botón Activar del CMS (sesión, CSRF, chequeo de conectados), dirigida sólo a las pantallas [SIM]; timeout {args.timeout_alerta} s por ronda.')
    L += [f'- **CMS:** `{args.api}` · broker `{args.mqtt_host}:{args.mqtt_port}` (sin TLS) · commit `{commit}`.',
          f'- **Equipo:** {platform.node()}, {platform.system()} {platform.release()}, {os.cpu_count()} núcleos'
          + (f', {psutil.virtual_memory().total / 1073741824:.1f} GB de RAM' if psutil else '') + '. El simulador corre en el mismo equipo que el servidor, así que compite con él por CPU.',
          f'- **Inicio / fin:** {inicio:%H:%M:%S} – {fin:%H:%M:%S} ({_f((fin - inicio).total_seconds() / 60, 1)} min).', '']

    if http:
        L += ['## Pedidos HTTP por endpoint', '', '| Endpoint | Pedidos | Errores | % error | 429 | p50 | p95 | p99 | máx | Bytes/resp. |', '|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|']
        L += [f'| `{f["endpoint"]}` | {f["pedidos"]} | {f["errores"]} | {_f(f["error_pct"], 2)} | {f["http_429"]} | {_f(f["p50_ms"])} ms | {_f(f["p95_ms"])} ms | {_f(f["p99_ms"])} ms | {_f(f["max_ms"])} ms | {_f(f["bytes_resp_prom"])} |' for f in http]
        total_bytes = sum(p['bytes_resp'] for p in medidor_global.pedidos)
        L += ['', f'Tráfico de respuestas: {_f(total_bytes / 1048576, 2)} MB en total.', '']

    if escenario == 'carga':
        L += ['## Telemetría y MQTT', '',
              f'- Conectados al broker: {resultado["mqtt_conectados"]} de {n}.',
              f'- Telemetría publicada: {resultado["telemetria_publicada"]} (fallidas al publicar: {resultado["telemetria_fallida"]}).']
        if integridad and integridad.get('telemetria_por_reproductor') is not None:
            guardada = sum(int(t['n']) for t in integridad['telemetria_por_reproductor'])
            L.append(f'- Telemetría guardada en la base por el daemon: {guardada} ({_f(100 * guardada / resultado["telemetria_publicada"], 1) if resultado["telemetria_publicada"] else "—"} % de lo publicado).')
        L.append('')
    if escenario == 'rafaga':
        L += ['## Rondas', '', '| Ronda | OK | Fallidos | p50 | p95 | Más lento | Hasta el último |', '|---:|---:|---:|---:|---:|---:|---:|']
        L += [f'| {r["ronda"]} | {r["ok"]} | {r["fallidos"]} | {_f(r["p50_ms"])} ms | {_f(r["p95_ms"])} ms | {_f(r["max_ms"])} ms | {_f(r["hasta_el_ultimo_ms"])} ms |' for r in resultado['rondas']]
        L.append('')
    if escenario == 'alerta':
        L += ['## Rondas', '', f'Conectados al broker: {resultado["mqtt_conectados"]} de {n}.', '',
              '| Ronda | CMS respondió | Respuesta de Activar | Recibieron | Sin recibir | Primera | p50 | p95 | Última |', '|---:|---|---:|---:|---:|---:|---:|---:|---:|']
        L += [f'| {r["ronda"]} | {r["http_estado"]} {"✅" if r["cms_success"] else "❌ " + str(r["cms_mensaje"])} | {_f(r["respuesta_activar_s"], 2)} s | {r["recibieron"]} | {r["no_recibieron"]} | {_f(r["primera_s"], 2)} s | {_f(r["p50_s"], 2)} s | {_f(r["p95_s"], 2)} s | {_f(r["ultima_s"], 2)} s |' for r in resultado['rondas']]
        L += ['', 'Los tiempos se miden desde que se envía el POST de Activar hasta que el mensaje llega a cada reproductor.', '']

    if servidor:
        L += ['## Consumo del servidor', '', f'CPU total del equipo: promedio {_f(servidor["cpu_total_prom"], 1)} %, pico {_f(servidor["cpu_total_max"], 1)} %. RAM del equipo: pico {_f(servidor["ram_total_max_pct"], 1)} %.', '',
              '| Proceso | CPU prom. | CPU pico | RAM pico |', '|---|---:|---:|---:|']
        L += [f'| {n_} | {_f(v["cpu_prom"], 1)} % | {_f(v["cpu_max"], 1)} % | {_f(v["ram_max_mb"])} MB |' for n_, v in servidor['procesos'].items()]
        L += ['', 'El % de CPU de un proceso es sobre un núcleo (puede pasar de 100 % si usa varios).', '']

    if integridad:
        L += ['## Integridad de la base después de la corrida', '']
        # Cada escenario hace sólo lo suyo: la ráfaga no manda heartbeats y la
        # alerta no sincroniza. Mostrar esos conteos ahí parecería un problema.
        if escenario in ('carga', 'rafaga'):
            L.append(f'- Reproductores [SIM] sin ningún sync en la corrida: {integridad.get("reproductores_sin_sync_en_la_corrida")}.')
        if escenario in ('carga', 'alerta'):
            L.append(f'- Sin ningún heartbeat en la corrida: {integridad.get("reproductores_sin_heartbeat_en_la_corrida")}.')
        L += [
              f'- Telemetría con valores fuera de rango: {integridad.get("telemetria_con_valores_fuera_de_rango")}.',
              f'- Telemetría huérfana en toda la base: {integridad.get("telemetria_huerfana_en_toda_la_base")}.',
              f'- Reproductores [SIM] con diagnóstico informado: {integridad.get("reproductores_con_diagnostico")}.']
        errores = integridad.get('errores_del_cms_en_el_log') or {}
        L.append(f'- Errores del CMS en `var/log/spui/dev.log` durante la corrida: {sum(errores.values())}.')
        L += [f'  - {k} — {v} vez/veces' for k, v in errores.items()]
        L.append('')

    L += ['## Análisis y recomendaciones', '', '_Pendiente: lo completa quien revisa la corrida (cuellos de botella, puntos a mejorar, buenas prácticas)._', '']
    base.with_suffix('.md').write_text('\n'.join(L), encoding='utf-8')
    base.with_suffix('.json').write_text(json.dumps({
        'escenario': escenario, 'inicio': inicio.isoformat(), 'fin': fin.isoformat(), 'commit': commit, 'n': n,
        'parametros': vars(args), 'preparacion': preparacion, 'resultado': resultado, 'http_por_endpoint': http,
        'servidor': servidor, 'integridad': integridad,
        'veredictos': [{'criterio': c, 'medido': m, 'cumple': ok} for c, m, ok in veredictos],
        'pedidos': medidor_global.pedidos,
    }, indent=1, ensure_ascii=False, default=str), encoding='utf-8')
    return base


medidor_global = Medidor()


def correr(escenario, args):
    global medidor_global
    medidor_global = Medidor()
    n = args.n or DEFECTOS_N[escenario]
    inicio = datetime.now()
    reps, preparacion = [], None
    servidor = MuestreadorServidor()
    try:
        reps, preparacion = preparar(args, n, medidor_global)
        servidor.start()
        resultado = ESCENARIOS[escenario](args, reps, medidor_global)
        servidor.detener()
        time.sleep(3)   # que el daemon termine de guardar la última telemetría
        integridad = sim_php('integridad', inicio.isoformat(timespec='seconds'))
    finally:
        servidor.detener()
        for r in reps:
            r.desconectar()
        if not args.conservar:
            print('Limpiando la estructura [SIM]…', flush=True)
            limpieza = sim_php('limpiar', tolerar_error=True)
            print(json.dumps(limpieza, ensure_ascii=False))
            if limpieza.get('credenciales_mqtt_revocadas') != limpieza.get('de'):
                print('ATENCIÓN: no se revocaron todas las credenciales MQTT [SIM]; correr `php tools/simulador/sim.php limpiar` de nuevo.')
    fin = datetime.now()
    base = escribir_informe(escenario, args, n, preparacion, resultado, resumir_http(medidor_global.pedidos),
                            resumir_servidor(servidor.muestras), integridad, inicio, fin)
    print(f'Informe: {base.with_suffix(".md")}')
    return base


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('escenario', choices=[*ESCENARIOS, 'todo'])
    ap.add_argument('-n', type=int, help='reproductores simulados (por defecto: carga 50, ráfaga 50, alerta 100)')
    ap.add_argument('--minutos', type=float, default=10, help='duración de la carga sostenida')
    ap.add_argument('--intervalo-sync', type=int, default=60, help='segundos entre syncs en la carga (mínimo del CMS: 60)')
    ap.add_argument('-r', '--repeticiones', type=int, default=None, help='rondas de ráfaga (5) o de alerta (10)')
    ap.add_argument('--timeout-alerta', type=float, default=30)
    ap.add_argument('--api', default='http://intranet/api/spui')
    ap.add_argument('--mqtt-host', default='127.0.0.1')
    ap.add_argument('--mqtt-port', type=int, default=1883)
    ap.add_argument('--playlist', type=int, help='id de la playlist que muestran las pantallas [SIM] (por defecto, la de una pantalla real)')
    ap.add_argument('--conservar', action='store_true', help='no borrar la estructura [SIM] al terminar')
    args = ap.parse_args()
    for esc in (ESCENARIOS if args.escenario == 'todo' else [args.escenario]):
        a = argparse.Namespace(**vars(args))
        if a.repeticiones is None:
            a.repeticiones = 10 if esc == 'alerta' else 5
        correr(esc, a)


if __name__ == '__main__':
    main()
