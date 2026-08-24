// Cronograma builder: <dialog> con los días ocultos (cuando hay más de 3).
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var dlg = document.getElementById('spuiDiasDialog');
        if (!dlg) return;

        var content  = document.getElementById('spuiDiasDialogContent');
        var closeBtn = document.getElementById('spuiDiasDialogClose');

        document.querySelectorAll('.spui-ver-dias-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var dias = JSON.parse(this.dataset.dias);
                content.innerHTML = dias.map(function (d) {
                    return '<span class="spui-dia-badge spui-dia-activo">' + d + '</span>';
                }).join('');
                dlg.showModal();
            });
        });

        if (closeBtn) closeBtn.addEventListener('click', function () { dlg.close(); });
        dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
    });
}());
