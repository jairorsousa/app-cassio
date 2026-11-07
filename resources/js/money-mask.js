// Máscara de reais no padrão brasileiro: 1.234,56.
// Digitação livre: números entram normalmente e a vírgula (ou o ponto do teclado)
// marca os centavos. Colar "1234,56", "1.234,56", "R$ 1.234,56" ou "1234.56" funciona.

const MAX_INTEGER_DIGITS = 13;

function groupThousands(integers) {
    return integers.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

export function formatCents(cents) {
    const amount = Math.abs(Number.isFinite(cents) ? Math.round(cents) : 0);
    const decimals = String(amount % 100).padStart(2, '0');
    const integers = String(Math.floor(amount / 100));
    const sign = cents < 0 ? '-' : '';

    return `${sign}${groupThousands(integers)},${decimals}`;
}

export function centsToModel(cents) {
    const amount = Math.abs(Number.isFinite(cents) ? Math.round(cents) : 0);
    const decimals = String(amount % 100).padStart(2, '0');
    const integers = String(Math.floor(amount / 100));
    const sign = cents < 0 ? '-' : '';

    return `${sign}${integers}.${decimals}`;
}

// Entende os formatos comuns: 1234,56 · 1.234,56 · R$ 1.234,56 · 1234.56 · 1,234.56 · 1234
export function parseToCents(value) {
    if (value === null || value === undefined) {
        return 0;
    }

    let text = String(value).trim().replace(/\s|\u00a0/g, '').replace(/R\$/gi, '');
    const negative = /^-|^\(.*\)$|-$/.test(text);
    text = text.replace(/[^\d.,]/g, '');
    if (!/\d/.test(text)) {
        return 0;
    }

    const lastComma = text.lastIndexOf(',');
    const lastDot = text.lastIndexOf('.');
    let integers = text;
    let fraction = '';

    if (lastComma !== -1 && lastDot !== -1) {
        // O separador que aparece por último é o decimal.
        const decimalAt = Math.max(lastComma, lastDot);
        integers = text.slice(0, decimalAt);
        fraction = text.slice(decimalAt + 1);
    } else if (lastComma !== -1) {
        const parts = text.split(',');
        if (parts.length > 2) {
            integers = parts.join('');
        } else {
            [integers, fraction] = parts;
        }
    } else if (lastDot !== -1) {
        const parts = text.split('.');
        // "1.234.567" ou "1.234" (exatamente 3 casas) = milhar; "1234.56" = decimal.
        if (parts.length > 2 || (parts[1].length === 3 && parts[0].length <= 3 && /^[1-9]/.test(parts[0]))) {
            integers = parts.join('');
        } else {
            [integers, fraction] = parts;
        }
    }

    const cents = digitsToCents(integers, fraction);

    return negative ? -cents : cents;
}

function digitsToCents(integers, fraction) {
    let whole = String(integers ?? '').replace(/\D/g, '').replace(/^0+(?=\d)/, '').slice(-MAX_INTEGER_DIGITS) || '0';
    let frac = String(fraction ?? '').replace(/\D/g, '');
    let roundUp = false;

    if (frac.length > 2) {
        roundUp = frac[2] >= '5';
        frac = frac.slice(0, 2);
    }
    frac = frac.padEnd(2, '0');

    return Number(BigInt(whole) * 100n + BigInt(frac) + (roundUp ? 1n : 0n));
}

// Filtro simples da digitação: só dígitos, uma vírgula (o ponto vira vírgula), até 2 casas e,
// se permitido, um "-" no início. Não formata nada enquanto o campo está em foco.
export function filterTyped(raw, { allowNegative = false } = {}) {
    const text = String(raw ?? '');
    let out = '';
    let hasComma = false;
    let decimals = 0;
    for (let i = 0; i < text.length; i += 1) {
        let ch = text[i];
        if (ch === '.') ch = ',';
        if (/\d/.test(ch)) {
            if (hasComma) {
                if (decimals >= 2) continue;
                decimals += 1;
            }
            out += ch;
        } else if (ch === ',' && !hasComma) {
            hasComma = true;
            out += ch;
        } else if (ch === '-' && allowNegative && out === '') {
            out += ch;
        }
    }
    return out;
}

function editable(cents, empty) {
    if (empty || cents === 0) return '';
    return formatCents(cents).replace(/\./g, '').replace(/,00$/, '');
}

export function bindMoneyInput(el, { onCommit = () => {}, allowNegative = false } = {}) {
    const state = { cents: 0, empty: true, touched: false };

    el.type = 'text';
    el.setAttribute('inputmode', 'decimal');
    el.setAttribute('autocomplete', 'off');
    el.spellcheck = false;
    if (!el.getAttribute('placeholder') || /^0[.,]00$/.test(el.getAttribute('placeholder'))) {
        el.placeholder = '0,00';
    }

    const readText = () => {
        const text = el.value.trim();
        state.empty = !/\d/.test(text);
        const cents = state.empty ? 0 : parseToCents(text.replace(/\./g, ''));
        state.cents = allowNegative ? cents : Math.abs(cents);
    };

    const showFull = () => {
        el.value = state.empty || state.cents === 0 ? '' : formatCents(state.cents);
    };

    // 'input': filtra caracteres inválidos e mantém o cursor no mesmo lugar. Nada mais.
    const onInput = (event) => {
        event.stopImmediatePropagation();
        const value = el.value;
        const caret = el.selectionStart ?? value.length;
        const filtered = filterTyped(value, { allowNegative });
        if (filtered !== value) {
            const before = filterTyped(value.slice(0, caret), { allowNegative }).length;
            el.value = filtered;
            try { el.setSelectionRange(before, before); } catch { /* sem seleção */ }
        }
        state.touched = true;
    };

    // Colar "R$ 1.234,56", "1234.56" etc.: entende o formato e mostra editável.
    const onPaste = (event) => {
        const pasted = event.clipboardData?.getData('text') ?? '';
        if (!/\d/.test(pasted)) { event.preventDefault(); return; }
        if (el.value === '' || (el.selectionStart === 0 && el.selectionEnd === el.value.length)) {
            event.preventDefault();
            const cents = parseToCents(pasted);
            state.cents = allowNegative ? cents : Math.abs(cents);
            state.empty = false;
            state.touched = true;
            el.value = editable(state.cents, false);
        }
    };

    const stop = (event) => event.stopImmediatePropagation();

    const onFocus = () => {
        el.value = editable(state.cents, state.empty);
    };

    const finish = () => {
        readText();
        if (document.activeElement !== el) showFull();
        if (state.touched) onCommit();
    };

    const onKeydown = (event) => {
        if (event.key === 'Enter') { readText(); if (state.touched) onCommit(); }
    };

    el.addEventListener('input', onInput, true);
    el.addEventListener('change', stop, true);
    el.addEventListener('paste', onPaste);
    el.addEventListener('focus', onFocus);
    el.addEventListener('blur', finish);
    el.addEventListener('keydown', onKeydown);

    const setFromExternal = (value) => {
        const blank = value === null || value === undefined || String(value).trim() === '';
        const cents = blank ? 0 : parseToCents(value);
        state.cents = allowNegative ? cents : Math.abs(cents);
        state.empty = blank || state.cents === 0;
        if (document.activeElement === el) return;
        showFull();
    };

    setFromExternal(el.value);

    return {
        cleanup() {
            el.removeEventListener('input', onInput, true);
            el.removeEventListener('change', stop, true);
            el.removeEventListener('paste', onPaste);
            el.removeEventListener('focus', onFocus);
            el.removeEventListener('blur', finish);
            el.removeEventListener('keydown', onKeydown);
        },
        read: () => { if (document.activeElement === el) readText(); },
        getCents: () => state.cents,
        isEmpty: () => state.empty,
        isTouched: () => state.touched,
        setFromExternal,
    };
}
