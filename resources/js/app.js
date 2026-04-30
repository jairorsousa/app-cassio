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
    // "1.234,56" → 1234.56
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
        if (el.value !== '') el.value = formatBRL(el.value);

        // No foco: exibe número puro para o wire:model ler corretamente
        const onFocus = () => {
            const num = parseBRL(el.value);
            el.value = num ? String(num) : '';
        };

        // No blur: aguarda Livewire ler o valor bruto, depois formata
        const onBlur = () => {
            setTimeout(() => {
                const num = parseFloat(el.value) || parseBRL(el.value);
                el.value = num ? formatBRL(num) : '';
            }, 0);
        };

        el.addEventListener('focus', onFocus);
        el.addEventListener('blur', onBlur);

        cleanup(() => {
            el.removeEventListener('focus', onFocus);
            el.removeEventListener('blur', onBlur);
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
