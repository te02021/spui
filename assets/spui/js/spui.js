// Helper: ejecuta callback cuando el DOM esté listo.
// Necesario porque los ES modules pueden correr antes o después de DOMContentLoaded.
function onReady(fn) {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fn);
    } else {
        fn();
    }
}

// ─── MODAL DE CONFIRMACIÓN (form-based, sin AJAX) ────────────────────────────
onReady(() => {
    const modalOverlay = document.getElementById('modalConfirmacionSpui');
    if (!modalOverlay) return;

    const modalTitulo    = document.getElementById('spuiModalTitulo');
    const modalMensaje   = document.getElementById('spuiModalMensaje');
    const modalForm      = document.getElementById('spuiModalForm');
    const modalConfirmar = document.getElementById('spuiModalConfirmar');
    const modalCerrar    = document.getElementById('spuiModalCerrar');
    const modalCancelar  = document.getElementById('spuiModalCancelar');

    const variantClasses = {
        danger:  'unraf-btn-danger',
        success: 'spui-btn-confirm-success',
        warning: 'spui-btn-confirm-warning',
    };

    function cerrar() {
        modalOverlay.classList.remove('is-active');
    }

    function abrir({ url, titulo, mensaje, variant }) {
        if (modalTitulo)    modalTitulo.textContent  = titulo  || 'Confirmar acción';
        if (modalMensaje)   modalMensaje.textContent = mensaje || '¿Estás seguro?';
        if (modalForm)      modalForm.action         = url;

        if (modalConfirmar) {
            modalConfirmar.className = 'unraf-btn ' + (variantClasses[variant] || variantClasses.danger);
        }

        modalOverlay.classList.add('is-active');
    }

    if (modalCerrar)   modalCerrar.addEventListener('click', cerrar);
    if (modalCancelar) modalCancelar.addEventListener('click', cerrar);
    modalOverlay.addEventListener('click', (e) => {
        if (e.target === modalOverlay) cerrar();
    });

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-confirm-url]');
        if (!btn) return;
        e.preventDefault();

        abrir({
            url:     btn.getAttribute('data-confirm-url'),
            titulo:  btn.getAttribute('data-confirm-title')   || null,
            mensaje: btn.getAttribute('data-confirm-message') || null,
            variant: btn.getAttribute('data-confirm-variant') || 'danger',
        });
    });
});

// ─── SIDEBAR TOGGLE ───────────────────────────────────────────────────────────
onReady(() => {
    const overlay = document.getElementById('sidebarOverlay');
    const sidebar = document.querySelector('.unraf-sidebar');
    if (!overlay || !sidebar) return;

    function syncOverlay() {
        overlay.classList.toggle('activo', sidebar.classList.contains('abierto'));
    }

    // El botón es manejado por main.js (Shared) que hace sidebar.classList.toggle('abierto').
    // Aquí solo sincronizamos el overlay después de que main.js actúe.
    const btn = document.getElementById('btnMenuToggle');
    if (btn) btn.addEventListener('click', () => setTimeout(syncOverlay, 0));

    overlay.addEventListener('click', () => {
        sidebar.classList.remove('abierto');
        syncOverlay();
    });
});

// ─── FLASH DISMISSAL ──────────────────────────────────────────────────────────
onReady(() => {
    document.querySelectorAll('.flash-container .alert').forEach((a) => {
        const h = setTimeout(() => a.classList.add('flash-hiding'), 4000);
        const r = setTimeout(() => a.remove(), 5000);
        const closeBtn = a.querySelector('.btn-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                clearTimeout(h);
                clearTimeout(r);
                a.classList.add('flash-hiding');
                setTimeout(() => a.remove(), 800);
            });
        }
    });
});

// ─── DASHBOARD — REFRESH PARCIAL (AJAX) ───────────────────────────────────────
onReady(() => {
    const btn = document.getElementById('btnActualizarDashboard');
    if (!btn) return;

    btn.addEventListener('click', async () => {
        const icon = btn.querySelector('img');
        btn.disabled = true;
        if (icon) icon.style.animation = 'spin .8s linear infinite';

        try {
            const res  = await fetch(location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const html = await res.text();
            const doc  = new DOMParser().parseFromString(html, 'text/html');

            ['dashboard-stats', 'dashboard-table'].forEach((id) => {
                const src  = doc.getElementById(id);
                const dest = document.getElementById(id);
                if (src && dest) dest.innerHTML = src.innerHTML;
            });
        } catch (_) {
            // silently ignore — full page reload as fallback
        } finally {
            btn.disabled = false;
            if (icon) icon.style.animation = '';
        }
    });
});

// ─── NUEVO CONTENIDO — tipo select dinámico ────────────────────────────────────
onReady(() => {
    const tipoSelect = document.getElementById('tipoSelect');
    if (!tipoSelect) return;

    function actualizarCampos() {
        const tipo      = tipoSelect.value;
        const archivo   = document.getElementById('campoArchivo');
        const texto     = document.getElementById('campoTexto');
        const label     = document.getElementById('labelTexto');
        const textarea  = document.getElementById('textareaTexto');
        const fileInput = document.getElementById('archivoInput');

        if (!archivo || !texto) return;

        archivo.style.display = 'none';
        texto.style.display   = 'none';
        if (fileInput) fileInput.required = false;
        if (textarea)  textarea.required  = false;

        if (tipo === 'imagen' || tipo === 'video') {
            archivo.style.display = 'block';
            if (fileInput) {
                fileInput.required = true;
                fileInput.accept   = tipo === 'imagen' ? 'image/*' : 'video/*';
            }
        } else if (tipo === 'texto') {
            texto.style.display = 'block';
            if (textarea) { textarea.required = true; textarea.rows = 6; textarea.placeholder = 'Escribí el mensaje que verán las pantallas...'; }
            if (label)    label.innerHTML = 'Texto a mostrar <span class="text-danger">*</span>';
        } else if (tipo === 'youtube') {
            texto.style.display = 'block';
            if (textarea) { textarea.required = true; textarea.rows = 2; textarea.placeholder = 'https://www.youtube.com/watch?v=...'; }
            if (label)    label.innerHTML = 'URL de YouTube <span class="text-danger">*</span>';
        } else if (tipo === 'qr') {
            texto.style.display = 'block';
            if (textarea) { textarea.required = true; textarea.rows = 2; textarea.placeholder = 'https://unraf.edu.ar/...'; }
            if (label)    label.innerHTML = 'URL de destino del QR <span class="text-danger">*</span>';
        }
    }

    tipoSelect.addEventListener('change', actualizarCampos);
    actualizarCampos(); // llamada inicial para manejar valores pre-seleccionados
});
