// Campo de archivo de Contenido (editar): muestra nombre + miniatura (si es
// imagen) del archivo recién elegido sin esperar a guardar, y deja el ícono
// de vista previa apuntando a ese mismo archivo sin subir todavía.
// Mismo patrón que alerta-sonido-field.js.
(function () {
    var SELECTOR_CAMPO = '[data-spui-archivo-field]';

    function porCampo(campo, selector) {
        return campo.querySelector(selector);
    }

    function initCampo(campo) {
        if (campo.dataset.spuiArchivoInit) return;
        campo.dataset.spuiArchivoInit = '1';

        var input = porCampo(campo, '[data-spui-archivo-input]');
        if (!input) return;

        input.addEventListener('change', function () {
            var archivo = input.files && input.files[0];
            if (!archivo) return;

            var fila       = porCampo(campo, '[data-spui-archivo-row]');
            var thumb      = porCampo(campo, '[data-spui-archivo-thumb]');
            var nombre     = porCampo(campo, '[data-spui-archivo-nombre]');
            var previewBtn = porCampo(campo, '[data-spui-archivo-preview-btn]');

            if (thumb && thumb.dataset.objectUrl) {
                URL.revokeObjectURL(thumb.dataset.objectUrl);
            }
            var url = URL.createObjectURL(archivo);

            if (thumb) {
                thumb.src = url;
                thumb.style.display = '';
                thumb.dataset.objectUrl = url;
            }
            if (nombre) nombre.textContent = archivo.name;
            if (previewBtn) {
                previewBtn.setAttribute('data-spui-preview-url', url);
                previewBtn.setAttribute('data-spui-preview-nombre', archivo.name);
            }
            if (fila) fila.hidden = false;
        });
    }

    function initTodos() {
        document.querySelectorAll(SELECTOR_CAMPO).forEach(initCampo);
    }

    document.addEventListener('DOMContentLoaded', initTodos);
    document.addEventListener('spui:modal:loaded', initTodos);
}());
