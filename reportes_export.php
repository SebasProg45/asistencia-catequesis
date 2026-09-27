<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/xlsx.php';

$grupoFiltro = isset($_GET['grupo']) && $_GET['grupo'] !== '' ? (int) $_GET['grupo'] : null;
$desde = campo($_GET, 'desde');
$hasta = campo($_GET, 'hasta');

$resumen = obtener_resumen_asistencia($pdo, $grupoFiltro, $desde ?: null, $hasta ?: null);
$actividadesPorEstudiante = obtener_resumen_actividades($pdo, $grupoFiltro, $desde ?: null, $hasta ?: null);

// Orden por defecto del Excel: puntos de mayor a menor (empate -> nombre).
usort($resumen, function ($a, $b) {
    $cmp = ((float) $b['puntos_totales']) <=> ((float) $a['puntos_totales']);
    return $cmp !== 0 ? $cmp : strcasecmp($a['nombres'], $b['nombres']);
});

$encabezados = [
    'Nombres', 'Apellidos', 'Grupo', 'Sesiones', 'Misa y Catequesis', 'Solo Catequesis', 'Solo Misa', 'No asistió', 'Puntos', '% Asistencia',
    'Meta de puntos', '% de la meta',
    'Actividades asistidas', 'Total actividades',
];

$filas = [];
foreach ($resumen as $r) {
    $pct = $r['total_sesiones'] > 0 ? round(($r['puntos_totales'] / $r['total_sesiones']) * 100) : '';
    $act = $actividadesPorEstudiante[$r['estudiante_id']] ?? ['actividades_asistidas' => 0, 'total_actividades' => 0];
    $filas[] = [
        $r['nombres'],
        $r['apellidos'],
        $r['grupo_nombre'],
        (int) $r['total_sesiones'],
        (int) $r['completas'],
        (int) $r['solo_catequesis'],
        (int) $r['solo_misa'],
        (int) $r['ausencias'],
        number_format((float) $r['puntos_totales'], 1),
        $pct !== '' ? $pct . '%' : '',
        $r['meta_puntos'] !== null ? (float) $r['meta_puntos'] : '',
        ($r['meta_puntos'] !== null && $r['meta_puntos'] > 0) ? round(($r['puntos_totales'] / $r['meta_puntos']) * 100) . '%' : '',
        (int) $act['actividades_asistidas'],
        (int) $act['total_actividades'],
    ];
}

descargar_xlsx('reporte_asistencia_' . date('Y-m-d') . '.xlsx', 'Reporte', $encabezados, $filas);
