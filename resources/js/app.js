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

    // --- x-money: máscara de real em tempo real ---
    Alpine.directive('money', (el, {}, { cleanup }) => {
        el.type = 'text';
        el.autocomplete = 'off';

        // Formata valor inicial do servidor ("1234.56" → "1.234,56", "0" → "")
        if (el.value !== '') {
            const n = parseFloat(el.value);
            if (!isNaN(n)) el.value = n > 0 ? formatBRL(n) : '';
        }

        let processing = false;

        const onInput = () => {
            if (processing) return;
            processing = true;

            // Extrai só dígitos e converte: 100 = R$ 1,00
            const digits = el.value.replace(/\D/g, '');
            const num = digits ? parseInt(digits, 10) / 100 : 0;

            // Expõe o valor numérico bruto para o Livewire ler (wire:model.live)
            // "0" em vez de "" para evitar TypeError no PHP com campos required
            el.value = String(num);
            el.dispatchEvent(new Event('input', { bubbles: true }));

            // Restaura exibição formatada com vírgula
            el.value = num > 0 ? formatBRL(num) : '';
            el.setSelectionRange(el.value.length, el.value.length);

            processing = false;
        };

        // Antes do submit: garante valor bruto para wire:model diferido
        const form = el.closest('form');
        const onSubmit = () => {
            const digits = el.value.replace(/\D/g, '');
            const num = digits ? parseInt(digits, 10) / 100 : 0;
            el.value = String(num);
        };

        el.addEventListener('input', onInput);
        if (form) form.addEventListener('submit', onSubmit, { capture: true });

        cleanup(() => {
            el.removeEventListener('input', onInput);
            if (form) form.removeEventListener('submit', onSubmit, { capture: true });
        });
    });
});

// Após re-renders do Livewire: reformata valores que voltaram como float bruto (ex: "0.01")
// Não verifica activeElement — o check da vírgula protege inputs em edição
document.addEventListener('livewire:initialized', () => {
    Livewire.hook('commit', ({ succeed }) => {
        succeed(() => {
            requestAnimationFrame(() => {
                document.querySelectorAll('[x-money]').forEach(el => {
                    // Já formatado (tem vírgula) ou vazio → não mexer
                    if (!el.value || el.value.includes(',')) return;
                    const num = parseFloat(el.value);
                    if (!isNaN(num)) el.value = num > 0 ? formatBRL(num) : '';
                });
            });
        });
    });
});
