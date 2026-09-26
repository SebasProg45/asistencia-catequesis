<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/functions.php';

$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT act.*, g.nombre AS grupo_nombre FROM actividades act JOIN grupos g ON g.id = act.grupo_id WHERE act.id = ?');
$stmt->execute([$id]);
$actividad = $stmt->fetch();

if (!$actividad) {
    set_flash('error', 'Actividad no encontrada.');
    redirect('actividades.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar') {
    $asistenciaPost = $_POST['asistio'] ?? [];

    $upsert = $pdo->prepare('
        INSERT INTO actividad_asistencias (actividad_id, estudiante_id, asistio) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE asistio = VALUES(asistio)
    ');

    try {
        $guardados = 0;
        foreach ($asistenciaPost as $estudiante_id => $valor) {
            if (!in_array($valor, ['1', '0'], true)) {
                continue;
            }
            $upsert->execute([$id, (int) $estudiante_id, $valor]);
            $guardados++;
        }
        set_flash('success', "Asistencia de la actividad guardada ($guardados catequizandos).");
    } catch (PDOException $e) {
        set_flash('error', 'No se pudo guardar la asistencia. Intenta de nuevo.');
    }

    redirect('actividad.php?id=' . $id);
}

$stmt = $pdo->prepare('SELECT * FROM estudiantes WHERE grupo_id = ? ORDER BY nombres, apellidos');
$stmt->execute([$actividad['grupo_id']]);
$estudiantes = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT estudiante_id, asistio FROM actividad_asistencias WHERE actividad_id = ?');
$stmt->execute([$id]);
$asistenciasPrevias = [];
foreach ($stmt->fetchAll() as $row) {
    $asistenciasPrevias[$row['estudiante_id']] = $row['asistio'];
}

$page_title = 'Tomar asistencia — actividad';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head no-print">
    <h1>Asistencia a la actividad</h1>
    <div>
        <button class="btn" onclick="window.print()">🖨 Imprimir / Guardar PDF</button>
        <a class="btn" href="actividades.php">← Volver a actividades</a>
    </div>
</div>

<div class="card print-card">
    <h2><?= e($actividad['nombre']) ?> — <?= date('d/m/Y', strtotime($actividad['fecha'])) ?></h2>
    <p class="muted">Grupo: <?= e($actividad['grupo_nombre']) ?><?php if ($actividad['descripcion']): ?> · <?= e($actividad['descripcion']) ?><?php endif; ?></p>
    <p class="muted">Actividad extracurricular/parroquial: esta asistencia no suma ni resta puntos, es solo un registro de participación.</p>

    <?php if (!$estudiantes): ?>
        <p class="empty">Este grupo no tiene catequizandos.</p>
    <?php else: ?>
    <form method="post" action="actividad.php?id=<?= $id ?>" id="form-actividad">
        <input type="hidden" name="accion" value="guardar">
        <table class="table attendance-table">
            <thead><tr><th>Catequizando</th><th>Asistencia</th></tr></thead>
            <tbody>
            <?php foreach ($estudiantes as $es): ?>
                <?php $asistioActual = array_key_exists($es['id'], $asistenciasPrevias) ? (string) $asistenciasPrevias[$es['id']] : '1'; ?>
                <tr>
                    <td><?= e($es['nombres']) ?> <?= e($es['apellidos']) ?></td>
                    <td>
                        <div class="chip-group">
                            <label class="chip chip-completo">
                                <input type="radio" name="asistio[<?= (int) $es['id'] ?>]" value="1" data-asistio-radio <?= $asistioActual === '1' ? 'checked' : '' ?>>
                                <span>Asistió</span>
                            </label>
                            <label class="chip chip-ausente">
                                <input type="radio" name="asistio[<?= (int) $es['id'] ?>]" value="0" data-asistio-radio <?= $asistioActual === '0' ? 'checked' : '' ?>>
                                <span>No asistió</span>
                            </label>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="points-summary no-print">
            Asistieron: <strong id="asistio-total">0</strong> / <span id="asistio-max">0</span>
        </div>

        <button class="btn btn-primary no-print" type="submit">Guardar asistencia</button>
    </form>
    <script src="assets/js/actividad.js"></script>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
