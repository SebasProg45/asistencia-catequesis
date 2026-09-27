/* Asistencia ya guardada: la pantalla se abre protegida y editar exige confirmar.
   Uso: <form data-lock class="is-locked"> con #btn-editar, #btn-cancelar-edicion y <dialog id="dlg-editar">.
   Los controles con data-lock-disable / data-lock-readonly se bloquean o liberan.
   Al cambiar de modo, el formulario emite el evento "bloqueo" ({detail:{bloqueado}}). */
(function () {
    var form = document.querySelector('form[data-lock]');
    if (!form) return;

    var dlg = document.getElementById('dlg-editar');
    var btnEditar = document.getElementById('btn-editar');
    var btnCancelar = document.getElementById('btn-cancelar-edicion');

    function aplicar(bloqueado) {
        form.classList.toggle('is-locked', bloqueado);
        form.querySelectorAll('[data-lock-disable]').forEach(function (el) { el.disabled = bloqueado; });
        form.querySelectorAll('[data-lock-readonly]').forEach(function (el) {
            el.readOnly = bloqueado;
            if (bloqueado) el.value = el.defaultValue;
        });
        form.dispatchEvent(new CustomEvent('bloqueo', { detail: { bloqueado: bloqueado } }));
    }

    function desbloquear() {
        aplicar(false);
        var primero = form.querySelector('input[type=radio]');
        if (primero) primero.focus();
        window.toast('Modo edición activado. Los cambios se aplican al guardar.', 'success');
    }

    btnEditar.addEventListener('click', function () {
        if (dlg && typeof dlg.showModal === 'function') {
            dlg.returnValue = '';
            dlg.showModal();
        } else if (window.confirm((dlg && dlg.dataset.fallback) || '¿Quieres editar lo ya guardado?')) {
            desbloquear();
        }
    });
    if (dlg) {
        // Se maneja el clic directamente: no depende del evento "close" del diálogo.
        dlg.querySelectorAll('button[value]').forEach(function (b) {
            b.addEventListener('click', function (e) {
                e.preventDefault();
                dlg.close(b.value);
                if (b.value === 'ok') {
                    desbloquear();
                } else {
                    btnEditar.focus();
                }
            });
        });
    }

    btnCancelar.addEventListener('click', function () {
        var sucio = form.querySelector('.ar-row.changed') || Array.prototype.some.call(
            form.querySelectorAll('[data-lock-readonly]'), function (el) { return el.value !== el.defaultValue; });
        if (sucio && !window.confirm('Se descartarán los cambios sin guardar. ¿Salir del modo edición?')) return;
        aplicar(true);
        btnEditar.focus();
    });
})();
