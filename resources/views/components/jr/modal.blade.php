@props([
    'name' => '',
    'maxWidth' => 'lg',
    'title' => '',
])

@php
    $maxWidthClass = [
        'sm' => 'sm:max-w-sm',
        'md' => 'sm:max-w-md',
        'lg' => 'sm:max-w-lg',
        'xl' => 'sm:max-w-xl',
        '2xl' => 'sm:max-w-2xl',
    ][$maxWidth] ?? 'sm:max-w-lg';
@endphp

<div
    x-data="{ show: false }"
    x-on:open-modal.window="$event.detail === '{{ $name }}' ? show = true : null"
    x-on:close-modal.window="$event.detail === '{{ $name }}' ? show = false : null"
    x-on:keydown.escape.window="show = false"
    x-show="show"
    class="fixed inset-0 z-modal overflow-y-auto"
    style="display: none;"
>
    <div class="fixed inset-0 bg-black/40" x-on:click="show = false"></div>

    <div class="flex min-h-screen items-center justify-center p-4">
        <div
            x-show="show"
            x-transition:enter="ease-out duration-300"
            x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
            x-transition:leave="ease-in duration-200"
            x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
            x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            class="relative w-full {{ $maxWidthClass }} overflow-hidden rounded-2xl border border-mono-100 bg-mono-white shadow-elevated"
        >
            @if ($title)
                <div class="flex items-center justify-between border-b border-mono-100 px-6 py-4">
                    <h3 class="text-lg font-bold text-mono-900">{{ $title }}</h3>
                    <button type="button" class="flex h-8 w-8 items-center justify-center rounded-lg text-mono-400 transition-colors hover:bg-mono-100 hover:text-mono-600" x-on:click="show = false">
                        <span class="material-icons-outlined text-[20px]">close</span>
                    </button>
                </div>
            @endif

            <div class="px-6 py-5">
                {{ $slot }}
            </div>

            @isset($footer)
                <div class="flex items-center justify-end gap-3 border-t border-mono-100 bg-mono-50 px-6 py-4">
                    {{ $footer }}
                </div>
            @endisset
        </div>
    </div>
</div>
