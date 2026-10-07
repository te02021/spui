// Validación en vivo de campos, antes de guardar.
//
// Muestra el error DEBAJO del campo, con la misma clase que usan los errores
// que manda el servidor (.spui-campo-error), en el momento en que se puede
// saber: al salir de un campo obligatorio vacío, mientras se escribe, o al
// elegir un archivo. No reemplaza al servidor, que vuelve a validar todo al
// guardar: sólo adelanta lo que se puede saber sin preguntarle.
//
// Reglas, declaradas en el propio campo:
//
//   required                                      obligatorio: se avisa al salir
//                                                 del campo (Tab o clic en otro)
//                                                 si quedó vacío, y al guardar
//   data-spui-requerido-mensaje="..."             (opcional) texto propio
//
//   data-spui-validar-posterior="#id-del-otro"   el valor tiene que ser mayor
//   data-spui-validar-mensaje="..."               que el del otro campo (horas
//                                                 HH:MM, se comparan como texto)
//
//   data-spui-max-bytes="N"                       el archivo elegido no puede
//                                                 pesar más de N bytes
//   data-spui-formatos="jpg,png"                  extensiones admitidas, y cómo
//   data-spui-formatos-texto="JPG o PNG"          nombrarlas en el mensaje
//
// Sólo se validan campos visibles y habilitados: un campo de otro tipo de
// contenido, oculto, no cuenta.
//
// Dónde va el error: al final del contenedor [data-spui-campo] más cercano, o
// si no hay, justo después del input (o de su .spui-input-with-addon).
(function () {
    'use strict';

    var MSG_REQUERIDO = 'Este campo es obligatorio.';
    var MSG_ARCHIVO_REQUERIDO = 'Elegí un archivo.';

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

    /** Visible y habilitado: un campo oculto (de otro tipo de contenido) no se valida. */
    function activo(campo) {
        if (campo.disabled) return false;
        // Un input de archivo suele ir oculto dentro de un <label> que hace de
        // botón: se mira si se ve su contenedor.
        var visible = campo.type === 'file' ? (contenedor(campo) || campo.parentElement) : campo;
        return !!(visible && visible.getClientRects().length);
    }

    function vacio(campo) {
        if (campo.type === 'file') return !(campo.files && campo.files.length);
        return (campo.value || '').trim() === '';
    }

    // ── Regla: obligatorio ──────────────────────────────────────────────────
    function esObligatorio(campo) {
        return campo.required && ['checkbox', 'radio', 'hidden', 'submit', 'button'].indexOf(campo.type) === -1;
    }

    /** true si está bien. */
    function validarRequerido(campo) {
        if (!esObligatorio(campo) || !activo(campo)) return true;
        if (vacio(campo)) {
            mostrar(campo, campo.dataset.spuiRequeridoMensaje || (campo.type === 'file' ? MSG_ARCHIVO_REQUERIDO : MSG_REQUERIDO));
            return false;
        }
        return true;
    }

    // ── Regla: posterior a otro campo ───────────────────────────────────────
    function validarPosterior(campo) {
        var form = campo.form || document;
        var otro = form.querySelector(campo.dataset.spuiValidarPosterior);
        // Sin los dos valores no hay nada que comparar: que falte uno lo dice
        // la regla de obligatorio, no ésta.
        if (!otro || !campo.value || !otro.value) return true;

        if (campo.value <= otro.value) {
            mostrar(campo, campo.dataset.spuiValidarMensaje || 'Tiene que ser posterior.');
            return false;
        }
        return true;
    }

    // ── Reglas de archivo: formato y tamaño ─────────────────────────────────
    /** Tamaño legible, con la unidad que corresponde y coma decimal. */
    function formatear(bytes) {
        var unidades = [['GB', 1024 * 1024 * 1024], ['MB', 1024 * 1024], ['KB', 1024]];
        for (var i = 0; i < unidades.length; i++) {
            if (bytes >= unidades[i][1]) {
                var n = bytes / unidades[i][1];
                // Un decimal sólo cuando aporta (2,1 GB; 15 MB; no 15,0 MB).
                var texto = n >= 100 || Number.isInteger(n) ? String(Math.round(n)) : n.toFixed(1).replace('.0', '');
                return texto.replace('.', ',') + ' ' + unidades[i][0];
            }
        }
        return bytes + ' bytes';
    }

    function extension(nombre) {
        var i = nombre.lastIndexOf('.');
        return i === -1 ? '' : nombre.slice(i + 1).toLowerCase();
    }

    /** true si el archivo elegido (si hay) tiene formato y tamaño admitidos. */
    function validarArchivo(campo) {
        var archivo = campo.files && campo.files[0];
        if (!archivo) return true;

        var formatos = (campo.dataset.spuiFormatos || '').split(',').map(function (f) { return f.trim(); }).filter(Boolean);
        if (formatos.length && formatos.indexOf(extension(archivo.name)) === -1) {
            mostrar(campo, 'Formato no admitido: elegí un archivo ' + (campo.dataset.spuiFormatosTexto || formatos.join(', ').toUpperCase()) + '.');
            return false;
        }

        var max = parseInt(campo.dataset.spuiMaxBytes, 10);
        if (max && archivo.size > max) {
            mostrar(campo, 'El archivo pesa ' + formatear(archivo.size) + ' y el máximo es ' + formatear(max) + '. Elegí uno más liviano.');
            return false;
        }
        return true;
    }

    // ── Orquestación ────────────────────────────────────────────────────────
    /**
     * Todas las reglas de un campo, en orden; la primera que falla es la que
     * se muestra. Devuelve true si el campo quedó bien.
     */
    function revisar(campo, alSalir, conservarAvisos) {
        var tieneRegla = campo.matches('[data-spui-validar-posterior], input[type="file"]') || esObligatorio(campo);

        if (campo.type === 'file') {
            if (!validarArchivo(campo)) return false;
            if (alSalir && !validarRequerido(campo)) return false;
        } else {
            // Mientras se escribe no se reprocha que esté vacío (recién se
            // está completando): eso se dice al salir del campo o al guardar.
            if (alSalir && !validarRequerido(campo)) return false;
            if (campo.matches('[data-spui-validar-posterior]') && !validarPosterior(campo)) return false;
        }

        // Llegó hasta acá: está bien. Se va cualquier aviso viejo (del servidor
        // o de una revisión anterior), incluido el de un campo sin regla propia
        // que el servidor había marcado y que se empezó a corregir.
        // Salvo que el campo se acabe de restaurar tras un error del servidor:
        // ese error lo detectó el servidor (ej: el contenido real del archivo)
        // y acá no se puede saber si ya no aplica.
        if (!conservarAvisos && (tieneRegla || campo.classList.contains('is-invalid'))) limpiar(campo);
        return true;
    }

    function alCambiar(e) {
        var t = e.target;
        if (!(t instanceof Element) || !t.matches('input, select, textarea')) return;
        // Un select o un archivo "se completa" al elegir: cuenta como salir.
        revisar(t, e.type === 'change' && (t.tagName === 'SELECT' || t.type === 'file'), e.spuiRestaurado === true);

        // Si cambió el campo de referencia (el "inicio"), revisar también a
        // los que dependen de él (el "fin").
        if (t.id && t.form) {
            t.form.querySelectorAll('[data-spui-validar-posterior="#' + t.id + '"]').forEach(function (c) { revisar(c, false); });
        }
    }

    // Último elemento presionado con mouse/dedo: para distinguir "hizo clic en
    // Cancelar" (se va, no hay nada que reprochar) de "llegó a Cancelar con
    // Tab" (siguió recorriendo el formulario: el campo que dejó vacío sí se
    // avisa). Pasaba con el último campo de un formulario, cuyo Tab cae justo
    // en Cancelar.
    var presionado = null;
    document.addEventListener('pointerdown', function (e) { presionado = e.target; }, true);

    // Al salir de un campo: si es obligatorio y quedó vacío, se avisa ya. Sólo
    // cuando el foco pasa a OTRO elemento de la página (Tab, clic en otro
    // campo): si se cambia de ventana, o se hace clic en Cancelar / cerrar, no
    // se reprocha nada.
    function alSalir(e) {
        var t = e.target;
        if (!(t instanceof Element) || !t.matches('input, select, textarea')) return;
        var destino = e.relatedTarget;
        if (!destino) return;
        var cerrar = destino.closest && destino.closest('[data-spui-close], .spui-abm-modal-close');
        if (cerrar && presionado && cerrar.contains(presionado)) return;
        revisar(t, true);
    }

    document.addEventListener('input', alCambiar);
    document.addEventListener('change', alCambiar);
    document.addEventListener('focusout', alSalir);

    // Al guardar: se revisan todos los campos visibles. Si alguno está mal no
    // se envía (se ahorra la vuelta al servidor y, con un archivo demasiado
    // pesado, que PHP descarte el pedido ENTERO). En fase de captura para
    // frenarlo antes que el envío por AJAX de modal.js. El servidor sigue
    // validando todo igual cuando sí se envía.
    document.addEventListener('submit', function (e) {
        var campos = Array.from(e.target.querySelectorAll('input, select, textarea'))
            .filter(function (c) { return activo(c) || c.type === 'file'; });
        var malos = campos.filter(function (c) { return !revisar(c, true); });
        if (malos.length) {
            e.preventDefault();
            e.stopImmediatePropagation();
            try { malos[0].focus({ preventScroll: false }); } catch (err) { /* no enfocable */ }
            if (malos[0].scrollIntoView) malos[0].scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
    }, true);

    window.spui = window.spui || {};
    window.spui.formatearTamano = formatear;
}());
