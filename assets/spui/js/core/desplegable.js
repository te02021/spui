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
//
// El menú ⋮ de acciones de cada tarjeta (.spui-menu-panel, 03/11/2026) es uno
// de estos: se cierra solo al elegir una acción, salvo una bloqueada, para
// que se pueda leer el motivo.
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

    /**
     * Si el panel se sale de la pantalla (celular, chip al final de la fila,
     * o el menú ⋮, que se abre hacia la izquierda), se corre lo justo.
     */
    function acomodar(btn) {
        var panel = document.getElementById(btn.getAttribute('aria-controls'));
        if (!panel) return;
        panel.style.transform = '';
        var r = panel.getBoundingClientRect(), vw = document.documentElement.clientWidth;
        var corrimiento = 0;
        if (r.right > vw - 16) corrimiento = vw - 16 - r.right;
        if (r.left + corrimiento < 16) corrimiento = 16 - r.left;
        if (corrimiento) panel.style.transform = 'translateX(' + corrimiento + 'px)';
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
        if (abierto && !e.target.closest('.spui-desp-panel')) { cerrar(); return; }
        // Elegida una acción del menú ⋮, el menú se cierra (la acción ya la
        // tomó su propio manejador: confirmación, modal o enlace).
        var accion = abierto && e.target.closest('.spui-menu-panel .unraf-btn');
        if (accion && !accion.classList.contains('spui-accion-bloqueada')) cerrar();
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
