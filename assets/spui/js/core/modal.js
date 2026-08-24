// Motor de modales ABM (alta/baja/modificación) por AJAX.
// Tras inyectar el HTML de un form dispara `spui:modal:loaded` para que otros
// módulos (switch-toggle, content-type-fields) inicialicen su estado.
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var overlay = document.getElementById('spuiAbmOverlay');
        if (!overlay) return;

        var modal   = document.getElementById('spuiAbmModal');
        var titleEl = document.getElementById('spuiAbmTitle');
        var bodyEl  = document.getElementById('spuiAbmBody');
        var closeEl = document.getElementById('spuiAbmClose');
        var closeTimer = null;

        function openModal() {
            if (closeTimer !== null) { clearTimeout(closeTimer); closeTimer = null; }
            overlay.classList.add('is-open');
            overlay.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            setTimeout(function () {
                if (!bodyEl) return;
                var el = bodyEl.querySelector('input:not([type=hidden]),select,textarea');
                if (el) el.focus();
            }, 270);
        }

        function closeModal() {
            if (closeTimer !== null) { clearTimeout(closeTimer); closeTimer = null; }
            overlay.classList.remove('is-open');
            overlay.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
            closeTimer = setTimeout(function () {
                closeTimer = null;
                bodyEl.innerHTML = '';
                titleEl.textContent = '';
                modal.className = 'spui-abm-modal';
            }, 230);
        }

        function setTitle(t) { titleEl.textContent = t || ''; }

        function showSpinner(title) {
            setTitle(title || '');
            bodyEl.innerHTML = '<div class="spui-abm-loading"><div class="spui-spinner"></div><span>Cargando…</span></div>';
        }

        // Notifica a otros módulos que se inyectó contenido nuevo en el modal.
        function notifyLoaded() {
            bodyEl.dispatchEvent(new CustomEvent('spui:modal:loaded', { bubbles: true }));
        }

        // ── Refrescar tabla ──
        function refreshTable() {
            var tableEl = document.getElementById('spui-table-body');
            if (!tableEl) return;
            tableEl.classList.add('is-refreshing');
            fetch(location.pathname + location.search, { cache: 'no-store' })
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var fresh = doc.getElementById('spui-table-body');
                    if (fresh) {
                        tableEl.innerHTML = fresh.innerHTML;
                        bindTableButtons();
                    }
                })
                .catch(function () { /* silent */ })
                .finally(function () { tableEl.classList.remove('is-refreshing'); });
        }

        // ── Abrir formulario en modal ──
        function openFormModal(url, title, size) {
            showSpinner(title);
            if (size) modal.classList.add(size);
            openModal();
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' })
                .then(function (r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function (data) {
                    if (data.title) setTitle(data.title);
                    bodyEl.innerHTML = data.html || '';
                    notifyLoaded();
                    bindFormInBody();
                    // Los botones que abren el nivel 2 llegan con este HTML, así
                    // que hay que bindearlos acá: bindTableButtons() sólo mira
                    // los data-spui-form/action del nivel 1.
                    if (window.spui.bindOpeners2) window.spui.bindOpeners2();
                })
                .catch(function () {
                    bodyEl.innerHTML = '<div class="alert alert-danger">Error al cargar el formulario. Intentá de nuevo.</div>';
                });
        }

        function bindFormInBody() {
            var form = bodyEl && bodyEl.querySelector('form[data-spui-ajax]');
            if (!form) return;
            bodyEl.querySelectorAll('[data-spui-close]').forEach(function (b) {
                b.addEventListener('click', closeModal);
            });
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var btn = form.querySelector('[type=submit]');
                if (btn) btn.classList.add('spui-btn-submitting');
                var fd = new FormData(form);
                fetch(form.getAttribute('action') || location.href, {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        closeModal();
                        if (data.message) window.spui.showFlash(data.message, 'success');
                        if (data.redirect) { window.location.href = data.redirect; }
                        else if (data.apiKey) { showApiKeyModal(data.apiKey, data.hostname || ''); }
                        else { refreshTable(); }
                    } else {
                        if (data.html) {
                            bodyEl.innerHTML = data.html;
                            notifyLoaded();
                            bindFormInBody();
                        } else {
                            if (btn) btn.classList.remove('spui-btn-submitting');
                            window.spui.showFlash(data.message || 'Error al guardar. Revisá el formulario.', 'error');
                        }
                    }
                })
                .catch(function () {
                    if (btn) btn.classList.remove('spui-btn-submitting');
                    window.spui.showFlash('Error de red. Intentá de nuevo.', 'error');
                });
            });
        }

        // ── API key inline (reproductores) ──
        // regenerada=true cambia el texto: al regenerar, la clave anterior ya
        // dejó de servir y hay que actualizar la Pi para que vuelva a sincronizar.
        function showApiKeyModal(key, hostname, regenerada) {
            modal.className = 'spui-abm-modal';
            setTitle('API Key — ' + hostname);
            var esc = window.spui.escHtml;
            var aviso = regenerada
                ? '<strong>¡Guardá esta clave ahora!</strong> No volverá a mostrarse. ' +
                  'La clave anterior dejó de funcionar: hasta que actualices la Raspberry Pi, ' +
                  'ese reproductor no va a poder sincronizar.'
                : '<strong>¡Guardá esta clave ahora!</strong> No volverá a mostrarse.';
            var instruccion = regenerada
                ? 'Actualizá <code>API_KEY=' + esc(key) + '</code> en <code>/etc/spui/spui.env</code> ' +
                  'y reiniciá el servicio con <code>sudo systemctl restart spui</code>.'
                : 'Configurá <code>API_KEY=' + esc(key) + '</code> en el cliente Pi.';
            bodyEl.innerHTML =
                '<div class="alert alert-warning">' + aviso + '</div>' +
                '<label class="unraf-form-label">API Key</label>' +
                '<div class="d-flex gap-2 mb-3">' +
                '<input type="text" class="unraf-form-control font-monospace" id="spuiApiKeyDisplay" readonly value="' + esc(key) + '">' +
                '<button type="button" class="unraf-btn unraf-btn-light" id="spuiApiKeyCopy" style="min-width:5.5rem;text-align:center">Copiar</button>' +
                '</div>' +
                '<p class="text-muted small">' + instruccion + '</p>' +
                '<div class="d-flex justify-content-end mt-3"><button type="button" class="unraf-btn unraf-btn-institucion" data-spui-close>Listo</button></div>';
            openModal();
            window.spui.attachCopyButton(document.getElementById('spuiApiKeyCopy'), function () { return key; });
            bodyEl.querySelectorAll('[data-spui-close]').forEach(function (b) { b.addEventListener('click', closeModal); });
            refreshTable();
        }

        // ── Confirmación de acción (POST) ──
        // reload=true fuerza recarga completa en vez de refrescar sólo la tabla.
        // Necesario en pantallas donde la acción cambia contenido fuera de
        // #spui-table-body (ej: el banner de alerta activa del dashboard).
        function openActionModal(url, title, message, variant, reload, confirmLabel) {
            modal.className = 'spui-abm-modal modal-sm';
            setTitle(title || 'Confirmar acción');
            // data-spui-variant nombra la ACCIÓN (activar, publicar, archivar…),
            // no un color genérico. Así el botón del modal sale con el mismo
            // color que el botón que lo abrió en el listado. Antes había sólo
            // tres variantes de color y casi todo caía en el rojo de eliminar,
            // que era el motivo de que Activar se viera como una acción
            // destructiva.
            var btnCls = variant ? 'spui-btn-' + variant : 'spui-btn-eliminar';
            var btnLabel = confirmLabel || (variant === 'eliminar' || !variant ? 'Eliminar' : 'Confirmar');
            bodyEl.innerHTML =
                '<p class="mb-0 text-secondary" style="font-size:.95rem">' + window.spui.escHtml(message) + '</p>' +
                '<div class="d-flex justify-content-end gap-2 pt-3 mt-3 border-top">' +
                '<button type="button" class="unraf-btn spui-btn-back" data-spui-close>Cancelar</button>' +
                '<button type="button" class="unraf-btn ' + btnCls + '" id="spuiAbmActionBtn">' + btnLabel + '</button>' +
                '</div>';
            bodyEl.querySelector('[data-spui-close]').addEventListener('click', closeModal);
            var actionBtn = document.getElementById('spuiAbmActionBtn');
            actionBtn.addEventListener('click', function () {
                actionBtn.classList.add('spui-btn-submitting');
                actionBtn.disabled = true;
                fetch(url, {
                    method: 'POST',
                    body: new FormData(),
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    closeModal();
                    var tp = (data.success !== false) ? (data.type || 'success') : 'error';
                    if (data.message) window.spui.showFlash(data.message, tp);
                    if (data.success !== false) {
                        if (data.redirect) window.location.href = data.redirect;
                        // La clave regenerada viaja en la respuesta y sólo se puede
                        // ver una vez (en la base queda sólo el SHA-256). Sin esta
                        // rama se descartaba en silencio y el reproductor quedaba
                        // inutilizable: la clave vieja ya no servía y la nueva no
                        // había forma de conocerla.
                        else if (data.apiKey) showApiKeyModal(data.apiKey, data.hostname || '', true);
                        else if (reload) window.location.reload();
                        else refreshTable();
                    }
                })
                .catch(function () {
                    closeModal();
                    window.spui.showFlash('Error de red.', 'error');
                });
            });
            openModal();
        }

        // ── Bind botones de tabla (idempotente) ──
        /**
         * Frena el clic si la acción está bloqueada por conectividad.
         *
         * El botón se marca con data-spui-bloqueado desde Twig cuando alguno de
         * los reproductores que afecta está desconectado. En vez de anular
         * pointer-events —que además de impedir el clic borra el tooltip—, se
         * intercepta acá y se avisa el motivo, que es lo que el operador
         * necesita saber.
         *
         * El servidor rechaza igual estas acciones con 409: esto es sólo para
         * que no haya que llegar hasta allá para enterarse.
         */
        function bloqueado(btn) {
            if (btn.dataset.spuiBloqueado === undefined) return false;
            window.spui.showFlash(
                btn.dataset.spuiBloqueado || 'El reproductor está desconectado.',
                'warning'
            );
            return true;
        }

        function bindTableButtons() {
            document.querySelectorAll('[data-spui-form]').forEach(function (btn) {
                if (btn._spuiBound) return;
                btn._spuiBound = true;
                btn.addEventListener('click', function () {
                    if (bloqueado(btn)) return;
                    openFormModal(btn.dataset.spuiForm, btn.dataset.spuiTitle, btn.dataset.spuiSize || null);
                });
            });
            document.querySelectorAll('[data-spui-action]').forEach(function (btn) {
                if (btn._spuiBound) return;
                btn._spuiBound = true;
                btn.addEventListener('click', function () {
                    if (bloqueado(btn)) return;
                    openActionModal(
                        btn.dataset.spuiAction,
                        btn.dataset.spuiTitle,
                        btn.dataset.spuiMessage || '¿Confirmar esta acción?',
                        btn.dataset.spuiVariant,
                        btn.dataset.spuiReload !== undefined,
                        btn.dataset.spuiConfirmLabel
                    );
                });
            });
        }

        // ═══════════════════════════════════════════════════════════════════
        //  SEGUNDO NIVEL DE MODAL
        // ═══════════════════════════════════════════════════════════════════
        // Un modal que se abre ENCIMA del nivel 1 sin cerrarlo (ej: "Ver ítems"
        // desde el modal de edición de una playlist). Es un overlay gemelo con
        // IDs propios, no una pila genérica: alcanzan dos capas y así el motor
        // del nivel 1 —del que depende todo el CMS— queda sin tocar.
        //
        // Los botones que abren acá usan data-spui-form2 / data-spui-action2.
        // Con los atributos sin el 2 abrirían en el nivel 1, pisando el modal
        // de abajo y perdiendo lo que el usuario tuviera cargado.
        var overlay2 = document.getElementById('spuiAbmOverlay2');
        var modal2, titleEl2, bodyEl2, closeEl2, closeTimer2 = null;
        // baseUrl2 es la vista "de fondo" del nivel 2 (la lista de ítems).
        // Los sub-formularios que se abren encima NO la pisan: al guardar hay que
        // volver a la vista de fondo, no recargar el form que se acaba de enviar.
        // Por eso van en su propia variable.
        var baseUrl2 = null, baseTitle2 = null, baseSize2 = null;

        if (overlay2) {
            modal2   = document.getElementById('spuiAbmModal2');
            titleEl2 = document.getElementById('spuiAbmTitle2');
            bodyEl2  = document.getElementById('spuiAbmBody2');
            closeEl2 = document.getElementById('spuiAbmClose2');
        }

        // Ojo: openModal2/closeModal2 NO tocan document.body.style.overflow.
        // El nivel 2 sólo se abre desde el nivel 1, que ya bloqueó el scroll; si
        // lo tocaran, cerrar el de arriba lo destrabaría con el de abajo todavía
        // abierto y el fondo scrollearía detrás del modal.
        function openModal2() {
            if (closeTimer2 !== null) { clearTimeout(closeTimer2); closeTimer2 = null; }
            overlay2.classList.add('is-open');
            overlay2.setAttribute('aria-hidden', 'false');
            overlay.classList.add('is-dimmed');
            setTimeout(function () {
                if (!bodyEl2) return;
                var el = bodyEl2.querySelector('input:not([type=hidden]),select,textarea');
                if (el) el.focus();
            }, 270);
        }

        function closeModal2() {
            if (closeTimer2 !== null) { clearTimeout(closeTimer2); closeTimer2 = null; }
            overlay2.classList.remove('is-open');
            overlay2.setAttribute('aria-hidden', 'true');
            overlay.classList.remove('is-dimmed');
            baseUrl2 = baseTitle2 = baseSize2 = null;
            closeTimer2 = setTimeout(function () {
                closeTimer2 = null;
                bodyEl2.innerHTML = '';
                titleEl2.textContent = '';
                modal2.className = 'spui-abm-modal';
            }, 230);
        }

        function notifyLoaded2() {
            bodyEl2.dispatchEvent(new CustomEvent('spui:modal:loaded', { bubbles: true }));
        }

        function injectLevel2(data) {
            if (data.title) titleEl2.textContent = data.title;
            bodyEl2.innerHTML = data.html || '';
            notifyLoaded2();
            bindFormInBody2();
            bindLevel2Buttons();
        }

        function cargarEnNivel2(url) {
            bodyEl2.innerHTML = '<div class="spui-abm-loading"><div class="spui-spinner"></div><span>Cargando…</span></div>';
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' })
                .then(function (r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(injectLevel2)
                .catch(function () {
                    bodyEl2.innerHTML = '<div class="alert alert-danger">Error al cargar. Intentá de nuevo.</div>';
                });
        }

        // Abre la vista de fondo del nivel 2 (se la recuerda para poder volver).
        function openFormModal2(url, title, size) {
            baseUrl2   = url;
            baseTitle2 = title || '';
            baseSize2  = size || null;
            titleEl2.textContent = baseTitle2;
            modal2.className = 'spui-abm-modal' + (size ? ' ' + size : '');
            openModal2();
            cargarEnNivel2(url);
        }

        // Sub-formulario dentro del nivel 2: se dibuja en la misma capa pero sin
        // tocar la vista de fondo, para poder volver a ella al guardar o cancelar.
        function openSubFormModal2(url, title) {
            titleEl2.textContent = title || '';
            cargarEnNivel2(url);
        }

        // Vuelve a la vista de fondo. La usan las acciones de adentro
        // (agregar/quitar) para reflejar el cambio sin cerrar nada.
        function refreshModal2() {
            if (!baseUrl2) return;
            titleEl2.textContent = baseTitle2 || '';
            modal2.className = 'spui-abm-modal' + (baseSize2 ? ' ' + baseSize2 : '');
            cargarEnNivel2(baseUrl2);
        }

        function bindFormInBody2() {
            var form = bodyEl2.querySelector('form[data-spui-ajax]');
            // Cancelar dentro de un SUB-formulario vuelve a la lista; en la vista
            // de fondo cierra la capa. Si cerrara siempre, cancelar "Agregar
            // contenido" tiraría abajo todo el modal de ítems.
            var enSubForm = !!form && baseUrl2 !== null;
            bodyEl2.querySelectorAll('[data-spui-close]').forEach(function (b) {
                b.addEventListener('click', enSubForm ? refreshModal2 : closeModal2);
            });
            if (!form) return;
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var btn = form.querySelector('[type=submit]');
                if (btn) btn.classList.add('spui-btn-submitting');
                fetch(form.getAttribute('action') || location.href, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        if (data.message) window.spui.showFlash(data.message, 'success');
                        // A diferencia del nivel 1, acá data.redirect NO navega:
                        // saldría de la página y se llevaría puestos los dos
                        // modales. El contrato del nivel 2 es refrescarse.
                        refreshModal2();
                    } else if (data.html) {
                        injectLevel2({ html: data.html });
                    } else {
                        if (btn) btn.classList.remove('spui-btn-submitting');
                        window.spui.showFlash(data.message || 'Error al guardar.', 'error');
                    }
                })
                .catch(function () {
                    if (btn) btn.classList.remove('spui-btn-submitting');
                    window.spui.showFlash('Error de red. Intentá de nuevo.', 'error');
                });
            });
        }

        // POST + refresco del nivel 2. Es el motor de las acciones de esta capa:
        // lo usan tanto las que preguntan antes (openActionModal2) como las que
        // se ejecutan de una (quitar un ítem, agregar un contenido).
        //
        // El botón se deshabilita mientras vuela el pedido: sin confirmación de
        // por medio, dos clics rápidos mandarían dos POST. No hace falta
        // rehabilitarlo en el camino feliz porque refreshModal2() reemplaza el
        // DOM entero; sí en el error, donde el botón sigue siendo el mismo nodo.
        function postAndRefresh2(url, body, btn, onError) {
            if (btn) { btn.disabled = true; btn.classList.add('spui-btn-submitting'); }
            fetch(url, {
                method: 'POST',
                body: body || new FormData(),
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.message) {
                    window.spui.showFlash(data.message, data.success !== false ? 'success' : (data.type || 'error'));
                }
                if (data.success !== false) {
                    refreshModal2();
                } else {
                    if (btn) { btn.disabled = false; btn.classList.remove('spui-btn-submitting'); }
                    if (onError) onError();
                }
            })
            .catch(function () {
                if (btn) { btn.disabled = false; btn.classList.remove('spui-btn-submitting'); }
                if (onError) onError();
                window.spui.showFlash('Error de red. Intentá de nuevo.', 'error');
            });
        }

        // Confirmación de acción dentro del nivel 2: pregunta en una franja de
        // esta misma capa (no puede abrir otro modal) y al aceptar delega en
        // postAndRefresh2. Hoy no la emite ningún template — el quitar de ítems
        // pasó a ejecutarse sin preguntar — pero queda para la próxima acción
        // destructiva que aparezca en el nivel 2.
        function openActionModal2(url, message, variant, confirmLabel) {
            var host = bodyEl2.querySelector('[data-spui-confirm-host]');
            if (!host) return;
            var btnCls = variant ? 'spui-btn-' + variant : 'spui-btn-eliminar';
            host.innerHTML =
                '<div class="spui-confirm-inline">' +
                '<p class="mb-2">' + window.spui.escHtml(message) + '</p>' +
                '<div class="d-flex justify-content-end gap-2">' +
                '<button type="button" class="unraf-btn spui-btn-back unraf-btn-sm" data-confirm-no>Cancelar</button>' +
                '<button type="button" class="unraf-btn ' + btnCls + ' unraf-btn-sm" data-confirm-si>' + (confirmLabel || 'Eliminar') + '</button>' +
                '</div></div>';
            host.querySelector('[data-confirm-no]').addEventListener('click', function () { host.innerHTML = ''; });
            var btnSi = host.querySelector('[data-confirm-si]');
            btnSi.addEventListener('click', function () {
                postAndRefresh2(url, null, btnSi, function () { host.innerHTML = ''; });
            });
        }

        function bindLevel2Buttons() {
            // Adentro del nivel 2 los data-spui-form2 son SUB-formularios: se
            // dibujan en esta misma capa y al guardar se vuelve a la lista.
            bodyEl2.querySelectorAll('[data-spui-form2]').forEach(function (btn) {
                if (btn._spuiBound2) return;
                btn._spuiBound2 = true;
                btn.addEventListener('click', function () {
                    openSubFormModal2(btn.dataset.spuiForm2, btn.dataset.spuiTitle);
                });
            });
            bodyEl2.querySelectorAll('[data-spui-action2]').forEach(function (btn) {
                if (btn._spuiBound2) return;
                btn._spuiBound2 = true;
                btn.addEventListener('click', function () {
                    openActionModal2(
                        btn.dataset.spuiAction2,
                        btn.dataset.spuiMessage || '¿Confirmar esta acción?',
                        btn.dataset.spuiVariant,
                        btn.dataset.spuiConfirmLabel
                    );
                });
            });

            // Acción sin confirmación: se ejecuta al primer clic. Para lo que es
            // fácil de deshacer (quitar un ítem de una playlist se revierte
            // volviéndolo a agregar desde la lista de abajo), preguntar molesta
            // más de lo que protege.
            bodyEl2.querySelectorAll('[data-spui-action2-now]').forEach(function (btn) {
                if (btn._spuiBound2) return;
                btn._spuiBound2 = true;
                btn.addEventListener('click', function () {
                    postAndRefresh2(btn.dataset.spuiAction2Now, null, btn);
                });
            });

            // POST con un campo, para los botones de fila. El nombre del campo
            // viaja en el data-* y no hardcodeado acá: modal.js es el motor de
            // todo el CMS y no tiene por qué saber de playlists.
            bodyEl2.querySelectorAll('[data-spui-post2]').forEach(function (btn) {
                if (btn._spuiBound2) return;
                btn._spuiBound2 = true;
                btn.addEventListener('click', function () {
                    var fd = new FormData();
                    if (btn.dataset.spuiPostField) {
                        fd.append(btn.dataset.spuiPostField, btn.dataset.spuiPostValue || '');
                    }
                    postAndRefresh2(btn.dataset.spuiPost2, fd, btn);
                });
            });
        }

        // Botones del NIVEL 1 que abren el nivel 2 (ej: "Ver ítems" dentro del
        // modal de edición). Idempotente, igual que bindTableButtons.
        function bindOpeners2() {
            if (!overlay2) return;
            document.querySelectorAll('[data-spui-form2]').forEach(function (btn) {
                if (btn._spuiBound2) return;
                btn._spuiBound2 = true;
                btn.addEventListener('click', function () {
                    openFormModal2(btn.dataset.spuiForm2, btn.dataset.spuiTitle, btn.dataset.spuiSize || null);
                });
            });
        }
        window.spui.bindOpeners2 = bindOpeners2;

        // Se exponen para el refresco automatico: cuando auto-refresh.js
        // reemplaza el HTML de una zona, los botones nuevos necesitan volver a
        // asociarse o quedan inertes (por ejemplo el "Desactivar" del banner de
        // alerta del dashboard). bindTableButtons es idempotente gracias al
        // flag _spuiBound, asi que llamarla de mas no duplica listeners.
        window.spui.bindTableButtons = bindTableButtons;
        window.spui.refreshTable = refreshTable;

        if (overlay2) {
            overlay2.addEventListener('click', function (e) { if (e.target === overlay2) closeModal2(); });
            if (closeEl2) closeEl2.addEventListener('click', closeModal2);
        }

        // ── Cerrar con backdrop / ESC ──
        overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
        if (closeEl) closeEl.addEventListener('click', closeModal);
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            // El de arriba primero: ESC cierra sólo la capa activa.
            if (overlay2 && overlay2.classList.contains('is-open')) { closeModal2(); return; }
            if (overlay.classList.contains('is-open')) closeModal();
        });

        bindTableButtons();
    });
}());
