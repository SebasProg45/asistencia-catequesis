(function () {
    var form = document.getElementById('form-asistencia');
    if (!form) return;

    var totalEl = document.getElementById('puntos-total');
    var maxEl = document.getElementById('puntos-max');
    var radios = form.querySelectorAll('input[data-puntos-radio]');

    var groups = {};
    radios.forEach(function (radio) {
        (groups[radio.name] = groups[radio.name] || []).push(radio);
    });

    function recalcular() {
        var total = 0;
        Object.keys(groups).forEach(function (name) {
            var seleccionado = groups[name].find(function (r) { return r.checked; });
            if (seleccionado) {
                total += parseFloat(seleccionado.dataset.puntos);
            }
        });
        totalEl.textContent = total.toFixed(1);
        maxEl.textContent = Object.keys(groups).length.toFixed(1);
    }

    radios.forEach(function (radio) {
        radio.addEventListener('change', recalcular);
    });

    recalcular();
})();
