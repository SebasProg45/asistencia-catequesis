<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/functions.php';

$grupos = $pdo->query('SELECT g.*, COUNT(es.id) AS total FROM grupos g LEFT JOIN estudiantes es ON es.grupo_id = g.id GROUP BY g.id ORDER BY g.nombre')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'mover') {
    $origen = (int) ($_POST['origen'] ?? 0);
    $destino = (int) ($_POST['destino'] ?? 0);
    // "estudiantes" debe llegar como arreglo (viene de casillas marcadas); si alguien lo
    // envía como un solo valor suelto, se trata como si no hubiera nada seleccionado
    // en vez de romper array_map().
    $estudiantesPost = is_array($_POST['estudiantes'] ?? null) ? $_POST['estudiantes'] : [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $estudiantesPost))));

    if ($origen <= 0 || $destino <= 0 || $origen === $destino) {
        set_flash('error', 'Elige un grupo de origen y un grupo de destino distintos.');
        redirect('promocion.php' . ($origen > 0 ? '?origen=' . $origen : ''));
    }
    if (!$ids) {
        set_flash('error', 'Selecciona al menos un catequizando.');
        redirect('promocion.php?origen=' . $origen);
    }

    try {
        $pdo->beginTransaction();
        // Solo mueve a quienes de verdad siguen en el grupo de origen (evita mover a alguien por un formulario viejo).
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("UPDATE estudiantes SET grupo_id = ? WHERE grupo_id = ? AND id IN ($marcas)");
        $stmt->execute(array_merge([$destino, $origen], $ids));
        $movidos = $stmt->rowCount();
        $pdo->commit();
        set_flash('success', "$movidos catequizando(s) movido(s) de grupo. Su historial de asistencia se conserva.");
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'No se pudo mover a los catequizandos. Intenta de nuevo.');
    }
    redirect('promocion.php?origen=' . $origen);
}

$origen = (int) ($_GET['origen'] ?? 0);
$estudiantes = [];
if ($origen > 0) {
    $stmt = $pdo->prepare('SELECT id, nombres, apellidos FROM estudiantes WHERE grupo_id = ? ORDER BY nombres, apellidos');
    $stmt->execute([$origen]);
    $estudiantes = $stmt->fetchAll();
}

$page_title = 'Pasar de grupo';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>Pasar de grupo (promoción)</h1>
    <a class="btn" href="estudiantes.php">← Volver a catequizandos</a>
</div>
<p class="muted">Selecciona a quienes pasan al siguiente nivel y elige el grupo de destino. Su historial de asistencia y de actividades se conserva y sigue contando en los reportes.</p>

<form class="card form-inline" method="get" action="promocion.php">
    <label>Grupo de origen
        <select name="origen" onchange="this.form.submit()">
            <option value="">-- Selecciona --</option>
            <?php foreach ($grupos as $g): ?>
                <option value="<?= (int) $g['id'] ?>" <?= $origen === (int) $g['id'] ? 'selected' : '' ?>><?= e($g['nombre']) ?> (<?= (int) $g['total'] ?>)</option>
            <?php endforeach; ?>
        </select>
    </label>
    <noscript><button class="btn" type="submit">Ver</button></noscript>
</form>

<?php if ($origen > 0): ?>
    <?php if (!$estudiantes): ?>
        <div class="flash error">Este grupo no tiene catequizandos.</div>
    <?php elseif (count($grupos) < 2): ?>
        <div class="flash error">Necesitas al menos otro grupo como destino. Créalo en <a href="grupos.php">Grupos</a>.</div>
    <?php else: ?>
    <form class="card" method="post" action="promocion.php" id="form-promocion"
          onsubmit="var n=document.querySelectorAll('#form-promocion .check-item input:checked').length; var d=document.getElementById('destino'); if(!d.value){alert('Elige el grupo de destino.');return false;} return confirm('¿Estás seguro? Vas a mover a '+n+' catequizando(s) a «'+d.options[d.selectedIndex].text+'».');">
        <input type="hidden" name="accion" value="mover">
        <input type="hidden" name="origen" value="<?= $origen ?>">
        <div class="form-inline">
            <label>Grupo de destino
                <select name="destino" id="destino" required>
                    <option value="">-- Selecciona --</option>
                    <?php foreach ($grupos as $g): ?>
                        <?php if ((int) $g['id'] !== $origen): ?>
                            <option value="<?= (int) $g['id'] ?>"><?= e($g['nombre']) ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn" type="button" onclick="document.querySelectorAll('#form-promocion .check-item input').forEach(function(c){c.checked=true})">Marcar todos</button>
            <button class="btn" type="button" onclick="document.querySelectorAll('#form-promocion .check-item input').forEach(function(c){c.checked=false})">Desmarcar todos</button>
        </div>

        <div class="check-list">
            <?php foreach ($estudiantes as $es): ?>
                <label class="check-item">
                    <input type="checkbox" name="estudiantes[]" value="<?= (int) $es['id'] ?>">
                    <?= e($es['nombres']) ?> <?= e($es['apellidos']) ?>
                </label>
            <?php endforeach; ?>
        </div>

        <button class="btn btn-primary" type="submit">Mover a los seleccionados</button>
    </form>
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
