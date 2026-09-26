<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/functions.php';

$totalEstudiantes = (int) $pdo->query("SELECT COUNT(*) FROM estudiantes")->fetchColumn();
$totalGrupos = (int) $pdo->query("SELECT COUNT(*) FROM grupos")->fetchColumn();
$sesionesMes = (int) $pdo->query("SELECT COUNT(*) FROM sesiones WHERE MONTH(fecha) = MONTH(CURDATE()) AND YEAR(fecha) = YEAR(CURDATE())")->fetchColumn();

$asistenciaGlobal = $pdo->query("
    SELECT
        COALESCE(SUM(a.puntos), 0) AS puntos,
        COUNT(*) AS total
    FROM asistencias a
    JOIN sesiones s ON s.id = a.sesion_id
    WHERE s.fecha >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
")->fetch();
$porcentajeAsistencia = $asistenciaGlobal['total'] > 0
    ? round(($asistenciaGlobal['puntos'] / $asistenciaGlobal['total']) * 100)
    : null;

$topAusencias = array_slice(
    array_filter(obtener_resumen_asistencia($pdo), fn($r) => $r['ausencias'] > 0),
    0,
    5
);

$ultimasSesiones = $pdo->query("
    SELECT s.id, s.fecha, g.nombre AS grupo_nombre,
        COALESCE(SUM(a.puntos), 0) AS puntos,
        COUNT(a.id) AS total
    FROM sesiones s
    JOIN grupos g ON g.id = s.grupo_id
    LEFT JOIN asistencias a ON a.sesion_id = s.id
    GROUP BY s.id, s.fecha, g.nombre
    ORDER BY s.fecha DESC
    LIMIT 8
")->fetchAll();
$ultimasSesiones = array_reverse($ultimasSesiones);

$page_title = 'Panel';
require __DIR__ . '/includes/header.php';
?>
<h1>Panel general</h1>

<div class="stats-grid">
    <div class="stat-card">
        <span class="stat-icon"><?= nav_icon('users') ?></span>
        <span class="stat-body">
            <span class="stat-value"><?= $totalEstudiantes ?></span>
            <span class="stat-label">Catequizandos</span>
        </span>
    </div>
    <div class="stat-card">
        <span class="stat-icon"><?= nav_icon('book') ?></span>
        <span class="stat-body">
            <span class="stat-value"><?= $totalGrupos ?></span>
            <span class="stat-label">Grupos</span>
        </span>
    </div>
    <div class="stat-card">
        <span class="stat-icon"><?= nav_icon('calendar') ?></span>
        <span class="stat-body">
            <span class="stat-value"><?= $sesionesMes ?></span>
            <span class="stat-label">Sesiones este mes</span>
        </span>
    </div>
    <div class="stat-card">
        <span class="stat-icon"><?= nav_icon('trend') ?></span>
        <span class="stat-body">
            <span class="stat-value"><?= $porcentajeAsistencia !== null ? $porcentajeAsistencia . '%' : '—' ?></span>
            <span class="stat-label">Asistencia (últimos 30 días)</span>
        </span>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <h2>Asistencia por sesión (recientes)</h2>
        <?php if (!$ultimasSesiones): ?>
            <p class="muted">Aún no hay sesiones registradas.</p>
        <?php else: ?>
        <div class="bar-chart">
            <?php foreach ($ultimasSesiones as $s): ?>
                <?php $pct = $s['total'] > 0 ? round(($s['puntos'] / $s['total']) * 100) : 0; ?>
                <div class="bar-col" title="<?= e($s['grupo_nombre']) ?> · <?= e($s['fecha']) ?> · <?= $pct ?>% de asistencia">
                    <div class="bar" style="height: <?= max(4, $pct) ?>%"></div>
                    <span class="bar-label"><?= date('d/m', strtotime($s['fecha'])) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Más ausencias acumuladas</h2>
        <?php if (!$topAusencias): ?>
            <p class="muted">Sin ausencias registradas todavía. 🎉</p>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>Catequizando</th><th>Grupo</th><th>No asistió</th></tr></thead>
            <tbody>
            <?php foreach ($topAusencias as $r): ?>
                <tr>
                    <td><?= e($r['nombres']) ?> <?= e($r['apellidos']) ?></td>
                    <td><?= e($r['grupo_nombre']) ?></td>
                    <td><span class="badge badge-off"><?= (int) $r['ausencias'] ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="muted"><a href="reportes.php">Ver reporte completo →</a></p>
        <?php endif; ?>
    </div>
</div>

<div class="card actions-row">
    <a class="btn btn-primary" href="asistencia.php">📋 Tomar asistencia de hoy</a>
    <a class="btn" href="reportes.php">📊 Ver reportes</a>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
