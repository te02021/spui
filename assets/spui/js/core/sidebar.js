// Toggle del sidebar en mobile con overlay.
// Clona el botón para descartar listeners previos (ej. el main.js de Shared)
// y evitar un doble toggle que se cancele a sí mismo.
document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('btnMenuToggle');
    if (!btn) return;

    var clone = btn.cloneNode(true);
    btn.parentNode.replaceChild(clone, btn);

    var sidebar = document.querySelector('.unraf-sidebar');
    var overlay = document.getElementById('sidebarOverlay');

    function toggle() {
        if (sidebar) sidebar.classList.toggle('abierto');
        if (overlay) overlay.classList.toggle('activo');
    }

    clone.addEventListener('click', toggle);
    if (overlay) overlay.addEventListener('click', toggle);
});
