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
    const allowedMarks = ['', 'dot', 'x', 'vertical', 'horizontal'];
    const markClasses = ['mark-dot', 'mark-x', 'mark-vertical', 'mark-horizontal'];
    const colorClasses = ['color-blue', 'color-red'];
    const fillClasses = ['fill-blue', 'fill-red'];

    const clearCell = (cell) => {
        markClasses.forEach((cls) => cell.classList.remove(cls));
        colorClasses.forEach((cls) => cell.classList.remove(cls));
        fillClasses.forEach((cls) => cell.classList.remove(cls));
        cell.classList.remove('has-mark', 'has-fill');
        cell.dataset.mark = '';
        cell.dataset.color = '';
    };

    const applyStateToCell = (cell, cellState) => {
        clearCell(cell);
        if (!cellState || !cellState.color || !allowedColors.includes(cellState.color)) {
            return;
        }

        cell.dataset.color = cellState.color;
        cell.classList.add(`fill-${cellState.color}`);
        cell.classList.add('has-fill');

        if (cellState.mark && allowedMarks.includes(cellState.mark)) {
            cell.dataset.mark = cellState.mark;
            cell.classList.add(`mark-${cellState.mark}`);
            cell.classList.add(`color-${cellState.color}`);
            cell.classList.add('has-mark');
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
        if (cellState) {
            entry.surfaces[surface] = {
                color: cellState.color,
                mark: cellState.mark || '',
            };
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
        const markButtons = Array.from(wrapper.querySelectorAll('.mark-option'));
        const cells = Array.from(wrapper.querySelectorAll('.tooth-cell'));

        if (!colorButtons.length || !markButtons.length || !cells.length) {
            return;
        }

        let currentColorButton = colorButtons.find((button) => button.classList.contains('is-active')) || colorButtons[0];
        let currentMarkButton = markButtons.find((button) => button.classList.contains('is-active')) || null;

        let currentColor = currentColorButton?.dataset.color || 'blue';
        let currentMark = currentMarkButton?.dataset.mark || '';

        wrapper.dataset.activeColor = currentColor;

        colorButtons.forEach((button) => {
            button.addEventListener('click', () => {
                if (button === currentColorButton) {
                    return;
                }
                currentColorButton = button;
                currentColor = allowedColors.includes(button.dataset.color || '') ? button.dataset.color || 'blue' : 'blue';
                wrapper.dataset.activeColor = currentColor;
                setActiveButton(colorButtons, button);
            });
        });

        markButtons.forEach((button) => {
            button.addEventListener('click', () => {
                if (button === currentMarkButton) {
                    currentMarkButton = null;
                    currentMark = '';
                    setActiveButton(markButtons, null);
                    wrapper.classList.remove('erase-mode');
                    return;
                }
                currentMarkButton = button;
                currentMark = button.dataset.mark || '';
                setActiveButton(markButtons, button);
                wrapper.classList.toggle('erase-mode', currentMark === 'erase');
            });
        });

        cells.forEach((cell) => {
            const toothCard = cell.closest('.tooth-card');
            const toothCode = toothCard?.dataset.tooth || '';
            const surface = cell.dataset.surface || '';

            if (toothCode && surface) {
                const storedState = getCellState(diagram, toothCode, surface);
                if (storedState) {
                    applyStateToCell(cell, storedState);
                }
            }

            cell.addEventListener('click', () => {
                if (!toothCode || !surface) {
                    return;
                }

                if (currentMark === 'erase') {
                    setCellState(diagram, toothCode, surface, null);
                    applyStateToCell(cell, null);
                    form.dataset.odontogramDirty = 'true';
                    return;
                }

                const colorToApply = allowedColors.includes(currentColor) ? currentColor : 'blue';
                const markToApply = allowedMarks.includes(currentMark) ? currentMark : '';

                setCellState(diagram, toothCode, surface, { color: colorToApply, mark: markToApply });
                applyStateToCell(cell, { color: colorToApply, mark: markToApply });
                form.dataset.odontogramDirty = 'true';
            });
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
