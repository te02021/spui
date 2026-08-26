// Campo de sonido de alerta (crear/editar): preview del archivo elegido (o
// del ya guardado) con un botón para quitarlo, sin checkbox aparte.
//
// El botón trash siempre hace lo mismo sea cual sea el origen del sonido que
// se está mostrando (el ya guardado en el servidor, o uno recién elegido sin
// subir todavía): oculta la fila, limpia la selección del <input type=file> y
// prende el flag oculto 'sonido_quitar'. En el servidor un archivo subido
// siempre gana sobre ese flag (AlertaCmsController::aplicarSonido), así que
// dejarlo prendido no rompe nada si después se elige un archivo nuevo.
(function () {
    var SELECTOR_CAMPO = '[data-spui-sonido-field]';

    function porCampo(campo, selector) {
        return campo.querySelector(selector);
    }

    function mostrarFila(campo, src, nombre, esObjectUrl) {
        var fila   = porCampo(campo, '[data-spui-sonido-row]');
        var audio  = porCampo(campo, '[data-spui-sonido-row] audio');
        var nombreEl = porCampo(campo, '[data-spui-sonido-row] .spui-sonido-nombre');
        if (!fila || !audio) return;

        // Revoca el object URL ANTERIOR (de una selección previa en esta misma
        // carga del modal) — nunca el que se está por asignar ahora. El
        // llamador NO debe guardar el nuevo en audio.dataset.objectUrl antes
        // de esta función: si lo hace, esta revocación borra la URL recién
        // creada antes de usarla (eso rompía la reproducción: el <audio>
        // quedaba apuntando a un blob ya revocado — ERR_FILE_NOT_FOUND).
        if (audio.dataset.objectUrl) {
            URL.revokeObjectURL(audio.dataset.objectUrl);
            delete audio.dataset.objectUrl;
        }

        audio.src = src;
        // Sin este load() el <audio> se queda con el estado interno de ANTES
        // del cambio de src (o "sin fuente" si nunca tuvo una): es lo que
        // hacía que un archivo recién elegido apareciera en la fila pero no
        // se pudiera reproducir hasta guardar y volver a abrir el modal.
        audio.load();
        if (esObjectUrl) audio.dataset.objectUrl = src;
        if (nombreEl) nombreEl.textContent = nombre || '';
        fila.hidden = false;
    }

    function ocultarFila(campo) {
        var fila  = porCampo(campo, '[data-spui-sonido-row]');
        var audio = porCampo(campo, '[data-spui-sonido-row] audio');
        if (!fila || !audio) return;

        if (audio.dataset.objectUrl) {
            URL.revokeObjectURL(audio.dataset.objectUrl);
            delete audio.dataset.objectUrl;
        }
        audio.removeAttribute('src');
        audio.load();
        fila.hidden = true;
    }

    function quitarSonido(campo) {
        var input = porCampo(campo, '[data-spui-sonido-input]');
        var flag  = porCampo(campo, '[data-spui-sonido-quitar-flag]');
        if (input) input.value = '';
        if (flag) flag.value = '1';
        ocultarFila(campo);
    }

    function initCampo(campo) {
        if (campo.dataset.spuiSonidoInit) return;
        campo.dataset.spuiSonidoInit = '1';

        var input = porCampo(campo, '[data-spui-sonido-input]');
        var trash = porCampo(campo, '[data-spui-sonido-quitar]');

        if (input) {
            input.addEventListener('change', function () {
                var archivo = input.files && input.files[0];
                if (!archivo) return;

                var url = URL.createObjectURL(archivo);
                mostrarFila(campo, url, archivo.name, true);

                // Elegir un archivo nuevo cancela cualquier "quitar" previo:
                // gana el archivo, pero no hace falta dejar el flag prendido
                // confundiendo una eventual inspección del form.
                var flag = porCampo(campo, '[data-spui-sonido-quitar-flag]');
                if (flag) flag.value = '0';
            });
        }

        if (trash) {
            trash.addEventListener('click', function () {
                quitarSonido(campo);
            });
        }
    }

    function initTodos() {
        document.querySelectorAll(SELECTOR_CAMPO).forEach(initCampo);
    }

    document.addEventListener('DOMContentLoaded', initTodos);
    document.addEventListener('spui:modal:loaded', initTodos);
}());
