<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/database.php';

ensureSessionStarted();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Método no permitido.';
    exit;
}

$patientId = isset($_POST['patient_id']) ? (int) $_POST['patient_id'] : 0;
$studyId = isset($_POST['study_id']) ? (int) $_POST['study_id'] : 0;
$redirectTarget = 'patient_history.php?id=' . $patientId . '#studies';

if ($patientId <= 0 || $studyId <= 0) {
    http_response_code(400);
    echo 'Solicitud inválida.';
    exit;
}

if (!verifyCsrfToken(post('csrf_token'))) {
    $_SESSION['patient_studies_errors'] = ['La sesión expiró. Vuelve a intentarlo.'];
    header('Location: ' . $redirectTarget);
    exit;
}

$pdo = db();
$study = findPatientStudy($pdo, $patientId, $studyId);
if (!$study) {
    $_SESSION['patient_studies_errors'] = ['No se encontró el estudio solicitado.'];
    header('Location: ' . $redirectTarget);
    exit;
}

if (!deletePatientStudy($pdo, $patientId, $studyId)) {
    $_SESSION['patient_studies_errors'] = ['No fue posible eliminar el estudio.'];
    header('Location: ' . $redirectTarget);
    exit;
}

$relativePath = isset($study['file_path']) ? (string) $study['file_path'] : '';
if ($relativePath !== '') {
    $absolutePath = __DIR__ . '/' . ltrim($relativePath, '/');
    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

$_SESSION['patient_studies_messages'] = ['Estudio eliminado exitosamente.'];
header('Location: ' . $redirectTarget);
exit;
