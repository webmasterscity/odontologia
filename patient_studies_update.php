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

$title = trim((string) post('title'));
$title = $title !== '' ? $title : null;
$studyType = trim((string) post('study_type'));
$studyType = $studyType !== '' ? $studyType : null;
$capturedAt = normalizeDate(post('captured_at'));
$notes = trim((string) post('notes'));
$notes = $notes !== '' ? $notes : null;
$uploadedBy = trim((string) post('uploaded_by'));
$uploadedBy = $uploadedBy !== '' ? $uploadedBy : null;

$updateData = [
    'title' => $title,
    'study_type' => $studyType,
    'captured_at' => $capturedAt,
    'notes' => $notes,
    'uploaded_by' => $uploadedBy,
];

try {
    if (!updatePatientStudy($pdo, $patientId, $studyId, $updateData)) {
        $_SESSION['patient_studies_errors'] = ['No se pudo actualizar el estudio. Inténtalo nuevamente.'];
        header('Location: ' . $redirectTarget);
        exit;
    }
} catch (Throwable $exception) {
    $_SESSION['patient_studies_errors'] = ['Ocurrió un error al actualizar el estudio.'];
    header('Location: ' . $redirectTarget);
    exit;
}

$_SESSION['patient_studies_messages'] = ['Estudio actualizado correctamente.'];
header('Location: ' . $redirectTarget);
exit;
