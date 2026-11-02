// Buscador y filtros de la ventana de ítems de un cronograma (02/11/2026,
// cronograma/_items.html.twig).
//
// Todo en el navegador, sobre las filas que ya están (con un Excel importado
// son cientos): responde al instante y no vuelve a pedir nada. Filtros:
//   Día    — lo de ese día de la semana (un evento, si su fecha cae ese día).
//   Fecha  — lo que se da ese día: clases de su día de la semana y eventos
//            de esa fecha (la misma regla que CronogramaItem::aplicaEl()).
//   Hora   — lo que está en curso en esa hora.
//   Buscar — materia, aula o docente, sin importar tildes ni mayúsculas.
// Cada opción dice cuántos ítems quedarían. Lo elegido se recuerda por
// cronograma mientras la página esté abierta: al volver de agregar o editar
// un ítem (la ventana se vuelve a dibujar) sigue igual.
(function () {
    'use strict';

    var DIAS = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
    var recordados = {};

    function normalizar(s) {
        return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    }

    function aMinutos(hhmm) {
        if (!hhmm) return null;
        var p = hhmm.split(':');
        return parseInt(p[0], 10) * 60 + parseInt(p[1], 10);
    }

    function leerFila(tr) {
        return {
            tr: tr,
            dias: parseInt(tr.getAttribute('data-dias'), 10) || 0,
            fecha: tr.getAttribute('data-fecha') || '',
            inicio: aMinutos(tr.getAttribute('data-inicio')),
            // Sin hora de fin (un evento "desde las 18"): hasta el final del día.
            fin: aMinutos(tr.getAttribute('data-fin')) || 24 * 60,
            texto: normalizar(tr.getAttribute('data-buscar')),
        };
    }

    // Día de la semana de una fecha "Y-m-d", 0 = lunes (como el bitmask).
    function diaDe(fecha) {
        var d = new Date(fecha + 'T12:00:00');
        return (d.getDay() + 6) % 7;
    }

    function cumple(f, filtro, salvo) {
        if (salvo !== 'dia' && filtro.dia !== '') {
            // Un evento, si su fecha cae ese día de la semana.
            if (f.fecha ? diaDe(f.fecha) !== filtro.dia : !(f.dias & (1 << filtro.dia))) return false;
        }
        if (salvo !== 'fecha' && filtro.fecha) {
            if (f.fecha ? f.fecha !== filtro.fecha : !(f.dias & (1 << diaDe(filtro.fecha)))) return false;
        }
        if (salvo !== 'hora' && filtro.hora !== '') {
            var h = filtro.hora * 60;
            if (!(f.inicio < h + 60 && f.fin > h)) return false;
        }
        if (filtro.q && f.texto.indexOf(filtro.q) === -1) return false;
        return true;
    }

    function opcion(grupo, valor, texto, cuenta, elegida) {
        return '<li><button type="button" class="spui-selector-opcion' + (elegida ? ' is-elegida' : '') + '"' +
            ' data-spui-items-op="' + grupo + '" data-valor="' + valor + '"' + (elegida ? ' aria-current="true"' : '') + '>' +
            '<span class="spui-selector-check" aria-hidden="true">✓</span>' +
            '<span class="spui-selector-texto">' + texto + '</span>' +
            '<span class="spui-filtro-cuenta">' + cuenta + '</span></button></li>';
    }

    function aplicar(barra) {
        var modal = barra.closest('.spui-abm-modal-body') || document;
        var filas = barra._spuiFilas;
        var filtro = barra._spuiFiltro;
        var visibles = 0;

        filas.forEach(function (f) {
            var ok = cumple(f, filtro);
            f.tr.hidden = !ok;
            if (ok) visibles++;
        });

        // Opciones con su cantidad, contando los otros filtros elegidos.
        var cuenta = function (grupo, valor) {
            var prueba = Object.assign({}, filtro);
            prueba[grupo] = valor;
            return filas.filter(function (f) { return cumple(f, prueba); }).length;
        };
        var ulDia = barra.querySelector('[data-spui-items-opciones="dia"]');
        var html = opcion('dia', '', 'Todos', cuenta('dia', ''), filtro.dia === '');
        DIAS.forEach(function (n, i) {
            var c = cuenta('dia', i);
            if (c || filtro.dia === i) html += opcion('dia', i, n, c, filtro.dia === i);
        });
        ulDia.innerHTML = html;

        var ulHora = barra.querySelector('[data-spui-items-opciones="hora"]');
        html = opcion('hora', '', 'Todas', cuenta('hora', ''), filtro.hora === '');
        barra._spuiHoras.forEach(function (h) {
            var c = cuenta('hora', h);
            if (c || filtro.hora === h) html += opcion('hora', h, 'A las ' + h + ' h', c, filtro.hora === h);
        });
        ulHora.innerHTML = html;

        // Lo elegido, en cada chip.
        var valores = {
            dia: filtro.dia === '' ? 'Todos' : DIAS[filtro.dia],
            fecha: filtro.fecha ? filtro.fecha.split('-').reverse().join('/') : 'Todas',
            hora: filtro.hora === '' ? 'Todas' : 'A las ' + filtro.hora + ' h',
        };
        Object.keys(valores).forEach(function (g) {
            var sel = barra.querySelector('[data-spui-items-selector="' + g + '"]');
            sel.querySelector('[data-spui-items-valor]').textContent = valores[g];
            sel.classList.toggle('is-activo', g === 'fecha' ? !!filtro.fecha : filtro[g] !== '');
        });
        var opFecha = barra.querySelector('[data-spui-items-op="fecha"]');
        opFecha.classList.toggle('is-elegida', !filtro.fecha);

        var hay = filtro.dia !== '' || filtro.fecha || filtro.hora !== '' || filtro.q;
        var texto = modal.querySelector('[data-spui-items-cuenta]');
        if (texto) texto.textContent = hay ? 'Mostrando ' + visibles + ' de ' + filas.length + ' ítems.' : '';
        var vacio = modal.querySelector('[data-spui-items-vacio]');
        if (vacio) vacio.hidden = visibles > 0;
        var tabla = modal.querySelector('.spui-tabla-cards');
        if (tabla) tabla.hidden = visibles === 0;
        barra.querySelector('[data-spui-items-limpiar]').hidden = !filtro.q;

        recordados[barra.getAttribute('data-contenido')] = Object.assign({}, filtro, { textoBuscado: barra.querySelector('[data-spui-items-buscar]').value });
    }

    function iniciar(raiz) {
        (raiz || document).querySelectorAll('[data-spui-items-filtros]').forEach(function (barra) {
            if (barra._spuiFilas) return;
            var modal = barra.closest('.spui-abm-modal-body') || document;
            barra._spuiFilas = Array.prototype.map.call(modal.querySelectorAll('tr[data-spui-item]'), leerFila);

            // Las horas que tienen algo: de la primera que empieza a la última que termina.
            var horas = {};
            barra._spuiFilas.forEach(function (f) {
                if (f.inicio === null) return;
                for (var h = Math.floor(f.inicio / 60); h * 60 < f.fin && h < 24; h++) horas[h] = true;
            });
            barra._spuiHoras = Object.keys(horas).map(Number).sort(function (a, b) { return a - b; });

            var previo = recordados[barra.getAttribute('data-contenido')];
            barra._spuiFiltro = previo ? { dia: previo.dia, fecha: previo.fecha, hora: previo.hora, q: previo.q } : { dia: '', fecha: '', hora: '', q: '' };
            if (previo) {
                barra.querySelector('[data-spui-items-buscar]').value = previo.textoBuscado || '';
                barra.querySelector('[data-spui-items-fecha]').value = previo.fecha || '';
            }
            aplicar(barra);
        });
    }

    function barraDe(el) {
        var modal = el.closest('.spui-abm-modal-body') || document;
        return modal.querySelector('[data-spui-items-filtros]');
    }

    document.addEventListener('click', function (e) {
        var op = e.target.closest && e.target.closest('[data-spui-items-op]');
        if (op) {
            var barra = barraDe(op);
            var g = op.getAttribute('data-spui-items-op');
            var v = op.getAttribute('data-valor');
            barra._spuiFiltro[g] = g === 'fecha' ? v : (v === '' ? '' : parseInt(v, 10));
            if (g === 'fecha') barra.querySelector('[data-spui-items-fecha]').value = v;
            aplicar(barra);
            // Elegida la opción, el menú se cierra (lo maneja desplegable.js).
            var btn = barra.querySelector('[data-spui-items-selector="' + g + '"]');
            if (btn && btn.getAttribute('aria-expanded') === 'true') btn.click();
            return;
        }
        var limpiar = e.target.closest && e.target.closest('[data-spui-items-limpiar], [data-spui-items-quitar-filtros]');
        if (limpiar) {
            var b = barraDe(limpiar);
            var campo = b.querySelector('[data-spui-items-buscar]');
            campo.value = '';
            b._spuiFiltro.q = '';
            if (limpiar.hasAttribute('data-spui-items-quitar-filtros')) {
                b._spuiFiltro.dia = b._spuiFiltro.hora = '';
                b._spuiFiltro.fecha = '';
                b.querySelector('[data-spui-items-fecha]').value = '';
            }
            aplicar(b);
            campo.focus();
        }
    });

    var espera = null;
    document.addEventListener('input', function (e) {
        var t = e.target;
        if (!t.matches) return;
        if (t.matches('[data-spui-items-buscar]')) {
            clearTimeout(espera);
            espera = setTimeout(function () {
                var barra = barraDe(t);
                barra._spuiFiltro.q = normalizar(t.value.trim());
                aplicar(barra);
            }, 150);
        }
    });
    document.addEventListener('change', function (e) {
        var t = e.target;
        if (t.matches && t.matches('[data-spui-items-fecha]')) {
            var barra = barraDe(t);
            barra._spuiFiltro.fecha = t.value;
            aplicar(barra);
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && e.target.matches && e.target.matches('[data-spui-items-buscar]') && e.target.value) {
            e.preventDefault();
            e.stopPropagation();
            e.target.value = '';
            var barra = barraDe(e.target);
            barra._spuiFiltro.q = '';
            aplicar(barra);
        }
    }, true);

    document.addEventListener('spui:modal:loaded', function (e) { iniciar(e.target); });
    document.addEventListener('DOMContentLoaded', function () { iniciar(); });
}());
