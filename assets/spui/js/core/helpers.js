// Helpers globales compartidos (window.spui). Se carga primero en spuiMap.js.
// Patrón: módulos autónomos que usan globals (como el mostrarToast de Shared).
(function () {
    window.spui = window.spui || {};

    window.spui.escHtml = function (s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    };

    function dismissFlash(el) {
        el.classList.add('flash-hiding');
        setTimeout(function () { el.parentNode && el.remove(); }, 850);
    }

    // Flash programático — lo usa el motor de modales tras una acción AJAX.
    window.spui.showFlash = function (msg, type) {
        var container = document.querySelector('.flash-container');
        if (!container) return;
        var cls = type === 'error' ? 'danger' : (type === 'warning' ? 'warning' : 'success');
        var el = document.createElement('div');
        el.className = 'alert alert-' + cls + ' fade show';
        el.setAttribute('role', 'alert');
        el.innerHTML = '<button type="button" class="flash-close-btn" aria-label="Cerrar">&times;</button>' + window.spui.escHtml(msg);
        el.querySelector('.flash-close-btn').addEventListener('click', function () { dismissFlash(el); });
        container.appendChild(el);
        setTimeout(function () { dismissFlash(el); }, 5000);
    };

    // Botón copiar con feedback ✓ y fallback para contexto no-seguro (HTTP por IP).
    window.spui.attachCopyButton = function (btn, getText) {
        if (!btn) return;
        var originalHtml = btn.innerHTML;
        btn.addEventListener('click', function () {
            var text = getText();
            function ok() {
                btn.innerHTML = '<span style="color:var(--unraf-success,#4A9930);font-weight:700">✓</span>';
                setTimeout(function () { btn.innerHTML = originalHtml; }, 1500);
            }
            function legacy() {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.focus();
                ta.select();
                try { document.execCommand('copy'); ok(); } catch (e) { /* noop */ }
                document.body.removeChild(ta);
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(ok).catch(legacy);
            } else {
                legacy();
            }
        });
    };

    // Auto-cierre de los flashes renderizados por el servidor.
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.flash-container .alert').forEach(function (alert) {
            var t = setTimeout(function () { dismissFlash(alert); }, 5000);
            var btn = alert.querySelector('.flash-close-btn');
            if (btn) btn.addEventListener('click', function () { clearTimeout(t); dismissFlash(alert); });
        });
    });
}());
