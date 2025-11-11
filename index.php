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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete_patient') {
    $patientId = (int) post('patient_id');
    if ($patientId <= 0) {
        $errors[] = 'No se pudo eliminar el paciente: identificador inválido.';
    } else {
        $patientNameStmt = $pdo->prepare('SELECT full_name FROM patients WHERE id = :id');
        $patientNameStmt->execute([':id' => $patientId]);
        $patientName = $patientNameStmt->fetchColumn();
        if (is_string($patientName)) {
            $patientName = trim($patientName);
        }

        if (!$patientName) {
            $errors[] = 'El paciente seleccionado ya no existe.';
        } else {
            $deleteStmt = $pdo->prepare('DELETE FROM patients WHERE id = :id');
            $deleteStmt->execute([':id' => $patientId]);

            if ($deleteStmt->rowCount() > 0) {
                $messages[] = 'Paciente eliminado: ' . $patientName . '.';
            } else {
                $errors[] = 'No se pudo eliminar el paciente. Inténtalo nuevamente.';
            }
        }
    }
}

$search = trim($_GET['search'] ?? '');
$searchSql = '';
$params = [];
if ($search !== '') {
    // Función para normalizar texto eliminando acentos
    $normalizeField = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(%s), 'á', 'a'), 'é', 'e'), 'í', 'i'), 'ó', 'o'), 'ú', 'u'), 'ñ', 'n'), 'ü', 'u'), 'à', 'a'), 'è', 'e'), 'ì', 'i')";

    $normalizedFullName = sprintf($normalizeField, 'p.full_name');
    $normalizedPreferredName = sprintf($normalizeField, 'p.preferred_name');

    $searchSql = "WHERE $normalizedFullName LIKE :term OR $normalizedPreferredName LIKE :term OR p.document_id LIKE :term OR p.phone_primary LIKE :term";

    // Normalizar el término de búsqueda
    $normalizedSearch = str_replace(
        ['á', 'é', 'í', 'ó', 'ú', 'ñ', 'ü', 'à', 'è', 'ì', 'Á', 'É', 'Í', 'Ó', 'Ú', 'Ñ', 'Ü'],
        ['a', 'e', 'i', 'o', 'u', 'n', 'u', 'a', 'e', 'i', 'a', 'e', 'i', 'o', 'u', 'n', 'u'],
        strtolower($search)
    );
    $params[':term'] = '%' . $normalizedSearch . '%';
}

$patientStmt = $pdo->prepare(
    "SELECT p.*,
        (
            SELECT MAX(visit_date)
            FROM visits v
            WHERE v.patient_id = p.id
        ) AS last_visit,
        (
            SELECT balance
            FROM treatment_activities ta
            WHERE ta.patient_id = p.id
            ORDER BY date(ta.activity_date) DESC, ta.id DESC
            LIMIT 1
        ) AS pending_balance
     FROM patients p
     $searchSql
     ORDER BY p.updated_at DESC, p.full_name ASC"
);
$patientStmt->execute($params);
$patients = $patientStmt->fetchAll(PDO::FETCH_ASSOC);
$pendingBalance = 0.0;
foreach ($patients as &$patientRow) {
    $rawBalance = $patientRow['pending_balance'] ?? null;
    $normalizedBalance = is_numeric($rawBalance) ? (float) $rawBalance : 0.0;
    $patientRow['pending_balance'] = $normalizedBalance;
    $pendingBalance += $normalizedBalance;
}
unset($patientRow);

$totalPatients = (int) $pdo->query('SELECT COUNT(*) FROM patients')->fetchColumn();
$totalVisitsThisMonth = (int) $pdo->query(
    "SELECT COUNT(*) FROM visits WHERE strftime('%Y-%m', visit_date) = strftime('%Y-%m', 'now', 'localtime')"
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

$financeMonthStart = date('Y-m-01');
$financeMonthEnd = date('Y-m-d', strtotime($financeMonthStart . ' +1 month'));
$monthlyFinance = [
    'income' => 0.0,
    'expense' => 0.0,
    'count' => 0,
    'net' => 0.0,
];
$financeSummaryStmt = $pdo->prepare(
    'SELECT type, SUM(amount) AS total
     FROM finance_entries
     WHERE entry_date >= :start AND entry_date < :end
     GROUP BY type'
);
$financeSummaryStmt->execute([
    ':start' => $financeMonthStart,
    ':end' => $financeMonthEnd,
]);
foreach ($financeSummaryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $type = $row['type'];
    if (isset($monthlyFinance[$type])) {
        $monthlyFinance[$type] = (float) $row['total'];
    }
}
$activityIncomeStmt = $pdo->prepare(
    'SELECT SUM(payment) AS total
     FROM treatment_activities
     WHERE payment > 0
       AND activity_date >= :start
       AND activity_date < :end'
);
$activityIncomeStmt->execute([
    ':start' => $financeMonthStart,
    ':end' => $financeMonthEnd,
]);
$activityIncome = (float) $activityIncomeStmt->fetchColumn();
$monthlyFinance['income'] += $activityIncome;
$monthlyFinance['net'] = $monthlyFinance['income'] - $monthlyFinance['expense'];
$financeCountStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM finance_entries WHERE entry_date >= :start AND entry_date < :end'
);
$financeCountStmt->execute([
    ':start' => $financeMonthStart,
    ':end' => $financeMonthEnd,
]);
$monthlyFinance['count'] = (int) $financeCountStmt->fetchColumn();
$monthlyFinance['net'] = $monthlyFinance['income'] - $monthlyFinance['expense'];

$avatarInitial = static function (?string $name): string {
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
$clampPhotoValue = static function (float $value, float $min, float $max): float {
    return max($min, min($max, $value));
};
$buildPhotoTransform = static function (array $row) use ($clampPhotoValue): string {
    $zoom = $clampPhotoValue((float) ($row['profile_photo_zoom'] ?? 1.0), 1.0, 2.5);
    $offsetX = $clampPhotoValue((float) ($row['profile_photo_offset_x'] ?? 0.0), -60.0, 60.0);
    $offsetY = $clampPhotoValue((float) ($row['profile_photo_offset_y'] ?? 0.0), -60.0, 60.0);
    return sprintf(
        'transform: translate(%0.2f%%, %0.2f%%) scale(%0.3f);',
        $offsetX,
        $offsetY,
        $zoom
    );
};

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

<section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
    <a href="#listado-pacientes" class="group block rounded-2xl bg-white p-6 shadow-sm shadow-slate-200/70 ring-1 ring-slate-200/70 transition duration-200 hover:-translate-y-1 hover:shadow-lg hover:ring-brand-300 cursor-pointer">
        <div class="flex items-center justify-between">
            <p class="text-sm font-medium text-slate-500">Total de pacientes</p>
            <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-brand-50 text-lg">👥</span>
        </div>
        <p class="mt-4 text-3xl font-semibold text-slate-900"><?= number_format($totalPatients) ?></p>
        <p class="mt-2 text-xs uppercase tracking-wide text-slate-400">Registros activos</p>
    </a>
    <a href="#listado-pacientes" class="group block rounded-2xl bg-white p-6 shadow-sm shadow-slate-200/70 ring-1 ring-slate-200/70 transition duration-200 hover:-translate-y-1 hover:shadow-lg hover:ring-brand-300 cursor-pointer">
        <div class="flex items-center justify-between">
            <p class="text-sm font-medium text-slate-500">Consultas en <?= htmlspecialchars(ucfirst($currentMonthLabel) . ' ' . date('Y')) ?></p>
            <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-brand-50 text-lg">📅</span>
        </div>
        <p class="mt-4 text-3xl font-semibold text-slate-900"><?= number_format($totalVisitsThisMonth) ?></p>
        <p class="mt-2 text-xs uppercase tracking-wide text-slate-400">Control mensual</p>
    </a>
    <a href="#listado-pacientes" class="group block rounded-2xl bg-white p-6 shadow-sm shadow-slate-200/70 ring-1 ring-slate-200/70 transition duration-200 hover:-translate-y-1 hover:shadow-lg hover:ring-brand-300 cursor-pointer">
        <div class="flex items-center justify-between">
            <p class="text-sm font-medium text-slate-500">Saldo pendiente</p>
            <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-brand-50 text-lg">💳</span>
        </div>
        <?php $pendingBalanceDisplay = $pendingBalance > 0 ? -$pendingBalance : 0.0; ?>
        <p class="mt-4 text-3xl font-semibold text-slate-900">$ <?= number_format($pendingBalanceDisplay, 2, ',', '.') ?></p>
        <p class="mt-2 text-xs uppercase tracking-wide text-slate-400">Cuentas por cobrar</p>
    </a>
    <a href="finances.php" class="group block rounded-2xl bg-white p-6 shadow-sm shadow-slate-200/70 ring-1 ring-slate-200/70 transition duration-200 hover:-translate-y-1 hover:shadow-lg hover:ring-brand-300 cursor-pointer">
        <div class="flex items-center justify-between">
            <p class="text-sm font-medium text-slate-500">Finanzas <?= htmlspecialchars(ucfirst($currentMonthLabel) . ' ' . date('Y')) ?></p>
            <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-brand-50 text-lg">💰</span>
        </div>
        <p class="mt-4 text-3xl font-semibold text-slate-900">$ <?= number_format($monthlyFinance['net'], 2, ',', '.') ?></p>
        <p class="mt-2 text-xs uppercase tracking-wide text-slate-400">Balance neto mensual</p>
        <p class="mt-2 text-xs text-slate-400">Ingresos: $ <?= number_format($monthlyFinance['income'], 2, ',', '.') ?> · Gastos: $ <?= number_format($monthlyFinance['expense'], 2, ',', '.') ?></p>
    </a>
    <a href="#citas" class="group block rounded-2xl bg-white p-6 shadow-sm shadow-slate-200/70 ring-1 ring-slate-200/70 transition duration-200 hover:-translate-y-1 hover:shadow-lg hover:ring-brand-300 cursor-pointer">
        <div class="flex items-center justify-between">
            <p class="text-sm font-medium text-slate-500">Próximas citas</p>
            <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-brand-50 text-lg">⏰</span>
        </div>
        <p class="mt-4 text-3xl font-semibold text-slate-900"><?= count($upcomingAppointments) ?></p>
        <p class="mt-2 text-xs uppercase tracking-wide text-slate-400">Seguimiento inmediato</p>
    </a>
</section>

<section class="rounded-3xl bg-white/90 p-6 shadow-sm shadow-slate-200/60 ring-1 ring-slate-200/70 sm:p-8" id="listado-pacientes">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h2 class="text-xl font-semibold text-slate-900">Listado de pacientes</h2>
            <p class="mt-1 text-sm text-slate-500">Filtra por nombre, cédula o teléfono para ubicar rápidamente a tus pacientes.</p>
        </div>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
            <form method="get" class="flex w-full max-w-md items-center gap-2 rounded-full border border-slate-200 bg-slate-50/60 px-4 py-1.5 text-sm shadow-inner focus-within:border-brand-300 focus-within:bg-white sm:py-2">
                <label for="search" class="sr-only">Buscar paciente</label>
                <input id="search" type="search" name="search" placeholder="Buscar por nombre, cédula o teléfono" value="<?= htmlspecialchars($search) ?>" class="flex-1 border-0 bg-transparent py-1 text-sm text-slate-700 placeholder:text-slate-400 focus:ring-0 capitalize" />
                <button type="submit" class="inline-flex items-center rounded-full bg-brand-600 px-4 py-1.5 text-sm font-semibold text-white shadow-soft transition hover:bg-brand-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500">
                    Buscar
                </button>
            </form>
        </div>
    </div>

    <?php if (empty($patients)): ?>
        <p class="mt-6 rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-6 py-10 text-center text-sm text-slate-500">
            <?php if ($search !== ''): ?>
                No se encontraron pacientes con el criterio de búsqueda "<?= htmlspecialchars(ucfirst($search)) ?>".
            <?php else: ?>
                No hay pacientes registrados todavía.
            <?php endif; ?>
        </p>
    <?php else: ?>
        <div class="mt-6 overflow-y-auto rounded-2xl border border-slate-200/70 shadow-sm" style="max-height: 600px;">
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
                        <?php
                        $balance = (float) ($patient['pending_balance'] ?? 0);
                        $balanceDisplay = $balance > 0 ? -$balance : 0.0;
                        $profilePhotoPath = trim((string) ($patient['profile_photo_path'] ?? ''));
                        $hasProfilePhoto = $profilePhotoPath !== '';
                        $avatarInitialValue = $avatarInitial($patient['full_name'] ?? '');
                        $profilePhotoStyle = $buildPhotoTransform($patient);
                        ?>
                        <tr class="transition hover:bg-slate-50/80">
                            <td class="px-4 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="relative h-11 w-11 overflow-hidden rounded-full bg-gradient-to-br from-slate-100 to-slate-200 shadow-inner ring-2 <?= $hasProfilePhoto ? 'ring-brand-100/80' : 'ring-slate-200' ?>">
                                        <?php if ($hasProfilePhoto): ?>
                                            <img
                                                src="<?= htmlspecialchars($profilePhotoPath) ?>"
                                                alt="<?= htmlspecialchars('Foto de ' . ($patient['full_name'] ?? '')) ?>"
                                                class="absolute inset-0 h-full w-full object-cover"
                                                style="<?= htmlspecialchars($profilePhotoStyle) ?>"
                                                draggable="false"
                                                loading="lazy"
                                            >
                                        <?php else: ?>
                                            <div class="absolute inset-0 flex items-center justify-center text-sm font-semibold text-slate-600 select-none">
                                                <?= htmlspecialchars($avatarInitialValue) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="space-y-1">
                                        <p class="font-semibold text-slate-900"><?= htmlspecialchars($patient['full_name']) ?></p>
                                        <?php if (!empty($patient['preferred_name'])): ?>
                                            <p class="text-xs text-slate-500">Preferido: <?= htmlspecialchars($patient['preferred_name']) ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($patient['notes'])): ?>
                                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-700">
                                                <span aria-hidden="true">⚠️</span>
                                                Alerta clínica
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
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
                                <?= number_format($balanceDisplay, 2, ',', '.') ?>
                            </td>
                            <td class="px-4 py-4 text-right">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <a href="patient.php?id=<?= (int) $patient['id'] ?>" class="inline-flex items-center rounded-full bg-brand-600 px-3.5 py-1.5 text-xs font-semibold text-white shadow-soft transition hover:bg-brand-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500">
                                        Ver ficha
                                    </a>
                                    <a href="patient_form.php?id=<?= (int) $patient['id'] ?>" class="inline-flex items-center rounded-full border border-slate-200 px-3.5 py-1.5 text-xs font-semibold text-slate-600 transition hover:border-brand-200 hover:text-brand-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500">
                                        Editar
                                    </a>
                                    <form method="post" class="inline-flex" onsubmit="return confirm('¿Eliminar al paciente <?= htmlspecialchars($patient['full_name'], ENT_QUOTES) ?>? Esta acción no se puede deshacer.');">
                                        <input type="hidden" name="action" value="delete_patient">
                                        <input type="hidden" name="patient_id" value="<?= (int) $patient['id'] ?>">
                                        <button type="submit" class="inline-flex items-center rounded-full border border-rose-200 px-3.5 py-1.5 text-xs font-semibold text-rose-600 transition hover:border-rose-300 hover:bg-rose-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-500">
                                            Eliminar
                                        </button>
                                    </form>
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
                            <p class="text-sm text-slate-600 prevent-overflow"><?= nl2br(htmlspecialchars($appointment['plan'])) ?></p>
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
