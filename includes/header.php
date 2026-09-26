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
        'star' => '<path d="M12 2.5l2.9 6.5 6.9.7-5.2 4.8 1.5 6.9L12 17.8l-6.1 3.6 1.5-6.9-5.2-4.8 6.9-.7z"/>',
    ];
    return '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . ($paths[$name] ?? '') . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($page_title) ?> · Asistencia Catequesis</title>
<link rel="stylesheet" href="assets/css/style.css">
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
