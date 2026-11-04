// Buscador, filtros y páginas de la ventana de ítems de un cronograma
// (02/11/2026, cronograma/_items.html.twig).
//
// Todo en el navegador, sobre las filas que ya están (con un Excel importado
// son cientos): responde al instante y no vuelve a pedir nada. Filtros:
//   Día    — lo de ese día de la semana (un evento, si su fecha cae ese día).
//   Fecha  — lo que se da ese día: clases de su día de la semana y eventos
//            de esa fecha (la misma regla que CronogramaItem::aplicaEl()).
//   Hora   — lo que está en curso a esa hora exacta, elegida con un selector
//            de dos columnas (hora y minutos de 1 en 1) y Aplicar (03/11/2026).
//   Buscar — materia, aula o docente, sin importar tildes ni mayúsculas.
// Lo que queda se muestra de a 25 por página (03/11/2026); cambiar un filtro
// vuelve a la primera. Cada opción de Día dice cuántos ítems quedarían. Lo
// elegido y la página se recuerdan por cronograma mientras la página esté
// abierta: al volver de agregar o editar un ítem (la ventana se vuelve a
// dibujar) sigue igual.
(function () {
    'use strict';

    var DIAS = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
    var POR_PAGINA = 25;
    var recordados = {};

    function normalizar(s) {
        return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    }

    function aMinutos(hhmm) {
        if (!hhmm) return null;
        var p = hhmm.split(':');
        return parseInt(p[0], 10) * 60 + parseInt(p[1], 10);
    }

    function dos(n) { return (n < 10 ? '0' : '') + n; }

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

    function cumple(f, filtro) {
        if (filtro.dia !== '') {
            // Un evento, si su fecha cae ese día de la semana.
            if (f.fecha ? diaDe(f.fecha) !== filtro.dia : !(f.dias & (1 << filtro.dia))) return false;
        }
        if (filtro.fecha) {
            if (f.fecha ? f.fecha !== filtro.fecha : !(f.dias & (1 << diaDe(filtro.fecha)))) return false;
        }
        if (filtro.hora) {
            // En curso a esa hora: ya empezó y todavía no terminó.
            var t = aMinutos(filtro.hora);
            if (f.inicio === null || !(f.inicio <= t && f.fin > t)) return false;
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

    // La barra de páginas, igual a _partials/paginacion.html.twig: "26–50 de
    // 226 ítems" a la izquierda y los botones a la derecha (la primera, la
    // última y dos a cada lado de la actual).
    function paginasHtml(pagina, paginas, desde, hasta, total) {
        var html = '<p class="spui-paginacion-resumen mb-0">' + desde + '–' + hasta + ' de ' + total + ' ítems</p>' +
            '<ul class="spui-paginacion-lista">';
        var boton = function (n, texto, etiqueta, rel) {
            return '<li><button type="button" class="spui-paginacion-btn" data-spui-items-pagina="' + n + '"' +
                (rel ? ' rel="' + rel + '"' : '') + ' aria-label="' + etiqueta + '">' + texto + '</button></li>';
        };
        html += pagina > 1
            ? boton(pagina - 1, '‹ <span class="spui-paginacion-txt">Anterior</span>', 'Página anterior', 'prev')
            : '<li><span class="spui-paginacion-btn is-off" aria-hidden="true">‹ <span class="spui-paginacion-txt">Anterior</span></span></li>';
        var hueco = false;
        for (var n = 1; n <= paginas; n++) {
            if (n === 1 || n === paginas || (n >= pagina - 2 && n <= pagina + 2)) {
                hueco = false;
                html += n === pagina
                    ? '<li><span class="spui-paginacion-btn is-actual" aria-current="page">' + n + '</span></li>'
                    : boton(n, n, 'Página ' + n);
            } else if (!hueco) {
                hueco = true;
                html += '<li><span class="spui-paginacion-btn is-hueco" aria-hidden="true">…</span></li>';
            }
        }
        html += pagina < paginas
            ? boton(pagina + 1, '<span class="spui-paginacion-txt">Siguiente</span> ›', 'Página siguiente', 'next')
            : '<li><span class="spui-paginacion-btn is-off" aria-hidden="true"><span class="spui-paginacion-txt">Siguiente</span> ›</span></li>';
        return html + '</ul>';
    }

    function aplicar(barra) {
        var modal = barra.closest('.spui-abm-modal-body') || document;
        var filas = barra._spuiFilas;
        var filtro = barra._spuiFiltro;

        var quedan = filas.filter(function (f) { return cumple(f, filtro); });
        var paginas = Math.max(1, Math.ceil(quedan.length / POR_PAGINA));
        barra._spuiPagina = Math.min(Math.max(1, barra._spuiPagina || 1), paginas);
        var desde = (barra._spuiPagina - 1) * POR_PAGINA;
        var enPagina = quedan.slice(desde, desde + POR_PAGINA);
        filas.forEach(function (f) { f.tr.hidden = enPagina.indexOf(f) === -1; });

        // Las opciones de Día con su cantidad, contando los otros filtros elegidos.
        var cuenta = function (valor) {
            var prueba = Object.assign({}, filtro, { dia: valor });
            return filas.filter(function (f) { return cumple(f, prueba); }).length;
        };
        var ulDia = buscar(barra, '[data-spui-items-opciones="dia"]');
        var html = opcion('dia', '', 'Todos', cuenta(''), filtro.dia === '');
        DIAS.forEach(function (n, i) {
            var c = cuenta(i);
            if (c || filtro.dia === i) html += opcion('dia', i, n, c, filtro.dia === i);
        });
        ulDia.innerHTML = html;

        // Lo elegido, en cada chip.
        var valores = {
            dia: filtro.dia === '' ? 'Todos' : DIAS[filtro.dia],
            fecha: filtro.fecha ? filtro.fecha.split('-').reverse().join('/') : 'Todas',
            hora: filtro.hora ? 'A las ' + filtro.hora : 'Todas',
        };
        Object.keys(valores).forEach(function (g) {
            var sel = barra.querySelector('[data-spui-items-selector="' + g + '"]');
            sel.querySelector('[data-spui-items-valor]').textContent = valores[g];
            sel.classList.toggle('is-activo', g === 'dia' ? filtro.dia !== '' : !!filtro[g]);
        });
        buscar(barra, '[data-spui-items-op="fecha"]').classList.toggle('is-elegida', !filtro.fecha);
        buscar(barra, '[data-spui-items-op="hora"]').classList.toggle('is-elegida', !filtro.hora);

        // Arriba, sólo con filtros: cuántos quedan de todos.
        var hay = filtro.dia !== '' || filtro.fecha || filtro.hora || filtro.q;
        var texto = modal.querySelector('[data-spui-items-cuenta]');
        if (texto) texto.textContent = hay && quedan.length ? quedan.length + ' de ' + filas.length + ' ítems coinciden.' : '';
        var nav = modal.querySelector('[data-spui-items-paginas]');
        if (nav) {
            nav.hidden = paginas < 2;
            nav.innerHTML = paginas < 2 ? '' : paginasHtml(barra._spuiPagina, paginas, desde + 1, desde + enPagina.length, quedan.length);
        }
        var vacio = modal.querySelector('[data-spui-items-vacio]');
        if (vacio) vacio.hidden = quedan.length > 0;
        var tabla = modal.querySelector('.spui-tabla-cards');
        if (tabla) tabla.hidden = quedan.length === 0;
        barra.querySelector('[data-spui-items-limpiar]').hidden = !filtro.q;

        recordados[barra.getAttribute('data-contenido')] = Object.assign({}, filtro, {
            textoBuscado: barra.querySelector('[data-spui-items-buscar]').value,
            pagina: barra._spuiPagina,
        });
    }

    // Un filtro cambió: se vuelve a la primera página.
    function filtrar(barra) {
        barra._spuiPagina = 1;
        aplicar(barra);
    }

    // ── Selector de hora (dos columnas, de 1 en 1) ──────────────────────────

    function llenarColumna(col, cantidad) {
        var html = '';
        for (var i = 0; i < cantidad; i++) {
            html += '<button type="button" class="spui-reloj-num" role="option" data-valor="' + i + '" aria-selected="false">' + dos(i) + '</button>';
        }
        col.innerHTML = html;
    }

    function elegirEnColumna(col, valor, suave) {
        col._spuiValor = valor;
        col.querySelectorAll('.spui-reloj-num').forEach(function (b) {
            var si = parseInt(b.getAttribute('data-valor'), 10) === valor;
            b.classList.toggle('is-elegido', si);
            b.setAttribute('aria-selected', si ? 'true' : 'false');
        });
        var el = col.querySelector('[data-valor="' + valor + '"]');
        if (el) {
            var destino = el.offsetTop - col.clientHeight / 2 + el.offsetHeight / 2;
            if (suave && col.scrollTo) col.scrollTo({ top: destino, behavior: 'smooth' }); else col.scrollTop = destino;
        }
    }

    function prepararReloj(barra) {
        var reloj = buscar(barra, '[data-spui-reloj]');
        if (!reloj || reloj._spuiListo) return;
        reloj._spuiListo = true;
        llenarColumna(reloj.querySelector('[data-spui-reloj-col="h"]'), 24);
        llenarColumna(reloj.querySelector('[data-spui-reloj-col="m"]'), 60);
    }

    // Al abrir: la hora del filtro, o la de ahora si no hay ninguna.
    function abrirReloj(barra) {
        prepararReloj(barra);
        var reloj = buscar(barra, '[data-spui-reloj]');
        var hora = barra._spuiFiltro.hora;
        var d = new Date();
        var h = hora ? parseInt(hora.split(':')[0], 10) : d.getHours();
        var m = hora ? parseInt(hora.split(':')[1], 10) : d.getMinutes();
        elegirEnColumna(reloj.querySelector('[data-spui-reloj-col="h"]'), h, false);
        elegirEnColumna(reloj.querySelector('[data-spui-reloj-col="m"]'), m, false);
    }

    function cerrarMenu(barra, grupo) {
        var btn = barra.querySelector('[data-spui-items-selector="' + grupo + '"]');
        if (btn && btn.getAttribute('aria-expanded') === 'true') btn.click();
    }

    function iniciar(raiz) {
        (raiz || document).querySelectorAll('[data-spui-items-filtros]').forEach(function (barra) {
            if (barra._spuiFilas) return;
            var modal = barra.closest('.spui-abm-modal-body') || document;
            barra._spuiFilas = Array.prototype.map.call(modal.querySelectorAll('tr[data-spui-item]'), leerFila);

            var previo = recordados[barra.getAttribute('data-contenido')];
            barra._spuiFiltro = previo
                ? { dia: previo.dia, fecha: previo.fecha, hora: typeof previo.hora === 'string' ? previo.hora : '', q: previo.q }
                : { dia: '', fecha: '', hora: '', q: '' };
            barra._spuiPagina = previo ? previo.pagina : 1;
            if (previo) {
                barra.querySelector('[data-spui-items-buscar]').value = previo.textoBuscado || '';
                buscar(barra, '[data-spui-items-fecha]').value = previo.fecha || '';
            }
            aplicar(barra);
        });
    }

    // Algo de la barra que puede estar en uno de sus paneles: un panel abierto
    // dentro de un modal se lleva afuera (desplegable.js), así que no siempre
    // está adentro de la barra.
    function buscar(barra, sel) {
        var el = barra.querySelector(sel);
        if (el) return el;
        var botones = barra.querySelectorAll('[data-spui-desp]');
        for (var i = 0; i < botones.length; i++) {
            var panel = document.getElementById(botones[i].getAttribute('aria-controls'));
            el = panel && panel.querySelector(sel);
            if (el) return el;
        }
        return null;
    }

    function barraDe(el) {
        // Un panel abierto dentro de un modal se lleva afuera (desplegable.js):
        // se busca desde su botón.
        var panel = el.closest('.spui-desp-panel');
        var desde = panel && panel._spuiBoton ? panel._spuiBoton : el;
        var modal = desde.closest('.spui-abm-modal-body') || document;
        return modal.querySelector('[data-spui-items-filtros]');
    }

    document.addEventListener('click', function (e) {
        if (!e.target.closest) return;

        // Abrir el selector de hora: se ubica en la hora elegida (o la actual).
        var selHora = e.target.closest('[data-spui-items-selector="hora"]');
        if (selHora) {
            var b0 = barraDe(selHora);
            // desplegable.js abre el panel en este mismo clic; se acomoda después.
            setTimeout(function () { if (selHora.getAttribute('aria-expanded') === 'true') abrirReloj(b0); }, 0);
            return;
        }
        var num = e.target.closest('.spui-reloj-num');
        if (num) {
            elegirEnColumna(num.parentElement, parseInt(num.getAttribute('data-valor'), 10), true);
            return;
        }
        var ahora = e.target.closest('[data-spui-reloj-ahora]');
        if (ahora) {
            var reloj = ahora.closest('[data-spui-reloj]');
            var d = new Date();
            elegirEnColumna(reloj.querySelector('[data-spui-reloj-col="h"]'), d.getHours(), true);
            elegirEnColumna(reloj.querySelector('[data-spui-reloj-col="m"]'), d.getMinutes(), true);
            return;
        }
        var aplicarBtn = e.target.closest('[data-spui-reloj-aplicar]');
        if (aplicarBtn) {
            var r = aplicarBtn.closest('[data-spui-reloj]');
            var b1 = barraDe(aplicarBtn);
            b1._spuiFiltro.hora = dos(r.querySelector('[data-spui-reloj-col="h"]')._spuiValor) + ':' +
                dos(r.querySelector('[data-spui-reloj-col="m"]')._spuiValor);
            filtrar(b1);
            cerrarMenu(b1, 'hora');
            return;
        }
        var cancelar = e.target.closest('[data-spui-reloj-cancelar]');
        if (cancelar) {
            cerrarMenu(barraDe(cancelar), 'hora');
            return;
        }

        var pag = e.target.closest('[data-spui-items-pagina]');
        if (pag) {
            var bp = barraDe(pag);
            bp._spuiPagina = parseInt(pag.getAttribute('data-spui-items-pagina'), 10);
            aplicar(bp);
            // Arriba de la tabla, para seguir leyendo desde el principio.
            var cuenta = (bp.closest('.spui-abm-modal-body') || document).querySelector('[data-spui-items-cuenta]');
            if (cuenta && cuenta.scrollIntoView) cuenta.scrollIntoView({ block: 'nearest' });
            return;
        }

        var op = e.target.closest('[data-spui-items-op]');
        if (op) {
            var barra = barraDe(op);
            var g = op.getAttribute('data-spui-items-op');
            var v = op.getAttribute('data-valor');
            barra._spuiFiltro[g] = g === 'dia' ? (v === '' ? '' : parseInt(v, 10)) : v;
            if (g === 'fecha') buscar(barra, '[data-spui-items-fecha]').value = v;
            filtrar(barra);
            // Elegida la opción, el menú se cierra (lo maneja desplegable.js).
            cerrarMenu(barra, g);
            return;
        }
        var limpiar = e.target.closest('[data-spui-items-limpiar], [data-spui-items-quitar-filtros]');
        if (limpiar) {
            var b = barraDe(limpiar);
            var campo = b.querySelector('[data-spui-items-buscar]');
            campo.value = '';
            b._spuiFiltro.q = '';
            if (limpiar.hasAttribute('data-spui-items-quitar-filtros')) {
                b._spuiFiltro.dia = '';
                b._spuiFiltro.hora = '';
                b._spuiFiltro.fecha = '';
                buscar(b, '[data-spui-items-fecha]').value = '';
            }
            filtrar(b);
            campo.focus();
        }
    });

    // Con el teclado, las flechas mueven la hora o los minutos de la columna enfocada.
    document.addEventListener('keydown', function (e) {
        var col = e.target.closest && e.target.closest('[data-spui-reloj-col]');
        if (col && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
            e.preventDefault();
            var tope = col.getAttribute('data-spui-reloj-col') === 'h' ? 24 : 60;
            var actual = col._spuiValor || 0;
            elegirEnColumna(col, (actual + (e.key === 'ArrowDown' ? 1 : tope - 1)) % tope, true);
            return;
        }
        if (col && e.key === 'Enter') {
            e.preventDefault();
            var aplicarBtn = col.closest('[data-spui-reloj]').querySelector('[data-spui-reloj-aplicar]');
            if (aplicarBtn) aplicarBtn.click();
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
                filtrar(barra);
            }, 150);
        }
    });
    document.addEventListener('change', function (e) {
        var t = e.target;
        if (t.matches && t.matches('[data-spui-items-fecha]')) {
            var barra = barraDe(t);
            barra._spuiFiltro.fecha = t.value;
            filtrar(barra);
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && e.target.matches && e.target.matches('[data-spui-items-buscar]') && e.target.value) {
            e.preventDefault();
            e.stopPropagation();
            e.target.value = '';
            var barra = barraDe(e.target);
            barra._spuiFiltro.q = '';
            filtrar(barra);
        }
    }, true);

    document.addEventListener('spui:modal:loaded', function (e) { iniciar(e.target); });
    document.addEventListener('DOMContentLoaded', function () { iniciar(); });
}());
