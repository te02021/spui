// Desplegables de los listados (30/10/2026): un chip o un botón que, al
// tocarlo, abre debajo una lista corta (los sectores de un edificio, las
// pantallas de una ubicación, el diagnóstico de un reproductor, los días de
// una regla). No es un modal: no tapa la página ni frena el refresco.
//
//     <span class="spui-desp">
//         <button type="button" class="spui-chip spui-chip-btn" data-spui-desp
//                 aria-expanded="false" aria-controls="x-panel">3 sectores</button>
//         <div id="x-panel" class="spui-desp-panel" role="region" aria-label="…">…</div>
//     </span>
//
// Se abre por aria-expanded (el CSS muestra el panel que le sigue), que
// auto-refresh.js no toca: si la lista se refresca, el panel sigue abierto.
// Uno a la vez; se cierra tocando afuera o con Esc.
(function () {
    'use strict';

    var abierto = null;

    function cerrar(devolverFoco) {
        if (!abierto) return;
        var btn = abierto;
        abierto = null;
        btn.setAttribute('aria-expanded', 'false');
        if (devolverFoco && document.contains(btn)) btn.focus();
    }

    /** Si el panel se sale por la derecha (celular, chip al final de la fila), se corre a la izquierda. */
    function acomodar(btn) {
        var panel = document.getElementById(btn.getAttribute('aria-controls'));
        if (!panel) return;
        panel.style.left = '';
        var r = panel.getBoundingClientRect(), vw = document.documentElement.clientWidth;
        if (r.right > vw - 16) panel.style.left = Math.min(0, vw - 16 - r.right) + 'px';
    }

    function abrir(btn) {
        cerrar();
        abierto = btn;
        btn.setAttribute('aria-expanded', 'true');
        acomodar(btn);
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-spui-desp]');
        if (btn) {
            if (btn === abierto) cerrar(); else abrir(btn);
            return;
        }
        if (abierto && !e.target.closest('.spui-desp-panel')) cerrar();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') cerrar(true);
    });
    window.addEventListener('resize', function () { cerrar(); });

    // Un refresco que reemplazó la fila entera deja el botón nuevo cerrado:
    // se lo vuelve a abrir si es el mismo (mismo aria-controls).
    document.addEventListener('spui:refrescado', function () {
        if (!abierto || document.contains(abierto)) return;
        var nuevo = document.querySelector('[data-spui-desp][aria-controls="' + abierto.getAttribute('aria-controls') + '"]');
        abierto = null;
        if (nuevo) abrir(nuevo);
    });
}());
