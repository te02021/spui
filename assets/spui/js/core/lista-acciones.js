// Botones de las tarjetas de los listados (30/10/2026): cuando no entran al
// lado de los datos y bajan a otro renglón, se centran. Lo mismo con la barra
// de arriba (buscador + filtros).
//
// No hay un ancho de pantalla fijo en que eso pase: depende de cuántos
// botones tenga la tarjeta, de si el sidebar está abierto y del largo de los
// datos. Por eso se mira cada tarjeta: si sus acciones quedaron debajo del
// cuerpo, lleva .is-apilada (style.css las pasa a todo el ancho, centradas).
(function () {
    'use strict';

    if (typeof ResizeObserver === 'undefined') return;

    function revisar(item) {
        var cuerpo = item.querySelector(':scope > .spui-item-cuerpo');
        var acciones = item.querySelector(':scope > .spui-item-acciones');
        if (!cuerpo || !acciones) return;
        // Se mide sin la clase: con ella las acciones siempre ocupan su
        // propio renglón y la tarjeta quedaría apilada para siempre.
        // Apiladas = empiezan debajo del final de los datos. Comparar sólo los
        // bordes de arriba no sirve: la tarjeta centra en vertical, así que
        // estando al lado los botones igual arrancan unos píxeles más abajo.
        item.classList.remove('is-apilada');
        item.classList.toggle('is-apilada', acciones.offsetTop >= cuerpo.offsetTop + cuerpo.offsetHeight - 2);
    }

    var observador = new ResizeObserver(function (entradas) {
        entradas.forEach(function (e) {
            if (e.target.classList.contains('spui-barra-lista')) revisarBarra(e.target); else revisar(e.target);
        });
    });
    var vistos = new WeakSet();

    // La barra de arriba (buscador + filtros): cuando sus piezas ya no entran
    // en una fila, se centra todo. Apilada = alguna pieza arranca más abajo
    // que el buscador.
    function revisarBarra(barra) {
        var piezas = barra.querySelectorAll('.spui-buscador, .spui-selector');
        if (!piezas.length) return;
        barra.classList.remove('is-apilada');
        var tope = piezas[0].getBoundingClientRect().top;
        var apilada = Array.prototype.some.call(piezas, function (p) { return p.getBoundingClientRect().top > tope + 8; });
        barra.classList.toggle('is-apilada', apilada);
    }

    function enganchar() {
        document.querySelectorAll('.spui-barra-lista').forEach(function (barra) {
            if (vistos.has(barra)) { revisarBarra(barra); return; }
            vistos.add(barra);
            observador.observe(barra);
        });
        document.querySelectorAll('.spui-item').forEach(function (item) {
            if (vistos.has(item)) { revisar(item); return; }
            vistos.add(item);
            observador.observe(item);
        });
    }

    document.addEventListener('DOMContentLoaded', enganchar);
    // Un refresco puede traer tarjetas nuevas o cambiar sus botones.
    document.addEventListener('spui:refrescado', enganchar);
}());
