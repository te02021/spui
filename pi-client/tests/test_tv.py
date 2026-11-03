"""
El TV de la Pi (noviembre 2026): spui/tv.py dibuja lo mismo que la vista
previa del CMS y spui/ventana.py lo muestra.

  - El sync manda la semana: la Pi elige lo de hoy y lo que todavía no terminó.
  - Tema automático: claro de 07:00 a 19:00 (el horario lo manda el CMS).
  - Las páginas se arman por altura y ninguna fila se pierde ni se corta.
  - El deslizamiento entre páginas usa la curva del CSS.

Correr desde la carpeta del cliente:
    python -m unittest discover -s tests -v
"""

import os
import sys
import threading
import unittest
from datetime import datetime
from importlib.util import find_spec
from unittest import mock

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..'))

_PIL = find_spec('PIL') is not None
if _PIL:
    from spui import tv  # noqa: E402
from spui import ventana  # noqa: E402

LUNES_18 = datetime(2026, 11, 2, 18, 0)     # lunes


def _item(inicio, fin, dias=1, fecha=None, nombre='Materia', aula='1', **extra):
    return dict({'dias': dias, 'fecha': fecha, 'inicio': inicio, 'fin': fin, 'nombre': nombre,
                 'docente': None, 'meta': '', 'aula': aula, 'evento': fecha is not None}, **extra)


@unittest.skipUnless(_PIL, 'Pillow no instalado')
class ReglasTest(unittest.TestCase):
    def test_elige_las_clases_de_hoy_por_dia_de_la_semana(self):
        cr = {'items': [_item('19:00', '21:00', dias=1, nombre='Lunes'), _item('19:00', '21:00', dias=2, nombre='Martes')]}
        filas, _ = tv.filas_del_dia(cr, LUNES_18)
        self.assertEqual([f['nombre'] for f in filas], ['Lunes'])

    def test_un_evento_sale_solo_en_su_fecha(self):
        cr = {'items': [_item('19:00', None, fecha='2026-11-02', nombre='Hoy'), _item('19:00', None, fecha='2026-11-03', nombre='Mañana')]}
        filas, _ = tv.filas_del_dia(cr, LUNES_18)
        self.assertEqual([f['nombre'] for f in filas], ['Hoy'])

    def test_lo_que_termino_no_ocupa_lugar_y_lo_empezado_esta_en_curso(self):
        cr = {'items': [_item('14:00', '18:00', nombre='Terminó'), _item('17:00', '20:00', nombre='En curso'),
                        _item('19:00', '21:00', nombre='Después')]}
        filas, _ = tv.filas_del_dia(cr, LUNES_18)
        self.assertEqual([(f['nombre'], f['curso']) for f in filas], [('En curso', True), ('Después', False)])

    def test_sin_hora_de_fin_sigue_hasta_que_termina_el_dia(self):
        cr = {'items': [_item('10:00', None, fecha='2026-11-02', nombre='Desde las 10')]}
        filas, _ = tv.filas_del_dia(cr, datetime(2026, 11, 2, 23, 30))
        self.assertEqual(len(filas), 1)

    def test_ordena_por_hora_y_aula_en_orden_natural(self):
        cr = {'items': [_item('19:00', '21:00', aula='19'), _item('19:00', '21:00', aula='2'), _item('18:30', '21:00', aula='30')]}
        filas, _ = tv.filas_del_dia(cr, LUNES_18)
        self.assertEqual([f['aula'] for f in filas], ['30', '2', '19'])

    def test_fuera_de_vigencia_lo_dice(self):
        cr = {'vigencia_hasta': '2026-11-01', 'items': [_item('19:00', '21:00')]}
        self.assertEqual(tv.filas_del_dia(cr, LUNES_18), ([], 'El cronograma no está vigente hoy.'))

    def test_sin_actividades_lo_dice(self):
        self.assertEqual(tv.filas_del_dia({'items': []}, LUNES_18)[1], 'No quedan actividades por hoy.')
        self.assertEqual(tv.filas_del_dia({'vacio': 'Sin cronograma.'}, LUNES_18)[1], 'Sin cronograma.')

    def test_tema_automatico_por_la_hora(self):
        self.assertEqual(tv.tema_efectivo('automatico', datetime(2026, 11, 2, 18, 59), None), 'clara')
        self.assertEqual(tv.tema_efectivo('automatico', datetime(2026, 11, 2, 19, 0), None), 'oscura')
        self.assertEqual(tv.tema_efectivo('automatico', datetime(2026, 11, 2, 6, 59), None), 'oscura')
        self.assertEqual(tv.tema_efectivo('automatico', datetime(2026, 11, 2, 18, 0), {'oscuro_desde': '18:00'}), 'oscura')
        self.assertEqual(tv.tema_efectivo('claro', datetime(2026, 11, 2, 23, 0), None), 'clara')

    def test_fecha_en_castellano_sin_depender_del_sistema(self):
        self.assertEqual(tv.fecha_legible(LUNES_18), 'Lunes 2 de noviembre')

    def test_un_cms_viejo_sin_semana_se_sigue_viendo(self):
        cr = tv.cronograma_de({'cronograma_items': [{'nombre': 'X', 'aula': '1', 'hora_inicio': '19:00', 'hora_fin': ''}]})
        filas, _ = tv.filas_del_dia(cr, LUNES_18)
        self.assertEqual(len(filas), 1)
        self.assertIsNone(filas[0]['fin'])


@unittest.skipUnless(_PIL, 'Pillow no instalado')
class DibujoTest(unittest.TestCase):
    def setUp(self):
        self.d = tv.Dibujante()

    def _cronograma(self, n, nombre='Materia', plantilla='institucional'):
        items = [_item('19:00', '21:00', nombre=f'{nombre} {i}', aula=str(i), docente='Docente') for i in range(n)]
        return {'tipo': 'cronograma', 'titulo': 'Cronograma', 'presentacion': {'plantilla': plantilla, 'tema': 'claro'},
                'cronograma': {'edificio': 'Campus E4', 'segundos_por_pagina': 7, 'items': items}}

    def test_todas_las_filas_entran_en_alguna_pagina(self):
        c = self._cronograma(30)
        lam = self.d.lamina(c, LUNES_18, 'Campus E4')
        self.assertGreater(lam.cantidad, 1)
        self.assertEqual(lam.segundos, 7)
        self.assertEqual(len(lam.avances), lam.cantidad)
        for p in lam.paginas:
            self.assertEqual(p.size, lam.zona[2:])
        # Cada página tiene filas dibujadas y la última no queda vacía.
        for p in lam.paginas:
            self.assertIsNotNone(p.getbbox())

    def test_una_materia_larga_baja_de_renglon_y_no_se_corta(self):
        fuente = self.d._fuente(32)
        largo = 'Introducción a la programación orientada a objetos y estructuras de datos avanzadas para ingeniería'
        renglones = self.d._envolver(largo, fuente, 600)
        self.assertGreater(len(renglones), 1)
        self.assertEqual(' '.join(renglones), largo)
        for r in renglones:
            self.assertLessEqual(fuente.getlength(r), 600)

    def test_el_aula_baja_entre_palabras_o_despues_de_la_barra(self):
        fuente = self.d._fuente(44)
        self.assertEqual(self.d._envolver('Sala de Reuniones Sur', fuente, 290, '/', partir=False),
                         ['Sala de', 'Reuniones', 'Sur'])
        self.assertEqual(''.join(self.d._envolver('AGRO/BIO/IC/DEP', self.d._fuente(27), 120, '/', partir=False)),
                         'AGRO/BIO/IC/DEP')

    def test_una_fila_alta_hace_mas_paginas_pero_no_pierde_nada(self):
        cortas = self.d.lamina(self._cronograma(12), LUNES_18)
        largas = self.d.lamina(self._cronograma(12, nombre='Materia con un nombre larguísimo ' * 3), LUNES_18)
        self.assertGreaterEqual(largas.cantidad, cortas.cantidad)

    def test_pocas_filas_una_sola_pagina_sin_puntos(self):
        lam = self.d.lamina(self._cronograma(2), LUNES_18)
        self.assertEqual((lam.cantidad, lam.paginas, lam.avances), (1, [], []))

    def test_sin_plantilla_no_hay_puntos_de_avance(self):
        lam = self.d.lamina(self._cronograma(30, plantilla='ninguna'), LUNES_18)
        self.assertGreater(lam.cantidad, 1)
        self.assertEqual(lam.avances, [])

    def test_cada_tipo_da_una_pantalla_completa(self):
        for c in ({'tipo': 'texto', 'contenido_texto': 'Hola ' * 300, 'presentacion': {'plantilla': 'institucional', 'tema': 'oscuro'}},
                  {'tipo': 'qr', 'titulo': 'Encuesta', 'presentacion': {'plantilla': 'ninguna', 'tema': 'claro', 'marca_agua': True}},
                  {'tipo': 'imagen', 'presentacion': {'plantilla': 'institucional', 'tema': 'automatico'}},
                  {'tipo': 'texto', 'contenido_texto': None}):
            lam = self.d.lamina(c, LUNES_18, 'Campus E4')
            self.assertEqual(lam.fondo.size, (1920, 1080))
            self.assertEqual(lam.plana(0).mode, 'RGB')

    def test_la_firma_cambia_con_la_presentacion_y_las_actividades(self):
        c = self._cronograma(3)
        a = tv.firma(c)
        c['presentacion']['tema'] = 'oscuro'
        self.assertNotEqual(a, tv.firma(c))
        b = tv.firma(c)
        c['cronograma']['items'].pop()
        self.assertNotEqual(b, tv.firma(c))


@unittest.skipUnless(_PIL, 'Pillow no instalado')
class HoraTest(unittest.TestCase):
    """La hora en vivo (03/11/2026): un recuadro que se repinta solo cada minuto."""

    def test_con_plantilla_va_en_la_cabecera_y_sin_plantilla_en_una_pildora(self):
        d = tv.Dibujante()
        con = d.lamina({'tipo': 'texto', 'contenido_texto': 'Hola', 'presentacion': {'plantilla': 'institucional'}}, LUNES_18, con_hora=True)
        sin = d.lamina({'tipo': 'texto', 'contenido_texto': 'Hola', 'presentacion': {'plantilla': 'ninguna'}}, LUNES_18, con_hora=True)
        self.assertLess(con.zona_hora[1] + con.zona_hora[3], 125)     # dentro de la franja blanca
        self.assertGreater(sin.zona_hora[0], 1600)                    # arriba a la derecha
        self.assertEqual((con.hora_dibujada, sin.hora_dibujada), ('18:00', '18:00'))

    def test_el_recuadro_cambia_con_el_minuto_y_es_del_tamano_de_su_zona(self):
        lam = tv.Dibujante().lamina({'tipo': 'texto', 'contenido_texto': 'Hola', 'presentacion': {'plantilla': 'institucional'}}, LUNES_18, con_hora=True)
        a, b = lam.con_hora('18:00'), lam.con_hora('18:01')
        self.assertEqual(a.size, lam.zona_hora[2:])
        self.assertNotEqual(a.tobytes(), b.tobytes())

    def test_sin_pedirla_no_hay_hora(self):
        lam = tv.Dibujante().lamina({'tipo': 'texto', 'contenido_texto': 'Hola', 'presentacion': {'plantilla': 'institucional'}}, LUNES_18)
        self.assertIsNone(lam.zona_hora)


@unittest.skipUnless(_PIL, 'Pillow no instalado')
class EsperaTest(unittest.TestCase):
    """La pantalla de espera institucional (03/11/2026)."""

    def test_con_la_ventana_lleva_la_hora_y_por_vlc_no(self):
        d = tv.Dibujante()
        con = d.espera(LUNES_18, 'Campus E4', con_hora=True)
        sin = d.espera(LUNES_18, 'Campus E4')
        self.assertEqual(con.fondo.size, (1920, 1080))
        self.assertIsNotNone(con.zona_hora)
        self.assertIsNone(sin.zona_hora)

    def test_no_se_vuelve_a_dibujar_en_el_mismo_dia(self):
        from spui.player import Player
        p = Player.__new__(Player)
        p._mostrando_fallback = False
        p._ventana = object()
        p._dibujante = tv.Dibujante()
        p._lugar = 'Campus E4'
        p._reloj = mock.Mock(ahora=lambda: LUNES_18)
        p._poner = mock.Mock()
        p.mostrar_fallback()
        p.mostrar_fallback()
        self.assertEqual(p._poner.call_count, 1)
        p._reloj = mock.Mock(ahora=lambda: datetime(2026, 11, 3, 0, 1))   # otro día: otra fecha arriba
        p.mostrar_fallback()
        self.assertEqual(p._poner.call_count, 2)


class CurvaTest(unittest.TestCase):
    def test_la_curva_del_css(self):
        self.assertEqual(ventana.curva(0), 0)
        self.assertEqual(ventana.curva(1), 1)
        self.assertAlmostEqual(ventana.curva(.5), .5, places=3)
        valores = [ventana.curva(i / 20) for i in range(21)]
        self.assertEqual(valores, sorted(valores))
        # Arranca y frena suave: el primer y el último tramo se mueven poco.
        self.assertLess(valores[1], .02)
        self.assertGreater(valores[19], .98)


@unittest.skipUnless(_PIL, 'Pillow no instalado')
class CronogramaEnElPlayerTest(unittest.TestCase):
    """El player pasa las páginas: una vuelta en una rotación, sin fin si está solo."""

    def _player(self, lam):
        from spui.player import Player
        p = Player.__new__(Player)
        p._simulacion = False
        p._stop = threading.Event()
        p._interrumpido = threading.Event()
        p._tiene_alerta = mock.Mock(return_value=False)
        p._lamina = mock.Mock(return_value=lam)
        p._poner = mock.Mock()
        p._deslizar = mock.Mock()
        p._esperar = mock.Mock()
        return p

    def test_en_una_rotacion_pasa_cada_pagina_una_vez(self):
        lam = tv.Lamina(fondo=None, paginas=[1, 2, 3])
        p = self._player(lam)
        p._mostrar_cronograma({'tipo': 'cronograma'}, 10)
        self.assertEqual(p._esperar.call_count, 3)
        self.assertEqual([c.args[1:] for c in p._deslizar.call_args_list], [(0, lam, 1), (1, lam, 2)])

    def test_solo_en_la_playlist_vuelve_a_la_primera_redibujando(self):
        lam = tv.Lamina(fondo=None, paginas=[1, 2])
        p = self._player(lam)
        vueltas = iter(range(5))
        p._esperar = mock.Mock(side_effect=lambda s: next(vueltas, None) is None and p._stop.set())
        p._mostrar_cronograma({'tipo': 'cronograma'}, None)
        # Una vez al empezar y otra cada vez que vuelve a la primera página.
        self.assertGreaterEqual(p._lamina.call_count, 3)
        self.assertIn((1, lam, 0), [c.args[1:] for c in p._deslizar.call_args_list])

    def test_una_alerta_corta_las_paginas(self):
        lam = tv.Lamina(fondo=None, paginas=[1, 2, 3])
        p = self._player(lam)
        p._tiene_alerta = mock.Mock(return_value=True)
        p._mostrar_cronograma({'tipo': 'cronograma'}, None)
        p._deslizar.assert_not_called()


@unittest.skipUnless(_PIL and ventana.PYGAME_DISPONIBLE, 'Pillow o pygame no instalados')
class VentanaTest(unittest.TestCase):
    """Con el driver 'dummy' de SDL: sin abrir nada en la pantalla de la PC."""

    def setUp(self):
        parche = mock.patch.dict(os.environ, {'SDL_VIDEODRIVER': 'dummy'})
        parche.start()
        self.addCleanup(parche.stop)
        self.v = ventana.Ventana()
        if not self.v.abrir():
            self.skipTest(self.v.motivo)
        self.addCleanup(self.v.cerrar)

    def test_desliza_y_termina_en_la_pagina_nueva(self):
        c = DibujoTest._cronograma(DibujoTest(), 30)
        lam = tv.Dibujante().lamina(c, LUNES_18)
        self.v.mostrar(lam, 0)
        with mock.patch.object(ventana, 'DESLIZAR_MS', 300):
            self.v.deslizar(lam, 0, lam, 1, lambda: False)
        self.assertEqual(self.v._actual, (lam, 1))
        self.assertIsNotNone(self.v.fps_ultimo_deslizamiento)

    def test_cortar_termina_en_el_acto(self):
        c = DibujoTest._cronograma(DibujoTest(), 30)
        lam = tv.Dibujante().lamina(c, LUNES_18)
        # Convertir las páginas lleva su tiempo y no es lo que se mide acá.
        self.v.mostrar(lam, 0)
        import time
        inicio = time.monotonic()
        self.v.deslizar(lam, 0, lam, 1, lambda: True)
        self.assertLess(time.monotonic() - inicio, 0.5)
        self.assertEqual(self.v._actual, (lam, 1))

    def test_la_hora_se_repinta_sola_al_cambiar_el_minuto(self):
        lam = tv.Dibujante().lamina({'tipo': 'texto', 'contenido_texto': 'Hola', 'presentacion': {'plantilla': 'institucional'}},
                                    LUNES_18, con_hora=True)
        self.v.mostrar(lam)
        self.v.hora('18:01')
        self.assertEqual(self.v._hora_en_pantalla, '18:01')
        self.v.mostrar(lam)    # al volver a mostrarla, sigue la hora nueva
        self.assertEqual(self.v._hora_en_pantalla, '18:01')

    def test_brillo_y_negro_no_fallan(self):
        lam = tv.Dibujante().lamina({'tipo': 'texto', 'contenido_texto': 'Hola'}, LUNES_18)
        self.v.mostrar(lam)
        self.v.brillo(.4)
        self.v.atender()
        self.v.negro()
        self.assertIsNone(self.v._actual)


if __name__ == '__main__':
    unittest.main()
