<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/database.php';

$pdo = db();
$messages = [];
$errors = [];

$allowedTypes = [
    'income' => 'Ingreso',
    'expense' => 'Gasto',
];

$spanishMonths = [
    1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
    5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
    9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'
];

$formatMonthLabel = static function (string $ym) use ($spanishMonths): string {
    if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $matches)) {
        return $ym;
    }
    $year = $matches[1];
    $monthNumber = (int) $matches[2];
    $monthName = $spanishMonths[$monthNumber] ?? $ym;
    return ucfirst($monthName) . ' ' . $year;
};

$formatCurrency = static fn(float $value): string => number_format($value, 2, ',', '.');

$capitalizeFirst = static function (string $value): string {
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }
    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        $firstChar = mb_substr($trimmed, 0, 1, 'UTF-8');
        $rest = mb_substr($trimmed, 1, null, 'UTF-8');
        return mb_strtoupper($firstChar, 'UTF-8') . $rest;
    }

    $firstChar = substr($trimmed, 0, 1);
    $rest = substr($trimmed, 1);

    return strtoupper($firstChar) . $rest;
};

$defaultMonth = date('Y-m');
$selectedMonth = isset($_GET['month']) ? trim((string) $_GET['month']) : $defaultMonth;
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = $defaultMonth;
}

$monthStart = $selectedMonth . '-01';
$monthEnd = date('Y-m-d', strtotime($monthStart . ' +1 month'));

$entryDateInput = date('Y-m-d');
$type = 'income';
$category = '';
$description = '';
$amountRaw = '';
$paymentMethod = '';
$notes = '';
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

if (isset($_GET['msg'])) {
    $messages[] = match ($_GET['msg']) {
        'added' => 'Movimiento registrado correctamente.',
        'updated' => 'Movimiento actualizado correctamente.',
        'deleted' => 'Movimiento eliminado.',
        default => null,
    };
    $messages = array_filter($messages);
}

$action = $_POST['action'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'add_entry' || $action === 'update_entry') {
        $entryDateInput = trim((string) post('entry_date'));
        $entryDate = normalizeDate($entryDateInput);
        $type = (string) post('type');
        $category = trim((string) post('category'));
        $description = trim((string) post('description'));
        $amountRaw = trim((string) post('amount'));
        $paymentMethod = trim((string) post('payment_method'));
        $notes = trim((string) post('notes'));

        if ($entryDate === null) {
            $errors[] = 'Selecciona una fecha válida.';
        } else {
            $selectedMonth = substr($entryDate, 0, 7);
            $monthStart = $selectedMonth . '-01';
            $monthEnd = date('Y-m-d', strtotime($monthStart . ' +1 month'));
        }

        if (!isset($allowedTypes[$type])) {
            $errors[] = 'Selecciona si es un ingreso o un gasto.';
        }

        if ($description === '') {
            $errors[] = 'Agrega una descripción para identificar el movimiento.';
        } else {
            $description = $capitalizeFirst($description);
        }

        if ($amountRaw === '' || !is_numeric($amountRaw)) {
            $errors[] = 'Ingresa un monto numérico.';
        }

        $amount = (float) $amountRaw;
        if ($amount <= 0) {
            $errors[] = 'El monto debe ser mayor a cero.';
        }

        if ($category !== '') {
            $category = $capitalizeFirst($category);
        }
        if ($paymentMethod !== '') {
            $paymentMethod = $capitalizeFirst($paymentMethod);
        }
        if ($notes !== '') {
            $notes = $capitalizeFirst($notes);
        }

        if ($action === 'add_entry' && !$errors) {
            $insertData = [
                'entry_date' => $entryDate,
                'type' => $type,
                'category' => $category !== '' ? $category : null,
                'description' => $description,
                'amount' => $amount,
                'payment_method' => $paymentMethod !== '' ? $paymentMethod : null,
                'notes' => $notes !== '' ? $notes : null,
            ];

            insertRow($pdo, 'finance_entries', $insertData);
            $redirectMonth = urlencode(substr($entryDate, 0, 7));
            header('Location: finances.php?month=' . $redirectMonth . '&msg=added');
            exit;
        }

        if ($action === 'update_entry') {
            $entryId = (int) post('entry_id');
            $editId = $entryId;
            if ($entryId <= 0) {
                $errors[] = 'Movimiento no válido para editar.';
            } else {
                $existsStmt = $pdo->prepare('SELECT id FROM finance_entries WHERE id = :id');
                $existsStmt->execute([':id' => $entryId]);
                if (!$existsStmt->fetchColumn()) {
                    $errors[] = 'El movimiento seleccionado ya no existe.';
                    $editId = 0;
                }
            }

            if (!$errors && $entryId > 0) {
                $updateData = [
                    'entry_date' => $entryDate,
                    'type' => $type,
                    'category' => $category !== '' ? $category : null,
                    'description' => $description,
                    'amount' => $amount,
                    'payment_method' => $paymentMethod !== '' ? $paymentMethod : null,
                    'notes' => $notes !== '' ? $notes : null,
                ];
                updateById($pdo, 'finance_entries', $updateData, $entryId);
                $redirectMonth = urlencode(substr($entryDate, 0, 7));
                header('Location: finances.php?month=' . $redirectMonth . '&msg=updated');
                exit;
            }
        }
    } elseif ($action === 'delete_entry') {
        $entryId = (int) post('entry_id');
        if ($entryId > 0) {
            $stmt = $pdo->prepare('DELETE FROM finance_entries WHERE id = :id');
            $stmt->execute([':id' => $entryId]);
            header('Location: finances.php?month=' . urlencode($selectedMonth) . '&msg=deleted');
            exit;
        }
    }
}

$manualTotals = [
    'income' => 0.0,
    'expense' => 0.0,
];
$summaryStmt = $pdo->prepare(
    'SELECT type, SUM(amount) AS total
     FROM finance_entries
     WHERE entry_date >= :start AND entry_date < :end
     GROUP BY type'
);
$summaryStmt->execute([
    ':start' => $monthStart,
    ':end' => $monthEnd,
]);
foreach ($summaryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $type = $row['type'];
    if (isset($manualTotals[$type])) {
        $manualTotals[$type] = (float) $row['total'];
    }
}

$activityIncomeStmt = $pdo->prepare(
    'SELECT payment
     FROM treatment_activities
     WHERE activity_date >= :start
       AND activity_date < :end
       AND payment > 0'
);
$activityIncomeStmt->execute([
    ':start' => $monthStart,
    ':end' => $monthEnd,
]);
$activityIncomeTotal = 0.0;
foreach ($activityIncomeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $activityIncomeTotal += (float) $row['payment'];
}

$periodTotals = [
    'income' => $manualTotals['income'] + $activityIncomeTotal,
    'expense' => $manualTotals['expense'],
];
$periodNet = $periodTotals['income'] - $periodTotals['expense'];

$entriesStmt = $pdo->prepare(
    'SELECT id, entry_date, type, category, description, amount, payment_method, notes, created_at
     FROM finance_entries
     WHERE entry_date >= :start AND entry_date < :end'
);
$entriesStmt->execute([
    ':start' => $monthStart,
    ':end' => $monthEnd,
]);
$manualEntries = [];
foreach ($entriesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $manualEntries[] = $row + [
        'source' => 'manual',
        'patient_id' => null,
        'patient_name' => null,
    ];
}

$activityEntriesStmt = $pdo->prepare(
    'SELECT ta.id, ta.activity_date, ta.description, ta.payment, ta.payment_method, ta.notes, ta.created_at, ta.patient_id, p.full_name AS patient_name
     FROM treatment_activities ta
     INNER JOIN patients p ON p.id = ta.patient_id
     WHERE ta.activity_date >= :start
       AND ta.activity_date < :end
       AND ta.payment > 0'
);
$activityEntriesStmt->execute([
    ':start' => $monthStart,
    ':end' => $monthEnd,
]);
$activityEntries = [];
foreach ($activityEntriesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $amount = (float) $row['payment'];
    if ($amount <= 0) {
        continue;
    }
    $methodLabel = normalizePaymentMethodValue($row['payment_method'] ?? null);
    $activityEntries[] = [
        'id' => (int) $row['id'],
        'entry_date' => (string) $row['activity_date'],
        'type' => 'income',
        'category' => 'Control de pagos',
        'description' => (string) $row['description'],
        'amount' => $amount,
        'payment_method' => $methodLabel !== '' ? $methodLabel : null,
        'notes' => $row['notes'] ?? null,
        'created_at' => $row['created_at'] ?? null,
        'source' => 'activity',
        'patient_id' => (int) $row['patient_id'],
        'patient_name' => $row['patient_name'] ?? '',
    ];
}

$entries = array_merge($manualEntries, $activityEntries);
usort($entries, static function (array $a, array $b): int {
    $dateComparison = strcmp((string) ($b['entry_date'] ?? ''), (string) ($a['entry_date'] ?? ''));
    if ($dateComparison !== 0) {
        return $dateComparison;
    }
    $createdComparison = strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    if ($createdComparison !== 0) {
        return $createdComparison;
    }
    return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
});

$editingEntry = null;
if ($editId > 0 && !($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_entry' && $errors)) {
    $editStmt = $pdo->prepare(
        'SELECT id, entry_date, type, category, description, amount, payment_method, notes
         FROM finance_entries
         WHERE id = :id'
    );
    $editStmt->execute([':id' => $editId]);
    $editingEntry = $editStmt->fetch(PDO::FETCH_ASSOC);
    if ($editingEntry) {
        $entryDateInput = $editingEntry['entry_date'];
        $type = (string) $editingEntry['type'];
        $category = (string) ($editingEntry['category'] ?? '');
        $description = (string) ($editingEntry['description'] ?? '');
        $amountRaw = number_format((float) $editingEntry['amount'], 2, '.', '');
        $paymentMethod = (string) ($editingEntry['payment_method'] ?? '');
        $notes = (string) ($editingEntry['notes'] ?? '');
    } else {
        $errors[] = 'El movimiento que intentas editar ya no existe.';
        $editId = 0;
    }
}
$isEditing = $editId > 0;

$financeMonths = $pdo->query(
    "SELECT DISTINCT substr(entry_date, 1, 7) AS month_key
     FROM finance_entries"
)->fetchAll(PDO::FETCH_COLUMN);
$activityMonths = $pdo->query(
    "SELECT DISTINCT substr(activity_date, 1, 7) AS month_key
     FROM treatment_activities
     WHERE payment > 0"
)->fetchAll(PDO::FETCH_COLUMN);
$availableMonths = array_values(array_unique(array_merge($financeMonths, $activityMonths)));
if (!$availableMonths) {
    $availableMonths = [$selectedMonth];
}

if (!in_array($selectedMonth, $availableMonths, true)) {
    $availableMonths[] = $selectedMonth;
}

usort($availableMonths, static fn($a, $b) => strcmp($b, $a));

$formEntryDate = $entryDateInput !== '' ? $entryDateInput : date('Y-m-d');
$formType = $type;
$formCategory = $category;
$formAmount = $amountRaw;
$formPaymentMethod = $paymentMethod;
$formDescription = $description;
$formNotes = $notes;

$pageTitle = 'Control financiero · Consultorio Odontológico';
require __DIR__ . '/templates/header.php';
?>

<?php if ($messages): ?>
    <div class="rounded-xl border border-emerald-200 bg-emerald-50/80 px-5 py-4 text-sm text-emerald-900 shadow-sm shadow-emerald-100/60 mb-6">
        <ul class="list-disc space-y-1 pl-5">
            <?php foreach ($messages as $message): ?>
                <li><?= htmlspecialchars($message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="rounded-xl border border-rose-200 bg-rose-50/90 px-5 py-4 text-sm text-rose-900 shadow-sm shadow-rose-200/60 mb-6">
        <ul class="list-disc space-y-1 pl-5">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<section class="space-y-8" id="resumen">
    <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
            <h1 class="text-3xl font-semibold text-slate-900">Control financiero</h1>
            <p class="text-slate-500">Registra ingresos, egresos y compras del consultorio.</p>
        </div>
        <form method="get" class="flex items-end gap-3">
            <div class="flex flex-col">
                <label for="month-selector" class="text-sm font-medium text-slate-600 mb-1">Mes</label>
                <div class="relative">
                    <select id="month-selector" name="month" style="-webkit-appearance: none; -moz-appearance: none; appearance: none; background-image: none;" class="rounded-xl border-slate-300 pr-10 pl-4 py-2.5 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500 bg-white min-w-[200px] cursor-pointer">
                        <?php foreach ($availableMonths as $monthKey): ?>
                            <option value="<?= htmlspecialchars($monthKey) ?>"<?= $monthKey === $selectedMonth ? ' selected' : '' ?>>
                                <?= htmlspecialchars($formatMonthLabel($monthKey)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 text-slate-500">
                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                        </svg>
                    </div>
                </div>
            </div>
            <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:-translate-y-0.5 hover:bg-brand-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500">
                Aplicar
            </button>
        </form>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <div class="rounded-2xl bg-white p-6 shadow-sm shadow-slate-200/70 ring-1 ring-emerald-100">
            <p class="text-sm font-medium text-emerald-600 uppercase tracking-wide">Ingresos del mes</p>
            <p class="mt-3 text-3xl font-semibold text-slate-900">Bs <?= $formatCurrency($periodTotals['income']) ?></p>
            <p class="mt-2 text-xs text-slate-400">Pagos, tratamientos y otros ingresos registrados.</p>
            <?php if ($activityIncomeTotal > 0): ?>
                <p class="mt-1 text-xs text-emerald-600/80">Incluye Bs <?= $formatCurrency($activityIncomeTotal) ?> registrados automáticamente desde el control de pagos.</p>
            <?php endif; ?>
        </div>
        <div class="rounded-2xl bg-white p-6 shadow-sm shadow-slate-200/70 ring-1 ring-rose-100">
            <p class="text-sm font-medium text-rose-600 uppercase tracking-wide">Gastos del mes</p>
            <p class="mt-3 text-3xl font-semibold text-slate-900">Bs <?= $formatCurrency($periodTotals['expense']) ?></p>
            <p class="mt-2 text-xs text-slate-400">Compras de insumos, inversiones y otros egresos.</p>
        </div>
        <div class="rounded-2xl bg-white p-6 shadow-sm shadow-slate-200/70 ring-1 <?= $periodNet >= 0 ? 'ring-emerald-100' : 'ring-rose-100' ?>">
            <p class="text-sm font-medium <?= $periodNet >= 0 ? 'text-emerald-600' : 'text-rose-600' ?> uppercase tracking-wide">Balance del mes</p>
            <p class="mt-3 text-3xl font-semibold text-slate-900">Bs <?= $formatCurrency($periodNet) ?></p>
            <p class="mt-2 text-xs text-slate-400">Resultado neto (ingresos menos gastos).</p>
        </div>
    </div>
</section>

<section class="grid gap-8 lg:grid-cols-3 lg:items-start mt-10" id="registro">
    <div class="lg:col-span-1 rounded-3xl bg-white/95 p-6 shadow-sm shadow-slate-200/60 ring-1 ring-slate-200/70 space-y-5">
        <h2 class="text-xl font-semibold text-slate-900"><?= $isEditing ? 'Editar movimiento' : 'Registrar movimiento' ?></h2>
        <p class="text-sm text-slate-500">
            <?= $isEditing
                ? 'Actualiza los datos del movimiento seleccionado o cancela para volver al registro.'
                : 'Guarda ingresos, compras o gastos operativos para mantener el control del consultorio.' ?>
        </p>
        <?php if ($isEditing): ?>
            <div class="rounded-xl border border-brand-200 bg-brand-50/60 px-4 py-2 text-xs font-medium text-brand-700">
                Estás editando un movimiento existente. Ajusta la información y presiona “Actualizar movimiento” o cancela para salir del modo edición.
            </div>
        <?php endif; ?>
        <form method="post" class="space-y-5">
            <input type="hidden" name="action" value="<?= $isEditing ? 'update_entry' : 'add_entry' ?>">
            <?php if ($isEditing): ?>
                <input type="hidden" name="entry_id" value="<?= (int) $editId ?>">
            <?php endif; ?>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="flex flex-col text-sm font-medium text-slate-600">
                    Fecha
                    <input type="date" name="entry_date" value="<?= htmlspecialchars($formEntryDate) ?>" class="mt-1 rounded-xl border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500" required>
                </label>
                <label class="flex flex-col text-sm font-medium text-slate-600">
                    Tipo
                    <select name="type" class="mt-1 rounded-xl border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500" required>
                        <?php foreach ($allowedTypes as $typeKey => $typeLabel): ?>
                            <option value="<?= htmlspecialchars($typeKey) ?>"<?= $formType === $typeKey ? ' selected' : '' ?>>
                                <?= htmlspecialchars($typeLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="flex flex-col text-sm font-medium text-slate-600">
                    Categoría
                    <input type="text" name="category" placeholder="Ej. Tratamientos, Insumos" value="<?= htmlspecialchars($formCategory) ?>" class="mt-1 rounded-xl border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500 capitalize">
                </label>
                <label class="flex flex-col text-sm font-medium text-slate-600">
                    Monto (Bs)
                    <input type="number" name="amount" min="0" step="0.01" value="<?= htmlspecialchars($formAmount) ?>" class="mt-1 rounded-xl border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500" required>
                </label>
            </div>
            <label class="flex flex-col text-sm font-medium text-slate-600">
                Medio de pago
                <input type="text" name="payment_method" placeholder="Efectivo, transferencia, tarjeta…" value="<?= htmlspecialchars($formPaymentMethod) ?>" class="mt-1 rounded-xl border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500 capitalize">
            </label>
            <label class="flex flex-col text-sm font-medium text-slate-600">
                Descripción
                <textarea name="description" rows="2" class="mt-1 rounded-xl border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500 capitalize" placeholder="Detalle del movimiento" required><?= htmlspecialchars($formDescription) ?></textarea>
            </label>
            <label class="flex flex-col text-sm font-medium text-slate-600">
                Notas adicionales
                <textarea name="notes" rows="2" class="mt-1 rounded-xl border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500 capitalize" placeholder="Observaciones opcionales"><?= htmlspecialchars($formNotes) ?></textarea>
            </label>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <button type="submit" class="inline-flex w-full items-center justify-center rounded-full bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:-translate-y-0.5 hover:bg-brand-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 sm:w-auto">
                    <?= $isEditing ? 'Actualizar movimiento' : 'Guardar movimiento' ?>
                </button>
                <?php if ($isEditing): ?>
                    <a href="finances.php?month=<?= htmlspecialchars($selectedMonth) ?>#registro" class="inline-flex w-full items-center justify-center rounded-full border border-slate-200 px-5 py-2.5 text-sm font-semibold text-slate-600 transition hover:-translate-y-0.5 hover:border-brand-300 hover:text-brand-600 sm:w-auto">
                        Cancelar
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <div class="lg:col-span-2 rounded-3xl bg-white/95 p-6 shadow-sm shadow-slate-200/60 ring-1 ring-slate-200/70 space-y-6 overflow-hidden">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold text-slate-900">Movimientos del mes</h2>
                <p class="text-sm text-slate-500"><?= htmlspecialchars($formatMonthLabel($selectedMonth)) ?></p>
            </div>
            <p class="text-sm font-medium text-slate-500">
                <?= count($entries) ?> registro<?= count($entries) === 1 ? '' : 's' ?>
            </p>
        </div>
        <?php if (!$entries): ?>
            <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50/60 p-6 text-center text-sm text-slate-500">
                Aún no hay movimientos registrados en este mes.
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold">Fecha</th>
                            <th class="px-4 py-3 text-left font-semibold">Detalle</th>
                            <th class="px-4 py-3 text-left font-semibold">Categoría</th>
                            <th class="px-4 py-3 text-left font-semibold">Medio</th>
                            <th class="px-4 py-3 text-right font-semibold">Monto</th>
                            <th class="px-4 py-3 text-center font-semibold">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        <?php foreach ($entries as $entry): ?>
                            <?php
                            $isManual = ($entry['source'] ?? 'manual') === 'manual';
                            $isActivity = ($entry['source'] ?? '') === 'activity';
                            $isCurrentEdit = $isManual && $isEditing && (int) $entry['id'] === $editId;
                            $typeLabel = $allowedTypes[$entry['type']] ?? ucfirst((string) $entry['type']);
                            if ($isActivity) {
                                $typeLabel .= ' · Paciente';
                            }
                            $createdAt = $entry['created_at'] ?? null;
                            ?>
                            <tr class="transition hover:bg-slate-50/80<?= $isCurrentEdit ? ' bg-brand-50/70' : '' ?>">
                                <td class="px-4 py-3 align-top text-slate-600">
                                    <p class="font-medium text-slate-900"><?= htmlspecialchars(date('d/m/Y', strtotime((string) $entry['entry_date']))) ?></p>
                                    <?php if ($createdAt): ?>
                                        <p class="text-xs text-slate-400"><?= htmlspecialchars(date('H:i', strtotime((string) $createdAt))) ?></p>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <div class="flex flex-col gap-1">
                                        <span class="inline-flex w-max items-center rounded-full px-2 py-0.5 text-xs font-semibold <?= $entry['type'] === 'income' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' ?>">
                                            <?= htmlspecialchars($typeLabel) ?>
                                        </span>
                                        <p class="font-medium text-slate-900"><?= htmlspecialchars($entry['description']) ?></p>
                                        <?php if ($entry['notes']): ?>
                                            <p class="text-xs text-slate-500"><?= nl2br(htmlspecialchars($entry['notes'])) ?></p>
                                        <?php endif; ?>
                                        <?php if ($isActivity && $entry['patient_id']): ?>
                                            <p class="text-xs text-brand-600">
                                                Paciente:
                                                <a class="font-semibold underline underline-offset-2 hover:text-brand-500" href="patient.php?id=<?= (int) $entry['patient_id'] ?>#actividades">
                                                    <?= htmlspecialchars($entry['patient_name'] ?: 'Ver ficha') ?>
                                                </a>
                                            </p>
                                        <?php endif; ?>
                                        <?php if ($isCurrentEdit): ?>
                                            <span class="inline-flex w-max items-center rounded-full bg-brand-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-brand-700">Editando</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-4 py-3 align-top text-slate-600">
                                    <?= $entry['category'] ? htmlspecialchars($entry['category']) : '—' ?>
                                </td>
                                <td class="px-4 py-3 align-top text-slate-600">
                                    <?= $entry['payment_method'] ? htmlspecialchars($entry['payment_method']) : '—' ?>
                                </td>
                                <td class="px-4 py-3 align-top text-right font-semibold <?= $entry['type'] === 'income' ? 'text-emerald-600' : 'text-rose-600' ?>">
                                    <?= $entry['type'] === 'expense' ? '-' : '+' ?> Bs <?= $formatCurrency((float) $entry['amount']) ?>
                                </td>
                                <td class="px-4 py-3 align-top text-center">
                                    <?php if ($isManual): ?>
                                        <div class="flex items-center justify-center gap-2">
                                            <a href="finances.php?month=<?= htmlspecialchars($selectedMonth) ?>&edit=<?= (int) $entry['id'] ?>#registro" class="inline-flex h-8 w-24 items-center justify-center gap-1.5 rounded-full border border-brand-200 bg-brand-50 px-3 text-xs font-semibold text-brand-700 shadow-sm transition hover:-translate-y-0.5 hover:bg-brand-100">
                                                <span aria-hidden="true">✏️</span><span>Editar</span>
                                            </a>
                                            <form method="post" class="inline-flex" onsubmit="return confirm('¿Eliminar este movimiento?');">
                                                <input type="hidden" name="action" value="delete_entry">
                                                <input type="hidden" name="entry_id" value="<?= (int) $entry['id'] ?>">
                                                <button type="submit" class="inline-flex h-8 w-24 items-center justify-center gap-1.5 rounded-full border border-rose-200 bg-rose-50 px-3 text-xs font-semibold text-rose-600 shadow-sm transition hover:-translate-y-0.5 hover:bg-rose-100">
                                                    <span aria-hidden="true">🗑️</span><span>Eliminar</span>
                                                </button>
                                            </form>
                                        </div>
                                    <?php elseif ($isActivity && $entry['patient_id']): ?>
                                        <a href="patient.php?id=<?= (int) $entry['patient_id'] ?>#actividades" class="inline-flex h-8 items-center justify-center gap-1.5 rounded-full border border-brand-200 bg-white px-4 text-xs font-semibold text-brand-700 shadow-sm transition hover:-translate-y-0.5 hover:bg-brand-50">
                                            <span aria-hidden="true">👤</span><span>Ver ficha</span>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-xs text-slate-400">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/templates/footer.php'; ?>
