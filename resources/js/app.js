import './bootstrap';

// --- Helpers de moeda BRL ---
function formatBRL(value) {
    const num = parseFloat(value);
    if (isNaN(num)) return '';
    return new Intl.NumberFormat('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(num);
}

function parseBRL(value) {
    if (value === null || value === undefined || value === '') return 0;
    const s = String(value);
    if (s.includes(',')) return parseFloat(s.replace(/\./g, '').replace(',', '.')) || 0;
    return parseFloat(s) || 0;
}

// --- Diretiva Alpine x-cpf-cnpj ---
function maskCpfCnpj(val) {
    const d = val.replace(/\D/g, '').slice(0, 14);
    if (d.length <= 11) {
        return d
            .replace(/(\d{3})(\d)/, '$1.$2')
            .replace(/(\d{3})(\d)/, '$1.$2')
            .replace(/(\d{3})(\d{1,2})$/, '$1-$2');
    }
    return d
        .replace(/(\d{2})(\d)/, '$1.$2')
        .replace(/(\d{3})(\d)/, '$1.$2')
        .replace(/(\d{3})(\d)/, '$1/$2')
        .replace(/(\d{4})(\d{1,2})$/, '$1-$2');
}

// --- Diretiva Alpine x-phone ---
function maskPhone(val) {
    const d = val.replace(/\D/g, '').slice(0, 11);
    if (d.length <= 10) {
        return d
            .replace(/(\d{2})(\d)/, '($1) $2')
            .replace(/(\d{4})(\d{1,4})$/, '$1-$2');
    }
    return d
        .replace(/(\d{2})(\d)/, '($1) $2')
        .replace(/(\d{5})(\d{1,4})$/, '$1-$2');
}

// --- Diretiva Alpine x-process-number ---
function maskProcessNumber(val) {
    const d = val.replace(/\D/g, '').slice(0, 20);

    return d
        .replace(/^(\d{7})(\d)/, '$1-$2')
        .replace(/^(\d{7}-\d{2})(\d)/, '$1.$2')
        .replace(/^(\d{7}-\d{2}\.\d{4})(\d)/, '$1.$2')
        .replace(/^(\d{7}-\d{2}\.\d{4}\.\d)(\d)/, '$1.$2')
        .replace(/^(\d{7}-\d{2}\.\d{4}\.\d\.\d{2})(\d)/, '$1.$2');
}

document.addEventListener('alpine:init', () => {
    Alpine.directive('cpf-cnpj', (el, {}, { cleanup }) => {
        if (el.value) el.value = maskCpfCnpj(el.value);
        const onInput = () => { el.value = maskCpfCnpj(el.value); };
        el.addEventListener('input', onInput);
        cleanup(() => el.removeEventListener('input', onInput));
    });

    Alpine.directive('phone', (el, {}, { cleanup }) => {
        if (el.value) el.value = maskPhone(el.value);
        const onInput = () => { el.value = maskPhone(el.value); };
        el.addEventListener('input', onInput);
        cleanup(() => el.removeEventListener('input', onInput));
    });

    Alpine.directive('process-number', (el, {}, { cleanup }) => {
        el.maxLength = 25;
        el.inputMode = 'numeric';
        el.placeholder = '0000000-00.0000.0.00.0000';

        if (el.value) el.value = maskProcessNumber(el.value);

        const onInput = () => { el.value = maskProcessNumber(el.value); };
        el.addEventListener('input', onInput);
        cleanup(() => el.removeEventListener('input', onInput));
    });

    // --- x-money: máscara de real em tempo real ---
    Alpine.directive('money', (el, {}, { cleanup }) => {
        el.type = 'text';
        el.autocomplete = 'off';
        el.placeholder = '0,00';

        // Formata valor inicial do servidor ("1234.56" → "1.234,56", "0" → "0,00")
        if (el.value !== '') {
            const n = parseFloat(el.value);
            if (!isNaN(n)) el.value = formatBRL(n);
        } else {
            el.value = '0,00';
        }

        let submitting = false;

        const rawValue = () => {
            const digits = el.value.replace(/\D/g, '');
            return String(digits ? parseInt(digits, 10) / 100 : 0);
        };

        const onInput = () => {
            if (submitting) return;

            const num = parseFloat(rawValue());
            el.dataset.moneyRaw = String(num);
            el.value = formatBRL(num);
            el.setSelectionRange(el.value.length, el.value.length);
        };

        // Antes do submit: garante valor bruto para wire:model diferido
        const form = el.closest('form');
        const onSubmit = () => {
            submitting = true;
            el.value = rawValue();
            el.dispatchEvent(new Event('input', { bubbles: true }));
            submitting = false;
        };

        el.addEventListener('input', onInput);
        if (form) form.addEventListener('submit', onSubmit, { capture: true });

        cleanup(() => {
            el.removeEventListener('input', onInput);
            if (form) form.removeEventListener('submit', onSubmit, { capture: true });
        });
    });
});

// Após re-renders do Livewire: reformata valores que voltaram como float bruto (ex: "0.01").
// Não toca no campo em edição para preservar o cursor e evitar travar a digitação rápida.
document.addEventListener('livewire:initialized', () => {
    Livewire.hook('commit', ({ succeed }) => {
        succeed(() => {
            requestAnimationFrame(() => {
                document.querySelectorAll('[x-money]').forEach(el => {
                    if (document.activeElement === el) return;

                    // Já formatado (tem vírgula)
                    if (el.value && el.value.includes(',')) return;
                    const num = parseFloat(el.value);
                    if (!isNaN(num)) el.value = formatBRL(num);
                });
            });
        });
    });
});
