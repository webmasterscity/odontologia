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
$today = date('Y-m-d');

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

$formatMultiline = static function ($value, string $default = '—') use ($formatValue): string {
    if ($value === null) {
        return $default;
    }
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $default;
        }
        return nl2br(htmlspecialchars($trimmed));
    }
    return $default;
};

/**
 * Capitalize the first character of a given value while trimming surrounding whitespace.
 */
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

/**
 * Recalculate outstanding balances for all activities of a patient.
 */
function recalculateActivityBalances(PDO $pdo, int $patientId): void
{
    $fetchStmt = $pdo->prepare(
        'SELECT id, fee, payment, activity_date FROM treatment_activities WHERE patient_id = :patient_id ORDER BY date(activity_date) ASC, id ASC'
    );
    $fetchStmt->execute([':patient_id' => $patientId]);
    $activities = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$activities) {
        return;
    }

    $updateStmt = $pdo->prepare('UPDATE treatment_activities SET balance = :balance WHERE id = :id');
    $runningBalance = 0.0;
    foreach ($activities as $activity) {
        $fee = isset($activity['fee']) ? (float) $activity['fee'] : 0.0;
        $payment = isset($activity['payment']) ? (float) $activity['payment'] : 0.0;
        $runningBalance = $runningBalance + $fee - $payment;
        if ($runningBalance < 0) {
            $runningBalance = 0.0;
        }
        $updateStmt->execute([
            ':balance' => $runningBalance,
            ':id' => (int) $activity['id'],
        ]);
    }
}

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

$hasBaseOdontogram = false;

$renderOdontogramSection = static function (
    string $diagramKey,
    string $title,
    string $summary
) use (
    $renderToothCard,
    $odontogramGroups,
    $permanentSurfaces,
    $deciduousSurfaces,
    &$hasBaseOdontogram
): void {
    $isBaseDiagram = $diagramKey === 'odontodiagrama';
    $baseIsLocked = $isBaseDiagram && $hasBaseOdontogram;
    ?>
    <fieldset class="space-y-6 rounded-2xl border border-slate-200/80 bg-white/90 p-4 sm:p-6 shadow-sm" data-odontogram-section="<?= htmlspecialchars($diagramKey) ?>">
        <legend class="px-3 text-xs font-semibold uppercase tracking-wide text-brand-700"><?= htmlspecialchars($title) ?></legend>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-slate-500"><?= htmlspecialchars($summary) ?></p>
            <?php if ($isBaseDiagram && $hasBaseOdontogram): ?>
                <button
                    type="button"
                    class="odontogram-toggle-button inline-flex items-center justify-center gap-2 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:-translate-y-0.5 hover:bg-brand-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 self-start sm:self-start"
                    data-odontogram-toggle="odontodiagrama"
                    data-label-locked="Editar odontograma base"
                    data-label-unlocked="Bloquear odontograma base"
                >
                    Editar odontograma base
                </button>
            <?php endif; ?>
        </div>
        <div
            class="odontogram-wrapper space-y-6<?= $baseIsLocked ? ' is-locked' : '' ?>"
            data-diagram="<?= htmlspecialchars($diagramKey) ?>"
            data-locked="<?= $baseIsLocked ? 'true' : 'false' ?>"
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
            <div class="odontogram-canvas space-y-10">
                <?php foreach ($odontogramGroups as $group): ?>
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
                <?php endforeach; ?>
            </div>
        </div>
    </fieldset>
    <?php
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'save_odontogram') {
        $rawPayload = (string) post('odontogram_payload', '');
        $decodedPayload = json_decode($rawPayload, true);
        if (!is_array($decodedPayload)) {
            $errors[] = 'El formato del odontograma no es válido.';
        } else {
            $baseDirty = (string) post('odontogram_base_dirty') === '1';
            $submittedEvolutionTeeth = [];
            if (!empty($decodedPayload['evolucion']) && is_array($decodedPayload['evolucion'])) {
                foreach ($decodedPayload['evolucion'] as $submittedTooth => $submittedPayload) {
                    $submittedToothKey = trim((string) $submittedTooth);
                    if ($submittedToothKey === '') {
                        continue;
                    }
                    if (is_array($submittedPayload)) {
                        $submittedEvolutionTeeth[$submittedToothKey] = true;
                    }
                }
            }
            $diagramKeys = ['odontodiagrama', 'evolucion'];
            $normalized = [];
            foreach ($diagramKeys as $diagramKey) {
                if (empty($decodedPayload[$diagramKey]) || !is_array($decodedPayload[$diagramKey])) {
                    continue;
                }
                foreach ($decodedPayload[$diagramKey] as $toothCode => $toothPayload) {
                    if (!is_array($toothPayload)) {
                        continue;
                    }

                    $toothCodeKey = trim((string) $toothCode);
                    if ($toothCodeKey === '' || !in_array($toothCodeKey, $validToothCodes, true)) {
                        continue;
                    }
                    $surfaces = $toothPayload['surfaces'] ?? [];
                    $skipBaseSurfaces = $diagramKey === 'odontodiagrama' && !$baseDirty;
                    if (is_array($surfaces) && !$skipBaseSurfaces) {
                        foreach ($surfaces as $surfaceKey => $surfaceData) {
                            if (!array_key_exists($surfaceKey, $allSurfaces) || !is_array($surfaceData)) {
                                continue;
                            }
                            $color = $surfaceData['color'] ?? '';
                            if ($color !== '' && !in_array($color, ['blue', 'red'], true)) {
                                $color = '';
                            }
                            $mark = $surfaceData['mark'] ?? '';
                            if (!in_array($mark, ['dot', 'x', 'vertical', 'horizontal'], true)) {
                                $mark = '';
                            }
                            $markColor = $surfaceData['markColor'] ?? '';
                            if ($mark !== '') {
                                if (!in_array($markColor, ['blue', 'red'], true)) {
                                    $markColor = $color !== '' ? $color : 'blue';
                                }
                                $position = null;
                                if (isset($surfaceData['position']) && is_array($surfaceData['position'])) {
                                    $posX = $surfaceData['position']['x'] ?? null;
                                    $posY = $surfaceData['position']['y'] ?? null;
                                    if (is_numeric($posX) && is_numeric($posY)) {
                                        $posX = max(0, min(100, (float) $posX));
                                        $posY = max(0, min(100, (float) $posY));
                                        $position = [
                                            'x' => round($posX, 2),
                                            'y' => round($posY, 2),
                                        ];
                                    }
                                }
                            } else {
                                $markColor = '';
                                $position = null;
                            }
                            if ($color === '' && $mark === '') {
                                continue;
                            }
                            $surfacePayload = [
                                'color' => $color,
                                'mark' => $mark,
                            ];
                            if ($mark !== '') {
                                $surfacePayload['markColor'] = $markColor;
                                if ($position !== null) {
                                    $surfacePayload['position'] = $position;
                                }
                            }
                            $normalized[$toothCodeKey][$diagramKey]['surfaces'][$surfaceKey] = $surfacePayload;
                        }
                    }
                    if (isset($toothPayload['status']) && array_key_exists($toothPayload['status'], $odontogramStatuses)) {
                        $normalized[$toothCodeKey]['status'] = $toothPayload['status'];
                    }
                    if (isset($toothPayload['notes']) && is_string($toothPayload['notes'])) {
                        $note = trim($toothPayload['notes']);
                        $normalized[$toothCodeKey]['notes'] = $note !== '' ? $note : null;
                    }
                }
            }

            foreach ($submittedEvolutionTeeth as $submittedToothKey => $_) {
                if (!isset($normalized[$submittedToothKey])) {
                    $normalized[$submittedToothKey] = [];
                }
                if (!isset($normalized[$submittedToothKey]['evolucion'])) {
                    $normalized[$submittedToothKey]['evolucion'] = ['surfaces' => []];
                } elseif (!isset($normalized[$submittedToothKey]['evolucion']['surfaces'])) {
                    $normalized[$submittedToothKey]['evolucion']['surfaces'] = [];
                }
            }

            try {
                $pdo->beginTransaction();
                $existingEvolutionSurfaces = [];
                $existingBaseSurfaces = [];
                $existingStmt = $pdo->prepare('SELECT tooth_code, surface_data FROM odontogram_entries WHERE patient_id = :patient_id');
                $existingStmt->execute([':patient_id' => $patientId]);
                foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $existingEntry) {
                    $existingTooth = trim((string) ($existingEntry['tooth_code'] ?? ''));
                    if ($existingTooth === '') {
                        continue;
                    }
                    $existingEvolutionSurfaces[$existingTooth] = [];
                    $existingBaseSurfaces[$existingTooth] = [];
                    if (empty($existingEntry['surface_data'])) {
                        continue;
                    }
                    $existingSurfaceData = json_decode((string) $existingEntry['surface_data'], true);
                    if (!is_array($existingSurfaceData)) {
                        continue;
                    }
                    if (!empty($existingSurfaceData['odontodiagrama']) && is_array($existingSurfaceData['odontodiagrama'])) {
                        $baseSurfaces = [];
                        foreach ($existingSurfaceData['odontodiagrama'] as $surfaceKey => $surfacePayload) {
                            if (!is_array($surfacePayload)) {
                                continue;
                            }
                            $hasColor = isset($surfacePayload['color']) && trim((string) $surfacePayload['color']) !== '';
                            $hasMark = isset($surfacePayload['mark']) && trim((string) $surfacePayload['mark']) !== '';
                            if ($hasColor || $hasMark) {
                                $baseSurfaces[$surfaceKey] = $surfacePayload;
                            }
                        }
                        $existingBaseSurfaces[$existingTooth] = $baseSurfaces;
                    }
                    if (!empty($existingSurfaceData['evolucion']) && is_array($existingSurfaceData['evolucion'])) {
                        foreach ($existingSurfaceData['evolucion'] as $surfaceKey => $surfacePayload) {
                            if (!is_array($surfacePayload)) {
                                continue;
                            }
                            $hasColor = isset($surfacePayload['color']) && trim((string) $surfacePayload['color']) !== '';
                            $hasMark = isset($surfacePayload['mark']) && trim((string) $surfacePayload['mark']) !== '';
                            if ($hasColor || $hasMark) {
                                $existingEvolutionSurfaces[$existingTooth][$surfaceKey] = true;
                            }
                        }
                    }
                }

                if (!$baseDirty) {
                    foreach ($existingBaseSurfaces as $existingTooth => $baseSurfaces) {
                        if (empty($baseSurfaces)) {
                            continue;
                        }
                        if (!isset($normalized[$existingTooth])) {
                            $normalized[$existingTooth] = [];
                        }
                        if (empty($normalized[$existingTooth]['odontodiagrama']['surfaces'])) {
                            $normalized[$existingTooth]['odontodiagrama']['surfaces'] = $baseSurfaces;
                        }
                    }
                }

                $pdo->prepare('DELETE FROM odontogram_entries WHERE patient_id = :patient_id')
                    ->execute([':patient_id' => $patientId]);

                foreach ($normalized as $toothCode => $diagramData) {
                    $toothCodeKey = trim((string) $toothCode);
                    if ($toothCodeKey === '') {
                        continue;
                    }
                    $surfacesPayload = [
                        'odontodiagrama' => $diagramData['odontodiagrama']['surfaces'] ?? [],
                        'evolucion' => $diagramData['evolucion']['surfaces'] ?? [],
                    ];

                    $baseSurfaces = $surfacesPayload['odontodiagrama'];
                    $hasBaseSurfaces = !empty($baseSurfaces);
                    $hasSubmittedEvolution = isset($submittedEvolutionTeeth[$toothCodeKey]);
                    if ($hasBaseSurfaces) {
                        if (!is_array($surfacesPayload['evolucion'])) {
                            $surfacesPayload['evolucion'] = [];
                        }
                        if ($baseDirty) {
                            foreach ($baseSurfaces as $surfaceKey => $surfaceState) {
                                $surfacesPayload['evolucion'][$surfaceKey] = $surfaceState;
                            }
                        } elseif (!$hasSubmittedEvolution) {
                            $existingEvolutionForTooth = $existingEvolutionSurfaces[$toothCodeKey] ?? [];
                            if (empty($surfacesPayload['evolucion'])) {
                                if (empty($existingEvolutionForTooth)) {
                                    $surfacesPayload['evolucion'] = $baseSurfaces;
                                }
                            } else {
                                foreach ($baseSurfaces as $surfaceKey => $surfaceState) {
                                    if (isset($surfacesPayload['evolucion'][$surfaceKey]) || isset($existingEvolutionForTooth[$surfaceKey])) {
                                        continue;
                                    }
                                    $surfacesPayload['evolucion'][$surfaceKey] = $surfaceState;
                                }
                            }
                        }
                    }

                    if (empty($surfacesPayload['odontodiagrama']) && empty($surfacesPayload['evolucion'])) {
                        continue;
                    }

                    $surfaceJson = json_encode($surfacesPayload, JSON_UNESCAPED_UNICODE);
                    if ($surfaceJson === false) {
                        continue;
                    }

                    upsertOdontogramEntry($pdo, $patientId, $toothCodeKey, [
                        'status' => $diagramData['status'] ?? 'sin_registro',
                        'surface_data' => $surfaceJson,
                        'notes' => $diagramData['notes'] ?? null,
                    ]);
                }

                $pdo->commit();
                $messages[] = 'Odontograma guardado correctamente.';
                header('Location: patient.php?id=' . $patientId . '#odontograma');
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('Odontogram save failed: ' . $e->getMessage());
                $errors[] = 'No se pudo guardar el odontograma. Inténtalo nuevamente.';
            }
        }
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
                'subjective_notes' => capitalizeInitial(post('subjective_notes')),
                'objective_notes' => capitalizeInitial(post('objective_notes')),
                'assessment' => capitalizeInitial(post('assessment')),
                'plan' => capitalizeInitial(post('plan')),
                'vitals_bp' => capitalizeInitial(post('vitals_bp')),
                'vitals_hr' => capitalizeInitial(post('vitals_hr')),
                'vitals_temp' => capitalizeInitial(post('vitals_temp')),
                'vitals_oxygen' => capitalizeInitial(post('vitals_oxygen')),
                'next_appointment' => normalizeDate(post('next_appointment')),
            ]);
            $messages[] = 'Consulta registrada correctamente.';
            header('Location: patient.php?id=' . $patientId . '#visitas');
            exit;
        }
    }

    if ($action === 'update_visit') {
        $visitId = (int) post('visit_id');
        if ($visitId <= 0) {
            $errors[] = 'Consulta no válida.';
        } else {
            $visitExistsStmt = $pdo->prepare('SELECT id FROM visits WHERE id = :id AND patient_id = :patient_id');
            $visitExistsStmt->execute([':id' => $visitId, ':patient_id' => $patientId]);
            if (!$visitExistsStmt->fetchColumn()) {
                $errors[] = 'No se encontró la consulta seleccionada.';
            }
        }

        $visitDate = normalizeDate(post('visit_date'));
        if (!$visitDate) {
            $errors[] = 'La fecha de la consulta es obligatoria.';
        }

        if (!$errors) {
            $updateStmt = $pdo->prepare(
                'UPDATE visits SET visit_date = :visit_date, subjective_notes = :subjective_notes, objective_notes = :objective_notes, assessment = :assessment, plan = :plan, vitals_bp = :vitals_bp, vitals_hr = :vitals_hr, vitals_temp = :vitals_temp, vitals_oxygen = :vitals_oxygen, next_appointment = :next_appointment WHERE id = :id AND patient_id = :patient_id'
            );
            $updateStmt->execute([
                ':visit_date' => $visitDate,
                ':subjective_notes' => capitalizeInitial(post('subjective_notes')),
                ':objective_notes' => capitalizeInitial(post('objective_notes')),
                ':assessment' => capitalizeInitial(post('assessment')),
                ':plan' => capitalizeInitial(post('plan')),
                ':vitals_bp' => capitalizeInitial(post('vitals_bp')),
                ':vitals_hr' => capitalizeInitial(post('vitals_hr')),
                ':vitals_temp' => capitalizeInitial(post('vitals_temp')),
                ':vitals_oxygen' => capitalizeInitial(post('vitals_oxygen')),
                ':next_appointment' => normalizeDate(post('next_appointment')),
                ':id' => $visitId,
                ':patient_id' => $patientId,
            ]);
            $messages[] = 'Consulta actualizada correctamente.';
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

            $balanceQuery = $pdo->prepare('SELECT balance FROM treatment_activities WHERE patient_id = :id ORDER BY date(activity_date) DESC, id DESC LIMIT 1');
            $balanceQuery->execute([':id' => $patientId]);
            $previousOutstanding = (float) $balanceQuery->fetchColumn();
            if ($previousOutstanding < 0) {
                $previousOutstanding = 0.0;
            }

            $balance = $previousOutstanding + $fee - $payment;
            if ($balance < 0) {
                $balance = 0.0;
            }

            insertRow($pdo, 'treatment_activities', [
                'patient_id' => $patientId,
                'visit_id' => post('related_visit') ? (int) post('related_visit') : null,
                'activity_date' => $activityDate,
                'description' => capitalizeInitial($description),
                'fee' => $fee,
                'payment' => $payment,
                'balance' => $balance,
                'notes' => capitalizeInitial(post('activity_notes')),
            ]);
            recalculateActivityBalances($pdo, $patientId);
            $messages[] = 'Actividad registrada.';
            header('Location: patient.php?id=' . $patientId . '#actividades');
            exit;
        }
    }

    if ($action === 'update_activity') {
        $activityId = (int) post('activity_id');
        if ($activityId <= 0) {
            $errors[] = 'Actividad no válida.';
        } else {
            $activityExistsStmt = $pdo->prepare('SELECT id FROM treatment_activities WHERE id = :id AND patient_id = :patient_id');
            $activityExistsStmt->execute([':id' => $activityId, ':patient_id' => $patientId]);
            if (!$activityExistsStmt->fetchColumn()) {
                $errors[] = 'No se encontró la actividad seleccionada.';
            }
        }

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
            $updateStmt = $pdo->prepare(
                'UPDATE treatment_activities SET visit_id = :visit_id, activity_date = :activity_date, description = :description, fee = :fee, payment = :payment, notes = :notes WHERE id = :id AND patient_id = :patient_id'
            );
            $updateStmt->execute([
                ':visit_id' => post('related_visit') ? (int) post('related_visit') : null,
                ':activity_date' => $activityDate,
                ':description' => capitalizeInitial($description),
                ':fee' => $fee,
                ':payment' => $payment,
                ':notes' => capitalizeInitial(post('activity_notes')),
                ':id' => $activityId,
                ':patient_id' => $patientId,
            ]);
            recalculateActivityBalances($pdo, $patientId);
            $messages[] = 'Actividad actualizada correctamente.';
            header('Location: patient.php?id=' . $patientId . '#actividades');
            exit;
        }
    }

    if ($action === 'delete_activity') {
        $activityId = (int) post('activity_id');
        $stmt = $pdo->prepare('DELETE FROM treatment_activities WHERE id = :id AND patient_id = :patient_id');
        $stmt->execute([':id' => $activityId, ':patient_id' => $patientId]);
        recalculateActivityBalances($pdo, $patientId);
        $messages[] = 'Actividad eliminada.';
        header('Location: patient.php?id=' . $patientId . '#actividades');
        exit;
    }
}

$profileStmt = $pdo->prepare('SELECT * FROM clinical_profiles WHERE patient_id = :id');
$profileStmt->execute([':id' => $patientId]);
$profile = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$odontogramStmt = $pdo->prepare('SELECT tooth_code, status, notes, surface_data FROM odontogram_entries WHERE patient_id = :id');
$odontogramStmt->execute([':id' => $patientId]);
$odontogramPayload = [
    'odontodiagrama' => [],
    'evolucion' => [],
];
$evolutionHasRecord = [];
foreach ($odontogramStmt->fetchAll(PDO::FETCH_ASSOC) as $entry) {
    $decodedSurfaces = [];
    if (!empty($entry['surface_data'])) {
        $decoded = json_decode($entry['surface_data'], true);
        if (is_array($decoded)) {
            $decodedSurfaces = $decoded;
        }
    }
    if (array_key_exists('evolucion', $decodedSurfaces) && is_array($decodedSurfaces['evolucion'])) {
        $evolutionHasRecord[$entry['tooth_code']] = true;
    }
    foreach (['odontodiagrama', 'evolucion'] as $diagramKey) {
        if (!array_key_exists($diagramKey, $decodedSurfaces) || !is_array($decodedSurfaces[$diagramKey])) {
            continue;
        }
        $odontogramPayload[$diagramKey][$entry['tooth_code']] = [
            'surfaces' => $decodedSurfaces[$diagramKey],
            'status' => $entry['status'],
            'notes' => $entry['notes'],
        ];
    }
}

// Ensure evolution diagram starts with the base odontogram when empty so clinicians can adjust it.
$hasBaseOdontogram = false;
foreach ($odontogramPayload['odontodiagrama'] as $toothCode => $baseEntry) {
    $baseSurfaces = $baseEntry['surfaces'] ?? [];
    if (!$baseSurfaces) {
        continue;
    }
    if (!$hasBaseOdontogram) {
        foreach ($baseSurfaces as $surfaceState) {
            if (!is_array($surfaceState)) {
                continue;
            }
            $color = isset($surfaceState['color']) ? trim((string) $surfaceState['color']) : '';
            $mark = isset($surfaceState['mark']) ? trim((string) $surfaceState['mark']) : '';
            if ($color !== '' || $mark !== '') {
                $hasBaseOdontogram = true;
                break;
            }
        }
    }
    if ($evolutionHasRecord[$toothCode] ?? false) {
        continue;
    }
    if (!isset($odontogramPayload['evolucion'][$toothCode]) || empty($odontogramPayload['evolucion'][$toothCode]['surfaces'])) {
        $clone = [];
        foreach ($baseSurfaces as $surfaceKey => $surfaceState) {
            if (!is_array($surfaceState)) {
                continue;
            }
            $clone[$surfaceKey] = $surfaceState;
        }
        $odontogramPayload['evolucion'][$toothCode] = [
            'surfaces' => $clone,
            'status' => $baseEntry['status'] ?? 'sin_registro',
            'notes' => $baseEntry['notes'] ?? null,
        ];
    }
}

$odontogramInitialJson = json_encode($odontogramPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($odontogramInitialJson === false) {
    $odontogramInitialJson = '{"odontodiagrama":{},"evolucion":{}}';
}

$visitsStmt = $pdo->prepare('SELECT * FROM visits WHERE patient_id = :id ORDER BY date(visit_date) DESC, id DESC');
$visitsStmt->execute([':id' => $patientId]);
$visits = $visitsStmt->fetchAll(PDO::FETCH_ASSOC);

$activitiesStmt = $pdo->prepare('SELECT * FROM treatment_activities WHERE patient_id = :id ORDER BY date(activity_date) DESC, id DESC');
$activitiesStmt->execute([':id' => $patientId]);
$activities = $activitiesStmt->fetchAll(PDO::FETCH_ASSOC);

$activityPreviousOutstanding = [];
$latestOutstanding = 0.0;
if ($activities) {
    $activitiesChronological = $activities;
    usort(
        $activitiesChronological,
        static function (array $a, array $b): int {
            $dateComparison = strcmp((string) $a['activity_date'], (string) $b['activity_date']);
            if ($dateComparison !== 0) {
                return $dateComparison;
            }
            return ((int) $a['id']) <=> ((int) $b['id']);
        }
    );
    $runningBalance = 0.0;
    foreach ($activitiesChronological as $activityRow) {
        $activityPreviousOutstanding[(int) $activityRow['id']] = $runningBalance;
        $fee = isset($activityRow['fee']) ? (float) $activityRow['fee'] : 0.0;
        $payment = isset($activityRow['payment']) ? (float) $activityRow['payment'] : 0.0;
        $runningBalance = $runningBalance + $fee - $payment;
        if ($runningBalance < 0) {
            $runningBalance = 0.0;
        }
    }
    $latestOutstanding = $runningBalance;
}
$totalBalance = $latestOutstanding;

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
$birthDateDisplay = '—';
if (!empty($patient['birth_date'])) {
    $timestamp = strtotime((string) $patient['birth_date']);
    if ($timestamp !== false) {
        $birthDateDisplay = date('d/m/Y', $timestamp);
    }
}
?>
<section class="rounded-3xl bg-white/95 p-6 shadow-sm shadow-slate-200/60 ring-1 ring-slate-200/70 sm:p-8 space-y-6">
    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-slate-900">Datos del paciente</h2>
            <p class="text-sm text-slate-500">Resumen actualizado de <span class="font-semibold text-slate-700">"<?= htmlspecialchars($patient['full_name']) ?>"</span>.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a class="inline-flex items-center justify-center gap-2 rounded-full border border-brand-200 bg-brand-50 px-4 py-2 text-xs font-semibold text-brand-700 transition hover:-translate-y-0.5 hover:bg-brand-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500" href="patient_history.php?id=<?= $patientId ?>">
                Historia clínica
            </a>
            <a class="inline-flex items-center rounded-full border border-slate-200 px-3.5 py-1.5 text-xs font-semibold text-slate-600 transition hover:border-brand-200 hover:text-brand-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500" href="patient_form.php?id=<?= $patientId ?>">
                Editar paciente
            </a>
            <a class="inline-flex items-center justify-center gap-2 rounded-full bg-brand-600 px-4 py-2 text-xs font-semibold text-white shadow-soft transition hover:-translate-y-0.5 hover:bg-brand-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500" href="index.php">
                Volver al listado
            </a>
        </div>
    </div>
    <div class="grid gap-6 lg:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-2xl border border-slate-200/80 bg-slate-50/70 p-6 shadow-inner xl:col-span-2">
            <h3 class="mb-6 text-xs font-semibold uppercase tracking-wide text-slate-600">Información general</h3>
            <dl class="space-y-4 text-sm text-slate-600">
                <div class="flex justify-between gap-4 border-b border-slate-200/60 pb-3 mb-3">
                    <dt class="font-semibold text-slate-700">Nombres y apellidos:</dt>
                    <dd class="text-right font-medium text-slate-900"><?= $formatValue($patient['full_name'] ?? null) ?></dd>
                </div>
                <div class="flex justify-between gap-4 border-b border-slate-200/60 pb-3">
                    <dt class="font-medium text-slate-700">Nombre preferido:</dt>
                    <dd class="text-right"><?= $formatValue($patient['preferred_name'] ?? null) ?></dd>
                </div>
                <div class="flex justify-between gap-4 border-b border-slate-200/60 pb-3">
                    <dt class="font-medium text-slate-700">Documento:</dt>
                    <dd class="text-right"><?= $formatValue($patient['document_id'] ?? null) ?></dd>
                </div>
                <div class="flex justify-between gap-4 border-b border-slate-200/60 pb-3">
                    <dt class="font-medium text-slate-700">Fecha de nacimiento:</dt>
                    <dd class="text-right"><?= htmlspecialchars($birthDateDisplay) ?></dd>
                </div>
                <div class="flex justify-between gap-4 border-b border-slate-200/60 pb-3">
                    <dt class="font-medium text-slate-700">Edad:</dt>
                    <dd class="text-right"><?= $patient['age'] ? (int) $patient['age'] . ' años' : '—' ?></dd>
                </div>
                <div class="flex justify-between gap-4 border-b border-slate-200/60 pb-3">
                    <dt class="font-medium text-slate-700">Género:</dt>
                    <dd class="text-right"><?= $formatValue($patient['gender'] ?? null) ?></dd>
                </div>
                <div class="flex justify-between gap-4 border-b border-slate-200/60 pb-3">
                    <dt class="font-medium text-slate-700">Estado civil:</dt>
                    <dd class="text-right"><?= $formatValue($patient['marital_status'] ?? null) ?></dd>
                </div>
                <div class="flex justify-between gap-4 border-b border-slate-200/60 pb-3">
                    <dt class="font-medium text-slate-700">Ocupación:</dt>
                    <dd class="text-right"><?= $formatValue($patient['occupation'] ?? null) ?></dd>
                </div>
                <div class="flex justify-between gap-4 pb-2">
                    <dt class="font-medium text-slate-700">Dirección:</dt>
                    <dd class="text-right"><?= $formatValue($patient['address'] ?? null) ?></dd>
                </div>
            </dl>
        </div>
        <div class="rounded-2xl border border-slate-200/80 bg-white p-6 xl:col-span-2 space-y-5">
            <div>
                <h3 class="mb-6 text-xs font-semibold uppercase tracking-wide text-slate-600">Red de contacto</h3>
                <dl class="space-y-3 text-sm text-slate-600">
                    <div class="flex justify-between gap-4 border-b border-slate-200/60 pb-3">
                        <dt class="font-medium text-slate-700">Correo:</dt>
                        <dd class="text-right break-words"><?= $formatValue($patient['email'] ?? null) ?></dd>
                    </div>
                    <div class="flex justify-between gap-4 border-b border-slate-200/60 pb-3">
                        <dt class="font-medium text-slate-700">Tel. principal:</dt>
                        <dd class="text-right"><?= $formatValue($patient['phone_primary'] ?? null) ?></dd>
                    </div>
                    <div class="flex justify-between gap-4 pb-2">
                        <dt class="font-medium text-slate-700">Tel. alterno:</dt>
                        <dd class="text-right"><?= $formatValue($patient['phone_secondary'] ?? null) ?></dd>
                    </div>
                </dl>
            </div>
            <div class="rounded-xl border border-brand-100/70 bg-brand-50/50 p-5">
                <h4 class="mb-5 text-xs font-semibold uppercase tracking-wide text-brand-700">Contacto de emergencia</h4>
                <dl class="space-y-3 text-sm text-brand-800">
                    <div class="flex justify-between gap-4 border-b border-brand-200/50 pb-3">
                        <dt class="font-medium">Nombres:</dt>
                        <dd class="text-right"><?= $formatValue($patient['emergency_contact'] ?? null) ?></dd>
                    </div>
                    <div class="flex justify-between gap-4 border-b border-brand-200/50 pb-3">
                        <dt class="font-medium">Parentesco:</dt>
                        <dd class="text-right"><?= $formatValue($patient['emergency_contact_relationship'] ?? null) ?></dd>
                    </div>
                    <div class="flex justify-between gap-4 pb-1">
                        <dt class="font-medium">Teléfono:</dt>
                        <dd class="text-right"><?= $formatValue($patient['emergency_contact_phone'] ?? null) ?></dd>
                    </div>
                </dl>
            </div>
            <div class="rounded-xl border border-slate-200/70 bg-slate-50/80 p-5">
                <h4 class="mb-5 text-xs font-semibold uppercase tracking-wide text-slate-600">Responsable legal</h4>
                <dl class="space-y-3 text-sm text-slate-600">
                    <div class="flex justify-between gap-4 border-b border-slate-200/60 pb-3">
                        <dt class="font-medium text-slate-700">Nombres:</dt>
                        <dd class="text-right"><?= $formatValue($patient['representative_name'] ?? null) ?></dd>
                    </div>
                    <div class="flex justify-between gap-4 border-b border-slate-200/60 pb-3">
                        <dt class="font-medium text-slate-700">Documento:</dt>
                        <dd class="text-right"><?= $formatValue($patient['representative_document'] ?? null) ?></dd>
                    </div>
                    <div class="flex justify-between gap-4 pb-1">
                        <dt class="font-medium text-slate-700">Teléfono:</dt>
                        <dd class="text-right"><?= $formatValue($patient['representative_phone'] ?? null) ?></dd>
                    </div>
                </dl>
            </div>
        </div>
        <div class="rounded-2xl border border-slate-200/80 bg-white p-6 xl:col-span-2">
            <h3 class="mb-6 text-xs font-semibold uppercase tracking-wide text-slate-600">Información administrativa</h3>
            <dl class="space-y-3 text-sm text-slate-600">
                <div class="flex justify-between gap-4 border-b border-slate-200/60 pb-3">
                    <dt class="font-medium text-slate-700">Referido por:</dt>
                    <dd class="text-right"><?= $formatValue($patient['referred_by'] ?? null) ?></dd>
                </div>
                <div class="flex justify-between gap-4 border-b border-slate-200/60 pb-3">
                    <dt class="font-medium text-slate-700">Médico tratante:</dt>
                    <dd class="text-right"><?= $formatValue($patient['primary_physician'] ?? null) ?></dd>
                </div>
                <div class="flex justify-between gap-4 border-b border-slate-200/60 pb-3">
                    <dt class="font-medium text-slate-700">Tel. del médico:</dt>
                    <dd class="text-right"><?= $formatValue($patient['primary_physician_phone'] ?? null) ?></dd>
                </div>
                <div class="flex justify-between gap-4 border-b border-slate-200/60 pb-3">
                    <dt class="font-medium text-slate-700">Aseguradora:</dt>
                    <dd class="text-right"><?= $formatValue($patient['insurance_provider'] ?? null) ?></dd>
                </div>
                <div class="flex justify-between gap-4 pb-2">
                    <dt class="font-medium text-slate-700">N.º de póliza:</dt>
                    <dd class="text-right"><?= $formatValue($patient['insurance_policy_number'] ?? null) ?></dd>
                </div>
            </dl>
        </div>
        <div class="rounded-2xl border <?= $hasAlert ? 'border-amber-200/70 bg-amber-50/80' : 'border-emerald-200/70 bg-emerald-50/80' ?> p-6 xl:col-span-2">
            <h3 class="mb-6 text-xs font-semibold uppercase tracking-wide <?= $hasAlert ? 'text-amber-700' : 'text-emerald-700' ?>">Alertas y saldo</h3>
            <div class="space-y-4 text-sm <?= $hasAlert ? 'text-amber-700' : 'text-emerald-700' ?>">
                <p class="leading-relaxed"><?= $hasAlert ? nl2br(htmlspecialchars((string) $alertText)) : 'Sin alertas registradas.' ?></p>
                <div class="inline-flex items-center gap-2 rounded-full bg-white/80 px-5 py-2.5 text-sm font-semibold shadow-sm <?= $hasAlert ? 'text-amber-700' : 'text-emerald-700' ?>">
                    Saldo pendiente: Bs <?= number_format(max($totalBalance, 0), 2, ',', '.') ?>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="rounded-3xl bg-white/95 p-6 shadow-sm shadow-slate-200/60 ring-1 ring-slate-200/70 sm:p-8 space-y-6" id="odontograma">
    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div class="space-y-1">
            <h2 class="text-2xl font-semibold text-slate-900">Odontograma</h2>
            <p class="text-sm text-slate-500">Registra el odontodiagrama inicial y su evolución clínica pieza por pieza.</p>
        </div>
        <p class="text-xs text-slate-400 md:text-right">Los cambios se almacenan en la base de datos cuando presionas “Guardar odontograma”.</p>
    </div>
    <form method="post" class="space-y-6" data-odontogram-form>
        <input type="hidden" name="action" value="save_odontogram">
        <input type="hidden" name="odontogram_payload" value="<?= htmlspecialchars($odontogramInitialJson, ENT_QUOTES) ?>">
        <input type="hidden" name="odontogram_base_dirty" value="0">
        <svg
            aria-hidden="true"
            focusable="false"
            class="odontogram-symbol-defs"
            width="0"
            height="0"
        >
            <defs>
                <symbol id="mark-x" overflow="visible">
                    <path d="M-6 -6 L 6 6 M 6 -6 L -6 6"></path>
                </symbol>
                <symbol id="mark-dot" overflow="visible">
                    <circle cx="0" cy="0" r="2.8"></circle>
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
                'Odontodiagrama',
                'Dibuja hallazgos iniciales según el examen físico y radiográfico.'
            );
            $renderOdontogramSection(
                'evolucion',
                'Evolución',
                'Actualiza los cambios observados durante el seguimiento del tratamiento.'
            );
        ?>
        <div class="flex justify-end">
            <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:-translate-y-0.5 hover:bg-brand-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500">
                Guardar odontograma
            </button>
        </div>
    </form>
</section>



<section class="rounded-3xl bg-white/95 p-6 shadow-sm shadow-slate-200/60 ring-1 ring-slate-200/70 sm:p-8 space-y-6" id="historia">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div class="space-y-1">
            <h2 class="text-2xl font-semibold text-slate-900">Historia clínica</h2>
            <p class="text-sm text-slate-500">Revisa los antecedentes registrados y actualízalos cuando sea necesario.</p>
        </div>
        <a class="inline-flex items-center justify-center gap-2 rounded-full bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:-translate-y-0.5 hover:bg-brand-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500" href="patient_history.php?id=<?= $patientId ?>">
            Editar historia clínica
        </a>
    </div>
    <?php
    $antecedentLabels = [
        'antecedent_ent' => 'Oído, nariz y garganta',
        'antecedent_respiratory' => 'Respiratorio',
        'antecedent_allergy' => 'Alergia',
        'antecedent_cardiovascular' => 'Cardiovascular',
        'antecedent_gastrointestinal' => 'Gastrointestinal',
        'antecedent_endocrine' => 'Endocrino',
        'antecedent_renal' => 'Renal',
        'antecedent_hepatic' => 'Hepático',
        'antecedent_neurologic' => 'Neurológico',
        'antecedent_neoplastic' => 'Neoplásico',
        'antecedent_hematologic' => 'Sanguíneo',
        'antecedent_viral' => 'Virales',
        'antecedent_gynecologic' => 'Ginecológicos',
        'antecedent_covid' => 'COVID-19',
    ];
    $activeAntecedents = [];
    foreach ($antecedentLabels as $field => $label) {
        if (!empty($profile[$field])) {
            $activeAntecedents[] = $label;
        }
    }
    ?>
    <?php if (!$profile): ?>
        <p class="text-sm text-slate-500">Aún no se ha registrado la historia clínica de este paciente.</p>
    <?php else: ?>
        <div class="grid gap-5 lg:grid-cols-2">
            <div class="rounded-2xl border border-slate-200/80 bg-slate-50/60 p-4">
                <h3 class="mb-3 flex items-center gap-2 rounded-lg border-l-4 border-slate-400 bg-slate-100/60 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-700 shadow-sm">
                    <span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span>
                    Motivo y alertas
                </h3>
                <dl class="space-y-3 text-sm text-slate-600">
                    <div class="border-b border-slate-200/60 pb-2">
                        <dt class="mb-1 text-xs font-semibold text-slate-700">Motivo principal</dt>
                        <dd class="text-slate-600"><?= $profile['consultation_reason'] ? nl2br(htmlspecialchars($profile['consultation_reason'])) : '—' ?></dd>
                    </div>
                    <div class="border-b border-slate-200/60 pb-2">
                        <dt class="mb-1 text-xs font-semibold text-slate-700">Evolución / enfermedad actual</dt>
                        <dd class="text-slate-600"><?= $profile['current_condition'] ? nl2br(htmlspecialchars($profile['current_condition'])) : '—' ?></dd>
                    </div>
                    <div class="border-b border-slate-200/60 pb-2">
                        <dt class="mb-1 text-xs font-semibold text-slate-700">Alertas clínicas</dt>
                        <dd class="text-slate-600"><?= $profile['medical_alerts'] ? nl2br(htmlspecialchars($profile['medical_alerts'])) : '—' ?></dd>
                    </div>
                    <div class="border-b border-slate-200/60 pb-2">
                        <dt class="mb-1 text-xs font-semibold text-slate-700">Medicación actual</dt>
                        <dd class="text-slate-600"><?= $profile['medications'] ? nl2br(htmlspecialchars($profile['medications'])) : '—' ?></dd>
                    </div>
                    <div class="border-b border-slate-200/60 pb-2">
                        <dt class="mb-1 text-xs font-semibold text-slate-700">Hospitalizaciones / procedimientos</dt>
                        <dd class="text-slate-600"><?= $profile['hospitalizations'] ? nl2br(htmlspecialchars($profile['hospitalizations'])) : '—' ?></dd>
                    </div>
                </dl>
            </div>
            <div class="rounded-2xl border border-slate-200/80 bg-white p-4">
                <h3 class="mb-3 flex items-center gap-2 rounded-lg border-l-4 border-blue-400 bg-blue-50/60 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-blue-700 shadow-sm">
                    <span class="h-1.5 w-1.5 rounded-full bg-blue-400"></span>
                    Antecedentes personales
                </h3>
                <?php if ($activeAntecedents): ?>
                    <ul class="list-disc space-y-0.5 pl-5 text-sm text-slate-600">
                        <?php foreach ($activeAntecedents as $item): ?>
                            <li><?= htmlspecialchars($item) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-sm text-slate-500">Sin antecedentes personales registrados.</p>
                <?php endif; ?>
                <div class="mt-3 space-y-3 text-sm text-slate-600">
                    <div class="border-b border-slate-200/60 pb-2">
                        <h4 class="mb-1 text-xs font-semibold text-slate-700">Antecedentes familiares</h4>
                        <p class="text-slate-600"><?= $profile['family_history'] ? nl2br(htmlspecialchars($profile['family_history'])) : '—' ?></p>
                    </div>
                    <div class="border-b border-slate-200/60 pb-2">
                        <h4 class="mb-1 text-xs font-semibold text-slate-700">Hábitos</h4>
                        <p class="text-slate-600"><?= $profile['habits'] ? nl2br(htmlspecialchars($profile['habits'])) : '—' ?></p>
                    </div>
                </div>
            </div>
            <div class="rounded-2xl border border-slate-200/80 bg-white p-4">
                <h3 class="mb-3 flex items-center gap-2 rounded-lg border-l-4 border-emerald-400 bg-emerald-50/60 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-emerald-700 shadow-sm">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                    Examen clínico
                </h3>
                <dl class="space-y-3 text-sm text-slate-600">
                    <div class="border-b border-slate-200/60 pb-2">
                        <dt class="mb-1 text-xs font-semibold text-slate-700">Examen extraoral</dt>
                        <dd class="text-slate-600"><?= $profile['extraoral_exam'] ? nl2br(htmlspecialchars($profile['extraoral_exam'])) : '—' ?></dd>
                    </div>
                    <div class="border-b border-slate-200/60 pb-2">
                        <dt class="mb-1 text-xs font-semibold text-slate-700">Examen intraoral</dt>
                        <dd class="text-slate-600"><?= $profile['intraoral_exam'] ? nl2br(htmlspecialchars($profile['intraoral_exam'])) : '—' ?></dd>
                    </div>
                    <div class="border-b border-slate-200/60 pb-2">
                        <dt class="mb-1 text-xs font-semibold text-slate-700">Tejidos periodontales</dt>
                        <dd class="text-slate-600"><?= $profile['periodontal_status'] ? nl2br(htmlspecialchars($profile['periodontal_status'])) : '—' ?></dd>
                    </div>
                    <div class="border-b border-slate-200/60 pb-2">
                        <dt class="mb-1 text-xs font-semibold text-slate-700">PA (mmHg)</dt>
                        <dd class="text-slate-600"><?= $profile['physical_exam_bp'] ? htmlspecialchars($profile['physical_exam_bp']) : '—' ?></dd>
                    </div>
                    <div class="border-b border-slate-200/60 pb-2">
                        <dt class="mb-1 text-xs font-semibold text-slate-700">Dolor (0-10)</dt>
                        <dd class="text-slate-600"><?= $profile['pain_level'] !== null ? (int) $profile['pain_level'] : '—' ?></dd>
                    </div>
                    <div class="border-b border-slate-200/60 pb-2">
                        <dt class="mb-1 text-xs font-semibold text-slate-700">Evaluación de riesgo</dt>
                        <dd class="text-slate-600"><?= $profile['risk_assessment'] ? nl2br(htmlspecialchars($profile['risk_assessment'])) : '—' ?></dd>
                    </div>
                </dl>
            </div>
            <div class="rounded-2xl border border-slate-200/80 bg-slate-50/60 p-4">
                <h3 class="mb-3 flex items-center gap-2 rounded-lg border-l-4 border-purple-400 bg-purple-50/60 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-purple-700 shadow-sm">
                    <span class="h-1.5 w-1.5 rounded-full bg-purple-400"></span>
                    Diagnóstico y plan
                </h3>
                <dl class="space-y-3 text-sm text-slate-600">
                    <div class="border-b border-slate-200/60 pb-2">
                        <dt class="mb-1 text-xs font-semibold text-slate-700">Diagnóstico</dt>
                        <dd class="text-slate-600"><?= $profile['diagnosis'] ? nl2br(htmlspecialchars($profile['diagnosis'])) : '—' ?></dd>
                    </div>
                    <div class="border-b border-slate-200/60 pb-2">
                        <dt class="mb-1 text-xs font-semibold text-slate-700">Plan de tratamiento</dt>
                        <dd class="text-slate-600"><?= $profile['treatment_plan'] ? nl2br(htmlspecialchars($profile['treatment_plan'])) : '—' ?></dd>
                    </div>
                </dl>
            </div>
            <div class="rounded-2xl border border-slate-200/80 bg-white p-4">
                <h3 class="mb-3 flex items-center gap-2 rounded-lg border-l-4 border-amber-400 bg-amber-50/60 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-amber-700 shadow-sm">
                    <span class="h-1.5 w-1.5 rounded-full bg-amber-400"></span>
                    Consentimiento informado
                </h3>
                <dl class="space-y-3 text-sm text-slate-600">
                    <div class="border-b border-slate-200/60 pb-2">
                        <dt class="mb-1 text-xs font-semibold text-slate-700">Estado</dt>
                        <dd class="text-slate-600"><?= !empty($profile['consent_signed']) ? 'Firmado' : 'Pendiente' ?></dd>
                    </div>
                    <div class="border-b border-slate-200/60 pb-2">
                        <dt class="mb-1 text-xs font-semibold text-slate-700">Fecha de firma</dt>
                        <dd class="text-slate-600"><?= $profile['consent_signed_at'] ? htmlspecialchars($profile['consent_signed_at']) : '—' ?></dd>
                    </div>
                    <div class="border-b border-slate-200/60 pb-2">
                        <dt class="mb-1 text-xs font-semibold text-slate-700">Observaciones</dt>
                        <dd class="text-slate-600"><?= $profile['consent_notes'] ? nl2br(htmlspecialchars($profile['consent_notes'])) : '—' ?></dd>
                    </div>
                </dl>
            </div>
        </div>
    <?php endif; ?>
</section>
<section class="rounded-3xl bg-white/95 p-6 shadow-sm shadow-slate-200/60 ring-1 ring-slate-200/70 sm:p-8 space-y-6" id="visitas">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <h2 class="text-2xl font-semibold text-slate-900">Evolución por visita</h2>
        <p class="text-sm text-slate-500">Registra consultas con formato SOAP y monitorea próximos seguimientos.</p>
    </div>
    <div class="grid gap-6 lg:grid-cols-[minmax(0,420px)_1fr]">
        <?php $isEditingVisit = post('action') === 'update_visit'; ?>
        <form
            method="post"
            id="visit-form"
            class="space-y-4 rounded-2xl border border-slate-200/80 bg-slate-50/60 p-4 shadow-inner"
        >
            <input type="hidden" name="action" value="<?= $isEditingVisit ? 'update_visit' : 'create_visit' ?>">
            <input type="hidden" name="visit_id" value="<?= htmlspecialchars((string) post('visit_id', '')) ?>">
            <label class="flex flex-col gap-2 text-sm text-slate-600">
                <span class="font-medium text-slate-700">Fecha de la consulta *</span>
                <input type="date" name="visit_date" value="<?= htmlspecialchars(post('visit_date', $today) ?: $today) ?>" required class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
            </label>
            <label class="flex flex-col gap-2 text-sm text-slate-600">
                <span class="font-medium text-slate-700">Motivo / síntomas (S)</span>
                <textarea name="subjective_notes" rows="2" data-capitalize-initial class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"><?= htmlspecialchars(capitalizeInitial(post('subjective_notes', '')) ?? '') ?></textarea>
            </label>
            <label class="flex flex-col gap-2 text-sm text-slate-600">
                <span class="font-medium text-slate-700">Hallazgos clínicos (O)</span>
                <textarea name="objective_notes" rows="2" data-capitalize-initial class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"><?= htmlspecialchars(capitalizeInitial(post('objective_notes', '')) ?? '') ?></textarea>
            </label>
            <label class="flex flex-col gap-2 text-sm text-slate-600">
                <span class="font-medium text-slate-700">Evaluación / diagnósticos (A)</span>
                <textarea name="assessment" rows="2" data-capitalize-initial class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"><?= htmlspecialchars(capitalizeInitial(post('assessment', '')) ?? '') ?></textarea>
            </label>
            <label class="flex flex-col gap-2 text-sm text-slate-600">
                <span class="font-medium text-slate-700">Plan inmediato / recomendaciones (P)</span>
                <textarea name="plan" rows="2" data-capitalize-initial class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"><?= htmlspecialchars(capitalizeInitial(post('plan', '')) ?? '') ?></textarea>
            </label>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">TA / PA</span>
                    <input type="text" name="vitals_bp" value="<?= htmlspecialchars(capitalizeInitial(post('vitals_bp', '')) ?? '') ?>" placeholder="Ej: 120/80" data-capitalize-initial class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">FC</span>
                    <input type="text" name="vitals_hr" value="<?= htmlspecialchars(capitalizeInitial(post('vitals_hr', '')) ?? '') ?>" placeholder="lat/min" data-capitalize-initial class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Temp</span>
                    <input type="text" name="vitals_temp" value="<?= htmlspecialchars(capitalizeInitial(post('vitals_temp', '')) ?? '') ?>" placeholder="°C" data-capitalize-initial class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">SpO₂</span>
                    <input type="text" name="vitals_oxygen" value="<?= htmlspecialchars(capitalizeInitial(post('vitals_oxygen', '')) ?? '') ?>" placeholder="%" data-capitalize-initial class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
                </label>
            </div>
            <label class="flex flex-col gap-2 text-sm text-slate-600">
                <span class="font-medium text-slate-700">Próxima cita</span>
                <input type="date" name="next_appointment" value="<?= htmlspecialchars((string) post('next_appointment', '')) ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
            </label>
            <div class="flex items-center justify-between gap-3">
                <button
                    type="button"
                    class="inline-flex items-center justify-center gap-2 rounded-full border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 shadow-soft transition hover:-translate-y-0.5 hover:bg-slate-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-400<?= $isEditingVisit ? '' : ' hidden' ?>"
                    data-visit-cancel
                >
                    Cancelar
                </button>
                <button
                    type="submit"
                    class="inline-flex items-center justify-center gap-2 rounded-full bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-soft transition hover:-translate-y-0.5 hover:bg-brand-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                    data-visit-submit
                    data-label-create="Registrar visita"
                    data-label-update="Actualizar visita"
                >
                    <?= $isEditingVisit ? 'Actualizar visita' : 'Registrar visita' ?>
                </button>
            </div>
        </form>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            var form = document.getElementById('activity-form');
            if (!form) {
                return;
            }
            var feeInput = form.querySelector('input[name="fee"]');
            var paymentInput = form.querySelector('input[name="payment"]');
            var balanceInput = form.querySelector('input[name="balance"]');
            if (!feeInput || !paymentInput || !balanceInput) {
                return;
            }
            var formatAmount = function (value) {
                return Number.isFinite(value) ? value.toFixed(2) : '';
            };
            var parseAmount = function (input) {
                if (!input || input.value.trim() === '') {
                    return NaN;
                }
                return Number.parseFloat(input.value.replace(',', '.'));
            };
            var updateBalance = function () {
                var previousOutstanding = Number.parseFloat(form.dataset.previousOutstanding || '0');
                if (!Number.isFinite(previousOutstanding) || previousOutstanding < 0) {
                    previousOutstanding = 0;
                }
                var fee = parseAmount(feeInput);
                var payment = parseAmount(paymentInput);
                if (Number.isNaN(fee) && Number.isNaN(payment)) {
                    balanceInput.value = previousOutstanding > 0 ? formatAmount(previousOutstanding) : '';
                    return;
                }
                if (Number.isNaN(fee)) {
                    fee = 0;
                }
                if (Number.isNaN(payment)) {
                    payment = 0;
                }
                var result = previousOutstanding + fee - payment;
                balanceInput.value = result > 0 ? formatAmount(result) : '0.00';
            };
            if (balanceInput.value.trim() === '') {
                var initialPrevious = Number.parseFloat(form.dataset.previousOutstanding || '0');
                if (!Number.isFinite(initialPrevious) || initialPrevious < 0) {
                    initialPrevious = 0;
                }
                balanceInput.value = initialPrevious > 0 ? formatAmount(initialPrevious) : '';
            }
            feeInput.addEventListener('input', updateBalance);
            paymentInput.addEventListener('input', updateBalance);
            form.addEventListener('submit', updateBalance);
        });
        </script>

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
                                <div class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        class="inline-flex items-center rounded-full border border-brand-200 px-3 py-1.5 text-xs font-semibold text-brand-700 transition hover:bg-brand-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                                        data-edit-visit
                                        data-visit-id="<?= (int) $visit['id'] ?>"
                                        data-visit-date="<?= htmlspecialchars((string) $visit['visit_date']) ?>"
                                        data-visit-next="<?= htmlspecialchars((string) ($visit['next_appointment'] ?? '')) ?>"
                                        data-subjective="<?= htmlspecialchars((string) ($visit['subjective_notes'] ?? ''), ENT_QUOTES) ?>"
                                        data-objective="<?= htmlspecialchars((string) ($visit['objective_notes'] ?? ''), ENT_QUOTES) ?>"
                                        data-assessment="<?= htmlspecialchars((string) ($visit['assessment'] ?? ''), ENT_QUOTES) ?>"
                                        data-plan="<?= htmlspecialchars((string) ($visit['plan'] ?? ''), ENT_QUOTES) ?>"
                                        data-vitals-bp="<?= htmlspecialchars((string) ($visit['vitals_bp'] ?? ''), ENT_QUOTES) ?>"
                                        data-vitals-hr="<?= htmlspecialchars((string) ($visit['vitals_hr'] ?? ''), ENT_QUOTES) ?>"
                                        data-vitals-temp="<?= htmlspecialchars((string) ($visit['vitals_temp'] ?? ''), ENT_QUOTES) ?>"
                                        data-vitals-oxygen="<?= htmlspecialchars((string) ($visit['vitals_oxygen'] ?? ''), ENT_QUOTES) ?>"
                                    >
                                        Editar
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex items-center rounded-full border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-400 hidden"
                                        data-visit-cancel-row="<?= (int) $visit['id'] ?>"
                                    >
                                        Cancelar
                                    </button>
                                    <form method="post" class="inline-flex" onsubmit="return confirm('¿Eliminar esta consulta?');">
                                        <input type="hidden" name="action" value="delete_visit">
                                        <input type="hidden" name="visit_id" value="<?= (int) $visit['id'] ?>">
                                        <button type="submit" class="inline-flex items-center rounded-full border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-600 transition hover:bg-rose-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-500">
                                            Eliminar
                                        </button>
                                    </form>
                                </div>
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
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            var visitForm = document.getElementById('visit-form');
            if (!visitForm) {
                return;
            }
            var actionInput = visitForm.querySelector('input[name="action"]');
            var visitIdInput = visitForm.querySelector('input[name="visit_id"]');
            var cancelButton = visitForm.querySelector('[data-visit-cancel]');
            var submitButton = visitForm.querySelector('[data-visit-submit]');
            var submitLabelCreate = submitButton ? submitButton.dataset.labelCreate || submitButton.textContent.trim() : 'Registrar visita';
            var submitLabelUpdate = submitButton ? submitButton.dataset.labelUpdate || 'Actualizar visita' : 'Actualizar visita';
            var visitFields = {
                visit_date: visitForm.querySelector('input[name="visit_date"]'),
                subjective_notes: visitForm.querySelector('textarea[name="subjective_notes"]'),
                objective_notes: visitForm.querySelector('textarea[name="objective_notes"]'),
                assessment: visitForm.querySelector('textarea[name="assessment"]'),
                plan: visitForm.querySelector('textarea[name="plan"]'),
                vitals_bp: visitForm.querySelector('input[name="vitals_bp"]'),
                vitals_hr: visitForm.querySelector('input[name="vitals_hr"]'),
                vitals_temp: visitForm.querySelector('input[name="vitals_temp"]'),
                vitals_oxygen: visitForm.querySelector('input[name="vitals_oxygen"]'),
                next_appointment: visitForm.querySelector('input[name="next_appointment"]'),
            };
            var visitRowCancelButtons = Array.prototype.slice.call(document.querySelectorAll('[data-visit-cancel-row]'));
            var currentVisitId = null;
            var initialValues = {};
            Object.keys(visitFields).forEach(function (fieldName) {
                var field = visitFields[fieldName];
                initialValues[fieldName] = field ? field.value : '';
            });

            var setModeCreate = function () {
                if (actionInput) {
                    actionInput.value = 'create_visit';
                }
                if (visitIdInput) {
                    visitIdInput.value = '';
                }
                currentVisitId = null;
                visitRowCancelButtons.forEach(function (button) {
                    button.classList.add('hidden');
                });
                if (submitButton) {
                    submitButton.textContent = submitLabelCreate;
                }
                if (cancelButton) {
                    cancelButton.classList.add('hidden');
                }
                Object.keys(initialValues).forEach(function (fieldName) {
                    var field = visitFields[fieldName];
                    if (field) {
                        field.value = initialValues[fieldName];
                    }
                });
            };

            var setModeEdit = function (dataset) {
                if (actionInput) {
                    actionInput.value = 'update_visit';
                }
                if (visitIdInput) {
                    visitIdInput.value = dataset.visitId || '';
                }
                currentVisitId = dataset.visitId || null;
                visitRowCancelButtons.forEach(function (button) {
                    if (button.dataset.visitCancelRow === currentVisitId) {
                        button.classList.remove('hidden');
                    } else {
                        button.classList.add('hidden');
                    }
                });
                if (submitButton) {
                    submitButton.textContent = submitLabelUpdate;
                }
                if (cancelButton) {
                    cancelButton.classList.remove('hidden');
                }
                var fieldMappings = {
                    visit_date: dataset.visitDate || '',
                    subjective_notes: dataset.subjective || '',
                    objective_notes: dataset.objective || '',
                    assessment: dataset.assessment || '',
                    plan: dataset.plan || '',
                    vitals_bp: dataset.vitalsBp || '',
                    vitals_hr: dataset.vitalsHr || '',
                    vitals_temp: dataset.vitalsTemp || '',
                    vitals_oxygen: dataset.vitalsOxygen || '',
                    next_appointment: dataset.visitNext || '',
                };
                Object.keys(fieldMappings).forEach(function (fieldName) {
                    var field = visitFields[fieldName];
                    if (field) {
                        field.value = fieldMappings[fieldName];
                    }
                });
            };

            document.querySelectorAll('[data-edit-visit]').forEach(function (button) {
                button.addEventListener('click', function () {
                    setModeEdit(button.dataset);
                    if (visitFields.visit_date) {
                        visitFields.visit_date.focus();
                    }
                });
            });

            if (cancelButton) {
                cancelButton.addEventListener('click', function () {
                    setModeCreate();
                });
            }

            visitRowCancelButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    setModeCreate();
                    if (visitFields.visit_date) {
                        visitFields.visit_date.focus();
                    }
                });
            });

            if (actionInput && actionInput.value === 'update_visit') {
                if (cancelButton) {
                    cancelButton.classList.remove('hidden');
                }
                if (submitButton) {
                    submitButton.textContent = submitLabelUpdate;
                }
                if (visitIdInput && visitIdInput.value) {
                    currentVisitId = visitIdInput.value;
                    visitRowCancelButtons.forEach(function (button) {
                        if (button.dataset.visitCancelRow === currentVisitId) {
                            button.classList.remove('hidden');
                        }
                    });
                }
            }
        });
        </script>
    </div>
</section>

<section class="rounded-3xl bg-white/95 p-6 shadow-sm shadow-slate-200/60 ring-1 ring-slate-200/70 sm:p-8 space-y-6" id="actividades">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <h2 class="text-2xl font-semibold text-slate-900">Actividades realizadas y control de pagos</h2>
        <p class="text-sm text-slate-500">Registra procedimientos, cobros y abonos asociados al tratamiento.</p>
    </div>
    <div class="grid gap-6 lg:grid-cols-[minmax(0,420px)_1fr]">
        <?php
        $isEditingActivity = post('action') === 'update_activity';
        $activityFormPrevOutstanding = (string) post('previous_outstanding', number_format($latestOutstanding, 2, '.', ''));
        ?>
        <form
            method="post"
            id="activity-form"
            class="space-y-4 rounded-2xl border border-slate-200/80 bg-slate-50/60 p-4 shadow-inner"
            data-previous-outstanding="<?= htmlspecialchars($activityFormPrevOutstanding) ?>"
            data-default-previous-outstanding="<?= htmlspecialchars(number_format($latestOutstanding, 2, '.', '')) ?>"
        >
            <input type="hidden" name="action" value="<?= $isEditingActivity ? 'update_activity' : 'create_activity' ?>">
            <input type="hidden" name="activity_id" value="<?= htmlspecialchars((string) post('activity_id', '')) ?>">
            <input type="hidden" name="previous_outstanding" value="<?= htmlspecialchars($activityFormPrevOutstanding) ?>">
            <label class="flex flex-col gap-2 text-sm text-slate-600">
                <span class="font-medium text-slate-700">Fecha *</span>
                <input type="date" name="activity_date" value="<?= htmlspecialchars(post('activity_date', $today) ?: $today) ?>" required class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400">
            </label>
            <label class="flex flex-col gap-2 text-sm text-slate-600">
                <span class="font-medium text-slate-700">Descripción *</span>
                <textarea name="description" rows="2" required data-capitalize-initial class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"><?= htmlspecialchars(capitalizeInitial(post('description', '')) ?? '') ?></textarea>
            </label>
            <label class="flex flex-col gap-2 text-sm text-slate-600">
                <span class="font-medium text-slate-700">Visita asociada</span>
                <select name="related_visit" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 focus:border-brand-400 focus:ring-brand-400">
                    <option value="">(Opcional)</option>
                    <?php foreach ($visits as $visit): ?>
                        <option value="<?= (int) $visit['id'] ?>"<?= (string) post('related_visit', '') === (string) $visit['id'] ? ' selected' : '' ?>>
                            <?= date('d/m/Y', strtotime($visit['visit_date'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="grid gap-3 sm:grid-cols-3">
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Honorarios (Bs)</span>
                    <input type="number" step="0.01" min="0" name="fee" value="<?= htmlspecialchars((string) post('fee', '')) ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400" inputmode="decimal">
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Abono (Bs)</span>
                    <input type="number" step="0.01" min="0" name="payment" value="<?= htmlspecialchars((string) post('payment', '')) ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400" inputmode="decimal">
                </label>
                <label class="flex flex-col gap-2 text-sm text-slate-600">
                    <span class="font-medium text-slate-700">Resta (Bs)</span>
                    <input type="number" step="0.01" min="0" name="balance" value="<?= htmlspecialchars((string) post('balance', $latestOutstanding > 0 ? number_format($latestOutstanding, 2, '.', '') : '')) ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400" inputmode="decimal" readonly aria-readonly="true">
                </label>
            </div>
            <label class="flex flex-col gap-2 text-sm text-slate-600">
                <span class="font-medium text-slate-700">Notas</span>
                <textarea name="activity_notes" rows="2" data-capitalize-initial class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-slate-700 shadow-inner focus:border-brand-400 focus:ring-brand-400"><?= htmlspecialchars(capitalizeInitial(post('activity_notes', '')) ?? '') ?></textarea>
            </label>
            <div class="flex items-center justify-between gap-3">
                <button
                    type="button"
                    class="inline-flex items-center justify-center gap-2 rounded-full border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 shadow-soft transition hover:-translate-y-0.5 hover:bg-slate-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-400<?= $isEditingActivity ? '' : ' hidden' ?>"
                    data-activity-cancel
                >
                    Cancelar
                </button>
                <button
                    type="submit"
                    class="inline-flex items-center justify-center gap-2 rounded-full bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-soft transition hover:-translate-y-0.5 hover:bg-brand-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                    data-activity-submit
                    data-label-create="Agregar actividad"
                    data-label-update="Actualizar actividad"
                >
                    <?= $isEditingActivity ? 'Actualizar actividad' : 'Agregar actividad' ?>
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
                                <th class="px-4 py-3 text-center font-semibold">Acciones</th>
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
                                    <td class="px-4 py-4 text-center">
                                        <div class="inline-flex items-center gap-2">
                                            <button
                                                type="button"
                                                class="inline-flex items-center rounded-full border border-brand-200 px-3 py-1.5 text-xs font-semibold text-brand-700 transition hover:bg-brand-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                                                data-edit-activity
                                                data-activity-id="<?= (int) $activity['id'] ?>"
                                                data-activity-date="<?= htmlspecialchars((string) $activity['activity_date']) ?>"
                                                data-description="<?= htmlspecialchars((string) $activity['description'], ENT_QUOTES) ?>"
                                                data-related-visit="<?= $activity['visit_id'] ? (int) $activity['visit_id'] : '' ?>"
                                                data-fee="<?= htmlspecialchars(number_format((float) $activity['fee'], 2, '.', ''), ENT_QUOTES) ?>"
                                                data-payment="<?= htmlspecialchars(number_format((float) $activity['payment'], 2, '.', ''), ENT_QUOTES) ?>"
                                                data-balance="<?= htmlspecialchars(number_format((float) $activity['balance'], 2, '.', ''), ENT_QUOTES) ?>"
                                                data-notes="<?= htmlspecialchars((string) ($activity['notes'] ?? ''), ENT_QUOTES) ?>"
                                                data-previous-outstanding="<?= htmlspecialchars(number_format($activityPreviousOutstanding[(int) $activity['id']] ?? 0.0, 2, '.', ''), ENT_QUOTES) ?>"
                                            >
                                                Editar
                                            </button>
                                            <button
                                                type="button"
                                                class="inline-flex items-center rounded-full border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-400 hidden"
                                                data-activity-cancel-row="<?= (int) $activity['id'] ?>"
                                            >
                                                Cancelar
                                            </button>
                                            <form method="post" class="inline-flex" onsubmit="return confirm('¿Eliminar esta actividad?');">
                                                <input type="hidden" name="action" value="delete_activity">
                                                <input type="hidden" name="activity_id" value="<?= (int) $activity['id'] ?>">
                                                <button class="inline-flex items-center rounded-full border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-600 transition hover:bg-rose-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-500" type="submit">
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
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            var activityForm = document.getElementById('activity-form');
            if (!activityForm) {
                return;
            }
            var actionInput = activityForm.querySelector('input[name="action"]');
            var activityIdInput = activityForm.querySelector('input[name="activity_id"]');
            var previousOutstandingInput = activityForm.querySelector('input[name="previous_outstanding"]');
            var cancelButton = activityForm.querySelector('[data-activity-cancel]');
            var submitButton = activityForm.querySelector('[data-activity-submit]');
            var submitLabelCreate = submitButton ? submitButton.dataset.labelCreate || submitButton.textContent.trim() : 'Agregar actividad';
            var submitLabelUpdate = submitButton ? submitButton.dataset.labelUpdate || 'Actualizar actividad' : 'Actualizar actividad';
            var activityFields = {
                activity_date: activityForm.querySelector('input[name="activity_date"]'),
                description: activityForm.querySelector('textarea[name="description"]'),
                related_visit: activityForm.querySelector('select[name="related_visit"]'),
                fee: activityForm.querySelector('input[name="fee"]'),
                payment: activityForm.querySelector('input[name="payment"]'),
                balance: activityForm.querySelector('input[name="balance"]'),
                activity_notes: activityForm.querySelector('textarea[name="activity_notes"]'),
            };
            var activityRowCancelButtons = Array.prototype.slice.call(document.querySelectorAll('[data-activity-cancel-row]'));
            var currentActivityId = null;
            var defaultPreviousOutstanding = activityForm.dataset.defaultPreviousOutstanding || activityForm.dataset.previousOutstanding || '0';
            var initialValues = {};
            Object.keys(activityFields).forEach(function (fieldName) {
                var field = activityFields[fieldName];
                initialValues[fieldName] = field ? field.value : '';
            });

            var normalizeAmount = function (value) {
                if (typeof value !== 'string') {
                    return '';
                }
                return value.replace(',', '.');
            };
            var updateBalanceField = function () {
                if (!activityFields.balance || !activityFields.fee || !activityFields.payment) {
                    return;
                }
                var previousOutstanding = Number.parseFloat(activityForm.dataset.previousOutstanding || '0');
                if (!Number.isFinite(previousOutstanding) || previousOutstanding < 0) {
                    previousOutstanding = 0;
                }
                var fee = Number.parseFloat(normalizeAmount(activityFields.fee.value));
                if (!Number.isFinite(fee)) {
                    fee = 0;
                }
                var payment = Number.parseFloat(normalizeAmount(activityFields.payment.value));
                if (!Number.isFinite(payment)) {
                    payment = 0;
                }
                var result = previousOutstanding + fee - payment;
                activityFields.balance.value = result > 0 ? result.toFixed(2) : '0.00';
            };

            var setActivityCreateMode = function () {
                if (actionInput) {
                    actionInput.value = 'create_activity';
                }
                if (activityIdInput) {
                    activityIdInput.value = '';
                }
                currentActivityId = null;
                activityRowCancelButtons.forEach(function (button) {
                    button.classList.add('hidden');
                });
                activityForm.dataset.previousOutstanding = defaultPreviousOutstanding;
                if (previousOutstandingInput) {
                    previousOutstandingInput.value = defaultPreviousOutstanding;
                }
                if (submitButton) {
                    submitButton.textContent = submitLabelCreate;
                }
                if (cancelButton) {
                    cancelButton.classList.add('hidden');
                }
                Object.keys(activityFields).forEach(function (fieldName) {
                    var field = activityFields[fieldName];
                    if (!field) {
                        return;
                    }
                    var resetValue = initialValues[fieldName] || '';
                    if (field.tagName === 'SELECT') {
                        field.value = resetValue;
                    } else {
                        field.value = resetValue;
                    }
                });
                updateBalanceField();
            };

            var setActivityEditMode = function (dataset) {
                if (actionInput) {
                    actionInput.value = 'update_activity';
                }
                if (activityIdInput) {
                    activityIdInput.value = dataset.activityId || '';
                }
                currentActivityId = dataset.activityId || null;
                activityRowCancelButtons.forEach(function (button) {
                    if (button.dataset.activityCancelRow === currentActivityId) {
                        button.classList.remove('hidden');
                    } else {
                        button.classList.add('hidden');
                    }
                });
                activityForm.dataset.previousOutstanding = dataset.previousOutstanding || '0';
                if (previousOutstandingInput) {
                    previousOutstandingInput.value = dataset.previousOutstanding || '0';
                }
                if (submitButton) {
                    submitButton.textContent = submitLabelUpdate;
                }
                if (cancelButton) {
                    cancelButton.classList.remove('hidden');
                }
                if (activityFields.activity_date) {
                    activityFields.activity_date.value = dataset.activityDate || '';
                }
                if (activityFields.description) {
                    activityFields.description.value = dataset.description || '';
                }
                if (activityFields.related_visit) {
                    activityFields.related_visit.value = dataset.relatedVisit || '';
                }
                if (activityFields.fee) {
                    activityFields.fee.value = dataset.fee || '';
                }
                if (activityFields.payment) {
                    activityFields.payment.value = dataset.payment || '';
                }
                if (activityFields.activity_notes) {
                    activityFields.activity_notes.value = dataset.notes || '';
                }
                if (activityFields.balance) {
                    activityFields.balance.value = dataset.balance || '';
                }
                updateBalanceField();
                if (activityFields.activity_date) {
                    activityFields.activity_date.focus();
                }
            };

            document.querySelectorAll('[data-edit-activity]').forEach(function (button) {
                button.addEventListener('click', function () {
                    setActivityEditMode(button.dataset);
                });
            });

            if (cancelButton) {
                cancelButton.addEventListener('click', function () {
                    setActivityCreateMode();
                });
            }

            activityRowCancelButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    setActivityCreateMode();
                    if (activityFields.activity_date) {
                        activityFields.activity_date.focus();
                    }
                });
            });

            if (actionInput && actionInput.value === 'update_activity') {
                if (submitButton) {
                    submitButton.textContent = submitLabelUpdate;
                }
                if (cancelButton) {
                    cancelButton.classList.remove('hidden');
                }
                if (activityIdInput && activityIdInput.value) {
                    currentActivityId = activityIdInput.value;
                    activityRowCancelButtons.forEach(function (button) {
                        if (button.dataset.activityCancelRow === currentActivityId) {
                            button.classList.remove('hidden');
                        }
                    });
                }
            }
        });
        </script>
    </div>
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

    var fields = document.querySelectorAll('[data-capitalize-initial]');
    fields.forEach(function (field) {
        var applyCapitalization = function () {
            var originalSelectionStart = field.selectionStart;
            var originalSelectionEnd = field.selectionEnd;
            var newValue = capitalizeInitialValue(field.value);
            if (field.value !== newValue) {
                field.value = newValue;
                if (typeof originalSelectionStart === 'number' && typeof originalSelectionEnd === 'number') {
                    field.selectionStart = originalSelectionStart;
                    field.selectionEnd = originalSelectionEnd;
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
</script>

<?php require __DIR__ . '/templates/footer.php'; ?>
