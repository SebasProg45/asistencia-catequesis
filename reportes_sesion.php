<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/functions.php';

$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT s.*, g.nombre AS grupo_nombre FROM sesiones s JOIN grupos g ON g.id = s.grupo_id WHERE s.id = ?');
$stmt->execute([$id]);
$sesion = $stmt->fetch();

if (!$sesion) {
    set_flash('error', 'Sesión no encontrada.');
    redirect('reportes.php');
}

$stmt = $pdo->prepare('
    SELECT es.apellidos, es.nombres, a.categoria, a.puntos
    FROM asistencias a
    JOIN estudiantes es ON es.id = a.estudiante_id
    WHERE a.sesion_id = ?
    ORDER BY es.nombres, es.apellidos
');
$stmt->execute([$id]);
$detalle = $stmt->fetchAll();

$etiquetas = [
    'completo' => 'Misa y Catequesis',
    'catequesis' => 'Solo Catequesis',
    'misa' => 'Solo Misa',
    'ausente' => 'No asistió',
];
$badgeClases = [
    'completo' => 'badge-ok',
    'catequesis' => 'badge-warn',
    'misa' => 'badge-info',
    'ausente' => 'badge-off',
];

$puntosTotales = array_sum(array_map(fn($d) => (float) $d['puntos'], $detalle));

$page_title = 'Detalle de sesión';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head no-print">
    <h1>Detalle de sesión</h1>
    <div>
        <a class="btn btn-primary" href="asistencia.php?grupo=<?= (int) $sesion['grupo_id'] ?>&fecha=<?= urlencode($sesion['fecha']) ?>">✏️ Editar asistencia</a>
        <button class="btn" onclick="window.print()">🖨 Imprimir / Guardar PDF</button>
        <a class="btn" href="reportes.php">← Volver a reportes</a>
    </div>
</div>

<div class="card print-card">
    <h2><?= e($sesion['grupo_nombre']) ?> — <?= date('d/m/Y', strtotime($sesion['fecha'])) ?></h2>
    <?php if ($sesion['tema']): ?><p class="muted">Tema: <?= e($sesion['tema']) ?></p><?php endif; ?>
    <?php if ($detalle): ?>
        <p class="muted">Puntos de la sesión: <strong><?= number_format($puntosTotales, 1) ?></strong> / <?= number_format(count($detalle), 1) ?></p>
    <?php endif; ?>

    <table class="table">
        <thead><tr><th>Catequizando</th><th>Asistencia</th></tr></thead>
        <tbody>
        <?php foreach ($detalle as $d): ?>
            <tr>
                <td><?= e($d['nombres']) ?> <?= e($d['apellidos']) ?></td>
                <td><span class="badge <?= $badgeClases[$d['categoria']] ?? 'badge-off' ?>"><?= e($etiquetas[$d['categoria']] ?? $d['categoria']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$detalle): ?>
            <tr><td colspan="2" class="empty">No se registró asistencia para esta sesión.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
