// Lista de ítems de una playlist, dentro del modal de segundo nivel.
//
// El punto de todo este módulo: agregar, quitar y reordenar NO tocan el
// servidor. Mutan sólo el DOM y se comparan contra el estado persistido; el
// botón Guardar aparece cuando hay diferencias y desaparece cuando no las hay.
// Antes cada acción iba sola al servidor, así que la lista se guardaba sin que
// nadie lo confirmara y no había forma de cancelar.
//
// Dos modos, según cómo se abrió el modal:
//   - Edición: hay data-guardar-url. Guardar manda la lista completa y la
//     persiste; el baseline pasa a ser lo que se acaba de guardar.
//   - Alta:    no hay playlist todavía (playlist_id es NOT NULL en la base, así
//     que no puede haber ítems sin ella). Guardar confirma en memoria y deja la
//     selección en el modal principal, que la manda al crear.
(function () {
    'use strict';

    function initItems() {
        document.querySelectorAll('[data-spui-items-root]').forEach(function (root) {
            if (root._spuiItems) return;
            root._spuiItems = true;
            new ListaItems(root);
        });
    }

    function ListaItems(root) {
        this.root        = root;
        this.esAlta      = root.dataset.alta === '1';
        this.guardarUrl  = root.dataset.guardarUrl || null;
        this.lista       = root.querySelector('[data-spui-items-lista]');
        this.vacio       = root.querySelector('[data-spui-items-vacio]');
        this.total       = root.querySelector('[data-spui-items-total]');
        this.disponibles = root.querySelector('[data-spui-disponibles]');
        this.acciones    = root.querySelector('[data-spui-items-acciones]');
        this.btnGuardar  = root.querySelector('[data-spui-items-guardar]');

        // En alta el servidor no sabe qué se eligió antes: la selección vive en
        // los inputs hidden del formulario principal. Sin esto, reabrir "Ver
        // ítems" mostraba la lista vacía y el siguiente Guardar la pisaba.
        this.baseline = [];   // agregar() compara contra esto mientras se restaura
        if (this.esAlta) this.restaurarSeleccion();

        // Estado contra el que se comparan los cambios: lo persistido en
        // edición, lo ya confirmado en alta.
        this.baseline = this.idsActuales();

        this.bindAgregar();
        this.bindQuitar();
        this.bindGuardar();
        this.initSortable();
        this.sincronizarVista();
    }

    /** Ids de contenido en el orden en que están ahora en la lista de arriba. */
    ListaItems.prototype.idsActuales = function () {
        if (!this.lista) return [];
        return Array.from(this.lista.querySelectorAll('[data-contenido-id]'))
            .map(function (li) { return parseInt(li.dataset.contenidoId, 10); });
    };

    ListaItems.prototype.hayCambios = function () {
        var a = this.idsActuales(), b = this.baseline;
        if (a.length !== b.length) return true;
        for (var i = 0; i < a.length; i++) {
            if (a[i] !== b[i]) return true;   // el orden también cuenta
        }
        return false;
    };

    /** Renumera los badges, muestra u oculta el vacío y el botón Guardar. */
    ListaItems.prototype.sincronizarVista = function () {
        var ids = this.idsActuales();

        if (this.lista) {
            this.lista.querySelectorAll('[data-spui-item-orden]').forEach(function (b, i) {
                b.textContent = i + 1;
            });
            this.lista.hidden = ids.length === 0;
        }
        if (this.vacio) this.vacio.hidden = ids.length > 0;
        if (this.total) this.total.textContent = ids.length;

        // Una fila del catálogo se oculta mientras su contenido está arriba.
        if (this.disponibles) {
            this.disponibles.querySelectorAll('[data-contenido-id]').forEach(function (li) {
                li.hidden = ids.indexOf(parseInt(li.dataset.contenidoId, 10)) !== -1;
            });
        }

        if (this.acciones) this.acciones.hidden = !this.hayCambios();
    };

    ListaItems.prototype.bindAgregar = function () {
        var self = this;
        if (!this.disponibles) return;
        this.disponibles.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-spui-item-agregar]');
            if (!btn) return;
            var li = btn.closest('[data-contenido-id]');
            if (li) self.agregar(li.dataset);
        });
    };

    ListaItems.prototype.agregar = function (datos) {
        if (!this.lista) return;
        var li = document.createElement('li');
        li.className = 'sortable-item d-flex align-items-center gap-2 p-3 border-bottom';
        li.dataset.contenidoId = datos.contenidoId;
        li.innerHTML =
            '<span class="item-drag-handle">' +
                '<img src="' + this.iconoArrastre() + '" class="unraf-icon-svg" alt="">' +
            '</span>' +
            '<span class="spui-badge-borrador" style="min-width:1.8rem;text-align:center" data-spui-item-orden></span>' +
            '<div class="flex-grow-1"><strong>' + window.spui.escHtml(datos.titulo) + '</strong>' +
                '<small class="text-muted ms-2">' + window.spui.escHtml(datos.tipo) + '</small></div>' +
            '<span class="duracion-badge text-muted small">' + window.spui.escHtml(datos.duracion) + '</span>' +
            '<button type="button" class="unraf-btn-icon btn-delete" data-spui-item-quitar title="Quitar de la playlist">' +
                '<img src="' + this.iconoTacho() + '" class="unraf-icon-svg" alt="Quitar">' +
            '</button>';
        this.lista.appendChild(li);
        this.sincronizarVista();
    };

    // Las URLs de los íconos vienen de la raíz (con el hash de AssetMapper ya
    // resuelto por Twig). No se pueden sacar de una fila existente: con la
    // lista vacía —siempre en un alta— no hay ninguna.
    ListaItems.prototype.iconoArrastre = function () {
        return this.root.dataset.iconoArrastre || '';
    };
    ListaItems.prototype.iconoTacho = function () {
        return this.root.dataset.iconoQuitar || '';
    };

    /** Alta: vuelve a dibujar arriba lo que ya se había confirmado. */
    ListaItems.prototype.restaurarSeleccion = function () {
        var form = document.querySelector('#spuiAbmBody form[data-spui-ajax]');
        if (!form || !this.disponibles) return;

        var self = this;
        form.querySelectorAll('[data-spui-items-hidden]').forEach(function (input) {
            var fila = self.disponibles.querySelector('[data-contenido-id="' + parseInt(input.value, 10) + '"]');
            // Un contenido que dejó de estar publicado mientras tanto ya no
            // figura en el catálogo: se descarta en vez de dibujar una fila
            // sin datos (el servidor lo ignoraría igual al crear).
            if (fila) self.agregar(fila.dataset);
        });
    };

    ListaItems.prototype.bindQuitar = function () {
        var self = this;
        if (!this.lista) return;
        this.lista.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-spui-item-quitar]');
            if (!btn) return;
            var li = btn.closest('[data-contenido-id]');
            if (li) { li.remove(); self.sincronizarVista(); }
        });
    };

    ListaItems.prototype.initSortable = function () {
        var self = this;
        if (!this.lista || typeof Sortable === 'undefined') return;
        if (!this.lista.hasAttribute('data-spui-sortable')) return;
        Sortable.create(this.lista, {
            handle: '.item-drag-handle',
            ghostClass: 'sortable-ghost',
            animation: 150,
            onEnd: function () { self.sincronizarVista(); },
        });
    };

    ListaItems.prototype.bindGuardar = function () {
        var self = this;
        if (!this.btnGuardar) return;
        this.btnGuardar.addEventListener('click', function () { self.guardar(); });
    };

    ListaItems.prototype.guardar = function () {
        var ids = this.idsActuales();

        // Alta: no hay contra qué persistir todavía. Se confirma en memoria y
        // el modal principal se lleva la selección para mandarla al crear.
        if (this.esAlta) {
            this.publicarSeleccion(ids);
            this.baseline = ids.slice();
            this.sincronizarVista();
            window.spui.showFlash(
                ids.length === 1 ? '1 ítem en la selección.' : ids.length + ' ítems en la selección.',
                'success'
            );
            return;
        }

        var self = this;
        var btn  = this.btnGuardar;
        // El spinner va SOLO en el botón: el resto de la lista queda usable y
        // no se reemplaza el HTML del modal entero.
        btn.disabled = true;
        btn.classList.add('spui-btn-submitting');

        var fd = new FormData();
        var meta = document.querySelector('meta[name="spui-csrf-token"]');
        fd.append('_token', meta ? meta.content : '');
        ids.forEach(function (id) { fd.append('contenido_ids[]', id); });

        fetch(this.guardarUrl, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.message) {
                window.spui.showFlash(data.message, data.success !== false ? 'success' : (data.type || 'error'));
            }
            if (data.success !== false) {
                // Lo que se acaba de guardar pasa a ser el nuevo punto de
                // comparación: el botón desaparece hasta que haya otro cambio.
                self.baseline = ids.slice();
                self.sincronizarVista();
            }
        })
        .catch(function () {
            window.spui.showFlash('Error de red. Intentá de nuevo.', 'error');
        })
        .finally(function () {
            btn.disabled = false;
            btn.classList.remove('spui-btn-submitting');
        });
    };

    /**
     * Deja la selección del alta en el formulario del modal principal.
     *
     * Se guarda como inputs hidden dentro de ese form para que viajen solos en
     * su submit, sin que el modal de abajo tenga que saber nada de este.
     */
    ListaItems.prototype.publicarSeleccion = function (ids) {
        var form = document.querySelector('#spuiAbmBody form[data-spui-ajax]');
        if (!form) return;

        form.querySelectorAll('[data-spui-items-hidden]').forEach(function (el) { el.remove(); });
        ids.forEach(function (id) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'contenido_ids[]';
            input.value = id;
            input.setAttribute('data-spui-items-hidden', '');
            form.appendChild(input);
        });

        // El resumen del botón del modal principal, para que se vea cuántos
        // ítems quedaron elegidos sin tener que reabrir esta capa.
        var resumen = document.querySelector('[data-spui-items-resumen]');
        if (resumen) {
            resumen.textContent = ids.length === 0
                ? 'Sin ítems'
                : (ids.length === 1 ? '1 ítem' : ids.length + ' ítems');
        }
    };

    document.addEventListener('DOMContentLoaded', initItems);
    document.addEventListener('spui:modal:loaded', initItems);
}());
