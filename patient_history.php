<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/database.php';

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

    <form method="post" class="space-y-6">
        <div class="space-y-1">
            <h2 class="text-2xl font-semibold text-slate-900">Historia clínica</h2>
            <p class="text-sm text-slate-500">Completa la historia, antecedentes y evaluaciones de <?= htmlspecialchars($patient['full_name']) ?>.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a class="inline-flex items-center justify-center gap-2 rounded-full border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-600 transition hover:border-brand-200 hover:text-brand-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500" href="patient.php?id=<?= $patientId ?>">
                Ver ficha del paciente
            </a>
            <a class="inline-flex items-center justify-center gap-2 rounded-full border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-600 transition hover:border-brand-200 hover:text-brand-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500" href="index.php">
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

        <fieldset class="rounded-2xl border border-slate-200/80 bg-slate-50/60 p-4 sm:p-6">
            <legend class="px-3 text-xs font-semibold uppercase tracking-wide text-brand-700">Consentimiento informado</legend>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" name="consent_signed" value="1" <?= !empty($profile['consent_signed']) ? 'checked' : '' ?> class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    <span>Consentimiento firmado</span>
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Fecha de firma</span>
                    <input type="date" name="consent_signed_at" value="<?= htmlspecialchars($profile['consent_signed_at'] ?? '') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
            </div>
            <label class="mt-4 flex flex-col gap-2 text-sm text-slate-600">
                <span class="font-medium text-slate-700">Observaciones / condiciones especiales</span>
                <textarea name="consent_notes" rows="2" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"><?= htmlspecialchars($profile['consent_notes'] ?? '') ?></textarea>
            </label>
        </fieldset>

        <div class="sticky bottom-4 z-20 mt-6 flex flex-col gap-3 rounded-2xl border border-brand-100 bg-white/96 p-4 shadow-lg shadow-brand-100/70 backdrop-blur-sm sm:flex-row sm:items-center sm:justify-between">
            <div class="flex flex-col gap-1 text-xs text-slate-600">
                <span class="font-semibold uppercase tracking-wide text-slate-700">Pendiente de guardar</span>
                <span>Guarda los datos antes de abandonar esta pantalla.</span>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <a class="inline-flex items-center justify-center gap-2 rounded-full border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-600 transition hover:border-rose-200 hover:text-rose-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-500" href="patient.php?id=<?= $patientId ?>">
                    Cancelar
                </a>
                <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:-translate-y-0.5 hover:bg-brand-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500">
                    Guardar cambios
                </button>
            </div>
        </div>
    </form>
</section>

<?php require __DIR__ . '/templates/footer.php'; ?>
