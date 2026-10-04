// Lista de ítems de una playlist, dentro del modal de segundo nivel.
//
// Agregar, quitar y reordenar NO tocan el servidor: mutan sólo el DOM y se
// comparan contra lo confirmado. Guardar y Cancelar aparecen recién cuando la
// lista difiere de lo confirmado.
//
// Un solo punto de guardado, igual en el alta y en la edición: el Guardar de
// esta ventana confirma la lista y la deja como inputs hidden en el
// formulario de la playlist (el de abajo); el Guardar de la playlist es el que
// la persiste. Reabrir esta ventana muestra lo confirmado; Cancelar la
// playlist descarta todo, porque el formulario muere con el modal.
//
// Un mismo contenido puede ir más de una vez en la lista: el catálogo nunca
// oculta filas, sólo indica cuántas veces ya está agregado.
(function () {
    'use strict';

    function initItems() {
        document.querySelectorAll('[data-spui-items-root]').forEach(function (root) {
            if (root._spuiItems) return;
            root._spuiItems = true;
            new ListaItems(root);
        });
    }

    /** El formulario de la playlist, en el modal de abajo (nivel 1). */
    function formPlaylist() {
        return document.querySelector('#spuiAbmBody form[data-spui-ajax]');
    }

    function ListaItems(root) {
        this.root        = root;
        this.lista       = root.querySelector('[data-spui-items-lista]');
        this.vacio       = root.querySelector('[data-spui-items-vacio]');
        this.total       = root.querySelector('[data-spui-items-total]');
        this.catalogo    = root.querySelector('[data-spui-disponibles]');
        this.acciones    = root.querySelector('[data-spui-items-acciones]');
        this.btnGuardar  = root.querySelector('[data-spui-items-guardar]');
        this.btnCancelar = root.querySelector('[data-spui-items-cancelar]');

        // Datos de cada contenido (título, tipo, duración), de las filas que
        // dibujó el servidor y del catálogo. Con esto se puede rearmar una
        // fila sin volver a pedir nada.
        this.datos = {};
        var self = this;
        root.querySelectorAll('[data-contenido-id][data-titulo]').forEach(function (el) {
            self.datos[el.dataset.contenidoId] = {
                contenidoId: el.dataset.contenidoId,
                titulo:      el.dataset.titulo,
                tipo:        el.dataset.tipo,
                duracion:    el.dataset.duracion
            };
        });

        // Si ya se confirmó una lista en esta misma edición (alta o edición),
        // vive en el formulario de la playlist y manda sobre lo que dibujó el
        // servidor: es lo que se va a guardar.
        this.baseline = [];
        var confirmados = this.idsConfirmadosEnForm();
        if (confirmados !== null) this.reconstruir(confirmados);

        this.baseline = this.idsActuales();

        this.bindAgregar();
        this.bindQuitar();
        this.bindBotones();
        this.initSortable();
        this.sincronizarVista();
    }

    /** Ids de contenido en el orden en que están ahora en la lista de arriba (con repetidos). */
    ListaItems.prototype.idsActuales = function () {
        if (!this.lista) return [];
        return Array.from(this.lista.querySelectorAll('[data-contenido-id]'))
            .map(function (li) { return parseInt(li.dataset.contenidoId, 10); });
    };

    /** La lista confirmada en el form de la playlist, o null si no se confirmó ninguna todavía. */
    ListaItems.prototype.idsConfirmadosEnForm = function () {
        var form = formPlaylist();
        if (!form || !form.querySelector('input[name="items_modificados"]')) return null;
        return Array.from(form.querySelectorAll('input[name="contenido_ids[]"]'))
            .map(function (i) { return parseInt(i.value, 10); });
    };

    ListaItems.prototype.hayCambios = function () {
        var a = this.idsActuales(), b = this.baseline;
        if (a.length !== b.length) return true;
        for (var i = 0; i < a.length; i++) {
            if (a[i] !== b[i]) return true;   // el orden también cuenta
        }
        return false;
    };

    /** Renumera, muestra u oculta el vacío, cuenta repeticiones y muestra Guardar/Cancelar si hay cambios. */
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

        // El catálogo no oculta nada (se puede repetir): sólo dice cuántas
        // veces está cada contenido en la lista.
        if (this.catalogo) {
            var veces = {};
            ids.forEach(function (id) { veces[id] = (veces[id] || 0) + 1; });
            this.catalogo.querySelectorAll('[data-contenido-id]').forEach(function (li) {
                var marca = li.querySelector('[data-spui-en-lista]');
                if (!marca) return;
                var n = veces[parseInt(li.dataset.contenidoId, 10)] || 0;
                marca.hidden = n === 0;
                marca.textContent = n === 1 ? 'En la lista' : 'En la lista ×' + n;
            });
        }

        if (this.acciones) this.acciones.hidden = !this.hayCambios();
    };

    ListaItems.prototype.bindAgregar = function () {
        var self = this;
        if (!this.catalogo) return;
        this.catalogo.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-spui-item-agregar]');
            if (!btn) return;
            var li = btn.closest('[data-contenido-id]');
            if (!li) return;
            self.agregarFila(self.datos[li.dataset.contenidoId]);
            self.sincronizarVista();
        });
    };

    /** Agrega una fila al final de la lista, sin refrescar la vista. */
    ListaItems.prototype.agregarFila = function (d) {
        if (!this.lista || !d) return;
        var esc = window.spui.escHtml;
        var li = document.createElement('li');
        li.className = 'sortable-item d-flex align-items-center gap-2 p-3 border-bottom';
        li.dataset.contenidoId = d.contenidoId;
        li.dataset.titulo      = d.titulo;
        li.dataset.tipo        = d.tipo;
        li.dataset.duracion    = d.duracion;
        // Las URLs de los íconos vienen de la raíz (con el hash de AssetMapper
        // ya resuelto por Twig): con la lista vacía no hay fila de dónde copiarlas.
        li.innerHTML =
            '<span class="item-drag-handle">' +
                '<img src="' + esc(this.root.dataset.iconoArrastre || '') + '" class="unraf-icon-svg" alt="">' +
            '</span>' +
            '<span class="spui-badge-borrador" style="min-width:1.8rem;text-align:center" data-spui-item-orden></span>' +
            '<div class="flex-grow-1"><strong>' + esc(d.titulo) + '</strong>' +
                '<small class="text-muted ms-2">' + esc(d.tipo) + '</small></div>' +
            '<span class="duracion-badge text-muted small">' + esc(d.duracion) + '</span>' +
            '<button type="button" class="unraf-btn-icon btn-delete" data-spui-item-quitar title="Quitar de la playlist">' +
                '<img src="' + esc(this.root.dataset.iconoQuitar || '') + '" class="unraf-icon-svg" alt="Quitar">' +
            '</button>';
        this.lista.appendChild(li);
    };

    /** Deja la lista de arriba exactamente con estos ids, en este orden. */
    ListaItems.prototype.reconstruir = function (ids) {
        if (!this.lista) return;
        this.lista.innerHTML = '';
        var self = this;
        ids.forEach(function (id) { self.agregarFila(self.datos[id]); });
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

    ListaItems.prototype.bindBotones = function () {
        var self = this;
        if (this.btnGuardar) this.btnGuardar.addEventListener('click', function () { self.guardar(); });
        if (this.btnCancelar) this.btnCancelar.addEventListener('click', function () { self.cancelar(); });
    };

    /** Confirma la lista en el formulario de la playlist y cierra la ventana. */
    ListaItems.prototype.guardar = function () {
        var ids = this.idsActuales();
        this.publicarEnForm(ids);
        this.baseline = ids.slice();
        this.sincronizarVista();
        if (window.spui.closeModal2) window.spui.closeModal2();
    };

    /** Vuelve a lo confirmado y cierra la ventana. */
    ListaItems.prototype.cancelar = function () {
        this.reconstruir(this.baseline);
        this.sincronizarVista();
        if (window.spui.closeModal2) window.spui.closeModal2();
    };

    /**
     * Deja la lista como inputs hidden dentro del formulario de la playlist,
     * para que viajen con su Guardar. items_modificados marca que hay una lista
     * confirmada: sin ella el servidor no toca los ítems (editar sólo el
     * nombre no puede vaciar la playlist).
     */
    ListaItems.prototype.publicarEnForm = function (ids) {
        var form = formPlaylist();
        if (!form) return;

        form.querySelectorAll('[data-spui-items-hidden]').forEach(function (el) { el.remove(); });

        function hidden(nombre, valor) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = nombre;
            input.value = valor;
            input.setAttribute('data-spui-items-hidden', '');
            form.appendChild(input);
        }
        hidden('items_modificados', '1');
        ids.forEach(function (id) { hidden('contenido_ids[]', id); });

        // El contador del botón "Ver ítems", para ver cuántos quedaron sin
        // tener que reabrir esta ventana.
        var resumen = form.querySelector('[data-spui-items-resumen]');
        if (resumen) resumen.textContent = '· ' + ids.length;
    };

    document.addEventListener('DOMContentLoaded', initItems);
    document.addEventListener('spui:modal:loaded', initItems);
}());
