// Equivalencia legible de una cantidad de minutos, al lado del campo, en vivo:
//   <input data-spui-duracion-minutos id="X">  →  <small id="X_texto">cada 1 hora y 30 min</small>
//
// Mismas palabras que la macro texto.duracion_minutos (templates/_macros/texto.html.twig),
// para que el listado, el detalle y el formulario digan lo mismo.
(function () {
    'use strict';

    function plural(n, uno, varios) { return n + ' ' + (n === 1 ? uno : varios); }

    function texto(m) {
        if (m < 60) return plural(m, 'minuto', 'minutos');
        if (m < 1440) {
            var h = Math.floor(m / 60), r = m % 60;
            return plural(h, 'hora', 'horas') + (r ? ' y ' + r + ' min' : '');
        }
        var d = Math.floor(m / 1440), rh = Math.floor((m % 1440) / 60);
        return plural(d, 'día', 'días') + (rh ? ' y ' + rh + ' h' : '');
    }

    function actualizar(input) {
        var out = document.getElementById(input.id + '_texto');
        if (!out) return;
        var v = (input.value || '').trim();
        // Sólo un entero de 1 o más tiene equivalencia; lo demás lo marca la validación.
        out.textContent = /^[0-9]+$/.test(v) && Number(v) >= 1 ? 'Sincroniza cada ' + texto(Number(v)) + '.' : '';
    }

    function todos() {
        document.querySelectorAll('input[data-spui-duracion-minutos]').forEach(actualizar);
    }

    document.addEventListener('DOMContentLoaded', todos);
    document.addEventListener('spui:modal:loaded', todos);
    document.addEventListener('input', function (e) {
        if (e.target && e.target.matches && e.target.matches('input[data-spui-duracion-minutos]')) actualizar(e.target);
    });

    window.spui = window.spui || {};
    window.spui.duracionMinutos = texto;
}());
