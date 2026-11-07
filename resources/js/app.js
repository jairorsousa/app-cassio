import './bootstrap';
import { bindMoneyInput, centsToModel, parseToCents } from './money-mask';
import { bindDecimalInput } from './decimal-mask';
import { registerSessionExpiredHandler } from './session-expired';

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
    // Texto livre (com letras etc.) não é mexido; só números e separadores viram máscara CNJ.
    if (!/^[\d\s.\-\/\u2010-\u2015]*$/.test(val)) return val;
    const d = val.replace(/\D/g, '').slice(0, 20);

    return d
        .replace(/^(\d{7})(\d)/, '$1-$2')
        .replace(/^(\d{7}-\d{2})(\d)/, '$1.$2')
        .replace(/^(\d{7}-\d{2}\.\d{4})(\d)/, '$1.$2')
        .replace(/^(\d{7}-\d{2}\.\d{4}\.\d)(\d)/, '$1.$2')
        .replace(/^(\d{7}-\d{2}\.\d{4}\.\d\.\d{2})(\d)/, '$1.$2');
}

function wireModelBinding(el) {
    for (const attribute of el.attributes) {
        if (attribute.name === 'wire:model' || attribute.name.startsWith('wire:model.')) {
            const debounce = attribute.name.match(/debounce\.(\d+)ms/);

            return {
                expression: attribute.value,
                live: attribute.name.includes('.live'),
                debounce: debounce ? Number(debounce[1]) : (attribute.name.includes('.live') ? 150 : null),
            };
        }
    }

    return null;
}

function componentWire(el) {
    const root = el.closest('[wire\\:id]');
    if (!root || !window.Livewire) {
        return null;
    }

    return window.Livewire.find(root.getAttribute('wire:id'));
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
        if (!el.maxLength || el.maxLength < 0) el.maxLength = 120;
        el.placeholder = '0000000-00.0000.0.00.0000';

        if (el.value) el.value = maskProcessNumber(el.value);

        // Captura: formata antes do wire:model ler o valor (digitação e colagem).
        const onInput = () => {
            const masked = maskProcessNumber(el.value);
            if (masked !== el.value) el.value = masked;
        };
        el.addEventListener('input', onInput, true);
        cleanup(() => el.removeEventListener('input', onInput, true));
    });

    // x-money: o que aparece é 1.234,56. O Livewire recebe 1234.56 (vazio = '').
    // x-money.signed aceita valor negativo (ex.: saldo inicial).
    // O valor digitado não volta cru para o campo enquanto a pessoa digita.
    Alpine.directive('money', (el, { modifiers }, { cleanup }) => {
        const allowNegative = modifiers.includes('signed');
        let mask;
        let disposed = false;

        // O Livewire só recebe o valor no blur, no Enter ou no submit (nunca a cada tecla).
        const syncWire = () => {
            const binding = wireModelBinding(el);
            const wire = binding ? componentWire(el) : null;
            if (!binding || !wire || binding.expression === '' || !mask || !mask.isTouched()) return;
            mask.read();
            const modelValue = mask.isEmpty() ? '' : centsToModel(mask.getCents());
            if (String(wire.get(binding.expression) ?? '') !== modelValue) {
                wire.set(binding.expression, modelValue, binding.live);
            }
        };

        mask = bindMoneyInput(el, { onCommit: syncWire, allowNegative });
        el._moneyMask = mask;

        const wrapModel = () => {
            if (disposed || !el._x_forceModelUpdate || el._moneyWrapped) return;
            el._moneyOriginalForce = el._x_forceModelUpdate;
            el._moneyWrapped = true;
            el._x_forceModelUpdate = (value) => mask.setFromExternal(value);
        };
        wrapModel();
        queueMicrotask(() => {
            if (disposed) return;
            wrapModel();
            if (document.activeElement === el) return;
            mask.setFromExternal((el._x_model ? el._x_model.get() : el.value) ?? '');
        });

        const form = el.closest('form');
        const onSubmit = () => {
            syncWire();
            if (!wireModelBinding(el)) {
                mask.read();
                el.value = mask.isEmpty() ? '' : centsToModel(mask.getCents());
            }
        };
        if (form) form.addEventListener('submit', onSubmit, { capture: true });

        // Botões de salvar fora do <form> (wire:click): manda o valor antes do clique.
        const onDocPointer = (event) => {
            if (document.activeElement === el && event.target !== el && mask.isTouched()) syncWire();
        };
        document.addEventListener('pointerdown', onDocPointer, true);

        cleanup(() => {
            disposed = true;
            mask.cleanup();
            if (form) form.removeEventListener('submit', onSubmit, { capture: true });
            document.removeEventListener('pointerdown', onDocPointer, true);
            if (el._moneyOriginalForce) el._x_forceModelUpdate = el._moneyOriginalForce;
            el._moneyWrapped = false;
            delete el._moneyMask;
        });
    });

    // x-decimal="N": percentuais e quantidades com vírgula (N casas). O Livewire recebe "12.5".
    Alpine.directive('decimal', (el, { expression, modifiers }, { cleanup }) => {
        const decimals = Math.max(0, Math.min(8, Number.parseInt(expression, 10) || 2));
        // x-decimal.pad="4": sempre mostra todas as casas (ex.: preço R$ 12,3456).
        const pad = modifiers.includes('pad') ? decimals : (decimals <= 3 ? Math.min(decimals, 2) : 0);
        let commitTimer = null;
        let mask;
        let disposed = false;

        const syncWire = () => {
            const binding = wireModelBinding(el);
            const wire = binding ? componentWire(el) : null;
            if (!binding || !wire || binding.expression === '' || !mask || !mask.isTouched()) {
                return;
            }
            const modelValue = mask.getModel();
            if (String(wire.get(binding.expression) ?? '') !== modelValue) {
                wire.set(binding.expression, modelValue, false);
            }
            if (binding.live) {
                clearTimeout(commitTimer);
                commitTimer = setTimeout(() => wire.$commit(), binding.debounce);
            }
        };

        mask = bindDecimalInput(el, { decimals, pad, onChange: syncWire });
        el._decimalMask = mask;

        const wrapModel = () => {
            if (disposed || !el._x_forceModelUpdate || el._decimalWrapped) return;
            el._decimalOriginalForce = el._x_forceModelUpdate;
            el._decimalWrapped = true;
            el._x_forceModelUpdate = (value) => {
                if (mask.isTouched() && document.activeElement === el && !mask.matches(value)) {
                    queueMicrotask(syncWire);
                    return;
                }
                mask.setFromExternal(value);
            };
        };
        wrapModel();
        queueMicrotask(() => {
            if (disposed) return;
            wrapModel();
            if (mask.isTouched() && document.activeElement === el) return;
            mask.setFromExternal((el._x_model ? el._x_model.get() : el.value) ?? '');
        });

        const form = el.closest('form');
        const onSubmit = () => {
            syncWire();
            if (!wireModelBinding(el)) el.value = mask.getModel();
        };
        if (form) form.addEventListener('submit', onSubmit, { capture: true });

        cleanup(() => {
            disposed = true;
            clearTimeout(commitTimer);
            mask.cleanup();
            if (form) form.removeEventListener('submit', onSubmit, { capture: true });
            if (el._decimalOriginalForce) el._x_forceModelUpdate = el._decimalOriginalForce;
            el._decimalWrapped = false;
            delete el._decimalMask;
        });
    });
});

// Se um re-render devolver o número cru em um campo sem foco, formata de novo.
document.addEventListener('livewire:initialized', () => {
    registerSessionExpiredHandler(Livewire);

    Livewire.hook('morph.updating', ({ el, skip }) => {
        if (el && el._moneyMask && document.activeElement === el) skip();
    });

    Livewire.hook('commit', ({ succeed }) => {
        succeed(() => {
            requestAnimationFrame(() => {
                document.querySelectorAll('[x-money]').forEach((el) => {
                    const mask = el._moneyMask;
                    if (!mask || (el.value && el.value.includes(','))) {
                        return;
                    }

                    if (document.activeElement === el) {
                        return;
                    }

                    mask.setFromExternal(el.value);
                });
            });
        });
    });
});
