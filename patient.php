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

$validToothCodes = [
    '18','17','16','15','14','13','12','11',
    '21','22','23','24','25','26','27','28',
    '48','47','46','45','44','43','42','41',
    '31','32','33','34','35','36','37','38',
    '55','54','53','52','51',
    '61','62','63','64','65',
    '85','84','83','82','81',
    '71','72','73','74','75'
];

$odontogramStatuses = [
    'sin_registro' => 'Sin registro',
    'sano' => 'Sano',
    'caries' => 'Caries',
    'restaurado' => 'Restaurado',
    'obturacion' => 'Obturación',
    'endodoncia' => 'Endodoncia',
    'protesis' => 'Prótesis fija/removible',
    'implante' => 'Implante',
    'ausente' => 'Ausente',
    'fractura' => 'Fractura',
    'en_tratamiento' => 'En tratamiento'
];

$odontogramSurfaces = [
    'top' => 'Superficie oclusal',
    'left' => 'Superficie mesial',
    'center' => 'Superficie central',
    'right' => 'Superficie distal',
    'bottom' => 'Superficie lingual'
];

$odontogramGroups = [
    [
        'label' => 'Maxilar superior',
        'teeth' => ['18', '17', '16', '15', '14', '13', '12', '11', '21', '22', '23', '24', '25', '26', '27', '28'],
        'is_deciduous' => false,
    ],
    [
        'label' => 'Maxilar inferior',
        'teeth' => ['48', '47', '46', '45', '44', '43', '42', '41', '31', '32', '33', '34', '35', '36', '37', '38'],
        'is_deciduous' => false,
    ],
    [
        'label' => 'Dentición temporal superior',
        'teeth' => ['55', '54', '53', '52', '51', '61', '62', '63', '64', '65'],
        'is_deciduous' => true,
    ],
    [
        'label' => 'Dentición temporal inferior',
        'teeth' => ['85', '84', '83', '82', '81', '71', '72', '73', '74', '75'],
        'is_deciduous' => true,
    ],
];

$renderToothCard = static function (string $code, array $surfaceLabels, bool $isDeciduous = false): void {
    ?>
    <div class="tooth-card<?= $isDeciduous ? ' tooth-card--deciduous' : '' ?>" data-tooth="<?= htmlspecialchars($code) ?>">
        <span class="tooth-card__code"><?= htmlspecialchars($code) ?></span>
        <div class="tooth-grid" role="group" aria-label="Pieza <?= htmlspecialchars($code) ?>">
            <?php foreach ($surfaceLabels as $surface => $surfaceLabel): ?>
                <button type="button" class="tooth-cell surface-<?= htmlspecialchars($surface) ?>" data-surface="<?= htmlspecialchars($surface) ?>" aria-label="<?= htmlspecialchars($surfaceLabel) ?>"></button>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'update_profile') {
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
        $messages[] = 'Perfil clínico actualizado.';

        if ($medicalAlert = $profileData['medical_alerts']) {
            $pdo->prepare('UPDATE patients SET notes = :notes WHERE id = :id')->execute([
                ':notes' => $medicalAlert,
                ':id' => $patientId,
            ]);
        }

        header('Location: patient.php?id=' . $patientId . '#historia');
        exit;
    }

    if ($action === 'save_tooth') {
        $toothCode = (string) post('tooth_code');
        if (!in_array($toothCode, $validToothCodes, true)) {
            $errors[] = 'Pieza dental no válida.';
        } else {
            $statusKey = (string) post('status');
            if (!array_key_exists($statusKey, $odontogramStatuses)) {
                $errors[] = 'Estado de odontograma inválido.';
            } else {
                $note = trim((string) post('note'));
                upsertOdontogramEntry($pdo, $patientId, $toothCode, [
                    'status' => $statusKey,
                    'notes' => $note,
                ]);
                $messages[] = "Odontograma actualizado para la pieza $toothCode.";
                header('Location: patient.php?id=' . $patientId . '#odontograma');
                exit;
            }
        }
    }

    if ($action === 'create_visit') {
        $visitDate = normalizeDate(post('visit_date'));
        if (!$visitDate) {
            $errors[] = 'La fecha de la consulta es obligatoria.';
        }
        if (!$errors) {
            $visitId = insertRow($pdo, 'visits', [
                'patient_id' => $patientId,
                'visit_date' => $visitDate,
                'subjective_notes' => trim((string) post('subjective_notes')) ?: null,
                'objective_notes' => trim((string) post('objective_notes')) ?: null,
                'assessment' => trim((string) post('assessment')) ?: null,
                'plan' => trim((string) post('plan')) ?: null,
                'vitals_bp' => trim((string) post('vitals_bp')) ?: null,
                'vitals_hr' => trim((string) post('vitals_hr')) ?: null,
                'vitals_temp' => trim((string) post('vitals_temp')) ?: null,
                'vitals_oxygen' => trim((string) post('vitals_oxygen')) ?: null,
                'next_appointment' => normalizeDate(post('next_appointment')),
            ]);
            $messages[] = 'Consulta registrada correctamente.';
            header('Location: patient.php?id=' . $patientId . '#visitas');
            exit;
        }
    }

    if ($action === 'delete_visit') {
        $visitId = (int) post('visit_id');
        $deleteStmt = $pdo->prepare('DELETE FROM visits WHERE id = :id AND patient_id = :patient_id');
        $deleteStmt->execute([':id' => $visitId, ':patient_id' => $patientId]);
        $messages[] = 'Consulta eliminada.';
        header('Location: patient.php?id=' . $patientId . '#visitas');
        exit;
    }

    if ($action === 'create_activity') {
        $activityDate = normalizeDate(post('activity_date'));
        if (!$activityDate) {
            $errors[] = 'La fecha de la actividad es obligatoria.';
        }
        $description = trim((string) post('description'));
        if ($description === '') {
            $errors[] = 'La descripción es obligatoria.';
        }

        if (!$errors) {
            $fee = is_numeric(post('fee')) ? (float) post('fee') : 0.0;
            $payment = is_numeric(post('payment')) ? (float) post('payment') : 0.0;
            $balance = is_numeric(post('balance')) ? (float) post('balance') : $fee - $payment;
            insertRow($pdo, 'treatment_activities', [
                'patient_id' => $patientId,
                'visit_id' => post('related_visit') ? (int) post('related_visit') : null,
                'activity_date' => $activityDate,
                'description' => $description,
                'fee' => $fee,
                'payment' => $payment,
                'balance' => $balance,
                'notes' => trim((string) post('activity_notes')) ?: null,
            ]);
            $messages[] = 'Actividad registrada.';
            header('Location: patient.php?id=' . $patientId . '#actividades');
            exit;
        }
    }

    if ($action === 'delete_activity') {
        $activityId = (int) post('activity_id');
        $stmt = $pdo->prepare('DELETE FROM treatment_activities WHERE id = :id AND patient_id = :patient_id');
        $stmt->execute([':id' => $activityId, ':patient_id' => $patientId]);
        $messages[] = 'Actividad eliminada.';
        header('Location: patient.php?id=' . $patientId . '#actividades');
        exit;
    }
}

$profileStmt = $pdo->prepare('SELECT * FROM clinical_profiles WHERE patient_id = :id');
$profileStmt->execute([':id' => $patientId]);
$profile = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$odontogramStmt = $pdo->prepare('SELECT tooth_code, status, notes FROM odontogram_entries WHERE patient_id = :id');
$odontogramStmt->execute([':id' => $patientId]);
$odontogramData = [];
foreach ($odontogramStmt->fetchAll(PDO::FETCH_ASSOC) as $entry) {
    $odontogramData[$entry['tooth_code']] = $entry;
}

$visitsStmt = $pdo->prepare('SELECT * FROM visits WHERE patient_id = :id ORDER BY date(visit_date) DESC, id DESC');
$visitsStmt->execute([':id' => $patientId]);
$visits = $visitsStmt->fetchAll(PDO::FETCH_ASSOC);

$activitiesStmt = $pdo->prepare('SELECT * FROM treatment_activities WHERE patient_id = :id ORDER BY date(activity_date) DESC, id DESC');
$activitiesStmt->execute([':id' => $patientId]);
$activities = $activitiesStmt->fetchAll(PDO::FETCH_ASSOC);

$totalBalance = array_sum(array_map(fn($row) => (float) $row['balance'], $activities));

$pageTitle = 'Ficha de ' . $patient['full_name'];
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

<?php
$alertText = $profile['medical_alerts'] ?? $patient['notes'] ?? null;
$hasAlert = $alertText && trim((string) $alertText) !== '';
?>
<section class="rounded-3xl bg-white/95 p-6 shadow-sm shadow-slate-200/60 ring-1 ring-slate-200/70 sm:p-8 space-y-6">
    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-slate-900">Datos del paciente</h2>
            <p class="text-sm text-slate-500">Resumen actualizado de <?= htmlspecialchars($patient['full_name']) ?>.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a class="inline-flex items-center justify-center gap-2 rounded-full border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-600 transition hover:border-brand-200 hover:text-brand-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500" href="patient_form.php?id=<?= $patientId ?>">
                Editar datos
            </a>
            <a class="inline-flex items-center justify-center gap-2 rounded-full bg-brand-600 px-4 py-2 text-xs font-semibold text-white shadow-soft transition hover:-translate-y-0.5 hover:bg-brand-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500" href="index.php">
                Volver al listado
            </a>
        </div>
    </div>
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="rounded-2xl border border-slate-200/80 bg-slate-50/70 p-5 shadow-inner">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-600">Información general</h3>
            <dl class="mt-3 space-y-2 text-sm text-slate-600">
                <div class="flex justify-between gap-2">
                    <dt class="font-medium text-slate-700">Cédula</dt>
                    <dd class="text-right"><?= htmlspecialchars($patient['document_id'] ?? '—') ?></dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="font-medium text-slate-700">Edad</dt>
                    <dd class="text-right"><?= $patient['age'] ? (int) $patient['age'] . ' años' : '—' ?></dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="font-medium text-slate-700">Género</dt>
                    <dd class="text-right"><?= htmlspecialchars($patient['gender'] ?? '—') ?></dd>
                </div>
                <div class="space-y-1">
                    <dt class="font-medium text-slate-700">Dirección</dt>
                    <dd><?= nl2br(htmlspecialchars($patient['address'] ?? '—')) ?></dd>
                </div>
            </dl>
        </div>
        <div class="rounded-2xl border border-slate-200/80 bg-white p-5">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-600">Contacto</h3>
            <dl class="mt-3 space-y-2 text-sm text-slate-600">
                <div class="flex justify-between gap-2">
                    <dt class="font-medium text-slate-700">Correo</dt>
                    <dd class="text-right break-words"><?= htmlspecialchars($patient['email'] ?? '—') ?></dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="font-medium text-slate-700">Tel. principal</dt>
                    <dd class="text-right"><?= htmlspecialchars($patient['phone_primary'] ?? '—') ?></dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="font-medium text-slate-700">Tel. alterno</dt>
                    <dd class="text-right"><?= htmlspecialchars($patient['phone_secondary'] ?? '—') ?></dd>
                </div>
                <div class="space-y-1">
                    <dt class="font-medium text-slate-700">Representante</dt>
                    <dd><?= htmlspecialchars($patient['representative_name'] ?? '—') ?></dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="font-medium text-slate-700">Tel. representante</dt>
                    <dd class="text-right"><?= htmlspecialchars($patient['representative_phone'] ?? '—') ?></dd>
                </div>
                <div class="space-y-1">
                    <dt class="font-medium text-slate-700">Contacto emergencia</dt>
                    <dd><?= htmlspecialchars($patient['emergency_contact'] ?? '—') ?></dd>
                </div>
            </dl>
        </div>
        <div class="rounded-2xl border <?= $hasAlert ? 'border-amber-200/70 bg-amber-50/80' : 'border-emerald-200/70 bg-emerald-50/80' ?> p-5">
            <h3 class="text-xs font-semibold uppercase tracking-wide <?= $hasAlert ? 'text-amber-700' : 'text-emerald-700' ?>">Alertas y saldo</h3>
            <div class="mt-3 space-y-3 text-sm <?= $hasAlert ? 'text-amber-700' : 'text-emerald-700' ?>">
                <p><?= $hasAlert ? nl2br(htmlspecialchars((string) $alertText)) : 'Sin alertas registradas.' ?></p>
                <div class="inline-flex items-center gap-2 rounded-full bg-white/80 px-4 py-2 text-sm font-semibold <?= $hasAlert ? 'text-amber-700' : 'text-emerald-700' ?>">
                    Saldo pendiente: Bs <?= number_format(max($totalBalance, 0), 2, ',', '.') ?>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="rounded-3xl bg-white/95 p-6 shadow-sm shadow-slate-200/60 ring-1 ring-slate-200/70 sm:p-8 space-y-6" id="historia">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <h2 class="text-2xl font-semibold text-slate-900">Historia clínica</h2>
        <p class="text-sm text-slate-500">Actualiza los antecedentes y hallazgos para mantener un seguimiento integral.</p>
    </div>
    <form method="post" class="space-y-6">
        <input type="hidden" name="action" value="update_profile">

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
                    'antecedent_cardiovascular' => 'Cardiovascular',
                    'antecedent_respiratory' => 'Respiratorio',
                    'antecedent_gastrointestinal' => 'Gastrointestinal',
                    'antecedent_endocrine' => 'Endocrino',
                    'antecedent_renal' => 'Renal',
                    'antecedent_neurologic' => 'Neurológico',
                    'antecedent_allergy' => 'Alergias',
                    'antecedent_neoplastic' => 'Neoplásico',
                    'antecedent_hematologic' => 'Hematológico',
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
                    <textarea name="hospitalizations" rows="2" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"><?= htmlspecialchars($profile['hospitalizations'] ?? '') ?></textarea>
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Medicación actual</span>
                    <textarea name="medications" rows="2" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"><?= htmlspecialchars($profile['medications'] ?? '') ?></textarea>
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600 md:col-span-2">
                    <span class="font-medium text-slate-700">Antecedentes familiares</span>
                    <textarea name="family_history" rows="2" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"><?= htmlspecialchars($profile['family_history'] ?? '') ?></textarea>
                </label>
            </div>
        </fieldset>

        <fieldset class="rounded-2xl border border-slate-200/80 bg-slate-50/60 p-4 sm:p-6">
            <legend class="px-3 text-xs font-semibold uppercase tracking-wide text-brand-700">Examen clínico</legend>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Examen extraoral</span>
                    <textarea name="extraoral_exam" rows="2" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"><?= htmlspecialchars($profile['extraoral_exam'] ?? '') ?></textarea>
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Examen intraoral</span>
                    <textarea name="intraoral_exam" rows="2" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"><?= htmlspecialchars($profile['intraoral_exam'] ?? '') ?></textarea>
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600 md:col-span-2">
                    <span class="font-medium text-slate-700">Tejidos periodontales</span>
                    <textarea name="periodontal_status" rows="2" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"><?= htmlspecialchars($profile['periodontal_status'] ?? '') ?></textarea>
                </label>
            </div>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Dolor (0-10)</span>
                    <input type="number" name="pain_level" min="0" max="10" value="<?= htmlspecialchars((string) ($profile['pain_level'] ?? '')) ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Hábitos (bruxismo, tabaquismo...)</span>
                    <input type="text" name="habits" value="<?= htmlspecialchars($profile['habits'] ?? '') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Evaluación de riesgo</span>
                    <input type="text" name="risk_assessment" value="<?= htmlspecialchars($profile['risk_assessment'] ?? '') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
            </div>
        </fieldset>

        <fieldset class="rounded-2xl border border-slate-200/80 bg-white p-4 sm:p-6">
            <legend class="px-3 text-xs font-semibold uppercase tracking-wide text-brand-700">Diagnóstico y plan</legend>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Diagnóstico</span>
                    <textarea name="diagnosis" rows="2" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"><?= htmlspecialchars($profile['diagnosis'] ?? '') ?></textarea>
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Plan de tratamiento</span>
                    <textarea name="treatment_plan" rows="3" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"><?= htmlspecialchars($profile['treatment_plan'] ?? '') ?></textarea>
                </label>
            </div>
        </fieldset>

        <fieldset class="rounded-2xl border border-slate-200/80 bg-slate-50/60 p-4 sm:p-6">
            <legend class="px-3 text-xs font-semibold uppercase tracking-wide text-brand-700">Consentimiento informado</legend>
            <div class="mt-4 grid gap-4 md:grid-cols-[auto_minmax(0,1fr)]">
                <label class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-600 shadow-sm transition hover:border-brand-200">
                    <input type="checkbox" name="consent_signed" <?= !empty($profile['consent_signed']) ? 'checked' : '' ?> class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
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

        <div class="flex justify-end">
            <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:-translate-y-0.5 hover:bg-brand-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500">
                Guardar historia clínica
            </button>
        </div>
    </form>
</section>

<section class="rounded-3xl bg-white/95 p-6 shadow-sm shadow-slate-200/60 ring-1 ring-slate-200/70 sm:p-8 space-y-6" id="odontograma">
    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div class="space-y-1">
            <h2 class="text-2xl font-semibold text-slate-900">Odontograma evolutivo</h2>
            <p class="text-sm text-slate-500">Selecciona un color y, si lo necesitas, un trazo para marcar los cuadrantes de cada pieza y reflejar la evolución clínica.</p>
        </div>
        <p class="text-xs text-slate-400 md:text-right">La persistencia en SQLite se incorporará en la siguiente etapa.</p>
    </div>
    <div class="odontogram-wrapper space-y-6" data-odontogram='<?= htmlspecialchars(json_encode($odontogramData), ENT_QUOTES) ?>'>
        <div class="odontogram-toolbar flex flex-wrap items-center justify-between gap-6 rounded-2xl border border-slate-200/80 bg-white/90 p-4 shadow-sm">
            <div class="toolbar-group color-group flex items-center gap-3" role="radiogroup" aria-label="Seleccionar color">
                <span class="toolbar-label text-xs font-semibold uppercase tracking-wide text-slate-500">Color</span>
                <button type="button" class="tool-button color-option is-active" data-color="blue" aria-pressed="true">
                    <span class="sr-only">Azul</span>
                </button>
                <button type="button" class="tool-button color-option" data-color="red" aria-pressed="false">
                    <span class="sr-only">Rojo</span>
                </button>
            </div>
            <div class="toolbar-group mark-group flex flex-wrap items-center gap-3" role="radiogroup" aria-label="Seleccionar trazo opcional">
                <span class="toolbar-label text-xs font-semibold uppercase tracking-wide text-slate-500">Trazo (opcional)</span>
                <button type="button" class="tool-button mark-option" data-mark="dot" aria-pressed="false">
                    <span class="tool-glyph" aria-hidden="true"></span>
                    <span class="tool-name">Punto</span>
                </button>
                <button type="button" class="tool-button mark-option" data-mark="x" aria-pressed="false">
                    <span class="tool-glyph" aria-hidden="true"></span>
                    <span class="tool-name">Equis</span>
                </button>
                <button type="button" class="tool-button mark-option" data-mark="vertical" aria-pressed="false">
                    <span class="tool-glyph" aria-hidden="true"></span>
                    <span class="tool-name">Vertical</span>
                </button>
                <button type="button" class="tool-button mark-option" data-mark="horizontal" aria-pressed="false">
                    <span class="tool-glyph" aria-hidden="true"></span>
                    <span class="tool-name">Horizontal</span>
                </button>
                <button type="button" class="tool-button mark-option" data-mark="erase" aria-pressed="false">
                    <span class="tool-glyph tool-glyph--erase" aria-hidden="true"></span>
                    <span class="tool-name">Borrar</span>
                </button>
            </div>
        </div>
        <div class="odontogram-canvas space-y-10">
            <?php foreach ($odontogramGroups as $group): ?>
                <div class="odontogram-arch<?= $group['is_deciduous'] ? ' odontogram-arch--deciduous' : '' ?>">
                    <div class="odontogram-arch__header">
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-600"><?= htmlspecialchars($group['label']) ?></h3>
                    </div>
                    <div class="odontogram-row">
                        <?php foreach ($group['teeth'] as $tooth): ?>
                            <?php $renderToothCard($tooth, $odontogramSurfaces, $group['is_deciduous']); ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <p class="text-xs text-slate-500">Marca cada cuadrante con el color y figura seleccionados para documentar hallazgos, tratamientos o ausencias.</p>
</section>

<section class="rounded-3xl bg-white/95 p-6 shadow-sm shadow-slate-200/60 ring-1 ring-slate-200/70 sm:p-8 space-y-6" id="visitas">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <h2 class="text-2xl font-semibold text-slate-900">Evolución por visita</h2>
        <p class="text-sm text-slate-500">Registra consultas con formato SOAP y monitorea próximos seguimientos.</p>
    </div>
    <div class="grid gap-6 lg:grid-cols-[minmax(0,420px)_1fr]">
        <form method="post" class="space-y-4 rounded-2xl border border-slate-200/80 bg-slate-50/60 p-4 shadow-inner">
            <input type="hidden" name="action" value="create_visit">
            <label class="flex flex-col gap-2 text-sm text-slate-600">
                <span class="font-medium text-slate-700">Fecha de la consulta *</span>
                <input type="date" name="visit_date" required class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
            </label>
            <label class="flex flex-col gap-2 text-sm text-slate-600">
                <span class="font-medium text-slate-700">Motivo / síntomas (S)</span>
                <textarea name="subjective_notes" rows="2" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"></textarea>
            </label>
            <label class="flex flex-col gap-2 text-sm text-slate-600">
                <span class="font-medium text-slate-700">Hallazgos clínicos (O)</span>
                <textarea name="objective_notes" rows="2" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"></textarea>
            </label>
            <label class="flex flex-col gap-2 text-sm text-slate-600">
                <span class="font-medium text-slate-700">Evaluación / diagnósticos (A)</span>
                <textarea name="assessment" rows="2" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"></textarea>
            </label>
            <label class="flex flex-col gap-2 text-sm text-slate-600">
                <span class="font-medium text-slate-700">Plan inmediato / recomendaciones (P)</span>
                <textarea name="plan" rows="2" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"></textarea>
            </label>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">TA / PA</span>
                    <input type="text" name="vitals_bp" placeholder="Ej: 120/80" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">FC</span>
                    <input type="text" name="vitals_hr" placeholder="lat/min" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Temp</span>
                    <input type="text" name="vitals_temp" placeholder="°C" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">SpO₂</span>
                    <input type="text" name="vitals_oxygen" placeholder="%" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
            </div>
            <label class="flex flex-col gap-2 text-sm text-slate-600">
                <span class="font-medium text-slate-700">Próxima cita</span>
                <input type="date" name="next_appointment" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
            </label>
            <div class="flex justify-end">
                <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-full bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-soft transition hover:-translate-y-0.5 hover:bg-brand-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500">
                    Registrar visita
                </button>
            </div>
        </form>

        <div class="space-y-4 rounded-2xl border border-slate-200/80 bg-white p-4 sm:p-6 shadow-sm">
            <?php if (!$visits): ?>
                <p class="text-sm text-slate-500">Aún no hay visitas registradas.</p>
            <?php else: ?>
                <ul class="space-y-4">
                    <?php foreach ($visits as $visit): ?>
                        <li class="rounded-2xl border border-slate-200/80 bg-slate-50/70 p-4">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <p class="text-sm font-semibold text-slate-900"><?= date('d/m/Y', strtotime($visit['visit_date'])) ?></p>
                                    <?php if ($visit['next_appointment']): ?>
                                        <p class="text-xs font-medium text-brand-600">Próxima cita: <?= date('d/m/Y', strtotime($visit['next_appointment'])) ?></p>
                                    <?php endif; ?>
                                </div>
                                <form method="post" class="inline-flex" onsubmit="return confirm('¿Eliminar esta consulta?');">
                                    <input type="hidden" name="action" value="delete_visit">
                                    <input type="hidden" name="visit_id" value="<?= (int) $visit['id'] ?>">
                                    <button type="submit" class="inline-flex items-center rounded-full border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-600 transition hover:bg-rose-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-500">
                                        Eliminar
                                    </button>
                                </form>
                            </div>
                            <div class="mt-3 space-y-2 text-sm text-slate-600">
                                <?php if ($visit['subjective_notes']): ?>
                                    <p><span class="font-semibold text-slate-700">S:</span> <?= nl2br(htmlspecialchars($visit['subjective_notes'])) ?></p>
                                <?php endif; ?>
                                <?php if ($visit['objective_notes']): ?>
                                    <p><span class="font-semibold text-slate-700">O:</span> <?= nl2br(htmlspecialchars($visit['objective_notes'])) ?></p>
                                <?php endif; ?>
                                <?php if ($visit['assessment']): ?>
                                    <p><span class="font-semibold text-slate-700">A:</span> <?= nl2br(htmlspecialchars($visit['assessment'])) ?></p>
                                <?php endif; ?>
                                <?php if ($visit['plan']): ?>
                                    <p><span class="font-semibold text-slate-700">P:</span> <?= nl2br(htmlspecialchars($visit['plan'])) ?></p>
                                <?php endif; ?>
                                <?php
                                $vitalTokens = [];
                                if (!empty($visit['vitals_bp'])) {
                                    $vitalTokens[] = 'TA: ' . htmlspecialchars($visit['vitals_bp']);
                                }
                                if (!empty($visit['vitals_hr'])) {
                                    $vitalTokens[] = 'FC: ' . htmlspecialchars($visit['vitals_hr']);
                                }
                                if (!empty($visit['vitals_temp'])) {
                                    $vitalTokens[] = 'Temp: ' . htmlspecialchars($visit['vitals_temp']);
                                }
                                if (!empty($visit['vitals_oxygen'])) {
                                    $vitalTokens[] = 'SpO₂: ' . htmlspecialchars($visit['vitals_oxygen']);
                                }
                                ?>
                                <?php if ($vitalTokens): ?>
                                    <p class="text-xs font-medium text-slate-500"><?= implode(' · ', $vitalTokens) ?></p>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="rounded-3xl bg-white/95 p-6 shadow-sm shadow-slate-200/60 ring-1 ring-slate-200/70 sm:p-8 space-y-6" id="actividades">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <h2 class="text-2xl font-semibold text-slate-900">Actividades realizadas y control de pagos</h2>
        <p class="text-sm text-slate-500">Registra procedimientos, cobros y abonos asociados al tratamiento.</p>
    </div>
    <div class="grid gap-6 lg:grid-cols-[minmax(0,420px)_1fr]">
        <form method="post" class="space-y-4 rounded-2xl border border-slate-200/80 bg-slate-50/60 p-4 shadow-inner">
            <input type="hidden" name="action" value="create_activity">
            <label class="flex flex-col gap-2 text-sm text-slate-600">
                <span class="font-medium text-slate-700">Fecha *</span>
                <input type="date" name="activity_date" required class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
            </label>
            <label class="flex flex-col gap-2 text-sm text-slate-600">
                <span class="font-medium text-slate-700">Descripción *</span>
                <textarea name="description" rows="2" required class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"></textarea>
            </label>
            <label class="flex flex-col gap-2 text-sm text-slate-600">
                <span class="font-medium text-slate-700">Visita asociada</span>
                <select name="related_visit" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 focus:border-brand-400 focus:ring-brand-400">
                    <option value="">(Opcional)</option>
                    <?php foreach ($visits as $visit): ?>
                        <option value="<?= (int) $visit['id'] ?>"><?= date('d/m/Y', strtotime($visit['visit_date'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="grid gap-3 sm:grid-cols-3">
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Honorarios (Bs)</span>
                    <input type="number" step="0.01" name="fee" value="0" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Abono (Bs)</span>
                    <input type="number" step="0.01" name="payment" value="0" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Resta (Bs)</span>
                    <input type="number" step="0.01" name="balance" value="0" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
            </div>
            <label class="flex flex-col gap-2 text-sm text-slate-600">
                <span class="font-medium text-slate-700">Notas</span>
                <textarea name="activity_notes" rows="2" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"></textarea>
            </label>
            <div class="flex justify-end">
                <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-full bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-soft transition hover:-translate-y-0.5 hover:bg-brand-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500">
                    Agregar actividad
                </button>
            </div>
        </form>

        <div class="space-y-4 rounded-2xl border border-slate-200/80 bg-white p-4 sm:p-6 shadow-sm">
            <?php if (!$activities): ?>
                <p class="text-sm text-slate-500">No hay actividades registradas.</p>
            <?php else: ?>
                <div class="overflow-hidden rounded-2xl border border-slate-200/70 shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold">Fecha</th>
                                <th class="px-4 py-3 text-left font-semibold">Descripción</th>
                                <th class="px-4 py-3 text-right font-semibold">Honorarios</th>
                                <th class="px-4 py-3 text-right font-semibold">Abono</th>
                                <th class="px-4 py-3 text-right font-semibold">Resta</th>
                                <th class="px-4 py-3 text-right font-semibold">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <?php foreach ($activities as $activity): ?>
                                <tr class="hover:bg-slate-50/80">
                                    <td class="px-4 py-4 text-sm text-slate-600"><?= date('d/m/Y', strtotime($activity['activity_date'])) ?></td>
                                    <td class="px-4 py-4 text-sm text-slate-700">
                                        <?= nl2br(htmlspecialchars($activity['description'])) ?>
                                        <?php if ($activity['notes']): ?>
                                            <p class="mt-2 text-xs text-slate-500"><?= nl2br(htmlspecialchars($activity['notes'])) ?></p>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4 text-right text-sm font-semibold text-slate-700"><?= number_format((float) $activity['fee'], 2, ',', '.') ?></td>
                                    <td class="px-4 py-4 text-right text-sm font-semibold text-emerald-600"><?= number_format((float) $activity['payment'], 2, ',', '.') ?></td>
                                    <td class="px-4 py-4 text-right text-sm font-semibold text-amber-600"><?= number_format((float) $activity['balance'], 2, ',', '.') ?></td>
                                    <td class="px-4 py-4 text-right">
                                        <form method="post" class="inline-flex" onsubmit="return confirm('¿Eliminar esta actividad?');">
                                            <input type="hidden" name="action" value="delete_activity">
                                            <input type="hidden" name="activity_id" value="<?= (int) $activity['id'] ?>">
                                            <button class="inline-flex items-center rounded-full border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-600 transition hover:bg-rose-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-500" type="submit">
                                                Eliminar
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require __DIR__ . '/templates/footer.php'; ?>
