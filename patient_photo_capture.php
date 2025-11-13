<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/database.php';
$pdo = db();

header('Content-Type: application/json; charset=utf-8');

$patientId = isset($_POST['patient_id']) ? (int) $_POST['patient_id'] : 0;
$photo = isset($_POST['photo']) ? (string) $_POST['photo'] : '';
if ($patientId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Paciente no válido.']);
    exit;
}
if ($photo === '') {
    echo json_encode(['success' => false, 'error' => 'No se proporcionó imagen.']);
    exit;
}

// Expect data URL like: data:image/jpeg;base64,/9j/4AAQ...
if (!preg_match('#^data:(image/\w+);base64,#i', $photo, $m)) {
    echo json_encode(['success' => false, 'error' => 'Formato de imagen no válido.']);
    exit;
}
$mime = strtolower($m[1]);
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($allowed[$mime])) {
    // allow jpeg/png/webp only
    echo json_encode(['success' => false, 'error' => 'Tipo de imagen no permitido.']);
    exit;
}
$ext = $allowed[$mime];
$base64 = substr($photo, strpos($photo, ',') + 1);
$base64 = str_replace(' ', '+', $base64);
$data = base64_decode($base64);
if ($data === false) {
    echo json_encode(['success' => false, 'error' => 'No se pudo decodificar la imagen.']);
    exit;
}

$uploadDir = __DIR__ . '/assets/patient_photos';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        echo json_encode(['success' => false, 'error' => 'No se pudo crear el directorio de destino.']);
        exit;
    }
}

$filename = sprintf('patient_%d_%s.%s', $patientId, preg_replace('/[^0-9A-Za-z_-]/', '', (string) time() . '_' . bin2hex(random_bytes(4))), $ext);
$targetPath = $uploadDir . '/' . $filename;
if (file_put_contents($targetPath, $data) === false) {
    echo json_encode(['success' => false, 'error' => 'No se pudo guardar la imagen en el servidor.']);
    exit;
}

$relativePath = 'assets/patient_photos/' . $filename;
// Update database
try {
    $stmt = $pdo->prepare('UPDATE patients SET profile_photo_path = :path WHERE id = :id');
    $stmt->execute([':path' => $relativePath, ':id' => $patientId]);
} catch (Throwable $e) {
    // Attempt to remove the file if DB update fails
    @unlink($targetPath);
    echo json_encode(['success' => false, 'error' => 'Error al actualizar la base de datos.']);
    exit;
}

echo json_encode(['success' => true, 'path' => $relativePath]);
exit;
