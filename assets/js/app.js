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

    const colorButtons = Array.from(wrapper.querySelectorAll('.color-option'));
    const markButtons = Array.from(wrapper.querySelectorAll('.mark-option'));
    const cells = Array.from(wrapper.querySelectorAll('.tooth-cell'));

    if (!colorButtons.length || !markButtons.length || !cells.length) {
        return;
    }

    const markClasses = ['mark-dot', 'mark-x', 'mark-vertical', 'mark-horizontal'];
    const colorClasses = ['color-blue', 'color-red'];
    const fillClasses = ['fill-blue', 'fill-red'];

    const setActiveButton = (buttons, activeButton) => {
        buttons.forEach((button) => {
            const isActive = Boolean(activeButton && button === activeButton);
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    };

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
            currentColor = button.dataset.color || 'blue';
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

    const applyMark = (cell, mark, color) => {
        const previousColor = cell.dataset.color || '';
        const targetColor = color || previousColor;

        if (mark === 'erase') {
            markClasses.forEach((cls) => cell.classList.remove(cls));
            colorClasses.forEach((cls) => cell.classList.remove(cls));
            fillClasses.forEach((cls) => cell.classList.remove(cls));
            cell.classList.remove('has-mark', 'has-fill');
            cell.dataset.mark = '';
            cell.dataset.color = '';
            return;
        }

        if (!targetColor) {
            return;
        }

        markClasses.forEach((cls) => cell.classList.remove(cls));
        colorClasses.forEach((cls) => cell.classList.remove(cls));
        fillClasses.forEach((cls) => cell.classList.remove(cls));
        cell.classList.remove('has-mark', 'has-fill');

        cell.dataset.color = targetColor;
        cell.classList.add(`fill-${targetColor}`);
        cell.classList.add('has-fill');

        if (!mark) {
            cell.dataset.mark = '';
            return;
        }

        cell.dataset.mark = mark;
        cell.classList.add(`mark-${mark}`);
        cell.classList.add(`color-${targetColor}`);
        cell.classList.add('has-mark');
    };

    cells.forEach((cell) => {
        cell.addEventListener('click', () => {
            applyMark(cell, currentMark, currentColor);
        });
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
