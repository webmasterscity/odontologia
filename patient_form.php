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
    $firstChar = mb_substr($trimmed, 0, 1, 'UTF-8');
    $rest = mb_substr($trimmed, 1, null, 'UTF-8');
    return mb_strtoupper($firstChar, 'UTF-8') . $rest;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

        header('Location: ' . $redirectTarget);
        exit;
    } else {
        $patient = array_merge($patient ?? [], $payload);
    }
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

    <form method="post" class="space-y-6" data-capitalize-initial-form>
        <?php
        $genders = ['Femenino', 'Masculino', 'No binario', 'Prefiere no indicarlo'];
        $maritalStatuses = ['Soltero(a)', 'Casado(a)', 'Unión estable', 'Divorciado(a)', 'Viudo(a)', 'Prefiere no indicarlo'];
        ?>
        <fieldset class="rounded-2xl border border-slate-200/80 bg-slate-50/60 p-4 sm:p-6">
            <legend class="px-3 text-xs font-semibold uppercase tracking-wide text-brand-700">Identificación</legend>
            <div class="mt-4 grid gap-4 md:grid-cols-2 lg:grid-cols-4">
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
    });
});
</script>

<?php require __DIR__ . '/templates/footer.php'; ?>
