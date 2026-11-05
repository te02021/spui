// Selector de alcance de un formulario (05/11/2026): un grupo de radios y, por
// cada opción, un bloque que sólo se ve con ella elegida.
//
//   <div data-spui-alcance>
//       <input type="radio" value="edificio"> …
//       <div data-spui-si-alcance="edificio"><select>…</select></div>
//   </div>
//
// Al cambiar de opción se vacían las listas que dejan de verse, para que no viaje
// un destino que ya no está a la vista. El servidor lo controla igual.
(function () {
    'use strict';

    function aplicar(cont, limpiar) {
        var elegido = cont.querySelector('input[type="radio"]:checked');
        var valor = elegido ? elegido.value : '';
        cont.querySelectorAll('[data-spui-si-alcance]').forEach(function (bloque) {
            var visible = bloque.getAttribute('data-spui-si-alcance') === valor;
            bloque.hidden = !visible;
            if (visible || !limpiar) return;
            bloque.querySelectorAll('select').forEach(function (s) {
                s.value = '';
                s.dispatchEvent(new Event('change', { bubbles: true }));
            });
            bloque.querySelectorAll('input[type="checkbox"]').forEach(function (c) { c.checked = false; });
            bloque.querySelectorAll('.spui-campo-error').forEach(function (e) { e.remove(); });
            bloque.querySelectorAll('.is-invalid').forEach(function (e) { e.classList.remove('is-invalid'); });
        });
    }

    function iniciar(raiz) {
        (raiz || document).querySelectorAll('[data-spui-alcance]').forEach(function (c) { aplicar(c, false); });
    }

    document.addEventListener('DOMContentLoaded', function () { iniciar(); });
    document.addEventListener('spui:modal:loaded', function (e) { iniciar(e.target); });
    document.addEventListener('change', function (e) {
        var t = e.target;
        if (!t.matches || !t.matches('input[type="radio"]')) return;
        var cont = t.closest('[data-spui-alcance]');
        if (!cont) return;
        // Ya eligió: el aviso de que falta elegir deja de valer.
        cont.querySelectorAll(':scope > .spui-campo-error').forEach(function (err) { err.remove(); });
        aplicar(cont, true);
    });
}());
