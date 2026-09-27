<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/functions.php';

$id = (int) ($_GET['id'] ?? 0);
$grupoId = (int) ($_GET['grupo'] ?? 0);

if ($id > 0) {
    $stmt = $pdo->prepare('SELECT es.*, g.nombre AS grupo_nombre, g.meta_puntos FROM estudiantes es JOIN grupos g ON g.id = es.grupo_id WHERE es.id = ?');
    $stmt->execute([$id]);
} elseif ($grupoId > 0) {
    $stmt = $pdo->prepare('SELECT es.*, g.nombre AS grupo_nombre, g.meta_puntos FROM estudiantes es JOIN grupos g ON g.id = es.grupo_id WHERE es.grupo_id = ? ORDER BY es.nombres, es.apellidos');
    $stmt->execute([$grupoId]);
} else {
    redirect('estudiantes.php');
}
$estudiantes = $stmt->fetchAll();

if (!$estudiantes) {
    set_flash('error', 'No se encontró al catequizando o el grupo no tiene catequizandos.');
    redirect('estudiantes.php');
}

$etiquetas = ['completo' => 'Misa y Catequesis', 'catequesis' => 'Solo Catequesis', 'misa' => 'Solo Misa', 'ausente' => 'No asistió'];
$badgeClases = ['completo' => 'badge-ok', 'catequesis' => 'badge-warn', 'misa' => 'badge-info', 'ausente' => 'badge-off'];

$stSes = $pdo->prepare('
    SELECT s.fecha, s.tema, a.categoria, a.puntos
    FROM asistencias a
    JOIN sesiones s ON s.id = a.sesion_id
    WHERE a.estudiante_id = ?
    ORDER BY s.fecha, s.id
');
$stAct = $pdo->prepare('
    SELECT act.nombre, act.fecha, aa.asistio
    FROM actividad_asistencias aa
    JOIN actividades act ON act.id = aa.actividad_id
    WHERE aa.estudiante_id = ?
    ORDER BY act.fecha, act.id
');

$page_title = 'Boletín de asistencia';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head no-print">
    <h1><?= count($estudiantes) > 1 ? 'Boletines del grupo' : 'Boletín de asistencia' ?></h1>
    <div>
        <button class="btn btn-primary" onclick="window.print()">🖨 Imprimir / Guardar PDF</button>
        <a class="btn" href="estudiantes.php">← Volver</a>
    </div>
</div>

<?php foreach ($estudiantes as $es): ?>
    <?php
    $stSes->execute([$es['id']]);
    $sesiones = $stSes->fetchAll();
    $stAct->execute([$es['id']]);
    $actividades = $stAct->fetchAll();

    $total = count($sesiones);
    $puntos = array_sum(array_map(fn($s) => (float) $s['puntos'], $sesiones));
    $pct = $total > 0 ? round(($puntos / $total) * 100) : null;
    $conteo = array_count_values(array_column($sesiones, 'categoria'));
    $actAsistidas = count(array_filter($actividades, fn($a) => (int) $a['asistio'] === 1));
    $meta = $es['meta_puntos'] !== null ? (float) $es['meta_puntos'] : null;
    ?>
    <div class="card boletin">
        <div class="boletin-head">
            <div>
                <h2><?= e($es['nombres']) ?> <?= e($es['apellidos']) ?></h2>
                <span class="muted">Grupo: <?= e($es['grupo_nombre']) ?><?php if ($es['nombre_encargado'] && $es['nombre_encargado'] !== '-'): ?> · Encargado: <?= e($es['nombre_encargado']) ?><?php endif; ?></span>
            </div>
            <div class="muted" style="text-align:right">Boletín de asistencia<br><?= date('d/m/Y') ?></div>
        </div>

        <div class="boletin-kpis">
            <div class="boletin-kpi"><strong><?= $total ?></strong><span>Sesiones registradas</span></div>
            <div class="boletin-kpi"><strong><?= fmt_num($puntos) ?: '0' ?></strong><span>Puntos</span></div>
            <div class="boletin-kpi"><strong><?= $pct !== null ? $pct . '%' : '—' ?></strong><span>Asistencia</span></div>
            <div class="boletin-kpi"><strong><?= $actAsistidas ?> / <?= count($actividades) ?></strong><span>Actividades</span></div>
        </div>

        <?php if ($meta !== null): ?>
            <p style="margin:6px 0 4px"><strong>Meta del grupo:</strong> <?= fmt_num($meta) ?> puntos
                <?= $puntos >= $meta ? '— ¡meta alcanzada!' : '— faltan ' . fmt_num($meta - $puntos) . ' puntos' ?></p>
            <?= barra_meta($puntos, $meta) ?>
        <?php endif; ?>

        <p class="muted" style="margin-top:12px">
            Misa y catequesis: <strong><?= (int) ($conteo['completo'] ?? 0) ?></strong> ·
            Solo catequesis: <strong><?= (int) ($conteo['catequesis'] ?? 0) ?></strong> ·
            Solo misa: <strong><?= (int) ($conteo['misa'] ?? 0) ?></strong> ·
            No asistió: <strong><?= (int) ($conteo['ausente'] ?? 0) ?></strong>
        </p>

        <h2 style="margin-top:14px">Sesiones</h2>
        <table class="table">
            <thead><tr><th>Fecha</th><th>Tema</th><th>Asistencia</th><th>Puntos</th></tr></thead>
            <tbody>
            <?php foreach ($sesiones as $s): ?>
                <tr>
                    <td><?= date('d/m/Y', strtotime($s['fecha'])) ?></td>
                    <td><?= e($s['tema']) ?></td>
                    <td><span class="badge <?= $badgeClases[$s['categoria']] ?? 'badge-off' ?>"><?= e($etiquetas[$s['categoria']] ?? $s['categoria']) ?></span></td>
                    <td><?= fmt_num($s['puntos']) ?: '0' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$sesiones): ?><tr><td colspan="4" class="empty">Aún no hay sesiones registradas.</td></tr><?php endif; ?>
            </tbody>
        </table>

        <?php if ($actividades): ?>
        <h2 style="margin-top:14px">Actividades extracurriculares <span class="muted" style="font-weight:400">(no suman ni restan puntos)</span></h2>
        <table class="table">
            <thead><tr><th>Fecha</th><th>Actividad</th><th>Asistencia</th></tr></thead>
            <tbody>
            <?php foreach ($actividades as $a): ?>
                <tr>
                    <td><?= date('d/m/Y', strtotime($a['fecha'])) ?></td>
                    <td><?= e($a['nombre']) ?></td>
                    <td><span class="badge <?= (int) $a['asistio'] === 1 ? 'badge-ok' : 'badge-off' ?>"><?= (int) $a['asistio'] === 1 ? 'Asistió' : 'No asistió' ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
