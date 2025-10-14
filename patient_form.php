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

<section class="panel panel-wide">
    <div class="panel-header">
        <h2><?= $patientId ? 'Actualizar datos del paciente' : 'Registrar nuevo paciente' ?></h2>
        <a class="button secondary" href="index.php">Volver</a>
    </div>

    <?php if ($errors): ?>
        <div class="alert error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" class="form-grid">
        <fieldset>
            <legend>Identificación</legend>
            <label>
                Nombre y apellidos *
                <input type="text" name="full_name" required value="<?= htmlspecialchars($patient['full_name'] ?? '') ?>">
            </label>
            <label>
                Cédula / Documento
                <input type="text" name="document_id" value="<?= htmlspecialchars($patient['document_id'] ?? '') ?>">
            </label>
            <div class="field-row">
                <label>
                    Fecha de nacimiento
                    <input type="date" name="birth_date" value="<?= htmlspecialchars($patient['birth_date'] ?? '') ?>">
                </label>
                <label>
                    Edad
                    <input type="number" name="age_display" value="<?= htmlspecialchars($patient['age'] ?? '') ?>" readonly>
                </label>
            </div>
            <label>
                Género
                <select name="gender">
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
        </fieldset>

        <fieldset>
            <legend>Contacto</legend>
            <label>
                Dirección
                <textarea name="address" rows="2"><?= htmlspecialchars($patient['address'] ?? '') ?></textarea>
            </label>
            <label>
                Correo electrónico
                <input type="email" name="email" value="<?= htmlspecialchars($patient['email'] ?? '') ?>">
            </label>
            <div class="field-row">
                <label>
                    Teléfono principal
                    <input type="tel" name="phone_primary" value="<?= htmlspecialchars($patient['phone_primary'] ?? '') ?>">
                </label>
                <label>
                    Teléfono alterno
                    <input type="tel" name="phone_secondary" value="<?= htmlspecialchars($patient['phone_secondary'] ?? '') ?>">
                </label>
            </div>
            <label>
                Representante (si aplica)
                <input type="text" name="representative_name" value="<?= htmlspecialchars($patient['representative_name'] ?? '') ?>">
            </label>
            <label>
                Teléfono del representante
                <input type="tel" name="representative_phone" value="<?= htmlspecialchars($patient['representative_phone'] ?? '') ?>">
            </label>
            <label>
                Contacto de emergencia
                <input type="text" name="emergency_contact" value="<?= htmlspecialchars($patient['emergency_contact'] ?? '') ?>">
            </label>
        </fieldset>

        <fieldset>
            <legend>Anotaciones</legend>
            <label>
                Notas relevantes / alertas clínicas
                <textarea name="notes" rows="3"><?= htmlspecialchars($patient['notes'] ?? '') ?></textarea>
            </label>
        </fieldset>

        <div class="form-actions">
            <button type="submit" class="button primary"><?= $patientId ? 'Guardar cambios' : 'Crear paciente' ?></button>
            <a class="button secondary" href="<?= $patientId ? 'patient.php?id=' . $patientId : 'index.php' ?>">Cancelar</a>
        </div>
    </form>
</section>

<?php require __DIR__ . '/templates/footer.php'; ?>
