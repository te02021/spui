// Validación en vivo de campos, antes de guardar.
//
// Muestra el error DEBAJO del campo, con la misma clase que usan los errores
// que manda el servidor (.spui-campo-error), mientras se escribe o al elegir
// un archivo. No reemplaza al servidor, que vuelve a validar todo al guardar:
// sólo adelanta lo que se puede saber sin preguntarle.
//
// Reglas, declaradas en el propio campo:
//
//   data-spui-validar-posterior="#id-del-otro"   el valor tiene que ser mayor
//   data-spui-validar-mensaje="..."               que el del otro campo (horas
//                                                 HH:MM, se comparan como texto)
//
//   data-spui-max-bytes="N"                       el archivo elegido no puede
//                                                 pesar más de N bytes
//
// Dónde va el error: al final del contenedor [data-spui-campo] más cercano, o
// si no hay, justo después del input (o de su .spui-input-with-addon).
(function () {
    'use strict';

    function contenedor(campo) {
        return campo.closest('[data-spui-campo]');
    }

    /** Los errores que se muestran hoy para este campo (del servidor o en vivo). */
    function erroresDe(campo) {
        var cont = contenedor(campo);
        if (cont) return Array.from(cont.querySelectorAll('.spui-campo-error'));

        var ancla = campo.closest('.spui-input-with-addon') || campo;
        var out = [];
        for (var el = ancla.nextElementSibling; el && el.classList.contains('spui-campo-error'); el = el.nextElementSibling) {
            out.push(el);
        }
        return out;
    }

    function limpiar(campo) {
        erroresDe(campo).forEach(function (e) { e.remove(); });
        campo.classList.remove('is-invalid');
    }

    function mostrar(campo, mensaje) {
        limpiar(campo);
        var p = document.createElement('p');
        p.className = 'spui-campo-error';
        p.setAttribute('role', 'alert');
        p.textContent = mensaje;

        var cont = contenedor(campo);
        if (cont) {
            cont.appendChild(p);
        } else {
            var ancla = campo.closest('.spui-input-with-addon') || campo;
            ancla.insertAdjacentElement('afterend', p);
        }
        campo.classList.add('is-invalid');
    }

    // ── Regla: posterior a otro campo ───────────────────────────────────────
    function validarPosterior(campo) {
        var form  = campo.form || document;
        var otro  = form.querySelector(campo.dataset.spuiValidarPosterior);
        // Sin los dos valores no hay nada que comparar: que falte uno lo dice
        // el "requerido" del servidor al guardar, no esta regla.
        if (!otro || !campo.value || !otro.value) { limpiar(campo); return; }

        if (campo.value <= otro.value) {
            mostrar(campo, campo.dataset.spuiValidarMensaje || 'Tiene que ser posterior.');
        } else {
            limpiar(campo);
        }
    }

    // ── Regla: tamaño máximo de archivo ─────────────────────────────────────
    function formatear(bytes) {
        if (bytes >= 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1).replace('.0', '') + ' MB';
        return Math.max(1, Math.round(bytes / 1024)) + ' KB';
    }

    /** true si el archivo elegido entra. */
    function validarTamano(campo) {
        var max = parseInt(campo.dataset.spuiMaxBytes, 10);
        var archivo = campo.files && campo.files[0];
        if (!max || !archivo || archivo.size <= max) { limpiar(campo); return true; }

        mostrar(campo, 'El archivo pesa ' + formatear(archivo.size) + ' y el máximo es ' + formatear(max) + '. Elegí uno más liviano.');
        return false;
    }

    function revisar(campo) {
        var conRegla = false;
        if (campo.matches('[data-spui-validar-posterior]')) { validarPosterior(campo); conRegla = true; }
        if (campo.matches('[data-spui-max-bytes]')) { validarTamano(campo); conRegla = true; }

        // Un campo que el servidor marcó (ej: "El nombre es requerido") y que
        // no tiene regla acá: apenas se lo empieza a corregir, el aviso viejo
        // se va. Si sigue mal, el servidor lo vuelve a decir al guardar.
        if (!conRegla && campo.classList.contains('is-invalid')) limpiar(campo);
    }

    function alCambiar(e) {
        var t = e.target;
        if (!(t instanceof Element)) return;
        revisar(t);

        // Si cambió el campo de referencia (el "inicio"), revisar también a
        // los que dependen de él (el "fin").
        if (t.id && t.form) {
            t.form.querySelectorAll('[data-spui-validar-posterior="#' + t.id + '"]').forEach(validarPosterior);
        }
    }

    document.addEventListener('input', alCambiar);
    document.addEventListener('change', alCambiar);

    // Un archivo demasiado pesado no se manda: PHP descartaría el pedido
    // ENTERO (todos los campos llegan vacíos) y lo único que se podría
    // contestar es un aviso genérico. En fase de captura para frenarlo antes
    // que el envío por AJAX de modal.js.
    document.addEventListener('submit', function (e) {
        var excedidos = Array.from(e.target.querySelectorAll('input[type="file"][data-spui-max-bytes]'))
            .filter(function (campo) { return !validarTamano(campo); });
        if (excedidos.length) {
            e.preventDefault();
            e.stopImmediatePropagation();
            excedidos[0].focus();
        }
    }, true);
}());
