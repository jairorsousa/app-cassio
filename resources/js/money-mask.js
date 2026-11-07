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

// Limpa o texto digitado: só números e uma vírgula, no máximo 2 casas, milhar com ponto.
// Devolve o texto formatado e quantos caracteres "úteis" (dígitos/vírgula) ficam antes do cursor.
export function sanitizeTyped(raw, { allowNegative = false } = {}) {
    const text = String(raw ?? '');
    const negative = allowNegative && text.trim().startsWith('-');
    let integers = '';
    let fraction = '';
    let hasComma = false;

    for (const ch of text) {
        if (/\d/.test(ch)) {
            if (hasComma) {
                if (fraction.length < 2) fraction += ch;
            } else if (integers.length < MAX_INTEGER_DIGITS) {
                integers += ch;
            }
        } else if (ch === ',' && !hasComma) {
            hasComma = true;
        }
    }

    integers = integers.replace(/^0+(?=\d)/, '');
    if (hasComma && integers === '') {
        integers = '0';
    }

    const display = (negative ? '-' : '') + groupThousands(integers) + (hasComma ? `,${fraction}` : '');
    const empty = integers === '' && !hasComma;

    return { display, empty, cents: empty ? 0 : (negative ? -1 : 1) * digitsToCents(integers, fraction) };
}

// Campo mostrando zero ("0,00", "0,0", "0"): o que a pessoa digitar substitui o zero.
// Ex.: "0,00" + "2" (em qualquer posição do cursor) → "2". Devolve null quando não se aplica.
export function typedOverZero(previous, next) {
    if (!/^-?0(,0{0,2})?$/.test(String(previous ?? '').trim()) || next === previous) {
        return null;
    }
    const prev = String(previous);
    const now = String(next ?? '');
    let p = 0;
    while (p < prev.length && p < now.length && prev[p] === now[p]) p += 1;
    let s = 0;
    while (s < prev.length - p && s < now.length - p && prev[prev.length - 1 - s] === now[now.length - 1 - s]) s += 1;
    const inserted = now.slice(p, now.length - s);
    if (!/^[\d]+$/.test(inserted)) {
        return null;
    }

    return inserted;
}

function significantBefore(text, position) {
    return (text.slice(0, position).match(/[\d,-]/g) || []).length;
}

function positionAfterSignificant(text, count) {
    if (count <= 0) return 0;
    let seen = 0;
    for (let i = 0; i < text.length; i += 1) {
        if (/[\d,-]/.test(text[i])) {
            seen += 1;
            if (seen === count) return i + 1;
        }
    }

    return text.length;
}

export function bindMoneyInput(el, { onChange = () => {}, allowNegative = false } = {}) {
    const state = { cents: 0, empty: true, touched: false, last: '' };

    el.type = 'text';
    el.inputMode = 'decimal';
    el.autocomplete = 'off';
    el.spellcheck = false;
    if (!el.getAttribute('placeholder') || /^0[.,]00$/.test(el.getAttribute('placeholder'))) {
        el.placeholder = '0,00';
    }
    el.setAttribute('inputmode', 'decimal');
    el.setAttribute('autocomplete', 'off');

    const setCaret = (position) => {
        if (document.activeElement !== el) return;
        try { el.setSelectionRange(position, position); } catch { /* sem seleção */ }
    };

    // Zero não é exibido: o campo fica vazio com o placeholder "0,00" e a digitação começa limpa (celular).
    const showFull = () => {
        el.value = state.empty || state.cents === 0 ? '' : formatCents(state.cents);
        state.last = el.value;
    };

    const applyTyped = () => {
        const over = typedOverZero(state.last, el.value);
        if (over !== null) {
            el.value = over;
            setCaret(el.value.length);
        }
        const caret = el.selectionStart ?? el.value.length;
        const keep = significantBefore(el.value, caret);
        const result = sanitizeTyped(el.value, { allowNegative });
        if (el.value !== result.display) {
            el.value = result.display;
            setCaret(positionAfterSignificant(result.display, keep));
        }
        state.cents = result.cents;
        state.empty = result.empty;
        state.touched = true;
        state.last = el.value;
        onChange(state.cents);
    };

    const replaceSelection = (text) => {
        const start = el.selectionStart ?? el.value.length;
        const end = el.selectionEnd ?? el.value.length;
        el.value = el.value.slice(0, start) + text + el.value.slice(end);
        setCaret(start + text.length);
    };

    const onBeforeInput = (event) => {
        if (event.inputType !== 'insertText' || event.data == null) return;
        const data = event.data;
        if (data === '.' || data === ',') {
            event.preventDefault();
            if (!el.value.includes(',')) {
                replaceSelection(',');
            }
            applyTyped();
        } else if (data === '-' && allowNegative) {
            event.preventDefault();
            const start = el.selectionStart ?? 0;
            const negative = el.value.startsWith('-');
            el.value = negative ? el.value.slice(1) : `-${el.value}`;
            setCaret(Math.max(0, start + (negative ? -1 : 1)));
            applyTyped();
        } else if (!/^\d+$/.test(data)) {
            event.preventDefault();
        }
    };

    const onInput = (event) => {
        // Capturado antes do wire:model para o Livewire nunca ler o texto cru.
        event.stopImmediatePropagation();
        applyTyped();
    };

    const onPaste = (event) => {
        event.preventDefault();
        const pasted = event.clipboardData?.getData('text') ?? '';
        if (!/\d/.test(pasted)) return;
        const cents = parseToCents(pasted);
        state.cents = allowNegative ? cents : Math.abs(cents);
        state.empty = false;
        state.touched = true;
        showFull();
        setCaret(el.value.length);
        onChange(state.cents);
    };

    // Em foco o texto fica editável: sem ",00" automático (1.234,00 → 1.234); centavos digitados ficam.
    const onFocus = () => {
        if (state.cents === 0 && el.value !== '' && !/[1-9]/.test(el.value)) {
            el.value = '';
        } else if (/,00$/.test(el.value)) {
            el.value = el.value.slice(0, -3);
        }
        state.last = el.value;
    };

    const onBlur = () => {
        showFull();
    };

    el.addEventListener('beforeinput', onBeforeInput);
    el.addEventListener('input', onInput, true);
    el.addEventListener('paste', onPaste);
    el.addEventListener('focus', onFocus);
    el.addEventListener('blur', onBlur);

    const setFromExternal = (value) => {
        const blank = value === null || value === undefined || String(value).trim() === '';
        const cents = blank ? 0 : parseToCents(value);
        state.cents = allowNegative ? cents : Math.abs(cents);
        state.empty = blank || state.cents === 0;
        showFull();
    };

    setFromExternal(el.value);

    return {
        cleanup() {
            el.removeEventListener('beforeinput', onBeforeInput);
            el.removeEventListener('input', onInput, true);
            el.removeEventListener('paste', onPaste);
            el.removeEventListener('focus', onFocus);
            el.removeEventListener('blur', onBlur);
        },
        getCents: () => state.cents,
        isEmpty: () => state.empty,
        isTouched: () => state.touched,
        setFromExternal,
    };
}
