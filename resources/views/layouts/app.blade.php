<!DOCTYPE html>
@php($prefs = \App\Support\UserPreferences::for(auth()->user()))
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $prefs['theme'] === 'dark' ? 'dark' : 'light' }}" data-theme-pref="{{ $prefs['theme'] }}" data-text-size="{{ $prefs['text_size'] }}" data-density="{{ $prefs['density'] }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Vértice CM') }}</title>
        @include('layouts.brand-head')

        <style id="accent-vars">{!! \App\Support\UserPreferences::accentCss($prefs['accent']) !!}</style>
        <script>
            (function () {
                var d = document.documentElement, o = sessionStorage.getItem('jr-theme-session');
                if (o === 'dark' || o === 'light') { d.dataset.theme = o; }
                else if (d.dataset.themePref === 'auto') { d.dataset.theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'; }
                var accents = @json(collect(\App\Support\UserPreferences::ACCENTS)->map(fn ($a) => [$a[1], $a[2]]));
                window.previewAccent = function (k) {
                    var a = accents[k]; if (!a) return;
                    var css = ':root,[data-theme="light"]{--colors-primary-g100:' + a[0][0] + ';--colors-primary-g500:' + a[0][1] + ';--colors-primary-g600:' + a[0][2] + '}[data-theme="dark"]{--colors-primary-g100:' + a[1][0] + ';--colors-primary-g500:' + a[1][1] + ';--colors-primary-g600:' + a[1][2] + '}';
                    document.getElementById('accent-vars').textContent = css;
                };
            })();
        </script>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    @if (\App\Support\RolePolicy::isReadOnly(auth()->user()))
        <style>
            body.read-only button[type=submit], body.read-only input[type=submit],
            body.read-only [wire\:click^="save"], body.read-only [wire\:click^="delete"], body.read-only [wire\:click^="remove"],
            body.read-only [wire\:click^="destroy"], body.read-only [wire\:click^="store"], body.read-only [wire\:click^="create"],
            body.read-only [wire\:click^="confirm"], body.read-only [wire\:click^="import"], body.read-only [wire\:click^="pay"],
            body.read-only [wire\:click^="register"], body.read-only [wire\:click^="add"], body.read-only [wire\:click^="update"],
            body.read-only [wire\:click^="duplicate"], body.read-only [wire\:click^="archive"], body.read-only [wire\:click^="block"],
            body.read-only [wire\:click^="move"], body.read-only [wire\:click^="mark"], body.read-only [wire\:click^="settle"],
            body.read-only a[href*="/create"], body.read-only a[href*="novo="], body.read-only a[href$="/edit"] { display: none !important; }
        </style>
    @endif
    <body
        class="bg-mono-50 font-sans text-mono-900 antialiased {{ \App\Support\RolePolicy::isReadOnly(auth()->user()) ? 'read-only' : '' }}"
        :class="{ 'overflow-hidden': sidebarOpen }"
        x-data="appShell({
            newIncome: '{{ route('banking.transactions.index', ['novo' => 'income']) }}',
            newExpense: '{{ route('banking.transactions.index', ['novo' => 'expense']) }}',
            newTransfer: '{{ route('banking.transactions.index', ['novo' => 'transfer']) }}',
            newWrit: '{{ route('writs.create') }}',
            dashboard: '{{ route('dashboard') }}',
        })"
        x-init="init()"
    >
        @if (\App\Support\RolePolicy::isReadOnly(auth()->user()))
            <div class="bg-mono-900 px-4 py-1.5 text-center text-xs font-semibold text-mono-white" data-read-only-banner>Perfil Somente leitura: você pode consultar, mas não alterar.</div>
        @endif
        <x-jr.toast />

        <div class="min-h-screen">
            <div class="min-h-screen">
                @include('layouts.header', ['title' => $header ?? null])

                <main class="p-4 md:p-6">
                    {{ $slot }}
                </main>
            </div>
        </div>

        <script>
            window.appShell = (config) => ({
                theme: document.documentElement.dataset.theme || 'light',
                sidebarOpen: false,
                buffer: '',
                bufferTimer: null,
                bindings: {
                    nr: config.newIncome,
                    nd: config.newExpense,
                    nt: config.newTransfer,
                    nw: config.newWrit,
                    gd: config.dashboard,
                },
                init() {
                    this.applyTheme();
                    window.addEventListener('keydown', (event) => this.handleShortcut(event));
                },
                applyTheme() {
                    document.documentElement.dataset.theme = this.theme;
                },
                toggleTheme() {
                    // Troca rápida só nesta aba; a preferência fixa fica em Configurações.
                    this.theme = this.theme === 'dark' ? 'light' : 'dark';
                    sessionStorage.setItem('jr-theme-session', this.theme);
                    this.applyTheme();
                },
                handleShortcut(event) {
                    const tag = event.target?.tagName?.toLowerCase();
                    if (['input', 'textarea', 'select'].includes(tag) || event.target?.isContentEditable) return;
                    if (event.metaKey || event.ctrlKey || event.altKey) return;

                    const key = event.key.toLowerCase();
                    if (!/^[a-z]$/.test(key)) return;

                    this.buffer += key;
                    clearTimeout(this.bufferTimer);
                    this.bufferTimer = setTimeout(() => this.buffer = '', 800);

                    const target = this.bindings[this.buffer];
                    if (target) {
                        this.buffer = '';
                        window.location.href = target;
                    }
                },
            });
        </script>
    </body>
</html>
