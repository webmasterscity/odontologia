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
    <div class="alert success">
        <ul>
            <?php foreach ($messages as $message): ?>
                <li><?= htmlspecialchars($message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="alert error">
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<section class="panel panel-wide patient-summary">
    <div class="panel-header">
        <h2>Datos del paciente</h2>
        <div>
            <a class="button small secondary" href="patient_form.php?id=<?= $patientId ?>">Editar datos</a>
            <a class="button small" href="index.php">Volver al listado</a>
        </div>
    </div>
    <div class="summary-grid">
        <div>
            <h3><?= htmlspecialchars($patient['full_name']) ?></h3>
            <ul class="summary-list">
                <li><strong>Cédula:</strong> <?= htmlspecialchars($patient['document_id'] ?? '—') ?></li>
                <li><strong>Edad:</strong> <?= $patient['age'] ? (int) $patient['age'] . ' años' : '—' ?></li>
                <li><strong>Género:</strong> <?= htmlspecialchars($patient['gender'] ?? '—') ?></li>
                <li><strong>Dirección:</strong> <?= htmlspecialchars($patient['address'] ?? '—') ?></li>
            </ul>
        </div>
        <div>
            <h3>Contacto</h3>
            <ul class="summary-list">
                <li><strong>Correo:</strong> <?= htmlspecialchars($patient['email'] ?? '—') ?></li>
                <li><strong>Teléfono principal:</strong> <?= htmlspecialchars($patient['phone_primary'] ?? '—') ?></li>
                <li><strong>Teléfono alterno:</strong> <?= htmlspecialchars($patient['phone_secondary'] ?? '—') ?></li>
                <li><strong>Representante:</strong> <?= htmlspecialchars($patient['representative_name'] ?? '—') ?></li>
                <li><strong>Tel. representante:</strong> <?= htmlspecialchars($patient['representative_phone'] ?? '—') ?></li>
                <li><strong>Contacto emergencia:</strong> <?= htmlspecialchars($patient['emergency_contact'] ?? '—') ?></li>
            </ul>
        </div>
        <div>
            <h3>Alertas</h3>
            <p><?= nl2br(htmlspecialchars($profile['medical_alerts'] ?? $patient['notes'] ?? 'Sin alertas registradas.')) ?></p>
            <p><strong>Saldo pendiente:</strong> Bs <?= number_format(max($totalBalance, 0), 2, ',', '.') ?></p>
        </div>
    </div>
</section>

<section class="panel panel-wide" id="historia">
    <div class="panel-header">
        <h2>Historia clínica</h2>
    </div>
    <form method="post" class="form-grid">
        <input type="hidden" name="action" value="update_profile">
        <fieldset>
            <legend>Motivo de consulta</legend>
            <label>
                Motivo principal
                <textarea name="consultation_reason" rows="2"><?= htmlspecialchars($profile['consultation_reason'] ?? '') ?></textarea>
            </label>
            <label>
                Enfermedad actual / evolución
                <textarea name="current_condition" rows="3"><?= htmlspecialchars($profile['current_condition'] ?? '') ?></textarea>
            </label>
            <label>
                Alertas clínicas (alergias, riesgos)
                <textarea name="medical_alerts" rows="2"><?= htmlspecialchars($profile['medical_alerts'] ?? '') ?></textarea>
            </label>
        </fieldset>

        <fieldset>
            <legend>Antecedentes personales</legend>
            <div class="checkbox-grid">
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
                    <label class="checkbox">
                        <input type="checkbox" name="<?= $field ?>" <?= $checked ?>>
                        <span><?= htmlspecialchars($label) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <label>
                Hospitalizaciones / procedimientos
                <textarea name="hospitalizations" rows="2"><?= htmlspecialchars($profile['hospitalizations'] ?? '') ?></textarea>
            </label>
            <label>
                Medicación actual
                <textarea name="medications" rows="2"><?= htmlspecialchars($profile['medications'] ?? '') ?></textarea>
            </label>
            <label>
                Antecedentes familiares
                <textarea name="family_history" rows="2"><?= htmlspecialchars($profile['family_history'] ?? '') ?></textarea>
            </label>
        </fieldset>

        <fieldset>
            <legend>Examen clínico</legend>
            <label>
                Examen extraoral
                <textarea name="extraoral_exam" rows="2"><?= htmlspecialchars($profile['extraoral_exam'] ?? '') ?></textarea>
            </label>
            <label>
                Examen intraoral
                <textarea name="intraoral_exam" rows="2"><?= htmlspecialchars($profile['intraoral_exam'] ?? '') ?></textarea>
            </label>
            <label>
                Tejidos periodontales
                <textarea name="periodontal_status" rows="2"><?= htmlspecialchars($profile['periodontal_status'] ?? '') ?></textarea>
            </label>
            <div class="field-row">
                <label>
                    Dolor (0-10)
                    <input type="number" name="pain_level" min="0" max="10" value="<?= htmlspecialchars((string) ($profile['pain_level'] ?? '')) ?>">
                </label>
                <label>
                    Hábitos (bruxismo, tabaquismo...)
                    <input type="text" name="habits" value="<?= htmlspecialchars($profile['habits'] ?? '') ?>">
                </label>
                <label>
                    Evaluación de riesgo
                    <input type="text" name="risk_assessment" value="<?= htmlspecialchars($profile['risk_assessment'] ?? '') ?>">
                </label>
            </div>
        </fieldset>

        <fieldset>
            <legend>Diagnóstico y plan</legend>
            <label>
                Diagnóstico
                <textarea name="diagnosis" rows="2"><?= htmlspecialchars($profile['diagnosis'] ?? '') ?></textarea>
            </label>
            <label>
                Plan de tratamiento
                <textarea name="treatment_plan" rows="3"><?= htmlspecialchars($profile['treatment_plan'] ?? '') ?></textarea>
            </label>
        </fieldset>

        <fieldset>
            <legend>Consentimiento informado</legend>
            <div class="field-row">
                <label class="checkbox">
                    <input type="checkbox" name="consent_signed" <?= !empty($profile['consent_signed']) ? 'checked' : '' ?>>
                    <span>Consentimiento firmado</span>
                </label>
                <label>
                    Fecha de firma
                    <input type="date" name="consent_signed_at" value="<?= htmlspecialchars($profile['consent_signed_at'] ?? '') ?>">
                </label>
            </div>
            <label>
                Observaciones / condiciones especiales
                <textarea name="consent_notes" rows="2"><?= htmlspecialchars($profile['consent_notes'] ?? '') ?></textarea>
            </label>
        </fieldset>

        <div class="form-actions">
            <button type="submit" class="button primary">Guardar historia clínica</button>
        </div>
    </form>
</section>

<section class="panel panel-wide" id="odontograma">
    <div class="panel-header">
        <h2>Odontograma evolutivo</h2>
    </div>
    <div class="odontogram-wrapper" data-odontogram='<?= htmlspecialchars(json_encode($odontogramData), ENT_QUOTES) ?>'>
        <div class="odontogram">
            <div class="arch">
                <h3>Maxilar superior</h3>
                <div class="teeth-row">
                    <?php foreach (['18','17','16','15','14','13','12','11','21','22','23','24','25','26','27','28'] as $tooth): ?>
                        <button class="tooth" data-tooth="<?= $tooth ?>">
                            <?= $tooth ?>
                            <span class="status"></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="arch">
                <h3>Maxilar inferior</h3>
                <div class="teeth-row">
                    <?php foreach (['48','47','46','45','44','43','42','41','31','32','33','34','35','36','37','38'] as $tooth): ?>
                        <button class="tooth" data-tooth="<?= $tooth ?>">
                            <?= $tooth ?>
                            <span class="status"></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="arch">
                <h3>Dentición temporal</h3>
                <div class="teeth-row">
                    <?php foreach (['55','54','53','52','51','61','62','63','64','65','85','84','83','82','81','71','72','73','74','75'] as $tooth): ?>
                        <button class="tooth tooth-small" data-tooth="<?= $tooth ?>">
                            <?= $tooth ?>
                            <span class="status"></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <form method="post" class="odontogram-form">
            <input type="hidden" name="action" value="save_tooth">
            <input type="hidden" name="tooth_code" id="tooth_code" required>
            <label>
                Pieza dental seleccionada
                <input type="text" id="tooth_label" readonly>
            </label>
            <label>
                Estado
                <select name="status" id="tooth_status">
                    <?php foreach ($odontogramStatuses as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Observaciones
                <textarea name="note" id="tooth_note" rows="3"></textarea>
            </label>
            <div class="form-actions">
                <button type="submit" class="button primary">Guardar pieza</button>
            </div>
        </form>
    </div>
    <p class="help-text">Selecciona una pieza para ver y actualizar su estado. Los colores facilitan visualizar caries, restauraciones y ausencias.</p>
</section>

<section class="panel panel-wide" id="visitas">
    <div class="panel-header">
        <h2>Evolución por visita</h2>
    </div>
    <div class="two-columns">
        <form method="post" class="visit-form">
            <input type="hidden" name="action" value="create_visit">
            <label>
                Fecha de la consulta *
                <input type="date" name="visit_date" required>
            </label>
            <label>
                Motivo / síntomas (S)
                <textarea name="subjective_notes" rows="2"></textarea>
            </label>
            <label>
                Hallazgos clínicos (O)
                <textarea name="objective_notes" rows="2"></textarea>
            </label>
            <label>
                Evaluación / diagnósticos (A)
                <textarea name="assessment" rows="2"></textarea>
            </label>
            <label>
                Plan inmediato / recomendaciones (P)
                <textarea name="plan" rows="2"></textarea>
            </label>
            <div class="field-row">
                <label>TA / PA
                    <input type="text" name="vitals_bp" placeholder="Ej: 120/80">
                </label>
                <label>FC
                    <input type="text" name="vitals_hr" placeholder="lat/min">
                </label>
                <label>Temp
                    <input type="text" name="vitals_temp" placeholder="°C">
                </label>
                <label>SpO₂
                    <input type="text" name="vitals_oxygen" placeholder="%">
                </label>
            </div>
            <label>
                Próxima cita
                <input type="date" name="next_appointment">
            </label>
            <div class="form-actions">
                <button type="submit" class="button primary">Registrar visita</button>
            </div>
        </form>

        <div class="visit-timeline">
            <?php if (!$visits): ?>
                <p class="empty">Aún no hay visitas registradas.</p>
            <?php else: ?>
                <ul class="timeline">
                    <?php foreach ($visits as $visit): ?>
                        <li>
                            <div class="timeline-head">
                                <span class="timeline-date"><?= date('d/m/Y', strtotime($visit['visit_date'])) ?></span>
                                <form method="post" onsubmit="return confirm('¿Eliminar esta consulta?');">
                                    <input type="hidden" name="action" value="delete_visit">
                                    <input type="hidden" name="visit_id" value="<?= (int) $visit['id'] ?>">
                                    <button type="submit" class="button small danger">Eliminar</button>
                                </form>
                            </div>
                            <div class="timeline-body">
                                <?php if ($visit['subjective_notes']): ?>
                                    <p><strong>S:</strong> <?= nl2br(htmlspecialchars($visit['subjective_notes'])) ?></p>
                                <?php endif; ?>
                                <?php if ($visit['objective_notes']): ?>
                                    <p><strong>O:</strong> <?= nl2br(htmlspecialchars($visit['objective_notes'])) ?></p>
                                <?php endif; ?>
                                <?php if ($visit['assessment']): ?>
                                    <p><strong>A:</strong> <?= nl2br(htmlspecialchars($visit['assessment'])) ?></p>
                                <?php endif; ?>
                                <?php if ($visit['plan']): ?>
                                    <p><strong>P:</strong> <?= nl2br(htmlspecialchars($visit['plan'])) ?></p>
                                <?php endif; ?>
                                <?php if ($visit['vitals_bp'] || $visit['vitals_hr'] || $visit['vitals_temp'] || $visit['vitals_oxygen']): ?>
                                    <p class="vitals">
                                        <?php if ($visit['vitals_bp']): ?>TA: <?= htmlspecialchars($visit['vitals_bp']) ?><?php endif; ?>
                                        <?php if ($visit['vitals_hr']): ?> · FC: <?= htmlspecialchars($visit['vitals_hr']) ?><?php endif; ?>
                                        <?php if ($visit['vitals_temp']): ?> · Temp: <?= htmlspecialchars($visit['vitals_temp']) ?><?php endif; ?>
                                        <?php if ($visit['vitals_oxygen']): ?> · SpO₂: <?= htmlspecialchars($visit['vitals_oxygen']) ?><?php endif; ?>
                                    </p>
                                <?php endif; ?>
                                <?php if ($visit['next_appointment']): ?>
                                    <p class="next-appointment">Próxima cita: <?= date('d/m/Y', strtotime($visit['next_appointment'])) ?></p>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="panel panel-wide" id="actividades">
    <div class="panel-header">
        <h2>Actividades realizadas y control de pagos</h2>
    </div>
    <div class="two-columns">
        <form method="post" class="activity-form">
            <input type="hidden" name="action" value="create_activity">
            <label>
                Fecha *
                <input type="date" name="activity_date" required>
            </label>
            <label>
                Descripción *
                <textarea name="description" rows="2" required></textarea>
            </label>
            <label>
                Visita asociada
                <select name="related_visit">
                    <option value="">(Opcional)</option>
                    <?php foreach ($visits as $visit): ?>
                        <option value="<?= (int) $visit['id'] ?>">
                            <?= date('d/m/Y', strtotime($visit['visit_date'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="field-row">
                <label>
                    Honorarios (Bs)
                    <input type="number" step="0.01" name="fee" value="0">
                </label>
                <label>
                    Abono (Bs)
                    <input type="number" step="0.01" name="payment" value="0">
                </label>
                <label>
                    Resta (Bs)
                    <input type="number" step="0.01" name="balance" value="0">
                </label>
            </div>
            <label>
                Notas
                <textarea name="activity_notes" rows="2"></textarea>
            </label>
            <div class="form-actions">
                <button type="submit" class="button primary">Agregar actividad</button>
            </div>
        </form>

        <div class="activity-table">
            <?php if (!$activities): ?>
                <p class="empty">No hay actividades registradas.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Descripción</th>
                                <th>Honorarios</th>
                                <th>Abono</th>
                                <th>Resta</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activities as $activity): ?>
                                <tr>
                                    <td><?= date('d/m/Y', strtotime($activity['activity_date'])) ?></td>
                                    <td>
                                        <?= nl2br(htmlspecialchars($activity['description'])) ?>
                                        <?php if ($activity['notes']): ?>
                                            <p class="small muted"><?= nl2br(htmlspecialchars($activity['notes'])) ?></p>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= number_format((float) $activity['fee'], 2, ',', '.') ?></td>
                                    <td><?= number_format((float) $activity['payment'], 2, ',', '.') ?></td>
                                    <td><?= number_format((float) $activity['balance'], 2, ',', '.') ?></td>
                                    <td class="table-actions">
                                        <form method="post" onsubmit="return confirm('¿Eliminar esta actividad?');">
                                            <input type="hidden" name="action" value="delete_activity">
                                            <input type="hidden" name="activity_id" value="<?= (int) $activity['id'] ?>">
                                            <button class="button small danger" type="submit">Eliminar</button>
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
