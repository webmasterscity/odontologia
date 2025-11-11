<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/database.php';

ensureSessionStarted();

$pdo = db();
$patientId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($patientId <= 0) {
    http_response_code(400);
    echo 'Identificador de paciente inválido.';
    exit;
}

$patientStmt = $pdo->prepare('SELECT * FROM patients WHERE id = :id');
$patientStmt->execute([':id' => $patientId]);
$patient = $patientStmt->fetch(PDO::FETCH_ASSOC);
if (!$patient) {
    http_response_code(404);
    echo 'Paciente no encontrado.';
    exit;
}

$messages = [];
$errors = [];
$studyMessages = $_SESSION['patient_studies_messages'] ?? [];
$studyErrors = $_SESSION['patient_studies_errors'] ?? [];
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    unset($_SESSION['patient_studies_messages'], $_SESSION['patient_studies_errors']);
}
$profileData = null;
$profilePhotoPath = trim((string) ($patient['profile_photo_path'] ?? ''));
$hasProfilePhoto = $profilePhotoPath !== '';

$computeInitial = static function (?string $name): string {
    if ($name === null) {
        return '👤';
    }
    $trimmed = trim($name);
    if ($trimmed === '') {
        return '👤';
    }
    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        return mb_strtoupper(mb_substr($trimmed, 0, 1, 'UTF-8'), 'UTF-8');
    }
    return strtoupper(substr($trimmed, 0, 1));
};
$patientInitial = $computeInitial($patient['full_name'] ?? '');
$clampPhotoValue = static function (float $value, float $min, float $max): float {
    return max($min, min($max, $value));
};
$profilePhotoZoom = $clampPhotoValue((float) ($patient['profile_photo_zoom'] ?? 1.0), 1.0, 2.5);
$profilePhotoOffsetX = $clampPhotoValue((float) ($patient['profile_photo_offset_x'] ?? 0.0), -60.0, 60.0);
$profilePhotoOffsetY = $clampPhotoValue((float) ($patient['profile_photo_offset_y'] ?? 0.0), -60.0, 60.0);
$profilePhotoStyle = sprintf(
    'transform: translate(%0.2f%%, %0.2f%%) scale(%0.3f);',
    $profilePhotoOffsetX,
    $profilePhotoOffsetY,
    $profilePhotoZoom
);

if (isset($_GET['saved'])) {
    $messages[] = 'Perfil clínico actualizado.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $profileData = [
        'patient_id' => $patientId,
        'consultation_reason' => trim((string) post('consultation_reason')) ?: null,
        'current_condition' => trim((string) post('current_condition')) ?: null,
        'medical_alerts' => trim((string) post('medical_alerts')) ?: null,
        'medications' => trim((string) post('medications')) ?: null,
        'hospitalizations' => trim((string) post('hospitalizations')) ?: null,
        'family_history' => trim((string) post('family_history')) ?: null,
        'extraoral_exam' => trim((string) post('extraoral_exam')) ?: null,
        'intraoral_exam' => trim((string) post('intraoral_exam')) ?: null,
        'periodontal_status' => trim((string) post('periodontal_status')) ?: null,
        'physical_exam_bp' => trim((string) post('physical_exam_bp')) ?: null,
        'diagnosis' => trim((string) post('diagnosis')) ?: null,
        'treatment_plan' => trim((string) post('treatment_plan')) ?: null,
        'consent_signed' => postCheckbox('consent_signed'),
        'consent_signed_at' => null,
        'consent_notes' => trim((string) post('consent_notes')) ?: null,
        'consent_signature' => trim((string) post('consent_signature')) ?: null,
        'consent_ci' => trim((string) post('consent_ci')) ?: null,
        'antecedent_cardiovascular' => postCheckbox('antecedent_cardiovascular'),
        'antecedent_respiratory' => postCheckbox('antecedent_respiratory'),
        'antecedent_gastrointestinal' => postCheckbox('antecedent_gastrointestinal'),
        'antecedent_endocrine' => postCheckbox('antecedent_endocrine'),
        'antecedent_renal' => postCheckbox('antecedent_renal'),
        'antecedent_ent' => postCheckbox('antecedent_ent'),
        'antecedent_hepatic' => postCheckbox('antecedent_hepatic'),
        'antecedent_neurologic' => postCheckbox('antecedent_neurologic'),
        'antecedent_allergy' => postCheckbox('antecedent_allergy'),
        'antecedent_neoplastic' => postCheckbox('antecedent_neoplastic'),
        'antecedent_hematologic' => postCheckbox('antecedent_hematologic'),
        'antecedent_viral' => postCheckbox('antecedent_viral'),
        'antecedent_gynecologic' => postCheckbox('antecedent_gynecologic'),
        'antecedent_covid' => postCheckbox('antecedent_covid'),
        'pain_level' => post('pain_level') !== '' ? (int) post('pain_level') : null,
        'habits' => trim((string) post('habits')) ?: null,
        'risk_assessment' => trim((string) post('risk_assessment')) ?: null,
    ];

    if ($profileData['consent_signed'] === 1) {
        $profileData['consent_signed_at'] = normalizeDate(post('consent_signed_at')) ?? date('Y-m-d');
    }

    try {
        $columns = array_keys($profileData);
        $placeholders = array_map(fn($c) => ':' . $c, $columns);
        $sets = array_map(fn($c) => "$c = excluded.$c", $columns);
        $sql = 'INSERT INTO clinical_profiles (' . implode(', ', $columns) . ')
                VALUES (' . implode(', ', $placeholders) . ')
                ON CONFLICT(patient_id) DO UPDATE SET
                    ' . implode(', ', $sets) . ',
                    last_updated = CURRENT_TIMESTAMP';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($profileData);

        if ($medicalAlert = $profileData['medical_alerts']) {
            $pdo->prepare('UPDATE patients SET notes = :notes WHERE id = :id')->execute([
                ':notes' => $medicalAlert,
                ':id' => $patientId,
            ]);
        }

        header('Location: patient_history.php?id=' . $patientId . '&saved=1');
        exit;
    } catch (Throwable $exception) {
        $errors[] = 'No se pudo actualizar la historia clínica. Inténtalo nuevamente.';
    }
}

$profileStmt = $pdo->prepare('SELECT * FROM clinical_profiles WHERE patient_id = :id');
$profileStmt->execute([':id' => $patientId]);
$profile = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: [];

if ($profileData && $errors) {
    $profile = array_merge($profile, $profileData);
}

$patientStudies = getPatientStudies($pdo, $patientId);
$studyLimit = getPatientStudyLimit();
$studyCount = count($patientStudies);
$studyLimitReached = $studyCount >= $studyLimit;
$csrfToken = getCsrfToken();
$studyMaxFileSizeBytes = 10 * 1024 * 1024;
$studyMaxFileSizeMb = (int) round($studyMaxFileSizeBytes / 1048576);
$studyAllowedLabel = 'JPG, PNG, WEBP o PDF';
$formatStudyDate = static function (?string $value): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    try {
        return (new DateTime($value))->format('d/m/Y');
    } catch (Throwable $exception) {
        return $value;
    }
};
$formatStudySize = static function (?int $bytes): ?string {
    if ($bytes === null || $bytes <= 0) {
        return null;
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
};

$pageTitle = 'Historia clínica de ' . $patient['full_name'];
require __DIR__ . '/templates/header.php';
?>

<?php if ($messages): ?>
    <div class="rounded-xl border border-emerald-200 bg-emerald-50/80 px-5 py-4 text-sm text-emerald-900 shadow-sm shadow-emerald-100/60">
        <ul class="list-disc space-y-1 pl-5">
            <?php foreach ($messages as $message): ?>
                <li><?= htmlspecialchars($message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="rounded-xl border border-rose-200 bg-rose-50/90 px-5 py-4 text-sm text-rose-900 shadow-sm shadow-rose-200/60">
        <ul class="list-disc space-y-1 pl-5">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($studyMessages): ?>
    <div class="rounded-xl border border-emerald-200 bg-emerald-50/80 px-5 py-4 text-sm text-emerald-900 shadow-sm shadow-emerald-100/60">
        <ul class="list-disc space-y-1 pl-5">
            <?php foreach ($studyMessages as $message): ?>
                <li><?= htmlspecialchars($message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($studyErrors): ?>
    <div class="rounded-xl border border-rose-200 bg-rose-50/90 px-5 py-4 text-sm text-rose-900 shadow-sm shadow-rose-200/60">
        <ul class="list-disc space-y-1 pl-5">
            <?php foreach ($studyErrors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<section class="rounded-3xl bg-white/95 p-6 shadow-sm shadow-slate-200/60 ring-1 ring-slate-200/70 sm:p-8 space-y-6">
    <div class="flex flex-col gap-4 rounded-2xl border border-slate-200/80 bg-gradient-to-br from-white to-slate-50/70 p-4 shadow-inner sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-4">
            <div class="relative h-20 w-20 overflow-hidden rounded-full bg-gradient-to-br from-slate-100 to-slate-200 shadow-inner ring-4 <?= $hasProfilePhoto ? 'ring-brand-100/70' : 'ring-slate-200' ?>">
                <?php if ($hasProfilePhoto): ?>
                    <img
                        src="<?= htmlspecialchars($profilePhotoPath) ?>"
                        alt="<?= htmlspecialchars('Foto del paciente ' . ($patient['full_name'] ?? '')) ?>"
                        class="absolute inset-0 h-full w-full object-cover"
                        style="<?= htmlspecialchars($profilePhotoStyle) ?>"
                        draggable="false"
                        loading="lazy"
                    >
                <?php else: ?>
                    <div class="absolute inset-0 flex items-center justify-center text-2xl font-semibold text-slate-500 select-none">
                        <?= htmlspecialchars($patientInitial) ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="space-y-1 text-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Paciente</p>
                <h2 class="text-xl font-semibold text-slate-900"><?= htmlspecialchars($patient['full_name'] ?? '') ?></h2>
                <?php if (!empty($patient['preferred_name'])): ?>
                    <p class="text-xs text-slate-500">Nombre preferido: <span class="font-semibold text-brand-600"><?= htmlspecialchars($patient['preferred_name']) ?></span></p>
                <?php endif; ?>
                <?php if (!empty($patient['document_id'])): ?>
                    <p class="text-xs text-slate-500">Documento: <span class="font-medium text-slate-700"><?= htmlspecialchars($patient['document_id']) ?></span></p>
                <?php endif; ?>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-3 text-xs">
            <?php if (!empty($patient['phone_primary'])): ?>
                <a href="tel:<?= htmlspecialchars($patient['phone_primary']) ?>" class="inline-flex items-center gap-2 rounded-full border border-slate-200 px-3 py-1.5 font-semibold text-slate-600 transition hover:border-brand-200 hover:text-brand-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500">
                    📞 <?= htmlspecialchars($patient['phone_primary']) ?>
                </a>
            <?php endif; ?>
            <?php if (!empty($patient['email'])): ?>
                <a href="mailto:<?= htmlspecialchars($patient['email']) ?>" class="inline-flex items-center gap-2 rounded-full border border-slate-200 px-3 py-1.5 font-semibold text-slate-600 transition hover:border-brand-200 hover:text-brand-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500">
                    ✉️ <?= htmlspecialchars($patient['email']) ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <form method="post" id="patientProfileForm" class="space-y-6">
        <div class="space-y-1">
            <h2 class="text-2xl font-semibold text-slate-900">Historia clínica</h2>
            <p class="text-sm text-slate-500">Completa la historia, antecedentes y evaluaciones de <?= htmlspecialchars($patient['full_name']) ?>.</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <a class="inline-flex items-center justify-center gap-2 rounded-xl border-2 border-brand-300 bg-white px-5 py-2.5 text-sm font-bold text-brand-700 shadow-sm transition hover:-translate-y-0.5 hover:border-brand-400 hover:bg-brand-50 hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500" href="patient.php?id=<?= $patientId ?>">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
                Ver ficha del paciente
            </a>
            <a class="inline-flex items-center justify-center gap-2 rounded-xl border-2 border-slate-300 bg-white px-5 py-2.5 text-sm font-bold text-slate-700 shadow-sm transition hover:-translate-y-0.5 hover:border-slate-400 hover:bg-slate-50 hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-500" href="index.php">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Volver al listado
            </a>
        </div>
        <fieldset class="rounded-2xl border border-slate-200/80 bg-slate-50/60 p-4 sm:p-6">
            <legend class="px-3 text-xs font-semibold uppercase tracking-wide text-brand-700">Motivo de consulta</legend>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <label class="flex flex-col gap-2 text-sm text-slate-600 md:col-span-2">
                    <span class="font-medium text-slate-700">Motivo principal</span>
                    <textarea name="consultation_reason" rows="2" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"><?= htmlspecialchars($profile['consultation_reason'] ?? '') ?></textarea>
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Enfermedad actual / evolución</span>
                    <textarea name="current_condition" rows="3" class="h-28 rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"><?= htmlspecialchars($profile['current_condition'] ?? '') ?></textarea>
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Alertas clínicas (alergias, riesgos)</span>
                    <textarea name="medical_alerts" rows="3" class="h-28 rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"><?= htmlspecialchars($profile['medical_alerts'] ?? '') ?></textarea>
                </label>
            </div>
        </fieldset>

        <fieldset class="rounded-2xl border border-slate-200/80 bg-white p-4 sm:p-6">
            <legend class="px-3 text-xs font-semibold uppercase tracking-wide text-brand-700">Antecedentes personales</legend>
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <?php
                $antecedents = [
                    'antecedent_ent' => 'Oído, nariz y garganta',
                    'antecedent_respiratory' => 'Respiratorio',
                    'antecedent_allergy' => 'Alergia',
                    'antecedent_cardiovascular' => 'Cardio vascular',
                    'antecedent_gastrointestinal' => 'Gastrointestinal',
                    'antecedent_endocrine' => 'Endocrino',
                    'antecedent_renal' => 'Renal',
                    'antecedent_hepatic' => 'Hepático',
                    'antecedent_neurologic' => 'Neurológico',
                    'antecedent_neoplastic' => 'Neoplásico',
                    'antecedent_hematologic' => 'Sanguíneo',
                    'antecedent_viral' => 'Virales',
                    'antecedent_gynecologic' => 'Ginecológicos',
                    'antecedent_covid' => 'COVID-19',
                ];
                foreach ($antecedents as $field => $label):
                    $checked = !empty($profile[$field]) ? 'checked' : '';
                    ?>
                    <label class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-medium text-slate-600 shadow-sm transition hover:border-brand-200">
                        <input type="checkbox" name="<?= $field ?>" <?= $checked ?> class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        <span><?= htmlspecialchars($label) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Hospitalizaciones / procedimientos</span>
                    <textarea name="hospitalizations" rows="3" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"><?= htmlspecialchars($profile['hospitalizations'] ?? '') ?></textarea>
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Medicamentos actuales</span>
                    <textarea name="medications" rows="3" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"><?= htmlspecialchars($profile['medications'] ?? '') ?></textarea>
                </label>
            </div>
        </fieldset>

        <fieldset class="rounded-2xl border border-slate-200/80 bg-white p-4 sm:p-6">
            <legend class="px-3 text-xs font-semibold uppercase tracking-wide text-brand-700">Antecedentes familiares y hábitos</legend>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Antecedentes familiares</span>
                    <textarea name="family_history" rows="3" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"><?= htmlspecialchars($profile['family_history'] ?? '') ?></textarea>
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Hábitos</span>
                    <textarea name="habits" rows="3" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"><?= htmlspecialchars($profile['habits'] ?? '') ?></textarea>
                </label>
            </div>
        </fieldset>

        <fieldset class="rounded-2xl border border-slate-200/80 bg-slate-50/60 p-4 sm:p-6">
            <legend class="px-3 text-xs font-semibold uppercase tracking-wide text-brand-700">Examen físico odontológico</legend>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Examen extraoral</span>
                    <textarea name="extraoral_exam" rows="3" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"><?= htmlspecialchars($profile['extraoral_exam'] ?? '') ?></textarea>
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Examen intraoral</span>
                    <textarea name="intraoral_exam" rows="3" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"><?= htmlspecialchars($profile['intraoral_exam'] ?? '') ?></textarea>
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Estado periodontal</span>
                    <textarea name="periodontal_status" rows="3" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"><?= htmlspecialchars($profile['periodontal_status'] ?? '') ?></textarea>
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">TA / PA</span>
                    <input type="text" name="physical_exam_bp" value="<?= htmlspecialchars($profile['physical_exam_bp'] ?? '') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
            </div>
        </fieldset>

        <fieldset class="rounded-2xl border border-slate-200/80 bg-white p-4 sm:p-6">
            <legend class="px-3 text-xs font-semibold uppercase tracking-wide text-brand-700">Diagnóstico y plan</legend>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Diagnóstico</span>
                    <textarea name="diagnosis" rows="3" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"><?= htmlspecialchars($profile['diagnosis'] ?? '') ?></textarea>
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Plan de tratamiento</span>
                    <textarea name="treatment_plan" rows="3" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"><?= htmlspecialchars($profile['treatment_plan'] ?? '') ?></textarea>
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Riesgo / pronóstico</span>
                    <textarea name="risk_assessment" rows="3" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"><?= htmlspecialchars($profile['risk_assessment'] ?? '') ?></textarea>
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Nivel de dolor</span>
                    <input type="number" min="0" max="10" name="pain_level" value="<?= htmlspecialchars((string)($profile['pain_level'] ?? '')) ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
            </div>
        </fieldset>

        <fieldset class="rounded-2xl border border-slate-200/80 bg-gradient-to-br from-white to-slate-50/60 p-6 sm:p-8 shadow-sm">
            <legend class="px-4 text-sm font-bold uppercase tracking-wider text-brand-700 bg-white rounded-lg border border-brand-200/50 shadow-sm">Consentimiento Informado</legend>

            <!-- Main consent content: text + signature together -->
            <div class="mt-6 grid gap-5 lg:grid-cols-[1fr,auto]">
                <!-- Left column: Text + additional fields -->
                <div class="space-y-3">
                    <!-- Consent text -->
                    <div class="rounded-2xl bg-gradient-to-br from-slate-50 to-white px-4 pt-3 pb-3 border-2 border-slate-200/80 shadow-sm">
                        <div class="space-y-2.5 text-base text-slate-700 leading-normal">
                            <p class="text-justify mb-0">
                                <span class="font-semibold text-slate-800">Declaro y manifiesto</span> en pleno uso de mis facultades mentales, libre y espontáneamente, lo siguiente: He sido informado(a) y comprendo la necesidad de ser atendido(a) por el odontólogo tratante. He sido informado(a) y comprendo la opción de tratamiento presentado en mi condición particular, explicándome en forma detallada en que consiste y como se llevará a cabo dichos procedimientos.
                            </p>
                            <p class="text-justify mb-0">
                                <span class="font-semibold text-slate-800">Acepto</span> la realización de pruebas diagnósticas necesarias para mi tratamiento, incluyendo la realización de estudios radiográficos, interconsultas médico/odontológicas en general con los fines proyectados para conocer el estado de mi salud.
                            </p>
                            <p class="text-justify mb-0">
                                <span class="font-semibold text-slate-800">Autorizo</span> al odontólogo tratante y su equipo de trabajo, para obtener fotografías videos y/o registro gráfico bajo los principios bioéticos.
                            </p>
                        </div>
                    </div>

                    <!-- Fields below text -->
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="flex items-center gap-3 px-4 py-3 text-sm rounded-xl bg-white border-2 border-slate-200/80 shadow-sm cursor-pointer hover:border-brand-300 hover:bg-brand-50/30 transition-all duration-200">
                            <input type="checkbox" name="consent_signed" value="1" <?= !empty($profile['consent_signed']) ? 'checked' : '' ?> class="h-5 w-5 rounded border-slate-300 text-brand-600 focus:ring-brand-500 cursor-pointer">
                            <span class="font-semibold text-slate-700">Consentimiento firmado</span>
                        </label>

                        <label class="flex flex-col gap-2 px-4 py-3 rounded-xl bg-white border-2 border-slate-200/80 shadow-sm">
                            <span class="text-xs font-bold text-slate-600 uppercase tracking-wide">Fecha de Firma</span>
                            <input type="date" name="consent_signed_at" value="<?= htmlspecialchars($profile['consent_signed_at'] ?? '') ?>" class="rounded-lg border-2 border-slate-300 bg-white px-3 py-2 text-slate-800 font-medium shadow-inner focus:border-brand-400 focus:ring-2 focus:ring-brand-400/20 transition-all duration-200">
                        </label>
                    </div>

                    <div class="rounded-2xl bg-white px-4 py-3 border-2 border-slate-200/80 shadow-sm">
                        <label class="flex flex-col gap-2">
                            <span class="text-xs font-bold text-slate-700 uppercase tracking-wide">Cédula de Identidad</span>
                            <input type="text" name="consent_ci" value="<?= htmlspecialchars($profile['consent_ci'] ?? '') ?>" placeholder="V-12.345.678" class="rounded-xl border-2 border-slate-300 bg-white px-4 py-2.5 text-slate-800 font-medium shadow-inner focus:border-brand-400 focus:ring-2 focus:ring-brand-400/20 transition-all duration-200">
                        </label>
                    </div>
                </div>

                <!-- Right column: Signature -->
                <div class="flex flex-col gap-3 lg:w-96">
                    <div class="rounded-2xl bg-white p-4 border-2 border-brand-200/80 shadow-md">
                        <label class="text-sm font-bold text-brand-700 uppercase tracking-wide mb-2 block">Firma del Paciente</label>

                        <?php if (!empty($profile['consent_signature'])): ?>
                            <!-- Mostrar firma guardada -->
                            <div id="saved-signature-display">
                                <div class="p-3 rounded-xl bg-green-50 border border-green-200">
                                    <span class="text-xs font-semibold text-green-700 uppercase tracking-wide block mb-2">✓ Firma guardada</span>
                                    <img src="<?= htmlspecialchars($profile['consent_signature']) ?>" alt="Firma guardada" class="w-full border border-green-200 rounded-lg bg-white shadow-sm" style="max-height: 340px; object-fit: contain;">
                                </div>
                                <button type="button" id="change-signature-btn" class="mt-3 w-full px-4 py-2.5 text-sm font-bold text-brand-700 bg-white border-2 border-brand-300 rounded-xl hover:bg-brand-50 hover:border-brand-400 shadow-sm transition-all duration-200">
                                    Cambiar firma
                                </button>
                            </div>

                            <!-- Canvas oculto inicialmente -->
                            <div id="signature-canvas-wrapper" class="hidden">
                                <div class="relative">
                                    <canvas id="signature-canvas" class="w-full border-2 border-brand-300 rounded-xl bg-gradient-to-br from-white to-slate-50/30 cursor-crosshair shadow-inner" style="height: 300px; touch-action: none;"></canvas>
                                    <button type="button" id="clear-signature" class="absolute top-2 right-2 px-3 py-1.5 text-xs font-bold text-brand-700 bg-white border-2 border-brand-300 rounded-lg hover:bg-brand-50 hover:border-brand-400 shadow-md transition-all duration-200">
                                        Limpiar
                                    </button>
                                </div>
                                <button type="button" id="cancel-change-btn" class="mt-3 w-full px-4 py-2.5 text-sm font-semibold text-slate-600 bg-white border-2 border-slate-300 rounded-xl hover:bg-slate-50 shadow-sm transition-all duration-200">
                                    Cancelar
                                </button>
                            </div>
                        <?php else: ?>
                            <!-- Mostrar canvas si no hay firma -->
                            <div class="relative">
                                <canvas id="signature-canvas" class="w-full border-2 border-brand-300 rounded-xl bg-gradient-to-br from-white to-slate-50/30 cursor-crosshair shadow-inner" style="height: 400px; touch-action: none;"></canvas>
                                <button type="button" id="clear-signature" class="absolute top-2 right-2 px-3 py-1.5 text-xs font-bold text-brand-700 bg-white border-2 border-brand-300 rounded-lg hover:bg-brand-50 hover:border-brand-400 shadow-md transition-all duration-200">
                                    Limpiar
                                </button>
                            </div>
                        <?php endif; ?>

                        <input type="hidden" name="consent_signature" id="signature-data" value="<?= htmlspecialchars($profile['consent_signature'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <!-- Observaciones -->
            <label class="mt-5 flex flex-col gap-3 px-5 py-4 rounded-xl bg-white border-2 border-slate-200/80 shadow-sm">
                <span class="text-xs font-bold text-slate-600 uppercase tracking-wide">Observaciones / Condiciones Especiales</span>
                <textarea name="consent_notes" rows="3" class="rounded-lg border-2 border-slate-300 bg-white px-4 py-3 text-slate-800 leading-relaxed shadow-inner focus:border-brand-400 focus:ring-2 focus:ring-brand-400/20 transition-all duration-200" placeholder="Agregue aquí cualquier observación o condición especial..."><?= htmlspecialchars($profile['consent_notes'] ?? '') ?></textarea>
            </label>
        </fieldset>

    </form>

    <div id="studies" class="space-y-6 rounded-3xl border border-brand-100/50 bg-gradient-to-br from-white to-slate-50/80 p-6 shadow-lg sm:p-8">
        <div class="flex items-start gap-4">
            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-500 to-brand-600 shadow-lg shadow-brand-200/50">
                <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
            </div>
            <div class="flex-1 space-y-1">
                <h3 class="text-2xl font-bold text-slate-900">Estudios paraclínicos</h3>
                <p class="text-sm text-slate-600">Adjunta imágenes o resultados complementarios para <?= htmlspecialchars($patient['full_name']) ?>.</p>
            </div>
        </div>

        <?php if ($studyLimitReached): ?>
            <div class="rounded-2xl border-2 border-amber-300/60 bg-gradient-to-br from-amber-50 to-orange-50/80 px-5 py-4 shadow-sm">
                <div class="flex items-start gap-3">
                    <svg class="h-5 w-5 flex-shrink-0 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                    <p class="text-sm font-medium text-amber-900">Se alcanzó el máximo de <?= $studyLimit ?> estudios. Elimina alguno para cargar uno nuevo.</p>
                </div>
            </div>
        <?php endif; ?>

        <form id="patientStudyForm" action="patient_studies_upload.php" method="post" enctype="multipart/form-data" class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-md">
            <input type="hidden" name="patient_id" value="<?= $patientId ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div class="mb-5 flex items-center gap-3">
                <svg class="h-5 w-5 text-brand-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                <h4 class="text-lg font-semibold text-slate-800">Registrar nuevo estudio</h4>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <label class="flex flex-col gap-2.5 text-sm text-slate-600">
                    <div class="flex items-center gap-2">
                        <svg class="h-4 w-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                        </svg>
                        <span class="font-semibold text-slate-700">Título del estudio</span>
                    </div>
                    <input type="text" name="title" class="rounded-xl border-2 border-slate-200 bg-slate-50/50 px-4 py-3 text-slate-700 transition focus:border-brand-400 focus:bg-white focus:ring-2 focus:ring-brand-100" placeholder="Ej. Radiografía panorámica" <?= $studyLimitReached ? 'disabled' : '' ?> data-capitalize-initial>
                </label>

                <label class="flex flex-col gap-2.5 text-sm text-slate-600">
                    <div class="flex items-center gap-2">
                        <svg class="h-4 w-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                        </svg>
                        <span class="font-semibold text-slate-700">Tipo</span>
                    </div>
                    <input type="text" name="study_type" class="rounded-xl border-2 border-slate-200 bg-slate-50/50 px-4 py-3 text-slate-700 transition focus:border-brand-400 focus:bg-white focus:ring-2 focus:ring-brand-100" placeholder="Radiografía, laboratorio..." <?= $studyLimitReached ? 'disabled' : '' ?> data-capitalize-initial>
                </label>

                <label class="flex flex-col gap-2.5 text-sm text-slate-600">
                    <div class="flex items-center gap-2">
                        <svg class="h-4 w-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                        <span class="font-semibold text-slate-700">Fecha del estudio</span>
                    </div>
                    <input type="date" name="captured_at" class="rounded-xl border-2 border-slate-200 bg-slate-50/50 px-4 py-3 text-slate-700 transition focus:border-brand-400 focus:bg-white focus:ring-2 focus:ring-brand-100" <?= $studyLimitReached ? 'disabled' : '' ?>>
                </label>

                <label class="flex flex-col gap-2.5 text-sm text-slate-600">
                    <div class="flex items-center gap-2">
                        <svg class="h-4 w-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                        </svg>
                        <span class="font-semibold text-slate-700">Registrado por</span>
                    </div>
                    <input type="text" name="uploaded_by" class="rounded-xl border-2 border-slate-200 bg-slate-50/50 px-4 py-3 text-slate-700 transition focus:border-brand-400 focus:bg-white focus:ring-2 focus:ring-brand-100" placeholder="Nombre del responsable" <?= $studyLimitReached ? 'disabled' : '' ?> data-capitalize-initial>
                </label>

                <label class="sm:col-span-2 flex flex-col gap-2.5 text-sm text-slate-600">
                    <div class="flex items-center gap-2">
                        <svg class="h-4 w-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                        </svg>
                        <span class="font-semibold text-slate-700">Notas</span>
                    </div>
                    <textarea name="notes" rows="2" class="rounded-xl border-2 border-slate-200 bg-slate-50/50 px-4 py-3 text-slate-700 transition focus:border-brand-400 focus:bg-white focus:ring-2 focus:ring-brand-100" placeholder="Observaciones relevantes" <?= $studyLimitReached ? 'disabled' : '' ?> data-capitalize-initial></textarea>
                </label>

                <div class="sm:col-span-2 space-y-3">
                    <label class="flex flex-col gap-2.5 text-sm text-slate-600">
                        <div class="flex items-center gap-2">
                            <svg class="h-4 w-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                            <span class="font-semibold text-slate-700">Archivo adjunto</span>
                        </div>
                        <input type="file" name="study_file" accept=".jpg,.jpeg,.png,.webp,.pdf" class="rounded-xl border-2 border-dashed border-brand-300 bg-brand-50/30 px-4 py-4 text-slate-700 transition hover:border-brand-400 hover:bg-brand-50/50 focus:border-brand-500 focus:bg-white focus:ring-2 focus:ring-brand-100 file:mr-4 file:rounded-lg file:border-0 file:bg-brand-600 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white file:transition hover:file:bg-brand-500" <?= $studyLimitReached ? 'disabled' : '' ?>>
                    </label>
                    <div class="flex items-center gap-2 rounded-lg bg-blue-50/60 px-4 py-2.5">
                        <svg class="h-4 w-4 flex-shrink-0 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span class="text-xs text-blue-800">Formatos admitidos: <?= htmlspecialchars($studyAllowedLabel) ?>. Tamaño máximo <?= $studyMaxFileSizeMb ?> MB.</span>
                    </div>
                </div>
            </div>

            <div class="mt-6 flex flex-wrap items-center justify-between gap-4 rounded-xl bg-slate-50/80 px-4 py-3">
                <span class="text-xs font-medium text-slate-600">Registros actuales: <span class="font-bold text-brand-600"><?= $studyCount ?> / <?= $studyLimit ?></span></span>
                <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-brand-600 to-brand-500 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-brand-200/50 transition hover:-translate-y-0.5 hover:shadow-xl hover:shadow-brand-300/60 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 <?= $studyLimitReached ? 'cursor-not-allowed opacity-60 hover:translate-y-0 hover:shadow-lg' : '' ?>" <?= $studyLimitReached ? 'disabled' : '' ?>>
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                    </svg>
                    Subir estudio
                </button>
            </div>
        </form>

        <div class="space-y-5">
            <div class="flex items-center gap-3">
                <svg class="h-5 w-5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path>
                </svg>
                <h4 class="text-base font-bold uppercase tracking-wide text-slate-700">Archivos registrados</h4>
            </div>

            <?php if (!$patientStudies): ?>
                <div class="rounded-2xl border-2 border-dashed border-slate-300 bg-slate-50/50 px-6 py-12 text-center">
                    <svg class="mx-auto h-12 w-12 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    <p class="mt-4 text-sm font-medium text-slate-500">Aún no se han adjuntado estudios para este paciente.</p>
                </div>
            <?php else: ?>
                <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-1">
                    <?php foreach ($patientStudies as $study): ?>
                        <?php
                        $studyTitle = trim((string) ($study['title'] ?? ''));
                        if ($studyTitle === '') {
                            $studyTitle = trim((string) ($study['original_filename'] ?? ''));
                        }
                        if ($studyTitle === '') {
                            $studyTitle = 'Estudio sin título';
                        }
                        $studyTypeLabel = trim((string) ($study['study_type'] ?? ''));
                        $capturedLabel = $formatStudyDate($study['captured_at'] ?? null);
                        $sizeLabel = isset($study['file_size']) ? $formatStudySize((int) $study['file_size']) : null;
                        $notesText = trim((string) ($study['notes'] ?? ''));
                        $filePath = trim((string) ($study['file_path'] ?? ''));
                        $downloadName = $study['original_filename'] ?? ($filePath !== '' ? basename($filePath) : 'estudio');
                        $mimeType = (string) ($study['mime_type'] ?? '');
                        $isImage = $mimeType !== '' && strpos($mimeType, 'image/') === 0;
                        $editPanelId = 'study-edit-panel-' . (int) ($study['id'] ?? 0);
                        $capturedRaw = trim((string) ($study['captured_at'] ?? ''));
                        $uploadedByValue = trim((string) ($study['uploaded_by'] ?? ''));
                        $notesValue = (string) ($study['notes'] ?? '');
                        ?>
                        <li class="group rounded-2xl border-2 border-slate-200/80 bg-white p-5 shadow-md transition hover:border-brand-200 hover:shadow-xl">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div class="flex min-w-0 gap-4">
                                    <?php if ($isImage && $filePath !== ''): ?>
                                        <a href="<?= htmlspecialchars($filePath) ?>" target="_blank" rel="noopener" class="block h-20 w-20 flex-shrink-0 overflow-hidden rounded-2xl border-2 border-slate-200 bg-slate-100 shadow-md ring-2 ring-transparent transition hover:ring-brand-300">
                                            <img src="<?= htmlspecialchars($filePath) ?>" alt="<?= htmlspecialchars($studyTitle) ?>" class="h-full w-full object-cover" loading="lazy">
                                        </a>
                                    <?php else: ?>
                                        <div class="flex h-20 w-20 flex-shrink-0 items-center justify-center rounded-2xl border-2 border-slate-200 bg-gradient-to-br from-slate-100 to-slate-200 shadow-md">
                                            <svg class="h-10 w-10 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                                            </svg>
                                        </div>
                                    <?php endif; ?>

                                    <div class="flex-1 min-w-0 space-y-2 text-sm">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h5 class="text-base font-bold text-slate-900"><?= htmlspecialchars($studyTitle) ?></h5>
                                            <?php if ($studyTypeLabel !== ''): ?>
                                                <span class="inline-flex items-center gap-1 rounded-lg bg-gradient-to-r from-brand-500 to-brand-600 px-3 py-1 text-xs font-bold text-white shadow-sm">
                                                    <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                                                    </svg>
                                                    <?= htmlspecialchars($studyTypeLabel) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="grid gap-1.5 text-xs text-slate-600 overflow-hidden">
                                            <?php if ($capturedLabel): ?>
                                                <div class="flex items-center gap-2">
                                                    <svg class="h-3.5 w-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                                    </svg>
                                                    <span><span class="font-medium text-slate-700">Fecha:</span> <?= htmlspecialchars($capturedLabel) ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($study['uploaded_by'])): ?>
                                                <div class="flex items-center gap-2">
                                                    <svg class="h-3.5 w-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                                    </svg>
                                                    <span><span class="font-medium text-slate-700">Registrado por:</span> <?= htmlspecialchars($study['uploaded_by']) ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($sizeLabel): ?>
                                                <div class="flex items-center gap-2">
                                                    <svg class="h-3.5 w-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                                    </svg>
                                                    <span><span class="font-medium text-slate-700">Tamaño:</span> <?= htmlspecialchars($sizeLabel) ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($notesText !== ''): ?>
                                                <div class="mt-1 overflow-x-auto">
                                                    <div class="flex items-start gap-2 rounded-lg bg-slate-50 px-2.5 py-2">
                                                        <svg class="h-3.5 w-3.5 flex-shrink-0 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                        </svg>
                                                        <div class="flex-1 min-w-0 max-w-full">
                                                            <span class="font-medium text-slate-700">Notas:</span>
                                                            <div class="prevent-overflow mt-1 max-h-20 max-w-full overflow-auto break-all rounded border border-slate-200 bg-white p-2 text-xs">
                                                                <?= nl2br(htmlspecialchars($notesText)) ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex flex-col gap-2 text-xs font-semibold">
                                    <button
                                        type="button"
                                        class="inline-flex items-center justify-center gap-1.5 rounded-xl border-2 border-amber-200 bg-white px-3.5 py-2 text-amber-600 shadow-sm transition hover:-translate-y-0.5 hover:border-amber-300 hover:bg-amber-50 hover:shadow-md"
                                        data-study-edit-toggle
                                        data-study-edit-target="<?= htmlspecialchars($editPanelId) ?>"
                                        data-label-open="Editar"
                                        data-label-close="Cerrar"
                                        aria-expanded="false"
                                    >
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                        </svg>
                                        <span data-study-edit-label>Editar</span>
                                    </button>
                                    <?php if ($isImage && $filePath !== ''): ?>
                                        <button type="button" class="inline-flex items-center justify-center gap-1.5 rounded-xl border-2 border-brand-200 bg-white px-3.5 py-2 text-brand-600 shadow-sm transition hover:-translate-y-0.5 hover:border-brand-300 hover:bg-brand-50 hover:shadow-md" data-study-preview-trigger data-study-preview-src="<?= htmlspecialchars($filePath) ?>" data-study-preview-title="<?= htmlspecialchars($studyTitle) ?>">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7"></path>
                                            </svg>
                                            Ampliar
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($filePath !== ''): ?>
                                        <a href="<?= htmlspecialchars($filePath) ?>" target="_blank" rel="noopener" download="<?= htmlspecialchars($downloadName) ?>" class="inline-flex items-center justify-center gap-1.5 rounded-xl border-2 border-blue-200 bg-white px-3.5 py-2 text-blue-600 shadow-sm transition hover:-translate-y-0.5 hover:border-blue-300 hover:bg-blue-50 hover:shadow-md">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                            </svg>
                                            Ver / descargar
                                        </a>
                                    <?php endif; ?>
                                    <form action="patient_studies_delete.php" method="post" onsubmit="return confirm('¿Seguro que deseas eliminar este estudio?');" class="w-full">
                                        <input type="hidden" name="patient_id" value="<?= $patientId ?>">
                                        <input type="hidden" name="study_id" value="<?= (int) ($study['id'] ?? 0) ?>">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <button type="submit" class="inline-flex w-full items-center justify-center gap-1.5 rounded-xl border-2 border-rose-200 bg-white px-3.5 py-2 text-rose-600 shadow-sm transition hover:-translate-y-0.5 hover:border-rose-300 hover:bg-rose-50 hover:shadow-md">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                            </svg>
                                            Eliminar
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <div id="<?= htmlspecialchars($editPanelId) ?>" class="mt-4 hidden rounded-2xl border border-indigo-100 bg-indigo-50/60 p-4 text-sm text-slate-600">
                                <form action="patient_studies_update.php" method="post" class="space-y-3">
                                    <input type="hidden" name="patient_id" value="<?= $patientId ?>">
                                    <input type="hidden" name="study_id" value="<?= (int) ($study['id'] ?? 0) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <label class="flex flex-col gap-1">
                                            <span class="text-xs font-semibold uppercase tracking-wide text-slate-700">Título</span>
                                            <input type="text" name="title" value="<?= htmlspecialchars((string) ($study['title'] ?? '')) ?>" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400" data-capitalize-initial>
                                        </label>
                                        <label class="flex flex-col gap-1">
                                            <span class="text-xs font-semibold uppercase tracking-wide text-slate-700">Tipo</span>
                                            <input type="text" name="study_type" value="<?= htmlspecialchars((string) ($study['study_type'] ?? '')) ?>" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400" data-capitalize-initial>
                                        </label>
                                        <label class="flex flex-col gap-1">
                                            <span class="text-xs font-semibold uppercase tracking-wide text-slate-700">Fecha del estudio</span>
                                            <input type="date" name="captured_at" value="<?= htmlspecialchars($capturedRaw) ?>" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                                        </label>
                                        <label class="flex flex-col gap-1">
                                            <span class="text-xs font-semibold uppercase tracking-wide text-slate-700">Registrado por</span>
                                            <input type="text" name="uploaded_by" value="<?= htmlspecialchars($uploadedByValue) ?>" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400" data-capitalize-initial>
                                        </label>
                                    </div>
                                    <label class="flex flex-col gap-1">
                                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-700">Notas</span>
                                        <textarea name="notes" rows="3" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400" data-capitalize-initial><?= htmlspecialchars($notesValue) ?></textarea>
                                    </label>
                                    <div class="flex flex-wrap items-center gap-3">
                                        <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-soft transition hover:-translate-y-0.5 hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                            </svg>
                                            Guardar cambios
                                        </button>
                                        <button type="button" class="inline-flex items-center gap-2 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-600 transition hover:-translate-y-0.5 hover:border-slate-400 hover:bg-white" data-study-edit-cancel>
                                            Cancelar
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

<div class="sticky bottom-4 z-40 mt-6 flex flex-col gap-3 rounded-2xl border border-brand-100 bg-white/96 p-4 shadow-lg shadow-brand-100/70 backdrop-blur-sm sm:flex-row sm:items-center sm:justify-between">
    <div class="flex flex-col gap-1 text-xs text-slate-600">
        <span class="font-semibold uppercase tracking-wide text-slate-700">Pendiente de guardar</span>
        <span>Guarda los datos antes de abandonar esta pantalla.</span>
    </div>
    <div class="flex flex-wrap items-center gap-3">
        <a class="inline-flex items-center justify-center gap-2 rounded-full border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-600 transition hover:border-rose-200 hover:text-rose-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-500" href="patient.php?id=<?= $patientId ?>">
            Cancelar
        </a>
        <button type="button" class="inline-flex items-center justify-center gap-2 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:-translate-y-0.5 hover:bg-brand-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500" data-patient-save-button>
            Guardar cambios
        </button>
    </div>
</div>
</section>

<div id="studyPreviewModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-slate-900/80 backdrop-blur-sm" data-study-preview-close></div>
    <div class="relative z-10 mx-auto flex h-full max-w-6xl flex-col gap-4 p-4">
        <div class="flex justify-end">
            <button type="button" class="inline-flex items-center justify-center rounded-full border border-white/60 bg-white/90 px-3 py-1 text-sm font-semibold text-slate-600 shadow-sm transition hover:bg-white" data-study-preview-close>
                Cerrar ✕
            </button>
        </div>
        <div class="flex flex-1 flex-col gap-4 rounded-2xl border border-slate-200 bg-white/95 p-4 shadow-2xl shadow-slate-900/20">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h5 class="text-base font-semibold text-slate-900" data-study-preview-title>Vista previa</h5>
                <div class="flex flex-wrap items-center gap-3 text-sm text-slate-600">
                    <label class="flex items-center gap-2">
                        <span class="font-medium text-slate-700">Zoom</span>
                        <input type="range" min="100" max="300" step="10" value="100" class="h-2 w-36 cursor-pointer appearance-none rounded-full bg-slate-200" data-study-preview-zoom>
                        <span class="w-12 text-right font-semibold text-brand-600" data-study-preview-zoom-label>100%</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <span class="font-medium text-slate-700">Mover X</span>
                        <input type="range" min="-100" max="100" step="5" value="0" class="h-2 w-32 cursor-pointer appearance-none rounded-full bg-slate-200" data-study-preview-offset-x>
                        <span class="w-12 text-right font-semibold text-brand-600" data-study-preview-offset-x-label>0%</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <span class="font-medium text-slate-700">Mover Y</span>
                        <input type="range" min="-100" max="100" step="5" value="0" class="h-2 w-32 cursor-pointer appearance-none rounded-full bg-slate-200" data-study-preview-offset-y>
                        <span class="w-12 text-right font-semibold text-brand-600" data-study-preview-offset-y-label>0%</span>
                    </label>
                </div>
            </div>
            <div class="flex-1 overflow-auto rounded-xl border border-slate-200 bg-slate-100 p-4" data-study-preview-container>
                <img src="" alt="" class="mx-auto select-none transition-transform duration-150 ease-in-out" style="transform: scale(1); transform-origin: center center;" data-study-preview-img>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const modal = document.getElementById('studyPreviewModal');
    if (!modal) {
        return;
    }
    const imageEl = modal.querySelector('[data-study-preview-img]');
    const containerEl = modal.querySelector('[data-study-preview-container]');
    const titleEl = modal.querySelector('[data-study-preview-title]');
    const zoomInput = modal.querySelector('[data-study-preview-zoom]');
    const zoomLabel = modal.querySelector('[data-study-preview-zoom-label]');
    const offsetXInput = modal.querySelector('[data-study-preview-offset-x]');
    const offsetXLabel = modal.querySelector('[data-study-preview-offset-x-label]');
    const offsetYInput = modal.querySelector('[data-study-preview-offset-y]');
    const offsetYLabel = modal.querySelector('[data-study-preview-offset-y-label]');
    const closeElements = modal.querySelectorAll('[data-study-preview-close]');
    const triggers = document.querySelectorAll('[data-study-preview-trigger]');
    const body = document.body;
    let currentScale = 1;
    let currentOffsetX = 0;
    let currentOffsetY = 0;

    const hideModal = () => {
        modal.classList.add('hidden');
        body.classList.remove('overflow-hidden');
        imageEl.src = '';
        imageEl.alt = '';
    };

    const applyTransform = () => {
        imageEl.style.transform = 'translate(' + currentOffsetX + '%, ' + currentOffsetY + '%) scale(' + currentScale + ')';
        if (zoomLabel) {
            zoomLabel.textContent = Math.round(currentScale * 100) + '%';
        }
        if (offsetXLabel) {
            offsetXLabel.textContent = currentOffsetX + '%';
        }
        if (offsetYLabel) {
            offsetYLabel.textContent = currentOffsetY + '%';
        }
    };

    if (zoomInput) {
        zoomInput.addEventListener('input', (event) => {
            const value = Number(event.target.value);
            currentScale = value / 100;
            applyTransform();
        });
    }

    if (offsetXInput) {
        offsetXInput.addEventListener('input', (event) => {
            currentOffsetX = Number(event.target.value);
            applyTransform();
        });
    }

    if (offsetYInput) {
        offsetYInput.addEventListener('input', (event) => {
            currentOffsetY = Number(event.target.value);
            applyTransform();
        });
    }

    closeElements.forEach((element) => {
        element.addEventListener('click', hideModal);
    });

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            hideModal();
        }
    });

    triggers.forEach((button) => {
        button.addEventListener('click', () => {
            const src = button.getAttribute('data-study-preview-src');
            const title = button.getAttribute('data-study-preview-title') || 'Vista previa';
            if (!src) {
                return;
            }
            if (containerEl) {
                containerEl.scrollTop = 0;
                containerEl.scrollLeft = 0;
            }
            imageEl.src = src;
            imageEl.alt = title;
            titleEl.textContent = title;
            currentScale = 1;
            currentOffsetX = 0;
            currentOffsetY = 0;
            if (zoomInput) {
                zoomInput.value = '100';
            }
            if (offsetXInput) {
                offsetXInput.value = '0';
            }
            if (offsetYInput) {
                offsetYInput.value = '0';
            }
            applyTransform();
            modal.classList.remove('hidden');
            body.classList.add('overflow-hidden');
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
            hideModal();
        }
    });
})();

(function () {
    const editButtons = document.querySelectorAll('[data-study-edit-toggle]');
    if (!editButtons.length) {
        return;
    }

    const getPanel = (button) => {
        const panelId = button.getAttribute('data-study-edit-target');
        if (!panelId) {
            return null;
        }
        return document.getElementById(panelId);
    };

    const setState = (button, panel, open) => {
        const openLabel = button.getAttribute('data-label-open') || 'Editar';
        const closeLabel = button.getAttribute('data-label-close') || 'Cerrar';
        panel.classList.toggle('hidden', !open);
        const labelSpan = button.querySelector('[data-study-edit-label]');
        if (labelSpan) {
            labelSpan.textContent = open ? closeLabel : openLabel;
        } else {
            button.textContent = open ? closeLabel : openLabel;
        }
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    editButtons.forEach((button) => {
        const panel = getPanel(button);
        if (!panel) {
            return;
        }

        setState(button, panel, false);

        button.addEventListener('click', () => {
            const willOpen = panel.classList.contains('hidden');
            if (willOpen) {
                editButtons.forEach((otherButton) => {
                    if (otherButton === button) {
                        return;
                    }
                    const otherPanel = getPanel(otherButton);
                    if (otherPanel) {
                        setState(otherButton, otherPanel, false);
                    }
                });
            }
            setState(button, panel, willOpen);
        });

        const cancelButton = panel.querySelector('[data-study-edit-cancel]');
        if (cancelButton) {
            cancelButton.addEventListener('click', (event) => {
                event.preventDefault();
                setState(button, panel, false);
            });
        }
    });
})();

(function () {
    const capitalizeInitialValue = function (value) {
        if (typeof value !== 'string' || value === '') {
            return '';
        }
        const leadingMatch = value.match(/^\s*/);
        const leadingWhitespace = leadingMatch ? leadingMatch[0] : '';
        const withoutLeading = value.slice(leadingWhitespace.length);
        if (withoutLeading === '') {
            return leadingWhitespace;
        }
        const firstChar = withoutLeading.charAt(0).toLocaleUpperCase('es-ES');
        return leadingWhitespace + firstChar + withoutLeading.slice(1);
    };

    const fields = document.querySelectorAll('[data-capitalize-initial]');
    if (!fields.length) {
        return;
    }

    fields.forEach((field) => {
        const applyCapitalization = () => {
            const selectionStart = field.selectionStart;
            const selectionEnd = field.selectionEnd;
            const newValue = capitalizeInitialValue(field.value || '');
            if (field.value !== newValue) {
                field.value = newValue;
                if (typeof selectionStart === 'number' && typeof selectionEnd === 'number') {
                    field.selectionStart = selectionStart;
                    field.selectionEnd = selectionEnd;
                }
            }
        };

        field.addEventListener('input', () => {
            const trimmed = (field.value || '').trimStart();
            if (trimmed.length === 0) {
                return;
            }
            const firstChar = trimmed.charAt(0);
            if (firstChar !== firstChar.toLocaleUpperCase('es-ES')) {
                applyCapitalization();
            }
        });
        field.addEventListener('blur', applyCapitalization);
        field.addEventListener('change', applyCapitalization);
        applyCapitalization();
    });
})();

(function () {
    const saveButton = document.querySelector('[data-patient-save-button]');
    const patientForm = document.getElementById('patientProfileForm');
    if (!saveButton || !patientForm) {
        return;
    }
    const studyForm = document.getElementById('patientStudyForm');
    const defaultLabel = saveButton.textContent.trim();
    let isSubmitting = false;

    const submitPatientForm = () => {
        if (typeof patientForm.requestSubmit === 'function') {
            patientForm.requestSubmit();
        } else {
            patientForm.submit();
        }
    };

    saveButton.addEventListener('click', async (event) => {
        event.preventDefault();
        if (isSubmitting) {
            return;
        }

        if (!studyForm) {
            submitPatientForm();
            return;
        }

        const fileInput = studyForm.querySelector('input[type="file"][name="study_file"]');
        const hasFile = fileInput && fileInput.files && fileInput.files.length > 0;
        const hasTextData = studyForm
            ? Array.from(studyForm.querySelectorAll('input[type="text"], textarea, input[type="date"]'))
                .some((field) => field.value.trim() !== '')
            : false;

        if (!hasFile) {
            if (hasTextData) {
                alert('Selecciona un archivo para adjuntar el estudio o deja los campos vacíos.');
                return;
            }
            submitPatientForm();
            return;
        }

        isSubmitting = true;
        saveButton.disabled = true;
        saveButton.textContent = 'Guardando...';

        try {
            const formData = new FormData(studyForm);
            const response = await fetch(studyForm.action, {
                method: studyForm.method || 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            const contentType = response.headers.get('Content-Type') || '';
            let payload = null;
            if (contentType.includes('application/json')) {
                payload = await response.json();
            }

            const success = payload ? payload.success === true : response.ok;
            if (!success) {
                const errorMessage = (payload && payload.errors && payload.errors[0]) || 'No se pudo registrar el estudio paraclínico.';
                throw new Error(errorMessage);
            }

            submitPatientForm();
        } catch (error) {
            alert(error.message || 'No se pudo registrar el estudio paraclínico. Revisa el archivo e inténtalo nuevamente.');
        } finally {
            isSubmitting = false;
            saveButton.disabled = false;
            saveButton.textContent = defaultLabel;
        }
    });
})();

// ========================================
// AUTOGUARDADO DE HISTORIA CLÍNICA
// ========================================
(function() {
    const form = document.getElementById('patientProfileForm');
    if (!form) return;

    const patientId = <?= json_encode($patientId) ?>;
    const storageKey = `clinical_history_draft_${patientId}`;
    const AUTOSAVE_DELAY = 1000; // 1 segundo después de dejar de escribir
    let autosaveTimeout = null;
    let hasUnsavedChanges = false;
    let isSubmitting = false;

    // Crear indicador visual de autoguardado
    const indicator = document.createElement('div');
    indicator.id = 'autosave-indicator';
    indicator.style.cssText = `
        position: fixed;
        top: 80px;
        right: 20px;
        padding: 12px 20px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        z-index: 1000;
        display: none;
        transition: all 0.3s ease;
    `;
    document.body.appendChild(indicator);

    function showIndicator(message, type = 'info') {
        const colors = {
            info: { bg: '#3b82f6', text: '#ffffff' },
            success: { bg: '#10b981', text: '#ffffff' },
            warning: { bg: '#f59e0b', text: '#ffffff' }
        };
        const color = colors[type] || colors.info;
        indicator.style.backgroundColor = color.bg;
        indicator.style.color = color.text;
        indicator.textContent = message;
        indicator.style.display = 'block';

        setTimeout(() => {
            indicator.style.display = 'none';
        }, 3000);
    }

    // Guardar datos en localStorage
    function saveToLocalStorage() {
        const formData = {};
        const inputs = form.querySelectorAll('input, textarea, select');

        inputs.forEach(input => {
            if (!input.name) return;

            if (input.type === 'checkbox') {
                formData[input.name] = input.checked;
            } else if (input.type === 'radio') {
                if (input.checked) {
                    formData[input.name] = input.value;
                }
            } else if (input.type !== 'file') {
                formData[input.name] = input.value;
            }
        });

        try {
            localStorage.setItem(storageKey, JSON.stringify({
                data: formData,
                timestamp: new Date().toISOString()
            }));
            hasUnsavedChanges = false;
            if (!isSubmitting) {
                showIndicator('✓ Datos guardados automáticamente', 'success');
            }
        } catch (error) {
            console.error('Error al guardar en localStorage:', error);
        }
    }

    // Recuperar datos de localStorage
    function loadFromLocalStorage() {
        try {
            const saved = localStorage.getItem(storageKey);
            if (!saved) return false;

            const { data, timestamp } = JSON.parse(saved);
            const savedDate = new Date(timestamp);
            const now = new Date();
            const hoursDiff = (now - savedDate) / (1000 * 60 * 60);

            // Si los datos tienen más de 24 horas, no los cargar
            if (hoursDiff > 24) {
                localStorage.removeItem(storageKey);
                return false;
            }

            // Cargar los datos en el formulario
            Object.keys(data).forEach(name => {
                const elements = form.querySelectorAll(`[name="${name}"]`);

                elements.forEach(element => {
                    if (element.type === 'checkbox') {
                        element.checked = data[name];
                    } else if (element.type === 'radio') {
                        if (element.value === data[name]) {
                            element.checked = true;
                        }
                    } else if (element.type !== 'file') {
                        element.value = data[name];
                    }
                });
            });

            // No mostrar mensaje al recuperar datos automáticamente
            return true;
        } catch (error) {
            console.error('Error al cargar desde localStorage:', error);
            return false;
        }
    }

    // Limpiar localStorage después de guardar exitosamente
    function clearLocalStorage() {
        try {
            localStorage.removeItem(storageKey);
            console.log('Borrador eliminado después de guardar exitosamente');
        } catch (error) {
            console.error('Error al limpiar localStorage:', error);
        }
    }

    // Configurar autoguardado
    function scheduleAutosave() {
        if (isSubmitting) return; // No autoguardar si se está enviando el formulario

        hasUnsavedChanges = true;

        if (autosaveTimeout) {
            clearTimeout(autosaveTimeout);
        }

        autosaveTimeout = setTimeout(() => {
            if (hasUnsavedChanges && !isSubmitting) {
                saveToLocalStorage();
            }
        }, AUTOSAVE_DELAY);
    }

    // Escuchar cambios en todos los campos del formulario
    const inputs = form.querySelectorAll('input, textarea, select');
    inputs.forEach(input => {
        if (input.type !== 'file') {
            input.addEventListener('input', scheduleAutosave);
            input.addEventListener('change', scheduleAutosave);
        }
    });

    // Capturar clicks en botones de submit para cancelar autoguardado INMEDIATAMENTE
    const submitButtons = form.querySelectorAll('button[type="submit"]');
    submitButtons.forEach(button => {
        button.addEventListener('mousedown', function() {
            isSubmitting = true;
            hasUnsavedChanges = false;
            if (autosaveTimeout) {
                clearTimeout(autosaveTimeout);
                autosaveTimeout = null;
            }
            // Limpiar localStorage INMEDIATAMENTE para evitar recuperación al recargar
            clearLocalStorage();
        });
    });

    // Limpiar localStorage cuando se envía el formulario exitosamente
    form.addEventListener('submit', function(e) {
        // Establecer bandera INMEDIATAMENTE antes de cualquier otra cosa
        isSubmitting = true;
        hasUnsavedChanges = false;

        // Cancelar cualquier autoguardado pendiente
        if (autosaveTimeout) {
            clearTimeout(autosaveTimeout);
            autosaveTimeout = null;
        }

        showIndicator('✓ Datos guardados exitosamente', 'success');

        // Esperar un poco para asegurarse de que el formulario se envíe
        setTimeout(() => {
            clearLocalStorage();
        }, 500);
    });

    // Recuperar datos al cargar la página
    window.addEventListener('DOMContentLoaded', () => {
        loadFromLocalStorage();
    });

    // Advertir antes de cerrar si hay cambios no guardados
    window.addEventListener('beforeunload', (e) => {
        if (hasUnsavedChanges) {
            // Guardar antes de salir
            saveToLocalStorage();
        }
    });

    console.log('✓ Autoguardado de historia clínica activado');
})();

// Firma digital en el canvas
(function() {
    const canvas = document.getElementById('signature-canvas');
    const clearBtn = document.getElementById('clear-signature');
    const signatureData = document.getElementById('signature-data');

    if (!canvas) return;

    const ctx = canvas.getContext('2d');
    let isDrawing = false;
    let lastX = 0;
    let lastY = 0;

    // Ajustar el tamaño del canvas al tamaño real
    function resizeCanvas() {
        const rect = canvas.getBoundingClientRect();
        canvas.width = rect.width;
        canvas.height = rect.height;

        // Si hay una firma guardada, cargarla
        if (signatureData.value) {
            const img = new Image();
            img.onload = function() {
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            };
            img.src = signatureData.value;
        }
    }

    // Configurar el canvas
    resizeCanvas();
    window.addEventListener('resize', resizeCanvas);

    // Configurar estilo de dibujo
    ctx.strokeStyle = '#000';
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';

    // Obtener coordenadas relativas al canvas
    function getCoordinates(e) {
        const rect = canvas.getBoundingClientRect();
        const touch = e.touches ? e.touches[0] : e;
        return {
            x: touch.clientX - rect.left,
            y: touch.clientY - rect.top
        };
    }

    // Iniciar dibujo
    function startDrawing(e) {
        e.preventDefault();
        isDrawing = true;
        const coords = getCoordinates(e);
        lastX = coords.x;
        lastY = coords.y;
    }

    // Dibujar
    function draw(e) {
        if (!isDrawing) return;
        e.preventDefault();

        const coords = getCoordinates(e);

        ctx.beginPath();
        ctx.moveTo(lastX, lastY);
        ctx.lineTo(coords.x, coords.y);
        ctx.stroke();

        lastX = coords.x;
        lastY = coords.y;
    }

    // Finalizar dibujo
    function stopDrawing(e) {
        if (!isDrawing) return;
        e.preventDefault();
        isDrawing = false;

        // Guardar la firma como base64
        signatureData.value = canvas.toDataURL('image/png');
    }

    // Limpiar firma
    function clearSignature() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        signatureData.value = '';
    }

    // Eventos para mouse
    canvas.addEventListener('mousedown', startDrawing);
    canvas.addEventListener('mousemove', draw);
    canvas.addEventListener('mouseup', stopDrawing);
    canvas.addEventListener('mouseout', stopDrawing);

    // Eventos para touch (móviles y tablets)
    canvas.addEventListener('touchstart', startDrawing);
    canvas.addEventListener('touchmove', draw);
    canvas.addEventListener('touchend', stopDrawing);
    canvas.addEventListener('touchcancel', stopDrawing);

    // Botón limpiar
    if (clearBtn) {
        clearBtn.addEventListener('click', clearSignature);
    }

    // Botones para cambiar firma (cuando hay firma guardada)
    const changeSignatureBtn = document.getElementById('change-signature-btn');
    const cancelChangeBtn = document.getElementById('cancel-change-btn');
    const savedSignatureDisplay = document.getElementById('saved-signature-display');
    const signatureCanvasWrapper = document.getElementById('signature-canvas-wrapper');

    if (changeSignatureBtn && savedSignatureDisplay && signatureCanvasWrapper) {
        // Mostrar canvas para cambiar firma
        changeSignatureBtn.addEventListener('click', function() {
            savedSignatureDisplay.classList.add('hidden');
            signatureCanvasWrapper.classList.remove('hidden');
            // Limpiar el canvas
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            // Reajustar tamaño
            resizeCanvas();
        });

        // Cancelar y volver a mostrar firma guardada
        cancelChangeBtn.addEventListener('click', function() {
            signatureCanvasWrapper.classList.add('hidden');
            savedSignatureDisplay.classList.remove('hidden');
            // Restaurar firma guardada en el canvas
            if (signatureData.value) {
                const img = new Image();
                img.onload = function() {
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                };
                img.src = signatureData.value;
            }
        });
    }

    console.log('✓ Firma digital inicializada');
})();
</script>

<?php require __DIR__ . '/templates/footer.php'; ?>
