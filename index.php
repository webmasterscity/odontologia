<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/database.php';

$monthNames = [
    1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
    5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
    9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'
];
$currentMonthLabel = $monthNames[(int) date('n')] ?? date('m');

$messages = [];
$errors = [];

// Handle database restore uploads before touching the current connection.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'restore_backup') {
    if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'No se pudo subir el archivo de respaldo. Inténtalo nuevamente.';
    } else {
        $tmpPath = $_FILES['backup_file']['tmp_name'];
        $backupDir = __DIR__ . '/data/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0775, true);
        }
        $destination = __DIR__ . '/data/clinic.sqlite';
        $timestamp = date('Ymd_His');
        if (file_exists($destination)) {
            copy($destination, $backupDir . "/clinic_before_restore_{$timestamp}.sqlite");
        }
        if (!copy($tmpPath, $destination)) {
            $errors[] = 'No se pudo restaurar la base de datos. Verifica los permisos.';
        } else {
            chmod($destination, 0664);
            $messages[] = 'Respaldo restaurado correctamente.';
        }
    }
}

$pdo = db();

$search = trim($_GET['search'] ?? '');
$searchSql = '';
$params = [];
if ($search !== '') {
    $searchSql = 'WHERE p.full_name LIKE :term OR p.document_id LIKE :term OR p.phone_primary LIKE :term';
    $params[':term'] = '%' . $search . '%';
}

$patientStmt = $pdo->prepare(
    "SELECT p.*,
        (
            SELECT MAX(visit_date)
            FROM visits v
            WHERE v.patient_id = p.id
        ) AS last_visit,
        (
            SELECT SUM(fee - payment)
            FROM treatment_activities ta
            WHERE ta.patient_id = p.id
        ) AS pending_balance
     FROM patients p
     $searchSql
     ORDER BY p.updated_at DESC, p.full_name ASC"
);
$patientStmt->execute($params);
$patients = $patientStmt->fetchAll(PDO::FETCH_ASSOC);

$totalPatients = (int) $pdo->query('SELECT COUNT(*) FROM patients')->fetchColumn();
$totalVisitsThisMonth = (int) $pdo->query(
    "SELECT COUNT(*) FROM visits WHERE strftime('%Y-%m', visit_date) = strftime('%Y-%m', 'now', 'localtime')"
)->fetchColumn();
$pendingBalance = (float) $pdo->query(
    'SELECT COALESCE(SUM(fee - payment), 0) FROM treatment_activities'
)->fetchColumn();
$upcomingStmt = $pdo->prepare(
    "SELECT v.*, p.full_name
     FROM visits v
     JOIN patients p ON p.id = v.patient_id
     WHERE v.next_appointment IS NOT NULL
       AND date(v.next_appointment) >= date('now', 'localtime')
     ORDER BY v.next_appointment ASC
     LIMIT 5"
);
$upcomingStmt->execute();
$upcomingAppointments = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Pacientes · Consultorio Odontológico';
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

<section class="dashboard">
    <div class="stat-card">
        <h2>Total de pacientes</h2>
        <p><?= number_format($totalPatients) ?></p>
    </div>
    <div class="stat-card">
        <h2>Consultas en <?= htmlspecialchars(ucfirst($currentMonthLabel) . ' ' . date('Y')) ?></h2>
        <p><?= number_format($totalVisitsThisMonth) ?></p>
    </div>
    <div class="stat-card">
        <h2>Saldo pendiente</h2>
        <p>Bs <?= number_format($pendingBalance, 2, ',', '.') ?></p>
    </div>
    <div class="stat-card">
        <h2>Próximas citas</h2>
        <p><?= count($upcomingAppointments) ?></p>
    </div>
</section>

<section class="panel">
    <div class="panel-header">
        <h2>Listado de pacientes</h2>
        <div class="actions">
            <form method="get" class="inline-form">
                <input type="search" name="search" placeholder="Buscar por nombre, cédula o teléfono" value="<?= htmlspecialchars($search) ?>">
                <button type="submit">Buscar</button>
            </form>
            <a class="button primary" href="patient_form.php">➕ Nuevo paciente</a>
        </div>
    </div>
    <?php if (empty($patients)): ?>
        <p class="empty">No hay pacientes registrados todavía.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Paciente</th>
                        <th>Cédula</th>
                        <th>Edad</th>
                        <th>Teléfono</th>
                        <th>Última visita</th>
                        <th>Saldo</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($patients as $patient): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($patient['full_name']) ?></strong>
                                <?php if (!empty($patient['notes'])): ?>
                                    <span class="tag tag-alert">Alerta clínica</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($patient['document_id'] ?? '—') ?></td>
                            <td><?= $patient['age'] ? (int) $patient['age'] . ' años' : '—' ?></td>
                            <td><?= htmlspecialchars($patient['phone_primary'] ?? '—') ?></td>
                            <td><?= $patient['last_visit'] ? date('d/m/Y', strtotime($patient['last_visit'])) : '—' ?></td>
                            <td class="<?= ($patient['pending_balance'] ?? 0) > 0 ? 'text-warning' : '' ?>">
                                <?= number_format((float) ($patient['pending_balance'] ?? 0), 2, ',', '.') ?>
                            </td>
                            <td class="table-actions">
                                <a href="patient.php?id=<?= (int) $patient['id'] ?>" class="button small">Ver</a>
                                <a href="patient_form.php?id=<?= (int) $patient['id'] ?>" class="button small secondary">Editar</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel" id="citas">
    <div class="panel-header">
        <h2>Próximas citas</h2>
    </div>
    <?php if (!$upcomingAppointments): ?>
        <p class="empty">No hay citas programadas.</p>
    <?php else: ?>
        <ul class="timeline">
            <?php foreach ($upcomingAppointments as $appointment): ?>
                <li>
                    <span class="timeline-date"><?= date('d/m/Y', strtotime($appointment['next_appointment'])) ?></span>
                    <div>
                        <strong><?= htmlspecialchars($appointment['full_name']) ?></strong>
                        <?php if (!empty($appointment['plan'])): ?>
                            <p><?= nl2br(htmlspecialchars($appointment['plan'])) ?></p>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="panel" id="respaldo">
    <div class="panel-header">
        <h2>Respaldo y restauración</h2>
    </div>
    <div class="backup-actions">
        <a class="button primary" href="backup.php">⬇️ Descargar respaldo (.sqlite)</a>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="restore_backup">
            <label class="file-input">
                <span>Seleccionar archivo .sqlite</span>
                <input type="file" name="backup_file" accept=".sqlite,.db,.sqlite3" required>
            </label>
            <button type="submit" class="button secondary">Restaurar</button>
            <p class="help-text">Se guardará automáticamente una copia del archivo actual antes de reemplazarlo.</p>
        </form>
    </div>
</section>

<?php require __DIR__ . '/templates/footer.php'; ?>
