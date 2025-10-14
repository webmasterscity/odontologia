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
    const wrapper = document.querySelector('.odontogram-wrapper');
    if (!wrapper) {
        return;
    }

    const dataAttr = wrapper.getAttribute('data-odontogram') || '{}';
    let records = {};
    try {
        records = JSON.parse(dataAttr);
    } catch (err) {
        records = {};
    }

    const statusLabels = {
        sin_registro: 'Sin registro',
        sano: 'Sano',
        caries: 'Caries',
        restaurado: 'Restaurado',
        obturacion: 'Obturación',
        endodoncia: 'Endodoncia',
        protesis: 'Prótesis fija/removible',
        implante: 'Implante',
        ausente: 'Ausente',
        fractura: 'Fractura',
        en_tratamiento: 'En tratamiento'
    };

    const toothCodeInput = document.getElementById('tooth_code');
    const toothLabelInput = document.getElementById('tooth_label');
    const toothStatusSelect = document.getElementById('tooth_status');
    const toothNoteField = document.getElementById('tooth_note');

    const updateToothButton = (button) => {
        const code = button.dataset.tooth;
        const record = records[code];
        const statusElement = button.querySelector('.status');
        button.classList.remove('status-sano', 'status-caries', 'status-restaurado', 'status-obturacion', 'status-endodoncia', 'status-protesis', 'status-implante', 'status-ausente', 'status-fractura', 'status-en_tratamiento', 'status-sin_registro');
        if (record && record.status) {
            statusElement.textContent = statusLabels[record.status] || '';
            button.classList.add(`status-${record.status}`);
            button.title = record.notes ? record.notes : statusLabels[record.status];
        } else {
            statusElement.textContent = '—';
            button.classList.add('status-sin_registro');
            button.title = '';
        }
    };

    const buttons = [...wrapper.querySelectorAll('.tooth')];
    buttons.forEach(updateToothButton);

    let activeButton = null;

    const selectTooth = (button) => {
        if (!button) {
            return;
        }
        if (activeButton) {
            activeButton.classList.remove('active');
        }
        activeButton = button;
        button.classList.add('active');
        const code = button.dataset.tooth;
        const record = records[code] || {};
        toothCodeInput.value = code;
        if (toothLabelInput) {
            toothLabelInput.value = `Pieza ${code}`;
        }
        if (toothStatusSelect) {
            toothStatusSelect.value = record.status || 'sin_registro';
        }
        if (toothNoteField) {
            toothNoteField.value = record.notes || '';
        }
    };

    buttons.forEach((button) => {
        button.addEventListener('click', () => selectTooth(button));
    });

    // Selecciona la primera pieza disponible por defecto.
    if (buttons.length) {
        selectTooth(buttons[0]);
    }

    if (toothStatusSelect) {
        toothStatusSelect.addEventListener('change', () => {
            if (!activeButton) {
                return;
            }
            const code = activeButton.dataset.tooth;
            const status = toothStatusSelect.value;
            if (!records[code]) {
                records[code] = { status: status, notes: toothNoteField ? toothNoteField.value : '' };
            } else {
                records[code].status = status;
            }
            updateToothButton(activeButton);
        });
    }

    if (toothNoteField) {
        toothNoteField.addEventListener('input', () => {
            if (!activeButton) {
                return;
            }
            const code = activeButton.dataset.tooth;
            if (!records[code]) {
                records[code] = { status: toothStatusSelect ? toothStatusSelect.value : 'sin_registro', notes: toothNoteField.value };
            } else {
                records[code].notes = toothNoteField.value;
            }
            activeButton.title = toothNoteField.value;
        });
    }
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
