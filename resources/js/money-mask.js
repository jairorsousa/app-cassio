// Máscara de reais no estilo centavos: cada dígito entra pela direita.
// 1 → 0,01, 12 → 0,12, 123 → 1,23, 1234 → 12,34.

const MAX_DIGITS = 15;

export function formatCents(cents) {
    const amount = Math.abs(Number.isFinite(cents) ? cents : 0);
    const decimals = String(amount % 100).padStart(2, '0');
    const integers = String(Math.floor(amount / 100));
    const grouped = integers.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    return `${grouped},${decimals}`;
}

export function centsToModel(cents) {
    const amount = Math.abs(Number.isFinite(cents) ? cents : 0);
    const decimals = String(amount % 100).padStart(2, '0');
    const integers = String(Math.floor(amount / 100));

    return `${integers}.${decimals}`;
}

export function parseToCents(value) {
    if (value === null || value === undefined) {
        return 0;
    }

    let text = String(value).trim().replace(/\s/g, '').replace(/R\$/gi, '');
    if (text === '') {
        return 0;
    }

    const negative = text.startsWith('-');
    text = text.replace(/-/g, '').replace(/[^\d.,]/g, '');
    if (text === '' || !/\d/.test(text)) {
        return 0;
    }

    let integers;
    let fraction;

    if (text.includes(',')) {
        const [whole, frac = ''] = text.replace(/\./g, '').split(',');
        integers = whole;
        fraction = frac;
    } else if (text.includes('.')) {
        const parts = text.split('.');
        if (parts.length > 2) {
            integers = parts.join('');
            fraction = '';
        } else {
            integers = parts[0];
            fraction = parts[1];
        }
    } else {
        integers = text;
        fraction = '';
    }

    const cents = digitsToCents(integers, fraction);

    return negative ? -cents : cents;
}

export function appendDigit(cents, digit) {
    if (!/^\d$/.test(digit)) {
        return normalizeCents(cents);
    }

    const current = String(normalizeCents(cents));
    if (current.length >= MAX_DIGITS) {
        return normalizeCents(cents);
    }

    const next = Number.parseInt(current + digit, 10);

    return String(next).length > MAX_DIGITS ? normalizeCents(cents) : next;
}

export function backspaceCents(cents) {
    return Math.floor(normalizeCents(cents) / 10);
}

export function centsFromDigits(text) {
    const digits = String(text ?? '').replace(/\D/g, '').slice(0, MAX_DIGITS);

    return digits ? Number.parseInt(digits, 10) : 0;
}

function digitsToCents(integers, fraction) {
    let whole = String(integers ?? '').replace(/\D/g, '');
    let frac = String(fraction ?? '').replace(/\D/g, '');

    if (whole === '') {
        whole = '0';
    }

    if (frac.length > 2) {
        const roundUp = frac[2] >= '5';
        frac = frac.slice(0, 2);
        if (roundUp) {
            const rounded = BigInt(whole) * 100n + BigInt(frac || '0') + 1n;
            return clampCents(rounded);
        }
    }

    if (frac.length === 0) {
        frac = '00';
    } else if (frac.length === 1) {
        frac = `${frac}0`;
    }

    return clampCents(BigInt(whole) * 100n + BigInt(frac));
}

function clampCents(value) {
    const max = 10n ** BigInt(MAX_DIGITS) - 1n;
    const clamped = value > max ? max : value;

    return Number(clamped);
}

function normalizeCents(cents) {
    const amount = Math.abs(Number.isFinite(cents) ? cents : 0);

    return amount > Number(10n ** BigInt(MAX_DIGITS) - 1n)
        ? Number(10n ** BigInt(MAX_DIGITS) - 1n)
        : amount;
}

export function bindMoneyInput(el, { onChange = () => {} } = {}) {
    const state = {
        cents: 0,
        touched: false,
    };
    let ignoreNextInput = false;

    el.type = 'text';
    el.inputMode = 'numeric';
    el.autocomplete = 'off';
    el.spellcheck = false;
    el.placeholder = '0,00';
    el.setAttribute('inputmode', 'numeric');
    el.setAttribute('autocomplete', 'off');

    const placeCursor = () => {
        if (document.activeElement !== el) {
            return;
        }

        const end = el.value.length;
        try {
            el.setSelectionRange(end, end);
        } catch {
            // Alguns tipos de input não aceitam seleção.
        }
    };

    const render = () => {
        const formatted = formatCents(state.cents);
        if (el.value !== formatted) {
            el.value = formatted;
        }
        placeCursor();
    };

    const commit = (cents, { touch = false } = {}) => {
        state.cents = normalizeCents(cents);
        if (touch) {
            state.touched = true;
        }
        render();
        if (touch) {
            onChange(state.cents);
        }
    };

    const allSelected = () => {
        return el.selectionStart === 0 && el.selectionEnd === el.value.length && el.value.length > 0;
    };

    const insertText = (data, replacing) => {
        const digits = String(data ?? '').replace(/\D/g, '');
        if (digits === '') {
            render();
            return;
        }

        if (digits.length > 1) {
            commit(centsFromDigits(digits), { touch: true });
            return;
        }

        const base = replacing ? 0 : state.cents;
        commit(appendDigit(base, digits), { touch: true });
    };

    const removeDigit = (replacing) => {
        commit(replacing ? 0 : backspaceCents(state.cents), { touch: true });
    };

    const onBeforeInput = (event) => {
        if (ignoreNextInput && event.inputType === 'insertFromPaste') {
            event.preventDefault();
            return;
        }

        if (event.inputType === 'historyUndo' || event.inputType === 'historyRedo') {
            event.preventDefault();
            render();
            return;
        }

        const replacing = allSelected();

        if (event.inputType === 'insertText' && (event.data === null || event.data === undefined)) {
            return;
        }

        if (event.inputType === 'insertText' || event.inputType === 'insertReplacementText' || event.inputType === 'insertFromDrop') {
            event.preventDefault();
            swallowFollowingInput();
            insertText(event.data, replacing);
            return;
        }

        if (event.inputType === 'deleteContentBackward' || event.inputType === 'deleteContentForward' || event.inputType === 'deleteByCut') {
            event.preventDefault();
            swallowFollowingInput();
            removeDigit(replacing);
        }
    };

    const swallowFollowingInput = () => {
        ignoreNextInput = true;
        requestAnimationFrame(() => {
            ignoreNextInput = false;
        });
    };

    const onInput = (event) => {
        event.stopImmediatePropagation();

        if (ignoreNextInput) {
            ignoreNextInput = false;
            render();
            return;
        }

        const replacing = false;
        if (event.inputType === 'insertText' || event.inputType === 'insertReplacementText') {
            insertText(event.data, replacing);
            return;
        }

        if (event.inputType === 'deleteContentBackward' || event.inputType === 'deleteContentForward') {
            removeDigit(false);
            return;
        }

        if (event.inputType === 'insertFromPaste' || event.inputType === 'insertFromDrop') {
            insertText(event.data, true);
            return;
        }

        render();
    };

    const onPaste = (event) => {
        event.preventDefault();
        swallowFollowingInput();
        insertText(event.clipboardData?.getData('text') ?? '', true);
    };

    const onFocus = () => {
        requestAnimationFrame(placeCursor);
    };

    const onKeyUp = (event) => {
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight' || event.key === 'Home' || event.key === 'End') {
            placeCursor();
        }
    };

    el.addEventListener('beforeinput', onBeforeInput);
    el.addEventListener('input', onInput, true);
    el.addEventListener('paste', onPaste);
    el.addEventListener('focus', onFocus);
    el.addEventListener('keyup', onKeyUp);

    commit(parseToCents(el.value));

    return {
        cleanup() {
            el.removeEventListener('beforeinput', onBeforeInput);
            el.removeEventListener('input', onInput, true);
            el.removeEventListener('paste', onPaste);
            el.removeEventListener('focus', onFocus);
            el.removeEventListener('keyup', onKeyUp);
        },
        getCents() {
            return state.cents;
        },
        isTouched() {
            return state.touched;
        },
        setFromExternal(value) {
            state.cents = Math.abs(parseToCents(value));
            render();
        },
    };
}
