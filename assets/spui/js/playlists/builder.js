// Drag & drop de los ítems de una playlist, con SortableJS (cargado por CDN
// desde base.html.twig). La lista vive dentro del modal de segundo nivel, que
// se abre desde "Ver ítems" en el modal de edición.
//
// La URL de reorder viene en [data-spui-sortable][data-reorder-url]. En modo
// sólo lectura el template no emite esos atributos, así que acá no se engancha
// nada y la lista queda estática.
(function () {
    function initSortables() {
        if (typeof Sortable === 'undefined') return;

        document.querySelectorAll('[data-spui-sortable]').forEach(function (listEl) {
            // Guarda obligatoria: spui:modal:loaded se dispara en CADA inyección
            // de contenido, y sin esto se acumularían instancias de Sortable
            // sobre el mismo <ul> — cada arrastre mandaría un POST por instancia.
            if (listEl._spuiSortable) return;
            listEl._spuiSortable = true;

            var reorderUrl = listEl.dataset.reorderUrl;
            if (!reorderUrl) return;

            Sortable.create(listEl, {
                handle: '.item-drag-handle',
                ghostClass: 'sortable-ghost',
                animation: 150,
                onEnd: function () {
                    var items = listEl.querySelectorAll('.sortable-item');
                    var orden = Array.from(items).map(function (el) { return parseInt(el.dataset.itemId, 10); });

                    fetch(reorderUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({ orden: orden }),
                    }).then(function (res) {
                        if (!res.ok) throw new Error('HTTP ' + res.status);
                        // Renumerar los badges en el cliente: el orden nuevo ya
                        // quedó guardado, no hace falta recargar la lista.
                        items.forEach(function (el, i) {
                            var badge = el.querySelector('.spui-badge-borrador');
                            if (badge) badge.textContent = i + 1;
                        });
                    }).catch(function () {
                        // Antes esto se tragaba en silencio y la pantalla quedaba
                        // mostrando un orden que la base no tenía.
                        window.spui.showFlash('No se pudo guardar el nuevo orden. Recargá para ver el orden real.', 'error');
                    });
                },
            });
        });
    }

    document.addEventListener('DOMContentLoaded', initSortables);
    document.addEventListener('spui:modal:loaded', initSortables);
}());
