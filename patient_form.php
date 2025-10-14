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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim((string) post('full_name'));
    if ($fullName === '') {
        $errors[] = 'El nombre del paciente es obligatorio.';
    }

    $birthDate = normalizeDate(post('birth_date'));
    $payload = [
        'full_name' => $fullName,
        'document_id' => trim((string) post('document_id')) ?: null,
        'birth_date' => $birthDate,
        'age' => calculateAge($birthDate),
        'gender' => post('gender') ?: null,
        'address' => trim((string) post('address')) ?: null,
        'email' => trim((string) post('email')) ?: null,
        'phone_primary' => trim((string) post('phone_primary')) ?: null,
        'phone_secondary' => trim((string) post('phone_secondary')) ?: null,
        'representative_name' => trim((string) post('representative_name')) ?: null,
        'representative_document' => trim((string) post('representative_document')) ?: null,
        'representative_phone' => trim((string) post('representative_phone')) ?: null,
        'emergency_contact' => trim((string) post('emergency_contact')) ?: null,
        'notes' => trim((string) post('notes')) ?: null,
    ];

    if (empty($errors)) {
        if ($patientId) {
            updateById($pdo, 'patients', $payload, $patientId);
            $targetId = $patientId;
        } else {
            $targetId = insertRow($pdo, 'patients', $payload);
        }

        // Garantiza que exista el registro de perfil clínico.
        $pdo->prepare(
            'INSERT OR IGNORE INTO clinical_profiles (patient_id) VALUES (:patient_id)'
        )->execute([':patient_id' => $targetId]);

        header('Location: patient.php?id=' . $targetId);
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

    <form method="post" class="space-y-6">
        <fieldset class="rounded-2xl border border-slate-200/80 bg-slate-50/60 p-4 sm:p-6">
            <legend class="px-3 text-xs font-semibold uppercase tracking-wide text-brand-700">Identificación</legend>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <label class="flex flex-col gap-2 text-sm text-slate-600 md:col-span-2">
                    <span class="font-medium text-slate-700">Nombre y apellidos *</span>
                    <input type="text" name="full_name" required value="<?= htmlspecialchars($patient['full_name'] ?? '') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Cédula / Documento</span>
                    <input type="text" name="document_id" value="<?= htmlspecialchars($patient['document_id'] ?? '') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
                <div class="grid gap-4 md:grid-cols-2 md:col-span-2">
                    <label class="flex flex-col gap-2 text-sm text-slate-600">
                        <span class="font-medium text-slate-700">Fecha de nacimiento</span>
                        <input type="date" name="birth_date" value="<?= htmlspecialchars($patient['birth_date'] ?? '') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                    </label>
                    <label class="flex flex-col gap-2 text-sm text-slate-600">
                        <span class="font-medium text-slate-700">Edad</span>
                        <input type="number" name="age_display" value="<?= htmlspecialchars($patient['age'] ?? '') ?>" readonly class="rounded-2xl border border-slate-200 bg-slate-100 px-4 py-2.5 text-slate-700 shadow-inner">
                    </label>
                </div>
                <label class="flex flex-col gap-2 text-sm text-slate-600 md:col-span-2 md:max-w-xs">
                    <span class="font-medium text-slate-700">Género</span>
                    <select name="gender" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 focus:border-brand-400 focus:ring-brand-400">
                        <option value="">Selecciona</option>
                        <?php
                        $genders = ['Femenino', 'Masculino', 'No binario', 'Prefiere no indicarlo'];
                        foreach ($genders as $genderOption):
                            $selected = ($patient['gender'] ?? '') === $genderOption ? 'selected' : '';
                            ?>
                            <option value="<?= htmlspecialchars($genderOption) ?>" <?= $selected ?>>
                                <?= htmlspecialchars($genderOption) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
        </fieldset>

        <fieldset class="rounded-2xl border border-slate-200/80 bg-slate-50/60 p-4 sm:p-6">
            <legend class="px-3 text-xs font-semibold uppercase tracking-wide text-brand-700">Contacto</legend>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <label class="flex flex-col gap-2 text-sm text-slate-600 md:col-span-2">
                    <span class="font-medium text-slate-700">Dirección</span>
                    <textarea name="address" rows="2" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"><?= htmlspecialchars($patient['address'] ?? '') ?></textarea>
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
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Representante (si aplica)</span>
                    <input type="text" name="representative_name" value="<?= htmlspecialchars($patient['representative_name'] ?? '') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Cédula del representante</span>
                    <input type="text" name="representative_document" value="<?= htmlspecialchars($patient['representative_document'] ?? '') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Teléfono del representante</span>
                    <input type="tel" name="representative_phone" value="<?= htmlspecialchars($patient['representative_phone'] ?? '') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600 md:col-span-2">
                    <span class="font-medium text-slate-700">Contacto de emergencia</span>
                    <input type="text" name="emergency_contact" value="<?= htmlspecialchars($patient['emergency_contact'] ?? '') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
            </div>
        </fieldset>

        <fieldset class="rounded-2xl border border-slate-200/80 bg-slate-50/60 p-4 sm:p-6">
            <legend class="px-3 text-xs font-semibold uppercase tracking-wide text-brand-700">Anotaciones</legend>
            <label class="mt-4 flex flex-col gap-2 text-sm text-slate-600">
                <span class="font-medium text-slate-700">Notas relevantes / alertas clínicas</span>
                <textarea name="notes" rows="3" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"><?= htmlspecialchars($patient['notes'] ?? '') ?></textarea>
            </label>
        </fieldset>

        <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
            <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:-translate-y-0.5 hover:bg-brand-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500">
                <?= $patientId ? 'Guardar cambios' : 'Crear paciente' ?>
            </button>
            <a class="inline-flex items-center justify-center gap-2 rounded-full border border-slate-200 px-5 py-2.5 text-sm font-semibold text-slate-600 transition hover:border-rose-200 hover:text-rose-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-500" href="<?= $patientId ? 'patient.php?id=' . $patientId : 'index.php' ?>">
                Cancelar
            </a>
        </div>
    </form>
</section>

<?php require __DIR__ . '/templates/footer.php'; ?>
