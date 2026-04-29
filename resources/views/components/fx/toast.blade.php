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
    class="fixed top-4 right-4 z-50 flex flex-col gap-xs w-80 max-w-[calc(100vw-2rem)]"
>
    <template x-for="t in toasts" :key="t.id">
        <div
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-x-4"
            x-transition:enter-end="opacity-100 translate-x-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fx-toast"
            :class="{
                'fx-toast--success': t.type === 'success',
                'fx-toast--error': t.type === 'error',
                'fx-toast--info': t.type === 'info',
            }"
        >
            <div class="flex items-start gap-xs">
                <span class="text-md leading-none" x-text="t.type === 'success' ? '✓' : t.type === 'error' ? '✕' : 'i'"></span>
                <div class="flex-1 text-sm" x-text="t.message"></div>
                <button
                    type="button"
                    class="text-mono-600 hover:text-mono-900 leading-none"
                    @click="toasts = toasts.filter(x => x.id !== t.id)"
                >×</button>
            </div>
        </div>
    </template>
</div>
