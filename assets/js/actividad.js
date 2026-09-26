(function () {
    var form = document.getElementById('form-actividad');
    if (!form) return;

    var totalEl = document.getElementById('asistio-total');
    var maxEl = document.getElementById('asistio-max');
    var radios = form.querySelectorAll('input[data-asistio-radio]');

    var groups = {};
    radios.forEach(function (radio) {
        (groups[radio.name] = groups[radio.name] || []).push(radio);
    });

    function recalcular() {
        var total = 0;
        Object.keys(groups).forEach(function (name) {
            var seleccionado = groups[name].find(function (r) { return r.checked; });
            if (seleccionado && seleccionado.value === '1') {
                total += 1;
            }
        });
        totalEl.textContent = total;
        maxEl.textContent = Object.keys(groups).length;
    }

    radios.forEach(function (radio) {
        radio.addEventListener('change', recalcular);
    });

    recalcular();
})();
