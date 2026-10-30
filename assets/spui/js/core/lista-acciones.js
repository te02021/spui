// Botones de las tarjetas de los listados (30/10/2026): cuando no entran al
// lado de los datos y bajan a otro renglón, se centran.
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
        item.classList.remove('is-apilada');
        item.classList.toggle('is-apilada', acciones.offsetTop > cuerpo.offsetTop + 4);
    }

    var observador = new ResizeObserver(function (entradas) {
        entradas.forEach(function (e) { revisar(e.target); });
    });
    var vistos = new WeakSet();

    function enganchar() {
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
