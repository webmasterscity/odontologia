<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/database.php';

$pdo = db();

$patientId = isset($_GET['patient_id']) ? (int) $_GET['patient_id'] : 0;
$snapshotId = isset($_GET['snapshot_id']) ? (int) $_GET['snapshot_id'] : 0;

if ($patientId <= 0 || $snapshotId <= 0) {
    http_response_code(400);
    echo 'Solicitud inválida.';
    exit;
}

$patientStmt = $pdo->prepare(
    'SELECT
        id,
        full_name,
        preferred_name,
        document_id,
        birth_date,
        occupation,
        phone_primary,
        email,
        address,
        created_at,
        profile_photo_path,
        profile_photo_zoom,
        profile_photo_offset_x,
        profile_photo_offset_y
     FROM patients
     WHERE id = :id'
);
$patientStmt->execute([':id' => $patientId]);
$patient = $patientStmt->fetch(PDO::FETCH_ASSOC);
if (!$patient) {
    http_response_code(404);
    echo 'Paciente no encontrado.';
    exit;
}
$clampPhotoValue = static function (float $value, float $min, float $max): float {
    return max($min, min($max, $value));
};
$hasProfilePhoto = !empty($patient['profile_photo_path']);
$profilePhotoZoom = $clampPhotoValue((float) ($patient['profile_photo_zoom'] ?? 1.0), 1.0, 2.5);
$profilePhotoOffsetX = $clampPhotoValue((float) ($patient['profile_photo_offset_x'] ?? 0.0), -60.0, 60.0);
$profilePhotoOffsetY = $clampPhotoValue((float) ($patient['profile_photo_offset_y'] ?? 0.0), -60.0, 60.0);
$profilePhotoStyle = sprintf(
    'transform: translate(%0.2f%%, %0.2f%%) scale(%0.3f);',
    $profilePhotoOffsetX,
    $profilePhotoOffsetY,
    $profilePhotoZoom
);

// Manejar eliminación de odontograma
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? trim((string) $_POST['action']) : '';

    if ($action === 'delete_odontogram') {
        $snapshotIdToDelete = isset($_POST['snapshot_id']) ? (int) $_POST['snapshot_id'] : 0;
        if ($snapshotIdToDelete > 0 && $snapshotIdToDelete === $snapshotId) {
            $deleteStmt = $pdo->prepare('DELETE FROM odontogram_snapshots WHERE id = :id AND patient_id = :patient_id');
            $deleteStmt->execute([':id' => $snapshotIdToDelete, ':patient_id' => $patientId]);
            header('Location: patient.php?id=' . $patientId . '#historial-odontogramas');
            exit;
        }
    }
}

$snapshotStmt = $pdo->prepare('SELECT id, payload, is_blank, created_at FROM odontogram_snapshots WHERE patient_id = :patient_id AND id = :snapshot_id');
$snapshotStmt->execute([
    ':patient_id' => $patientId,
    ':snapshot_id' => $snapshotId,
]);
$snapshot = $snapshotStmt->fetch(PDO::FETCH_ASSOC);
if (!$snapshot) {
    http_response_code(404);
    echo 'Odontograma guardado no encontrado.';
    exit;
}

$formatValue = static function ($value, string $default = '—'): string {
    if ($value === null) {
        return $default;
    }
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $default;
        }
        return htmlspecialchars($trimmed);
    }
    if (is_numeric($value)) {
        return htmlspecialchars((string) $value);
    }
    return $default;
};

$formatDateTime = static function (?string $value, string $default = '—'): string {
    if ($value === null) {
        return $default;
    }
    $trimmed = trim((string) $value);
    if ($trimmed === '') {
        return $default;
    }
    $timestamp = strtotime($trimmed);
    if ($timestamp === false) {
        return $default;
    }
    return date('d/m/Y H:i', $timestamp);
};

$patientAgeYears = null;
if (!empty($patient['birth_date'])) {
    try {
        $birthDate = new DateTime($patient['birth_date']);
        $todayDate = new DateTime('today');
        $diff = $birthDate->diff($todayDate);
        $patientAgeYears = $diff->y;
    } catch (Throwable $e) {
        $patientAgeYears = null;
    }
}

$registeredAtDisplay = $formatDateTime($patient['created_at'] ?? null);

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

$defaultOdontogramStatus = array_key_exists('sin_registro', $odontogramStatuses)
    ? 'sin_registro'
    : (array_key_first($odontogramStatuses) ?? '');

$permanentSurfaces = [
    'top' => 'Superficie oclusal',
    'left' => 'Superficie mesial',
    'center' => 'Superficie central',
    'right' => 'Superficie distal',
    'bottom' => 'Superficie lingual'
];

$deciduousSurfaces = [
    'upper_left' => 'Superficie superior izquierda',
    'upper_right' => 'Superficie superior derecha',
    'center' => 'Superficie central',
    'lower_right' => 'Superficie inferior derecha',
    'lower_left' => 'Superficie inferior izquierda'
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

$allSurfaces = $permanentSurfaces + $deciduousSurfaces;

$colorLabels = [
    'blue' => 'Azul',
    'red' => 'Rojo',
];

$markLabels = [
    'dot' => 'Punto',
    'x' => 'Equis',
    'vertical' => 'Línea vertical',
    'horizontal' => 'Línea horizontal',
];

$describeSurfaces = static function (array $surfaces) use ($allSurfaces, $colorLabels, $markLabels): array {
    $descriptions = [];
    foreach ($surfaces as $surfaceKey => $surfaceState) {
        if (!is_array($surfaceState)) {
            continue;
        }
        $surfaceLabel = $allSurfaces[$surfaceKey] ?? $surfaceKey;
        $parts = [];
        if (!empty($surfaceState['color']) && isset($colorLabels[$surfaceState['color']])) {
            $parts[] = $colorLabels[$surfaceState['color']];
        }
        if (!empty($surfaceState['mark']) && isset($markLabels[$surfaceState['mark']])) {
            $markDescriptor = $markLabels[$surfaceState['mark']];
            if (!empty($surfaceState['markColor']) && isset($colorLabels[$surfaceState['markColor']])) {
                $markDescriptor .= ' (' . $colorLabels[$surfaceState['markColor']] . ')';
            }
            $parts[] = $markDescriptor;
        }
        $descriptions[] = $surfaceLabel . ($parts ? ' — ' . implode(', ', $parts) : '');
    }

    return $descriptions;
};

$formatOdontogramOrdinal = static function (int $position): string {
    static $labels = [
        1 => 'Primer odontograma',
        2 => 'Segundo odontograma',
        3 => 'Tercer odontograma',
        4 => 'Cuarto odontograma',
        5 => 'Quinto odontograma',
        6 => 'Sexto odontograma',
        7 => 'Septimo odontograma',
        8 => 'Octavo odontograma',
        9 => 'Noveno odontograma',
        10 => 'Decimo odontograma',
    ];
    if (isset($labels[$position])) {
        return $labels[$position];
    }
    return 'Odontograma ' . $position;
};

$renderToothCard = static function (
    string $code,
    array $permanentSurfaceLabels,
    array $deciduousSurfaceLabels,
    bool $isDeciduous = false
): void {
    static $clipCounter = 0;
    static $permanentSymbolCenters = [
        'top' => ['x' => 50, 'y' => 18],
        'left' => ['x' => 18, 'y' => 50],
        'center' => ['x' => 50, 'y' => 50],
        'right' => ['x' => 82, 'y' => 50],
        'bottom' => ['x' => 50, 'y' => 82],
    ];
    static $deciduousSymbolCenters = [
        'upper_left' => ['x' => 32, 'y' => 32],
        'upper_right' => ['x' => 68, 'y' => 32],
        'center' => ['x' => 50, 'y' => 50],
        'lower_right' => ['x' => 68, 'y' => 68],
        'lower_left' => ['x' => 32, 'y' => 68],
    ];
    $clipId = 'tooth-clip-' . (++$clipCounter);
    $sanitizedCode = preg_replace('/[^a-zA-Z0-9_-]/', '', $code);
    if ($sanitizedCode === '') {
        $sanitizedCode = 'tooth';
    }
    $uniqueSuffix = $sanitizedCode . '-' . $clipCounter;
    $clipOuterId = 'clipOuter-' . $uniqueSuffix;
    $clipDonutId = 'clipDonut-' . $uniqueSuffix;
    $clipSafeRingId = 'clipSafeRing-' . $uniqueSuffix;
    $clipSafeCenterId = 'clipSafeCenter-' . $uniqueSuffix;
    $symbolRingGroupId = 'tooth-symbols-ring-' . $uniqueSuffix;
    $symbolCenterGroupId = 'tooth-symbols-center-' . $uniqueSuffix;
    $symbolClipId = 'symbols-clip-' . $uniqueSuffix;
    $symbolCenters = $isDeciduous ? $deciduousSymbolCenters : $permanentSymbolCenters;
    if (!$isDeciduous) {
        $symbolCenterGroupId = $symbolRingGroupId;
    }
    $shape = $isDeciduous ? 'circle' : 'square';
    $sectorBySurface = $isDeciduous
        ? [
            'upper_left' => 'upper_left',
            'upper_right' => 'upper_right',
            'center' => 'center',
            'lower_right' => 'lower_right',
            'lower_left' => 'lower_left',
        ]
        : [
            'top' => 'upper_right',
            'left' => 'upper_left',
            'center' => 'center',
            'right' => 'lower_right',
            'bottom' => 'lower_left',
        ];
    ?>
    <div
        class="tooth-card<?= $isDeciduous ? ' tooth-card--deciduous' : '' ?>"
        data-tooth="<?= htmlspecialchars($code) ?>"
        data-symbol-group-ring="<?= htmlspecialchars($symbolRingGroupId) ?>"
        data-symbol-group-center="<?= htmlspecialchars($symbolCenterGroupId) ?>"
        data-tooth-kind="<?= $isDeciduous ? 'deciduous' : 'permanent' ?>"
        data-shape="<?= htmlspecialchars($shape) ?>"
    >
        <span class="tooth-card__code"><?= htmlspecialchars($code) ?></span>
        <?php if ($isDeciduous): ?>
            <div class="tooth-grid tooth-grid--deciduous" role="group" aria-label="Pieza <?= htmlspecialchars($code) ?>">
                <svg class="tooth-grid__svg" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid meet" aria-hidden="false" shape-rendering="geometricPrecision">
                    <defs>
                        <clipPath id="<?= htmlspecialchars($clipOuterId) ?>">
                            <circle cx="50" cy="50" r="47"></circle>
                        </clipPath>
                        <clipPath id="<?= htmlspecialchars($clipDonutId) ?>" clipPathUnits="userSpaceOnUse">
                            <path fill-rule="evenodd" d="M50,50 m-47,0 a47,47 0 1,0 94,0 a47,47 0 1,0 -94,0 M50,50 m-22.7,0 a22.7,22.7 0 1,1 45.4,0 a22.7,22.7 0 1,1 -45.4,0"></path>
                        </clipPath>
                        <clipPath id="<?= htmlspecialchars($clipSafeRingId) ?>" clipPathUnits="userSpaceOnUse">
                            <path fill-rule="evenodd" d="M50,50 m-44,0 a44,44 0 1,0 88,0 a44,44 0 1,0 -88,0 M50,50 m-25.5,0 a25.5,25.5 0 1,1 51,0 a25.5,25.5 0 1,1 -51,0"></path>
                        </clipPath>
                        <clipPath id="<?= htmlspecialchars($clipSafeCenterId) ?>" clipPathUnits="userSpaceOnUse">
                            <circle cx="50" cy="50" r="19.5"></circle>
                        </clipPath>
                    </defs>
                    <g class="tooth-grid__selections" id="<?= htmlspecialchars($clipId . '-selections') ?>" clip-path="url(#<?= htmlspecialchars($clipOuterId) ?>)">
                        <?php
                        $ringGroupOpened = false;
                        $centerSurfaceLabel = null;
                        ?>
                        <?php foreach ($deciduousSurfaceLabels as $surface => $surfaceLabel): ?>
                            <?php if ($surface === 'center'): ?>
                                <?php $centerSurfaceLabel = $surfaceLabel; ?>
                            <?php else: ?>
                                <?php if (!$ringGroupOpened): ?>
                                    <?php $ringGroupOpened = true; ?>
                                    <g class="ring" clip-path="url(#<?= htmlspecialchars($clipDonutId) ?>)">
                                <?php endif; ?>
                                <?php if ($surface === 'upper_left'): ?>
                                    <path
                                        class="tooth-cell tooth-cell--svg surface-upper_left"
                                        data-shape="circle"
                                        data-symbol-zone="ring"
                                        data-surface="upper_left"
                                        data-sector="<?= htmlspecialchars($sectorBySurface['upper_left']) ?>"
                                        id="<?= htmlspecialchars($clipId . '-upper_left') ?>"
                                        role="button"
                                        tabindex="0"
                                        pointer-events="visiblePainted"
                                        aria-label="<?= htmlspecialchars($surfaceLabel) ?>"
                                        data-symbol-x="<?= htmlspecialchars((string) ($symbolCenters[$surface]['x'] ?? 50)) ?>"
                                        data-symbol-y="<?= htmlspecialchars((string) ($symbolCenters[$surface]['y'] ?? 50)) ?>"
                                        d="M50 3 A47 47 0 0 0 3 50 L27.5 50 A22.5 22.5 0 0 1 50 27.5 Z"
                                        fill="transparent"
                                    ></path>
                                <?php elseif ($surface === 'upper_right'): ?>
                                    <path
                                        class="tooth-cell tooth-cell--svg surface-upper_right"
                                        data-shape="circle"
                                        data-symbol-zone="ring"
                                        data-surface="upper_right"
                                        data-sector="<?= htmlspecialchars($sectorBySurface['upper_right']) ?>"
                                        id="<?= htmlspecialchars($clipId . '-upper_right') ?>"
                                        role="button"
                                        tabindex="0"
                                        pointer-events="visiblePainted"
                                        aria-label="<?= htmlspecialchars($surfaceLabel) ?>"
                                        data-symbol-x="<?= htmlspecialchars((string) ($symbolCenters[$surface]['x'] ?? 50)) ?>"
                                        data-symbol-y="<?= htmlspecialchars((string) ($symbolCenters[$surface]['y'] ?? 50)) ?>"
                                        d="M50 3 A47 47 0 0 1 97 50 L72.5 50 A22.5 22.5 0 0 1 50 27.5 Z"
                                        fill="transparent"
                                    ></path>
                                <?php elseif ($surface === 'lower_right'): ?>
                                    <path
                                        class="tooth-cell tooth-cell--svg surface-lower_right"
                                        data-shape="circle"
                                        data-symbol-zone="ring"
                                        data-surface="lower_right"
                                        data-sector="<?= htmlspecialchars($sectorBySurface['lower_right']) ?>"
                                        id="<?= htmlspecialchars($clipId . '-lower_right') ?>"
                                        role="button"
                                        tabindex="0"
                                        pointer-events="visiblePainted"
                                        aria-label="<?= htmlspecialchars($surfaceLabel) ?>"
                                        data-symbol-x="<?= htmlspecialchars((string) ($symbolCenters[$surface]['x'] ?? 50)) ?>"
                                        data-symbol-y="<?= htmlspecialchars((string) ($symbolCenters[$surface]['y'] ?? 50)) ?>"
                                        d="M97 50 A47 47 0 0 1 50 97 L50 72.5 A22.5 22.5 0 0 1 72.5 50 Z"
                                        fill="transparent"
                                    ></path>
                                <?php elseif ($surface === 'lower_left'): ?>
                                    <path
                                        class="tooth-cell tooth-cell--svg surface-lower_left"
                                        data-shape="circle"
                                        data-symbol-zone="ring"
                                        data-surface="lower_left"
                                        data-sector="<?= htmlspecialchars($sectorBySurface['lower_left']) ?>"
                                        id="<?= htmlspecialchars($clipId . '-lower_left') ?>"
                                        role="button"
                                        tabindex="0"
                                        pointer-events="visiblePainted"
                                        aria-label="<?= htmlspecialchars($surfaceLabel) ?>"
                                        data-symbol-x="<?= htmlspecialchars((string) ($symbolCenters[$surface]['x'] ?? 50)) ?>"
                                        data-symbol-y="<?= htmlspecialchars((string) ($symbolCenters[$surface]['y'] ?? 50)) ?>"
                                        d="M50 97 A47 47 0 0 1 3 50 L27.5 50 A22.5 22.5 0 0 1 50 72.5 Z"
                                        fill="transparent"
                                    ></path>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if ($ringGroupOpened): ?>
                            </g>
                        <?php endif; ?>
                        <?php if ($centerSurfaceLabel !== null): ?>
                            <circle
                                class="tooth-cell tooth-cell--svg surface-center"
                                data-shape="circle"
                                data-symbol-zone="center"
                                data-surface="center"
                                data-sector="<?= htmlspecialchars($sectorBySurface['center']) ?>"
                                id="<?= htmlspecialchars($clipId . '-center') ?>"
                                role="button"
                                tabindex="0"
                                pointer-events="visiblePainted"
                                aria-label="<?= htmlspecialchars($centerSurfaceLabel) ?>"
                                data-symbol-x="<?= htmlspecialchars((string) ($symbolCenters['center']['x'] ?? 50)) ?>"
                                data-symbol-y="<?= htmlspecialchars((string) ($symbolCenters['center']['y'] ?? 50)) ?>"
                                cx="50"
                                cy="50"
                                r="22.5"
                                fill="transparent"
                            ></circle>
                        <?php endif; ?>
                    </g>
                    <g class="tooth-grid__overlay" id="<?= htmlspecialchars($clipId . '-overlay') ?>" aria-hidden="true">
                        <circle cx="50" cy="50" r="47" fill="none"></circle>
                        <circle cx="50" cy="50" r="22.5" fill="none"></circle>
                        <path d="M50 3 L50 27.5 M50 72.5 L50 97" fill="none"></path>
                        <path d="M3 50 L27.5 50 M72.5 50 L97 50" fill="none"></path>
                    </g>
                    <g
                        id="<?= htmlspecialchars($symbolRingGroupId) ?>"
                        class="tooth-symbol-layer"
                        aria-hidden="false"
                        pointer-events="none"
                        fill="none"
                        stroke="#1D4ED8"
                        stroke-width="3"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        clip-path="url(#<?= htmlspecialchars($clipSafeRingId) ?>)"
                    ></g>
                    <g
                        id="<?= htmlspecialchars($symbolCenterGroupId) ?>"
                        class="tooth-symbol-layer"
                        aria-hidden="false"
                        pointer-events="none"
                        fill="none"
                        stroke="#1D4ED8"
                        stroke-width="3"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        clip-path="url(#<?= htmlspecialchars($clipSafeCenterId) ?>)"
                    ></g>
                </svg>
            </div>
        <?php else: ?>
            <div class="tooth-grid tooth-grid--permanent" role="group" aria-label="Pieza <?= htmlspecialchars($code) ?>">
                <?php foreach ($permanentSurfaceLabels as $surface => $surfaceLabel): ?>
                    <button
                        type="button"
                        class="tooth-cell surface-<?= htmlspecialchars($surface) ?>"
                        data-surface="<?= htmlspecialchars($surface) ?>"
                        data-sector="<?= htmlspecialchars($sectorBySurface[$surface] ?? '') ?>"
                        aria-label="<?= htmlspecialchars($surfaceLabel) ?>"
                        data-shape="square"
                        data-symbol-zone="<?= htmlspecialchars($surface === 'center' ? 'center' : 'ring') ?>"
                        data-symbol-x="<?= htmlspecialchars((string) ($symbolCenters[$surface]['x'] ?? 50)) ?>"
                        data-symbol-y="<?= htmlspecialchars((string) ($symbolCenters[$surface]['y'] ?? 50)) ?>"
                    ></button>
                <?php endforeach; ?>
                <span class="tooth-grid__overlay" aria-hidden="true"></span>
                <svg
                    class="tooth-grid__symbols"
                    viewBox="0 0 100 100"
                    preserveAspectRatio="xMidYMid meet"
                    aria-hidden="true"
                    focusable="false"
                >
                    <defs>
                        <clipPath id="<?= htmlspecialchars($symbolClipId) ?>">
                            <rect x="3.4" y="3.4" width="93.2" height="93.2" rx="8.2" ry="8.2"></rect>
                        </clipPath>
                    </defs>
                    <g
                        id="<?= htmlspecialchars($symbolRingGroupId) ?>"
                        class="tooth-symbol-layer"
                        aria-hidden="false"
                        pointer-events="none"
                        fill="none"
                        stroke="#1D4ED8"
                        stroke-width="3"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        clip-path="url(#<?= htmlspecialchars($symbolClipId) ?>)"
                    ></g>
                </svg>
            </div>
        <?php endif; ?>
    </div>
    <?php
};

$renderOdontogramSection = static function (
    string $diagramKey,
    string $title,
    string $summary
) use (
    $renderToothCard,
    $odontogramGroups,
    $permanentSurfaces,
    $deciduousSurfaces,
    $odontogramStatuses
): void {
    ?>
    <fieldset class="space-y-6 rounded-2xl border border-slate-200/80 bg-white/90 p-4 sm:p-6 shadow-sm" data-odontogram-section="<?= htmlspecialchars($diagramKey) ?>">
        <legend class="px-3 text-xs font-semibold uppercase tracking-wide text-brand-700"><?= htmlspecialchars($title) ?></legend>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-slate-500"><?= htmlspecialchars($summary) ?></p>
        </div>
        <div
            class="odontogram-wrapper space-y-6"
            data-diagram="<?= htmlspecialchars($diagramKey) ?>"
            data-locked="false"
        >
            <div class="odontogram-toolbar flex flex-wrap items-center justify-between gap-6 rounded-2xl border border-slate-200/80 bg-white/95 p-4 shadow-sm">
                <div class="toolbar-group color-group flex items-center gap-3" role="radiogroup" aria-label="Seleccionar color">
                    <span class="toolbar-label text-xs font-semibold uppercase tracking-wide text-slate-500">Color</span>
                    <button type="button" class="tool-button color-option is-active" data-color="blue" aria-pressed="true">
                        <span class="sr-only">Azul</span>
                    </button>
                    <button type="button" class="tool-button color-option" data-color="red" aria-pressed="false">
                        <span class="sr-only">Rojo</span>
                    </button>
                </div>
                <div class="toolbar-group mode-group flex items-center gap-3" role="radiogroup" aria-label="Modo de edición">
                    <span class="toolbar-label text-xs font-semibold uppercase tracking-wide text-slate-500">Modo</span>
                    <button type="button" class="tool-button mode-option is-active" data-mode="color" aria-pressed="true">
                        <span class="tool-name">Color</span>
                    </button>
                    <button type="button" class="tool-button mode-option" data-mode="mark" aria-pressed="false">
                        <span class="tool-name">Marcas</span>
                    </button>
                    <button type="button" class="tool-button mode-option" data-mode="move" aria-pressed="false">
                        <span class="tool-name">Mover</span>
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
            <div class="odontogram-board space-y-10">
                <!-- Maxilar superior e inferior -->
                <div class="space-y-10">
                    <?php for ($i = 0; $i < 2; $i++): ?>
                        <?php $group = $odontogramGroups[$i]; ?>
                        <div class="odontogram-arch<?= $group['is_deciduous'] ? ' odontogram-arch--deciduous' : '' ?>">
                            <div class="odontogram-arch__header">
                                <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-600"><?= htmlspecialchars($group['label']) ?></h3>
                            </div>
                            <div class="odontogram-row">
                                <?php foreach ($group['teeth'] as $tooth): ?>
                                    <?php $renderToothCard($tooth, $permanentSurfaces, $deciduousSurfaces, $group['is_deciduous']); ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>

                <!-- Dentición temporal con panel lateral en el espacio blanco -->
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_280px]">
                    <div class="space-y-10">
                        <?php for ($i = 2; $i < 4; $i++): ?>
                            <?php $group = $odontogramGroups[$i]; ?>
                            <div class="odontogram-arch<?= $group['is_deciduous'] ? ' odontogram-arch--deciduous' : '' ?>">
                                <div class="odontogram-arch__header">
                                    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-600"><?= htmlspecialchars($group['label']) ?></h3>
                                </div>
                                <div class="odontogram-row">
                                    <?php foreach ($group['teeth'] as $tooth): ?>
                                        <?php $renderToothCard($tooth, $permanentSurfaces, $deciduousSurfaces, $group['is_deciduous']); ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>

                    <!-- Panel lateral en el espacio blanco -->
                    <aside
                        class="odontogram-metadata flex flex-col gap-4 rounded-2xl border border-slate-200/80 bg-white/95 p-4 shadow-sm self-start"
                        data-odontogram-metadata
                    >
                    <div class="odontogram-metadata__empty text-sm text-slate-500" data-odontogram-empty>
                        <p class="font-semibold text-slate-600">Selecciona una pieza</p>
                        <p class="mt-1 text-xs leading-relaxed text-slate-500">
                            Elige cualquier diente del diagrama para registrar su estado clínico y anotar observaciones relevantes.
                        </p>
                    </div>
                    <div class="odontogram-metadata__fields flex-1" data-odontogram-fields hidden>
                        <div class="space-y-3">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Pieza seleccionada</p>
                                <p class="text-lg font-semibold text-brand-600" data-odontogram-tooth>—</p>
                            </div>
                            <label class="flex flex-col gap-1 text-sm text-slate-600">
                                <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Estado</span>
                                <select class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200" data-odontogram-status>
                                    <?php foreach ($odontogramStatuses as $statusKey => $statusLabel): ?>
                                        <option value="<?= htmlspecialchars($statusKey) ?>"><?= htmlspecialchars($statusLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="flex flex-col gap-1 text-sm text-slate-600">
                                <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Notas</span>
                                <textarea
                                    rows="3"
                                    class="rounded-2xl border border-slate-300 bg-white px-3 py-2 text-sm shadow-inner focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200"
                                    data-odontogram-notes
                                    placeholder="Observaciones clínicas, hallazgos, recomendaciones..."
                                ></textarea>
                            </label>
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="rounded-full border border-slate-300 px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-slate-600 shadow-sm transition hover:bg-slate-50"
                                data-odontogram-clear
                            >
                                Limpiar campos
                            </button>
                        </div>
                    </div>
                </aside>
                </div>
            </div>
        </div>
    </fieldset>
    <?php
};

$decodedPayload = json_decode((string) $snapshot['payload'], true);
$teethPayload = [];
if (is_array($decodedPayload) && isset($decodedPayload['teeth']) && is_array($decodedPayload['teeth'])) {
    foreach ($decodedPayload['teeth'] as $toothCode => $toothData) {
        $key = trim((string) $toothCode);
        if ($key === '' || !in_array($key, $validToothCodes, true)) {
            continue;
        }
        if (!is_array($toothData)) {
            continue;
        }
        $sanitized = [
            'surfaces' => [],
        ];
        if (isset($toothData['surfaces']) && is_array($toothData['surfaces'])) {
            $sanitized['surfaces'] = $toothData['surfaces'];
        }
        if (isset($toothData['status']) && array_key_exists($toothData['status'], $odontogramStatuses)) {
            $sanitized['status'] = $toothData['status'];
        }
        if (isset($toothData['notes']) && is_string($toothData['notes'])) {
            $note = trim($toothData['notes']);
            if ($note !== '') {
                $sanitized['notes'] = $note;
            }
        }
        $teethPayload[$key] = $sanitized;
    }
}

$ordinalLabel = 'Odontograma';
$orderedSnapshotsStmt = $pdo->prepare(
    'SELECT id FROM odontogram_snapshots WHERE patient_id = :patient_id AND is_blank = 0 ORDER BY datetime(created_at) ASC, id ASC'
);
$orderedSnapshotsStmt->execute([':patient_id' => $patientId]);
$orderedSnapshotIds = $orderedSnapshotsStmt->fetchAll(PDO::FETCH_COLUMN, 0);
if ($orderedSnapshotIds) {
    foreach ($orderedSnapshotIds as $index => $orderedSnapshotId) {
        if ((int) $orderedSnapshotId === (int) $snapshot['id']) {
            $ordinalLabel = $formatOdontogramOrdinal($index + 1);
            break;
        }
    }
}

$initialPayload = [
    'odontodiagrama' => $teethPayload,
];

$initialJson = json_encode($initialPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($initialJson === false) {
    $initialJson = '{"odontodiagrama":{}}';
}

$snapshotDate = $formatDateTime($snapshot['created_at']);

$pageTitle = $ordinalLabel . ' — ' . ($patient['full_name'] ?? '');

require __DIR__ . '/templates/header.php';
?>

<section class="rounded-3xl bg-white/95 p-6 shadow-sm shadow-slate-200/60 ring-1 ring-slate-200/70 sm:p-8 space-y-6">
    <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div class="space-y-3">
            <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center rounded-full bg-brand-100 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-brand-700">
                    <?= htmlspecialchars($ordinalLabel) ?>
                </span>
                <?php if ($snapshotDate !== '—'): ?>
                    <span class="text-xs font-medium text-slate-500"><?= htmlspecialchars($snapshotDate) ?></span>
                <?php endif; ?>
                <?php if ((int) $snapshot['is_blank'] === 1): ?>
                    <span class="inline-flex items-center rounded-full bg-slate-200 px-2.5 py-0.5 text-xs font-semibold text-slate-700">
                        Plantilla en blanco
                    </span>
                <?php endif; ?>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Paciente</p>
                <h1 class="text-2xl font-semibold text-slate-900"><?= htmlspecialchars($patient['full_name'] ?? '') ?></h1>
            </div>
            <p class="text-sm text-slate-500">
                Registro guardado con los datos básicos del paciente y el odontograma completo para su seguimiento clínico.
            </p>
        </div>
        <div class="flex gap-2">
            <a href="patient.php?id=<?= $patientId ?>#odontograma" class="inline-flex items-center justify-center rounded-full border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                ← Volver al paciente
            </a>
            <form method="post" class="inline" onsubmit="return confirm('¿Estás segura de eliminar este odontograma? Esta acción no se puede deshacer.');">
                <input type="hidden" name="action" value="delete_odontogram">
                <input type="hidden" name="snapshot_id" value="<?= (int) $snapshotId ?>">
                <button type="submit" class="inline-flex items-center justify-center gap-1.5 rounded-full border-2 border-rose-200 bg-white px-4 py-2 text-sm font-semibold text-rose-600 shadow-sm transition hover:bg-rose-50 hover:border-rose-300 hover:-translate-y-0.5">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                    </svg>
                    Eliminar odontograma
                </button>
            </form>
        </div>
    </div>
    <div class="grid gap-6 lg:grid-cols-[minmax(0,280px)_1fr_minmax(0,320px)]">
        <div class="flex flex-col items-center gap-4 rounded-2xl border border-slate-200/80 bg-gradient-to-br from-white to-slate-50/50 p-6 text-center shadow-md">
            <div class="relative h-28 w-28 overflow-hidden rounded-full bg-gradient-to-br from-slate-100 to-slate-200 shadow-inner ring-4 <?= $hasProfilePhoto ? 'ring-brand-100/60' : 'ring-slate-200/80' ?>">
                <?php if ($hasProfilePhoto): ?>
                    <img
                        src="<?= htmlspecialchars($patient['profile_photo_path']) ?>"
                        alt="<?= htmlspecialchars('Foto del paciente ' . ($patient['full_name'] ?? '')) ?>"
                        class="absolute inset-0 h-full w-full object-cover"
                        style="<?= htmlspecialchars($profilePhotoStyle) ?>"
                        draggable="false"
                        loading="lazy"
                    >
                <?php else: ?>
                    <div class="absolute inset-0 flex items-center justify-center text-4xl text-slate-400 select-none">
                        <span>👤</span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="space-y-1 text-sm w-full">
                <p class="text-base font-bold text-slate-900 truncate"><?= htmlspecialchars($patient['full_name'] ?? '') ?></p>
                <?php if (!empty($patient['preferred_name'])): ?>
                    <p class="text-slate-600 text-xs">Preferido: <?= htmlspecialchars($patient['preferred_name']) ?></p>
                <?php endif; ?>
                <?php if (!empty($patient['occupation'])): ?>
                    <p class="text-slate-500 text-xs truncate"><?= htmlspecialchars($patient['occupation']) ?></p>
                <?php endif; ?>
            </div>
            <div class="w-full space-y-2 text-xs text-slate-600">
                <div class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 shadow-sm">
                    <span class="font-medium text-slate-500">Documento</span>
                    <p class="font-semibold text-slate-800 mt-0.5"><?= $formatValue($patient['document_id']) ?></p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 shadow-sm">
                    <span class="font-medium text-slate-500">Edad</span>
                    <p class="font-semibold text-slate-800 mt-0.5"><?= $patientAgeYears !== null ? $patientAgeYears . ' años' : '—' ?></p>
                </div>
                <?php if (!empty($patient['phone_primary'])): ?>
                    <div class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 shadow-sm">
                        <span class="font-medium text-slate-500">Teléfono</span>
                        <p class="font-semibold text-brand-600 hover:text-brand-700 mt-0.5">
                            <a href="tel:<?= htmlspecialchars($patient['phone_primary']) ?>"><?= htmlspecialchars($patient['phone_primary']) ?></a>
                        </p>
                    </div>
                <?php endif; ?>
                <?php if (!empty($patient['email'])): ?>
                    <div class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 shadow-sm">
                        <span class="font-medium text-slate-500">Correo</span>
                        <p class="font-semibold text-brand-600 hover:text-brand-700 mt-0.5 break-all">
                            <a href="mailto:<?= htmlspecialchars($patient['email']) ?>"><?= htmlspecialchars($patient['email']) ?></a>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="rounded-2xl border border-slate-200/80 bg-gradient-to-br from-brand-50/40 to-white/95 p-6 shadow-md space-y-5">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-600 text-white shadow-md">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <h2 class="text-base font-bold text-slate-800">Detalles del odontograma</h2>
            </div>
            <dl class="grid gap-4 sm:grid-cols-2 text-sm">
                <div class="rounded-xl border border-brand-100 bg-white/80 p-4 shadow-sm">
                    <dt class="font-semibold text-brand-700 text-xs uppercase tracking-wide mb-1">Fecha registrada</dt>
                    <dd class="text-slate-800 font-medium"><?= htmlspecialchars($snapshotDate) ?></dd>
                </div>
                <div class="rounded-xl border border-brand-100 bg-white/80 p-4 shadow-sm">
                    <dt class="font-semibold text-brand-700 text-xs uppercase tracking-wide mb-1">Tipo de registro</dt>
                    <dd class="text-slate-800 font-medium"><?= (int) $snapshot['is_blank'] === 1 ? 'Plantilla limpia' : 'Registro clínico' ?></dd>
                </div>
                <div class="sm:col-span-2 rounded-xl border border-amber-200 bg-amber-50/50 p-4 shadow-sm">
                    <dt class="font-semibold text-amber-800 text-xs uppercase tracking-wide mb-2 flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Indicaciones
                    </dt>
                    <dd class="text-amber-900 text-sm leading-relaxed">Revisa los trazos, marcas y colores registrados. Puedes añadir nuevos hallazgos o ajustar los existentes antes de guardar.</dd>
                </div>
                <?php
                $registeredTeeth = array_filter($teethPayload, function($tooth) {
                    return !empty($tooth['surfaces']) || !empty($tooth['status']) || !empty($tooth['notes']);
                });
                if (!empty($registeredTeeth)):
                ?>
                <div class="sm:col-span-2 rounded-xl border border-blue-100 bg-blue-50/50 p-4 shadow-sm">
                    <dt class="font-semibold text-blue-800 text-xs uppercase tracking-wide mb-3">Piezas registradas: <?= count($registeredTeeth) ?></dt>
                    <dd class="space-y-2 max-h-64 overflow-y-auto pr-2">
                        <?php foreach ($registeredTeeth as $toothCode => $toothData): ?>
                            <div class="text-xs border-l-2 border-blue-400 pl-3 py-1">
                                <div class="font-bold text-blue-900">Pieza <?= htmlspecialchars((string)$toothCode) ?></div>
                                <?php if (!empty($toothData['status'])): ?>
                                    <div class="text-blue-800 mt-0.5">
                                        <span class="font-medium">Estado:</span> <?= htmlspecialchars($odontogramStatuses[$toothData['status']] ?? $toothData['status']) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($toothData['surfaces'])): ?>
                                    <?php $surfaceDescriptions = $describeSurfaces($toothData['surfaces']); ?>
                                    <?php if (!empty($surfaceDescriptions)): ?>
                                        <div class="text-blue-800 mt-0.5">
                                            <span class="font-medium">Superficies:</span>
                                            <?php foreach ($surfaceDescriptions as $desc): ?>
                                                <div class="ml-2">• <?= htmlspecialchars($desc) ?></div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (!empty($toothData['notes'])): ?>
                                    <div class="text-blue-700 mt-0.5 italic">
                                        <span class="font-medium not-italic">Notas:</span> <?= htmlspecialchars($toothData['notes']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </dd>
                </div>
                <?php endif; ?>
            </dl>
        </div>
        <div class="rounded-2xl border border-slate-200/80 bg-gradient-to-br from-white to-blue-50/30 p-6 shadow-md space-y-4">
            <div class="flex items-center gap-3 pb-3 border-b border-slate-200">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-600 text-white shadow-md">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                </div>
                <h2 class="text-base font-bold text-slate-800">Información adicional</h2>
            </div>
            <div class="space-y-3">
                <?php if (!empty($patient['address'])): ?>
                    <div class="rounded-xl border border-blue-100 bg-white/80 p-3 shadow-sm">
                        <dt class="font-semibold text-blue-700 text-xs uppercase tracking-wide mb-1">Dirección</dt>
                        <dd class="text-slate-700 text-sm leading-relaxed"><?= htmlspecialchars($patient['address']) ?></dd>
                    </div>
                <?php endif; ?>
                <div class="rounded-xl border border-slate-200 bg-white/80 p-3 shadow-sm">
                    <dt class="font-semibold text-slate-600 text-xs uppercase tracking-wide mb-1">Fecha de registro</dt>
                    <dd class="text-slate-700 text-sm"><?= htmlspecialchars($registeredAtDisplay) ?></dd>
                </div>
                <div class="rounded-xl border border-green-200 bg-green-50/50 p-3 shadow-sm">
                    <dt class="font-semibold text-green-700 text-xs uppercase tracking-wide mb-1 flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Estado
                    </dt>
                    <dd class="text-green-800 text-sm font-medium">Odontograma guardado</dd>
                </div>
                <?php
                $teethWithData = count(array_filter($teethPayload, function($tooth) {
                    return !empty($tooth['surfaces']) || !empty($tooth['status']) || !empty($tooth['notes']);
                }));
                ?>
                <div class="rounded-xl border border-purple-200 bg-purple-50/50 p-3 shadow-sm">
                    <dt class="font-semibold text-purple-700 text-xs uppercase tracking-wide mb-1">Piezas registradas</dt>
                    <dd class="text-purple-800 text-2xl font-bold"><?= $teethWithData ?></dd>
                </div>
            </div>
        </div>
    </div>
    <p class="text-sm text-slate-500">Al guardar, se actualizará este odontograma registrado sin modificar el odontograma principal del paciente.</p>
</section>

<section id="odontograma-guardado" class="rounded-3xl bg-white/95 p-6 shadow-sm shadow-slate-200/60 ring-1 ring-slate-200/70 sm:p-8 space-y-6">
    <?php
        $odontogramStatusesJson = json_encode($odontogramStatuses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($odontogramStatusesJson === false) {
            $odontogramStatusesJson = '{}';
        }
    ?>
    <form
        method="post"
        action="patient.php?id=<?= $patientId ?>#odontograma"
        class="space-y-6"
        data-odontogram-form
        data-odontogram-statuses="<?= htmlspecialchars($odontogramStatusesJson, ENT_QUOTES) ?>"
        data-odontogram-default-status="<?= htmlspecialchars($defaultOdontogramStatus) ?>"
    >
        <input type="hidden" name="action" value="save_odontogram">
        <input type="hidden" name="odontogram_payload" value="<?= htmlspecialchars($initialJson, ENT_QUOTES) ?>">
        <input type="hidden" name="odontogram_base_dirty" value="0">
        <input type="hidden" name="snapshot_origin_id" value="<?= (int) $snapshot['id'] ?>">
        <input type="hidden" name="snapshot_only" value="1">

        <svg class="sr-only">
            <defs>
                <symbol id="mark-dot" overflow="visible">
                    <circle cx="0" cy="0" r="6"></circle>
                </symbol>
                <symbol id="mark-x" overflow="visible">
                    <path d="M-8 -8 L 8 8 M-8 8 L 8 -8"></path>
                </symbol>
                <symbol id="mark-vert" overflow="visible">
                    <path d="M0 -8 L 0 8"></path>
                </symbol>
                <symbol id="mark-horz" overflow="visible">
                    <path d="M-10 0 L 10 0"></path>
                </symbol>
            </defs>
        </svg>

        <?php
            $renderOdontogramSection(
                'odontodiagrama',
                $ordinalLabel,
                'Revisa los trazos, colores y notas registrados. Ajusta lo necesario y guarda para actualizar el registro clínico.'
            );
        ?>

        <div class="flex justify-end gap-3">
            <a href="patient.php?id=<?= $patientId ?>#odontograma" class="inline-flex items-center justify-center rounded-full border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                Cancelar
            </a>
            <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:-translate-y-0.5 hover:bg-brand-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500">
                Guardar cambios en odontograma
            </button>
        </div>
    </form>
</section>

<?php require __DIR__ . '/templates/footer.php'; ?>
