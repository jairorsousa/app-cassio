# JR Design System

> Guia completo de design para manter consistencia visual em todos os projetos baseados no padrao Sistema JR.
> Ultima atualizacao: Maio 2026

---

## Sumario

1. [Fundamentos](#1-fundamentos)
2. [Paleta de Cores](#2-paleta-de-cores)
3. [Tipografia](#3-tipografia)
4. [Espacamento](#4-espacamento)
5. [Bordas e Arredondamento](#5-bordas-e-arredondamento)
6. [Sombras e Elevacao](#6-sombras-e-elevacao)
7. [Iconografia](#7-iconografia)
8. [Z-Index](#8-z-index)
9. [Componentes Blade](#9-componentes-blade)
10. [Layout e Estrutura](#10-layout-e-estrutura)
11. [Padroes de Interface](#11-padroes-de-interface)
12. [Dark Mode](#12-dark-mode)
13. [Responsividade](#13-responsividade)
14. [Animacoes e Transicoes](#14-animacoes-e-transicoes)
15. [Padroes de Codigo](#15-padroes-de-codigo)
16. [Referencia Rapida](#16-referencia-rapida)

---

## 1. Fundamentos

### Stack Tecnologico

| Camada       | Tecnologia                      |
|-------------|----------------------------------|
| Backend     | Laravel 12                       |
| Frontend    | Livewire 4 + Volt 1.10          |
| Interacao   | Alpine.js 3                      |
| CSS         | Tailwind CSS 3 + CSS Custom Properties |
| Icones      | Material Icons Outlined          |
| Fonte       | Reddit Sans (Google Fonts)       |
| Build       | Vite 7                           |

### Principios de Design

- **Limpo e Moderno**: Interface sem poluicao visual, com espacos generosos
- **Pill-shaped**: Inputs, botoes e badges usam arredondamento maximo (`rounded-pill` / `999px`)
- **Laranja como Identidade**: A cor primaria `#ff6f00` e usada como acento, nunca dominando a tela
- **Monocromatico como Base**: A escala de cinzas `mono-*` cria hierarquia sem distracao
- **Consistencia**: Todos os componentes seguem o mesmo vocabulario visual
- **Dark Mode Nativo**: Suporte completo via CSS Custom Properties e `data-theme="dark"`

---

## 2. Paleta de Cores

### Definicao (CSS Custom Properties)

As cores sao definidas como CSS Custom Properties em `:root` e mapeadas no `tailwind.config.js`. Isso permite dark mode sem alterar classes nos templates.

### Cores Primarias (Identidade)

| Token                | Light            | Dark             | Tailwind Class   | Uso                                    |
|---------------------|------------------|------------------|------------------|----------------------------------------|
| `--colors-primary-g100` | `#fff0e0`    | `#3d2800`        | `bg-primary-100` | Backgrounds sutis, hover de sidebar    |
| `--colors-primary-g500` | `#ff6f00`    | `#ff8c33`        | `bg-primary-500` | Botoes, links, badges ativos, destaque |
| `--colors-primary-g600` | `#e56300`    | `#ff6f00`        | `bg-primary-600` | Hover de botoes primarios              |

### Escala Monocromatica

| Token                | Light            | Dark             | Tailwind Class   | Uso                                    |
|---------------------|------------------|------------------|------------------|----------------------------------------|
| `--colors-mono-white`   | `#ffffff`    | `#1a1d21`        | `bg-mono-white`  | Background de cards, sidebar, header   |
| `--colors-mono-black`   | `#212427`    | `#f5f6f7`        | `text-mono-black` | Texto muito forte (raro)              |
| `--colors-mono-g50`     | `#f5f6f7`    | `#22262b`        | `bg-mono-50`     | Background da pagina, hovers           |
| `--colors-mono-g100`    | `#ecedef`    | `#2c3138`        | `bg-mono-100`    | Bordas, divisores, linhas              |
| `--colors-mono-g200`    | `#d5d7da`    | `#3a4049`        | `border-mono-200`| Bordas de inputs, separadores          |
| `--colors-mono-g300`    | `#b2b7bb`    | `#5a6370`        | `text-mono-300`  | Placeholders, icones inativos          |
| `--colors-mono-g600`    | `#8d959d`    | `#9aa1a9`        | `text-mono-600`  | Texto secundario, labels               |
| `--colors-mono-g900`    | `#212529`    | `#f0f1f3`        | `text-mono-900`  | Texto principal, titulos               |

### Cores Semanticas

| Token              | Light         | Dark          | Tailwind Class   | Uso                                      |
|-------------------|---------------|---------------|------------------|------------------------------------------|
| `--colors-up`     | `#15a96f`     | `#22c77e`     | `text-up`        | Receitas, valores positivos              |
| `--colors-up-bg`  | `#e8f8f0`     | `#162e22`     | `bg-up-bg`       | Background de receitas                   |
| `--colors-down`   | `#e43b3b`     | `#ef5555`     | `text-down`      | Despesas, valores negativos              |
| `--colors-down-bg`| `#fdeaea`     | `#3a1a1a`     | `bg-down-bg`     | Background de despesas, erros            |
| `--colors-error`  | `#ff4747`     | `#ff5c5c`     | `text-error`     | Erros de validacao, acoes perigosas      |
| `--colors-success`| `#1cc97d`     | `#22c77e`     | `text-success`   | Sucesso, confirmacao, status online      |
| `--colors-success-bg`| `#e8f3ea`  | `#162e22`     | `bg-success-bg`  | Background de sucesso                    |
| `--colors-info`   | `#1a73e8`     | `#4d9af5`     | `text-info`      | Informacoes, links, dicas               |
| `--colors-info-bg`| `#e8f0fe`     | `#1a2a40`     | `bg-info-bg`     | Background de informacao                 |

### Configuracao Tailwind

```javascript
// tailwind.config.js
colors: {
    primary: {
        100: 'var(--colors-primary-g100)',
        500: 'var(--colors-primary-g500)',
        600: 'var(--colors-primary-g600)',
    },
    mono: {
        white: 'var(--colors-mono-white)',
        black: 'var(--colors-mono-black)',
        50:  'var(--colors-mono-g50)',
        100: 'var(--colors-mono-g100)',
        200: 'var(--colors-mono-g200)',
        300: 'var(--colors-mono-g300)',
        600: 'var(--colors-mono-g600)',
        900: 'var(--colors-mono-g900)',
    },
    success:      'var(--colors-success)',
    'success-bg': 'var(--colors-success-bg)',
    error:        'var(--colors-error)',
    up:           'var(--colors-up)',
    'up-bg':      'var(--colors-up-bg)',
    down:         'var(--colors-down)',
    'down-bg':    'var(--colors-down-bg)',
    info:         'var(--colors-info)',
    'info-bg':    'var(--colors-info-bg)',
},
```

---

## 3. Tipografia

### Fonte Principal

```
Reddit Sans — Google Fonts
Pesos: 300 (Light), 400 (Regular), 500 (Medium), 600 (Semibold), 700 (Bold), 800 (Extra Bold)
```

**Importacao:**
```html
<link href="https://fonts.googleapis.com/css2?family=Reddit+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
```

**Tailwind Config:**
```javascript
fontFamily: {
    sans: ['Reddit Sans', ...defaultTheme.fontFamily.sans],
},
```

### Escala Tipografica

| Token        | Tamanho       | CSS Variable   | Uso                                     |
|-------------|---------------|----------------|------------------------------------------|
| `text-xxs`  | `0.625rem`    | `--fs-xxs`     | Micro-labels, contadores                |
| `text-[10px]`| `10px`       | —              | Timestamps, badges sm, meta info         |
| `text-[11px]`| `11px`       | —              | Section headers (tracking-widest)        |
| `text-xs`   | `0.75rem`     | —              | Labels, textos auxiliares                |
| `text-[13px]`| `13px`       | —              | Nomes em listas, botoes sm               |
| `text-sm`   | `0.875rem`    | `--fs-xs`      | Texto padrao em formularios e conteudo   |
| `text-base` | `1rem`        | `--fs-sm`      | Titulos de secao                         |
| `text-lg`   | `1.125rem`    | `--fs-md`      | Titulos de modal, titulos de pagina      |
| `text-xl`   | `1.25rem`     | `--fs-lg`      | Page title no header                     |
| `text-2xl`  | `1.5rem`      | `--fs-xl`      | Valores numericos grandes (dashboard)    |

### Line Height

| Token     | Valor  | CSS Variable | Uso                          |
|----------|--------|-------------|-------------------------------|
| `lh-sm`  | `1.2`  | `--lh-sm`   | Titulos                      |
| `lh-md`  | `1.4`  | `--lh-md`   | Texto padrao                 |
| `lh-lg`  | `1.6`  | `--lh-lg`   | Texto longo, descricoes      |

### Convencoes de Uso

| Contexto             | Tamanho      | Peso           | Cor             |
|---------------------|-------------|----------------|-----------------|
| Page title (header) | `text-xl`   | `font-bold`    | `text-mono-900` |
| Section title       | `text-base` | `font-bold`    | `text-mono-900` |
| Card metric label   | `text-xs`   | `font-medium`  | `text-mono-600` |
| Card metric value   | `text-2xl`  | `font-bold`    | `text-mono-900` |
| Table header (th)   | `text-xs`   | `font-semibold`| `text-mono-600` |
| Table cell (td)     | `text-sm`   | `font-medium`  | `text-mono-900` |
| Input label         | `text-sm`   | `font-medium`  | `text-mono-600` |
| Input text          | `text-sm`   | —              | `text-mono-900` |
| Placeholder         | `text-sm`   | —              | `text-mono-300` |
| Helper text         | `text-xs`   | —              | `text-mono-600` |
| Error text          | `text-xs`   | `font-medium`  | `text-error`    |
| Section label (CRM) | `text-[11px]`| `font-semibold`| `text-mono-400` + `uppercase tracking-widest` |

---

## 4. Espacamento

### Tokens de Espacamento

| Token       | Valor       | CSS Variable  | Tailwind   |
|------------|-------------|---------------|------------|
| xxxs       | `0.25rem`   | `--sp-xxxs`   | `gap-1`    |
| xxs        | `0.5rem`    | `--sp-xxs`    | `gap-2`    |
| xs         | `0.75rem`   | `--sp-xs`     | `gap-3`    |
| sm         | `1rem`      | `--sp-sm`     | `gap-4` / `p-4` |
| md         | `1.25rem`   | `--sp-md`     | `gap-5` / `p-5` |
| lg         | `1.5rem`    | `--sp-lg`     | `gap-6` / `p-6` |
| xl         | `2rem`      | `--sp-xl`     | `gap-8` / `p-8` |
| xxl        | `2.5rem`    | `--sp-xxl`    | `gap-10`   |

### Convencoes de Spacing

| Contexto                        | Padding / Gap             |
|-------------------------------|---------------------------|
| Main content (page)           | `p-6`                     |
| Card interno                  | `p-6`                     |
| Modal body                    | `px-6 py-5`               |
| Modal header/footer           | `px-6 py-4`               |
| Sidebar nav items             | `px-3 py-2.5`             |
| Header da pagina              | `px-6 h-16`               |
| Gap entre cards (grid)        | `gap-4`                   |
| Gap entre secoes              | `mb-6`                    |
| Flash messages margin         | `mb-4`                    |
| Gap entre form fields         | `space-y-4`               |
| Gap entre items de lista      | `space-y-1` ou `space-y-2`|
| Conversation items padding    | `px-5 py-3.5`             |

---

## 5. Bordas e Arredondamento

### Border Radius

| Token         | Valor     | CSS Variable     | Tailwind         | Uso                          |
|--------------|-----------|------------------|------------------|------------------------------|
| xs           | `4px`     | `--radius-xs`    | `rounded`        | Pouquissimo uso              |
| sm           | `8px`     | `--radius-sm`    | `rounded-lg`     | Botoes pequenos, close btn   |
| md           | `12px`    | `--radius-md`    | `rounded-xl`     | Inputs inline, dropdowns     |
| lg           | `16px`    | `--radius-lg`    | `rounded-2xl`    | Cards, modais, tabelas       |
| xl           | `20px`    | `--radius-xl`    | `rounded-3xl`    | Cards especiais (empty state)|
| pill         | `999px`   | `--radius-pill`  | `rounded-pill`   | **Inputs, botoes, badges, select** |

> **Regra principal:** Inputs (`<x-jr.input>`), botoes (`<x-jr.button>`) e badges (`<x-jr.badge>`) SEMPRE usam `rounded-pill`. Cards, modais e tabelas usam `rounded-2xl`.

### Tailwind Config

```javascript
borderRadius: {
    pill: '999px',
},
```

### Bordas (border)

| Contexto             | Classe                                        |
|---------------------|-----------------------------------------------|
| Card                | `border border-mono-100`                       |
| Input padrao        | `border border-mono-200`                       |
| Input focus         | `border-primary-500 shadow-[0_0_0_3px_rgba(255,111,0,.1)]` |
| Sidebar             | `border-r border-mono-100`                     |
| Header              | `border-b border-mono-100`                     |
| Divisor horizontal  | `border-t border-mono-100`                     |
| Divisor com margin  | `border-t border-mono-100 my-3`                |
| Borda sutil         | `border-mono-100/50` ou `border-mono-100/80`   |

---

## 6. Sombras e Elevacao

### Niveis de Sombra

| Token          | Valor                                                     | Tailwind          | Uso                              |
|---------------|-----------------------------------------------------------|-------------------|----------------------------------|
| card          | `0 2px 8px rgba(0,0,0,.06)`                                | `shadow-card`     | Cards padrao                     |
| dropdown      | `0 4px 20px hsla(0,0%,54%,.16), 0 4px 20px rgba(0,0,0,.1)` | `shadow-dropdown` | Menus dropdown, user menu        |
| elevated      | `0 8px 32px rgba(0,0,0,.12)`                               | `shadow-elevated` | Modais, paineis flutuantes       |
| sm (nativo)   | Tailwind default                                           | `shadow-sm`       | Botoes com destaque, unread badge|
| lg (nativo)   | Tailwind default                                           | `shadow-lg`       | Destaque especial (empty state)  |

### Dark Mode Sombras

```css
[data-theme="dark"] {
    --shadow-card: 0 2px 8px rgba(0,0,0,.3);
    --shadow-dropdown: 0 4px 20px rgba(0,0,0,.4);
    --shadow-elevated: 0 8px 32px rgba(0,0,0,.4);
}
```

### Tailwind Config

```javascript
boxShadow: {
    card: '0 2px 8px rgba(0,0,0,.06)',
    dropdown: '0 4px 20px 0 hsla(0,0%,54%,.16), 0 4px 20px 0 rgba(0,0,0,.1)',
    elevated: '0 8px 32px rgba(0,0,0,.12)',
},
```

---

## 7. Iconografia

### Biblioteca

**Material Icons Outlined** (Google Fonts)

```html
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
```

### Sintaxe

```html
<span class="material-icons-outlined text-[20px] text-mono-300">icon_name</span>
```

### Tamanhos Padrao

| Contexto              | Tamanho        | Classe                        |
|----------------------|----------------|-------------------------------|
| Icone de botao       | 16-18px        | `text-[16px]` / `text-[18px]` |
| Icone de input       | 20px           | `text-[20px]`                 |
| Icone de sidebar     | 20px           | `text-[20px]`                 |
| Icone de header      | 22px           | `text-[22px]`                 |
| Icone de card metric | 22px           | `text-[22px]`                 |
| Icone de empty state | 32-48px        | `text-[32px]` / `text-[48px]` |
| Icone hero           | 40px           | `text-[40px]`                 |

### Container de Icone (Icon Box)

Icones em cards de metricas e listas sempre vem dentro de um container:

```html
<!-- Padrao: 40x40 com rounded-xl e background semantico -->
<div class="w-10 h-10 rounded-xl bg-primary-100 flex items-center justify-center">
    <span class="material-icons-outlined text-[22px] text-primary-500">icon_name</span>
</div>

<!-- Variantes de cor -->
<div class="w-10 h-10 rounded-xl bg-up-bg flex items-center justify-center">
    <span class="material-icons-outlined text-[22px] text-up">trending_up</span>
</div>

<div class="w-10 h-10 rounded-xl bg-down-bg flex items-center justify-center">
    <span class="material-icons-outlined text-[22px] text-down">trending_down</span>
</div>

<div class="w-10 h-10 rounded-xl bg-info-bg flex items-center justify-center">
    <span class="material-icons-outlined text-[22px] text-info">view_kanban</span>
</div>
```

### Icones Comuns do Sistema

| Acao / Contexto     | Icone              |
|--------------------|--------------------|
| Adicionar          | `add`              |
| Editar             | `edit`             |
| Excluir            | `delete_outline`   |
| Fechar             | `close`            |
| Buscar             | `search`           |
| Filtrar            | `filter_list`      |
| Voltar             | `arrow_back`       |
| Mais opcoes        | `more_horiz`       |
| Expandir           | `expand_more`      |
| Configuracoes      | `settings`         |
| Sair               | `logout`           |
| Telefone           | `phone`            |
| Email              | `email`            |
| Pessoa             | `person`           |
| Empresa            | `business`         |
| Calendario         | `calendar_today`   |
| Dinheiro           | `payments`         |
| Conta bancaria     | `account_balance`  |
| Cartao             | `credit_card`      |
| Chat               | `chat`             |
| Enviar             | `send`             |
| Anexar             | `attach_file`      |
| Download           | `download`         |
| Link externo       | `open_in_new`      |
| Info               | `info`             |
| Sucesso            | `check_circle`     |
| Erro               | `error`            |
| Menu hamburguer    | `menu`             |

---

## 8. Z-Index

| Token        | Valor   | CSS Variable   | Tailwind       | Uso                         |
|-------------|---------|----------------|----------------|------------------------------|
| dropdown    | `100`   | `--z-dropdown` | `z-dropdown`   | Dropdowns, menus flutuantes  |
| modal       | `1000`  | `--z-modal`    | `z-modal`      | Modais, overlays             |
| sidebar     | `50`    | —              | `z-50`         | Sidebar (fixed)              |
| header      | `30`    | —              | `z-30`         | Header (sticky)              |
| overlay     | `40`    | —              | `z-40`         | Mobile overlay               |
| flash       | `60`    | —              | `z-[60]`       | Flash messages flutuantes    |

### Tailwind Config

```javascript
zIndex: {
    dropdown: '100',
    modal: '1000',
},
```

---

## 9. Componentes Blade

Todos os componentes reutilizaveis ficam em `resources/views/components/jr/` e sao usados com o prefixo `<x-jr.*>`.

### 9.1 Button (`<x-jr.button>`)

**Arquivo:** `resources/views/components/jr/button.blade.php`

**Props:**

| Prop      | Tipo     | Default     | Opcoes                                     |
|----------|----------|-------------|---------------------------------------------|
| variant  | string   | `primary`   | `primary`, `standard`, `mono`, `text`, `danger` |
| size     | string   | `default`   | `default`, `sm`                             |
| type     | string   | `button`    | `button`, `submit`, `reset`                 |
| href     | string   | `null`      | URL (renderiza como `<a>`)                  |

**Caracteristicas:**
- Sempre `rounded-pill`
- `active:scale-[.97]` para micro-interacao
- `transition-all duration-200`
- `disabled:opacity-50 disabled:cursor-not-allowed`

**Variantes Visuais:**

| Variante   | Background          | Texto          | Hover              |
|-----------|---------------------|----------------|---------------------|
| primary   | `bg-primary-500`    | `text-white`   | `bg-primary-600`   |
| standard  | `bg-transparent`    | `text-mono-900`| `bg-mono-50` + border |
| mono      | `bg-mono-100`       | `text-mono-900`| `bg-mono-200`      |
| text      | `bg-transparent`    | `text-mono-900`| `bg-mono-50`       |
| danger    | `bg-error`          | `text-white`   | `bg-red-600`       |

**Tamanhos:**

| Tamanho   | Height | Padding    | Font Size   |
|----------|--------|------------|-------------|
| default  | `h-11` | `px-6`     | `text-sm`   |
| sm       | `h-9`  | `px-4`     | `text-[13px]`|

**Exemplos:**
```html
<x-jr.button>Salvar</x-jr.button>
<x-jr.button variant="mono" size="sm">Cancelar</x-jr.button>
<x-jr.button variant="danger">Excluir</x-jr.button>
<x-jr.button href="/rota">Link Button</x-jr.button>

<!-- Com icone -->
<x-jr.button>
    <span class="material-icons-outlined text-[16px]">add</span>
    Novo Item
</x-jr.button>
```

---

### 9.2 Input (`<x-jr.input>`)

**Arquivo:** `resources/views/components/jr/input.blade.php`

**Props:**

| Prop    | Tipo     | Default  | Descricao                        |
|--------|----------|----------|-----------------------------------|
| label  | string   | `null`   | Label acima do input             |
| icon   | string   | `null`   | Nome do Material Icon (esquerda) |
| error  | string   | `null`   | Mensagem de erro                 |
| success| bool     | `false`  | Estado de sucesso                |
| helper | string   | `null`   | Texto de ajuda                   |
| type   | string   | `text`   | Tipo do input                    |

**Caracteristicas:**
- Container com `rounded-pill` e `h-12`
- Focus: `border-primary-500` + `shadow-[0_0_0_3px_rgba(255,111,0,.1)]`
- Erro: `border-error` + icone `error`
- Sucesso: `border-success` + icone `check_circle`
- Input interno sem borda, sem ring, transparente

**Exemplos:**
```html
<x-jr.input label="Nome" wire:model="name" placeholder="Nome completo" icon="person" />
<x-jr.input label="Email" wire:model="email" type="email" icon="email" :error="$errors->first('email')" />
<x-jr.input wire:model.live.debounce.300ms="search" placeholder="Buscar..." icon="search" />
```

---

### 9.3 Badge (`<x-jr.badge>`)

**Arquivo:** `resources/views/components/jr/badge.blade.php`

**Props:**

| Prop    | Tipo   | Default   | Opcoes                                               |
|--------|--------|-----------|-------------------------------------------------------|
| variant| string | `neutral` | `up`, `down`, `success`, `error`, `info`, `primary`, `neutral` |
| size   | string | `default` | `default`, `sm`                                       |

**Caracteristicas:**
- Sempre `rounded-pill`
- `font-semibold`
- Combina texto colorido com background claro

**Tamanhos:**

| Tamanho  | Font Size    | Padding          |
|---------|-------------|------------------|
| default | `text-xs`   | `px-2.5 py-1`   |
| sm      | `text-[10px]`| `px-2 py-0.5`   |

**Exemplos:**
```html
<x-jr.badge variant="success">Ativo</x-jr.badge>
<x-jr.badge variant="error" size="sm">Erro</x-jr.badge>
<x-jr.badge variant="up">+15%</x-jr.badge>
<x-jr.badge variant="primary">CRM</x-jr.badge>
```

---

### 9.4 Alert (`<x-jr.alert>`)

**Arquivo:** `resources/views/components/jr/alert.blade.php`

**Props:**

| Prop        | Tipo   | Default | Opcoes                       |
|------------|--------|---------|-------------------------------|
| variant    | string | `info`  | `success`, `error`, `info`    |
| dismissible| bool   | `true`  | Mostra/oculta botao fechar    |

**Caracteristicas:**
- `rounded-xl` com `border` + `background semantico`
- Icone automatico por variante (`check_circle`, `error`, `info`)
- Dismissible via Alpine.js `x-data="{ visible: true }"`

**Variantes:**

| Variante | Background      | Texto         | Icone          |
|---------|-----------------|---------------|----------------|
| success | `bg-success-bg` | `text-success`| `check_circle` |
| error   | `bg-down-bg`    | `text-error`  | `error`        |
| info    | `bg-info-bg`    | `text-info`   | `info`         |

**Exemplo:**
```html
<x-jr.alert variant="success">Registro salvo com sucesso!</x-jr.alert>
<x-jr.alert variant="error" :dismissible="false">Erro ao processar.</x-jr.alert>
```

**Padrao de uso em paginas (flash messages):**
```html
@if (session('success'))
    <div class="mb-4"><x-jr.alert variant="success">{{ session('success') }}</x-jr.alert></div>
@endif
@if (session('error'))
    <div class="mb-4"><x-jr.alert variant="error">{{ session('error') }}</x-jr.alert></div>
@endif
```

---

### 9.5 Card (`<x-jr.card>`)

**Arquivo:** `resources/views/components/jr/card.blade.php`

**Props:**

| Prop    | Tipo | Default | Descricao                    |
|--------|------|---------|-------------------------------|
| padding| bool | `true`  | Aplica `p-6` automaticamente  |

**Caracteristicas:**
- `bg-mono-white rounded-2xl shadow-card border border-mono-100`
- `p-6` quando `padding=true`

**Exemplos:**
```html
<x-jr.card>
    <p>Conteudo do card</p>
</x-jr.card>

<x-jr.card :padding="false">
    <!-- Card sem padding (ex: tabela interna) -->
</x-jr.card>
```

---

### 9.6 Table (`<x-jr.table>`)

**Arquivo:** `resources/views/components/jr/table.blade.php`

**Props:**

| Prop    | Tipo | Default | Descricao                     |
|--------|------|---------|--------------------------------|
| striped| bool | `false` | Linhas alternadas com bg-mono-50|

**Slots:**
- `$head` — Conteudo do `<thead><tr>`
- `$slot` — Linhas do `<tbody>`

**Caracteristicas:**
- Container: `bg-mono-white rounded-2xl border border-mono-100 overflow-hidden`
- `overflow-x-auto` com `min-w-[600px]` para responsividade
- Header: `bg-mono-50`

**Exemplo:**
```html
<x-jr.table>
    <x-slot:head>
        <th class="text-left px-4 py-3 text-xs font-semibold text-mono-600 uppercase tracking-wider">Nome</th>
        <th class="text-left px-4 py-3 text-xs font-semibold text-mono-600 uppercase tracking-wider">Email</th>
        <th class="text-right px-4 py-3 text-xs font-semibold text-mono-600 uppercase tracking-wider">Acoes</th>
    </x-slot:head>

    @foreach($items as $item)
        <tr class="border-t border-mono-100 hover:bg-mono-50 transition-colors">
            <td class="px-4 py-3 text-sm font-medium text-mono-900">{{ $item->name }}</td>
            <td class="px-4 py-3 text-sm text-mono-600">{{ $item->email }}</td>
            <td class="px-4 py-3 text-right">
                <!-- acoes -->
            </td>
        </tr>
    @endforeach
</x-jr.table>
```

---

### 9.7 Modal (`<x-jr.modal>`)

**Arquivo:** `resources/views/components/jr/modal.blade.php`

**Props:**

| Prop     | Tipo   | Default | Opcoes                          |
|---------|--------|---------|---------------------------------|
| name    | string | `''`    | Identificador do modal          |
| maxWidth| string | `lg`    | `sm`, `md`, `lg`, `xl`, `2xl`   |
| title   | string | `''`    | Titulo no header                |

**Slots:**
- `$slot` — Body do modal (dentro de `px-6 py-5`)
- `$footer` — Footer com botoes (dentro de `px-6 py-4 border-t bg-mono-50`)

**Caracteristicas:**
- Overlay: `bg-black/40` (sem blur no modal padrao)
- Container: `rounded-2xl shadow-elevated`
- Abertura/fechamento via eventos Alpine: `open-modal` / `close-modal`
- Escape fecha o modal
- Mobile: fullscreen via CSS `.modal-content`

**Controle:**
```javascript
// Abrir
$dispatch('open-modal', 'nome-do-modal')

// Fechar
$dispatch('close-modal', 'nome-do-modal')
```

**Modais Inline (sem componente):**

Para modais controlados por Livewire, o padrao e usar condicionais `@if($showModal)` com estrutura manual:

```html
@if($showMyModal)
    <div class="fixed inset-0 z-modal overflow-y-auto" wire:keydown.escape="$set('showMyModal', false)">
        <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" wire:click="$set('showMyModal', false)"></div>
        <div class="flex min-h-screen items-center justify-center p-4">
            <div class="relative bg-mono-white rounded-2xl shadow-elevated w-full sm:max-w-md overflow-hidden">
                <!-- Header -->
                <div class="flex items-center justify-between px-6 py-4 border-b border-mono-100">
                    <h3 class="text-lg font-bold text-mono-900">Titulo</h3>
                    <button wire:click="$set('showMyModal', false)"
                            class="w-8 h-8 rounded-lg flex items-center justify-center text-mono-400 hover:text-mono-600 hover:bg-mono-100 transition-colors">
                        <span class="material-icons-outlined text-[20px]">close</span>
                    </button>
                </div>

                <!-- Body -->
                <div class="px-6 py-5 space-y-4">
                    <!-- conteudo -->
                </div>

                <!-- Footer -->
                <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-mono-100 bg-mono-50">
                    <x-jr.button variant="mono" wire:click="$set('showMyModal', false)" type="button">Cancelar</x-jr.button>
                    <x-jr.button type="submit">Confirmar</x-jr.button>
                </div>
            </div>
        </div>
    </div>
@endif
```

---

## 10. Layout e Estrutura

### Estrutura Geral

```
+--------------------------------------------------+
|                    Header (h-16)                   |
|  [hamburger]  [Page Title]          [dark][notif][user]
+--------+-----------------------------------------+
|        |                                          |
| Sidebar|              Main Content                |
| (w-60) |                 (p-6)                     |
|        |                                          |
|        |                                          |
+--------+-----------------------------------------+
```

### Sidebar

- **Arquivo:** `resources/views/layouts/sidebar.blade.php`
- Largura aberta: `w-60` (240px)
- Largura colapsada: `w-16` (64px, apenas icones)
- Posicao: `fixed top-0 left-0 z-50`
- Background: `bg-mono-white`
- Borda: `border-r border-mono-100`

**Logo:**
```html
<div class="w-8 h-8 rounded-lg bg-primary-500 flex items-center justify-center">
    <span class="text-white font-bold text-sm">JR</span>
</div>
```

**Item de Menu:**
```html
<!-- Ativo -->
<a class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-primary-500 bg-primary-100">
    <span class="material-icons-outlined text-[20px]">icon</span>
    <span>Label</span>
</a>

<!-- Inativo -->
<a class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-mono-900 hover:bg-mono-100">
    <span class="material-icons-outlined text-[20px]">icon</span>
    <span>Label</span>
</a>
```

**Secao de Menu:**
```html
<p class="px-3 pt-2 pb-1 text-[10px] font-semibold text-mono-300 uppercase tracking-wider">
    Titulo da Secao
</p>
```

**Item Perigoso (Sair):**
```html
<button class="... text-error hover:bg-down-bg">
    <span class="material-icons-outlined text-[20px]">logout</span>
    Sair
</button>
```

### Header

- **Arquivo:** `resources/views/layouts/header.blade.php`
- Altura: `h-16`
- Posicao: `sticky top-0 z-30`
- Background: `bg-mono-white`
- Borda: `border-b border-mono-100`

**Botoes do Header:**
```html
<!-- Circular -->
<button class="relative p-2 rounded-full text-mono-600 hover:bg-mono-50 transition-colors">
    <span class="material-icons-outlined text-[22px]">icon</span>
</button>
```

**User Dropdown:**
```html
<div class="absolute right-0 mt-2 w-56 bg-mono-white rounded-xl shadow-dropdown border border-mono-100 py-2 z-dropdown">
    <!-- items -->
</div>
```

### Main Content

```html
<main class="flex-1 p-6">
    {{ $slot }}
</main>
```

### Page Wrapper (Views)

Cada pagina usa o layout padrao com slot de header:

```html
<x-app-layout>
    <x-slot name="header">Nome da Pagina</x-slot>
    <livewire:modulo.componente />
</x-app-layout>
```

### Login/Guest Layout

- Centralizado vertical/horizontal
- Card: `sm:max-w-md px-8 py-8 bg-white shadow-card border border-mono-100 rounded-2xl`
- Logo maior: `w-12 h-12 rounded-xl`

---

## 11. Padroes de Interface

### 11.1 Summary Cards (Dashboard)

Cards de metricas no topo de paginas:

```html
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <x-jr.card>
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs text-mono-600 font-medium uppercase tracking-wider">Label</p>
                <p class="text-2xl font-bold text-mono-900 mt-1">R$ 1.234,56</p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-primary-100 flex items-center justify-center">
                <span class="material-icons-outlined text-[22px] text-primary-500">icon</span>
            </div>
        </div>
    </x-jr.card>
</div>
```

### 11.2 Summary Cards (Compacto)

Variante com icone a esquerda:

```html
<x-jr.card>
    <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-info-bg flex items-center justify-center">
            <span class="material-icons-outlined text-[22px] text-info">icon</span>
        </div>
        <div>
            <p class="text-xs text-mono-600">Label</p>
            <p class="text-lg font-bold text-mono-900">Valor</p>
        </div>
    </div>
</x-jr.card>
```

### 11.3 Page Header com Filtros

```html
<div class="flex items-center justify-between mb-6">
    <div class="flex items-center gap-3 flex-1">
        <div class="max-w-sm flex-1">
            <x-jr.input wire:model.live.debounce.300ms="search" placeholder="Buscar..." icon="search" />
        </div>
        <select wire:model.live="filter"
                class="h-12 px-4 rounded-pill border border-mono-200 bg-mono-white text-sm text-mono-900 focus:border-primary-500 focus:ring-1 focus:ring-primary-500">
            <option value="">Todos</option>
        </select>
    </div>
    <x-jr.button wire:click="openCreateModal">
        <span class="material-icons-outlined text-[18px]">add</span>
        Novo Item
    </x-jr.button>
</div>
```

### 11.4 Month Navigation

```html
<div class="flex items-center gap-2">
    <button wire:click="previousMonth"
            class="p-2 rounded-xl text-mono-600 hover:bg-mono-100 transition-colors">
        <span class="material-icons-outlined text-[22px]">chevron_left</span>
    </button>
    <div class="text-center min-w-[180px]">
        <h2 class="text-lg font-bold text-mono-900">{{ $monthLabel }}</h2>
    </div>
    <button wire:click="nextMonth"
            class="p-2 rounded-xl text-mono-600 hover:bg-mono-100 transition-colors">
        <span class="material-icons-outlined text-[22px]">chevron_right</span>
    </button>
</div>
```

### 11.5 Empty State

```html
<x-jr.card>
    <div class="text-center py-12">
        <span class="material-icons-outlined text-[48px] text-mono-200">icon_name</span>
        <p class="text-mono-600 mt-2">Nenhum item encontrado.</p>
        <div class="mt-4">
            <x-jr.button wire:click="openCreateModal" size="sm">
                Criar primeiro item
            </x-jr.button>
        </div>
    </div>
</x-jr.card>
```

### 11.6 Empty State (Premium/Glassmorphism)

Usado em areas especiais como o chat:

```html
<div class="bg-mono-white/80 backdrop-blur-sm rounded-3xl p-10 shadow-sm border border-mono-100/50 max-w-sm">
    <div class="w-20 h-20 rounded-2xl bg-gradient-to-br from-primary-400 to-primary-600 flex items-center justify-center mx-auto mb-5 shadow-lg shadow-primary-500/20">
        <span class="material-icons-outlined text-[40px] text-white">icon</span>
    </div>
    <h3 class="text-xl font-bold text-mono-900 mb-2">Titulo</h3>
    <p class="text-sm text-mono-500 leading-relaxed">Descricao</p>
</div>
```

### 11.7 Grid de Cards

```html
<!-- 3 colunas -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
    @foreach($items as $item)
        <x-jr.card>...</x-jr.card>
    @endforeach
</div>
```

### 11.8 Dropdown Menu

```html
<div class="relative" x-data="{ open: false }">
    <button @click="open = !open" class="...">
        <span class="material-icons-outlined text-[20px]">more_horiz</span>
    </button>
    <div x-show="open" x-cloak @click.away="open = false"
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         class="absolute right-0 top-11 bg-mono-white rounded-2xl shadow-elevated border border-mono-100 py-2 z-50 w-52">

        <!-- Item normal -->
        <button class="flex items-center gap-3 w-full px-4 py-2.5 text-sm text-mono-700 hover:bg-mono-50 transition-colors">
            <span class="material-icons-outlined text-[18px] text-mono-400">icon</span>
            Label
        </button>

        <!-- Divisor -->
        <div class="my-1.5 mx-3 border-t border-mono-100"></div>

        <!-- Item perigoso -->
        <button class="flex items-center gap-3 w-full px-4 py-2.5 text-sm text-error hover:bg-down-bg transition-colors">
            <span class="material-icons-outlined text-[18px]">delete_outline</span>
            Excluir
        </button>
    </div>
</div>
```

### 11.9 Table Header Cell

```html
<th class="text-left px-4 py-3 text-xs font-semibold text-mono-600 uppercase tracking-wider">
    Label
</th>

<!-- Alinhado a direita (valores, acoes) -->
<th class="text-right px-4 py-3 text-xs font-semibold text-mono-600 uppercase tracking-wider">
    Valor
</th>
```

### 11.10 Table Row

```html
<tr class="border-t border-mono-100 hover:bg-mono-50 transition-colors">
    <td class="px-4 py-3 text-sm font-medium text-mono-900">Texto</td>
    <td class="px-4 py-3 text-sm text-mono-600">Secundario</td>
    <td class="px-4 py-3 text-right">
        <button class="p-1.5 rounded-lg text-mono-400 hover:text-primary-500 hover:bg-primary-50 transition-colors">
            <span class="material-icons-outlined text-[18px]">edit</span>
        </button>
    </td>
</tr>
```

### 11.11 Select Inline

Selects usados em filtros (sem componente Blade):

```html
<select wire:model.live="filter"
        class="h-12 px-4 rounded-pill border border-mono-200 bg-mono-white text-sm text-mono-900 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-colors">
    <option value="">Todos</option>
</select>
```

### 11.12 Date Input Inline

```html
<input type="date" wire:model="date"
       class="w-full bg-mono-white border border-mono-200 rounded-xl px-3 h-10 text-sm text-mono-900 focus:border-primary-500 focus:ring-0">
```

### 11.13 Toggle / Action Buttons

Botoes de acao inline em tabelas:

```html
<!-- Acao principal (icon button) -->
<button class="p-1.5 rounded-lg text-mono-400 hover:text-primary-500 hover:bg-primary-50 transition-colors">
    <span class="material-icons-outlined text-[18px]">edit</span>
</button>

<!-- Acao perigosa -->
<button class="p-1.5 rounded-lg text-mono-400 hover:text-error hover:bg-down-bg transition-colors">
    <span class="material-icons-outlined text-[18px]">delete_outline</span>
</button>
```

### 11.14 Toggle Button (active state)

```html
<button @class([
    'w-9 h-9 rounded-xl flex items-center justify-center transition-all',
    'bg-primary-500 text-white shadow-sm' => $isActive,
    'text-mono-400 hover:text-mono-600 hover:bg-mono-100' => !$isActive,
])>
    <span class="material-icons-outlined text-[20px]">icon</span>
</button>
```

### 11.15 Progress Bar

```html
<div class="w-full bg-mono-100 rounded-full h-2 overflow-hidden">
    <div class="h-full bg-primary-500 rounded-full transition-all duration-500"
         style="width: {{ $percent }}%"></div>
</div>
```

### 11.16 Avatar (Iniciais)

```html
<!-- Tamanho padrao (48px, listas) -->
<div class="w-12 h-12 rounded-full bg-mono-100 flex items-center justify-center">
    <span class="text-sm font-bold text-mono-500">AB</span>
</div>

<!-- Com gradiente (header, perfil) -->
<div class="w-11 h-11 rounded-full bg-gradient-to-br from-primary-400 to-primary-600 flex items-center justify-center">
    <span class="text-sm font-bold text-white">AB</span>
</div>

<!-- Com indicador online -->
<div class="relative">
    <div class="w-12 h-12 rounded-full bg-mono-100 flex items-center justify-center">...</div>
    <span class="absolute bottom-0 right-0 w-3 h-3 rounded-full bg-success border-2 border-mono-white"></span>
</div>

<!-- Com badge CRM -->
<div class="relative">
    <div class="w-12 h-12 rounded-full bg-mono-100 flex items-center justify-center">...</div>
    <span class="absolute -bottom-0.5 -right-0.5 w-[18px] h-[18px] rounded-full bg-success flex items-center justify-center border-2 border-mono-white shadow-sm">
        <span class="material-icons-outlined text-[9px] text-white">person</span>
    </span>
</div>
```

### 11.17 Indicator Dot (Status)

```html
<!-- Pulsante (online, sincronizado) -->
<span class="relative flex h-2 w-2">
    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-success opacity-75"></span>
    <span class="relative inline-flex rounded-full h-2 w-2 bg-success"></span>
</span>

<!-- Estatico -->
<span class="w-1.5 h-1.5 rounded-full bg-success"></span>
```

---

## 12. Dark Mode

### Implementacao

O dark mode usa CSS Custom Properties que sao alternadas via atributo `data-theme="dark"` no `<html>`.

**Controle (Alpine.js no body):**
```javascript
x-data="{
    darkMode: localStorage.getItem('jr-theme') === 'dark',
    toggleDark() {
        this.darkMode = !this.darkMode;
        localStorage.setItem('jr-theme', this.darkMode ? 'dark' : 'light');
    }
}"
:data-theme="darkMode ? 'dark' : ''"
```

**Prevencao de flash (no head):**
```html
<script>
    if (localStorage.getItem('jr-theme') === 'dark') {
        document.documentElement.setAttribute('data-theme', 'dark');
    }
</script>
```

### Valores Dark Mode

Veja a tabela completa de cores na secao [Paleta de Cores](#2-paleta-de-cores). Os valores dark sao definidos em:

```css
[data-theme="dark"] {
    --colors-mono-white: #1a1d21;   /* Inverte: branco vira escuro */
    --colors-mono-g50: #22262b;
    --colors-mono-g100: #2c3138;
    --colors-mono-g200: #3a4049;
    --colors-mono-g300: #5a6370;
    --colors-mono-g600: #9aa1a9;
    --colors-mono-g900: #f0f1f3;    /* Inverte: texto escuro vira claro */
    /* ... */
}
```

### Regras de Dark Mode

1. **Nunca use cores hardcoded** (`bg-white`, `text-black`). Sempre use os tokens (`bg-mono-white`, `text-mono-900`).
2. Excecao: `text-white` e permitido sobre backgrounds primarios/gradientes.
3. Inputs e selects tem override global no CSS para dark mode.
4. Sombras ficam mais intensas no dark mode (opacity maior).

---

## 13. Responsividade

### Breakpoints (Tailwind default)

| Prefixo | Largura   | Uso                        |
|---------|-----------|----------------------------|
| (none)  | < 640px   | Mobile first               |
| `sm:`   | >= 640px  | Smartphones grandes        |
| `md:`   | >= 768px  | Tablets, sidebar aparece   |
| `lg:`   | >= 1024px | Desktop, grids expandem    |
| `xl:`   | >= 1280px | Desktop grande             |

### Padroes Responsivos

**Grids:**
```html
<!-- 4 colunas -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">

<!-- 3 colunas -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">

<!-- 3 colunas (summary) -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
```

**Sidebar:**
- Mobile: escondida (`-translate-x-full`), abre como overlay com `z-50`
- Desktop: fixa com `md:translate-x-0`, main content com `md:ml-60`
- Colapsada: `md:w-16` (mostra so icones)

**Modais:**
```css
@media (max-width: 639px) {
    .modal-content {
        @apply !max-w-full !w-full !mx-0 !rounded-none min-h-screen;
    }
}
```

**Main content mobile:**
```css
@media (max-width: 639px) {
    main.flex-1 {
        @apply px-3 py-4;
    }
}
```

**Tabelas:**
```html
<div class="overflow-x-auto">
    <table class="w-full border-collapse min-w-[600px]">
```

### Chat (Full Height Layout)

Para interfaces full-height como o chat:
```html
<div class="flex h-[calc(100vh-5rem)] -mx-6 -mt-6 -mb-6">
    <!-- Colunas internas com flex -->
</div>
```

---

## 14. Animacoes e Transicoes

### Transicoes Padrao

| Contexto           | Classe Tailwind                        |
|-------------------|----------------------------------------|
| Geral             | `transition-colors`                     |
| Completo          | `transition-all`                        |
| Com duracao       | `transition-all duration-200`           |
| Hover de botao    | `transition-all` + `active:scale-[.97]` |
| Hover icon button | `transition-colors`                     |

### Transicoes Alpine.js (Dropdowns/Modais)

```html
<!-- Dropdown -->
x-transition:enter="transition ease-out duration-150"
x-transition:enter-start="opacity-0 scale-95"
x-transition:enter-end="opacity-100 scale-100"

<!-- Com translate -->
x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
x-transition:enter-end="opacity-100 scale-100 translate-y-0"

<!-- Modal -->
x-transition:enter="ease-out duration-300"
x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
x-transition:leave="ease-in duration-200"
x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
```

### Micro-interacoes

```html
<!-- Botao com scale -->
active:scale-[.97]

<!-- Botao icon com scale -->
active:scale-95

<!-- Loading spinner -->
<span class="material-icons-outlined animate-spin">autorenew</span>

<!-- Ping (indicador online) -->
<span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-success opacity-75"></span>

<!-- Progress bar transicao -->
<div class="h-full bg-primary-500 rounded-full transition-all duration-500" style="width: {{ $percent }}%"></div>
```

---

## 15. Padroes de Codigo

### Estrutura de Componente Livewire

```php
// app/Livewire/Modulo/NomeComponente.php
<?php

namespace App\Livewire\Modulo;

use Livewire\Component;

class NomeComponente extends Component
{
    // Properties
    public string $search = '';
    public bool $showCreateModal = false;

    // Computed / Render
    public function render()
    {
        return view('livewire.modulo.nome-componente', [
            'items' => $this->getItems(),
        ]);
    }

    // Private helpers
    private function getItems() { /* ... */ }
}
```

### Estrutura de View Livewire

```html
<div>
    <!-- Flash Messages -->
    @if (session('success'))
        <div class="mb-4"><x-jr.alert variant="success">{{ session('success') }}</x-jr.alert></div>
    @endif
    @if (session('error'))
        <div class="mb-4"><x-jr.alert variant="error">{{ session('error') }}</x-jr.alert></div>
    @endif

    <!-- Header com filtros e botao de acao -->
    <div class="flex items-center justify-between mb-6">
        <!-- Filtros -->
        <!-- Botao principal -->
    </div>

    <!-- Conteudo principal -->

    <!-- Modais -->
</div>
```

### Nomeacao de Rotas

```
modulo.recurso       → financeiro.transacoes
modulo.sub-recurso   → whatsapp.chat
crm.recurso          → crm.pipeline, crm.contatos
```

### Nomeacao de Views

```
resources/views/livewire/modulo/componente.blade.php
resources/views/modulo/pagina.blade.php  (wrapper)
```

### Wire Model Patterns

```html
<!-- Busca com debounce -->
wire:model.live.debounce.300ms="search"

<!-- Select reativo -->
wire:model.live="filter"

<!-- Input de formulario (sem live) -->
wire:model="name"
```

### Loading States

```html
<!-- Botao com loading -->
<button wire:loading.attr="disabled" wire:target="save">
    <span wire:loading.remove wire:target="save">Salvar</span>
    <span wire:loading wire:target="save" class="material-icons-outlined animate-spin text-[18px]">autorenew</span>
</button>

<!-- Icone de upload loading -->
<span wire:loading.remove wire:target="mediaFile" class="material-icons-outlined text-[22px]">attach_file</span>
<span wire:loading wire:target="mediaFile" class="material-icons-outlined text-[20px] animate-spin text-primary-500">autorenew</span>
```

### Confirm Delete Pattern

```html
<button wire:click="delete('{{ $item->id }}')"
        wire:confirm="Tem certeza que deseja excluir?"
        class="p-1.5 rounded-lg text-mono-400 hover:text-error hover:bg-down-bg transition-colors">
    <span class="material-icons-outlined text-[18px]">delete_outline</span>
</button>
```

### Currency Format (BRL)

```php
R$ {{ number_format($value, 2, ',', '.') }}
```

### Date Formats

```php
// Data curta
$date->format('d/m/Y')

// Data com hora
$date->format('d/m/Y H:i')

// Hora
$date->format('H:i')

// Relativo
$date->isToday() ? 'Hoje' : ($date->isYesterday() ? 'Ontem' : $date->format('d/m/Y'))

// Month label
Carbon::createFromDate($year, $month, 1)->translatedFormat('F Y')
```

---

## 16. Referencia Rapida

### Checklist para Novas Paginas

- [ ] Criar wrapper view: `resources/views/modulo/pagina.blade.php`
- [ ] Usar `<x-app-layout><x-slot name="header">Titulo</x-slot>...</x-app-layout>`
- [ ] Criar componente Livewire em `app/Livewire/Modulo/`
- [ ] Adicionar rota em `routes/web.php`
- [ ] Adicionar item no sidebar em `resources/views/layouts/sidebar.blade.php`
- [ ] Usar flash messages no topo da view
- [ ] Seguir padrao de filtros (input + select + button)
- [ ] Usar componentes `x-jr.*` em vez de HTML manual
- [ ] Testar dark mode
- [ ] Testar responsividade mobile

### Classes Mais Usadas

```
bg-mono-white          → Background de cards/superficies
bg-mono-50             → Background da pagina
bg-mono-100            → Hover, stripe de tabela
border-mono-100        → Todas as bordas
border-mono-200        → Bordas de inputs
text-mono-900          → Texto principal
text-mono-600          → Texto secundario
text-mono-300          → Placeholders, icones inativos
text-mono-400          → Meta info, timestamps
bg-primary-500         → Botoes, links, acento
bg-primary-100         → Hover sidebar ativo, background leve
text-primary-500       → Links, texto primario
rounded-pill           → Inputs, botoes, badges
rounded-2xl            → Cards, modais, dropdowns
rounded-xl             → Botoes internos, icon boxes
shadow-card            → Cards padrao
shadow-elevated        → Modais
shadow-dropdown        → Menus flutuantes
h-12                   → Altura de inputs
h-11                   → Altura de botoes
h-9                    → Altura de botoes sm
h-16                   → Altura do header
w-60                   → Largura da sidebar aberta
```

### Cores por Contexto

| Contexto          | Texto          | Background     | Border          |
|------------------|----------------|----------------|-----------------|
| Receita / Ganho  | `text-up`      | `bg-up-bg`     | `border-up`     |
| Despesa / Perda  | `text-down`    | `bg-down-bg`   | `border-down`   |
| Sucesso          | `text-success` | `bg-success-bg`| `border-success`|
| Erro / Perigo    | `text-error`   | `bg-down-bg`   | `border-error`  |
| Informacao       | `text-info`    | `bg-info-bg`   | `border-info`   |
| Primario / Acao  | `text-primary-500`| `bg-primary-100`| `border-primary-500`|
| Neutro           | `text-mono-600`| `bg-mono-100`  | `border-mono-200`|

---

> **Nota:** Este design system e um documento vivo. Atualize-o sempre que novos componentes ou padroes forem adicionados ao sistema.
