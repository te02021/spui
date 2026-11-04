"""
Gestión energética de la pantalla (CU-11).

Aplica el horario que viene en el sync: enciende y apaga la pantalla a la hora
programada y ajusta el brillo. El horario llega en pantallas[].energia como una
lista de reglas, una por día configurado:

    {"dia_semana": 1, "hora_encendido": "07:30", "hora_apagado": "21:00", "nivel_brillo": 80}

dia_semana va en ISO (1=Lunes … 7=Domingo), igual que datetime.isoweekday().
Un día sin regla significa "sin gestión": la pantalla queda encendida y con el
brillo que tenga configurado.

Qué se puede controlar depende de la pantalla y se DETECTA, no se supone. La
detección completa (consultas por CEC y DDC/CI) se hace al arrancar y cuando
se conecta otra pantalla, que se nota porque cambia el EDID que el kernel ya
tiene leído en /sys/class/drm (leer ese archivo no genera tráfico por el
cable). Mientras no se encuentre cómo controlarla, se reintenta cada 2 minutos.

  Encendido/apagado, en orden de preferencia:
    1. HDMI-CEC (cec-client) — apaga el TV de verdad. Sólo si el TV RESPONDE a
       una consulta CEC: que exista cec-client no alcanza (un monitor de PC no
       tiene CEC y el comando "funciona" igual, sin efecto).
    2. Señal HDMI (xrandr --off / --auto) — corta la señal: un monitor entra
       solo en reposo; un TV sin CEC muestra "sin señal". Funciona con el
       driver KMS que trae Raspberry Pi OS Bookworm.
    3. vcgencmd display_power — sólo como último recurso: con el driver KMS
       de Bookworm no tiene efecto, así que se avisa.
  Brillo: ver spui/brillo.py (backlight → DDC/CI → oscurecer la imagen).

Desde noviembre 2026 (decisiones de Mateo, 02/11/2026):
  - Con la hora todavía dudosa (spui/reloj.py) no se apaga por horario.
  - Encender por CEC es "a mejor esfuerzo": se manda al arrancar y en cada
    encendido, con dos reintentos a los 10 y 20 s por si el TV tarda en
    despertar. Nunca se consulta el estado para decidir.
  - Si el TV avisa por CEC que lo apagaron con el control en pleno horario,
    queda apagado hasta el próximo encendido del horario (spui/cec.py).

Nada falla en silencio: si un comando no tiene efecto o devuelve error, o si
la pantalla sólo se puede controlar en parte, diagnostico() lo devuelve y viaja
al CMS en el heartbeat (decisión de Mateo, 10/10/2026: avisar sólo cuando hay
una limitación y la pantalla tiene horario energético).

En una VM o en Windows nada de esto existe: el módulo entra en modo simulación
y sólo registra en el log lo que haría.
"""

import glob
import hashlib
import logging
import os
import re
import shutil
import subprocess
import threading
import time as _time
from dataclasses import dataclass
from datetime import datetime, time
from typing import Callable, Optional

from spui.brillo import ControlBrillo
from spui import resolucion as _resolucion
from spui.cec import ClienteCec
from spui.x11 import entorno_x

logger = logging.getLogger(__name__)

_BACKLIGHT = '/sys/class/backlight/rpi_backlight/brightness'

# Cómo se nombra cada método en los avisos del CMS.
_NOMBRES = {'cec': 'HDMI-CEC', 'senal': 'corte de señal HDMI', 'vcgencmd': 'vcgencmd'}

# Encender por CEC: la orden se repite a los 10 y a los 20 s.
_REINTENTOS_ENCENDIDO = 2
_ENTRE_REINTENTOS_SEG = 10
# Un "se apagó" del TV hasta este tiempo después de mandarle standby es el
# eco de nuestra propia orden, no alguien con el control.
_ECO_STANDBY_SEG = 90

# Mientras no se encuentre cómo controlar la pantalla (X arrancando, cable
# recién enchufado), cada cuánto se vuelve a intentar la detección completa.
_REDETECTAR_SIN_CONTROL_SEG = 2 * 60


@dataclass
class Capacidades:
    """Qué se puede apagar de la pantalla conectada, según la última detección."""
    cec_instalado: bool = False
    cec_responde: bool = False          # el TV contestó una consulta CEC
    salida_hdmi: Optional[str] = None   # salida conectada según xrandr (p. ej. "HDMI-1")
    vcgencmd: bool = False

    @property
    def metodo_apagado(self) -> str:
        if self.cec_responde:
            return 'cec'
        if self.salida_hdmi:
            return 'senal'
        if self.vcgencmd:
            return 'vcgencmd'
        return 'ninguno'


def _nivel(regla: dict) -> int:
    """Brillo de una regla, 0-100. Sin dato = 100; un 0 cargado en el CMS se respeta (no `or 100`)."""
    valor = regla.get('nivel_brillo')
    try:
        return max(0, min(100, int(valor))) if valor is not None else 100
    except (TypeError, ValueError):
        return 100


def _ejecutar_por_defecto(args: list[str], entrada: Optional[str] = None,
                          timeout: float = 10, env: Optional[dict] = None) -> tuple[int, str]:
    """Corre un programa y devuelve (código de salida, salida + errores)."""
    try:
        r = subprocess.run(args, input=entrada.encode() if entrada is not None else None,
                           capture_output=True, timeout=timeout, check=False, env=env)
        return r.returncode, (r.stdout + r.stderr).decode('utf-8', 'replace')
    except (OSError, subprocess.SubprocessError) as exc:
        return -1, str(exc)


def _firma_pantalla_por_defecto() -> str:
    """
    Huella de las pantallas conectadas: el EDID de cada conector HDMI que el
    kernel ya leyó al enchufar. Cambia si se conecta otra pantalla. Leer estos
    archivos no habla con la pantalla.
    """
    sha = hashlib.sha256()
    for estado in sorted(glob.glob('/sys/class/drm/card*-HDMI-A-*/status')):
        base = os.path.dirname(estado)
        try:
            with open(estado) as fh:
                conectado = fh.read().strip()
            sha.update(f'{base}={conectado};'.encode())
            if conectado == 'connected':
                with open(os.path.join(base, 'edid'), 'rb') as fh:
                    sha.update(fh.read())
        except OSError:
            continue
    return sha.hexdigest()


class GestorEnergia:
    """
    Mantiene el estado de la pantalla acorde al horario recibido del CMS.

    Es idempotente: aplicar() se puede llamar en cada ciclo de sync sin efectos
    secundarios, porque sólo actúa cuando el estado deseado cambia respecto del
    último aplicado.

    Los parámetros del constructor permiten simular cualquier pantalla en las
    pruebas sin hardware.
    """

    def __init__(self,
                 ejecutar: Callable[..., tuple[int, str]] = _ejecutar_por_defecto,
                 hay: Callable[[str], bool] = lambda b: shutil.which(b) is not None,
                 ruta_backlight: Optional[str] = _BACKLIGHT,
                 reloj: Callable[[], float] = _time.monotonic,
                 entorno: Callable[[], Optional[dict]] = entorno_x,
                 firma_pantalla: Callable[[], str] = _firma_pantalla_por_defecto,
                 ruta_estado_brillo: str = '/var/cache/spui/brillo.json',
                 brillo_software: Optional[Callable[[float], Optional[str]]] = None,
                 crear_cec: Optional[Callable[[Callable[[str], None]], Optional[ClienteCec]]] = None,
                 espera_reintento: float = _ENTRE_REINTENTOS_SEG,
                 resolucion: Optional[tuple[int, int]] = None,
                 al_cambiar_resolucion: Optional[Callable[[], None]] = None) -> None:
        self._ejecutar = ejecutar
        self._entorno = entorno
        self._hay = hay
        self._reloj = reloj
        self._firma_pantalla = firma_pantalla

        self._reglas: list[dict] = []
        self._encendida: Optional[bool] = None   # None = todavía no se aplicó nada
        self._senal_cortada = False
        # Fallos de la última acción de encendido/apagado: se borran solos
        # cuando vuelve a salir bien.
        self._fallas: dict[str, str] = {}

        # Escucha CEC (spui/cec.py): se arranca sólo si el TV responde a CEC.
        # main.py pasa ClienteCec; sin eso (las pruebas) no se escucha.
        self._crear_cec = crear_cec
        self._cec: Optional[ClienteCec] = None
        self._lock = threading.Lock()
        # El TV avisó que lo apagaron con el control, en horario de encendido.
        self._apagado_manual: Optional[str] = None     # la hora, para el aviso
        self._standby_pedido_en = -1e9                  # reloj() de nuestra última orden de apagar
        self._cancelar_reintentos = threading.Event()
        self._espera_reintento = espera_reintento
        self._ultima_hora: Optional[datetime] = None   # la de aplicar(), ya corregida
        # SPUI_RESOLUCION (spui/resolucion.py): la que se impone, y a quién
        # avisarle cuando cambia (la ventana del TV se rehace a ese tamaño).
        self._resolucion = resolucion
        self._al_cambiar_resolucion = al_cambiar_resolucion

        self.brillo = ControlBrillo(ejecutar, hay, ruta_backlight, ruta_estado_brillo, software=brillo_software)
        self.capacidades = Capacidades()
        self._detectado_en: Optional[float] = None
        self._firma: Optional[str] = None
        self.detectar()

    # ── API pública ───────────────────────────────────────────────────────

    def actualizar_reglas(self, reglas: Optional[list[dict]]) -> None:
        """Reemplaza el horario con el que vino en el último sync."""
        nuevas = reglas or []
        if nuevas != self._reglas:
            logger.info('Horario energético actualizado: %d día(s) configurado(s).', len(nuevas))
            self._reglas = nuevas

    def aplicar(self, ahora: Optional[datetime] = None, hay_alerta: bool = False,
                verificar_brillo: bool = False, hora_confiable: bool = True) -> None:
        """
        Evalúa el horario y ajusta la pantalla si hace falta.

        hay_alerta fuerza el encendido y el máximo brillo: una emergencia tiene
        que verse aunque el horario mande apagar. Al desactivarse la alerta, el
        siguiente aplicar() vuelve a respetar el horario.

        verificar_brillo: el ciclo acaba de sincronizar con el CMS; si hay una
        regla de brillo vigente, se compara con el brillo real de la pantalla
        (ver ControlBrillo.aplicar).

        hora_confiable: False mientras la hora no la confirmó el CMS ni NTP
        (spui/reloj.py). Ahí no se apaga por horario: con la hora equivocada
        se apagaría en pleno horario de clases.
        """
        self._redetectar_si_hace_falta()
        ahora = ahora or datetime.now()
        self._ultima_hora = ahora

        if hay_alerta:
            # Una emergencia enciende aunque lo hayan apagado con el control.
            if self._apagado_manual is not None:
                logger.info('Alerta: se enciende el TV aunque lo habían apagado con el control.')
                self._apagado_manual = None
                self._encendida = None
            self._asegurar_encendida(True)
            self.brillo.aplicar(None, alerta=True)
            return

        if not hora_confiable:
            self._asegurar_encendida(True)
            self.brillo.aplicar(None)
            return

        regla = self._regla_de_hoy(ahora)

        # Sin regla para hoy: encendida y con el brillo propio de la pantalla.
        if regla is None:
            self._asegurar_encendida(True)
            self.brillo.aplicar(None)
            return

        debe_estar_encendida = self._dentro_de_horario(regla, ahora.time())
        if not debe_estar_encendida and self._apagado_manual is not None:
            # Terminó el horario: el próximo encendido vuelve a ser normal.
            self._apagado_manual = None
        self._asegurar_encendida(debe_estar_encendida)
        if debe_estar_encendida:
            self.brillo.aplicar(_nivel(regla), verificar=verificar_brillo)

    def esta_apagada(self) -> bool:
        """True si el horario mandó apagar: el player no debería reproducir."""
        return self._encendida is False

    def en_simulacion(self) -> bool:
        """True si no hay forma de controlar el encendido de la pantalla."""
        return self.capacidades.metodo_apagado == 'ninguno'

    def detectar(self) -> Capacidades:
        """Averigua qué permite la pantalla conectada ahora mismo."""
        cap = Capacidades(cec_instalado=self._hay('cec-client'), vcgencmd=self._hay('vcgencmd'))
        if cap.cec_instalado:
            # Dos cec-client a la vez se disputan el adaptador: la escucha se
            # suelta durante la consulta y vuelve después si el TV responde.
            self._detener_escucha()
            cap.cec_responde = self._cec_estado() is not None
            if cap.cec_responde:
                self._iniciar_escucha()
        cap.salida_hdmi = self._salida_conectada()
        metodo_brillo = self.brillo.detectar()

        logger.info(
            'Pantalla: apagado por %s, brillo por %s (CEC instalado=%s, responde=%s, salida=%s%s).',
            cap.metodo_apagado, metodo_brillo, cap.cec_instalado, cap.cec_responde, cap.salida_hdmi,
            f', sin control de brillo: {self.brillo.motivo_sin_control}' if self.brillo.motivo_sin_control else '',
        )
        if self._detectado_en is not None and cap.metodo_apagado != self.capacidades.metodo_apagado:
            # Cambió la pantalla o su conexión: el próximo aplicar() vuelve a actuar.
            self._encendida = None
        self.capacidades = cap
        self._detectado_en = self._reloj()
        self._firma = self._firma_pantalla()
        self.aplicar_resolucion()
        return cap

    def aplicar_resolucion(self) -> None:
        """
        Deja la salida en SPUI_RESOLUCION si está configurada y no lo está ya.
        Un TV que no informa sus resoluciones arranca en 1024×768: ver
        spui/resolucion.py. Si no se puede, queda en el diagnóstico del CMS.
        """
        salida = self.capacidades.salida_hdmi
        if not self._resolucion or not salida or self._senal_cortada:
            return
        env = self._entorno()
        if env is None:
            return
        codigo, query = self._ejecutar(['xrandr', '--query'], None, 10, env)
        if codigo != 0:
            return
        ancho, alto = self._resolucion
        pasos, motivo = _resolucion.pasos(query, salida, ancho, alto, self._cvt)
        if motivo:
            self._fallas['resolucion'] = f'No se pudo poner la pantalla en {ancho}x{alto}: {motivo}.'
            return
        if not pasos:
            self._fallas.pop('resolucion', None)
            return
        logger.info('Se pone la pantalla en %dx%d (SPUI_RESOLUCION): el TV no la elige solo.', ancho, alto)
        for paso in pasos:
            error = self._xrandr(paso)
            # Un modo ya creado en esta sesión de X da error al volver a crearlo: no importa.
            if error and paso[0] != '--newmode':
                self._fallas['resolucion'] = f'No se pudo poner la pantalla en {ancho}x{alto} ({error}).'
                return
        self._fallas.pop('resolucion', None)
        if self._al_cambiar_resolucion is not None:
            self._al_cambiar_resolucion()

    def _cvt(self, ancho: int, alto: int) -> Optional[str]:
        """Los tiempos de un modo que no es estándar, calculados con cvt (si está)."""
        if not self._hay('cvt'):
            return None
        codigo, salida = self._ejecutar(['cvt', str(ancho), str(alto), '60'], None, 5)
        return _resolucion.cvt_desde(salida) if codigo == 0 else None

    def diagnostico(self) -> list[str]:
        """
        Problemas para el panel del CMS. Las limitaciones de la pantalla sólo se
        informan si tiene horario energético: sin horario no se pide nada que
        no se pueda hacer. Los fallos de una acción se informan siempre.
        """
        problemas = list(self._fallas.values())
        if self._apagado_manual is not None:
            problemas.append(
                f'El TV se apagó con su control a las {self._apagado_manual}: queda apagado hasta el '
                'próximo encendido del horario (o hasta que lo enciendan con el control).'
            )
        atenuadas = any(_nivel(r) < 100 for r in self._reglas)
        problemas.extend(self.brillo.diagnostico(hay_reglas_atenuadas=atenuadas))
        if not self._reglas:
            return problemas

        cap = self.capacidades
        metodo = cap.metodo_apagado
        if metodo == 'senal':
            motivo = ('la pantalla no responde a HDMI-CEC' if cap.cec_instalado
                      else 'no está instalado cec-utils, así que no se puede usar HDMI-CEC')
            problemas.append(
                f'Horario energético: {motivo}. Para apagarla se corta la señal HDMI: '
                'un monitor entra en reposo, pero un TV sin CEC queda encendido mostrando «sin señal».'
            )
        elif metodo == 'vcgencmd':
            problemas.append(
                'Horario energético: sin HDMI-CEC ni control de la señal (no se encontró la salida HDMI con xrandr). '
                'Se intenta con vcgencmd, que en Raspberry Pi OS Bookworm (driver KMS) no tiene efecto: '
                'la pantalla probablemente no se apague.'
            )
        elif metodo == 'ninguno':
            problemas.append(
                'Horario energético: no hay forma de controlar la pantalla (sin HDMI-CEC, sin xrandr ni vcgencmd). '
                'El horario se registra pero no se aplica.'
            )
        return problemas

    def detener(self) -> None:
        self._cancelar_reintentos.set()
        self._detener_escucha()

    # ── Escucha CEC (spui/cec.py) ─────────────────────────────────────────

    def _iniciar_escucha(self) -> None:
        if self._crear_cec is None:
            return
        cliente = self._crear_cec(self._aviso_del_tv)
        if cliente is not None and cliente.iniciar():
            self._cec = cliente

    def _detener_escucha(self) -> None:
        cliente, self._cec = self._cec, None
        if cliente is not None:
            cliente.detener()

    def _aviso_del_tv(self, estado: str) -> None:
        """Lo que avisa el TV por CEC. Corre en el hilo de la escucha."""
        with self._lock:
            if estado == 'standby':
                if self._encendida is not True or self._apagado_manual is not None:
                    return
                if self._reloj() - self._standby_pedido_en < _ECO_STANDBY_SEG:
                    return   # el eco de nuestra propia orden de apagar
                self._apagado_manual = (self._ultima_hora or datetime.now()).strftime('%H:%M')
                self._cancelar_reintentos.set()
                logger.info('El TV se apagó con su control: queda apagado hasta el próximo encendido del horario.')
            elif estado == 'on' and self._apagado_manual is not None:
                self._apagado_manual = None
                logger.info('El TV se volvió a encender: se retoma el horario energético normal.')

    # ── Detección ─────────────────────────────────────────────────────────

    def _redetectar_si_hace_falta(self) -> None:
        sin_control = not (self.capacidades.cec_responde or self.capacidades.salida_hdmi)
        if sin_control and self._reloj() - (self._detectado_en or 0) >= _REDETECTAR_SIN_CONTROL_SEG:
            self.detectar()
            return
        if self._firma_pantalla() != self._firma:
            logger.info('Cambió la pantalla conectada: se vuelve a detectar qué permite.')
            self.detectar()

    def _cec_estado(self) -> Optional[str]:
        """Estado de energía que informa el TV por CEC ('on', 'standby'…), o None si no responde."""
        codigo, salida = self._ejecutar(['cec-client', '-s', '-d', '1'], 'pow 0', 15)
        m = re.search(r'power status:\s*(.+)', salida)
        if codigo != 0 or not m:
            return None
        estado = m.group(1).strip().lower()
        return None if estado.startswith('unknown') else estado

    def _salida_conectada(self) -> Optional[str]:
        """Nombre de la salida de video conectada según xrandr (p. ej. 'HDMI-1'), o None."""
        if not self._hay('xrandr'):
            return None
        env = self._entorno()
        if env is None:
            return None
        codigo, salida = self._ejecutar(['xrandr', '--query'], None, 10, env)
        if codigo != 0:
            logger.debug('xrandr --query falló (%s): %s', codigo, salida.strip())
            return None
        m = re.search(r'^(\S+) connected', salida, re.MULTILINE)
        return m.group(1) if m else None

    # ── Lógica de horario ─────────────────────────────────────────────────

    def _regla_de_hoy(self, ahora: datetime) -> Optional[dict]:
        hoy = ahora.isoweekday()   # 1=Lunes … 7=Domingo, igual que el CMS
        for regla in self._reglas:
            if regla.get('dia_semana') == hoy:
                return regla
        return None

    @staticmethod
    def _parse_hora(valor: str, defecto: time) -> time:
        try:
            h, m = valor.split(':')[:2]
            return time(int(h), int(m))
        except (ValueError, AttributeError):
            logger.warning('Hora inválida en el horario energético: %r', valor)
            return defecto

    def _dentro_de_horario(self, regla: dict, ahora: time) -> bool:
        encendido = self._parse_hora(regla.get('hora_encendido'), time(0, 0))
        apagado   = self._parse_hora(regla.get('hora_apagado'), time(23, 59))

        # El CMS valida apagado > encendido, así que no hay rangos que crucen
        # la medianoche; aun así se contempla por robustez.
        if encendido <= apagado:
            return encendido <= ahora < apagado
        return ahora >= encendido or ahora < apagado

    # ── Encendido / apagado ───────────────────────────────────────────────

    def _asegurar_encendida(self, encendida: bool) -> None:
        if self._encendida == encendida:
            return
        if encendida and self._apagado_manual is not None:
            # Lo apagaron con el control: no se lo vuelve a encender (una
            # nueva detección de la pantalla pondría el estado en None).
            self._encendida = True
            return

        accion = 'ENCENDER' if encendida else 'APAGAR'
        metodo = self.capacidades.metodo_apagado
        if metodo == 'ninguno':
            logger.info('[SIM] %s pantalla (sin forma de controlarla).', accion)
            self._encendida = encendida
            return

        logger.info('%s pantalla por %s (horario energético).', accion, metodo)
        error = {'cec': self._por_cec, 'senal': self._por_senal, 'vcgencmd': self._por_vcgencmd}[metodo](encendida)

        # Si se había cortado la señal antes (otra pantalla, otra detección),
        # al encender se restablece igual.
        if encendida and self._senal_cortada and metodo != 'senal':
            error = error or self._por_senal(True)

        if error:
            logger.warning('No se pudo %s la pantalla: %s', accion.lower(), error)
            self._fallas['apagado'] = f'No se pudo {accion.lower()} la pantalla por {_NOMBRES[metodo]}: {error}.'
        else:
            self._fallas.pop('apagado', None)
        # Se registra igual el estado buscado: el player tiene que dejar de
        # reproducir en horario de apagado aunque el apagado haya fallado.
        self._encendida = encendida

    # Cada método devuelve None si salió bien, o el motivo del fallo.

    def _por_cec(self, encendida: bool) -> Optional[str]:
        orden = 'on 0' if encendida else 'standby 0'
        self._cancelar_reintentos.set()
        if not encendida:
            self._standby_pedido_en = self._reloj()
        cec = self._cec
        if cec is not None and cec.vivo():
            # Por la escucha: no se espera respuesta (a mejor esfuerzo).
            if not cec.enviar(orden):
                return 'se cortó la conexión HDMI-CEC con el TV'
            if encendida:
                self._reintentar_encendido()
            return None
        error = self._cec_una_vez(orden, encendida)
        if encendida:
            self._reintentar_encendido()
        return error

    def _reintentar_encendido(self) -> None:
        """
        Repite 'on' a los 10 y 20 s: un TV que recién recibe corriente o
        que está saliendo de reposo puede no atender la primera orden. Si ya
        está encendido, la ignora. Se cancela si llega otra orden o lo apagan
        con el control.
        """
        cancelar = threading.Event()
        self._cancelar_reintentos = cancelar

        def reintentar():
            for _ in range(_REINTENTOS_ENCENDIDO):
                if cancelar.wait(self._espera_reintento):
                    return
                cec = self._cec
                if cec is not None and cec.vivo():
                    cec.enviar('on 0')
                else:
                    self._ejecutar(['cec-client', '-s', '-d', '1'], 'on 0', 15)

        threading.Thread(target=reintentar, daemon=True, name='cec-encender').start()

    def _cec_una_vez(self, orden: str, encendida: bool) -> Optional[str]:
        """Sin escucha: un cec-client por orden, y se comprueba que el TV cambió."""
        codigo, salida = self._ejecutar(['cec-client', '-s', '-d', '1'], orden, 15)
        if codigo != 0:
            logger.warning('cec-client terminó con código %s: %s', codigo, salida.strip()[-400:])
            return 'el TV no aceptó el pedido por HDMI-CEC (detalle en el registro de la Pi)'
        # Comprobar que el TV realmente cambió (un comando aceptado no alcanza).
        estado = self._cec_estado()
        esperado = 'on' if encendida else 'standby'
        if estado is not None and not estado.startswith(esperado) and 'transition' not in estado:
            return f'el TV informa «{estado}» después de pedir «{esperado}»'
        return None

    def _por_senal(self, encendida: bool) -> Optional[str]:
        salida = self.capacidades.salida_hdmi
        if not salida:
            return 'no se encontró la salida HDMI con xrandr'
        error = self._xrandr(['--output', salida, '--auto' if encendida else '--off'])
        if error is None:
            self._senal_cortada = not encendida
            # --auto vuelve a la resolución "preferida" del TV, que en uno sin
            # EDID es 1024×768: se repone la de SPUI_RESOLUCION.
            if encendida:
                self.aplicar_resolucion()
        return error

    def _por_vcgencmd(self, encendida: bool) -> Optional[str]:
        codigo, salida = self._ejecutar(['vcgencmd', 'display_power', '1' if encendida else '0'], None, 5)
        if codigo != 0:
            logger.warning('vcgencmd terminó con código %s: %s', codigo, salida.strip()[-400:])
            return 'vcgencmd falló (detalle en el registro de la Pi)'
        return None

    def _xrandr(self, argumentos: list[str]) -> Optional[str]:
        env = self._entorno()
        if env is None:
            return 'no hay servidor gráfico X11 corriendo'
        codigo, salida = self._ejecutar(['xrandr', *argumentos], None, 10, env)
        if codigo != 0:
            logger.warning('xrandr %s terminó con código %s: %s', ' '.join(argumentos), codigo, salida.strip()[-400:])
            return 'no se pudo cambiar la señal HDMI (detalle en el registro de la Pi)'
        return None
