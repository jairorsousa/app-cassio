<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Cassio Finance') }}</title>

        <script>
            if (localStorage.getItem('jr-theme') === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        </script>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body
        class="bg-mono-50 font-sans text-mono-900 antialiased"
        x-data="appShell({
            newIncome: '{{ route('banking.transactions.create', ['type' => 'income']) }}',
            newExpense: '{{ route('banking.transactions.create', ['type' => 'expense']) }}',
            newTransfer: '{{ route('banking.transactions.create', ['type' => 'transfer']) }}',
            newWrit: '{{ route('writs.create') }}',
            dashboard: '{{ route('dashboard') }}',
        })"
        x-init="init()"
    >
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
                theme: localStorage.getItem('jr-theme') || 'light',
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
                    localStorage.setItem('jr-theme', this.theme);
                },
                toggleTheme() {
                    this.theme = this.theme === 'dark' ? 'light' : 'dark';
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
