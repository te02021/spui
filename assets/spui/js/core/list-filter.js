// Filtro de listas en el cliente. Declarativo vía data-*:
//   <input data-spui-filter-input data-spui-filter-target="#mi-lista">
//   <ul id="mi-lista">
//     <li data-filter-text="titulo tipo en minúsculas">…</li>
//   </ul>
//   <p data-spui-filter-empty hidden>Sin resultados</p>   (opcional, hermano)
//
// El texto contra el que se compara viene precomputado en el data-filter-text y
// no se lee del DOM: así el filtro no depende del markup de la fila y el match
// sale case-insensitive sin normalizar en cada tecla.
(function () {
    function filtrar(input) {
        var lista = document.querySelector(input.dataset.spuiFilterTarget || '');
        if (!lista) return;

        var q = (input.value || '').toLowerCase().trim();
        var visibles = 0;

        lista.querySelectorAll('[data-filter-text]').forEach(function (el) {
            var coincide = q === '' || (el.dataset.filterText || '').indexOf(q) !== -1;
            el.hidden = !coincide;
            if (coincide) visibles++;
        });

        var vacio = document.querySelector(input.dataset.spuiFilterEmpty || '');
        if (vacio) vacio.hidden = visibles > 0;
    }

    function initAll() {
        document.querySelectorAll('input[data-spui-filter-input]').forEach(function (input) {
            // Guarda de idempotencia: spui:modal:loaded se dispara en cada
            // inyección de contenido y sin esto se acumularían listeners sobre
            // el mismo input. El flag muere con el nodo cuando el modal recarga.
            if (input._spuiFilter) return;
            input._spuiFilter = true;
            input.addEventListener('input', function () { filtrar(input); });
        });
    }

    document.addEventListener('DOMContentLoaded', initAll);
    document.addEventListener('spui:modal:loaded', initAll);
}());
