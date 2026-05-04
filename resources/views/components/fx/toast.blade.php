{{-- Container global de toasts (success/error). Lê de session() flash. --}}
<div
    x-data="{
        toasts: [],
        push(type, message) {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, type, message });
            setTimeout(() => this.toasts = this.toasts.filter(t => t.id !== id), 5000);
        },
        init() {
            @if (session('status'))
                this.push('success', @js(session('status')));
            @endif
            @if (session('error'))
                this.push('error', @js(session('error')));
            @endif

            window.addEventListener('toast', e => {
                this.push(e.detail?.type || 'success', e.detail?.message || '');
            });

            Livewire.on('toast', (data) => {
                const payload = Array.isArray(data) ? data[0] : data;
                this.push(payload?.type || 'success', payload?.message || '');
            });
        }
    }"
    class="fixed top-4 right-4 z-50 flex flex-col gap-space-2 w-80 max-w-[calc(100vw-2rem)]"
>
    <template x-for="t in toasts" :key="t.id">
        <div
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-x-4"
            x-transition:enter-end="opacity-100 translate-x-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            :class="{
                'border-l-4 border-l-cryptex-green-500': t.type === 'success',
                'border-l-4 border-l-cryptex-red-500': t.type === 'error',
                'border-l-4 border-l-cryptex-blue-400': t.type === 'info',
            }"
            class="rounded-md px-space-4 py-space-3 shadow-lg bg-cryptex-bg-elevated border border-cryptex-border-subtle"
        >
            <div class="flex items-start gap-space-3 text-cryptex-text-primary">
                <span class="text-fs-16 leading-none mt-1" x-text="t.type === 'success' ? '✓' : t.type === 'error' ? '✕' : 'i'" :class="{
                    'text-cryptex-green-500': t.type === 'success',
                    'text-cryptex-red-500': t.type === 'error',
                    'text-cryptex-blue-400': t.type === 'info',
                }"></span>
                <div class="flex-1 text-fs-14" x-text="t.message"></div>
                <button
                    type="button"
                    class="text-cryptex-text-secondary hover:text-cryptex-text-primary leading-none"
                    @click="toasts = toasts.filter(x => x.id !== t.id)"
                >×</button>
            </div>
        </div>
    </template>
</div>
