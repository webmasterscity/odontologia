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

<section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <article class="group rounded-2xl bg-white p-6 shadow-sm shadow-slate-200/70 ring-1 ring-slate-200/70 transition duration-200 hover:-translate-y-1 hover:shadow-lg">
        <div class="flex items-center justify-between">
            <p class="text-sm font-medium text-slate-500">Total de pacientes</p>
            <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-brand-50 text-lg">👥</span>
        </div>
        <p class="mt-4 text-3xl font-semibold text-slate-900"><?= number_format($totalPatients) ?></p>
        <p class="mt-2 text-xs uppercase tracking-wide text-slate-400">Registros activos</p>
    </article>
    <article class="group rounded-2xl bg-white p-6 shadow-sm shadow-slate-200/70 ring-1 ring-slate-200/70 transition duration-200 hover:-translate-y-1 hover:shadow-lg">
        <div class="flex items-center justify-between">
            <p class="text-sm font-medium text-slate-500">Consultas en <?= htmlspecialchars(ucfirst($currentMonthLabel) . ' ' . date('Y')) ?></p>
            <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-brand-50 text-lg">📅</span>
        </div>
        <p class="mt-4 text-3xl font-semibold text-slate-900"><?= number_format($totalVisitsThisMonth) ?></p>
        <p class="mt-2 text-xs uppercase tracking-wide text-slate-400">Control mensual</p>
    </article>
    <article class="group rounded-2xl bg-white p-6 shadow-sm shadow-slate-200/70 ring-1 ring-slate-200/70 transition duration-200 hover:-translate-y-1 hover:shadow-lg">
        <div class="flex items-center justify-between">
            <p class="text-sm font-medium text-slate-500">Saldo pendiente</p>
            <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-brand-50 text-lg">💳</span>
        </div>
        <p class="mt-4 text-3xl font-semibold text-slate-900">Bs <?= number_format($pendingBalance, 2, ',', '.') ?></p>
        <p class="mt-2 text-xs uppercase tracking-wide text-slate-400">Cuentas por cobrar</p>
    </article>
    <article class="group rounded-2xl bg-white p-6 shadow-sm shadow-slate-200/70 ring-1 ring-slate-200/70 transition duration-200 hover:-translate-y-1 hover:shadow-lg">
        <div class="flex items-center justify-between">
            <p class="text-sm font-medium text-slate-500">Próximas citas</p>
            <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-brand-50 text-lg">⏰</span>
        </div>
        <p class="mt-4 text-3xl font-semibold text-slate-900"><?= count($upcomingAppointments) ?></p>
        <p class="mt-2 text-xs uppercase tracking-wide text-slate-400">Seguimiento inmediato</p>
    </article>
</section>

<section class="rounded-3xl bg-white/90 p-6 shadow-sm shadow-slate-200/60 ring-1 ring-slate-200/70 sm:p-8">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h2 class="text-xl font-semibold text-slate-900">Listado de pacientes</h2>
            <p class="mt-1 text-sm text-slate-500">Filtra por nombre, cédula o teléfono para ubicar rápidamente a tus pacientes.</p>
        </div>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
            <form method="get" class="flex w-full max-w-md items-center gap-2 rounded-full border border-slate-200 bg-slate-50/60 px-4 py-1.5 text-sm shadow-inner focus-within:border-brand-300 focus-within:bg-white sm:py-2">
                <label for="search" class="sr-only">Buscar paciente</label>
                <input id="search" type="search" name="search" placeholder="Buscar por nombre, cédula o teléfono" value="<?= htmlspecialchars($search) ?>" class="flex-1 border-0 bg-transparent py-1 text-sm text-slate-700 placeholder:text-slate-400 focus:ring-0" />
                <button type="submit" class="inline-flex items-center rounded-full bg-brand-600 px-4 py-1.5 text-sm font-semibold text-white shadow-soft transition hover:bg-brand-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500">
                    Buscar
                </button>
            </form>
            <a class="inline-flex items-center justify-center gap-2 rounded-full bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-soft transition hover:-translate-y-0.5 hover:bg-slate-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900" href="patient_form.php">
                <span class="text-base">➕</span>
                Nuevo paciente
            </a>
        </div>
    </div>

    <?php if (empty($patients)): ?>
        <p class="mt-6 rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-6 py-10 text-center text-sm text-slate-500">
            No hay pacientes registrados todavía.
        </p>
    <?php else: ?>
        <div class="mt-6 overflow-hidden rounded-2xl border border-slate-200/70 shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-3 text-left font-semibold">Paciente</th>
                        <th scope="col" class="px-4 py-3 text-left font-semibold">Cédula</th>
                        <th scope="col" class="px-4 py-3 text-left font-semibold">Edad</th>
                        <th scope="col" class="px-4 py-3 text-left font-semibold">Teléfono</th>
                        <th scope="col" class="px-4 py-3 text-left font-semibold">Última visita</th>
                        <th scope="col" class="px-4 py-3 text-left font-semibold">Saldo</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    <?php foreach ($patients as $patient): ?>
                        <?php $balance = (float) ($patient['pending_balance'] ?? 0); ?>
                        <tr class="transition hover:bg-slate-50/80">
                            <td class="px-4 py-4">
                                <p class="font-semibold text-slate-900"><?= htmlspecialchars($patient['full_name']) ?></p>
                                <?php if (!empty($patient['notes'])): ?>
                                    <span class="mt-1 inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-700">
                                        <span aria-hidden="true">⚠️</span>
                                        Alerta clínica
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-4 text-sm text-slate-600">
                                <?= htmlspecialchars($patient['document_id'] ?? '—') ?>
                            </td>
                            <td class="px-4 py-4 text-sm text-slate-600">
                                <?= $patient['age'] ? (int) $patient['age'] . ' años' : '—' ?>
                            </td>
                            <td class="px-4 py-4 text-sm text-slate-600">
                                <?= htmlspecialchars($patient['phone_primary'] ?? '—') ?>
                            </td>
                            <td class="px-4 py-4 text-sm text-slate-600">
                                <?= $patient['last_visit'] ? date('d/m/Y', strtotime($patient['last_visit'])) : '—' ?>
                            </td>
                            <td class="px-4 py-4 text-sm font-semibold <?= $balance > 0 ? 'text-amber-600' : 'text-slate-700' ?>">
                                <?= number_format($balance, 2, ',', '.') ?>
                            </td>
                            <td class="px-4 py-4 text-right">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <a href="patient.php?id=<?= (int) $patient['id'] ?>" class="inline-flex items-center rounded-full bg-brand-600 px-3.5 py-1.5 text-xs font-semibold text-white shadow-soft transition hover:bg-brand-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500">
                                        Ver ficha
                                    </a>
                                    <a href="patient_form.php?id=<?= (int) $patient['id'] ?>" class="inline-flex items-center rounded-full border border-slate-200 px-3.5 py-1.5 text-xs font-semibold text-slate-600 transition hover:border-brand-200 hover:text-brand-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500">
                                        Editar
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="rounded-3xl bg-white/90 p-6 shadow-sm shadow-slate-200/60 ring-1 ring-slate-200/70 sm:p-8" id="citas">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <h2 class="text-xl font-semibold text-slate-900">Próximas citas</h2>
        <p class="text-sm text-slate-500">Visualiza los compromisos más cercanos para preparar al equipo.</p>
    </div>
    <?php if (!$upcomingAppointments): ?>
        <p class="mt-6 rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-6 py-10 text-center text-sm text-slate-500">
            No hay citas programadas.
        </p>
    <?php else: ?>
        <ul class="mt-6 space-y-4">
            <?php foreach ($upcomingAppointments as $appointment): ?>
                <?php $appointmentTs = strtotime($appointment['next_appointment']); ?>
                <li class="flex items-start gap-4 rounded-2xl border border-slate-200/70 bg-slate-50/70 p-4">
                    <span class="inline-flex h-12 w-12 flex-none items-center justify-center rounded-full bg-brand-500/10 text-sm font-semibold text-brand-600">
                        <?= date('d/m', $appointmentTs) ?>
                    </span>
                    <div class="space-y-1">
                        <p class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($appointment['full_name']) ?></p>
                        <?php if (!empty($appointment['plan'])): ?>
                            <p class="text-sm text-slate-600"><?= nl2br(htmlspecialchars($appointment['plan'])) ?></p>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="rounded-3xl bg-gradient-to-br from-brand-50 via-white to-brand-50/70 p-6 shadow-sm shadow-brand-100/60 ring-1 ring-brand-100/70 sm:p-8" id="respaldo">
    <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div class="space-y-2">
            <h2 class="text-xl font-semibold text-slate-900">Respaldo y restauración</h2>
            <p class="text-sm text-slate-600">Genera un respaldo local o restaura una copia existente de forma segura.</p>
        </div>
        <a class="inline-flex items-center justify-center gap-2 rounded-full bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-soft transition hover:-translate-y-0.5 hover:bg-brand-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500" href="backup.php">
            <span class="text-base">⬇️</span>
            Descargar respaldo (.sqlite)
        </a>
    </div>
    <form method="post" enctype="multipart/form-data" class="mt-6 grid gap-4 rounded-2xl border border-dashed border-brand-200 bg-white/90 p-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:gap-6">
        <input type="hidden" name="action" value="restore_backup">
        <label class="text-sm font-medium text-slate-600">
            <span class="mb-2 block text-slate-500">Seleccionar archivo .sqlite</span>
            <input type="file" name="backup_file" accept=".sqlite,.db,.sqlite3" required class="block w-full cursor-pointer rounded-2xl border border-dashed border-brand-200 bg-brand-50/70 px-4 py-3 text-sm text-slate-600 shadow-inner focus:border-brand-400 focus:ring-brand-400">
        </label>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end">
            <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-full bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-soft transition hover:bg-slate-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                Restaurar
            </button>
            <p class="text-sm text-slate-500 sm:text-right">Se guardará automáticamente una copia del archivo actual antes de reemplazarlo.</p>
        </div>
    </form>
</section>

<?php require __DIR__ . '/templates/footer.php'; ?>
