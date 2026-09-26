<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/functions.php';

$grupos = $pdo->query('SELECT * FROM grupos ORDER BY nombre')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar') {
    $grupo_id = (int) $_POST['grupo_id'];
    $fecha = $_POST['fecha'];
    $tema = trim($_POST['tema'] ?? '') ?: null;
    $categoriasPost = $_POST['categoria'] ?? [];

    if ($grupo_id <= 0 || !$fecha) {
        set_flash('error', 'Selecciona un grupo y una fecha válidos.');
        redirect('asistencia.php');
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('
            INSERT INTO sesiones (grupo_id, fecha, tema) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE tema = VALUES(tema)
        ');
        $stmt->execute([$grupo_id, $fecha, $tema]);

        $stmt = $pdo->prepare('SELECT id FROM sesiones WHERE grupo_id = ? AND fecha = ?');
        $stmt->execute([$grupo_id, $fecha]);
        $sesion_id = (int) $stmt->fetchColumn();

        $upsert = $pdo->prepare('
            INSERT INTO asistencias (sesion_id, estudiante_id, categoria) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE categoria = VALUES(categoria)
        ');

        $categoriasValidas = ['completo', 'catequesis', 'misa', 'ausente'];
        $guardados = 0;
        foreach ($categoriasPost as $estudiante_id => $valor) {
            if (!in_array($valor, $categoriasValidas, true)) {
                continue;
            }
            $upsert->execute([$sesion_id, (int) $estudiante_id, $valor]);
            $guardados++;
        }

        $pdo->commit();
        set_flash('success', "Asistencia guardada ($guardados catequizandos).");
    } catch (PDOException $e) {
        $pdo->rollBack();
        set_flash('error', 'No se pudo guardar la asistencia. Intenta de nuevo.');
    }

    redirect('asistencia.php?grupo=' . $grupo_id . '&fecha=' . urlencode($fecha));
}

$grupo_id = (int) ($_GET['grupo'] ?? 0);
$fecha = $_GET['fecha'] ?? date('Y-m-d');

$estudiantes = [];
$sesion = null;
$asistenciasPrevias = [];

if ($grupo_id) {
    $stmt = $pdo->prepare('SELECT * FROM estudiantes WHERE grupo_id = ? ORDER BY nombres, apellidos');
    $stmt->execute([$grupo_id]);
    $estudiantes = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT * FROM sesiones WHERE grupo_id = ? AND fecha = ?');
    $stmt->execute([$grupo_id, $fecha]);
    $sesion = $stmt->fetch() ?: null;

    if ($sesion) {
        $stmt = $pdo->prepare('SELECT estudiante_id, categoria FROM asistencias WHERE sesion_id = ?');
        $stmt->execute([$sesion['id']]);
        foreach ($stmt->fetchAll() as $row) {
            $asistenciasPrevias[$row['estudiante_id']] = $row['categoria'];
        }
    }
}

$opcionesAsistencia = [
    ['valor' => 'completo', 'etiqueta' => 'Misa y Catequesis', 'puntos' => '1.0', 'clase' => 'completo'],
    ['valor' => 'catequesis', 'etiqueta' => 'Solo Catequesis', 'puntos' => '0.5', 'clase' => 'parcial'],
    ['valor' => 'misa', 'etiqueta' => 'Solo Misa', 'puntos' => '0.5', 'clase' => 'misa'],
    ['valor' => 'ausente', 'etiqueta' => 'No asistió', 'puntos' => '0.0', 'clase' => 'ausente'],
];

$page_title = 'Tomar asistencia';
require __DIR__ . '/includes/header.php';
?>
<h1>Tomar asistencia</h1>

<form class="card form-inline" method="get" action="asistencia.php">
    <label>Grupo
        <select name="grupo" required onchange="this.form.submit()">
            <option value="">-- Selecciona un grupo --</option>
            <?php foreach ($grupos as $g): ?>
                <option value="<?= (int) $g['id'] ?>" <?= $grupo_id == $g['id'] ? 'selected' : '' ?>><?= e($g['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Fecha <input type="date" name="fecha" value="<?= e($fecha) ?>" onchange="this.form.submit()"></label>
    <noscript><button class="btn" type="submit">Ver</button></noscript>
</form>

<?php if (!$grupos): ?>
    <div class="flash error">No hay grupos creados. Crea uno en <a href="grupos.php">Grupos</a>.</div>
<?php elseif ($grupo_id && !$estudiantes): ?>
    <div class="flash error">Este grupo no tiene catequizandos. Agrégalos en <a href="estudiantes.php">Catequizandos</a>.</div>
<?php elseif ($grupo_id && $estudiantes): ?>
    <?php if ($sesion): ?>
        <p class="muted">Ya existe una sesión para esta fecha. Puedes corregir la asistencia y guardar de nuevo, o <a href="reportes_sesion.php?id=<?= (int) $sesion['id'] ?>">ver el detalle</a>.</p>
    <?php endif; ?>
    <p class="muted">1 punto = misa y catequesis · 0.5 = solo catequesis o solo misa · 0 = no asistió.</p>
    <form method="post" action="asistencia.php" class="card" id="form-asistencia">
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="grupo_id" value="<?= (int) $grupo_id ?>">
        <input type="hidden" name="fecha" value="<?= e($fecha) ?>">
        <label class="full">Tema de la sesión (opcional)
            <input type="text" name="tema" value="<?= e($sesion['tema'] ?? '') ?>" placeholder="Ej: Los sacramentos">
        </label>

        <table class="table attendance-table">
            <thead>
                <tr>
                    <th>Catequizando</th>
                    <th>Asistencia</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($estudiantes as $es): ?>
                <?php $categoriaActual = $asistenciasPrevias[$es['id']] ?? 'completo'; ?>
                <tr>
                    <td><?= e($es['nombres']) ?> <?= e($es['apellidos']) ?></td>
                    <td>
                        <div class="chip-group">
                        <?php foreach ($opcionesAsistencia as $op): ?>
                            <label class="chip chip-<?= $op['clase'] ?>">
                                <input type="radio" name="categoria[<?= (int) $es['id'] ?>]" value="<?= $op['valor'] ?>" data-puntos-radio data-puntos="<?= $op['puntos'] ?>" <?= $categoriaActual === $op['valor'] ? 'checked' : '' ?>>
                                <span><?= e($op['etiqueta']) ?></span>
                            </label>
                        <?php endforeach; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="points-summary">
            Puntos de la sesión: <strong id="puntos-total">0.0</strong> / <span id="puntos-max">0.0</span>
        </div>

        <button class="btn btn-primary" type="submit">Guardar asistencia</button>
    </form>
    <script src="assets/js/asistencia.js"></script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
