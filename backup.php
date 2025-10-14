<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/database.php';

$pdo = db(); // Garantiza que el archivo exista y la estructura esté creada.
$dbPath = __DIR__ . '/data/clinic.sqlite';

if (!file_exists($dbPath)) {
    http_response_code(404);
    echo 'Base de datos no encontrada.';
    exit;
}

$fileName = 'respaldo_consultorio_' . date('Ymd_His') . '.sqlite';
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . filesize($dbPath));
readfile($dbPath);
exit;
