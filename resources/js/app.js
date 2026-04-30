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

// --- Diretiva Alpine x-money ---
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

    Alpine.directive('money', (el, {}, { cleanup }) => {
        el.type = 'text';
        el.autocomplete = 'off';

        // Formata valor inicial vindo do servidor (ex: "1234.56" → "1.234,56")
        if (el.value !== '') {
            const n = parseFloat(el.value);
            if (!isNaN(n)) el.value = formatBRL(n);
        }

        let processing = false;

        // Máscara em tempo real enquanto o usuário digita
        const onInput = () => {
            if (processing) return;
            processing = true;

            const digits = el.value.replace(/\D/g, '');
            const num = digits ? parseInt(digits, 10) / 100 : 0;
            const raw = num ? String(num) : '';
            const formatted = num ? formatBRL(num) : '';

            // Expõe o valor numérico bruto para o Livewire ler (wire:model.live)
            el.value = raw;
            el.dispatchEvent(new Event('input', { bubbles: true }));

            // Restaura a exibição formatada
            el.value = formatted;
            el.setSelectionRange(formatted.length, formatted.length);

            processing = false;
        };

        // Antes do submit do form: garante valor bruto para wire:model diferido
        const form = el.closest('form');
        const onSubmit = () => {
            const digits = el.value.replace(/\D/g, '');
            const num = digits ? parseInt(digits, 10) / 100 : 0;
            el.value = num ? String(num) : '0';
        };

        el.addEventListener('input', onInput);
        if (form) form.addEventListener('submit', onSubmit, { capture: true });

        cleanup(() => {
            el.removeEventListener('input', onInput);
            if (form) form.removeEventListener('submit', onSubmit, { capture: true });
        });
    });
});

// Reformata inputs x-money após re-renders do Livewire
document.addEventListener('livewire:initialized', () => {
    Livewire.hook('commit', ({ succeed }) => {
        succeed(() => {
            requestAnimationFrame(() => {
                document.querySelectorAll('[x-money]').forEach(el => {
                    if (el === document.activeElement || el.value === '') return;
                    const num = parseFloat(el.value);
                    if (!isNaN(num)) el.value = formatBRL(num);
                });
            });
        });
    });
});
