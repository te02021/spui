// Elige el color del texto (blanco o negro) de cada badge de color según su
// fondo, para que siempre se lea sin importar qué combinación tenga.
//
// Por qué en JS y no fijando el color en el CSS de cada clase: los fondos de
// unraf-badge-*/spui-badge-* salen de variables CSS (--unraf-azul,
// --unraf-info, etc.) que cambian según el tema activo de la intranet
// (data-theme). Un color de texto fijo por clase se calcula una vez contra
// UN fondo y queda mal si el tema cambia ese fondo — es exactamente lo que le
// pasaba a unraf-badge-info, pensado para un fondo pastel y aplicado sobre un
// azul saturado. Leyendo el fondo ya resuelto por el navegador
// (getComputedStyle) el cálculo es correcto sin importar el tema.
(function () {
    'use strict';

    var SELECTOR = '[class*="unraf-badge-"], [class*="spui-badge-"]';

    // YIQ (recomendación W3C para decidir texto blanco/negro): pondera verde
    // más que rojo y azul porque el ojo es más sensible a él.
    function contrasteDe(r, g, b) {
        var yiq = (r * 299 + g * 587 + b * 114) / 1000;
        return yiq >= 128 ? '#000' : '#fff';
    }

    function aplicar(el) {
        var fondo = getComputedStyle(el).backgroundColor;
        var m = fondo.match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/);
        if (!m) return; // fondo transparente/sin resolver: no hay nada que decidir
        el.style.color = contrasteDe(+m[1], +m[2], +m[3]);
    }

    function recorrer(raiz) {
        if (raiz.matches && raiz.matches(SELECTOR)) aplicar(raiz);
        raiz.querySelectorAll(SELECTOR).forEach(aplicar);
    }

    document.addEventListener('DOMContentLoaded', function () {
        recorrer(document.body);

        // #spui-table-body se reemplaza por innerHTML tras guardar/cerrar
        // acciones (ver modal.js::refreshTable), y #spuiAbmBody es donde el
        // modal Ver/Editar inyecta su HTML — los badges de ambos son nuevos
        // nodos que necesitan el mismo tratamiento.
        ['spui-table-body', 'spuiAbmBody'].forEach(function (id) {
            var cont = document.getElementById(id);
            if (!cont) return;
            new MutationObserver(function () { recorrer(cont); })
                .observe(cont, { childList: true, subtree: true });
        });
    });
})();
