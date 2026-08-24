"""
Gestión energética de la pantalla (CU-11).

Aplica el horario que viene en el sync: enciende y apaga la pantalla a la hora
programada y ajusta el brillo. El horario llega en pantallas[].energia como una
lista de reglas, una por día configurado:

    {"dia_semana": 1, "hora_encendido": "07:30", "hora_apagado": "21:00", "nivel_brillo": 80}

dia_semana va en ISO (1=Lunes … 7=Domingo), igual que datetime.isoweekday().
Un día sin regla significa "sin gestión": la pantalla queda encendida.

Mecanismos de control, en orden de preferencia:
  1. HDMI-CEC (cec-client)  — apaga el televisor de verdad, es lo que se usa en
     el campus. Requiere `sudo apt install cec-utils` y un TV con CEC habilitado.
  2. vcgencmd display_power — corta la salida HDMI del Pi. El TV queda encendido
     mostrando "sin señal", así que ahorra menos, pero funciona sin CEC.
  3. Brillo por software (DPMS/backlight) donde esté disponible.

NOTA Pi-only: en una VM o en Windows nada de esto existe. En ese caso el módulo
entra en modo simulación y sólo registra en el log lo que haría, para poder
probar la lógica de horarios sin hardware.
"""

import logging
import shutil
import subprocess
from datetime import datetime, time

logger = logging.getLogger(__name__)

# Ruta del backlight en Raspberry Pi OS (puede no existir según el panel)
_BACKLIGHT = '/sys/class/backlight/rpi_backlight/brightness'


def _hay(binario: str) -> bool:
    return shutil.which(binario) is not None


class GestorEnergia:
    """
    Mantiene el estado de la pantalla acorde al horario recibido del CMS.

    Es idempotente: aplicar() se puede llamar en cada ciclo de sync sin efectos
    secundarios, porque sólo actúa cuando el estado deseado cambia respecto del
    último aplicado.
    """

    def __init__(self) -> None:
        self._reglas: list[dict] = []
        self._encendida: bool | None = None   # None = todavía no se aplicó nada
        self._brillo: int | None = None
        self._simulacion = not (_hay('cec-client') or _hay('vcgencmd'))

        if self._simulacion:
            logger.warning(
                'Sin cec-client ni vcgencmd — gestión energética en modo SIMULACIÓN '
                '(se registra en el log, no se controla hardware).'
            )

    # ── API pública ───────────────────────────────────────────────────────

    def actualizar_reglas(self, reglas: list[dict] | None) -> None:
        """Reemplaza el horario con el que vino en el último sync."""
        nuevas = reglas or []
        if nuevas != self._reglas:
            logger.info('Horario energético actualizado: %d día(s) configurado(s).', len(nuevas))
            self._reglas = nuevas

    def aplicar(self, ahora: datetime | None = None, hay_alerta: bool = False) -> None:
        """
        Evalúa el horario y ajusta la pantalla si hace falta.

        hay_alerta fuerza el encendido al 100%: una emergencia tiene que verse
        aunque el horario mande apagar. Al desactivarse la alerta, el siguiente
        aplicar() vuelve a respetar el horario.
        """
        ahora = ahora or datetime.now()

        if hay_alerta:
            self._asegurar_encendida(True)
            self._asegurar_brillo(100)
            return

        regla = self._regla_de_hoy(ahora)

        # Sin regla para hoy: la pantalla queda encendida al 100%.
        if regla is None:
            self._asegurar_encendida(True)
            self._asegurar_brillo(100)
            return

        debe_estar_encendida = self._dentro_de_horario(regla, ahora.time())
        self._asegurar_encendida(debe_estar_encendida)
        if debe_estar_encendida:
            self._asegurar_brillo(int(regla.get('nivel_brillo', 100)))

    def esta_apagada(self) -> bool:
        """True si el horario mandó apagar: el player no debería reproducir."""
        return self._encendida is False

    def en_simulacion(self) -> bool:
        """
        True si no hay forma de controlar la pantalla (faltan cec-client y
        vcgencmd). Se informa al CMS: el horario energético se guarda y se
        evalúa, pero la pantalla nunca se apaga de verdad, y eso tiene que
        poder verse desde el panel.
        """
        return self._simulacion

    # ── Lógica de horario ─────────────────────────────────────────────────

    def _regla_de_hoy(self, ahora: datetime) -> dict | None:
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

    # ── Control del hardware ──────────────────────────────────────────────

    def _asegurar_encendida(self, encendida: bool) -> None:
        if self._encendida == encendida:
            return

        accion = 'ENCENDER' if encendida else 'APAGAR'
        if self._simulacion:
            logger.info('[SIM] %s pantalla.', accion)
        else:
            logger.info('%s pantalla (horario energético).', accion)
            self._cec(encendida)
            self._hdmi(encendida)

        self._encendida = encendida

    def _asegurar_brillo(self, nivel: int) -> None:
        nivel = max(0, min(100, nivel))
        if self._brillo == nivel:
            return

        if self._simulacion:
            logger.info('[SIM] brillo al %d%%.', nivel)
        else:
            logger.info('Brillo al %d%%.', nivel)
            self._backlight(nivel)

        self._brillo = nivel

    def _cec(self, encendida: bool) -> None:
        if not _hay('cec-client'):
            return
        cmd = 'on 0' if encendida else 'standby 0'
        try:
            subprocess.run(
                ['cec-client', '-s', '-d', '1'],
                input=cmd.encode(),
                timeout=10,
                capture_output=True,
                check=False,
            )
        except (OSError, subprocess.SubprocessError) as exc:
            logger.warning('CEC falló (%s): %s', cmd, exc)

    def _hdmi(self, encendida: bool) -> None:
        if not _hay('vcgencmd'):
            return
        try:
            subprocess.run(
                ['vcgencmd', 'display_power', '1' if encendida else '0'],
                timeout=5,
                capture_output=True,
                check=False,
            )
        except (OSError, subprocess.SubprocessError) as exc:
            logger.warning('vcgencmd display_power falló: %s', exc)

    def _backlight(self, nivel: int) -> None:
        # 0-100 % → 0-255, que es la escala del backlight oficial del Pi
        valor = round(nivel * 255 / 100)
        try:
            with open(_BACKLIGHT, 'w') as fh:
                fh.write(str(valor))
        except OSError:
            # Sin panel con backlight controlable: no es un error, es lo normal
            # en un TV por HDMI (ahí el brillo se maneja desde el propio TV).
            logger.debug('Backlight no disponible (%s), brillo no aplicado.', _BACKLIGHT)
