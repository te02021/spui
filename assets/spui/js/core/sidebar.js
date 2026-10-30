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

// Sidebar plegable en escritorio (30/10/2026). La clase va en <html> (el
// <head> la pone antes de pintar si quedó plegado) y la elección se recuerda
// entre secciones. Al cambiar el ancho del contenido se avisa con un resize:
// los gráficos de ECharts (Dashboard, Telemetría) se acomodan solos.
document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('spui-sidebar-plegar');
    if (!btn) return;
    var raiz = document.documentElement;

    function pintar() {
        var plegado = raiz.classList.contains('spui-sidebar-plegado');
        var texto = plegado ? 'Mostrar menú' : 'Ocultar menú';
        btn.setAttribute('aria-expanded', plegado ? 'false' : 'true');
        btn.setAttribute('aria-label', texto);
        btn.title = texto;
    }

    btn.addEventListener('click', function () {
        var plegado = raiz.classList.toggle('spui-sidebar-plegado');
        try { localStorage.setItem('spui.sidebar.plegado', plegado ? '1' : '0'); } catch (e) { /* sin almacenamiento: dura hasta recargar */ }
        pintar();
        // El contenido no cambia de ancho, pero al terminar de deslizarse se
        // avisa igual: si algo depende del ancho de la ventana, se acomoda.
        setTimeout(function () { window.dispatchEvent(new Event('resize')); }, 320);
    });
    pintar();
});
