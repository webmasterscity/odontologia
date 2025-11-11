document.addEventListener('DOMContentLoaded', () => {
    setupAgeCalculator();
    setupOdontogram();
    setupFinancialCalculator();
    setupConsentFields();
});

function setupAgeCalculator() {
    const birthInput = document.querySelector('input[name="birth_date"]');
    const ageDisplay = document.querySelector('input[name="age_display"]');
    if (!birthInput || !ageDisplay) {
        return;
    }

    const updateAge = () => {
        if (!birthInput.value) {
            ageDisplay.value = '';
            return;
        }
        const birthDate = new Date(birthInput.value);
        if (Number.isNaN(birthDate.getTime())) {
            ageDisplay.value = '';
            return;
        }
        const today = new Date();
        let age = today.getFullYear() - birthDate.getFullYear();
        const monthDiff = today.getMonth() - birthDate.getMonth();
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
            age -= 1;
        }
        ageDisplay.value = Math.max(age, 0);
    };

    birthInput.addEventListener('change', updateAge);
    birthInput.addEventListener('keyup', updateAge);
    updateAge();
}

function setupOdontogram() {
    const form = document.querySelector('[data-odontogram-form]');
    if (!form) {
        return;
    }

    const payloadInput = form.querySelector('input[name="odontogram_payload"]');
    const baseDirtyInput = form.querySelector('input[name="odontogram_base_dirty"]');
    form.dataset.odontogramDirty = form.dataset.odontogramDirty || 'false';
    form.dataset.odontogramBaseDirty = 'false';
    if (baseDirtyInput) {
        baseDirtyInput.value = '0';
    }
    let state = {
        odontodiagrama: {},
        evolucion: {},
    };
    if (payloadInput && payloadInput.value) {
        try {
            const parsed = JSON.parse(payloadInput.value);
            if (parsed && typeof parsed === 'object') {
                state = {
                    odontodiagrama: parsed.odontodiagrama && typeof parsed.odontodiagrama === 'object' ? parsed.odontodiagrama : {},
                    evolucion: parsed.evolucion && typeof parsed.evolucion === 'object' ? parsed.evolucion : {},
                };
            }
        } catch (error) {
            console.warn('No se pudo interpretar el odontograma guardado:', error);
        }
    }

    const wrappers = Array.from(form.querySelectorAll('.odontogram-wrapper'));
    if (!wrappers.length) {
        return;
    }

    const wrapperControllers = new Map();
    const toggleLabelUpdaters = new Map();

    let statusLabelsMap = {};
    try {
        const rawStatuses = form.dataset.odontogramStatuses || '{}';
        const parsedStatuses = JSON.parse(rawStatuses);
        if (parsedStatuses && typeof parsedStatuses === 'object') {
            statusLabelsMap = parsedStatuses;
        }
    } catch (error) {
        console.warn('No se pudo interpretar los estados del odontograma:', error);
        statusLabelsMap = {};
    }
    const allowedStatuses = Object.keys(statusLabelsMap).filter((key) => typeof key === 'string' && key.trim() !== '');
    const defaultStatusValue = (() => {
        const preferred = (form.dataset.odontogramDefaultStatus || '').trim();
        if (preferred && allowedStatuses.includes(preferred)) {
            return preferred;
        }
        return allowedStatuses[0] || '';
    })();
    const isValidStatus = (value) => typeof value === 'string' && allowedStatuses.includes(value.trim());
    const normalizeStatus = (value) => {
        if (!isValidStatus(value)) {
            return '';
        }
        return value.trim();
    };
    const normalizeNotes = (value) => {
        if (typeof value !== 'string') {
            return '';
        }
        return value.trim();
    };
    const markDirtyForDiagram = (diagramKey) => {
        form.dataset.odontogramDirty = 'true';
        if (diagramKey === 'odontodiagrama') {
            form.dataset.odontogramBaseDirty = 'true';
        }
    };

    const allowedColors = ['blue', 'red'];
    const strokePalette = {
        blue: '#1D4ED8',
        red: '#FF0000',
    };
    const symbolRefs = {
        dot: '#mark-dot',
        x: '#mark-x',
        vertical: '#mark-vert',
        horizontal: '#mark-horz',
    };
    const allowedMarks = Object.keys(symbolRefs);
    const markTypeDatasetMap = {
        dot: 'dot',
        x: 'x',
        vertical: 'vert',
        horizontal: 'horz',
    };
    const sectorAngles = {
        upper_right: 270,   // ARRIBA (norte) - cardinal
        upper_left: 180,    // IZQUIERDA (oeste) - cardinal
        lower_left: 90,     // ABAJO (sur) - cardinal
        lower_right: 0,     // DERECHA (este) - cardinal
    };
    const geometry = {
        cx: 50,
        cy: 50,
        outerRadius: 47,
        innerRadius: 22.5,
        padding: 3,
    };
    const safeOuterRadius = geometry.outerRadius - geometry.padding;
    const safeInnerRadius = geometry.innerRadius + geometry.padding;
    const ringAnchorRadius = (safeOuterRadius + safeInnerRadius) / 2;
    const safeCenterRadius = 19.5;
    const sectorAngleRanges = {
        upper_right: [225, 315],   // ARRIBA (norte) - 90° centrados en 270°
        upper_left: [135, 225],    // IZQUIERDA (oeste) - 90° centrados en 180°
        lower_left: [45, 135],     // ABAJO (sur) - 90° centrados en 90°
        lower_right: [315, 45],    // DERECHA (este) - 90° centrados en 0° (cruza 0°)
    };
    const squarePadding = geometry.padding;
    const squarePolygons = {
        top: [
            [3 + squarePadding, 3 + squarePadding],
            [97 - squarePadding, 3 + squarePadding],
            [72.5 - squarePadding, 27.5 + squarePadding],
            [27.5 + squarePadding, 27.5 + squarePadding],
        ],
        right: [
            [97 - squarePadding, 3 + squarePadding],
            [97 - squarePadding, 97 - squarePadding],
            [72.5 - squarePadding, 72.5 - squarePadding],
            [72.5 - squarePadding, 27.5 + squarePadding],
        ],
        bottom: [
            [27.5 + squarePadding, 72.5 - squarePadding],
            [72.5 - squarePadding, 72.5 - squarePadding],
            [97 - squarePadding, 97 - squarePadding],
            [3 + squarePadding, 97 - squarePadding],
        ],
        left: [
            [3 + squarePadding, 3 + squarePadding],
            [27.5 + squarePadding, 27.5 + squarePadding],
            [27.5 + squarePadding, 72.5 - squarePadding],
            [3 + squarePadding, 97 - squarePadding],
        ],
        center: [
            [27.5 + squarePadding, 27.5 + squarePadding],
            [72.5 - squarePadding, 27.5 + squarePadding],
            [72.5 - squarePadding, 72.5 - squarePadding],
            [27.5 + squarePadding, 72.5 - squarePadding],
        ],
    };
    const squareSectorPolygons = {
        upper_right: squarePolygons.top,
        upper_left: squarePolygons.left,
        lower_left: squarePolygons.bottom,
        lower_right: squarePolygons.right,
        center: squarePolygons.center,
    };
    const squareSurfaceDefaultSectors = {
        top: 'upper_right',
        left: 'upper_left',
        center: 'center',
        right: 'lower_right',
        bottom: 'lower_left',
    };
    const colorClasses = ['color-blue', 'color-red'];
    const fillClasses = ['fill-blue', 'fill-red'];
    const svgNS = 'http://www.w3.org/2000/svg';
    const xlinkNS = 'http://www.w3.org/1999/xlink';

    const clamp = (value, min, max) => Math.min(Math.max(value, min), max);
    const isFiniteNumber = (value) => Number.isFinite(value);
    const normalizePosition = (position) => {
        if (!position || typeof position !== 'object') {
            return null;
        }
        const x = Number.parseFloat(position.x);
        const y = Number.parseFloat(position.y);
        if (!isFiniteNumber(x) || !isFiniteNumber(y)) {
            return null;
        }
        return {
            x: clamp(x, 0, 100),
            y: clamp(y, 0, 100),
        };
    };
    const clonePosition = (position) => (position ? { x: position.x, y: position.y } : null);
    const normalizeAngle = (angleDeg) => {
        const normalized = angleDeg % 360;
        return normalized < 0 ? normalized + 360 : normalized;
    };
    const angleToSector = (angleDeg) => {
        const normalized = normalizeAngle(angleDeg);
        if (normalized >= 270 || normalized < 0) {
            return 'upper_right';
        }
        if (normalized >= 180) {
            return 'upper_left';
        }
        if (normalized >= 90) {
            return 'lower_left';
        }
        return 'lower_right';
    };
    const determineSector = (symbolZone, surface, position) => {
        if (symbolZone === 'center') {
            return 'center';
        }
        const mappedSquareSector = squareSurfaceDefaultSectors[surface];
        if (mappedSquareSector) {
            return mappedSquareSector;
        }
        if (['upper_right', 'upper_left', 'lower_right', 'lower_left'].includes(surface)) {
            return surface;
        }
        if (position) {
            const dx = position.x - geometry.cx;
            const dy = position.y - geometry.cy;
            if (dx !== 0 || dy !== 0) {
                const angleDeg = (Math.atan2(dy, dx) * 180) / Math.PI;
                return angleToSector(angleDeg);
            }
        }
        return 'upper_right';
    };
    const roundPositionValue = (value) => Math.round(value * 100) / 100;
    const parseTranslate = (transformValue) => {
        const match = typeof transformValue === 'string'
            ? transformValue.match(/translate\(\s*([-\d.]+)[,\s]+([-\d.]+)\s*\)/i)
            : null;
        if (match) {
            const x = Number.parseFloat(match[1]);
            const y = Number.parseFloat(match[2]);
            if (isFiniteNumber(x) && isFiniteNumber(y)) {
                return { x, y };
            }
        }
        return { x: geometry.cx, y: geometry.cy };
    };
    const formatTranslate = (x, y) => `translate(${roundPositionValue(x)}, ${roundPositionValue(y)})`;
    const getSvgPoint = (svg, event) => {
        if (!svg) {
            return { x: 0, y: 0 };
        }
        const point = svg.createSVGPoint();
        point.x = event.clientX;
        point.y = event.clientY;
        const ctm = svg.getScreenCTM();
        if (!ctm) {
            return { x: 0, y: 0 };
        }
        const transformed = point.matrixTransform(ctm.inverse());
        return {
            x: transformed.x,
            y: transformed.y,
        };
    };
    const pointInPolygon = (point, polygon) => {
        if (!Array.isArray(polygon) || polygon.length < 3) {
            return false;
        }
        const [x, y] = point;
        let inside = false;
        for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i, i += 1) {
            const [xi, yi] = polygon[i];
            const [xj, yj] = polygon[j];
            const intersects = ((yi > y) !== (yj > y))
                && (x < ((xj - xi) * (y - yi)) / ((yj - yi) || Number.EPSILON) + xi);
            if (intersects) {
                inside = !inside;
            }
        }
        return inside;
    };
    const closestPointOnSegment = (ax, ay, bx, by, px, py) => {
        const abx = bx - ax;
        const aby = by - ay;
        const apx = px - ax;
        const apy = py - ay;
        const denominator = (abx * abx) + (aby * aby);
        if (denominator === 0) {
            return { x: ax, y: ay };
        }
        const t = Math.max(0, Math.min(1, (apx * abx + apy * aby) / denominator));
        return {
            x: ax + t * abx,
            y: ay + t * aby,
        };
    };
    const snapToPolygon = (polygon, x, y) => {
        if (!Array.isArray(polygon) || polygon.length < 3) {
            return { x, y };
        }
        if (pointInPolygon([x, y], polygon)) {
            return { x, y };
        }
        let bestPoint = { x, y };
        let bestDistance = Number.POSITIVE_INFINITY;
        for (let i = 0; i < polygon.length; i += 1) {
            const a = polygon[i];
            const b = polygon[(i + 1) % polygon.length];
            const candidate = closestPointOnSegment(a[0], a[1], b[0], b[1], x, y);
            const dx = candidate.x - x;
            const dy = candidate.y - y;
            const distanceSquared = (dx * dx) + (dy * dy);
            if (distanceSquared < bestDistance) {
                bestDistance = distanceSquared;
                bestPoint = candidate;
            }
        }
        return bestPoint;
    };
    const restrictCircularPosition = (position, { sector, symbolZone }) => {
        const fallbackSector = sector && sectorAngleRanges[sector] ? sector : 'upper_right';
        const zone = symbolZone === 'center' ? 'center' : 'ring';
        let { x, y } = position;
        x = clamp(x, 0, 100);
        y = clamp(y, 0, 100);
        const dx = x - geometry.cx;
        const dy = y - geometry.cy;
        let rho = Math.sqrt(dx * dx + dy * dy);
        let theta = Math.atan2(dy, dx);
        let angleDeg = normalizeAngle((theta * 180) / Math.PI);

        if (zone === 'center' || fallbackSector === 'center') {
            const limitedRho = Math.min(rho, safeCenterRadius);
            if (limitedRho === 0) {
                return { x: geometry.cx, y: geometry.cy };
            }
            const finalX = geometry.cx + limitedRho * Math.cos(theta);
            const finalY = geometry.cy + limitedRho * Math.sin(theta);
            return {
                x: roundPositionValue(finalX),
                y: roundPositionValue(finalY),
            };
        }

        if (rho === 0) {
            const [minAngle, maxAngle] = sectorAngleRanges[fallbackSector] || [0, 90];
            angleDeg = (minAngle + maxAngle) / 2;
            theta = (angleDeg * Math.PI) / 180;
            rho = safeInnerRadius;
        }

        const [rangeMinRaw, rangeMaxRaw] = sectorAngleRanges[fallbackSector] || [0, 90];
        const rangeMin = normalizeAngle(rangeMinRaw);
        const rangeMax = normalizeAngle(rangeMaxRaw);
        let clampedAngleDeg = angleDeg;
        if (rangeMin <= rangeMax) {
            clampedAngleDeg = clamp(angleDeg, rangeMin, rangeMax);
        } else {
            // Range crosses 0°
            const inRange = angleDeg >= rangeMin || angleDeg <= rangeMax;
            if (!inRange) {
                const distanceToMin = Math.min(
                    Math.abs(angleDeg - rangeMin),
                    Math.abs(angleDeg - (rangeMin + 360)),
                );
                const distanceToMax = Math.min(
                    Math.abs(angleDeg - rangeMax),
                    Math.abs(angleDeg + 360 - rangeMax),
                );
                clampedAngleDeg = distanceToMin <= distanceToMax ? rangeMin : rangeMax;
            }
        }
        const clampedTheta = (clampedAngleDeg * Math.PI) / 180;
        const clampedRho = clamp(rho, safeInnerRadius, safeOuterRadius);
        const finalX = geometry.cx + clampedRho * Math.cos(clampedTheta);
        const finalY = geometry.cy + clampedRho * Math.sin(clampedTheta);
        return {
            x: roundPositionValue(finalX),
            y: roundPositionValue(finalY),
        };
    };
    const restrictSquarePosition = (position, { sector, symbolZone }) => {
        const zoneKey = symbolZone === 'center' ? 'center' : sector;
        const polygon = squareSectorPolygons[zoneKey] || squareSectorPolygons.center;
        const snapped = snapToPolygon(polygon, position.x, position.y);
        return {
            x: roundPositionValue(snapped.x),
            y: roundPositionValue(snapped.y),
        };
    };
    const restrictPositionToZone = (position, restriction) => {
        if (!restriction || restriction.shape !== 'square') {
            return restrictCircularPosition(position, restriction || { sector: 'upper_right', symbolZone: 'ring' });
        }
        return restrictSquarePosition(position, restriction);
    };

    const normalizeCellState = (cellState) => {
        if (!cellState || typeof cellState !== 'object') {
            return null;
        }
        const fillColor = allowedColors.includes(cellState.color) ? cellState.color : '';
        const mark = allowedMarks.includes(cellState.mark) ? cellState.mark : '';
        let markColor = '';
        let position = null;
        if (mark) {
            if (allowedColors.includes(cellState.markColor)) {
                markColor = cellState.markColor;
            } else if (fillColor) {
                markColor = fillColor;
            } else {
                markColor = allowedColors[0];
            }
            position = normalizePosition(cellState.position);
        }

        if (!mark) {
            position = null;
        }

        if (!fillColor && !mark) {
            return null;
        }

        return {
            color: fillColor,
            mark,
            markColor,
            position,
        };
    };

    const sanitizeLoadedState = () => {
        Object.keys(state).forEach((diagramKey) => {
            const diagramData = state[diagramKey];
            if (!diagramData || typeof diagramData !== 'object') {
                state[diagramKey] = {};
                return;
            }
        Object.keys(diagramData).forEach((toothKey) => {
            const toothEntry = diagramData[toothKey];
            if (!toothEntry || typeof toothEntry !== 'object') {
                delete diagramData[toothKey];
                return;
            }
            if (!toothEntry.surfaces || typeof toothEntry.surfaces !== 'object') {
                toothEntry.surfaces = {};
            }
            Object.keys(toothEntry.surfaces).forEach((surfaceKey) => {
                const normalized = normalizeCellState(toothEntry.surfaces[surfaceKey]);
                if (normalized) {
                    toothEntry.surfaces[surfaceKey] = normalized;
                } else {
                    delete toothEntry.surfaces[surfaceKey];
                }
            });
            const normalizedStatus = normalizeStatus(toothEntry.status);
            if (normalizedStatus) {
                toothEntry.status = normalizedStatus;
            } else {
                delete toothEntry.status;
            }
            if (typeof toothEntry.notes === 'string') {
                const normalizedNote = normalizeNotes(toothEntry.notes);
                if (normalizedNote) {
                    toothEntry.notes = normalizedNote;
                } else {
                    delete toothEntry.notes;
                }
            } else if (toothEntry.notes !== undefined) {
                delete toothEntry.notes;
            }
            const hasMetadata = Boolean(toothEntry.status || toothEntry.notes);
            if (!Object.keys(toothEntry.surfaces).length && !hasMetadata) {
                delete diagramData[toothKey];
            }
        });
        });
    };

    sanitizeLoadedState();

    const getExistingEntry = (diagramKey, toothCode) => {
        if (!toothCode) {
            return null;
        }
        const diagramData = state[diagramKey];
        if (!diagramData || typeof diagramData !== 'object') {
            return null;
        }
        const entry = diagramData[toothCode];
        if (!entry || typeof entry !== 'object') {
            return null;
        }
        if (!entry.surfaces || typeof entry.surfaces !== 'object') {
            entry.surfaces = {};
        }
        return entry;
    };

    const ensureEntry = (diagramKey, toothCode) => {
        if (!toothCode) {
            return null;
        }
        if (!state[diagramKey] || typeof state[diagramKey] !== 'object') {
            state[diagramKey] = {};
        }
        if (!state[diagramKey][toothCode] || typeof state[diagramKey][toothCode] !== 'object') {
            state[diagramKey][toothCode] = { surfaces: {} };
        }
        const entry = state[diagramKey][toothCode];
        if (!entry.surfaces || typeof entry.surfaces !== 'object') {
            entry.surfaces = {};
        }
        return entry;
    };

    const cleanupEntry = (diagramKey, toothCode) => {
        const diagramData = state[diagramKey];
        if (!diagramData || typeof diagramData !== 'object') {
            return;
        }
        const entry = diagramData[toothCode];
        if (!entry || typeof entry !== 'object') {
            return;
        }
        if (!entry.surfaces || typeof entry.surfaces !== 'object') {
            entry.surfaces = {};
        }
        const hasSurfaces = Object.keys(entry.surfaces).length > 0;
        const statusValue = normalizeStatus(entry.status);
        const notesValue = normalizeNotes(entry.notes);
        if (!statusValue) {
            delete entry.status;
        } else {
            entry.status = statusValue;
        }
        if (!notesValue) {
            delete entry.notes;
        } else {
            entry.notes = notesValue;
        }
        const hasMetadata = Boolean(entry.status || entry.notes);
        if (!hasSurfaces && !hasMetadata) {
            delete diagramData[toothCode];
        }
    };

    const applyMetadataToState = (diagramKey, toothCode, metadata) => {
        if (!toothCode) {
            return { changed: false, hasMetadata: false };
        }
        const nextStatus = normalizeStatus(metadata?.status);
        const nextNotes = normalizeNotes(metadata?.notes);
        const existingEntry = getExistingEntry(diagramKey, toothCode);
        const currentStatus = normalizeStatus(existingEntry?.status);
        const currentNotes = normalizeNotes(existingEntry?.notes);

        if (!nextStatus && !nextNotes) {
            if (!existingEntry || (!currentStatus && !currentNotes)) {
                return { changed: false, hasMetadata: false };
            }
            if (existingEntry) {
                delete existingEntry.status;
                delete existingEntry.notes;
            }
            cleanupEntry(diagramKey, toothCode);
            return { changed: true, hasMetadata: false };
        }

        if (nextStatus === currentStatus && nextNotes === currentNotes) {
            return { changed: false, hasMetadata: Boolean(nextStatus || nextNotes) };
        }

        const entry = ensureEntry(diagramKey, toothCode);
        if (nextStatus) {
            entry.status = nextStatus;
        } else {
            delete entry.status;
        }
        if (nextNotes) {
            entry.notes = nextNotes;
        } else {
            delete entry.notes;
        }
        cleanupEntry(diagramKey, toothCode);
        return { changed: true, hasMetadata: Boolean(nextStatus || nextNotes) };
    };

    const clearFill = (cell) => {
        fillClasses.forEach((cls) => cell.classList.remove(cls));
        cell.classList.remove('has-fill');
        cell.dataset.color = '';
    };

    const applyFill = (cell, color) => {
        clearFill(cell);
        if (!color || !allowedColors.includes(color)) {
            return;
        }
        cell.classList.add(`fill-${color}`);
        cell.classList.add('has-fill');
        cell.dataset.color = color;
    };

    const clearMarkState = (cell) => {
        cell.classList.remove('has-mark');
        colorClasses.forEach((cls) => cell.classList.remove(cls));
        cell.dataset.mark = '';
        cell.dataset.markColor = '';
        delete cell.dataset.markX;
        delete cell.dataset.markY;
    };

    const removeSurfaceSymbol = (symbolGroup, surface) => {
        if (!symbolGroup) {
            return;
        }
        const nodes = Array.from(symbolGroup.querySelectorAll(`[data-surface="${surface}"]`));
        nodes.forEach((node) => node.remove());
    };

    const addSurfaceSymbol = ({
        symbolGroup,
        surface,
        markType,
        markColor,
        position,
        fillColor,
        toothCode,
        diagram,
        symbolZone,
        sector,
        shape,
    }) => {
        if (!symbolGroup || !symbolRefs[markType]) {
            return null;
        }
        const stroke = strokePalette[markColor] || strokePalette.blue;
        const anchorX = Number.isFinite(position?.x) ? position.x : geometry.cx;
        const anchorY = Number.isFinite(position?.y) ? position.y : geometry.cy;

        removeSurfaceSymbol(symbolGroup, surface);

        const group = document.createElementNS(svgNS, 'g');
        group.setAttribute('data-surface', surface);
        group.setAttribute('data-mark', markType);
        if (diagram) {
            group.setAttribute('data-diagram', diagram);
        }
        if (toothCode) {
            group.setAttribute('data-tooth', toothCode);
        }
        if (symbolZone) {
            group.setAttribute('data-zone', symbolZone);
        }
        if (toothCode && surface) {
            group.setAttribute('data-cell-key', `${toothCode}::${surface}`);
        }
        if (sector) {
            group.setAttribute('data-sector', sector);
        }
        const typeAlias = markTypeDatasetMap[markType] || '';
        if (typeAlias) {
            group.setAttribute('data-type', typeAlias);
        }
        group.setAttribute('data-color', markColor);
        const shapeAttr = shape === 'square' ? 'square' : 'circle';
        group.setAttribute('data-shape', shapeAttr);
        group.setAttribute('transform', formatTranslate(anchorX, anchorY));
        group.setAttribute('data-x', String(roundPositionValue(anchorX)));
        group.setAttribute('data-y', String(roundPositionValue(anchorY)));
        group.setAttribute('pointer-events', 'none');
        group.setAttribute('fill', 'none');

        // Caso especial: X completa en botón circular
        if (markType === 'x' && shape === 'circle') {
            // Buscar el grupo full sin clip-path
            const toothCards = Array.from(form.querySelectorAll('.tooth-card'));
            let fullGroup = null;
            for (const card of toothCards) {
                if (card.dataset.tooth === toothCode) {
                    const fullGroupId = card.dataset.symbolGroupFull;
                    if (fullGroupId) {
                        fullGroup = document.getElementById(fullGroupId);
                    }
                    break;
                }
            }

            // Si encontramos el grupo full, limpiar símbolos anteriores de TODAS las superficies
            if (fullGroup) {
                const oldGroups = Array.from(fullGroup.querySelectorAll(`[data-tooth="${toothCode}"]`));
                oldGroups.forEach(oldGroup => oldGroup.remove());
            }

            // Usar el grupo full si existe, sino usar el symbolGroup original
            const targetGroup = fullGroup || symbolGroup;

            // Calcular dimensiones de la X: 115% del radio externo
            const cx = geometry.cx;
            const cy = geometry.cy;
            const L = geometry.outerRadius * 1.15;

            // Crear las dos líneas de la X sin transform (coordenadas absolutas)
            group.removeAttribute('transform');

            const needsHalo = Boolean(fillColor && allowedColors.includes(fillColor));

            if (needsHalo) {
                const haloPath = document.createElementNS(svgNS, 'path');
                const d = `M${cx - L} ${cy - L} L${cx + L} ${cy + L} M${cx + L} ${cy - L} L${cx - L} ${cy + L}`;
                haloPath.setAttribute('d', d);
                haloPath.setAttribute('stroke', '#ffffff');
                haloPath.setAttribute('stroke-width', '6');
                haloPath.setAttribute('stroke-linecap', 'round');
                haloPath.setAttribute('fill', 'none');
                group.appendChild(haloPath);
            }

            const xPath = document.createElementNS(svgNS, 'path');
            const d = `M${cx - L} ${cy - L} L${cx + L} ${cy + L} M${cx + L} ${cy - L} L${cx - L} ${cy + L}`;
            xPath.setAttribute('d', d);
            xPath.setAttribute('stroke', stroke);
            xPath.setAttribute('stroke-width', '4');
            xPath.setAttribute('stroke-linecap', 'round');
            xPath.setAttribute('fill', 'none');
            group.appendChild(xPath);

            targetGroup.appendChild(group);
            return group;
        }

        // Caso especial: X solo en centro para botón cuadrado
        if (markType === 'x' && shape === 'square') {
            // Forzar que la X solo se dibuje en el centro
            group.setAttribute('data-zone', 'center');
            group.setAttribute('data-sector', 'center');

            // Posicionar en el centro
            group.setAttribute('transform', formatTranslate(geometry.cx, geometry.cy));
            group.setAttribute('data-x', String(geometry.cx));
            group.setAttribute('data-y', String(geometry.cy));
        }

        // Seleccionar el símbolo correcto según la forma (círculo o cuadrado)
        let symbolHref = symbolRefs[markType];
        if (shape === 'circle' && markType !== 'x') {
            // Para botones circulares, usar símbolos rotados
            symbolHref = symbolHref + '-circle';
        }
        const needsHalo = Boolean(fillColor && allowedColors.includes(fillColor));

        if (needsHalo) {
            const halo = document.createElementNS(svgNS, 'use');
            halo.setAttribute('stroke', '#ffffff');
            // El halo de la equis debe ser más grueso para mantener la proporción
            // Circular: 6, Cuadrado: 12
            if (markType === 'x') {
                halo.setAttribute('stroke-width', shape === 'circle' ? '6' : '12');
            } else {
                halo.setAttribute('stroke-width', '6');
            }
            halo.setAttribute('stroke-linecap', 'round');
            halo.setAttribute('fill', 'none');
            halo.setAttribute('href', symbolHref);
            halo.setAttributeNS(xlinkNS, 'href', symbolHref);
            group.appendChild(halo);
        }

        const use = document.createElementNS(svgNS, 'use');
        use.setAttribute('stroke', stroke);
        // La equis (x) debe ser más gruesa que los demás símbolos
        // Circular: 4, Cuadrado: 8
        if (markType === 'x') {
            use.setAttribute('stroke-width', shape === 'circle' ? '4' : '8');
        } else {
            use.setAttribute('stroke-width', '4');
        }
        use.setAttribute('stroke-linecap', 'round');
        // El punto debe ser relleno, los demás símbolos solo contorno
        use.setAttribute('fill', markType === 'dot' ? stroke : 'none');
        use.setAttribute('href', symbolHref);
        use.setAttributeNS(xlinkNS, 'href', symbolHref);
        group.appendChild(use);

        symbolGroup.appendChild(group);
        return group;
    };

    const applyStateToCell = ({
        cell,
        cellState,
        symbolGroup,
        surface,
        anchorPosition,
        symbolZone,
        diagram,
        toothCode,
    }) => {
        const normalized = normalizeCellState(cellState);
        clearFill(cell);
        clearMarkState(cell);
        removeSurfaceSymbol(symbolGroup, surface);
        let createdSymbol = null;
        const cellShape = cell.dataset.shape === 'square' ? 'square' : 'circle';
        const initialSector = cell.dataset.sector || determineSector(symbolZone, surface, anchorPosition);
        if (initialSector) {
            cell.dataset.sector = initialSector;
        }

        if (!normalized) {
            return null;
        }

        if (normalized.color) {
            applyFill(cell, normalized.color);
        }

        if (normalized.mark) {
            const basePosition = normalized.position ? clonePosition(normalized.position) : clonePosition(anchorPosition);
            const resolvedPosition = basePosition || clonePosition(anchorPosition);
            let sector = cell.dataset.sector || determineSector(symbolZone, surface, resolvedPosition);
            if (!sector) {
                sector = symbolZone === 'center' ? 'center' : 'upper_right';
            }
            cell.dataset.sector = sector;
            const restriction = {
                sector,
                symbolZone,
                shape: cellShape,
            };
            const fallbackPosition = resolvedPosition || clonePosition(anchorPosition) || { x: geometry.cx, y: geometry.cy };
            const safePosition = restrictPositionToZone(fallbackPosition, restriction);
            createdSymbol = addSurfaceSymbol({
                symbolGroup,
                surface,
                markType: normalized.mark,
                markColor: normalized.markColor,
                position: safePosition,
                fillColor: normalized.color,
                toothCode,
                diagram,
                symbolZone,
                sector,
                shape: cellShape,
            });
            cell.classList.add('has-mark');
            cell.dataset.mark = normalized.mark;
            cell.dataset.markColor = normalized.markColor;
            if (normalized.markColor) {
                cell.classList.add(`color-${normalized.markColor}`);
            }
            if (safePosition) {
                cell.dataset.markX = String(safePosition.x);
                cell.dataset.markY = String(safePosition.y);
            } else {
                delete cell.dataset.markX;
                delete cell.dataset.markY;
            }
        }
        return createdSymbol;
    };

    const getToothEntry = (diagram, tooth) => {
        if (!state[diagram]) {
            state[diagram] = {};
        }
        if (!state[diagram][tooth]) {
            state[diagram][tooth] = { surfaces: {} };
        } else if (!state[diagram][tooth].surfaces) {
            state[diagram][tooth].surfaces = {};
        }
        return state[diagram][tooth];
    };

    const getCellState = (diagram, tooth, surface) => {
        const diagramData = state[diagram];
        if (!diagramData || !diagramData[tooth] || !diagramData[tooth].surfaces) {
            return null;
        }
        return diagramData[tooth].surfaces[surface] || null;
    };

    const setCellState = (diagram, tooth, surface, cellState) => {
        const entry = getToothEntry(diagram, tooth);
        const normalized = cellState ? normalizeCellState(cellState) : null;
        if (normalized) {
            entry.surfaces[surface] = { ...normalized };
            if (entry.__empty) {
                delete entry.__empty;
            }
        } else {
            delete entry.surfaces[surface];
        }

        const remainingSurfaces = Object.keys(entry.surfaces);
        const hasMetadata = Boolean(entry.status || entry.notes);
        if (!remainingSurfaces.length) {
            if (diagram === 'evolucion') {
                entry.surfaces = {};
                entry.__empty = true;
                state[diagram][tooth] = entry;
            } else if (hasMetadata) {
                entry.surfaces = {};
                if (entry.__empty) {
                    delete entry.__empty;
                }
                state[diagram][tooth] = entry;
            } else {
                delete state[diagram][tooth];
            }
            return;
        }
        if (entry.__empty) {
            delete entry.__empty;
        }
        state[diagram][tooth] = entry;
    };

    const setActiveButton = (buttons, activeButton) => {
        buttons.forEach((button) => {
            const isActive = Boolean(activeButton && button === activeButton);
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    };

    wrappers.forEach((wrapper) => {
        const diagram = wrapper.dataset.diagram || 'odontodiagrama';
        if (!state[diagram]) {
            state[diagram] = {};
        }

        const section = wrapper.closest('[data-odontogram-section]');
        const metadataPanel = section ? section.querySelector('[data-odontogram-metadata]') : null;
        const metadataEmpty = metadataPanel ? metadataPanel.querySelector('[data-odontogram-empty]') : null;
        const metadataFields = metadataPanel ? metadataPanel.querySelector('[data-odontogram-fields]') : null;
        const metadataToothLabel = metadataPanel ? metadataPanel.querySelector('[data-odontogram-tooth]') : null;
        const metadataStatusField = metadataPanel ? metadataPanel.querySelector('[data-odontogram-status]') : null;
        const metadataNotesField = metadataPanel ? metadataPanel.querySelector('[data-odontogram-notes]') : null;
        const metadataClearButton = metadataPanel ? metadataPanel.querySelector('[data-odontogram-clear]') : null;

        const metadataControls = {
            panel: metadataPanel,
            empty: metadataEmpty,
            fields: metadataFields,
            toothLabel: metadataToothLabel,
            statusField: metadataStatusField,
            notesField: metadataNotesField,
            clearButton: metadataClearButton,
        };

        const toothCards = Array.from(wrapper.querySelectorAll('.tooth-card'));
        const toothCardLookup = new Map();
        toothCards.forEach((card) => {
            const code = (card.dataset.tooth || '').trim();
            if (code) {
                toothCardLookup.set(code, card);
            }
        });

        let activeToothCode = null;
        let suppressMetadataHandlers = false;

        const getMetadataFallbackStatus = () => {
            if (defaultStatusValue) {
                return defaultStatusValue;
            }
            if (metadataControls.statusField && metadataControls.statusField.options.length > 0) {
                return metadataControls.statusField.options[0].value;
            }
            return '';
        };

        const toggleMetadataView = (showFields) => {
            if (!metadataControls.panel) {
                return;
            }
            if (metadataControls.empty) {
                metadataControls.empty.hidden = Boolean(showFields);
            }
            if (metadataControls.fields) {
                metadataControls.fields.hidden = !showFields;
            }
        };

        const refreshMetadataBadge = (toothCode) => {
            if (!toothCode) {
                return;
            }
            const card = toothCardLookup.get(toothCode);
            if (!card) {
                return;
            }
            const entry = getExistingEntry(diagram, toothCode);
            const hasStatus = Boolean(normalizeStatus(entry?.status));
            const hasNotes = Boolean(normalizeNotes(entry?.notes));
            const hasMetadata = hasStatus || hasNotes;
            if (hasMetadata) {
                card.classList.add('has-metadata');
            } else {
                card.classList.remove('has-metadata');
            }
        };

        const populateMetadataFields = () => {
            if (!metadataControls.panel) {
                return;
            }
            if (!activeToothCode) {
                if (metadataControls.toothLabel) {
                    metadataControls.toothLabel.textContent = '—';
                }
                toggleMetadataView(false);
                suppressMetadataHandlers = true;
                if (metadataControls.statusField) {
                    metadataControls.statusField.value = getMetadataFallbackStatus();
                }
                if (metadataControls.notesField) {
                    metadataControls.notesField.value = '';
                }
                suppressMetadataHandlers = false;
                return;
            }

            const entry = getExistingEntry(diagram, activeToothCode);
            const statusValue = normalizeStatus(entry?.status);
            const notesValueRaw = typeof entry?.notes === 'string' ? entry.notes : '';
            if (metadataControls.toothLabel) {
                metadataControls.toothLabel.textContent = activeToothCode;
            }
            suppressMetadataHandlers = true;
            if (metadataControls.statusField) {
                const fallback = statusValue || getMetadataFallbackStatus();
                metadataControls.statusField.value = fallback;
            }
            if (metadataControls.notesField) {
                metadataControls.notesField.value = notesValueRaw;
            }
            suppressMetadataHandlers = false;
            toggleMetadataView(true);
        };

        const clearActiveSelection = () => {
            if (activeToothCode && toothCardLookup.has(activeToothCode)) {
                const previousCard = toothCardLookup.get(activeToothCode);
                if (previousCard) {
                    previousCard.classList.remove('is-selected');
                }
            }
            activeToothCode = null;
            populateMetadataFields();
        };

        const setActiveTooth = (toothCode, options = {}) => {
            if (!toothCode) {
                clearActiveSelection();
                return;
            }
            const normalized = toothCode.trim();
            if (!normalized || !toothCardLookup.has(normalized)) {
                return;
            }
            if (activeToothCode !== normalized || options.force) {
                if (activeToothCode && toothCardLookup.has(activeToothCode)) {
                    const previousCard = toothCardLookup.get(activeToothCode);
                    if (previousCard) {
                        previousCard.classList.remove('is-selected');
                    }
                }
                activeToothCode = normalized;
                const nextCard = toothCardLookup.get(activeToothCode);
                if (nextCard) {
                    nextCard.classList.add('is-selected');
                }
                populateMetadataFields();
            } else if (metadataControls.panel) {
                populateMetadataFields();
            }
            if (options.focusStatus && metadataControls.statusField) {
                metadataControls.statusField.focus();
            }
        };

        if (metadataControls.panel) {
            toggleMetadataView(false);
        }

        const handleMetadataChange = () => {
            if (!activeToothCode || suppressMetadataHandlers) {
                return;
            }
            const statusValue = metadataControls.statusField
                ? metadataControls.statusField.value
                : normalizeStatus(getExistingEntry(diagram, activeToothCode)?.status);
            const notesValue = metadataControls.notesField
                ? metadataControls.notesField.value
                : getExistingEntry(diagram, activeToothCode)?.notes || '';
            const result = applyMetadataToState(diagram, activeToothCode, {
                status: statusValue,
                notes: notesValue,
            });
            if (result.changed) {
                markDirtyForDiagram(diagram);
            }
            refreshMetadataBadge(activeToothCode);
        };

        if (metadataControls.statusField) {
            metadataControls.statusField.addEventListener('change', handleMetadataChange);
        }
        if (metadataControls.notesField) {
            metadataControls.notesField.addEventListener('input', handleMetadataChange);
            metadataControls.notesField.addEventListener('blur', handleMetadataChange);
        }
        if (metadataControls.clearButton) {
            metadataControls.clearButton.addEventListener('click', () => {
                if (!activeToothCode) {
                    return;
                }
                const result = applyMetadataToState(diagram, activeToothCode, { status: '', notes: '' });
                if (result.changed) {
                    markDirtyForDiagram(diagram);
                }
                refreshMetadataBadge(activeToothCode);
                populateMetadataFields();
            });
        }

        toothCards.forEach((card) => {
            const toothCode = (card.dataset.tooth || '').trim();
            if (!toothCode) {
                return;
            }
            card.addEventListener('click', (event) => {
                if (event.defaultPrevented) {
                    return;
                }
                const targetElement = event.target instanceof Element ? event.target : null;
                if (targetElement && targetElement.closest('.tooth-cell')) {
                    return;
                }
                setActiveTooth(toothCode);
            });
            card.addEventListener('focusin', () => {
                setActiveTooth(toothCode);
            });
        });

        const isLocked = () => false;

        const rememberOriginalTabIndex = (element) => {
            if (!element.dataset.originalTabindexStored) {
                element.dataset.originalTabindexStored = 'true';
                element.dataset.originalTabindex = element.getAttribute('tabindex') ?? '';
            }
        };

        const colorButtons = Array.from(wrapper.querySelectorAll('.color-option'));
        const modeButtons = Array.from(wrapper.querySelectorAll('.mode-option'));
        const markButtons = Array.from(wrapper.querySelectorAll('.mark-option'));
        const cells = Array.from(wrapper.querySelectorAll('.tooth-cell'));
        cells.forEach(rememberOriginalTabIndex);
        const cellLookup = new Map();
        const makeCellKey = (tooth, surfaceKey) => `${tooth}::${surfaceKey}`;
        const symbolElements = new Map();
        const disableButtonGroup = (buttons, disabled) => {
            buttons.forEach((button) => {
                button.disabled = disabled;
                button.setAttribute('aria-disabled', disabled ? 'true' : 'false');
            });
        };
        const setWrapperUnlocked = () => {
            wrapper.dataset.locked = 'false';
            wrapper.classList.remove('is-locked');
            disableButtonGroup(colorButtons, false);
            disableButtonGroup(modeButtons, false);
            disableButtonGroup(markButtons, false);
            cells.forEach((cell) => {
                rememberOriginalTabIndex(cell);
                const original = cell.dataset.originalTabindex || '';
                if (original !== '') {
                    cell.setAttribute('tabindex', original);
                } else {
                    cell.removeAttribute('tabindex');
                }
            });
            refreshSymbolInteractivity();
        };
        const dragState = {
            symbolElement: null,
            pointerId: null,
            svg: null,
            cellKey: null,
            diagram: null,
            toothCode: null,
            surface: null,
            symbolZone: null,
            sector: null,
            shape: null,
            offsetX: 0,
            offsetY: 0,
            currentPosition: null,
            startPosition: null,
        };
        const resetDragState = () => {
            dragState.symbolElement = null;
            dragState.pointerId = null;
            dragState.svg = null;
            dragState.cellKey = null;
            dragState.diagram = null;
            dragState.toothCode = null;
            dragState.surface = null;
            dragState.symbolZone = null;
            dragState.sector = null;
            dragState.shape = null;
            dragState.offsetX = 0;
            dragState.offsetY = 0;
            dragState.currentPosition = null;
            dragState.startPosition = null;
        };
        const getCellKeyFromSymbol = (symbolElement) => {
            if (!symbolElement) {
                return null;
            }
            const explicitKey = symbolElement.getAttribute('data-cell-key');
            if (explicitKey) {
                return explicitKey;
            }
            const toothAttr = symbolElement.getAttribute('data-tooth');
            const surfaceAttr = symbolElement.getAttribute('data-surface');
            if (toothAttr && surfaceAttr) {
                return makeCellKey(toothAttr, surfaceAttr);
            }
            return null;
        };
        const getRestrictionContext = (symbolElement, cellContext, explicitSector) => {
            const symbolZoneAttr = symbolElement.getAttribute('data-zone') || cellContext?.symbolZone || 'ring';
            let sectorAttr = explicitSector || symbolElement.getAttribute('data-sector') || '';
            const defaultSector = cellContext?.defaultSector || cellContext?.cell?.dataset.sector || '';
            if (!sectorAttr && defaultSector) {
                sectorAttr = defaultSector;
            }
            if (!sectorAttr && cellContext) {
                sectorAttr = determineSector(symbolZoneAttr, cellContext.surface, cellContext.anchorPosition);
            }
            if (!sectorAttr) {
                sectorAttr = symbolZoneAttr === 'center' ? 'center' : 'upper_right';
            }
            const shapeAttr = symbolElement.getAttribute('data-shape')
                || cellContext?.shape
                || cellContext?.cell?.dataset.shape
                || 'circle';
            const normalizedShape = shapeAttr === 'square' ? 'square' : 'circle';
            symbolElement.setAttribute('data-sector', sectorAttr);
            symbolElement.setAttribute('data-zone', symbolZoneAttr);
            symbolElement.setAttribute('data-shape', normalizedShape);
            return {
                sector: sectorAttr,
                symbolZone: symbolZoneAttr,
                shape: normalizedShape,
            };
        };
        const applyPositionToSymbol = (symbolElement, position, restriction) => {
            const restricted = restrictPositionToZone(position, restriction);
            symbolElement.setAttribute('transform', formatTranslate(restricted.x, restricted.y));
            symbolElement.setAttribute('data-x', String(restricted.x));
            symbolElement.setAttribute('data-y', String(restricted.y));
            return restricted;
        };
        const updateCellPositionDataset = (cellContext, position) => {
            if (!cellContext) {
                return;
            }
            cellContext.cell.dataset.markX = String(position.x);
            cellContext.cell.dataset.markY = String(position.y);
        };
        const commitPositionChange = (cellKey, position) => {
            if (!cellKey || !position) {
                return;
            }
            const cellContext = cellLookup.get(cellKey);
            if (!cellContext) {
                return;
            }
            const { diagram: ctxDiagram, toothCode, surface } = cellContext;
            const existingState = getCellState(ctxDiagram, toothCode, surface);
            if (!existingState || !existingState.mark) {
                return;
            }
            const nextState = {
                ...existingState,
                position: { x: position.x, y: position.y },
            };
            setCellState(ctxDiagram, toothCode, surface, nextState);
            markDirtyForDiagram(ctxDiagram);
        };
        const handlePointerDown = (event) => {
            if (currentMode !== 'move' || isLocked()) {
                return;
            }
            const symbolElement = event.currentTarget;
            if (!symbolElement || symbolElement.tagName.toLowerCase() !== 'g') {
                return;
            }
            const cellKey = getCellKeyFromSymbol(symbolElement);
            if (!cellKey) {
                return;
            }
            const cellContext = cellLookup.get(cellKey);
            if (!cellContext) {
                return;
            }
            const svg = symbolElement.ownerSVGElement;
            if (!svg) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();

            const restriction = getRestrictionContext(symbolElement, cellContext);
            const transformValue = symbolElement.getAttribute('transform');
            const startPosition = parseTranslate(transformValue);
            const pointerPoint = getSvgPoint(svg, event);

            dragState.symbolElement = symbolElement;
            dragState.pointerId = event.pointerId;
            dragState.svg = svg;
            dragState.cellKey = cellKey;
            dragState.diagram = cellContext.diagram;
            dragState.toothCode = cellContext.toothCode;
            dragState.surface = cellContext.surface;
            dragState.symbolZone = restriction.symbolZone;
            dragState.sector = restriction.sector;
            dragState.shape = restriction.shape;
            dragState.offsetX = startPosition.x - pointerPoint.x;
            dragState.offsetY = startPosition.y - pointerPoint.y;
            dragState.startPosition = { x: startPosition.x, y: startPosition.y };
            dragState.currentPosition = null;

            symbolElement.setPointerCapture(event.pointerId);
            symbolElement.style.cursor = 'grabbing';
            symbolElement.classList.add('is-dragging');
        };
        const handlePointerMove = (event) => {
            if (isLocked()) {
                return;
            }
            if (!dragState.symbolElement || dragState.pointerId !== event.pointerId) {
                return;
            }
            event.preventDefault();
            const svg = dragState.svg;
            const symbolElement = dragState.symbolElement;
            if (!svg || !symbolElement) {
                return;
            }
            const pointerPoint = getSvgPoint(svg, event);
            const proposed = {
                x: pointerPoint.x + dragState.offsetX,
                y: pointerPoint.y + dragState.offsetY,
            };
            const restriction = {
                sector: dragState.sector,
                symbolZone: dragState.symbolZone,
                shape: dragState.shape || 'circle',
            };
            const restricted = applyPositionToSymbol(symbolElement, proposed, restriction);
            dragState.currentPosition = restricted;
            const cellContext = cellLookup.get(dragState.cellKey);
            updateCellPositionDataset(cellContext, restricted);
        };
        const finishPointerInteraction = (event, cancel = false) => {
            if (!dragState.symbolElement || dragState.pointerId !== event.pointerId) {
                return;
            }
            const symbolElement = dragState.symbolElement;
            try {
                symbolElement.releasePointerCapture(event.pointerId);
            } catch (error) {
                /* Ignorado si el pointer capture ya no es válido */
            }
            symbolElement.style.cursor = 'grab';
            symbolElement.classList.remove('is-dragging');

            if (!cancel && dragState.currentPosition) {
                const cellContext = cellLookup.get(dragState.cellKey);
                updateCellPositionDataset(cellContext, dragState.currentPosition);
                commitPositionChange(dragState.cellKey, dragState.currentPosition);
            }
            resetDragState();
        };
        const handlePointerUp = (event) => {
            if (dragState.pointerId === event.pointerId) {
                event.preventDefault();
            }
            finishPointerInteraction(event, false);
        };
        const handlePointerCancel = (event) => {
            finishPointerInteraction(event, true);
        };
        const handleSymbolKeydown = (event) => {
            if (currentMode !== 'move' || isLocked()) {
                return;
            }
            const { key } = event;
            if (!['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(key)) {
                return;
            }
            const symbolElement = event.currentTarget;
            const cellKey = getCellKeyFromSymbol(symbolElement);
            if (!cellKey) {
                return;
            }
            const cellContext = cellLookup.get(cellKey);
            if (!cellContext) {
                return;
            }
            event.preventDefault();
            const restriction = getRestrictionContext(symbolElement, cellContext);
            const currentTransform = symbolElement.getAttribute('transform');
            const currentPosition = parseTranslate(currentTransform);
            const step = event.shiftKey ? 2 : 0.5;
            const proposed = { ...currentPosition };
            if (key === 'ArrowUp') {
                proposed.y -= step;
            } else if (key === 'ArrowDown') {
                proposed.y += step;
            } else if (key === 'ArrowLeft') {
                proposed.x -= step;
            } else if (key === 'ArrowRight') {
                proposed.x += step;
            }
            const restricted = applyPositionToSymbol(symbolElement, proposed, restriction);
            updateCellPositionDataset(cellContext, restricted);
            commitPositionChange(cellKey, restricted);
            dragState.currentPosition = restricted;
            dragState.cellKey = cellKey;
            dragState.diagram = cellContext.diagram;
            dragState.toothCode = cellContext.toothCode;
            dragState.surface = cellContext.surface;
            dragState.symbolZone = restriction.symbolZone;
            dragState.sector = restriction.sector;
            dragState.shape = restriction.shape;
        };
        function refreshSymbolInteractivity() {
            const isMoveMode = currentMode === 'move' && !isLocked();
            symbolElements.forEach((symbolElement) => {
                if (!symbolElement) {
                    return;
                }
                symbolElement.setAttribute('pointer-events', isMoveMode ? 'visiblePainted' : 'none');
                symbolElement.style.cursor = isMoveMode ? 'grab' : '';
                symbolElement.setAttribute('tabindex', isMoveMode ? '0' : '-1');
                symbolElement.style.touchAction = 'none';
            });
        }
        const initializeSymbolElement = (symbolElement) => {
            if (!symbolElement) {
                return;
            }
            if (symbolElement.dataset.dragInit === 'true') {
                return;
            }
            symbolElement.dataset.dragInit = 'true';
            if (!symbolElement.hasAttribute('tabindex')) {
                symbolElement.setAttribute('tabindex', '-1');
            }
            symbolElement.addEventListener('pointerdown', handlePointerDown);
            symbolElement.addEventListener('pointermove', handlePointerMove);
            symbolElement.addEventListener('pointerup', handlePointerUp);
            symbolElement.addEventListener('pointercancel', handlePointerCancel);
            symbolElement.addEventListener('keydown', handleSymbolKeydown);
            symbolElement.addEventListener('focus', () => {
                if (currentMode === 'move' && !isLocked()) {
                    symbolElement.style.cursor = 'grab';
                }
            });
            symbolElement.addEventListener('blur', () => {
                symbolElement.classList.remove('is-dragging');
            });
        };
        const syncSymbolElement = (cellKey, symbolElement) => {
            if (!cellKey) {
                return;
            }
            if (symbolElement) {
                symbolElements.set(cellKey, symbolElement);
                initializeSymbolElement(symbolElement);
            } else {
                symbolElements.delete(cellKey);
            }
            refreshSymbolInteractivity();
        };

        if (!colorButtons.length || !cells.length) {
            return;
        }

        let currentColorButton = colorButtons.find((button) => button.classList.contains('is-active')) || colorButtons[0];
        let currentColor = allowedColors.includes(currentColorButton?.dataset.color || '') ? currentColorButton?.dataset.color || 'blue' : 'blue';
        wrapper.dataset.activeColor = currentColor;

        let currentModeButton = modeButtons.find((button) => button.classList.contains('is-active')) || modeButtons[0] || null;
        const resolveMode = (modeValue) => {
            if (modeValue === 'mark' || modeValue === 'move') {
                return modeValue;
            }
            return 'color';
        };
        let currentMode = resolveMode(currentModeButton?.dataset.mode || 'color');

        let currentMarkButton = null;
        let currentMarkType = '';
        let currentTool = 'paint';

        const updateMode = (mode) => {
            currentMode = resolveMode(mode);
            wrapper.dataset.mode = currentMode;
            wrapper.classList.toggle('mark-mode', currentMode === 'mark');
            wrapper.classList.toggle('move-mode', currentMode === 'move');
            refreshSymbolInteractivity();
            if (currentMode !== 'move' && dragState.symbolElement && dragState.pointerId !== null) {
                try {
                    dragState.symbolElement.releasePointerCapture(dragState.pointerId);
                } catch (error) {
                    /* Ignorado */
                }
                dragState.symbolElement.classList.remove('is-dragging');
                dragState.symbolElement.style.cursor = '';
                resetDragState();
            }
        };

        if (modeButtons.length) {
            if (!currentModeButton) {
                currentModeButton = modeButtons[0];
                setActiveButton(modeButtons, currentModeButton);
                currentMode = resolveMode(currentModeButton.dataset.mode || 'color');
            }
            updateMode(currentMode);
        } else {
            updateMode(currentMode);
        }

        const setMarkButton = (button) => {
            currentMarkButton = button || null;
            if (!button) {
                currentMarkType = '';
                currentTool = 'paint';
                setActiveButton(markButtons, null);
                return;
            }
            const markValue = button.dataset.mark || '';
            if (markValue === 'erase') {
                currentMarkType = '';
                currentTool = 'erase';
            } else {
                currentMarkType = allowedMarks.includes(markValue) ? markValue : '';
                currentTool = 'paint';
            }
            setActiveButton(markButtons, button);
        };

        const initialMarkButton = markButtons.find((button) => button.classList.contains('is-active')) || null;
        setMarkButton(initialMarkButton);

        colorButtons.forEach((button) => {
            button.addEventListener('click', () => {
                if (isLocked()) {
                    return;
                }
                if (button === currentColorButton) {
                    return;
                }
                currentColorButton = button;
                const colorValue = button.dataset.color || '';
                currentColor = allowedColors.includes(colorValue) ? colorValue : 'blue';
                wrapper.dataset.activeColor = currentColor;
                setActiveButton(colorButtons, button);
            });
        });

        modeButtons.forEach((button) => {
            button.addEventListener('click', () => {
                if (isLocked()) {
                    return;
                }
                if (button === currentModeButton) {
                    return;
                }
                currentModeButton = button;
                setActiveButton(modeButtons, button);
                updateMode(button.dataset.mode || 'color');
            });
        });

        markButtons.forEach((button) => {
            button.addEventListener('click', () => {
                if (isLocked()) {
                    return;
                }
                if (button === currentMarkButton) {
                    setMarkButton(null);
                    return;
                }
                setMarkButton(button);
                if (button.dataset.mark !== 'erase' && currentMode !== 'mark') {
                    updateMode('mark');
                    if (modeButtons.length) {
                        const markModeButton = modeButtons.find((modeBtn) => (modeBtn.dataset.mode || 'color') === 'mark');
                        if (markModeButton) {
                            currentModeButton = markModeButton;
                            setActiveButton(modeButtons, markModeButton);
                        }
                    }
                }
            });
        });

        cells.forEach((cell) => {
            const toothCard = cell.closest('.tooth-card');
            const toothCode = toothCard?.dataset.tooth || '';
            const surface = cell.dataset.surface || '';
            const symbolZone = cell.dataset.symbolZone || 'ring';
            const shapeAttr = (cell.dataset.shape || toothCard?.dataset.shape || '').toLowerCase();
            const cellShape = shapeAttr === 'square' ? 'square' : 'circle';
            cell.dataset.shape = cellShape;
            const ringGroupId = toothCard?.dataset.symbolGroupRing || toothCard?.dataset.symbolGroup || '';
            const centerGroupId = toothCard?.dataset.symbolGroupCenter || ringGroupId;
            const targetGroupId = symbolZone === 'center' ? centerGroupId : ringGroupId;
            const symbolGroup = targetGroupId ? document.getElementById(targetGroupId) : null;
            const resolveFallbackPosition = () => {
                const xRaw = Number.parseFloat(cell.dataset.symbolX || `${geometry.cx}`);
                const yRaw = Number.parseFloat(cell.dataset.symbolY || `${geometry.cy}`);
                const x = Number.isFinite(xRaw) ? xRaw : geometry.cx;
                const y = Number.isFinite(yRaw) ? yRaw : geometry.cy;
                return { x, y };
            };

            const resolveAnchorPosition = () => {
                if (symbolZone === 'center') {
                    return { x: geometry.cx, y: geometry.cy };
                }
                const toothKind = toothCard?.dataset.toothKind || '';
                if (toothKind === 'deciduous') {
                    const angleDeg = sectorAngles[surface];
                    if (typeof angleDeg === 'number') {
                        const theta = (angleDeg * Math.PI) / 180;
                        const x = geometry.cx + ringAnchorRadius * Math.cos(theta);
                        const y = geometry.cy + ringAnchorRadius * Math.sin(theta);
                        return { x, y };
                    }
                }
                return resolveFallbackPosition();
            };

            const anchorPosition = resolveAnchorPosition();
            const defaultSector = cell.dataset.sector || determineSector(symbolZone, surface, anchorPosition);
            if (defaultSector) {
                cell.dataset.sector = defaultSector;
            }
            const cellKey = toothCode && surface ? makeCellKey(toothCode, surface) : null;
            if (cellKey) {
                cellLookup.set(cellKey, {
                    cell,
                    symbolGroup,
                    anchorPosition: clonePosition(anchorPosition),
                    symbolZone,
                    toothCode,
                    diagram,
                    surface,
                    shape: cellShape,
                    defaultSector,
                });
            }

            if (toothCode && surface) {
                const storedState = getCellState(diagram, toothCode, surface);
                const symbolElement = applyStateToCell({
                    cell,
                    cellState: storedState,
                    symbolGroup,
                    surface,
                    anchorPosition,
                    symbolZone,
                    diagram,
                    toothCode,
                });
                if (cellKey) {
                    syncSymbolElement(cellKey, symbolElement);
                }
            }

            cell.addEventListener('click', () => {
                if (!toothCode || !surface) {
                    return;
                }
                setActiveTooth(toothCode);
                if (isLocked()) {
                    return;
                }
                if (currentMode === 'move') {
                    return;
                }

                const existingState = getCellState(diagram, toothCode, surface);

                if (currentTool === 'erase') {
                    if (!existingState) {
                        return;
                    }
                    setCellState(diagram, toothCode, surface, null);
                    const symbolElement = applyStateToCell({
                        cell,
                        cellState: null,
                        symbolGroup,
                        surface,
                        anchorPosition,
                        symbolZone,
                        diagram,
                        toothCode,
                    });
                    if (cellKey) {
                        syncSymbolElement(cellKey, symbolElement);
                    }
                    markDirtyForDiagram(diagram);
                    refreshMetadataBadge(toothCode);
                    return;
                }

                if (currentMode === 'mark') {
                    if (!currentMarkType || !allowedMarks.includes(currentMarkType)) {
                        return;
                    }
                    const markColor = allowedColors.includes(currentColor) ? currentColor : 'blue';

                    // Si es X en botón cuadrado, forzar que se marque solo en el centro
                    let targetSurface = surface;
                    let targetCell = cell;
                    let targetSymbolGroup = symbolGroup;
                    let targetSymbolZone = symbolZone;
                    let targetAnchorPosition = anchorPosition;
                    let targetCellKey = cellKey;

                    if (currentMarkType === 'x' && cellShape === 'square') {
                        // Buscar la celda del centro del mismo diente
                        const centerKey = makeCellKey(toothCode, 'center');
                        const centerContext = cellLookup.get(centerKey);

                        if (centerContext) {
                            targetSurface = 'center';
                            targetCell = centerContext.cell;
                            targetSymbolGroup = centerContext.symbolGroup;
                            targetSymbolZone = 'center';
                            targetAnchorPosition = centerContext.anchorPosition;
                            targetCellKey = centerKey;
                        }
                    }

                    const existingStateTarget = getCellState(diagram, toothCode, targetSurface);
                    const nextState = existingStateTarget ? { ...existingStateTarget } : { color: '', mark: '', markColor: '' };
                    const sameMark = existingStateTarget
                        && existingStateTarget.mark === currentMarkType
                        && (existingStateTarget.markColor || '') === markColor;

                    if (sameMark) {
                        nextState.mark = '';
                        nextState.markColor = '';
                    } else {
                        nextState.mark = currentMarkType;
                        nextState.markColor = markColor;
                    }

                    setCellState(diagram, toothCode, targetSurface, nextState);
                    const updatedState = getCellState(diagram, toothCode, targetSurface);
                    const symbolElement = applyStateToCell({
                        cell: targetCell,
                        cellState: updatedState,
                        symbolGroup: targetSymbolGroup,
                        surface: targetSurface,
                        anchorPosition: targetAnchorPosition,
                        symbolZone: targetSymbolZone,
                        diagram,
                        toothCode,
                    });
                    if (targetCellKey) {
                        syncSymbolElement(targetCellKey, symbolElement);
                    }
                    markDirtyForDiagram(diagram);
                    refreshMetadataBadge(toothCode);
                    return;
                }

                const colorToApply = allowedColors.includes(currentColor) ? currentColor : 'blue';
                const nextState = existingState
                    ? { ...existingState, color: colorToApply }
                    : { color: colorToApply, mark: '', markColor: '' };

                if (nextState.mark && !nextState.markColor) {
                    nextState.markColor = colorToApply;
                }

                setCellState(diagram, toothCode, surface, nextState);
                const updatedState = getCellState(diagram, toothCode, surface);
                const symbolElement = applyStateToCell({
                    cell,
                    cellState: updatedState,
                    symbolGroup,
                    surface,
                    anchorPosition,
                    symbolZone,
                    diagram,
                    toothCode,
                });
                if (cellKey) {
                    syncSymbolElement(cellKey, symbolElement);
                }
                markDirtyForDiagram(diagram);
                refreshMetadataBadge(toothCode);
            });

            if (cell.tagName !== 'BUTTON') {
                cell.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        if (isLocked()) {
                            return;
                        }
                        event.preventDefault();
                        cell.dispatchEvent(new MouseEvent('click', { bubbles: true }));
                    }
                });
            }
        });

        toothCardLookup.forEach((_, toothCode) => {
            refreshMetadataBadge(toothCode);
        });

        setWrapperUnlocked();

        wrapperControllers.set(diagram, {
            isLocked,
            setLocked: () => {},
        });
    });

    const toggleButtons = Array.from(form.querySelectorAll('[data-odontogram-toggle]'));
    toggleButtons.forEach((button) => {
        const targetDiagram = button.dataset.odontogramToggle || '';
        const controller = wrapperControllers.get(targetDiagram);
        if (!controller) {
            return;
        }
        const updateLabel = () => {
            const locked = controller.isLocked();
            const nextLabel = locked
                ? (button.dataset.labelLocked || button.textContent)
                : (button.dataset.labelUnlocked || button.textContent);
            button.textContent = nextLabel;
            button.setAttribute('aria-pressed', locked ? 'false' : 'true');
            button.classList.toggle('is-active', !locked);
        };
        button.type = 'button';
        updateLabel();
        toggleLabelUpdaters.set(targetDiagram, updateLabel);
        button.addEventListener('click', () => {
            controller.setLocked(false);
            updateLabel();
        });
    });

    form.addEventListener('submit', () => {
        if (!payloadInput) {
            return;
        }
        if (baseDirtyInput) {
            baseDirtyInput.value = form.dataset.odontogramBaseDirty === 'true' ? '1' : '0';
        }
        payloadInput.value = JSON.stringify(state);
    });
}

function setupFinancialCalculator() {
    const feeInput = document.querySelector('input[name="fee"]');
    const paymentInput = document.querySelector('input[name="payment"]');
    const balanceInput = document.querySelector('input[name="balance"]');
    if (!feeInput || !paymentInput || !balanceInput) {
        return;
    }

    const recalc = () => {
        const fee = parseFloat(feeInput.value) || 0;
        const payment = parseFloat(paymentInput.value) || 0;
        const balance = Math.max(fee - payment, 0);
        balanceInput.value = balance.toFixed(2);
    };

    feeInput.addEventListener('input', recalc);
    paymentInput.addEventListener('input', recalc);
    recalc();
}

function setupConsentFields() {
    const consentCheckbox = document.querySelector('input[name="consent_signed"]');
    const consentDate = document.querySelector('input[name="consent_signed_at"]');
    if (!consentCheckbox || !consentDate) {
        return;
    }

    const toggleDate = () => {
        consentDate.disabled = !consentCheckbox.checked;
        if (!consentCheckbox.checked) {
            consentDate.value = '';
        } else if (!consentDate.value) {
            const today = new Date();
            const month = String(today.getMonth() + 1).padStart(2, '0');
            const day = String(today.getDate()).padStart(2, '0');
            consentDate.value = `${today.getFullYear()}-${month}-${day}`;
        }
    };

    consentCheckbox.addEventListener('change', toggleDate);
    toggleDate();
}
