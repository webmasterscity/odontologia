<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/database.php';

ensureSessionStarted();

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$respondJson = static function (int $status, array $payload): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isAjax) {
        $respondJson(405, ['success' => false, 'errors' => ['Método no permitido.']]);
    }
    http_response_code(405);
    echo 'Método no permitido.';
    exit;
}

$patientId = isset($_POST['patient_id']) ? (int) $_POST['patient_id'] : 0;
$redirectTarget = 'patient_history.php?id=' . $patientId . '#studies';

if ($patientId <= 0) {
    if ($isAjax) {
        $respondJson(400, ['success' => false, 'errors' => ['Paciente inválido.']]);
    }
    http_response_code(400);
    echo 'Paciente inválido.';
    exit;
}

if (!verifyCsrfToken(post('csrf_token'))) {
    $_SESSION['patient_studies_errors'] = ['La sesión expiró. Vuelve a intentarlo.'];
    if ($isAjax) {
        $respondJson(419, ['success' => false, 'errors' => $_SESSION['patient_studies_errors']]);
    }
    header('Location: ' . $redirectTarget);
    exit;
}

$pdo = db();
$patientExists = $pdo->prepare('SELECT COUNT(*) FROM patients WHERE id = :id');
$patientExists->execute([':id' => $patientId]);
if ((int) $patientExists->fetchColumn() === 0) {
    $_SESSION['patient_studies_errors'] = ['El paciente indicado no existe.'];
    if ($isAjax) {
        $respondJson(404, ['success' => false, 'errors' => $_SESSION['patient_studies_errors']]);
    }
    header('Location: ' . $redirectTarget);
    exit;
}

$errors = [];
$studyLimit = getPatientStudyLimit();
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM patient_studies WHERE patient_id = :patient_id');
$countStmt->execute([':patient_id' => $patientId]);
$currentCount = (int) $countStmt->fetchColumn();
if ($currentCount >= $studyLimit) {
    $errors[] = 'Se alcanzó el número máximo de estudios permitidos para este paciente.';
}

$fileData = $_FILES['study_file'] ?? null;
if (!is_array($fileData) || ($fileData['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    $errors[] = 'Selecciona un archivo para subir.';
}

$maxFileSize = 10 * 1024 * 1024; // 10 MB
$allowedTypes = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'application/pdf' => 'pdf',
];
$detectedMime = null;
$extension = null;
$originalName = null;

if (empty($errors) && is_array($fileData)) {
    $errorCode = $fileData['error'] ?? UPLOAD_ERR_OK;
    if ($errorCode !== UPLOAD_ERR_OK) {
        $errors[] = 'No se pudo cargar el archivo. Intenta nuevamente.';
    } elseif (($fileData['size'] ?? 0) <= 0) {
        $errors[] = 'El archivo está vacío.';
    } elseif (($fileData['size'] ?? 0) > $maxFileSize) {
        $errors[] = 'El archivo supera el límite de 10 MB.';
    } else {
        $originalName = $fileData['name'] ?? '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detectedMime = finfo_file($finfo, $fileData['tmp_name']) ?: null;
                finfo_close($finfo);
            }
        }
        if ($detectedMime === null && function_exists('mime_content_type')) {
            $detectedMime = @mime_content_type($fileData['tmp_name']) ?: null;
        }
        if ($detectedMime === null) {
            $imageInfo = @getimagesize($fileData['tmp_name']);
            if (is_array($imageInfo) && isset($imageInfo['mime'])) {
                $detectedMime = $imageInfo['mime'];
            }
        }

        if ($detectedMime === null || !isset($allowedTypes[$detectedMime])) {
            $errors[] = 'Solo se permiten archivos JPG, PNG, WEBP o PDF.';
        } else {
            $extension = $allowedTypes[$detectedMime];
        }
    }
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

$uploadDir = __DIR__ . '/assets/patient_studies';
$relativePath = null;
$targetPath = null;

if (empty($errors)) {
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        $errors[] = 'No se pudo preparar la carpeta de almacenamiento.';
    }
}

if (empty($errors) && is_array($fileData) && $extension !== null) {
    try {
        $uniqueSegment = bin2hex(random_bytes(8));
    } catch (Exception $exception) {
        $uniqueSegment = sha1(uniqid('', true));
    }
    $fileName = date('YmdHis') . '_' . $uniqueSegment . '.' . $extension;
    $targetPath = $uploadDir . '/' . $fileName;
    $relativePath = 'assets/patient_studies/' . $fileName;

    $moved = false;
    if (is_uploaded_file($fileData['tmp_name'])) {
        $moved = move_uploaded_file($fileData['tmp_name'], $targetPath);
    }
    if (!$moved && is_file($fileData['tmp_name'])) {
        $moved = rename($fileData['tmp_name'], $targetPath) || copy($fileData['tmp_name'], $targetPath);
    }

    if (!$moved) {
        $errors[] = 'No se pudo guardar el archivo en el servidor.';
    } else {
        @chmod($targetPath, 0664);
    }
}

if (!empty($errors)) {
    if ($targetPath && is_file($targetPath)) {
        @unlink($targetPath);
    }
    $_SESSION['patient_studies_errors'] = $errors;
    if ($isAjax) {
        $respondJson(422, ['success' => false, 'errors' => $errors]);
    }
    header('Location: ' . $redirectTarget);
    exit;
}

try {
    insertPatientStudy($pdo, [
        'patient_id' => $patientId,
        'title' => $title,
        'study_type' => $studyType,
        'captured_at' => $capturedAt,
        'notes' => $notes,
        'original_filename' => $originalName,
        'file_path' => $relativePath,
        'mime_type' => $detectedMime,
        'file_size' => $fileData['size'] ?? null,
        'uploaded_by' => $uploadedBy,
    ]);
} catch (Throwable $exception) {
    if ($targetPath && is_file($targetPath)) {
        @unlink($targetPath);
    }
    $_SESSION['patient_studies_errors'] = ['No se pudo registrar el estudio. Inténtalo nuevamente.'];
    if ($isAjax) {
        $respondJson(500, ['success' => false, 'errors' => $_SESSION['patient_studies_errors']]);
    }
    header('Location: ' . $redirectTarget);
    exit;
}

$_SESSION['patient_studies_messages'] = ['Estudio paraclínico cargado correctamente.'];
if ($isAjax) {
    $respondJson(200, ['success' => true]);
}
header('Location: ' . $redirectTarget);
exit;
