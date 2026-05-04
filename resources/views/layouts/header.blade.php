<header class="sticky top-0 z-30 border-b border-mono-100 bg-mono-white px-4 md:px-6">
    <div class="flex h-16 min-w-0 items-center gap-4">
        <a href="{{ route('dashboard') }}" class="flex shrink-0 items-center gap-3">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary-500 text-sm font-bold text-white">CM</span>
            <span class="hidden text-sm font-bold text-mono-900 sm:block">Cassio Finance</span>
        </a>

        @include('layouts.sidebar')

    <div class="ml-auto flex shrink-0 items-center gap-2">
        <button
            type="button"
            class="flex h-10 w-10 items-center justify-center rounded-full text-mono-600 transition-colors hover:bg-mono-50"
            @click="toggleTheme()"
            aria-label="Alternar tema"
        >
            <span x-show="theme === 'light'" class="material-icons-outlined text-[22px]">dark_mode</span>
            <span x-show="theme === 'dark'" x-cloak class="material-icons-outlined text-[22px]">light_mode</span>
        </button>

        <div class="relative" x-data="{ open: false }" @click.outside="open = false">
            <button type="button" class="flex items-center gap-2 rounded-pill px-2 py-1.5 transition-colors hover:bg-mono-50" @click="open = !open">
                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-primary-100 text-sm font-bold text-primary-500">
                    {{ \Illuminate\Support\Str::substr(auth()->user()->name ?? 'U', 0, 1) }}
                </span>
                <span class="hidden max-w-[140px] truncate text-sm font-medium text-mono-900 sm:block">{{ auth()->user()->name ?? 'Conta' }}</span>
                <span class="material-icons-outlined text-[18px] text-mono-300">expand_more</span>
            </button>

            <div
                x-show="open"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
                x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                x-transition:leave="transition ease-in duration-100"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                class="absolute right-0 mt-2 w-56 rounded-xl border border-mono-100 bg-mono-white py-2 shadow-dropdown"
                style="display: none;"
            >
                <a href="{{ route('profile') }}" class="flex items-center gap-3 px-4 py-2.5 text-sm text-mono-900 transition-colors hover:bg-mono-50">
                    <span class="material-icons-outlined text-[18px] text-mono-400">settings</span>
                    Perfil
                </a>
                <div class="my-1.5 mx-3 border-t border-mono-100"></div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="flex w-full items-center gap-3 px-4 py-2.5 text-sm text-error transition-colors hover:bg-down-bg">
                        <span class="material-icons-outlined text-[18px]">logout</span>
                        Sair
                    </button>
                </form>
            </div>
        </div>
    </div>
    </div>
</header>
