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

        {{-- Cores de valores de lançamentos (App\Support\AmountTone / <x-fx.amount>): verde-700/vermelho-700 no claro, 400 no escuro. --}}
        <style id="amount-tones">
            :root,[data-theme=light]{--amount-in:#15803d;--amount-out:#b91c1c}
            [data-theme=dark]{--amount-in:#4ade80;--amount-out:#f87171}
            .fx-amount-in{color:var(--amount-in)!important}.fx-amount-out{color:var(--amount-out)!important}.fx-amount-neutral{color:var(--text-primary,inherit)!important}
        </style>
        {{-- Lançamento rápido: "+ Lançar" (desktop), botão "+" (celular), abas do modal e atalhos do painel. --}}
        <style id="quick-entry">
            :root,[data-theme=light]{--qe-out-bg:#fef2f2;--qe-out-bd:#fecaca;--qe-in-bg:#f0fdf4;--qe-in-bd:#bbf7d0}
            [data-theme=dark]{--qe-out-bg:rgba(248,113,113,.12);--qe-out-bd:rgba(248,113,113,.4);--qe-in-bg:rgba(74,222,128,.12);--qe-in-bd:rgba(74,222,128,.4)}
            .qe-desktop{display:none}
            @media (min-width:1024px){.qe-desktop{display:inline-flex}}
            .qe-launch-btn{display:inline-flex;align-items:center;gap:4px;height:40px;padding:0 16px;border-radius:9999px;background:var(--colors-primary-g500);color:#fff;font-size:14px;font-weight:600;white-space:nowrap}
            .qe-launch-btn:hover{background:var(--colors-primary-g600)}
            .qe-launch-split{border-top-right-radius:0;border-bottom-right-radius:0;padding-right:12px}
            .qe-launch-caret{border-top-left-radius:0;border-bottom-left-radius:0;padding:0 10px;margin-left:1px}
            .qe-launch-menu{position:absolute;right:0;top:46px;z-index:40;width:230px;overflow:hidden;border-radius:12px;border:1px solid var(--colors-mono-g200);background:var(--colors-mono-white);box-shadow:var(--shadow-elevated)}
            .qe-menu-item{display:flex;width:100%;align-items:center;gap:10px;padding:12px 14px;font-size:14px;font-weight:600;color:var(--colors-mono-g900);text-align:left}
            .qe-menu-item:hover{background:var(--colors-mono-g50)}
            .qe-fab{position:fixed;z-index:50;right:max(16px, calc(env(safe-area-inset-right) + 12px));bottom:max(16px, calc(env(safe-area-inset-bottom) + 12px));display:flex;flex-direction:column;align-items:flex-end;gap:10px}
            @media (min-width:1024px){.qe-fab{display:none}}
            .qe-fab-btn{display:flex;height:56px;width:56px;align-items:center;justify-content:center;border-radius:9999px;background:var(--colors-primary-g500);color:#fff;box-shadow:0 6px 18px rgba(0,0,0,.25)}
            .qe-fab-menu{width:230px;overflow:hidden;border-radius:14px;border:1px solid var(--colors-mono-g200);background:var(--colors-mono-white);box-shadow:var(--shadow-elevated)}
            /* Espaço no fim da página (celular) para o "+" não cobrir o último conteúdo. */
            @media (max-width:1023px){body.has-quick-fab main{padding-bottom:calc(96px + env(safe-area-inset-bottom))}}
            .qe-tab{display:inline-flex;align-items:center;gap:6px;min-height:40px;padding:0 14px;border-radius:9999px;border:1px solid var(--colors-mono-g200);font-size:14px;font-weight:600;color:var(--colors-mono-g600)}
            .qe-tab[aria-selected=true][data-quick-tab=expense]{background:var(--qe-out-bg);border-color:var(--qe-out-bd);color:var(--amount-out)}
            .qe-tab[aria-selected=true][data-quick-tab=income]{background:var(--qe-in-bg);border-color:var(--qe-in-bd);color:var(--amount-in)}
            .qe-tab[aria-selected=true][data-quick-tab=transfer]{background:var(--colors-mono-g100);border-color:var(--colors-mono-g600);color:var(--colors-mono-g900)}
            .qe-card-toggle{display:inline-flex;align-items:center;gap:4px;min-height:32px;padding:0 10px;border-radius:9999px;border:1px dashed var(--colors-mono-g200);font-size:12px;font-weight:600;color:var(--colors-mono-g600)}
            .qe-card-toggle[aria-pressed=true]{border-style:solid;border-color:#0d9488;color:#0d9488}
            .qe-shortcut{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;min-height:76px;padding:10px 6px;border-radius:14px;border:1px solid var(--colors-mono-g200);background:var(--colors-mono-white);font-size:15px;font-weight:700;color:var(--colors-mono-g900)}
            .qe-shortcut .material-icons-outlined{font-size:26px}
            @media (min-width:640px){.qe-shortcut{flex-direction:row;gap:10px;min-height:64px;font-size:16px}}
            .qe-shortcut-expense{background:var(--qe-out-bg);border-color:var(--qe-out-bd);color:var(--amount-out)}
            .qe-shortcut-income{background:var(--qe-in-bg);border-color:var(--qe-in-bd);color:var(--amount-in)}
            .qe-shortcut:hover{filter:brightness(.97)}
        </style>
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
    @php($quickEntry = \App\Support\QuickEntry::enabled(auth()->user()))
    <body
        class="bg-mono-50 font-sans text-mono-900 antialiased {{ \App\Support\RolePolicy::isReadOnly(auth()->user()) ? 'read-only' : '' }} {{ $quickEntry ? 'has-quick-fab' : '' }}"
        :class="{ 'overflow-hidden': sidebarOpen }"
        x-data="appShell({
            newIncome: '{{ route('banking.transactions.index', ['novo' => 'income']) }}',
            newExpense: '{{ route('banking.transactions.index', ['novo' => 'expense']) }}',
            newTransfer: '{{ route('banking.transactions.index', ['novo' => 'transfer']) }}',
            newWrit: '{{ route('writs.create') }}',
            dashboard: '{{ route('dashboard') }}',
            quickEntry: @js($quickEntry),
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

        @if ($quickEntry)
            <x-quick-entry.launcher variant="fab" />
            {{-- Lançamento rápido global: o MESMO componente/validação de Lançamentos, em modo só-modal. Em Lançamentos o próprio componente da página atende. --}}
            @unless (request()->routeIs('banking.transactions.index'))
                <livewire:banking.transactions.index :quick="true" key="quick-entry" />
            @endunless
        @endif

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
                        // nr/nd/nt abrem o lançamento rápido na própria tela.
                        const quickType = { nr: 'income', nd: 'expense', nt: 'transfer' }[this.buffer];
                        this.buffer = '';
                        if (config.quickEntry && quickType && window.Livewire) {
                            window.Livewire.dispatch('quick-entry', { type: quickType });
                            return;
                        }
                        window.location.href = target;
                    }
                },
            });
        </script>
    </body>
</html>
