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
    $pathsToDelete = [];
    $removePhotoRequested = (string) post('remove_photo', '0') === '1';
    if ($removePhotoRequested && $profilePhotoPath) {
        $pathsToDelete[] = $profilePhotoPath;
        $profilePhotoPath = null;
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
                    }
                }
            }
        }
    }

    $payload['profile_photo_path'] = $profilePhotoPath;

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
    }
} else {
    $manualPhotoPathValue = '';
}

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
                <?php $hasProfilePhoto = !empty($profilePhotoPath); ?>
                <div class="md:col-span-2 lg:col-span-4 flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white/80 p-4 shadow-inner sm:flex-row sm:items-center" data-profile-photo-field>
                    <div class="flex items-center justify-center">
                        <div class="relative">
                            <img
                                src="<?= htmlspecialchars($profilePhotoPath ?? '') ?>"
                                alt="<?= htmlspecialchars('Foto del paciente ' . ($patient['full_name'] ?? '')) ?>"
                                class="h-24 w-24 rounded-full object-cover shadow-md ring-2 ring-brand-100/80 <?= $hasProfilePhoto ? '' : 'hidden' ?>"
                                data-profile-photo-preview
                                data-initial-src="<?= htmlspecialchars($profilePhotoPath ?? '') ?>"
                            >
                            <div class="flex h-24 w-24 items-center justify-center rounded-full bg-gradient-to-br from-slate-100 to-slate-200 text-3xl text-slate-500 shadow-inner <?= $hasProfilePhoto ? 'hidden' : '' ?>" data-profile-photo-placeholder>
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
            var initialSrc = preview && preview.dataset.initialSrc ? preview.dataset.initialSrc : '';
            var currentObjectUrl = null;

            var resetObjectUrl = function () {
                if (currentObjectUrl) {
                    URL.revokeObjectURL(currentObjectUrl);
                    currentObjectUrl = null;
                }
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
                if (markRemovalFlag && removeFlagInput) {
                    removeFlagInput.value = '1';
                }
            };

            if (fileInput) {
                fileInput.addEventListener('change', function () {
                    resetObjectUrl();
                    var file = fileInput.files && fileInput.files[0];
                    if (file) {
                        currentObjectUrl = URL.createObjectURL(file);
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
                    var hasNewFile = fileInput && fileInput.files && fileInput.files.length > 0;
                    if (hasNewFile && initialSrc) {
                        fileInput.value = '';
                        showPreview(initialSrc);
                        return;
                    }

                    var manualHasValue = manualInput && manualInput.value.trim() !== '';

                    if (manualHasValue && initialSrc) {
                        manualInput.value = '';
                        showPreview(initialSrc);
                        return;
                    }

                    if (fileInput) {
                        fileInput.value = '';
                    }
                    if (manualInput) {
                        manualInput.value = '';
                    }

                    if (initialSrc) {
                        initialSrc = '';
                    }
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
