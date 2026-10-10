"""
Brillo de la pantalla según el horario energético, con cualquier pantalla.

Se detecta qué permite la pantalla y se usa lo mejor que haya (decisión de
Mateo, 10/10/2026):
  1. backlight  — el display oficial de la Pi (/sys/class/backlight).
  2. ddc        — brillo real del monitor por DDC/CI (spui/ddc.py). Baja la
                  retroiluminación: ahorra energía.
  3. software   — VLC oscurece la imagen (Player.ajustar_brillo). Anda con
                  cualquier pantalla, pero no ahorra energía; se avisa.
xrandr --brightness NO se usa: con el driver KMS de la Pi 4 responde bien e
informa el valor nuevo, pero la imagen no cambia (probado en hardware real).

Con un control real (1 y 2) el brillo que dejó quien instaló la pantalla es la
REFERENCIA (decisiones de Mateo):
  - El % del CMS es relativo a esa referencia: con el monitor en 50, una regla
    de 80 % lo deja en 40. Sin regla, el monitor queda en la referencia.
  - Un cambio hecho a mano en el monitor se detecta (ver revisar_cambio_manual):
      · fuera de una regla, pasa a ser la nueva referencia y se guarda en disco;
      · durante una regla, se deja y se avisa, pero la regla del CMS manda: a
        los 30 s se vuelve al brillo de la regla.
  - Para una alerta se usa el máximo del monitor: una emergencia tiene que verse.
"""

import json
import logging
import os
import threading
from dataclasses import dataclass
from typing import Callable, Optional

from spui import ddc

logger = logging.getLogger(__name__)

VOLVER_A_LA_REGLA_SEG = 30


@dataclass
class _Control:
    """Un control de brillo real: el backlight o un monitor por DDC/CI."""
    metodo: str            # 'backlight' | 'ddc'
    identidad: str         # para guardar la referencia de ESTA pantalla
    leer: Callable[[], tuple[Optional[tuple[int, int]], Optional[str]]]
    escribir: Callable[[int], Optional[str]]
    hubo_cambio: Callable[[], Optional[bool]]          # VCP 0x02, None si no lo implementa
    bajar_aviso: Callable[[], None]


class ControlBrillo:
    def __init__(self, ejecutar, hay: Callable[[str], bool], ruta_backlight: Optional[str],
                 ruta_estado: str,
                 software: Optional[Callable[[float], Optional[str]]] = None,
                 temporizador: Callable[[float, Callable[[], None]], object] = None) -> None:
        self._ejecutar = ejecutar
        self._hay = hay
        self._ruta_backlight = ruta_backlight
        self._ruta_estado = ruta_estado
        self._software = software
        self._temporizador = temporizador or _temporizador_real
        self._lock = threading.RLock()

        self.metodo = 'ninguno'
        self.motivo_sin_control: Optional[str] = None   # por qué no hay control real
        self._control: Optional[_Control] = None
        self._maximo = 100
        self._referencia: Optional[int] = None
        self._ultimo_escrito: Optional[int] = None
        self._objetivo: Optional[int] = None
        self._en_regla = False
        self._revertir_pendiente = None
        self._fallas: dict[str, str] = {}
        self._aviso_manual: Optional[str] = None
        self._nivel_software = 100   # la imagen arranca sin oscurecer

    # ── Detección ─────────────────────────────────────────────────────────

    def detectar(self) -> str:
        with self._lock:
            control, motivo = self._buscar_control()
            if control is not None:
                lectura, error = control.leer()
                if lectura is None:
                    control, motivo = None, error
            if control is None:
                self._control = None
                self.motivo_sin_control = motivo
                self.metodo = 'software' if self._software else 'ninguno'
                return self.metodo

            actual, maximo = lectura
            # Si antes se oscurecía la imagen (otra pantalla, o ésta sin DDC/CI),
            # se deshace: ahora el brillo lo maneja la propia pantalla.
            self._aplicar_software(100)
            cambio_de_pantalla = self._control is None or self._control.identidad != control.identidad
            self._control, self._maximo, self.metodo, self.motivo_sin_control = control, maximo, control.metodo, None
            if cambio_de_pantalla:
                self._cargar_estado(actual)
            return self.metodo

    def _buscar_control(self) -> tuple[Optional[_Control], Optional[str]]:
        if self._ruta_backlight and os.path.exists(self._ruta_backlight):
            return self._control_backlight(), None
        if not self._hay('ddcutil'):
            return None, 'no está instalado ddcutil (lo instala install.sh)'
        monitor, motivo = ddc.detectar(self._ejecutar)
        if monitor is None:
            return None, motivo
        e = self._ejecutar
        return _Control(
            'ddc', monitor.identidad,
            leer=lambda: ddc.leer_brillo(e, monitor),
            escribir=lambda v: ddc.escribir_brillo(e, monitor, v),
            hubo_cambio=lambda: ddc.hubo_cambio_manual(e, monitor),
            bajar_aviso=lambda: ddc.bajar_aviso_de_cambio(e, monitor),
        ), None

    def _control_backlight(self) -> _Control:
        ruta = self._ruta_backlight
        ruta_max = os.path.join(os.path.dirname(ruta), 'max_brightness')

        def leer():
            try:
                with open(ruta) as a, open(ruta_max) as m:
                    return (int(a.read().strip()), int(m.read().strip())), None
            except (OSError, ValueError) as exc:
                return None, f'no se pudo leer el backlight: {exc}'

        def escribir(valor):
            try:
                with open(ruta, 'w') as fh:
                    fh.write(str(valor))
                return None
            except OSError as exc:
                return f'no se pudo escribir el backlight: {exc}'

        return _Control('backlight', 'backlight ' + ruta, leer, escribir, lambda: None, lambda: None)

    # ── Aplicar ───────────────────────────────────────────────────────────

    def aplicar(self, nivel: Optional[int], alerta: bool = False) -> None:
        """
        nivel: % del CMS (0-100) si hay una regla vigente; None si no hay regla
        (la pantalla queda en su referencia). alerta=True: máximo brillo.
        """
        with self._lock:
            en_regla = nivel is not None and not alerta
            if self._control is None:
                self._aplicar_software(100 if (alerta or nivel is None) else nivel)
                self._en_regla = en_regla
                return

            # Momento clave: antes de escribir se mira si alguien lo cambió a mano.
            self.revisar_cambio_manual(_ya_tengo_lock=True)
            if self._revertir_pendiente is not None and en_regla == self._en_regla:
                return   # cambio manual en una regla: se respeta hasta que venza el plazo
            self._cancelar_vuelta()

            if alerta:
                objetivo = self._maximo
            elif nivel is None:
                objetivo = self._referencia
            else:
                objetivo = round(self._referencia * max(0, min(100, nivel)) / 100)
            self._en_regla = en_regla
            self._escribir(objetivo)

    def pausar(self) -> None:
        """La pantalla se apaga por horario: no tiene sentido volver a la regla en un monitor en reposo."""
        with self._lock:
            self._cancelar_vuelta()

    def revisar_cambio_manual(self, _ya_tengo_lock: bool = False) -> None:
        """
        Compara el brillo real del monitor con el último que puso el sistema. Si
        difiere, alguien lo cambió a mano.

        Si el monitor implementa el aviso VCP 0x02 se lee primero ese byte y el
        brillo sólo si está levantado. Se llama en los momentos clave (antes de
        cada cambio de brillo, que es cada vez que llega el horario o se edita en
        el CMS) y la puede llamar quien quiera vigilar más seguido.
        """
        if not _ya_tengo_lock:
            with self._lock:
                return self.revisar_cambio_manual(_ya_tengo_lock=True)
        if self._control is None or self._ultimo_escrito is None:
            return
        aviso = self._control.hubo_cambio()
        if aviso is False:
            return   # el monitor dice que nadie tocó nada
        lectura, error = self._control.leer()
        if aviso:
            self._control.bajar_aviso()
        if lectura is None:
            self._fallas['leer'] = error
            return
        self._fallas.pop('leer', None)
        actual = lectura[0]
        if actual == self._ultimo_escrito:
            return

        if not self._en_regla:
            logger.info('Brillo cambiado a mano en el monitor: %s → %s. Pasa a ser la referencia.',
                        self._ultimo_escrito, actual)
            self._referencia = actual
            self._ultimo_escrito = actual
            self._guardar_estado()
            return

        logger.info('Brillo cambiado a mano en el monitor (%s) durante una regla: en %d s se vuelve a %s.',
                    actual, VOLVER_A_LA_REGLA_SEG, self._objetivo)
        self._aviso_manual = (
            f'Se cambió a mano el brillo del monitor ({actual} de {self._maximo}). '
            f'Hay una regla del horario energético: en {VOLVER_A_LA_REGLA_SEG} s vuelve a {self._objetivo}.'
        )
        self._ultimo_escrito = actual
        if self._revertir_pendiente is None:
            self._revertir_pendiente = self._temporizador(VOLVER_A_LA_REGLA_SEG, self._volver_a_la_regla)

    def _volver_a_la_regla(self) -> None:
        with self._lock:
            self._revertir_pendiente = None
            self._aviso_manual = None
            if self._en_regla and self._objetivo is not None:
                logger.info('Se restablece el brillo de la regla (%s).', self._objetivo)
                self._escribir(self._objetivo)

    def _cancelar_vuelta(self) -> None:
        if self._revertir_pendiente is not None:
            cancelar = getattr(self._revertir_pendiente, 'cancel', None)
            if cancelar:
                cancelar()
            self._revertir_pendiente = None
            self._aviso_manual = None

    def _escribir(self, objetivo: int) -> None:
        self._objetivo = objetivo
        if objetivo == self._ultimo_escrito:
            return
        error = self._control.escribir(objetivo)
        if error is None:
            # Se lee de vuelta: un comando aceptado no alcanza (así se descubrió
            # que xrandr --brightness no tiene efecto en la Pi 4).
            lectura, error = self._control.leer()
            if lectura is not None and lectura[0] != objetivo:
                error = f'se pidió {objetivo} y el monitor quedó en {lectura[0]}'
        if error:
            logger.warning('No se pudo aplicar el brillo por %s: %s', self.metodo, error)
            nombre = {'ddc': 'DDC/CI', 'backlight': 'el backlight'}.get(self.metodo, self.metodo)
            self._fallas['escribir'] = f'No se pudo aplicar el brillo por {nombre}: {error}.'
            return
        self._fallas.pop('escribir', None)
        logger.info('Brillo del monitor en %s de %s (referencia %s) por %s.',
                    objetivo, self._maximo, self._referencia, self.metodo)
        self._ultimo_escrito = objetivo
        self._guardar_estado()

    def _aplicar_software(self, nivel: int) -> None:
        if not self._software or nivel == self._nivel_software:
            return
        error = self._software(nivel / 100)
        self._nivel_software = None if error else nivel
        if error:
            self._fallas['escribir'] = f'No se pudo oscurecer la imagen: {error}'
        else:
            self._fallas.pop('escribir', None)

    # ── Diagnóstico ───────────────────────────────────────────────────────

    def diagnostico(self, hay_reglas_atenuadas: bool) -> list[str]:
        with self._lock:
            problemas = list(self._fallas.values())
            if self._aviso_manual:
                problemas.append(self._aviso_manual)
            if hay_reglas_atenuadas and self._control is None:
                motivo = self.motivo_sin_control or 'la pantalla no permite controlar su brillo'
                if self.metodo == 'software':
                    problemas.append(
                        f'Brillo: {motivo}. Se oscurece la imagen en su lugar, '
                        'que no reduce el consumo de la pantalla.'
                    )
                else:
                    problemas.append(f'Brillo: {motivo}. El brillo programado no se puede aplicar.')
            return problemas

    # ── Referencia guardada en disco ──────────────────────────────────────

    def _cargar_estado(self, actual: int) -> None:
        guardado = self._leer_archivo().get(self._control.identidad)
        if guardado and isinstance(guardado.get('referencia'), int):
            self._referencia = guardado['referencia']
            self._ultimo_escrito = guardado.get('ultimo_escrito')
            if self._ultimo_escrito is not None and actual != self._ultimo_escrito:
                # Lo tocaron con el servicio apagado: no fue durante una regla
                # que este proceso esté aplicando, así que es la nueva referencia.
                logger.info('El brillo del monitor cambió mientras el servicio no corría (%s → %s): nueva referencia.',
                            self._ultimo_escrito, actual)
                self._referencia = actual
            self._ultimo_escrito = actual
        else:
            logger.info('Pantalla nueva (%s): el brillo de referencia es el que tiene ahora, %s de %s.',
                        self._control.identidad, actual, self._maximo)
            self._referencia = actual
            self._ultimo_escrito = actual
        self._guardar_estado()

    def _leer_archivo(self) -> dict:
        try:
            with open(self._ruta_estado, encoding='utf-8') as fh:
                datos = json.load(fh)
            return datos if isinstance(datos, dict) else {}
        except (OSError, ValueError):
            return {}

    def _guardar_estado(self) -> None:
        if self._control is None:
            return
        datos = self._leer_archivo()
        datos[self._control.identidad] = {'referencia': self._referencia, 'ultimo_escrito': self._ultimo_escrito,
                                          'maximo': self._maximo}
        temporal = self._ruta_estado + '.tmp'
        try:
            os.makedirs(os.path.dirname(self._ruta_estado) or '.', exist_ok=True)
            with open(temporal, 'w', encoding='utf-8') as fh:
                json.dump(datos, fh, indent=1)
            os.replace(temporal, self._ruta_estado)
        except OSError as exc:
            logger.warning('No se pudo guardar el brillo de referencia: %s', exc)


def _temporizador_real(segundos: float, funcion: Callable[[], None]):
    t = threading.Timer(segundos, funcion)
    t.daemon = True
    t.start()
    return t
