"""
Cómo se ve un contenido en el TV (noviembre 2026): la misma pantalla que la
vista previa del CMS (templates/pantalla/_tv.html.twig), dibujada con Pillow.

Las medidas, colores y reglas salen de assets/spui/css/pantalla-tv.css y de
SPUI\\Service\\PantallaTvService: si cambia una, hay que cambiar la otra.

  - Plantilla institucional (franja con el logo y la fecha, filete de colores
    y pie con el lugar) o sin plantilla (de borde a borde, con marca de agua
    opcional).
  - Tema claro u oscuro; el automático es claro de 07:00 a 19:00 (el horario
    lo manda el CMS en cada sync).
  - Cronograma: lo de hoy que todavía no terminó, en páginas armadas por
    altura. Nada se corta ni se achica (decisión de Mateo, 02/11/2026): una
    materia larga baja de renglón y la fila crece hacia abajo.

Este módulo no sabe nada de pygame ni de VLC: devuelve imágenes, y quien las
muestra (spui/ventana.py, o VLC como resguardo) decide cómo. Así se puede
probar en la PC, sin pantalla.
"""

import hashlib
import json
import os
import re
from dataclasses import dataclass, field
from datetime import datetime, timedelta
from typing import Callable, Optional

from PIL import Image, ImageDraw, ImageFilter, ImageFont

ANCHO, ALTO = 1920, 1080

_ASSETS = os.path.join(os.path.dirname(__file__), '..', 'assets')

# Colores de la marca (unraf-estilos) y de pantalla-tv.css.
AZUL      = (0x44, 0x66, 0x87)
AZUL_OSC  = (0x24, 0x39, 0x4f)
AMARILLO  = (0xFE, 0xAA, 0x20)
TURQUESA  = (0x1C, 0xA1, 0xA7)
BLANCO    = (255, 255, 255)
NEGRO     = (0, 0, 0)
GRIS_CAB  = (0x2b, 0x3a, 0x4a)

# Por tema: (color, opacidad) de cada cosa.
TEMAS = {
    'clara': {
        'fondo': (0xf4, 0xf7, 0xfa), 'texto': (0x1f, 0x2d, 0x3b),
        'pie': ((0xe6, 0xec, 0xf2), 1.0),
        'fila': (BLANCO, 1.0), 'curso': ((0xe7, 0xf6, 0xf6), 1.0), 'sombra': True,
        'aula': AZUL,
    },
    'oscura': {
        'fondo': None, 'texto': BLANCO,   # fondo: degradé, ver _fondo()
        'pie': (NEGRO, .18),
        'fila': (BLANCO, .07), 'curso': (TURQUESA, .28), 'sombra': False,
        'aula': AMARILLO,
    },
}

DIAS  = ('Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo')
MESES = ('enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto',
         'septiembre', 'octubre', 'noviembre', 'diciembre')

SEG_PAGINA_DEFECTO = 10


# ── Reglas (las de PantallaTvService) ─────────────────────────────────────────

def tema_efectivo(tema: Optional[str], ahora: datetime, horario: Optional[dict]) -> str:
    """'clara' u 'oscura'. El automático, según la hora (claro de 07:00 a 19:00)."""
    if tema == 'claro':
        return 'clara'
    if tema == 'oscuro':
        return 'oscura'
    horario = horario or {}
    claro = horario.get('claro_desde') or '07:00'
    oscuro = horario.get('oscuro_desde') or '19:00'
    hora = ahora.strftime('%H:%M')
    return 'clara' if claro <= hora < oscuro else 'oscura'


def fecha_legible(dia: datetime) -> str:
    """'Lunes 2 de noviembre', sin depender del locale del sistema."""
    return f'{DIAS[dia.weekday()]} {dia.day} de {MESES[dia.month - 1]}'


def _clave_aula(aula: Optional[str]):
    """Orden natural, como strnatcasecmp: el aula 2 antes que la 19."""
    return [int(p) if p.isdigit() else p for p in re.split(r'(\d+)', (aula or '').lower())]


def filas_del_dia(cronograma: dict, ahora: datetime) -> tuple[list[dict], Optional[str]]:
    """
    Lo que se muestra ahora: las actividades de hoy que todavía no terminaron,
    por hora de inicio y aula. Devuelve (filas, motivo si no hay ninguna).

    El sync manda la semana entera (clases con sus días y eventos con su
    fecha): elegir el día acá hace que la pantalla pase sola de un día al
    otro y que sin conexión siga mostrando lo que corresponde.
    """
    if cronograma.get('vacio'):
        return [], cronograma['vacio']

    hoy = ahora.strftime('%Y-%m-%d')
    desde, hasta = cronograma.get('vigencia_desde'), cronograma.get('vigencia_hasta')
    if (desde and hoy < desde) or (hasta and hoy > hasta):
        return [], 'El cronograma no está vigente hoy.'

    hora = ahora.strftime('%H:%M')
    bit = 1 << ahora.weekday()
    filas = []
    for it in cronograma.get('items') or []:
        if not isinstance(it, dict) or not it.get('inicio'):
            continue
        if it.get('fecha'):
            if it['fecha'] != hoy:
                continue
        elif not (int(it.get('dias') or 0) & bit):
            continue
        fin = it.get('fin') or None
        # Sin hora de fin (un evento "desde las 18:00"): sigue hasta que
        # termina el día.
        if fin is not None and fin <= hora:
            continue
        fila = dict(it)
        fila['fin'] = fin
        fila['curso'] = it['inicio'] <= hora
        filas.append(fila)

    filas.sort(key=lambda f: (f['inicio'], _clave_aula(f.get('aula'))))
    return filas, (None if filas else 'No quedan actividades por hoy.')


def cronograma_de(contenido: dict) -> dict:
    """
    El cronograma de un contenido. Un CMS anterior a noviembre 2026 manda sólo
    'cronograma_items' (lo de hoy, sin días): se arma el formato nuevo con eso.
    """
    cr = contenido.get('cronograma')
    if isinstance(cr, dict):
        return cr
    items = []
    for it in contenido.get('cronograma_items') or []:
        if isinstance(it, dict):
            items.append({
                'dias': 127, 'fecha': None, 'inicio': it.get('hora_inicio'), 'fin': it.get('hora_fin') or None,
                'nombre': it.get('nombre'), 'docente': None, 'meta': '', 'aula': it.get('aula'), 'evento': False,
            })
    return {'edificio': None, 'segundos_por_pagina': SEG_PAGINA_DEFECTO, 'items': items}


def firma(contenido: dict) -> str:
    """Cambia si cambia algo de lo que se dibuja (para saber si recargar)."""
    datos = {k: contenido.get(k) for k in ('titulo', 'tipo', 'contenido_texto', 'hash_archivo', 'presentacion', 'cronograma')}
    return hashlib.sha1(json.dumps(datos, sort_keys=True, ensure_ascii=False).encode('utf-8')).hexdigest()[:16]


# ── Lo que se entrega ─────────────────────────────────────────────────────────

@dataclass
class Lamina:
    """
    Una pantalla lista para mostrar.

    `fondo` es la imagen completa (1920 × 1080). En un cronograma con más de
    una página, `fondo` no trae las filas: van en `paginas` (transparentes,
    del tamaño de `zona`), y los puntos de avance del pie en `avances`
    (del tamaño de `zona_avance`). Así quien muestra puede deslizar sólo la
    tabla, como la vista previa del CMS, sin redibujar nada.
    """
    fondo: Image.Image
    zona: Optional[tuple[int, int, int, int]] = None          # x, y, ancho, alto
    paginas: list = field(default_factory=list)
    zona_avance: Optional[tuple[int, int, int, int]] = None
    avances: list = field(default_factory=list)
    segundos: int = SEG_PAGINA_DEFECTO
    # Para no volver a dibujar si nada cambió (fecha, hora, tema, contenido).
    clave: str = ''

    @property
    def cantidad(self) -> int:
        return max(1, len(self.paginas))

    def plana(self, i: int = 0) -> Image.Image:
        """La página i como una sola imagen (para mostrarla sin pygame)."""
        if not self.paginas:
            return self.fondo
        img = self.fondo.convert('RGBA')
        img.alpha_composite(self.paginas[i], self.zona[:2])
        if self.avances:
            img.alpha_composite(self.avances[i], self.zona_avance[:2])
        return img.convert('RGB')


# ── El dibujante ──────────────────────────────────────────────────────────────

class Dibujante:
    def __init__(self, assets_dir: str = _ASSETS):
        self._assets = assets_dir
        self._fuentes: dict = {}
        self._imagenes: dict = {}
        self._degrade: Optional[Image.Image] = None

    # ── Entrada principal ─────────────────────────────────────────────────

    def lamina(
        self,
        contenido: dict,
        ahora: datetime,
        lugar: Optional[str] = None,
        horario_tema: Optional[dict] = None,
        archivo: Optional[str] = None,
    ) -> Lamina:
        """
        La pantalla de un contenido (texto, imagen, qr o cronograma) a esta
        hora. `archivo` es la imagen ya descargada (imagen o el PNG del QR).
        """
        tipo = contenido.get('tipo') or ''
        pres = contenido.get('presentacion') or {}
        # Un CMS sin presentación (anterior a noviembre 2026): como se veía
        # antes, de borde a borde y oscuro.
        plantilla = pres.get('plantilla') or 'ninguna'
        tema = tema_efectivo(pres.get('tema') or 'oscuro', ahora, horario_tema)
        institucional = plantilla == 'institucional'
        cr = cronograma_de(contenido) if tipo == 'cronograma' else None
        if cr is not None:
            lugar = cr.get('edificio') or lugar

        lienzo = self._fondo(tema, tipo == 'imagen' and not institucional)
        if institucional:
            self._cabecera(lienzo, ahora)
            self._pie(lienzo, tema, lugar)
            caja = (0, 132, ANCHO, 1016) if tipo == 'imagen' else (64, 168, 1856, 992)
        else:
            caja = (0, 0, ANCHO, ALTO) if tipo == 'imagen' else (64, 64, 1856, 1016)

        lam = Lamina(fondo=lienzo)
        color = TEMAS[tema]['texto']
        if tipo == 'texto':
            self._texto(lienzo, caja, contenido.get('contenido_texto') or '', color)
        elif tipo == 'imagen':
            self._imagen(lienzo, caja, archivo)
        elif tipo == 'qr':
            self._qr(lienzo, caja, contenido.get('titulo') or '', archivo, color)
        elif cr is not None:
            lam = self._cronograma(lienzo, caja, cr, contenido.get('titulo') or '', tema, ahora, institucional)

        if not institucional and pres.get('marca_agua'):
            self._marca_agua(lam.fondo)
        lam.fondo = lam.fondo.convert('RGB')
        return lam

    def de_imagen(self, ruta: str) -> Lamina:
        """Una imagen suelta a pantalla completa, sin recortar (la de espera, la de alerta)."""
        lienzo = Image.new('RGBA', (ANCHO, ALTO), NEGRO + (255,))
        self._imagen(lienzo, (0, 0, ANCHO, ALTO), ruta)
        return Lamina(fondo=lienzo.convert('RGB'))

    # ── Fuentes, imágenes y primitivas ────────────────────────────────────

    def _fuente(self, tam: float, negrita: bool = True):
        """Museo 700 o 300, las de la marca, empaquetadas en assets/."""
        clave = (round(tam), negrita)
        if clave not in self._fuentes:
            nombre = 'Museo700-Regular.otf' if negrita else 'Museo300-Regular.otf'
            for ruta in (os.path.join(self._assets, nombre),
                         os.path.join(self._assets, 'Museo700-Regular.otf'),
                         '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'):
                try:
                    self._fuentes[clave] = ImageFont.truetype(ruta, round(tam))
                    break
                except OSError:
                    continue
            else:
                self._fuentes[clave] = ImageFont.load_default()
        return self._fuentes[clave]

    def _imagen_asset(self, nombre: str) -> Optional[Image.Image]:
        if nombre not in self._imagenes:
            try:
                self._imagenes[nombre] = Image.open(os.path.join(self._assets, nombre)).convert('RGBA')
            except OSError:
                self._imagenes[nombre] = None
        return self._imagenes[nombre]

    @staticmethod
    def _capa(destino: Image.Image, caja, color, opacidad: float, dibujar: Callable) -> None:
        """
        Pinta algo de un color con su opacidad, bien mezclado sobre lo que hay
        abajo (también sobre una página transparente). `dibujar(d, dx, dy)`
        dibuja en blanco sobre una máscara corrida en (dx, dy).
        """
        x0, y0 = max(0, int(caja[0])), max(0, int(caja[1]))
        x1, y1 = min(destino.width, int(caja[2]) + 1), min(destino.height, int(caja[3]) + 1)
        if x1 <= x0 or y1 <= y0 or opacidad <= 0:
            return
        mascara = Image.new('L', (x1 - x0, y1 - y0), 0)
        dibujar(ImageDraw.Draw(mascara), -x0, -y0)
        if opacidad < 1:
            mascara = mascara.point(lambda a: round(a * opacidad))
        solido = Image.new('RGBA', mascara.size, tuple(color[:3]) + (255,))
        solido.putalpha(mascara)
        destino.alpha_composite(solido, (x0, y0))

    def _rect(self, destino, caja, color, opacidad=1.0, radio=0) -> None:
        x0, y0, x1, y1 = caja
        self._capa(destino, caja, color, opacidad, lambda d, dx, dy: d.rounded_rectangle(
            (x0 + dx, y0 + dy, x1 + dx - 1, y1 + dy - 1), radius=radio, fill=255))

    def _ancho(self, texto: str, fuente, espaciado: float = 0) -> float:
        return fuente.getlength(texto) + espaciado * len(texto)

    def _escribir(self, destino, x, y_linea, alto_linea, texto, fuente, color, opacidad=1.0, espaciado=0.0) -> None:
        """
        Una línea de texto con su caja de renglón como en CSS: `y_linea` es el
        borde de arriba del renglón y el texto queda centrado en su alto.
        """
        if not texto:
            return
        asc, desc = fuente.getmetrics()
        base = y_linea + (alto_linea - (asc + desc)) / 2 + asc
        ancho = self._ancho(texto, fuente, espaciado)
        caja = (x - 4, base - asc - 4, x + ancho + 4, base + desc + 4)

        def dibujar(d, dx, dy):
            if not espaciado:
                d.text((x + dx, base + dy), texto, font=fuente, fill=255, anchor='ls')
                return
            cx = x
            for letra in texto:
                d.text((cx + dx, base + dy), letra, font=fuente, fill=255, anchor='ls')
                cx += fuente.getlength(letra) + espaciado

        self._capa(destino, caja, color, opacidad, dibujar)

    def _envolver(self, texto: str, fuente, ancho: float, cortes: str = '', partir: bool = True) -> list[str]:
        """
        Parte el texto en renglones que entran en `ancho`, entre palabras. Los
        caracteres de `cortes` (la "/" de "AGRO/BIO/IC") también son un lugar
        donde bajar. Una palabra sola más ancha que todo se parte por letras
        sólo si `partir` (overflow-wrap: anywhere de la materia y el docente).
        """
        renglones: list[str] = []
        for parrafo in (texto or '').split('\n'):
            piezas: list[str] = []
            for palabra in parrafo.split():
                partes = re.split('(?<=[' + re.escape(cortes) + '])', palabra) if cortes else [palabra]
                partes = [p for p in partes if p]
                for i, p in enumerate(partes):
                    piezas.append(p + ('' if i < len(partes) - 1 else ' '))
            actual = ''
            for pieza in piezas:
                prueba = actual + pieza
                if not actual or self._ancho(prueba.rstrip(), fuente) <= ancho:
                    actual = prueba
                    continue
                renglones.append(actual.rstrip())
                actual = pieza
            if actual or not piezas:
                renglones.append(actual.rstrip())

        if not partir:
            return renglones
        salida = []
        for r in renglones:
            while self._ancho(r, fuente) > ancho and len(r) > 1:
                n = len(r)
                while n > 1 and self._ancho(r[:n], fuente) > ancho:
                    n -= 1
                salida.append(r[:n])
                r = r[n:]
            salida.append(r)
        return salida

    # ── Fondo, cabecera y pie ─────────────────────────────────────────────

    def _fondo(self, tema: str, negro: bool) -> Image.Image:
        if negro:
            return Image.new('RGBA', (ANCHO, ALTO), NEGRO + (255,))
        if tema == 'clara':
            return Image.new('RGBA', (ANCHO, ALTO), TEMAS['clara']['fondo'] + (255,))
        if self._degrade is None:
            self._degrade = self._armar_degrade()
        return self._degrade.copy()

    @staticmethod
    def _armar_degrade() -> Image.Image:
        """linear-gradient(160deg, #2c4762 0%, #24394f 70%), como en CSS."""
        import math
        w, h = ANCHO // 10, ALTO // 10
        ang = math.radians(160)
        sx, sy = math.sin(ang), -math.cos(ang)
        largo = abs(ANCHO * sx) + abs(ALTO * sy)
        a, b = (0x2c, 0x47, 0x62), AZUL_OSC
        datos = []
        for j in range(h):
            for i in range(w):
                x, y = (i + .5) * 10 - ANCHO / 2, (j + .5) * 10 - ALTO / 2
                t = min(1.0, max(0.0, (x * sx + y * sy) / largo + .5) / .7)
                datos.append(tuple(round(a[k] + (b[k] - a[k]) * t) for k in range(3)) + (255,))
        chico = Image.new('RGBA', (w, h))
        chico.putdata(datos)
        return chico.resize((ANCHO, ALTO), Image.BILINEAR)

    def _cabecera(self, lienzo, ahora: datetime) -> None:
        self._rect(lienzo, (0, 0, ANCHO, 124), BLANCO)
        logo = self._imagen_asset('unraf.png')
        if logo is not None:
            alto = 76
            logo = logo.resize((round(logo.width * alto / logo.height), alto), Image.LANCZOS)
            lienzo.alpha_composite(logo, (64, (124 - alto) // 2))
        f = self._fuente(40)
        texto = fecha_legible(ahora)
        self._escribir(lienzo, ANCHO - 64 - self._ancho(texto, f), 0, 124, texto, f, GRIS_CAB)
        # Filete: amarillo, turquesa y azul.
        for i, c in enumerate((AMARILLO, TURQUESA, AZUL)):
            self._rect(lienzo, (i * ANCHO // 3, 124, (i + 1) * ANCHO // 3, 132), c)

    def _pie(self, lienzo, tema: str, lugar: Optional[str]) -> None:
        color, op = TEMAS[tema]['pie']
        self._rect(lienzo, (0, 1016, ANCHO, ALTO), color, op)
        texto = TEMAS[tema]['texto']
        fb, fl = self._fuente(24), self._fuente(24, False)
        self._escribir(lienzo, 64, 1016, 64, 'SPUI', fb, texto, .8)
        self._escribir(lienzo, 64 + self._ancho('SPUI', fb), 1016, 64, ' · Sistema de Pantallas UNRaf', fl, texto, .8)
        if lugar:
            self._escribir(lienzo, ANCHO - 64 - self._ancho(lugar, fl), 1016, 64, lugar, fl, texto, .8)

    def _marca_agua(self, lienzo) -> None:
        """El molino abajo a la derecha, en un círculo blanco (opacidad .85)."""
        capa = Image.new('RGBA', (96, 96), (0, 0, 0, 0))
        ImageDraw.Draw(capa).ellipse((0, 0, 95, 95), fill=BLANCO + (255,))
        molino = self._imagen_asset('unraf-molino.png')
        if molino is not None:
            capa.alpha_composite(molino.resize((76, 76), Image.LANCZOS), (10, 10))
        capa.putalpha(capa.getchannel('A').point(lambda a: round(a * .85)))
        lienzo.alpha_composite(capa, (ANCHO - 40 - 96, ALTO - 36 - 96))

    # ── Texto, imagen y QR ────────────────────────────────────────────────

    def _texto(self, lienzo, caja, texto: str, color) -> None:
        """Centrado; la letra baja de a 4 px hasta que entra todo (mínimo 28)."""
        x0, y0, x1, y1 = caja
        # pre-line: se respetan los saltos de renglón, no los espacios de más.
        texto = '\n'.join(' '.join(r.split()) for r in (texto or '').replace('\r', '').split('\n'))
        ancho = min(1600, x1 - x0)
        tam = 104
        while True:
            f = self._fuente(tam)
            renglones = self._envolver(texto, f, ancho)
            alto_r = tam * 1.15
            if tam <= 28 or alto_r * len(renglones) <= y1 - y0:
                break
            tam -= 4
        y = y0 + ((y1 - y0) - alto_r * len(renglones)) / 2
        for r in renglones:
            self._escribir(lienzo, (x0 + x1) / 2 - self._ancho(r, f) / 2, y, alto_r, r, f, color)
            y += alto_r

    def _imagen(self, lienzo, caja, ruta: Optional[str]) -> None:
        """La imagen entera en su espacio, sin recortar (object-fit: contain)."""
        if not ruta or not os.path.exists(ruta):
            return
        try:
            img = Image.open(ruta)
            img.load()
            img = img.convert('RGBA')
        except Exception:
            return
        x0, y0, x1, y1 = caja
        escala = min((x1 - x0) / img.width, (y1 - y0) / img.height)
        tam = (max(1, round(img.width * escala)), max(1, round(img.height * escala)))
        img = img.resize(tam, Image.LANCZOS)
        lienzo.alpha_composite(img, (round(x0 + (x1 - x0 - tam[0]) / 2), round(y0 + (y1 - y0 - tam[1]) / 2)))

    def _qr(self, lienzo, caja, titulo: str, ruta: Optional[str], color) -> None:
        x0, y0, x1, y1 = caja
        # Texto: "Escaneá con tu celular" y el título, achicándolo hasta 640 px de alto.
        fk = self._fuente(32)
        kicker = 'ESCANEÁ CON TU CELULAR'
        esp = 32 * .12
        ancho_k = self._ancho(kicker, fk, esp)
        tam = 80
        while True:
            ft = self._fuente(tam)
            renglones = self._envolver(titulo, ft, 900)
            alto_r = tam * 1.1
            if tam <= 28 or alto_r * len(renglones) <= 640:
                break
            tam -= 4
        ancho_t = max([ancho_k] + [self._ancho(r, ft) for r in renglones])
        alto_k = 32 * 1.2 + 12 + 6
        alto_txt = alto_k + 40 + alto_r * len(renglones)

        marco = 672
        total = ancho_t + 120 + marco
        x = (x0 + x1) / 2 - total / 2
        y_txt = (y0 + y1) / 2 - alto_txt / 2
        self._escribir(lienzo, x, y_txt, 32 * 1.2, kicker, fk, TURQUESA, 1, esp)
        self._rect(lienzo, (x, y_txt + 32 * 1.2 + 12, x + ancho_k, y_txt + alto_k), TURQUESA)
        y = y_txt + alto_k + 40
        for r in renglones:
            self._escribir(lienzo, x, y, alto_r, r, ft, color)
            y += alto_r

        # El código, en un marco blanco con sombra.
        mx, my = x + ancho_t + 120, (y0 + y1) / 2 - marco / 2
        sombra = Image.new('RGBA', (marco + 120, marco + 120), (0, 0, 0, 0))
        ImageDraw.Draw(sombra).rounded_rectangle((60, 60, 60 + marco, 60 + marco), radius=28, fill=(20, 35, 55, 46))
        sombra = sombra.filter(ImageFilter.GaussianBlur(20))
        lienzo.alpha_composite(sombra, (round(mx - 60), round(my - 60 + 16)))
        self._rect(lienzo, (mx, my, mx + marco, my + marco), BLANCO, 1, 28)
        self._imagen(lienzo, (mx + 36, my + 36, mx + 36 + 600, my + 36 + 600), ruta)

    # ── Cronograma ────────────────────────────────────────────────────────

    # Columnas de la tabla (grid-template-columns: 280px 1fr 200px 290px).
    _COL_HORA, _COL_CAR, _COL_AULA, _HUECO = 280, 200, 290, 16

    def _columnas(self, x0: float, x1: float) -> list[tuple[float, float]]:
        mat = x1 - x0 - self._COL_HORA - self._COL_CAR - self._COL_AULA - 3 * self._HUECO
        anchos = (self._COL_HORA, mat, self._COL_CAR, self._COL_AULA)
        cols, x = [], x0
        for a in anchos:
            cols.append((x, a))
            x += a + self._HUECO
        return cols

    def _cronograma(self, lienzo, caja, cr: dict, titulo: str, tema: str, ahora: datetime, institucional: bool) -> Lamina:
        x0, y0, x1, y1 = caja
        t = TEMAS[tema]
        color = t['texto']
        filas, vacio = filas_del_dia(cr, ahora)
        seg = int(cr.get('segundos_por_pagina') or SEG_PAGINA_DEFECTO)

        # Título: "CRONOGRAMA DE HOY" y el edificio.
        fs = self._fuente(28)
        self._escribir(lienzo, x0, y0, 28 * 1.2, 'CRONOGRAMA DE HOY', fs, TURQUESA, 1, 28 * .12)
        fh = self._fuente(64)
        nombre = cr.get('edificio') or titulo
        y_h1 = y0 + 28 * 1.2 + 6
        self._escribir(lienzo, x0, y_h1, 64, nombre, fh, color)
        y = y_h1 + 64 + 20

        if not filas:
            fv = self._fuente(48)
            renglones = self._envolver(vacio or '', fv, x1 - x0)
            alto_r = 48 * 1.2
            yv = y + ((y1 - y) - alto_r * len(renglones)) / 2
            for r in renglones:
                self._escribir(lienzo, (x0 + x1) / 2 - self._ancho(r, fv) / 2, yv, alto_r, r, fv, color, .6)
                yv += alto_r
            return Lamina(fondo=lienzo, segundos=seg)

        # Referencias, a la derecha del título y alineadas abajo.
        fr = self._fuente(26, False)
        cuenta = f"{len(filas)} {'actividad' if len(filas) == 1 else 'actividades'} por delante"
        piezas = [('En curso', TURQUESA), ('Evento especial', AMARILLO), (cuenta, None)]
        anchos = [(22 if c else 0) + self._ancho(s, fr) for s, c in piezas]
        xr = x1 - sum(anchos) - 36 * (len(piezas) - 1)
        y_ref = y_h1 + 64 - 26 * 1.2
        for (s, c), a in zip(piezas, anchos):
            if c:
                self._rect(lienzo, (xr, y_ref + 2, xr + 10, y_ref + 32), c, 1, 4)
                self._escribir(lienzo, xr + 22, y_ref, 26 * 1.2, s, fr, color)
            else:
                self._escribir(lienzo, xr, y_ref, 26 * 1.2, s, fr, color, .65)
            xr += a + 36

        # Cabecera de la tabla.
        fc = self._fuente(22)
        cols = self._columnas(x0 + 12, x1 - 12)
        for (cx, ca), s in zip(cols, ('HORARIO', 'MATERIA · DOCENTE', 'CARRERA · AÑO', 'AULA')):
            esp = 22 * .1
            self._escribir(lienzo, cx + ca / 2 - self._ancho(s, fc, esp) / 2, y, 22 * 1.2, s, fc, color, .55, esp)
        y += 22 * 1.2 + 10

        # Las filas, repartidas en páginas por altura (como vista-tv.js).
        zona = (int(x0), int(round(y)), int(x1 - x0), int(y1 - round(y)))
        cols_fila = self._columnas(10 + 12, zona[2] - 12)
        medidas = [self._medir_fila(f, cols_fila) for f in filas]
        paginas, usado = [[]], 0.0
        for f, m in zip(filas, medidas):
            if paginas[-1] and usado + 8 + m['alto'] > zona[3]:
                paginas.append([])
                usado = 0.0
            usado += (8 if paginas[-1] else 0) + m['alto']
            paginas[-1].append((f, m))

        capas = []
        for pagina in paginas:
            capa = Image.new('RGBA', zona[2:], (0, 0, 0, 0))
            yf = 0.0
            for f, m in pagina:
                self._fila(capa, yf, f, m, t, color, cols_fila)
                yf += m['alto'] + 8
            capas.append(capa)

        if len(capas) == 1:
            lienzo.alpha_composite(capas[0], zona[:2])
            return Lamina(fondo=lienzo, segundos=seg)

        lam = Lamina(fondo=lienzo, zona=zona, paginas=capas, segundos=seg)
        if institucional:
            self._avances(lam, color)
        return lam

    def _medir_fila(self, f: dict, cols) -> dict:
        """Renglones de cada celda y el alto de la fila (mínimo 84, 8 arriba y abajo)."""
        fm, fd = self._fuente(32), self._fuente(23, False)
        fcar, fau = self._fuente(27), self._fuente(44)
        fe = self._fuente(18)
        ancho_m = cols[1][1]
        etiqueta = 'EVENTO ESPECIAL'
        ancho_et = self._ancho(etiqueta, fe, 18 * .08) + 24
        renglones_m = self._envolver(f.get('nombre') or '', fm, ancho_m)
        if f.get('evento'):
            # La etiqueta va a continuación del nombre; si no entra, abajo.
            ult = renglones_m[-1] if renglones_m else ''
            if renglones_m and self._ancho(ult, fm) + 14 + ancho_et <= ancho_m:
                renglones_m[-1] = (ult, True)
            else:
                renglones_m.append(('', True))
        docente = self._envolver(f['docente'], fd, ancho_m) if f.get('docente') else []
        car = self._envolver(f.get('meta') or '', fcar, cols[2][1], '/', partir=False) if f.get('meta') else []
        aula = self._envolver(f.get('aula') or '', fau, cols[3][1], '/', partir=False) if f.get('aula') else []
        alto_mat = len(renglones_m) * 32 * 1.15 + (4 + len(docente) * 23 * 1.2 if docente else 0)
        altos = (38 * 1.2, alto_mat, len(car) * 27 * 1.2, len(aula) * 44 * 1.1)
        return {'mat': renglones_m, 'doc': docente, 'car': car, 'aula': aula, 'altos': altos,
                'ancho_et': ancho_et, 'alto': max(84.0, max(altos) + 16)}

    def _fila(self, capa, y, f: dict, m: dict, t: dict, color, cols) -> None:
        ancho = capa.width
        alto = m['alto']
        fondo, op = t['curso'] if f.get('curso') else t['fila']
        if t['sombra']:
            self._rect(capa, (0, y + 2, ancho, y + alto + 2), (20, 35, 55), .06, 14)
        self._rect(capa, (0, y, ancho, y + alto), fondo, op, 14)
        borde = AMARILLO if f.get('evento') else (TURQUESA if f.get('curso') else None)
        if borde:
            # border-left de 10 px con las esquinas redondeadas de la fila.
            def dibujar(d, dx, dy):
                d.rounded_rectangle((dx, y + dy, ancho - 1 + dx, y + alto - 1 + dy), radius=14, fill=255)
                d.rectangle((10 + dx, y + dy, ancho + dx, y + alto + dy), fill=0)
            self._capa(capa, (0, y, 14, y + alto), borde, 1, dibujar)

        def centrar(col, alto_celda):
            return y + (alto - alto_celda) / 2

        # Horario.
        cx, ca = cols[0]
        fh = self._fuente(38)
        yh = centrar(0, m['altos'][0])
        if f.get('fin'):
            a, guion, b = f['inicio'], '–', f['fin']
            total = self._ancho(a, fh) + 20 + self._ancho(guion, fh) + self._ancho(b, fh)
            xh = cx + ca / 2 - total / 2
            self._escribir(capa, xh, yh, 38 * 1.2, a, fh, color)
            xh += self._ancho(a, fh) + 10
            self._escribir(capa, xh, yh, 38 * 1.2, guion, fh, color, .5)
            xh += self._ancho(guion, fh) + 10
            self._escribir(capa, xh, yh, 38 * 1.2, b, fh, color)
        else:
            s = 'Desde las ' + f['inicio']
            self._escribir(capa, cx + ca / 2 - self._ancho(s, fh) / 2, yh, 38 * 1.2, s, fh, color)

        # Materia (con la etiqueta de evento) y docente debajo.
        cx, ca = cols[1]
        fm, fd, fe = self._fuente(32), self._fuente(23, False), self._fuente(18)
        ym = centrar(1, m['altos'][1])
        for r in m['mat']:
            texto, etiqueta = (r if isinstance(r, tuple) else (r, False))
            ancho_r = self._ancho(texto, fm) + ((14 if texto else 0) + m['ancho_et'] if etiqueta else 0)
            xm = cx + ca / 2 - ancho_r / 2
            self._escribir(capa, xm, ym, 32 * 1.15, texto, fm, color)
            if etiqueta:
                xe = xm + (self._ancho(texto, fm) + 14 if texto else 0)
                alto_e = 18 * 1.2 + 10
                ye = ym + (32 * 1.15 - alto_e) / 2 - 2
                self._rect(capa, (xe, ye, xe + m['ancho_et'], ye + alto_e), AMARILLO, 1, 16)
                self._escribir(capa, xe + 12, ye + 5, 18 * 1.2, 'EVENTO ESPECIAL', fe, GRIS_CAB, 1, 18 * .08)
            ym += 32 * 1.15
        if m['doc']:
            ym += 4
            for r in m['doc']:
                self._escribir(capa, cx + ca / 2 - self._ancho(r, fd) / 2, ym, 23 * 1.2, r, fd, color, .7)
                ym += 23 * 1.2

        # Carrera · año y aula.
        for i, (clave, fuente, lh, col, op) in enumerate((
            ('car', self._fuente(27), 27 * 1.2, color, .8),
            ('aula', self._fuente(44), 44 * 1.1, t['aula'], 1),
        )):
            cx, ca = cols[2 + i]
            yc = centrar(2 + i, m['altos'][2 + i])
            for r in m[clave]:
                self._escribir(capa, cx + ca / 2 - self._ancho(r, fuente) / 2, yc, lh, r, fuente, col, op)
                yc += lh

    def _avances(self, lam: Lamina, color) -> None:
        """Los puntos de cada página y "2 / 5", en el medio del pie."""
        n = len(lam.paginas)
        f = self._fuente(24, False)
        ancho_txt = self._ancho(f'{n} / {n}', f)
        ancho = n * 14 + (n - 1) * 12 + 12 + 8 + ancho_txt
        x0 = round(ANCHO / 2 - ancho / 2)
        lam.zona_avance = (x0, 1016, int(ancho) + 8, 64)
        for i in range(n):
            capa = Image.new('RGBA', lam.zona_avance[2:], (0, 0, 0, 0))
            for k in range(n):
                cx = k * 26
                self._capa(capa, (cx, 25, cx + 14, 39), color, 1 if k == i else .3,
                           lambda d, dx, dy, cx=cx: d.ellipse((cx + dx, 25 + dy, cx + 13 + dx, 38 + dy), fill=255))
            self._escribir(capa, n * 26 - 12 + 12 + 8, 0, 64, f'{i + 1} / {n}', f, color, .8)
            lam.avances.append(capa)


def para_mostrar(contenido: dict) -> bool:
    """Si este cliente lo dibuja con el TV (todo menos el video)."""
    return (contenido.get('tipo') or '') in ('texto', 'imagen', 'qr', 'cronograma')


def proximo_cambio(ahora: datetime) -> datetime:
    """El próximo minuto en punto: ahí puede cambiar lo que dice un cronograma o el tema."""
    return (ahora + timedelta(minutes=1)).replace(second=0, microsecond=0)
