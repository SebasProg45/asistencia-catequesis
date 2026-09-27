<?php
// Espera que $page_title esté definido antes de incluir este archivo.
$page_title = $page_title ?? 'Asistencia Catequesis';
$current_script = basename($_SERVER['SCRIPT_NAME']);

function nav_active(string $script, string $current): string
{
    return $script === $current ? 'active' : '';
}

function nav_icon(string $name): string
{
    $paths = [
        'home' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5"/>',
        'check' => '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="m8 12.5 2.5 2.5L16 9.5"/>',
        'users' => '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 20c.5-3.2 2.8-5 5.5-5s5 1.8 5.5 5"/><path d="M15.5 6.2a3.2 3.2 0 0 1 0 6.1"/><path d="M17 15.3c2.4.4 4 2.1 4.5 4.7"/>',
        'book' => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v16H6.5A2.5 2.5 0 0 0 4 21.5z"/><path d="M4 19V5.5"/>',
        'chart' => '<path d="M4 20V10"/><path d="M12 20V4"/><path d="M20 20v-7"/><path d="M3 20h18"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18"/><path d="M8 3v4"/><path d="M16 3v4"/>',
        'trend' => '<path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
        'db' => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'edit' => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/>',
        'trash' => '<path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="M6 7l1 13h10l1-13"/><path d="M10 11v5"/><path d="M14 11v5"/>',
        'plus' => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'search' => '<circle cx="11" cy="11" r="6"/><path d="M20 20l-4-4"/>',
        'x' => '<path d="M6 6l12 12"/><path d="M18 6L6 18"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'undo' => '<path d="M9 14L4 9l5-5"/><path d="M4 9h10a6 6 0 010 12h-3"/>',
        'star' => '<path d="M12 2.5l2.9 6.5 6.9.7-5.2 4.8 1.5 6.9L12 17.8l-6.1 3.6 1.5-6.9-5.2-4.8 6.9-.7z"/>',
        'tick' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'cross' => '<path d="M12 3v18"/><path d="M6.5 9h11"/>',
        'lock' => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
        'arrow' => '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>',
    ];
    return '<svg class="nav-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . ($paths[$name] ?? '') . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($page_title) ?> · Asistencia Catequesis</title>
<link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
</head>
<body>
<div class="app-shell">
<aside class="sidebar">
    <a class="brand" href="dashboard.php">
        <span class="brand-mark">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 3v18"/><path d="M6 8h12"/></svg>
        </span>
        <span class="brand-text">Catequesis<br><em>Asistencia</em></span>
    </a>
    <nav class="mainnav">
        <a class="<?= nav_active('dashboard.php', $current_script) ?>" href="dashboard.php"><?= nav_icon('home') ?><span>Panel</span></a>
        <a class="<?= nav_active('asistencia.php', $current_script) ?>" href="asistencia.php"><?= nav_icon('check') ?><span>Tomar asistencia</span></a>
        <a class="<?= nav_active('estudiantes.php', $current_script) ?>" href="estudiantes.php"><?= nav_icon('users') ?><span>Catequizandos</span></a>
        <a class="<?= nav_active('grupos.php', $current_script) ?>" href="grupos.php"><?= nav_icon('book') ?><span>Grupos</span></a>
        <a class="<?= in_array($current_script, ['actividades.php', 'actividad.php'], true) ? 'active' : '' ?>" href="actividades.php"><?= nav_icon('star') ?><span>Actividades</span></a>
        <a class="<?= nav_active('reportes.php', $current_script) ?>" href="reportes.php"><?= nav_icon('chart') ?><span>Reportes</span></a>
        <a class="<?= nav_active('respaldo.php', $current_script) ?>" href="respaldo.php"><?= nav_icon('db') ?><span>Respaldo</span></a>
    </nav>
    <div class="sidebar-foot">Sistema local · WampServer</div>
</aside>
<div class="main-col">
<main class="page">
<?php if (!empty($_SESSION['flash'])): ?>
    <div class="flash <?= htmlspecialchars($_SESSION['flash']['type']) ?>">
        <?= htmlspecialchars($_SESSION['flash']['message']) ?>
    </div>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
