// Buscador de los listados (30/10/2026): filtra a medida que se escribe, sin
// botón Buscar (_partials/barra_lista.html.twig).
//
// Cada pausa de 250 ms al escribir pone ?q= en la dirección (sin sumar al
// historial y volviendo a la página 1), vuelve a pedir la página y reemplaza
// las zonas data-spui-busqueda-zona: los chips, porque sus cantidades respetan
// la búsqueda, y la lista. El campo queda afuera de esas zonas, así que no
// pierde el foco ni lo escrito. Como la búsqueda queda en la dirección, el
// refresco automático (auto-refresh.js pide location.search) la respeta.
(function () {
    'use strict';

    var ESPERA_MS = 250;

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('form[data-spui-busqueda]');
        if (!form) return;
        var campo = form.querySelector('input[name="q"]');
        var limpiar = form.querySelector('[data-spui-busqueda-limpiar]');
        var timer = null, enCurso = null;

        function destino() {
            var u = new URL(location.href), v = campo.value.trim();
            if (v) u.searchParams.set('q', v); else u.searchParams.delete('q');
            u.searchParams.delete('pagina');
            return u;
        }

        function buscar() {
            clearTimeout(timer);
            var u = destino();
            if (u.href === location.href) return;
            history.replaceState(null, '', u);
            if (enCurso) enCurso.abort();
            enCurso = new AbortController();

            fetch(u, { credentials: 'same-origin', cache: 'no-store', signal: enCurso.signal })
                .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
                .then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    document.querySelectorAll('[data-spui-busqueda-zona]').forEach(function (zona) {
                        var nueva = zona.id && doc.getElementById(zona.id);
                        if (nueva) zona.innerHTML = nueva.innerHTML;
                    });
                    // Los botones de las tarjetas nuevas y sus tiempos relativos.
                    if (window.spui && window.spui.bindTableButtons) window.spui.bindTableButtons();
                    if (window.spui && window.spui.actualizarTiempos) window.spui.actualizarTiempos();
                    document.dispatchEvent(new CustomEvent('spui:refrescado'));
                })
                .catch(function () { /* cortado por otra búsqueda, o sin red: la próxima tecla reintenta */ });
        }

        function pintarLimpiar() { limpiar.hidden = campo.value === ''; }

        campo.addEventListener('input', function () {
            pintarLimpiar();
            clearTimeout(timer);
            timer = setTimeout(buscar, ESPERA_MS);
        });
        // Enter busca en el acto (sin JS, el formulario hace lo mismo por GET).
        form.addEventListener('submit', function (e) { e.preventDefault(); buscar(); });
        limpiar.addEventListener('click', function () {
            campo.value = '';
            pintarLimpiar();
            buscar();
            campo.focus();
        });
        campo.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && campo.value !== '') { e.preventDefault(); limpiar.click(); }
        });
    });
}());
