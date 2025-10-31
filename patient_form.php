<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/database.php';

$pdo = db();
$patientId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$patient = null;
$errors = [];

if ($patientId) {
    $stmt = $pdo->prepare('SELECT * FROM patients WHERE id = :id');
    $stmt->execute([':id' => $patientId]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$patient) {
        http_response_code(404);
        echo 'Paciente no encontrado.';
        exit;
    }
}

$profilePhotoPath = $patient['profile_photo_path'] ?? null;
$previousPhotoPath = $profilePhotoPath;
$manualPhotoPathValue = '';
$removePhotoRequested = false;
$defaultPhotoZoom = 1.0;
$defaultPhotoOffset = 0.0;

if (!function_exists('clamp_photo_value')) {
    /**
     * @return float
     */
    function clamp_photo_value(float $value, float $min, float $max)
    {
        return max($min, min($max, $value));
    }
}

$profilePhotoZoom = clamp_photo_value((float) ($patient['profile_photo_zoom'] ?? $defaultPhotoZoom), 1.0, 2.5);
$profilePhotoOffsetX = clamp_photo_value((float) ($patient['profile_photo_offset_x'] ?? 0.0), -60.0, 60.0);
$profilePhotoOffsetY = clamp_photo_value((float) ($patient['profile_photo_offset_y'] ?? 0.0), -60.0, 60.0);
$profilePhotoTransform = sprintf(
    'transform: translate(%0.2f%%, %0.2f%%) scale(%0.3f);',
    $profilePhotoOffsetX,
    $profilePhotoOffsetY,
    $profilePhotoZoom
);
$profilePhotoZoomValue = number_format($profilePhotoZoom, 2, '.', '');
$profilePhotoOffsetXValue = number_format($profilePhotoOffsetX, 2, '.', '');
$profilePhotoOffsetYValue = number_format($profilePhotoOffsetY, 2, '.', '');
$profilePhotoZoomPercent = (int) round($profilePhotoZoom * 100);
$profilePhotoOffsetXDisplay = number_format($profilePhotoOffsetX, 1, '.', '');
$profilePhotoOffsetYDisplay = number_format($profilePhotoOffsetY, 1, '.', '');

function calculateAge(?string $birthDate): ?int
{
    if (!$birthDate) {
        return null;
    }
    try {
        $birth = new DateTime($birthDate);
        $today = new DateTime('today');
        return (int) $birth->diff($today)->y;
    } catch (Exception $e) {
        return null;
    }
}

function capitalizeInitial($value): ?string
{
    if ($value === null) {
        return null;
    }
    if (is_array($value)) {
        return null;
    }
    $trimmed = trim((string) $value);
    if ($trimmed === '') {
        return null;
    }
    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        $firstChar = mb_substr($trimmed, 0, 1, 'UTF-8');
        $rest = mb_substr($trimmed, 1, null, 'UTF-8');
        return mb_strtoupper($firstChar, 'UTF-8') . $rest;
    }
    $firstChar = substr($trimmed, 0, 1);
    $rest = substr($trimmed, 1);
    return strtoupper($firstChar) . $rest;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $photoUpload = $_FILES['profile_photo'] ?? null;
    $shouldProcessPhoto = is_array($photoUpload) && (($photoUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
    $photoExtension = null;
    $allowedPhotoTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $manualPhotoPathInput = trim((string) post('profile_photo_copy_path'));
    $manualPhotoPathValue = $manualPhotoPathInput;
    $zoomRaw = post('profile_photo_zoom', null);
    if ($zoomRaw !== null && $zoomRaw !== '') {
        $profilePhotoZoom = clamp_photo_value((float) $zoomRaw, 1.0, 2.5);
    }
    $offsetXRaw = post('profile_photo_offset_x', null);
    if ($offsetXRaw !== null && $offsetXRaw !== '') {
        $profilePhotoOffsetX = clamp_photo_value((float) $offsetXRaw, -60.0, 60.0);
    }
    $offsetYRaw = post('profile_photo_offset_y', null);
    if ($offsetYRaw !== null && $offsetYRaw !== '') {
        $profilePhotoOffsetY = clamp_photo_value((float) $offsetYRaw, -60.0, 60.0);
    }
    $pathsToDelete = [];
    $removePhotoRequested = (string) post('remove_photo', '0') === '1';
    if ($removePhotoRequested && $profilePhotoPath) {
        $pathsToDelete[] = $profilePhotoPath;
        $profilePhotoPath = null;
    }
    if ($removePhotoRequested) {
        $profilePhotoZoom = $defaultPhotoZoom;
        $profilePhotoOffsetX = $defaultPhotoOffset;
        $profilePhotoOffsetY = $defaultPhotoOffset;
    }

    $fullName = capitalizeInitial(post('full_name'));
    if ($fullName === null) {
        $errors[] = 'El nombre del paciente es obligatorio.';
    }

    $birthDate = normalizeDate(post('birth_date'));
    $payload = [
        'full_name' => $fullName,
        'preferred_name' => capitalizeInitial(post('preferred_name')),
        'document_id' => capitalizeInitial(post('document_id')),
        'birth_date' => $birthDate,
        'age' => calculateAge($birthDate),
        'gender' => post('gender') ?: null,
        'marital_status' => post('marital_status') ?: null,
        'occupation' => capitalizeInitial(post('occupation')),
        'address' => capitalizeInitial(post('address')),
        'email' => trim((string) post('email')) ?: null,
        'phone_primary' => trim((string) post('phone_primary')) ?: null,
        'phone_secondary' => trim((string) post('phone_secondary')) ?: null,
        'referred_by' => capitalizeInitial(post('referred_by')),
        'primary_physician' => capitalizeInitial(post('primary_physician')),
        'primary_physician_phone' => trim((string) post('primary_physician_phone')) ?: null,
        'insurance_provider' => capitalizeInitial(post('insurance_provider')),
        'insurance_policy_number' => capitalizeInitial(post('insurance_policy_number')),
        'representative_name' => capitalizeInitial(post('representative_name')),
        'representative_document' => capitalizeInitial(post('representative_document')),
        'representative_phone' => trim((string) post('representative_phone')) ?: null,
        'emergency_contact' => capitalizeInitial(post('emergency_contact')),
        'emergency_contact_relationship' => capitalizeInitial(post('emergency_contact_relationship')),
        'emergency_contact_phone' => trim((string) post('emergency_contact_phone')) ?: null,
        'notes' => capitalizeInitial(post('notes')),
    ];

    if ($shouldProcessPhoto) {
        if (($photoUpload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $errors[] = 'No se pudo cargar la foto del paciente. Inténtalo nuevamente.';
        } elseif (($photoUpload['size'] ?? 0) <= 0) {
            $errors[] = 'El archivo seleccionado está vacío. Selecciona una imagen válida.';
        } elseif (($photoUpload['size'] ?? 0) > 5 * 1024 * 1024) {
            $errors[] = 'La foto debe pesar menos de 5 MB.';
        } else {
            $detectedMime = null;

            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo !== false) {
                    $detectedMime = finfo_file($finfo, $photoUpload['tmp_name']) ?: null;
                    finfo_close($finfo);
                }
            }
            if ($detectedMime === null && function_exists('mime_content_type')) {
                $detectedMime = @mime_content_type($photoUpload['tmp_name']) ?: null;
            }
            if ($detectedMime === null) {
                $imageInfo = @getimagesize($photoUpload['tmp_name']);
                if (is_array($imageInfo) && isset($imageInfo['mime'])) {
                    $detectedMime = $imageInfo['mime'];
                }
            }

            if ($detectedMime === null || !isset($allowedPhotoTypes[$detectedMime])) {
                $errors[] = 'Solo se permiten imágenes en formato JPG, PNG o WEBP.';
            } else {
                $photoExtension = $allowedPhotoTypes[$detectedMime];
            }
        }
    }

    if (empty($errors) && $shouldProcessPhoto && $photoExtension !== null) {
        $uploadDir = __DIR__ . '/assets/patient_photos';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            $errors[] = 'No se pudo preparar la carpeta para almacenar la foto del paciente.';
        } else {
            try {
                $uniqueSegment = bin2hex(random_bytes(8));
            } catch (Exception $exception) {
                $uniqueSegment = sha1(uniqid('', true));
            }
            $fileName = date('YmdHis') . '_' . $uniqueSegment . '.' . $photoExtension;
            $targetPath = $uploadDir . '/' . $fileName;

            $moved = false;
            if (is_uploaded_file($photoUpload['tmp_name'])) {
                $moved = move_uploaded_file($photoUpload['tmp_name'], $targetPath);
            }
            if (!$moved && is_file($photoUpload['tmp_name'])) {
                $moved = rename($photoUpload['tmp_name'], $targetPath) || copy($photoUpload['tmp_name'], $targetPath);
            }

            if (!$moved) {
                $lastError = error_get_last();
                $errors[] = 'No se pudo guardar la foto del paciente. Revisa los permisos e inténtalo nuevamente.' . ($lastError ? ' Detalle: ' . $lastError['message'] : '');
            } else {
                @chmod($targetPath, 0664);
                $newRelativePath = 'assets/patient_photos/' . $fileName;
                if ($previousPhotoPath && $previousPhotoPath !== $newRelativePath) {
                    $pathsToDelete[] = $previousPhotoPath;
                }
                $profilePhotoPath = $newRelativePath;
                $removePhotoRequested = false;
            }
        }
    }

    if (!$shouldProcessPhoto && $manualPhotoPathInput !== '' && empty($errors)) {
        $resolvedManualPath = $manualPhotoPathInput;
        if ($manualPhotoPathInput[0] === '~') {
            $homeDir = null;
            if (function_exists('posix_getpwuid') && function_exists('posix_getuid')) {
                $pw = @posix_getpwuid(posix_getuid());
                if (is_array($pw) && isset($pw['dir'])) {
                    $homeDir = $pw['dir'];
                }
            }
            if ($homeDir === null) {
                $homeDir = getenv('HOME') ?: null;
            }
            if ($homeDir !== null) {
                $resolvedManualPath = rtrim($homeDir, '/') . '/' . ltrim(substr($manualPhotoPathInput, 1), '/');
            }
        }
        $resolvedManualPath = realpath($resolvedManualPath) ?: $manualPhotoPathInput;
        if (!is_file($resolvedManualPath) || !is_readable($resolvedManualPath)) {
            $errors[] = 'No se encontró la imagen en la ruta indicada o no se puede leer.';
        } else {
            $detectedMime = null;
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo !== false) {
                    $detectedMime = finfo_file($finfo, $resolvedManualPath) ?: null;
                    finfo_close($finfo);
                }
            }
            if ($detectedMime === null && function_exists('mime_content_type')) {
                $detectedMime = @mime_content_type($resolvedManualPath) ?: null;
            }
            if ($detectedMime === null) {
                $imageInfo = @getimagesize($resolvedManualPath);
                if (is_array($imageInfo) && isset($imageInfo['mime'])) {
                    $detectedMime = $imageInfo['mime'];
                }
            }

            if ($detectedMime === null || !isset($allowedPhotoTypes[$detectedMime])) {
                $errors[] = 'La imagen indicada no es un archivo JPG, PNG o WEBP válido.';
            } else {
                $manualExtension = $allowedPhotoTypes[$detectedMime];
                $uploadDir = __DIR__ . '/assets/patient_photos';
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                    $errors[] = 'No se pudo preparar la carpeta para almacenar la foto del paciente.';
                } else {
                    try {
                        $uniqueSegment = bin2hex(random_bytes(8));
                    } catch (Exception $exception) {
                        $uniqueSegment = sha1(uniqid('', true));
                    }
                    $fileName = date('YmdHis') . '_' . $uniqueSegment . '.' . $manualExtension;
                    $targetPath = $uploadDir . '/' . $fileName;

                    if (!copy($resolvedManualPath, $targetPath)) {
                        $lastError = error_get_last();
                        $errors[] = 'No se pudo copiar la imagen desde la ruta indicada.' . ($lastError ? ' Detalle: ' . $lastError['message'] : '');
                    } else {
                        @chmod($targetPath, 0664);
                        $newRelativePath = 'assets/patient_photos/' . $fileName;
                        if ($previousPhotoPath && $previousPhotoPath !== $newRelativePath) {
                            $pathsToDelete[] = $previousPhotoPath;
                        }
                        $profilePhotoPath = $newRelativePath;
                        $manualPhotoPathValue = '';
                        $removePhotoRequested = false;
                    }
                }
            }
        }
    }

    if ($profilePhotoPath === null) {
        $profilePhotoZoom = $defaultPhotoZoom;
        $profilePhotoOffsetX = $defaultPhotoOffset;
        $profilePhotoOffsetY = $defaultPhotoOffset;
    }

    $payload['profile_photo_path'] = $profilePhotoPath;
    $payload['profile_photo_zoom'] = $profilePhotoZoom;
    $payload['profile_photo_offset_x'] = $profilePhotoOffsetX;
    $payload['profile_photo_offset_y'] = $profilePhotoOffsetY;

    $profilePhotoTransform = sprintf(
        'transform: translate(%0.2f%%, %0.2f%%) scale(%0.3f);',
        $profilePhotoOffsetX,
        $profilePhotoOffsetY,
        $profilePhotoZoom
    );
    $profilePhotoZoomValue = number_format($profilePhotoZoom, 2, '.', '');
    $profilePhotoOffsetXValue = number_format($profilePhotoOffsetX, 2, '.', '');
    $profilePhotoOffsetYValue = number_format($profilePhotoOffsetY, 2, '.', '');
    $profilePhotoZoomPercent = (int) round($profilePhotoZoom * 100);
    $profilePhotoOffsetXDisplay = number_format($profilePhotoOffsetX, 1, '.', '');
    $profilePhotoOffsetYDisplay = number_format($profilePhotoOffsetY, 1, '.', '');

    if (empty($errors)) {
        $redirectTarget = '';
        if ($patientId) {
            updateById($pdo, 'patients', $payload, $patientId);
            $targetId = $patientId;
            $redirectTarget = 'patient.php?id=' . $targetId;
        } else {
            $payload['registered_at'] = date('Y-m-d H:i:s');
            $targetId = insertRow($pdo, 'patients', $payload);
            $redirectTarget = 'patient_history.php?id=' . $targetId;
        }

        // Garantiza que exista el registro de perfil clínico.
        $pdo->prepare(
            'INSERT OR IGNORE INTO clinical_profiles (patient_id) VALUES (:patient_id)'
        )->execute([':patient_id' => $targetId]);

        if ($pathsToDelete) {
            $uniquePaths = array_unique(array_filter($pathsToDelete));
            foreach ($uniquePaths as $relativePath) {
                $absolutePath = __DIR__ . '/' . ltrim($relativePath, '/');
                if (is_file($absolutePath)) {
                    @unlink($absolutePath);
                }
            }
        }

        header('Location: ' . $redirectTarget);
        exit;
    } else {
        $patient = array_merge($patient ?? [], $payload);
        $profilePhotoPath = $patient['profile_photo_path'] ?? null;
        $profilePhotoZoom = clamp_photo_value((float) ($patient['profile_photo_zoom'] ?? $defaultPhotoZoom), 1.0, 2.5);
        $profilePhotoOffsetX = clamp_photo_value((float) ($patient['profile_photo_offset_x'] ?? $defaultPhotoOffset), -60.0, 60.0);
        $profilePhotoOffsetY = clamp_photo_value((float) ($patient['profile_photo_offset_y'] ?? $defaultPhotoOffset), -60.0, 60.0);
        $profilePhotoTransform = sprintf(
            'transform: translate(%0.2f%%, %0.2f%%) scale(%0.3f);',
            $profilePhotoOffsetX,
            $profilePhotoOffsetY,
            $profilePhotoZoom
        );
        $profilePhotoZoomValue = number_format($profilePhotoZoom, 2, '.', '');
        $profilePhotoOffsetXValue = number_format($profilePhotoOffsetX, 2, '.', '');
        $profilePhotoOffsetYValue = number_format($profilePhotoOffsetY, 2, '.', '');
        $profilePhotoZoomPercent = (int) round($profilePhotoZoom * 100);
        $profilePhotoOffsetXDisplay = number_format($profilePhotoOffsetX, 1, '.', '');
        $profilePhotoOffsetYDisplay = number_format($profilePhotoOffsetY, 1, '.', '');
    }
} else {
    $manualPhotoPathValue = '';
}

$hasProfilePhoto = !empty($profilePhotoPath);

$pageTitle = $patientId ? 'Editar paciente' : 'Nuevo paciente';
require __DIR__ . '/templates/header.php';
?>

<section class="rounded-3xl bg-white/95 p-6 shadow-sm shadow-slate-200/60 ring-1 ring-slate-200/70 sm:p-8 space-y-8">
    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-slate-900"><?= $patientId ? 'Actualizar datos del paciente' : 'Registrar nuevo paciente' ?></h2>
            <p class="text-sm text-slate-500">Completa la información clave para mantener la historia clínica al día.</p>
        </div>
        <a class="inline-flex items-center justify-center gap-2 rounded-full border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 transition hover:border-brand-200 hover:text-brand-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500" href="index.php">
            Regresar al listado
        </a>
    </div>

    <?php if ($errors): ?>
        <div class="rounded-xl border border-rose-200 bg-rose-50/90 px-5 py-4 text-sm text-rose-900 shadow-sm">
            <ul class="list-disc space-y-1 pl-5">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="space-y-6" data-capitalize-initial-form>
        <?php
        $genders = ['Femenino', 'Masculino', 'No binario', 'Prefiere no indicarlo'];
        $maritalStatuses = ['Soltero(a)', 'Casado(a)', 'Unión estable', 'Divorciado(a)', 'Viudo(a)', 'Prefiere no indicarlo'];
        ?>
        <fieldset class="rounded-2xl border border-slate-200/80 bg-slate-50/60 p-4 sm:p-6">
            <legend class="px-3 text-xs font-semibold uppercase tracking-wide text-brand-700">Identificación</legend>
            <div class="mt-4 grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                <div class="md:col-span-2 lg:col-span-4 flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white/80 p-4 shadow-inner sm:flex-row sm:items-center" data-profile-photo-field>
                    <div class="flex items-center justify-center">
                        <div class="relative h-24 w-24 overflow-hidden rounded-full bg-gradient-to-br from-slate-100 to-slate-200 shadow-inner ring-2 <?= $hasProfilePhoto ? 'ring-brand-100/80' : 'ring-slate-200/90 is-empty' ?>" data-profile-photo-frame>
                            <img
                                src="<?= htmlspecialchars($profilePhotoPath ?? '') ?>"
                                alt="<?= htmlspecialchars('Foto del paciente ' . ($patient['full_name'] ?? '')) ?>"
                                class="absolute inset-0 h-full w-full object-cover <?= $hasProfilePhoto ? '' : 'hidden' ?>"
                                style="<?= htmlspecialchars($profilePhotoTransform) ?>"
                                data-profile-photo-preview
                                data-initial-src="<?= htmlspecialchars($profilePhotoPath ?? '') ?>"
                                draggable="false"
                            >
                            <div class="absolute inset-0 flex items-center justify-center text-3xl text-slate-500 <?= $hasProfilePhoto ? 'hidden' : '' ?>" data-profile-photo-placeholder>
                                <span>👤</span>
                            </div>
                        </div>
                    </div>
                    <div class="flex-1 text-sm text-slate-600 space-y-4">
                        <input type="hidden" name="remove_photo" value="<?= $removePhotoRequested ? '1' : '0' ?>" data-profile-photo-remove-flag>
                        <label class="flex flex-col gap-2">
                            <span class="font-medium text-slate-700">Foto de perfil</span>
                            <input type="file" name="profile_photo" accept="image/*" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-sm text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400" data-profile-photo-input>
                            <span class="text-xs text-slate-500">Opcional. Formatos aceptados: JPG, PNG o WEBP (máx. 5&nbsp;MB). Se mostrará una vista previa al seleccionar el archivo.</span>
                        </label>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" class="inline-flex items-center justify-center gap-1 rounded-full border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:border-rose-200 hover:text-rose-600 <?= $hasProfilePhoto ? '' : 'hidden' ?>" data-profile-photo-remove>
                                <span aria-hidden="true">✖️</span>
                                Quitar foto
                            </button>
                        </div>
                        <div class="rounded-2xl border border-slate-200/80 bg-slate-50/70 p-3 space-y-3" data-profile-photo-adjustments>
                            <div class="space-y-1">
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Ajustar encuadre</p>
                                <p class="text-[11px] text-slate-500">Arrastra la imagen o usa los controles para centrar el rostro.</p>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-3">
                                <label class="flex flex-col gap-1 text-xs font-medium text-slate-600 sm:col-span-3">
                                    <span class="font-semibold text-slate-700">Zoom</span>
                                    <input type="range" name="profile_photo_zoom" min="1" max="2.5" step="0.01" value="<?= htmlspecialchars($profilePhotoZoomValue) ?>" class="h-2 w-full cursor-pointer accent-brand-500" data-profile-photo-zoom data-default-value="<?= $defaultPhotoZoom ?>">
                                    <span class="text-[11px] text-slate-500">Ampliación: <span data-profile-photo-zoom-display><?= htmlspecialchars((string) $profilePhotoZoomPercent) ?></span>%</span>
                                </label>
                                <label class="flex flex-col gap-1 text-xs font-medium text-slate-600">
                                    <span class="font-semibold text-slate-700">Horizontal</span>
                                    <input type="range" name="profile_photo_offset_x" min="-60" max="60" step="0.5" value="<?= htmlspecialchars($profilePhotoOffsetXValue) ?>" class="h-2 w-full cursor-pointer accent-brand-500" data-profile-photo-offset-x data-default-value="<?= $defaultPhotoOffset ?>">
                                    <span class="text-[11px] text-slate-500">Desplazamiento: <span data-profile-photo-offset-x-display><?= htmlspecialchars($profilePhotoOffsetXDisplay) ?></span>%</span>
                                </label>
                                <label class="flex flex-col gap-1 text-xs font-medium text-slate-600">
                                    <span class="font-semibold text-slate-700">Vertical</span>
                                    <input type="range" name="profile_photo_offset_y" min="-60" max="60" step="0.5" value="<?= htmlspecialchars($profilePhotoOffsetYValue) ?>" class="h-2 w-full cursor-pointer accent-brand-500" data-profile-photo-offset-y data-default-value="<?= $defaultPhotoOffset ?>">
                                    <span class="text-[11px] text-slate-500">Desplazamiento: <span data-profile-photo-offset-y-display><?= htmlspecialchars($profilePhotoOffsetYDisplay) ?></span>%</span>
                                </label>
                            </div>
                        </div>
                        <label class="flex flex-col gap-2">
                            <span class="font-medium text-slate-700">Copiar imagen desde una ruta del sistema</span>
                            <input type="text" name="profile_photo_copy_path" value="<?= htmlspecialchars($manualPhotoPathValue) ?>" placeholder="/ruta/al/archivo.png" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-sm text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400" data-profile-photo-manual>
                            <span class="text-xs text-slate-500">Opcional. Ingresa la ruta completa de un archivo existente en este equipo (se copiará a la carpeta de pacientes).</span>
                        </label>
                    </div>
                </div>
                <label class="flex flex-col gap-2 text-sm text-slate-600 md:col-span-2 lg:col-span-4">
                    <span class="font-medium text-slate-700">Nombre y apellidos *</span>
                    <input type="text" name="full_name" required value="<?= htmlspecialchars($patient['full_name'] ?? '') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Nombre preferido</span>
                    <input type="text" name="preferred_name" value="<?= htmlspecialchars($patient['preferred_name'] ?? '') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400" placeholder="Apodo o forma de tratamiento">
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Cédula / Documento</span>
                    <input type="text" name="document_id" value="<?= htmlspecialchars($patient['document_id'] ?? '') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Fecha de nacimiento</span>
                    <input type="date" name="birth_date" value="<?= htmlspecialchars($patient['birth_date'] ?? '') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Edad</span>
                    <input type="number" name="age_display" value="<?= htmlspecialchars((string) ($patient['age'] ?? '')) ?>" readonly class="rounded-2xl border border-slate-200 bg-slate-100 px-4 py-2.5 text-slate-700 shadow-inner">
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Género</span>
                    <select name="gender" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 focus:border-brand-400 focus:ring-brand-400">
                        <option value="">Selecciona</option>
                        <?php foreach ($genders as $genderOption): ?>
                            <?php $selected = ($patient['gender'] ?? '') === $genderOption ? 'selected' : ''; ?>
                            <option value="<?= htmlspecialchars($genderOption) ?>" <?= $selected ?>>
                                <?= htmlspecialchars($genderOption) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Estado civil</span>
                    <select name="marital_status" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 focus:border-brand-400 focus:ring-brand-400">
                        <option value="">Selecciona</option>
                        <?php foreach ($maritalStatuses as $statusOption): ?>
                            <?php $selected = ($patient['marital_status'] ?? '') === $statusOption ? 'selected' : ''; ?>
                            <option value="<?= htmlspecialchars($statusOption) ?>" <?= $selected ?>>
                                <?= htmlspecialchars($statusOption) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600 md:col-span-2 lg:col-span-2">
                    <span class="font-medium text-slate-700">Ocupación</span>
                    <input type="text" name="occupation" value="<?= htmlspecialchars($patient['occupation'] ?? '') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400" placeholder="Profesión u oficio">
                </label>
            </div>
        </fieldset>

        <fieldset class="rounded-2xl border border-slate-200/80 bg-white p-4 sm:p-6">
            <legend class="px-3 text-xs font-semibold uppercase tracking-wide text-brand-700">Información administrativa</legend>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Referido por</span>
                    <input type="text" name="referred_by" value="<?= htmlspecialchars($patient['referred_by'] ?? '') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400" placeholder="Persona o institución que refirió">
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Médico tratante</span>
                    <input type="text" name="primary_physician" value="<?= htmlspecialchars($patient['primary_physician'] ?? '') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Teléfono del médico tratante</span>
                    <input type="tel" name="primary_physician_phone" value="<?= htmlspecialchars($patient['primary_physician_phone'] ?? '') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
                <div class="grid gap-4 md:grid-cols-2 md:col-span-2">
                    <label class="flex flex-col gap-2 text-sm text-slate-600">
                        <span class="font-medium text-slate-700">Aseguradora</span>
                        <input type="text" name="insurance_provider" value="<?= htmlspecialchars($patient['insurance_provider'] ?? '') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400" placeholder="Compañía de seguros">
                    </label>
                    <label class="flex flex-col gap-2 text-sm text-slate-600">
                        <span class="font-medium text-slate-700">Número de póliza</span>
                        <input type="text" name="insurance_policy_number" value="<?= htmlspecialchars($patient['insurance_policy_number'] ?? '') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                    </label>
                </div>
            </div>
        </fieldset>

        <fieldset class="rounded-2xl border border-slate-200/80 bg-slate-50/60 p-4 sm:p-6">
            <legend class="px-3 text-xs font-semibold uppercase tracking-wide text-brand-700">Contacto</legend>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <label class="flex flex-col gap-2 text-sm text-slate-600 md:col-span-2">
                    <span class="font-medium text-slate-700">Dirección</span>
                    <textarea name="address" rows="2" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400" placeholder="Calle, edificio, ciudad"><?= htmlspecialchars($patient['address'] ?? '') ?></textarea>
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Correo electrónico</span>
                    <input type="email" name="email" value="<?= htmlspecialchars($patient['email'] ?? '') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Teléfono principal</span>
                    <input type="tel" name="phone_primary" value="<?= htmlspecialchars($patient['phone_primary'] ?? '') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Teléfono alterno</span>
                    <input type="tel" name="phone_secondary" value="<?= htmlspecialchars($patient['phone_secondary'] ?? '') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
            </div>
        </fieldset>

        <fieldset class="rounded-2xl border border-slate-200/80 bg-white p-4 sm:p-6">
            <legend class="px-3 text-xs font-semibold uppercase tracking-wide text-brand-700">Contacto de emergencia</legend>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Nombre del contacto</span>
                    <input type="text" name="emergency_contact" value="<?= htmlspecialchars($patient['emergency_contact'] ?? '') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Parentesco</span>
                    <input type="text" name="emergency_contact_relationship" value="<?= htmlspecialchars($patient['emergency_contact_relationship'] ?? '') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600 md:col-span-2 md:max-w-sm">
                    <span class="font-medium text-slate-700">Teléfono de emergencia</span>
                    <input type="tel" name="emergency_contact_phone" value="<?= htmlspecialchars($patient['emergency_contact_phone'] ?? '') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
            </div>
        </fieldset>

        <fieldset class="rounded-2xl border border-slate-200/80 bg-slate-50/60 p-4 sm:p-6">
            <legend class="px-3 text-xs font-semibold uppercase tracking-wide text-brand-700">Responsable legal (si aplica)</legend>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <label class="flex flex-col gap-2 text-sm text-slate-600 md:col-span-3">
                    <span class="font-medium text-slate-700">Nombre completo</span>
                    <input type="text" name="representative_name" value="<?= htmlspecialchars($patient['representative_name'] ?? '') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Documento de identidad</span>
                    <input type="text" name="representative_document" value="<?= htmlspecialchars($patient['representative_document'] ?? '') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600 md:col-span-2">
                    <span class="font-medium text-slate-700">Teléfono</span>
                    <input type="tel" name="representative_phone" value="<?= htmlspecialchars($patient['representative_phone'] ?? '') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
            </div>
        </fieldset>

        <fieldset class="rounded-2xl border border-slate-200/80 bg-white p-4 sm:p-6">
            <legend class="px-3 text-xs font-semibold uppercase tracking-wide text-brand-700">Anotaciones</legend>
            <label class="mt-4 flex flex-col gap-2 text-sm text-slate-600">
                <span class="font-medium text-slate-700">Notas relevantes / alertas clínicas</span>
                <textarea name="notes" rows="3" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400" placeholder="Observaciones administradas al equipo clínico"><?= htmlspecialchars($patient['notes'] ?? '') ?></textarea>
            </label>
        </fieldset>

        <div class="flex justify-end sm:hidden">
            <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:-translate-y-0.5 hover:bg-brand-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500">
                <?= $patientId ? 'Guardar cambios' : 'Crear paciente' ?>
            </button>
        </div>
        <div class="sticky bottom-4 z-20 flex flex-col gap-3 rounded-2xl border border-brand-100 bg-white/95 p-4 shadow-lg shadow-brand-100/70 backdrop-blur-sm sm:flex-row sm:items-center sm:justify-between">
            <div class="flex flex-col gap-1 text-xs text-slate-600">
                <span class="font-semibold uppercase tracking-wide text-slate-700">Datos del paciente</span>
                <span>Revisa que la información sea correcta y guarda para continuar.</span>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <a class="inline-flex items-center justify-center gap-2 rounded-full border border-slate-200 px-5 py-2.5 text-sm font-semibold text-slate-600 transition hover:border-rose-200 hover:text-rose-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-500" href="<?= $patientId ? 'patient.php?id=' . $patientId : 'index.php' ?>">
                    Cancelar
                </a>
                <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:-translate-y-0.5 hover:bg-brand-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500">
                    <?= $patientId ? 'Guardar cambios' : 'Crear paciente' ?>
                </button>
            </div>
        </div>
    </form>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var capitalizeInitialValue = function (value) {
        if (typeof value !== 'string') {
            return '';
        }
        if (value === '') {
            return '';
        }
        var leadingMatch = value.match(/^\s*/);
        var leadingWhitespace = leadingMatch ? leadingMatch[0] : '';
        var withoutLeading = value.slice(leadingWhitespace.length);
        if (withoutLeading === '') {
            return leadingWhitespace;
        }
        var firstChar = withoutLeading.charAt(0).toLocaleUpperCase('es-ES');
        return leadingWhitespace + firstChar + withoutLeading.slice(1);
    };

    document.querySelectorAll('[data-capitalize-initial-form]').forEach(function (form) {
        var fields = form.querySelectorAll('input[type="text"], input[type="tel"], textarea');
        fields.forEach(function (field) {
            var applyCapitalization = function () {
                var start = field.selectionStart;
                var end = field.selectionEnd;
                var formatted = capitalizeInitialValue(field.value);
                if (field.value !== formatted) {
                    field.value = formatted;
                    if (typeof start === 'number' && typeof end === 'number') {
                        field.selectionStart = start;
                        field.selectionEnd = end;
                    }
                }
            };

            field.addEventListener('blur', applyCapitalization);
            field.addEventListener('change', applyCapitalization);
            field.addEventListener('input', function () {
                var trimmed = field.value.trimStart();
                if (trimmed.length === 0) {
                    return;
                }
                var firstChar = trimmed.charAt(0);
                if (firstChar !== firstChar.toLocaleUpperCase('es-ES')) {
                    applyCapitalization();
                }
            });

            applyCapitalization();
        });

        var profileField = form.querySelector('[data-profile-photo-field]');
        if (profileField) {
            var fileInput = profileField.querySelector('[data-profile-photo-input]');
            var preview = profileField.querySelector('[data-profile-photo-preview]');
            var placeholder = profileField.querySelector('[data-profile-photo-placeholder]');
            var removeFlagInput = profileField.querySelector('[data-profile-photo-remove-flag]');
            var removeButton = profileField.querySelector('[data-profile-photo-remove]');
            var manualInput = profileField.querySelector('[data-profile-photo-manual]');
            var frame = profileField.querySelector('[data-profile-photo-frame]');
            var zoomInput = profileField.querySelector('[data-profile-photo-zoom]');
            var offsetXInput = profileField.querySelector('[data-profile-photo-offset-x]');
            var offsetYInput = profileField.querySelector('[data-profile-photo-offset-y]');
            var zoomDisplay = profileField.querySelector('[data-profile-photo-zoom-display]');
            var offsetXDisplay = profileField.querySelector('[data-profile-photo-offset-x-display]');
            var offsetYDisplay = profileField.querySelector('[data-profile-photo-offset-y-display]');
            var adjustmentsBlock = profileField.querySelector('[data-profile-photo-adjustments]');
            var initialSrc = preview && preview.dataset.initialSrc ? preview.dataset.initialSrc : '';
            var currentObjectUrl = null;
            var dragPointerId = null;
            var isDragging = false;
            var dragStartX = 0;
            var dragStartY = 0;
            var dragStartOffsetX = 0;
            var dragStartOffsetY = 0;
            var defaults = {
                zoom: zoomInput ? parseFloat(zoomInput.dataset.defaultValue || '1') || 1 : 1,
                offsetX: offsetXInput ? parseFloat(offsetXInput.dataset.defaultValue || '0') || 0 : 0,
                offsetY: offsetYInput ? parseFloat(offsetYInput.dataset.defaultValue || '0') || 0 : 0
            };

            var clamp = function (value, min, max) {
                if (typeof value !== 'number' || !isFinite(value)) {
                    return min;
                }
                return Math.min(Math.max(value, min), max);
            };

            var resetObjectUrl = function () {
                if (currentObjectUrl) {
                    URL.revokeObjectURL(currentObjectUrl);
                    currentObjectUrl = null;
                }
            };

            var updateDisplays = function (zoom, offsetX, offsetY) {
                if (zoomDisplay) {
                    zoomDisplay.textContent = Math.round(zoom * 100);
                }
                if (offsetXDisplay) {
                    offsetXDisplay.textContent = offsetX.toFixed(1);
                }
                if (offsetYDisplay) {
                    offsetYDisplay.textContent = offsetY.toFixed(1);
                }
            };

            var applyTransform = function () {
                if (!preview) {
                    return;
                }
                var zoom = zoomInput ? parseFloat(zoomInput.value) : defaults.zoom;
                var offsetX = offsetXInput ? parseFloat(offsetXInput.value) : defaults.offsetX;
                var offsetY = offsetYInput ? parseFloat(offsetYInput.value) : defaults.offsetY;
                zoom = clamp(zoom, 1, 2.5);
                offsetX = clamp(offsetX, -60, 60);
                offsetY = clamp(offsetY, -60, 60);
                if (zoomInput) {
                    zoomInput.value = zoom.toFixed(2);
                }
                if (offsetXInput) {
                    offsetXInput.value = offsetX.toFixed(2);
                }
                if (offsetYInput) {
                    offsetYInput.value = offsetY.toFixed(2);
                }
                preview.style.transform = 'translate(' + offsetX + '%, ' + offsetY + '%) scale(' + zoom + ')';
                updateDisplays(zoom, offsetX, offsetY);
            };

            var setControlsDisabled = function (disabled) {
                [zoomInput, offsetXInput, offsetYInput].forEach(function (input) {
                    if (input) {
                        input.disabled = disabled;
                    }
                });
                if (adjustmentsBlock) {
                    adjustmentsBlock.classList.toggle('opacity-60', disabled);
                    adjustmentsBlock.classList.toggle('pointer-events-none', disabled);
                }
                if (frame) {
                    frame.classList.toggle('is-empty', disabled);
                    if (disabled) {
                        frame.classList.remove('is-dragging');
                        if (dragPointerId !== null) {
                            try {
                                frame.releasePointerCapture(dragPointerId);
                            } catch (error) {
                                /* ignore */
                            }
                        }
                    }
                }
                if (disabled) {
                    isDragging = false;
                    dragPointerId = null;
                }
            };

            var resetToDefaults = function () {
                if (zoomInput) {
                    zoomInput.value = defaults.zoom.toFixed(2);
                }
                if (offsetXInput) {
                    offsetXInput.value = defaults.offsetX.toFixed(2);
                }
                if (offsetYInput) {
                    offsetYInput.value = defaults.offsetY.toFixed(2);
                }
                applyTransform();
            };

            var showPreview = function (src) {
                if (!preview) {
                    return;
                }
                preview.src = src;
                preview.classList.remove('hidden');
                if (placeholder) {
                    placeholder.classList.add('hidden');
                }
                if (removeButton) {
                    removeButton.classList.remove('hidden');
                }
                setControlsDisabled(false);
                applyTransform();
                if (removeFlagInput) {
                    removeFlagInput.value = '0';
                }
            };

            var showPlaceholder = function (markRemovalFlag) {
                if (preview) {
                    preview.classList.add('hidden');
                }
                if (placeholder) {
                    placeholder.classList.remove('hidden');
                }
                if (removeButton) {
                    removeButton.classList.add('hidden');
                }
                resetToDefaults();
                setControlsDisabled(true);
                if (markRemovalFlag && removeFlagInput) {
                    removeFlagInput.value = '1';
                } else if (!markRemovalFlag && removeFlagInput) {
                    removeFlagInput.value = '0';
                }
            };

            var hasInitialPhoto = preview && !preview.classList.contains('hidden') && preview.getAttribute('src');
            if (hasInitialPhoto) {
                setControlsDisabled(false);
                applyTransform();
            } else {
                setControlsDisabled(true);
                updateDisplays(defaults.zoom, defaults.offsetX, defaults.offsetY);
            }

            var beginDrag = function (event) {
                if (!frame || !preview || preview.classList.contains('hidden')) {
                    return;
                }
                isDragging = true;
                dragPointerId = event.pointerId;
                dragStartX = event.clientX;
                dragStartY = event.clientY;
                dragStartOffsetX = offsetXInput ? parseFloat(offsetXInput.value) || defaults.offsetX : defaults.offsetX;
                dragStartOffsetY = offsetYInput ? parseFloat(offsetYInput.value) || defaults.offsetY : defaults.offsetY;
                frame.classList.add('is-dragging');
                frame.setPointerCapture(dragPointerId);
                event.preventDefault();
            };

            var continueDrag = function (event) {
                if (!isDragging || !frame) {
                    return;
                }
                var rect = frame.getBoundingClientRect();
                if (!rect.width || !rect.height) {
                    return;
                }
                var deltaX = ((event.clientX - dragStartX) / rect.width) * 100;
                var deltaY = ((event.clientY - dragStartY) / rect.height) * 100;
                var newOffsetX = clamp(dragStartOffsetX + deltaX, -60, 60);
                var newOffsetY = clamp(dragStartOffsetY + deltaY, -60, 60);
                if (offsetXInput) {
                    offsetXInput.value = newOffsetX.toFixed(2);
                }
                if (offsetYInput) {
                    offsetYInput.value = newOffsetY.toFixed(2);
                }
                if (removeFlagInput) {
                    removeFlagInput.value = '0';
                }
                applyTransform();
            };

            var endDrag = function () {
                if (!isDragging || !frame) {
                    return;
                }
                isDragging = false;
                if (dragPointerId !== null) {
                    try {
                        frame.releasePointerCapture(dragPointerId);
                    } catch (error) {
                        /* ignore */
                    }
                }
                dragPointerId = null;
                frame.classList.remove('is-dragging');
            };

            if (frame) {
                frame.addEventListener('pointerdown', beginDrag);
                frame.addEventListener('pointermove', continueDrag);
                frame.addEventListener('pointerup', endDrag);
                frame.addEventListener('pointercancel', endDrag);
            }

            if (zoomInput) {
                zoomInput.addEventListener('input', function () {
                    if (removeFlagInput && preview && !preview.classList.contains('hidden')) {
                        removeFlagInput.value = '0';
                    }
                    applyTransform();
                });
            }

            if (offsetXInput) {
                offsetXInput.addEventListener('input', function () {
                    if (removeFlagInput && preview && !preview.classList.contains('hidden')) {
                        removeFlagInput.value = '0';
                    }
                    applyTransform();
                });
            }

            if (offsetYInput) {
                offsetYInput.addEventListener('input', function () {
                    if (removeFlagInput && preview && !preview.classList.contains('hidden')) {
                        removeFlagInput.value = '0';
                    }
                    applyTransform();
                });
            }

            if (fileInput) {
                fileInput.addEventListener('change', function () {
                    resetObjectUrl();
                    var file = fileInput.files && fileInput.files[0];
                    if (file) {
                        currentObjectUrl = URL.createObjectURL(file);
                        if (manualInput) {
                            manualInput.value = '';
                        }
                        resetToDefaults();
                        showPreview(currentObjectUrl);
                    } else if (initialSrc) {
                        showPreview(initialSrc);
                    } else {
                        showPlaceholder(false);
                    }
                });
            }

            if (removeButton) {
                removeButton.addEventListener('click', function () {
                    resetObjectUrl();
                    if (fileInput) {
                        fileInput.value = '';
                    }
                    if (manualInput) {
                        manualInput.value = '';
                    }
                    initialSrc = '';
                    showPlaceholder(true);
                });
            }

            if (manualInput) {
                manualInput.addEventListener('input', function () {
                    if (manualInput.value.trim() !== '' && removeFlagInput) {
                        removeFlagInput.value = '0';
                    }
                });
            }

            form.addEventListener('submit', function () {
                resetObjectUrl();
            });
        }
    });
});
</script>

<?php require __DIR__ . '/templates/footer.php'; ?>
