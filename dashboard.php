<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/functions.php';

// Ajustes de las alertas (racha de faltas, % mínimo, código de país de WhatsApp).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'ajustes_alertas') {
    $racha = max(0, min(20, (int) ($_POST['alerta_racha'] ?? 3)));
    $pct = max(0, min(100, (int) ($_POST['alerta_porcentaje'] ?? 60)));
    $pais = preg_replace('/\D+/', '', campo($_POST, 'codigo_pais', '507')) ?: '507';
    try {
        config_set($pdo, 'alerta_racha', (string) $racha);
        config_set($pdo, 'alerta_porcentaje', (string) $pct);
        config_set($pdo, 'codigo_pais', substr($pais, 0, 4));
        set_flash('success', 'Ajustes de alertas guardados.');
    } catch (PDOException $e) {
        set_flash('error', 'No se pudieron guardar los ajustes.');
    }
    redirect('dashboard.php');
}

$alertaRacha = (int) config_get($pdo, 'alerta_racha', '3');
$alertaPct = (int) config_get($pdo, 'alerta_porcentaje', '60');
$codigoPais = config_get($pdo, 'codigo_pais', '507');
$alertas = obtener_alertas($pdo, $alertaRacha, (float) $alertaPct);

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
    ? (int) round(($asistenciaGlobal['puntos'] / $asistenciaGlobal['total']) * 100)
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

$nivelGlobal = nivel_pct($porcentajeAsistencia);

$page_title = 'Panel';
require __DIR__ . '/includes/header.php';
?>
<div class="dash-hero">
    <div>
        <h1>Panel general</h1>
        <p class="muted dash-date"><?= e(fecha_larga()) ?></p>
    </div>
    <div class="actions-row">
        <a class="btn btn-primary" href="asistencia.php"><?= nav_icon('check') ?> Tomar asistencia de hoy</a>
        <a class="btn" href="reportes.php"><?= nav_icon('chart') ?> Reportes</a>
    </div>
</div>

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
            <span class="stat-label"><?= $totalGrupos === 1 ? 'Grupo' : 'Grupos' ?></span>
        </span>
    </div>
    <div class="stat-card">
        <span class="stat-icon"><?= nav_icon('calendar') ?></span>
        <span class="stat-body">
            <span class="stat-value"><?= $sesionesMes ?></span>
            <span class="stat-label"><?= $sesionesMes === 1 ? 'Sesión este mes' : 'Sesiones este mes' ?></span>
        </span>
    </div>
    <div class="stat-card stat-<?= $nivelGlobal ?>">
        <span class="stat-icon"><?= nav_icon('trend') ?></span>
        <span class="stat-body">
            <span class="stat-value"><?= $porcentajeAsistencia !== null ? $porcentajeAsistencia . '%' : '—' ?></span>
            <span class="stat-label">Asistencia · últimos 30 días</span>
        </span>
    </div>
</div>

<div class="card card-alert <?= $alertas ? 'has-alerts' : '' ?>">
    <div class="card-head">
        <div>
            <h2>Alertas de riesgo <?php if ($alertas): ?><span class="badge badge-off"><?= count($alertas) ?></span><?php endif; ?></h2>
            <p class="muted">
                <?= $alertaRacha > 0 ? '<strong>' . $alertaRacha . ' sesiones seguidas sin asistir</strong> o ' : '' ?>asistencia por debajo del <strong><?= $alertaPct ?>%</strong> (con 3 o más sesiones registradas)
            </p>
        </div>
        <button class="btn btn-small" type="button" onclick="document.getElementById('form-alertas').classList.toggle('hidden')">Ajustes</button>
    </div>

    <form id="form-alertas" class="form-inline hidden" method="post" action="dashboard.php" style="margin:4px 0 14px">
        <input type="hidden" name="accion" value="ajustes_alertas">
        <label>Sesiones seguidas sin asistir (0 = desactivar) <input type="number" name="alerta_racha" min="0" max="20" value="<?= $alertaRacha ?>"></label>
        <label>% mínimo de asistencia <input type="number" name="alerta_porcentaje" min="0" max="100" value="<?= $alertaPct ?>"></label>
        <label>Código de país (WhatsApp) <input type="text" name="codigo_pais" value="<?= e($codigoPais) ?>" size="4"></label>
        <button class="btn btn-primary" type="submit">Guardar ajustes</button>
    </form>

    <?php if (!$alertas): ?>
        <div class="empty-state">
            <span class="empty-icon"><?= nav_icon('check') ?></span>
            <strong>Todo en orden</strong>
            <span class="muted">Nadie está en riesgo por ahora.</span>
        </div>
    <?php else: ?>
    <ul class="alert-list">
        <?php foreach ($alertas as $al): ?>
            <?php
            $wa = telefono_whatsapp($al['telefono'], $codigoPais);
            $mensaje = 'Hola' . ($al['encargado'] && $al['encargado'] !== '-' ? ' ' . $al['encargado'] : '')
                . ', le escribimos de la catequesis. Queríamos conversar sobre la asistencia de '
                . $al['nombres'] . ' (' . implode(' y ', $al['motivos']) . '). ¿Está todo bien? Gracias.';
            ?>
            <li class="alert-item">
                <span class="avatar avatar-off"><?= e(iniciales($al['nombres'], $al['apellidos'])) ?></span>
                <div class="alert-main">
                    <a class="alert-name" href="boletin.php?id=<?= (int) $al['id'] ?>"><?= e($al['nombres']) ?> <?= e($al['apellidos']) ?></a>
                    <span class="muted"><?= e($al['grupo_nombre']) ?></span>
                    <div class="alert-badges"><?php foreach ($al['motivos'] as $m): ?><span class="badge badge-off"><?= e($m) ?></span><?php endforeach; ?></div>
                </div>
                <div class="alert-contact">
                    <span><?= e($al['encargado'] && $al['encargado'] !== '-' ? $al['encargado'] : 'Sin encargado') ?></span>
                    <span class="muted"><?= e($al['telefono'] ?: 'Sin teléfono') ?></span>
                </div>
                <?php if ($wa): ?>
                    <a class="btn btn-small btn-whatsapp" target="_blank" rel="noopener" href="https://wa.me/<?= e($wa) ?>?text=<?= e(rawurlencode($mensaje)) ?>">WhatsApp</a>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-head">
            <div>
                <h2>Asistencia por sesión</h2>
                <p class="muted">Últimas <?= count($ultimasSesiones) ?> sesiones · % de puntos obtenidos</p>
            </div>
            <div class="chart-legend">
                <span><i class="dot dot-ok"></i>80% o más</span>
                <span><i class="dot dot-mid"></i>60–79%</span>
                <span><i class="dot dot-low"></i>Menos de 60%</span>
            </div>
        </div>
        <?php if (!$ultimasSesiones): ?>
            <div class="empty-state">
                <span class="empty-icon"><?= nav_icon('calendar') ?></span>
                <strong>Aún no hay sesiones</strong>
                <a class="btn btn-small btn-primary" href="asistencia.php">Tomar la primera asistencia</a>
            </div>
        <?php else: ?>
        <div class="chart">
            <div class="chart-plot">
                <span class="grid-line" style="bottom:100%"><em>100%</em></span>
                <span class="grid-line" style="bottom:50%"><em>50%</em></span>
                <span class="grid-line" style="bottom:0"><em>0%</em></span>
                <?php foreach ($ultimasSesiones as $s): ?>
                    <?php $pct = $s['total'] > 0 ? (int) round(($s['puntos'] / $s['total']) * 100) : 0; ?>
                    <a class="bar-col" href="reportes_sesion.php?id=<?= (int) $s['id'] ?>" title="<?= e($s['grupo_nombre']) ?> · <?= date('d/m/Y', strtotime($s['fecha'])) ?> · <?= $pct ?>% de asistencia">
                        <span class="bar-val"><?= $pct ?>%</span>
                        <span class="bar bar-<?= nivel_pct($pct) ?>" style="height: <?= max(3, $pct) ?>%"></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <div class="chart-labels">
                <?php foreach ($ultimasSesiones as $s): ?>
                    <span><?= date('d/m', strtotime($s['fecha'])) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <h2>Más ausencias acumuladas</h2>
                <p class="muted">Sesiones en las que no asistieron a nada</p>
            </div>
        </div>
        <?php if (!$topAusencias): ?>
            <div class="empty-state">
                <span class="empty-icon"><?= nav_icon('check') ?></span>
                <strong>Sin ausencias</strong>
                <span class="muted">Nadie ha faltado todavía.</span>
            </div>
        <?php else: ?>
        <ul class="rank-list">
            <?php foreach ($topAusencias as $r): ?>
                <li>
                    <span class="avatar"><?= e(iniciales($r['nombres'], $r['apellidos'])) ?></span>
                    <a class="rank-name" href="boletin.php?id=<?= (int) $r['estudiante_id'] ?>"><?= e($r['nombres']) ?> <?= e($r['apellidos']) ?></a>
                    <span class="badge badge-off"><?= (int) $r['ausencias'] ?> <?= (int) $r['ausencias'] === 1 ? 'falta' : 'faltas' ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="rank-more"><a href="reportes.php">Ver reporte completo →</a></p>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
