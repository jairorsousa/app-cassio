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
            @if (session('success'))
                this.push('success', @js(session('success')));
            @endif
            @if (session('error'))
                this.push('error', @js(session('error')));
            @endif

            window.addEventListener('toast', e => this.push(e.detail?.type || 'success', e.detail?.message || ''));

            Livewire.on('toast', (data) => {
                const payload = Array.isArray(data) ? data[0] : data;
                this.push(payload?.type || 'success', payload?.message || '');
            });
        }
    }"
    class="fixed right-4 top-4 z-[60] flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-2"
>
    <template x-for="toast in toasts" :key="toast.id">
        <div
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-x-4"
            x-transition:enter-end="opacity-100 translate-x-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="rounded-xl border border-mono-100 bg-mono-white px-4 py-3 shadow-elevated"
        >
            <div class="flex items-start gap-3">
                <span class="material-icons-outlined mt-0.5 text-[20px]" :class="toast.type === 'error' ? 'text-error' : toast.type === 'info' ? 'text-info' : 'text-success'" x-text="toast.type === 'error' ? 'error' : toast.type === 'info' ? 'info' : 'check_circle'"></span>
                <div class="min-w-0 flex-1 text-sm text-mono-900" x-text="toast.message"></div>
                <button type="button" class="rounded-lg p-1 text-mono-400 transition-colors hover:bg-mono-100 hover:text-mono-600" @click="toasts = toasts.filter(t => t.id !== toast.id)">
                    <span class="material-icons-outlined text-[18px]">close</span>
                </button>
            </div>
        </div>
    </template>
</div>
