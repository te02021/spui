// Sliders con el valor visible al costado. Declarativo vía data-*:
//   <input type="range" data-spui-range id="X">
//   <span id="X_val">80%</span>
//
// Mismo patrón que switch-toggle.js. Escucha 'input' y no 'change' porque el
// valor tiene que acompañar al dedo mientras se arrastra: 'change' sólo dispara
// al soltar y la barra se vería muda todo el recorrido.
(function () {
    function apply(input) {
        var out = document.getElementById(input.id + '_val');
        if (!out) return;
        out.textContent = input.value + (input.dataset.rangeSuffix || '%');
    }

    function initAll() {
        document.querySelectorAll('input[data-spui-range]').forEach(apply);
    }

    // Estado inicial: al cargar la página y tras inyectar un form en el modal.
    document.addEventListener('DOMContentLoaded', initAll);
    document.addEventListener('spui:modal:loaded', initAll);

    // Delegación: cubre sliders inyectados dinámicamente.
    document.addEventListener('input', function (e) {
        var t = e.target;
        if (t && t.matches && t.matches('input[data-spui-range]')) apply(t);
    });
}());
