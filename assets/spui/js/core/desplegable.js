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
    // Dentro de un modal: si la ventana cambia de tamaño con el panel abierto
    // (se está achicando por un filtro recién aplicado y, como está centrada,
    // el botón se mueve), el panel lo acompaña.
    var vigiaModal = typeof ResizeObserver !== 'undefined'
        ? new ResizeObserver(function () { if (abierto) acomodar(abierto); })
        : null;

    function cerrar(devolverFoco) {
        if (!abierto) return;
        var btn = abierto;
        abierto = null;
        if (vigiaModal) vigiaModal.disconnect();
        btn.setAttribute('aria-expanded', 'false');
        devolver(btn);
        if (devolverFoco && document.contains(btn)) btn.focus();
    }

    // Dentro de un modal, el panel abierto se lleva al final de la página: la
    // ventana del modal recorta todo lo que se le sale (y, por su transform,
    // ni siquiera "fixed" se escapa). Al cerrarse vuelve a su lugar, al lado
    // de su botón. panel._spuiBoton le dice a quien lo use de qué filtro es.
    function sacarDelModal(btn, panel) {
        if (!btn.closest('.spui-abm-modal-body') || panel.parentNode === document.body) return;
        panel._spuiBoton = btn;
        panel.style.display = 'block';
        document.body.appendChild(panel);
    }

    function devolver(btn) {
        var panel = document.getElementById(btn.getAttribute('aria-controls'));
        if (!panel || panel.parentNode !== document.body || panel._spuiBoton !== btn) return;
        panel.style.display = '';
        ['position', 'zIndex', 'left', 'right', 'top', 'maxHeight', 'transform'].forEach(function (p) { panel.style[p] = ''; });
        if (document.contains(btn)) btn.parentNode.insertBefore(panel, btn.nextSibling);
        else panel.remove();   // la ventana se volvió a dibujar: ya hay otro
    }

    /**
     * Dentro de un modal (los filtros de la ventana de ítems), el panel se
     * ubica fijo respecto de la pantalla: si no, el cuerpo del modal, que
     * tiene su propio scroll, lo recortaba y había que scrollear para ver el
     * selector de hora entero (03/11/2026). Se abre hacia abajo o hacia arriba,
     * donde haya lugar; sólo si no entra de ninguna forma tiene scroll propio.
     */
    function ubicarEnModal(btn, panel) {
        var rb = btn.getBoundingClientRect(), vh = window.innerHeight;
        panel.style.position = 'fixed';
        panel.style.zIndex = '10050';
        panel.style.right = 'auto';
        // La ventana del modal tiene un transform, y con un ancestro así
        // "fixed" se mide desde ese ancestro y no desde la pantalla: se mide
        // dónde cae el 0,0 y se descuenta.
        panel.style.top = '0px';
        panel.style.left = '0px';
        var cero = panel.getBoundingClientRect();
        var x = panel.classList.contains('spui-menu-panel') ? rb.right - panel.offsetWidth : rb.left;
        panel.style.left = (x - cero.left) + 'px';
        var alto = panel.offsetHeight;
        var abajo = vh - rb.bottom - 16, arriba = rb.top - 16;
        if (alto > abajo && arriba > abajo) {
            panel.style.maxHeight = alto > arriba ? arriba + 'px' : '';
            panel.style.top = (rb.top - 6 - Math.min(alto, arriba) - cero.top) + 'px';
        } else {
            panel.style.maxHeight = alto > abajo ? abajo + 'px' : '';
            panel.style.top = (rb.bottom + 6 - cero.top) + 'px';
        }
    }

    /**
     * Si el panel se sale de la pantalla (celular, chip al final de la fila,
     * o el menú ⋮, que se abre hacia la izquierda), se corre lo justo.
     */
    function acomodar(btn) {
        var panel = document.getElementById(btn.getAttribute('aria-controls'));
        if (!panel) return;
        panel.style.transform = '';
        ['position', 'zIndex', 'left', 'right', 'top', 'maxHeight'].forEach(function (p) { panel.style[p] = ''; });
        if (panel._spuiBoton === btn && panel.parentNode === document.body) ubicarEnModal(btn, panel);
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
        var panel = document.getElementById(btn.getAttribute('aria-controls'));
        if (panel) sacarDelModal(btn, panel);
        acomodar(btn);
        var modal = btn.closest('.spui-abm-modal');
        if (modal && vigiaModal) vigiaModal.observe(modal);
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
    // Un panel ubicado fijo dentro de un modal acompaña al botón si se scrollea.
    document.addEventListener('scroll', function (e) {
        if (!abierto || !abierto.closest('.spui-abm-modal-body')) return;
        if (e.target.closest && e.target.closest('.spui-desp-panel')) return;   // el scroll propio de una columna
        acomodar(abierto);
    }, true);

    // Un refresco que reemplazó la fila entera deja el botón nuevo cerrado:
    // se lo vuelve a abrir si es el mismo (mismo aria-controls).
    document.addEventListener('spui:refrescado', function () {
        if (!abierto || document.contains(abierto)) return;
        var nuevo = document.querySelector('[data-spui-desp][aria-controls="' + abierto.getAttribute('aria-controls') + '"]');
        abierto = null;
        if (nuevo) abrir(nuevo);
    });
}());
