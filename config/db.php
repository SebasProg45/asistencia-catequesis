<?php
// Configuración de conexión a la base de datos (WampServer / MySQL local)

$DB_HOST = '127.0.0.1';
$DB_NAME = 'asistencia_catequesis';
$DB_USER = 'root';
$DB_PASS = ''; // completa si le pusiste contraseña al root de MySQL

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die('No se pudo conectar a la base de datos. Verifica que WampServer esté iniciado (icono en verde) '
        . 'y que ejecutaste sql/schema.sql en phpMyAdmin. Detalle: ' . htmlspecialchars($e->getMessage()));
}
