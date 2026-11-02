// Importar cronograma (noviembre 2026, contenidos/importar/*).
//
//   - Paso 1: el archivo se lee apenas se elige (no hay un botón "Leer").
//   - Paso 2: en cada hoja, según el destino elegido, se muestra el título
//     (cronograma nuevo) o el aviso de lo que se reemplaza
//     (data-spui-si-destino="valor", data-spui-si-destino-no="valor").
(function () {
    'use strict';

    function actualizarHoja(hoja) {
        var select = hoja.querySelector('[data-spui-destino]');
        if (!select) return;
        var v = select.value;
        hoja.querySelectorAll('[data-spui-si-destino]').forEach(function (el) {
            el.hidden = el.getAttribute('data-spui-si-destino') !== v;
        });
        hoja.querySelectorAll('[data-spui-si-destino-no]').forEach(function (el) {
            el.hidden = el.getAttribute('data-spui-si-destino-no') === v;
        });
        hoja.classList.toggle('is-omitida', v === 'omitir');
    }

    document.addEventListener('change', function (e) {
        var t = e.target;
        if (t.matches && t.matches('[data-spui-enviar-al-elegir]') && t.files && t.files.length && t.form) {
            var leyendo = t.form.querySelector('[data-spui-importar-leyendo]');
            if (leyendo) leyendo.hidden = false;
            // Después de validacion-vivo.js (tamaño y formato): si marcó el
            // campo como inválido, no se envía.
            setTimeout(function () {
                if (t.classList.contains('is-invalid')) { if (leyendo) leyendo.hidden = true; return; }
                t.form.requestSubmit();
            }, 0);
        }
        if (t.matches && t.matches('[data-spui-destino]')) {
            actualizarHoja(t.closest('[data-spui-importar-hoja]'));
        }
    });

    document.addEventListener('spui:modal:loaded', function () {
        document.querySelectorAll('[data-spui-importar-hoja]').forEach(actualizarHoja);
    });
}());
