// Switches con etiqueta de estado. Declarativo vía data-*:
//   <input data-spui-switch data-switch-on="Activo" data-switch-off="Inactivo"
//          data-switch-variant="verde|azul" id="X">
//   <span class="spui-switch-status ..." id="X_lbl">…</span>
(function () {
    function apply(input) {
        var lbl = document.getElementById(input.id + '_lbl');
        if (!lbl) return;
        var on   = input.dataset.switchOn  || 'Sí';
        var off  = input.dataset.switchOff || 'No';
        var azul = input.dataset.switchVariant === 'azul';
        lbl.textContent = input.checked ? on : off;
        lbl.className = 'spui-switch-status ' + (input.checked
            ? (azul ? 'on-azul' : 'on')
            : (azul ? 'off-azul' : 'off'));
    }

    function initAll() {
        document.querySelectorAll('input[data-spui-switch]').forEach(apply);
    }

    // Estado inicial: al cargar la página y tras inyectar un form en el modal.
    document.addEventListener('DOMContentLoaded', initAll);
    document.addEventListener('spui:modal:loaded', initAll);

    // Cambios por interacción (delegación; cubre switches inyectados dinámicamente).
    document.addEventListener('change', function (e) {
        var t = e.target;
        if (t && t.matches && t.matches('input[data-spui-switch]')) apply(t);
    });
}());
