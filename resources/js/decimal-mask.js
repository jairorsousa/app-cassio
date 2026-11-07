// Máscara leve para percentuais e quantidades no padrão brasileiro: 12,5 · 1.234,567890.
// A vírgula (ou o ponto do teclado) marca as casas decimais; o Livewire recebe "1234.56789".

function groupThousands(integers) {
    return integers.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

/** Texto colado/externo → valor do modelo ("1234.5"), ou '' se vazio. Vírgula = decimal (padrão BR). */
export function parseDecimal(value, decimals = 2) {
    if (value === null || value === undefined) return '';
    let text = String(value).trim().replace(/\s|\u00a0/g, '').replace(/%/g, '');
    if (!/\d/.test(text)) return '';
    const negative = text.startsWith('-');
    text = text.replace(/[^\d.,]/g, '');
    let integers = text;
    let fraction = '';
    const lastComma = text.lastIndexOf(',');
    const lastDot = text.lastIndexOf('.');
    if (lastComma !== -1 && lastDot !== -1) {
        const at = Math.max(lastComma, lastDot);
        integers = text.slice(0, at);
        fraction = text.slice(at + 1);
    } else if (lastComma !== -1) {
        const parts = text.split(',');
        integers = parts.length > 2 ? parts.join('') : parts[0];
        fraction = parts.length > 2 ? '' : parts[1];
    } else if (lastDot !== -1) {
        const parts = text.split('.');
        integers = parts.length > 2 ? parts.join('') : parts[0];
        fraction = parts.length > 2 ? '' : parts[1];
    }
    integers = integers.replace(/\D/g, '').replace(/^0+(?=\d)/, '') || '0';
    fraction = fraction.replace(/\D/g, '');
    if (fraction.length > decimals) {
        const scale = 10n ** BigInt(decimals);
        let scaled = BigInt(integers) * scale + BigInt(fraction.slice(0, decimals) || '0');
        if (fraction[decimals] >= '5') scaled += 1n;
        integers = (scaled / scale).toString();
        fraction = (scaled % scale).toString().padStart(decimals, '0');
    }
    fraction = fraction.replace(/0+$/, '');
    const out = fraction ? `${integers}.${fraction}` : integers;

    return negative && out !== '0' ? `-${out}` : out;
}

/** Valor do modelo → texto exibido (1.234,50), completando até `pad` casas. */
export function formatDecimal(model, decimals = 2, pad = 0) {
    const value = parseDecimal(model, decimals);
    if (value === '') return '';
    const negative = value.startsWith('-');
    const [integers, fraction = ''] = value.replace('-', '').split('.');
    const frac = fraction.padEnd(Math.min(pad, decimals), '0');

    return (negative ? '-' : '') + groupThousands(integers) + (frac ? `,${frac}` : '');
}

/** Limpa o que está sendo digitado (só números e uma vírgula, até `decimals` casas). */
export function sanitizeDecimalTyped(raw, decimals = 2) {
    let integers = '';
    let fraction = '';
    let hasComma = false;
    for (const ch of String(raw ?? '')) {
        if (/\d/.test(ch)) {
            if (hasComma) { if (fraction.length < decimals) fraction += ch; } else if (integers.length < 15) integers += ch;
        } else if (ch === ',' && !hasComma && decimals > 0) {
            hasComma = true;
        }
    }
    integers = integers.replace(/^0+(?=\d)/, '');
    if (hasComma && integers === '') integers = '0';
    const display = groupThousands(integers) + (hasComma ? `,${fraction}` : '');
    const empty = integers === '' && !hasComma;
    const model = empty ? '' : parseDecimal(`${integers},${fraction}`, decimals);

    return { display, empty, model };
}

function significantBefore(text, position) {
    return (text.slice(0, position).match(/[\d,]/g) || []).length;
}

function positionAfterSignificant(text, count) {
    if (count <= 0) return 0;
    let seen = 0;
    for (let i = 0; i < text.length; i += 1) {
        if (/[\d,]/.test(text[i]) && (seen += 1) === count) return i + 1;
    }

    return text.length;
}

export function bindDecimalInput(el, { decimals = 2, pad = 0, onChange = () => {} } = {}) {
    const state = { model: '', touched: false };
    el.type = 'text';
    el.setAttribute('inputmode', 'decimal');
    el.setAttribute('autocomplete', 'off');
    el.removeAttribute('step');
    if (!el.getAttribute('placeholder')) {
        el.placeholder = decimals > 0 ? `0,${'0'.repeat(Math.min(decimals, Math.max(pad, 2)))}` : '0';
    }

    const setCaret = (p) => { if (document.activeElement === el) { try { el.setSelectionRange(p, p); } catch { /* */ } } };

    const applyTyped = () => {
        const keep = significantBefore(el.value, el.selectionStart ?? el.value.length);
        const r = sanitizeDecimalTyped(el.value, decimals);
        if (el.value !== r.display) {
            el.value = r.display;
            setCaret(positionAfterSignificant(r.display, keep));
        }
        state.model = r.model;
        state.touched = true;
        onChange(state.model);
    };

    const onBeforeInput = (e) => {
        if (e.inputType !== 'insertText' || e.data == null) return;
        if (e.data === '.' || e.data === ',') {
            e.preventDefault();
            if (decimals > 0 && !el.value.includes(',')) {
                const s = el.selectionStart ?? el.value.length;
                const en = el.selectionEnd ?? el.value.length;
                el.value = `${el.value.slice(0, s)},${el.value.slice(en)}`;
                setCaret(s + 1);
            }
            applyTyped();
        } else if (!/^\d+$/.test(e.data)) {
            e.preventDefault();
        }
    };
    const onInput = (e) => { e.stopImmediatePropagation(); applyTyped(); };
    const onPaste = (e) => {
        e.preventDefault();
        const text = e.clipboardData?.getData('text') ?? '';
        if (!/\d/.test(text)) return;
        state.model = parseDecimal(text, decimals).replace(/^-/, '');
        state.touched = true;
        el.value = formatDecimal(state.model, decimals, pad);
        setCaret(el.value.length);
        onChange(state.model);
    };
    const onBlur = () => { el.value = formatDecimal(state.model, decimals, pad); };

    el.addEventListener('beforeinput', onBeforeInput);
    el.addEventListener('input', onInput, true);
    el.addEventListener('paste', onPaste);
    el.addEventListener('blur', onBlur);

    const setFromExternal = (value) => {
        state.model = parseDecimal(value, decimals).replace(/^-/, '');
        el.value = formatDecimal(state.model, decimals, pad);
    };
    setFromExternal(el.value);

    return {
        cleanup() {
            el.removeEventListener('beforeinput', onBeforeInput);
            el.removeEventListener('input', onInput, true);
            el.removeEventListener('paste', onPaste);
            el.removeEventListener('blur', onBlur);
        },
        getModel: () => state.model,
        isTouched: () => state.touched,
        matches: (value) => parseDecimal(value, decimals).replace(/^-/, '') === state.model,
        setFromExternal,
    };
}
