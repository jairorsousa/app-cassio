import './bootstrap';
import { bindMoneyInput, centsToModel, parseToCents } from './money-mask';

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
        el.maxLength = 25;
        el.inputMode = 'numeric';
        el.placeholder = '0000000-00.0000.0.00.0000';

        if (el.value) el.value = maskProcessNumber(el.value);

        const onInput = () => { el.value = maskProcessNumber(el.value); };
        el.addEventListener('input', onInput);
        cleanup(() => el.removeEventListener('input', onInput));
    });

    // x-money: o que aparece é 1.234,56. O Livewire recebe 1234.56.
    // O valor digitado não volta cru para o campo enquanto a pessoa digita.
    Alpine.directive('money', (el, {}, { cleanup }) => {
        let commitTimer = null;
        let mask;

        const syncWire = () => {
            const binding = wireModelBinding(el);
            const wire = binding ? componentWire(el) : null;
            if (!binding || !wire || binding.expression === '' || !mask) {
                return;
            }

            if (!mask.isTouched()) {
                return;
            }

            const modelValue = centsToModel(mask.getCents());

            const current = wire.get(binding.expression);
            if (String(current ?? '') !== modelValue) {
                wire.set(binding.expression, modelValue, false);
            }

            if (binding.live) {
                clearTimeout(commitTimer);
                commitTimer = setTimeout(() => wire.$commit(), binding.debounce);
            }
        };

        mask = bindMoneyInput(el, { onChange: syncWire });
        el._moneyMask = mask;
        let disposed = false;

        const applyExternal = (value) => {
            const incoming = Math.abs(parseToCents(value));
            if (mask.isTouched() && document.activeElement === el && incoming !== mask.getCents()) {
                queueMicrotask(syncWire);
                return;
            }

            mask.setFromExternal(value);
        };

        const wrapModel = () => {
            if (disposed || !el._x_forceModelUpdate || el._moneyWrapped) {
                return;
            }

            el._moneyOriginalForce = el._x_forceModelUpdate;
            el._moneyWrapped = true;
            el._x_forceModelUpdate = (value) => {
                applyExternal(value);
            };
        };

        wrapModel();
        queueMicrotask(() => {
            if (disposed) {
                return;
            }

            wrapModel();
            if (mask.isTouched() && document.activeElement === el) {
                return;
            }

            const source = el._x_model ? el._x_model.get() : el.value;
            mask.setFromExternal(source ?? '');
        });

        const form = el.closest('form');
        const onSubmit = () => {
            syncWire();
            if (!wireModelBinding(el)) {
                el.value = mask.getCents() === 0 ? '' : centsToModel(mask.getCents());
            }
        };

        if (form) {
            form.addEventListener('submit', onSubmit, { capture: true });
        }

        cleanup(() => {
            disposed = true;
            clearTimeout(commitTimer);
            mask.cleanup();
            if (form) {
                form.removeEventListener('submit', onSubmit, { capture: true });
            }
            if (el._moneyOriginalForce) {
                el._x_forceModelUpdate = el._moneyOriginalForce;
            }
            el._moneyWrapped = false;
            delete el._moneyMask;
        });
    });
});

// Se um re-render devolver o número cru em um campo sem foco, formata de novo.
document.addEventListener('livewire:initialized', () => {
    Livewire.hook('commit', ({ succeed }) => {
        succeed(() => {
            requestAnimationFrame(() => {
                document.querySelectorAll('[x-money]').forEach((el) => {
                    const mask = el._moneyMask;
                    if (!mask || (el.value && el.value.includes(','))) {
                        return;
                    }

                    if (document.activeElement === el && mask.isTouched()) {
                        return;
                    }

                    mask.setFromExternal(el.value);
                });
            });
        });
    });
});
