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

    const allowedColors = ['blue', 'red'];
    const strokePalette = {
        blue: '#1D4ED8',
        red: '#B91C1C',
    };
    const symbolRefs = {
        dot: '#mark-dot',
        x: '#mark-x',
        vertical: '#mark-vert',
        horizontal: '#mark-horz',
    };
    const allowedMarks = Object.keys(symbolRefs);
    const sectorAngles = {
        upper_right: 315,
        upper_left: 225,
        lower_left: 135,
        lower_right: 45,
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
    const colorClasses = ['color-blue', 'color-red'];
    const fillClasses = ['fill-blue', 'fill-red'];
    const svgNS = 'http://www.w3.org/2000/svg';
    const xlinkNS = 'http://www.w3.org/1999/xlink';

    const normalizeCellState = (cellState) => {
        if (!cellState || typeof cellState !== 'object') {
            return null;
        }
        const fillColor = allowedColors.includes(cellState.color) ? cellState.color : '';
        const mark = allowedMarks.includes(cellState.mark) ? cellState.mark : '';
        let markColor = '';
        if (mark) {
            if (allowedColors.includes(cellState.markColor)) {
                markColor = cellState.markColor;
            } else if (fillColor) {
                markColor = fillColor;
            } else {
                markColor = allowedColors[0];
            }
        }

        if (!fillColor && !mark) {
            return null;
        }

        return {
            color: fillColor,
            mark,
            markColor,
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
                if (!Object.keys(toothEntry.surfaces).length && !toothEntry.status && !toothEntry.notes) {
                    delete diagramData[toothKey];
                }
            });
        });
    };

    sanitizeLoadedState();

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
    };

    const removeSurfaceSymbol = (symbolGroup, surface) => {
        if (!symbolGroup) {
            return;
        }
        const nodes = Array.from(symbolGroup.querySelectorAll(`[data-surface="${surface}"]`));
        nodes.forEach((node) => node.remove());
    };

    const addSurfaceSymbol = (symbolGroup, surface, markType, markColor, position, fillColor) => {
        if (!symbolGroup || !symbolRefs[markType]) {
            return;
        }
        const stroke = strokePalette[markColor] || strokePalette.blue;
        const anchorX = Number.isFinite(position?.x) ? position.x : geometry.cx;
        const anchorY = Number.isFinite(position?.y) ? position.y : geometry.cy;

        removeSurfaceSymbol(symbolGroup, surface);

        const group = document.createElementNS(svgNS, 'g');
        group.setAttribute('data-surface', surface);
        group.setAttribute('data-mark', markType);
        group.setAttribute('data-color', markColor);
        group.setAttribute('transform', `translate(${anchorX}, ${anchorY})`);
        group.setAttribute('pointer-events', 'none');
        group.setAttribute('fill', 'none');

        const symbolHref = symbolRefs[markType];
        const needsHalo = Boolean(fillColor && allowedColors.includes(fillColor));

        if (needsHalo) {
            const halo = document.createElementNS(svgNS, 'use');
            halo.setAttribute('stroke', '#ffffff');
            halo.setAttribute('stroke-width', '5');
            halo.setAttribute('stroke-linecap', 'round');
            halo.setAttribute('fill', 'none');
            halo.setAttribute('href', symbolHref);
            halo.setAttributeNS(xlinkNS, 'href', symbolHref);
            group.appendChild(halo);
        }

        const use = document.createElementNS(svgNS, 'use');
        use.setAttribute('stroke', stroke);
        use.setAttribute('stroke-width', '3');
        use.setAttribute('stroke-linecap', 'round');
        use.setAttribute('fill', 'none');
        use.setAttribute('href', symbolHref);
        use.setAttributeNS(xlinkNS, 'href', symbolHref);
        group.appendChild(use);

        symbolGroup.appendChild(group);
    };

    const applyStateToCell = (cell, cellState, symbolGroup, surface, position) => {
        const normalized = normalizeCellState(cellState);
        clearFill(cell);
        clearMarkState(cell);
        removeSurfaceSymbol(symbolGroup, surface);

        if (!normalized) {
            return;
        }

        if (normalized.color) {
            applyFill(cell, normalized.color);
        }

        if (normalized.mark) {
            addSurfaceSymbol(symbolGroup, surface, normalized.mark, normalized.markColor, position, normalized.color);
            cell.classList.add('has-mark');
            cell.dataset.mark = normalized.mark;
            cell.dataset.markColor = normalized.markColor;
            if (normalized.markColor) {
                cell.classList.add(`color-${normalized.markColor}`);
            }
        }
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
        } else {
            delete entry.surfaces[surface];
        }

        const remainingSurfaces = Object.keys(entry.surfaces);
        if (!remainingSurfaces.length && !entry.status && !entry.notes) {
            delete state[diagram][tooth];
        } else {
            state[diagram][tooth] = entry;
        }
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

        const colorButtons = Array.from(wrapper.querySelectorAll('.color-option'));
        const modeButtons = Array.from(wrapper.querySelectorAll('.mode-option'));
        const markButtons = Array.from(wrapper.querySelectorAll('.mark-option'));
        const cells = Array.from(wrapper.querySelectorAll('.tooth-cell'));

        if (!colorButtons.length || !cells.length) {
            return;
        }

        let currentColorButton = colorButtons.find((button) => button.classList.contains('is-active')) || colorButtons[0];
        let currentColor = allowedColors.includes(currentColorButton?.dataset.color || '') ? currentColorButton?.dataset.color || 'blue' : 'blue';
        wrapper.dataset.activeColor = currentColor;

        let currentModeButton = modeButtons.find((button) => button.classList.contains('is-active')) || modeButtons[0] || null;
        let currentMode = currentModeButton?.dataset.mode === 'mark' ? 'mark' : 'color';

        let currentMarkButton = null;
        let currentMarkType = '';
        let currentTool = 'paint';

        const updateMode = (mode) => {
            currentMode = mode === 'mark' ? 'mark' : 'color';
            wrapper.dataset.mode = currentMode;
            wrapper.classList.toggle('mark-mode', currentMode === 'mark');
        };

        if (modeButtons.length) {
            if (!currentModeButton) {
                currentModeButton = modeButtons[0];
                setActiveButton(modeButtons, currentModeButton);
                currentMode = currentModeButton.dataset.mode === 'mark' ? 'mark' : 'color';
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

            if (toothCode && surface) {
                const storedState = getCellState(diagram, toothCode, surface);
                applyStateToCell(cell, storedState, symbolGroup, surface, anchorPosition);
            }

            cell.addEventListener('click', () => {
                if (!toothCode || !surface) {
                    return;
                }

                const existingState = getCellState(diagram, toothCode, surface);

                if (currentTool === 'erase') {
                    if (!existingState) {
                        return;
                    }
                    setCellState(diagram, toothCode, surface, null);
                    applyStateToCell(cell, null, symbolGroup, surface, anchorPosition);
                    form.dataset.odontogramDirty = 'true';
                    return;
                }

                if (currentMode === 'mark') {
                    if (!currentMarkType || !allowedMarks.includes(currentMarkType)) {
                        return;
                    }
                    const markColor = allowedColors.includes(currentColor) ? currentColor : 'blue';
                    const nextState = existingState ? { ...existingState } : { color: '', mark: '', markColor: '' };
                    const sameMark = existingState
                        && existingState.mark === currentMarkType
                        && (existingState.markColor || '') === markColor;

                    if (sameMark) {
                        nextState.mark = '';
                        nextState.markColor = '';
                    } else {
                        nextState.mark = currentMarkType;
                        nextState.markColor = markColor;
                    }

                    setCellState(diagram, toothCode, surface, nextState);
                    const updatedState = getCellState(diagram, toothCode, surface);
                    applyStateToCell(cell, updatedState, symbolGroup, surface, anchorPosition);
                    form.dataset.odontogramDirty = 'true';
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
                applyStateToCell(cell, updatedState, symbolGroup, surface, anchorPosition);
                form.dataset.odontogramDirty = 'true';
            });

            if (cell.tagName !== 'BUTTON') {
                cell.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        cell.dispatchEvent(new MouseEvent('click', { bubbles: true }));
                    }
                });
            }
        });
    });

    form.addEventListener('submit', () => {
        if (!payloadInput) {
            return;
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
