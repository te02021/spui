// Los enlaces de destino de un QR (contenidos/_qr_destinos): un campo por enlace.
// Con uno, el botón + suma otro; con dos, cada uno tiene su tacho. Con uno solo no
// hay tacho: el enlace es obligatorio, se corrige escribiendo en el campo.
(function () {
    'use strict';

    function filasDe(grupo) {
        return Array.from(grupo.querySelectorAll('.spui-qr-destino'));
    }

    function actualizar(grupo) {
        var filas = filasDe(grupo);
        var max = parseInt(grupo.getAttribute('data-spui-qr-max'), 10) || 2;
        grupo.querySelectorAll('[data-spui-qr-quitar]').forEach(function (b) { b.hidden = filas.length < 2; });
        grupo.querySelectorAll('[data-spui-qr-agregar], [data-spui-qr-ayuda]').forEach(function (b) { b.hidden = filas.length >= max; });
        // El error de los enlaces en conjunto ya no corresponde: se vuelve a ver al guardar.
        grupo.querySelectorAll(':scope > .spui-campo-error').forEach(function (e) { e.remove(); });
    }

    function agregar(grupo) {
        var filas = filasDe(grupo);
        var nueva = filas[0].cloneNode(true);
        var input = nueva.querySelector('input');
        input.value = '';
        input.setAttribute('value', '');
        input.removeAttribute('id');
        input.required = false;
        input.classList.remove('is-invalid');
        input.setAttribute('aria-label', 'Segundo enlace');
        nueva.querySelectorAll('.spui-campo-error').forEach(function (e) { e.remove(); });
        filas[filas.length - 1].after(nueva);
        actualizar(grupo);
        input.focus();
    }

    function quitar(grupo, fila) {
        var primero = filasDe(grupo)[0].querySelector('input');
        var id = primero.id;
        var requerido = primero.required;
        fila.remove();
        // El que queda pasa a ser el primero: el de la etiqueta y el obligatorio.
        var queda = filasDe(grupo)[0].querySelector('input');
        queda.id = id;
        queda.required = requerido;
        queda.removeAttribute('aria-label');
        actualizar(grupo);
        queda.focus();
    }

    document.addEventListener('click', function (e) {
        var boton = e.target.closest && e.target.closest('[data-spui-qr-agregar], [data-spui-qr-quitar]');
        if (!boton) return;
        var grupo = boton.closest('[data-spui-qr-destinos]');
        if (!grupo) return;
        if (boton.hasAttribute('data-spui-qr-agregar')) agregar(grupo);
        else quitar(grupo, boton.closest('.spui-qr-destino'));
    });
})();
