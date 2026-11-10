// Máscara de reais no padrão brasileiro "R$ 1.234,56" (estilo caixa eletrônico).
// Ao digitar, os dígitos entram pelos centavos: 1, 0, 0, 0, 0, 0 → R$ 1.000,00.
// Backspace tira o último dígito. Colar "1.000,00", "1000", "1000.5" ou "R$ 1.000,00" entende o valor.
// O Livewire recebe sempre o número com ponto: "1000.00" (vazio = '').

const MAX_DIGITS = 15; // até R$ 9.999.999.999.999,99
const PREFIX = 'R$ ';

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

/** Texto mostrado no campo: "R$ 1.234,56" / "-R$ 10,00" / '' (vazio). */
export function displayCents(cents, { empty = false, negative = false } = {}) {
    if (empty) return '';
    const neg = negative || cents < 0;

    return `${neg ? '-' : ''}${PREFIX}${formatCents(Math.abs(cents))}`;
}

export function centsToModel(cents) {
    const amount = Math.abs(Number.isFinite(cents) ? Math.round(cents) : 0);
    const decimals = String(amount % 100).padStart(2, '0');
    const integers = String(Math.floor(amount / 100));
    const sign = cents < 0 ? '-' : '';

    return `${sign}${integers}.${decimals}`;
}

// Entende os formatos comuns: 1234,56 · 1.234,56 · R$ 1.234,56 · 1234.56 · 1,234.56 · 1234 · 1000.5
export function parseToCents(value) {
    if (value === null || value === undefined) {
        return 0;
    }
    if (typeof value === 'number') {
        return Number.isFinite(value) ? Math.round(value * 100) : 0;
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
    const whole = String(integers ?? '').replace(/\D/g, '').replace(/^0+(?=\d)/, '').slice(-(MAX_DIGITS - 2)) || '0';
    let frac = String(fraction ?? '').replace(/\D/g, '');
    let roundUp = false;

    if (frac.length > 2) {
        roundUp = frac[2] >= '5';
        frac = frac.slice(0, 2);
    }
    frac = frac.padEnd(2, '0');

    return Number(BigInt(whole) * 100n + BigInt(frac) + (roundUp ? 1n : 0n));
}

function countDigits(text) {
    return (String(text).match(/\d/g) || []).length;
}

/** Posição no texto formatado que deixa `digitsAfter` dígitos à direita do cursor (logo após o dígito anterior). */
function caretForDigitsAfter(display, digitsAfter) {
    const total = countDigits(display);
    const before = total - Math.max(0, digitsAfter);
    if (before >= total) return display.length;
    const prefixEnd = display.length - display.replace(/^-?R\$\s?/, '').length;
    if (before <= 0) return prefixEnd;
    let seen = 0;
    for (let i = 0; i < display.length; i += 1) {
        if (/\d/.test(display[i])) {
            seen += 1;
            if (seen === before) return i + 1;
        }
    }

    return display.length;
}

/**
 * Reinterpreta o texto do campo depois de uma tecla (função pura, testada em tests/js).
 * prev: { cents, empty, negative, display } · raw: texto após a tecla · caret: cursor após a tecla.
 */
export function applyTyped(prev, raw, caret, { inputType = '', allowNegative = false } = {}) {
    const text = String(raw ?? '');
    const at = Math.max(0, Math.min(caret ?? text.length, text.length));
    let digits = text.replace(/\D/g, '');
    let digitsBefore = countDigits(text.slice(0, at));
    const prevDigits = prev.empty ? '' : String(Math.abs(prev.cents));

    // Apagou só um separador (ponto, vírgula, "R$"): apaga o dígito vizinho.
    if (digits === prevDigits.padStart(digits.length, '0') && text.length < (prev.display || '').length) {
        if (/deleteContentBackward|deleteWordBackward/.test(inputType) && digitsBefore > 0) {
            digits = digits.slice(0, digitsBefore - 1) + digits.slice(digitsBefore);
            digitsBefore -= 1;
        } else if (/deleteContentForward|deleteWordForward/.test(inputType) && digitsBefore < digits.length) {
            digits = digits.slice(0, digitsBefore) + digits.slice(digitsBefore + 1);
        }
    }

    let digitsAfter = digits.length - digitsBefore;
    const stripped = digits.replace(/^0+/, '');
    digitsAfter = Math.min(digitsAfter, stripped.length);

    if (stripped.length > MAX_DIGITS) {
        return { ...prev, caret: caretForDigitsAfter(prev.display || '', 0) };
    }

    let negative = prev.negative;
    if (allowNegative) {
        const minus = (text.match(/-/g) || []).length;
        if (minus !== (prev.negative ? 1 : 0)) negative = !prev.negative;
        if (/\+/.test(text)) negative = false;
    } else {
        negative = false;
    }

    const empty = stripped === '';
    const cents = empty ? 0 : Number(stripped) * (negative ? -1 : 1);
    if (empty) negative = allowNegative && negative && /-/.test(text) ? true : false;
    const display = empty ? (negative ? '-' : '') : displayCents(cents, { negative });

    return { cents, empty, negative, display, caret: empty ? display.length : caretForDigitsAfter(display, digitsAfter) };
}

export function bindMoneyInput(el, { onCommit = () => {}, onType = () => {}, allowNegative = false } = {}) {
    const state = { cents: 0, empty: true, negative: false, display: '', touched: false };

    el.type = 'text';
    // Teclado numérico no celular; com sinal, o teclado decimal (tem "-" no Android).
    el.setAttribute('inputmode', allowNegative ? 'decimal' : 'numeric');
    el.setAttribute('autocomplete', 'off');
    el.spellcheck = false;
    if (!el.getAttribute('placeholder') || /^(R\$\s?)?0[.,]00$/.test(el.getAttribute('placeholder'))) {
        el.placeholder = 'R$ 0,00';
    }

    const render = (caret = null) => {
        el.value = state.display;
        if (caret !== null && document.activeElement === el) {
            try { el.setSelectionRange(caret, caret); } catch { /* sem seleção */ }
        }
    };

    const setCents = (cents, empty) => {
        state.empty = empty;
        state.cents = empty ? 0 : (allowNegative ? cents : Math.abs(cents));
        state.negative = !empty && state.cents < 0;
        state.display = displayCents(state.cents, { empty: state.empty, negative: state.negative });
    };

    let lastInputType = '';
    const onBeforeInput = (event) => { lastInputType = event.inputType || ''; };

    // Cada tecla (inclusive teclado do celular, que não manda keydown confiável): reinterpreta os dígitos.
    const onInput = (event) => {
        event.stopImmediatePropagation();
        const next = applyTyped(state, el.value, el.selectionStart ?? el.value.length, { inputType: event.inputType || lastInputType, allowNegative });
        Object.assign(state, { cents: next.cents, empty: next.empty, negative: next.negative, display: next.display });
        state.touched = true;
        render(next.caret);
        onType();
    };

    // Colar troca o valor inteiro, entendendo o formato colado.
    const onPaste = (event) => {
        event.preventDefault();
        const pasted = event.clipboardData?.getData('text') ?? '';
        if (!/\d/.test(pasted)) return;
        setCents(parseToCents(pasted), false);
        state.touched = true;
        render(state.display.length);
        onType();
    };

    const onDrop = (event) => event.preventDefault();
    const stop = (event) => event.stopImmediatePropagation();

    const finish = () => { if (state.touched) onCommit(); };
    const onKeydown = (event) => {
        if (event.key === 'Enter' && state.touched) onCommit();
    };

    el.addEventListener('beforeinput', onBeforeInput, true);
    el.addEventListener('input', onInput, true);
    el.addEventListener('change', stop, true);
    el.addEventListener('paste', onPaste);
    el.addEventListener('drop', onDrop);
    el.addEventListener('blur', finish);
    el.addEventListener('keydown', onKeydown);

    const setFromExternal = (value) => {
        if (document.activeElement === el && state.touched) return;
        const blank = value === null || value === undefined || String(value).trim() === '';
        setCents(blank ? 0 : parseToCents(value), blank);
        render();
    };

    setFromExternal(el.value);

    return {
        cleanup() {
            el.removeEventListener('beforeinput', onBeforeInput, true);
            el.removeEventListener('input', onInput, true);
            el.removeEventListener('change', stop, true);
            el.removeEventListener('paste', onPaste);
            el.removeEventListener('drop', onDrop);
            el.removeEventListener('blur', finish);
            el.removeEventListener('keydown', onKeydown);
        },
        read: () => {},
        getCents: () => state.cents,
        isEmpty: () => state.empty,
        isTouched: () => state.touched,
        model: () => (state.empty ? '' : centsToModel(state.cents)),
        matches: (value) => {
            const blank = value === null || value === undefined || String(value).trim() === '';
            return blank ? state.empty : (!state.empty && parseToCents(value) === state.cents);
        },
        setFromExternal,
    };
}
