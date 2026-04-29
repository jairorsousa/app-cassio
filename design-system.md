# Amber Design System

> Documentação de tokens, componentes e padrões visuais com paleta amarelo âmbar (`#f5b800`) — adaptado para sistemas web profissionais.

---

## Sumário

1. [Cores](#1-cores)
2. [Tipografia](#2-tipografia)
3. [Espaçamentos](#3-espaçamentos)
4. [Border Radius](#4-border-radius)
5. [Sombras](#5-sombras)
6. [Botões](#6-botões)
7. [Inputs de Formulário](#7-inputs-de-formulário)
8. [Badges & Notificações](#8-badges--notificações)
9. [Alertas](#9-alertas)
10. [Tabela](#10-tabela)
11. [Category Pills](#11-category-pills)
12. [Menu Lateral](#12-menu-lateral)
13. [Toggle, Progress e Cards](#13-toggle-progress-e-cards)
14. [Tema Escuro](#14-tema-escuro)
15. [Variáveis CSS — Copiar e Colar](#15-variáveis-css--copiar-e-colar)

---

## 1. Cores

### Primária — Amarelo Âmbar

Tom escolhido por ser equilibrado para uso prolongado em telas. Não é vibrante o suficiente para causar fadiga visual, mas mantém presença suficiente para call-to-actions.

| Token | Hex | Uso |
|---|---|---|
| `--colors-primary-g100` | `#fff8e1` | Fundo de itens ativos no menu, hover suave |
| `--colors-primary-g500` | `#f5b800` | Cor principal — botões, focus rings, progress |
| `--colors-primary-g600` | `#d99e00` | Hover/pressed, links, badges textuais |

### Monocromáticas

| Token | Hex | Uso típico |
|---|---|---|
| `--colors-monochromatic-white` | `#ffffff` | Fundo de cards |
| `--colors-monochromatic-g50` | `#f5f6f7` | Fundo de tabela header, hover de linha |
| `--colors-monochromatic-g100` | `#ecedef` | Bordas suaves, dividers |
| `--colors-monochromatic-g200` | `#d5d7da` | Bordas de inputs |
| `--colors-monochromatic-g300` | `#b2b7bb` | Ícones inativos, placeholder |
| `--colors-monochromatic-g600` | `#8d959d` | Texto secundário, labels |
| `--colors-monochromatic-g900` | `#212529` | Texto principal |
| `--colors-monochromatic-black` | `#212427` | Header, fundos escuros |

### Sistema

| Token | Hex | Uso |
|---|---|---|
| `--colors-system-up` | `#15a96f` | Variação positiva (gráficos, badges) |
| `--colors-system-success` | `#1cc97d` | Validação de sucesso em forms |
| `--colors-system-success-bg` | `#e8f3ea` | Fundo de alertas de sucesso |
| `--colors-system-down` | `#e43b3b` | Variação negativa |
| `--colors-system-error` | `#ff4747` | Erros de validação |

---

## 2. Tipografia

**Família:** `Reddit Sans` (carregada via Google Fonts).
**Fallback:** `-apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif`.

| Escala | Tamanho | Peso | Aplicação |
|---|---|---|---|
| XXL / H1 | 32px (`2rem`) | Bold (700) | Saldo total, hero |
| XL / H2 | 24px (`1.5rem`) | Bold (700) | Títulos de página |
| LG / H3 | 20px (`1.25rem`) | SemiBold (600) | Títulos de seção |
| MD | 18px (`1.125rem`) | Medium (500) | Subtítulos |
| SM / Body | 16px (`1rem`) | Medium (500) | Corpo de texto principal |
| XS / Small | 14px (`0.875rem`) | Regular (400) | Texto auxiliar, body em UI densa |
| XXS / Caption | 10–12px (`0.625–0.75rem`) | SemiBold (600) | Labels, captions, meta-info |

**Pesos disponíveis:** 300 (light), 400 (regular), 500 (medium), 600 (semibold), 700 (bold), 800 (extrabold).

**Line-heights:** `1.2` (sm), `1.4` (md, padrão), `1.6` (lg).

---

## 3. Espaçamentos

Escala baseada em múltiplos de 4px.

| Token | Valor |
|---|---|
| `--spacings-xxxs` | 4px |
| `--spacings-xxs` | 8px |
| `--spacings-xs` | 12px |
| `--spacings-sm` | 16px |
| `--spacings-md` | 20px |
| `--spacings-lg` | 24px |
| `--spacings-xl` | 32px |
| `--spacings-xxl` | 40px |

---

## 4. Border Radius

| Token | Valor | Uso |
|---|---|---|
| `--borders-radius-xs` | 4px | Inputs internos, badges pequenos |
| `--borders-radius-sm` | 8px | Code blocks, alerts |
| `--borders-radius-md` | 12px | Color swatches, alerts grandes |
| `--borders-radius-lg` | 16px | Cards, tabelas, sidebars |
| `--borders-radius-xl` | 20px | Modais |
| `--borders-radius-xxl` | 999px | Pills (botões, inputs, badges, tags) |

---

## 5. Sombras

```css
--shadow-card: 0 2px 8px rgba(0,0,0,.06);
--shadow-elevated: 0 8px 32px rgba(0,0,0,.12);
--shadow-dropdown: 0 4px 20px 0 hsla(0,0%,54%,.16), 0 4px 20px 0 rgba(0,0,0,.1);
```

**Card:** sombra padrão para cards e tabelas.
**Elevated:** modais, login cards, popovers principais.
**Dropdown:** menus suspensos, dropdowns.

---

## 6. Botões

Todos os botões usam o padrão **pill** (`border-radius: 999px`) com altura padrão de **44px** e small de **36px**.

### Variantes

| Classe | Comportamento |
|---|---|
| `.fx-btn--primary` | Fundo amarelo, texto escuro (g900). **Texto escuro é obrigatório** para passar em WCAG AA sobre amarelo |
| `.fx-btn--standard` | Outline cinza, texto escuro. Ações secundárias |
| `.fx-btn--mono` | Fundo cinza claro (g100). Ações terciárias |
| `.fx-btn--text` | Sem fundo. Ações tipo "Marcar como lido" |
| `.fx-btn--icon` | Apenas ícone, 40x40, circular |

### Modificadores

- `.fx-btn--sm` — altura 36px

### Exemplo

```html
<button class="fx-btn fx-btn--primary">Depositar reais</button>
<button class="fx-btn fx-btn--standard">Depositar</button>
<button class="fx-btn fx-btn--primary fx-btn--sm">Confirmar</button>
```

```css
.fx-btn--primary {
  background: var(--colors-primary-g500);
  color: var(--colors-monochromatic-g900); /* texto escuro */
  height: 44px;
  padding: 0 24px;
  border-radius: 999px;
  font-weight: 600;
}
.fx-btn--primary:hover {
  background: var(--colors-primary-g600);
}
```

---

## 7. Inputs de Formulário

Padrão pill com altura **48px**, ícone à esquerda e ícone de status opcional à direita.

### Estrutura

```html
<div class="fx-form-field">
  <span class="fx-field-icon">[SVG]</span>
  <input type="email" placeholder="E-mail">
  <span class="fx-field-status fx-field-status--success">[SVG]</span>
</div>
```

### Estados

| Modificador | Aplicação |
|---|---|
| `.fx-form-field--success` | Borda verde — validação OK |
| `.fx-form-field--error` | Borda vermelha — erro de validação |
| `.fx-form-field--disabled` | Opacidade 0.6, sem interação |

### Comportamento

- **Hover:** borda escurece para `g300`.
- **Focus-within:** borda muda para amarelo primário, com glow `rgba(245,184,0,.15)`.
- **Ícone esquerdo no focus:** muda do cinza para `g600` (amarelo escuro).

### Helper text

```html
<div class="fx-form-helper">Texto de ajuda neutro</div>
<div class="fx-form-helper fx-form-helper--error">E-mail inválido</div>
```

### Link sob o campo

```html
<a class="fx-form-link" href="#">Esqueceu a senha?</a>
```

Cor: `--colors-primary-g600` (mais escuro que o primário, melhor contraste em texto pequeno).

---

## 8. Badges & Notificações

### Badge de variação

```html
<span class="fx-badge fx-badge--up">▲ 4,22%</span>
<span class="fx-badge fx-badge--down">▼ 3,46%</span>
<span class="fx-badge fx-badge--neutral">0,00%</span>
```

### Notification badge (contador)

```html
<span class="fx-notif-badge">10</span>
```

Pílula vermelha (`#ff4747`) com texto branco. Usar sobre ícones de sino, menu de notificações, etc.

---

## 9. Alertas

```html
<div class="fx-alert fx-alert--error">[ícone] Mensagem [×]</div>
<div class="fx-alert fx-alert--success">[ícone] Mensagem [×]</div>
<div class="fx-alert fx-alert--info">[ícone] Mensagem</div>
```

| Modificador | Cor de fundo | Cor de texto |
|---|---|---|
| `--error` | `#fdeaea` | `#ff4747` |
| `--success` | `#e8f3ea` | `#1cc97d` |
| `--info` | `#e8f0fe` | `#1a73e8` |

Botão `.fx-alert-close` opcional para alertas dispensáveis.

---

## 10. Tabela

Tabelas têm cantos arredondados (`16px`), header em `g50`, e hover de linha em `g50`.

```html
<table class="fx-table">
  <thead>
    <tr>
      <th>Ativo</th>
      <th>Preço</th>
      <th>Saldo</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>
        <div class="coin-cell">
          <img class="coin-icon" src="..." alt="">
          <span>Bitcoin</span>
        </div>
      </td>
      <td>R$ 347.872 <span class="fx-badge fx-badge--down">▼ 3,46%</span></td>
      <td>BTC 0,00000000</td>
    </tr>
  </tbody>
</table>
```

**Notas:**
- Header: 12px, semibold, cor `g600`, fundo `g50`.
- Body: 14px, cor `g900`.
- Bordas inferiores: 1px sólido `g100`. Última linha sem borda.

---

## 11. Category Pills

Filtros tipo pill com estado ativo amarelo.

```html
<div class="fx-pills">
  <button class="fx-pill fx-pill--active">Todas</button>
  <button class="fx-pill">Cripto</button>
  <button class="fx-pill">Stablecoin</button>
</div>
```

- **Padrão:** fundo branco, borda `g200`.
- **Hover:** borda amarela, texto `g600`.
- **Ativo:** fundo amarelo, texto escuro, borda amarela.

---

## 12. Menu Lateral

```html
<a class="fx-menu-item fx-menu-item--active" href="#">
  <span class="fx-menu-icon">🏠</span> Início
</a>
<a class="fx-menu-item" href="#">
  <span class="fx-menu-icon">↕️</span> Depositar | Sacar
</a>
```

| Estado | Cor de fundo | Cor de texto |
|---|---|---|
| Padrão | transparente | `g900` |
| Hover | `g100` | `g900` |
| Ativo | `primary-g100` (`#fff8e1`) | `primary-g600` (`#d99e00`) |

Divider entre grupos de itens: `<hr class="fx-divider">`.

---

## 13. Toggle, Progress e Cards

### Toggle Switch

```html
<input type="checkbox" class="fx-toggle">
```

50x24px, OFF cinza (`g300`), ON amarelo. Bolinha branca de 20px desliza 26px no eixo X.

### Progress Bar

```html
<div class="fx-progress">
  <div class="fx-progress-bar" style="width:35%"></div>
</div>
```

Altura 8px, fundo `g100`, preenchimento amarelo. Transição de 400ms.

### Card

```html
<div class="fx-card">
  <div class="fx-card-title">Título</div>
  <p>Conteúdo</p>
</div>
```

Padding 24px, radius 16px, sombra card, borda 1px `g100`.

### Highlight Card (lista)

Linha clicável com avatar circular, nome e badge. Hover muda fundo para `g50`.

```html
<div class="fx-highlight-card">
  <img src="...">
  <div style="flex:1">
    <div>Nome</div>
    <div>ticker</div>
  </div>
  <span class="fx-badge fx-badge--up">▲ 4,84%</span>
</div>
```

### Collapsible / Accordion

Usa `<details>` nativo do HTML.

```html
<details class="fx-collapsible" open>
  <summary>Carteira <span class="chevron">▼</span></summary>
  <div class="fx-collapsible-content">...</div>
</details>
```

A chevron rotaciona 180° quando aberto.

---

## 14. Tema Escuro

Aplique `data-theme="dark"` em qualquer container raiz para ativar:

```html
<html data-theme="dark">
```

Tokens monocromáticos são invertidos automaticamente:

```css
[data-theme="dark"] {
  --colors-monochromatic-white: #1a1d21;
  --colors-monochromatic-black: #f5f6f7;
  --colors-monochromatic-g50: #22262b;
  --colors-monochromatic-g100: #2c3138;
  --colors-monochromatic-g200: #3a4049;
  --colors-monochromatic-g900: #f5f6f7;
  --colors-custom-background1: #1a1d21;
  --shadow-card: 0 2px 8px rgba(0,0,0,.2);
}
```

A paleta primária amarela permanece igual em ambos os temas — funciona bem sobre fundos claros e escuros.

---

## 15. Variáveis CSS — Copiar e Colar

```css
:root {
  /* === CORES PRIMÁRIAS (Amarelo Âmbar) === */
  --colors-primary-g100: #fff8e1;
  --colors-primary-g500: #f5b800;
  --colors-primary-g600: #d99e00;

  /* === MONOCROMÁTICAS === */
  --colors-monochromatic-white: #ffffff;
  --colors-monochromatic-black: #212427;
  --colors-monochromatic-g50: #f5f6f7;
  --colors-monochromatic-g100: #ecedef;
  --colors-monochromatic-g200: #d5d7da;
  --colors-monochromatic-g300: #b2b7bb;
  --colors-monochromatic-g600: #8d959d;
  --colors-monochromatic-g900: #212529;

  /* === SISTEMA === */
  --colors-system-up: #15a96f;
  --colors-system-down: #e43b3b;
  --colors-system-success: #1cc97d;
  --colors-system-success-bg: #e8f3ea;
  --colors-system-error: #ff4747;
  --colors-system-error-light: #e43b3b;

  /* === TIPOGRAFIA === */
  --font-family: 'Reddit Sans', -apple-system, BlinkMacSystemFont, sans-serif;
  --font-size-xxs: 0.625rem;   /* 10px */
  --font-size-xs:  0.875rem;   /* 14px */
  --font-size-sm:  1rem;       /* 16px */
  --font-size-md:  1.125rem;   /* 18px */
  --font-size-lg:  1.25rem;    /* 20px */
  --font-size-xl:  1.5rem;     /* 24px */
  --font-size-xxl: 2rem;       /* 32px */

  --font-weight-light:    300;
  --font-weight-regular:  400;
  --font-weight-medium:   500;
  --font-weight-semibold: 600;
  --font-weight-bold:     700;

  --font-line-height-sm: 1.2;
  --font-line-height-md: 1.4;
  --font-line-height-lg: 1.6;

  /* === ESPAÇAMENTOS === */
  --spacings-xxxs: 0.25rem;  /* 4px */
  --spacings-xxs:  0.5rem;   /* 8px */
  --spacings-xs:   0.75rem;  /* 12px */
  --spacings-sm:   1rem;     /* 16px */
  --spacings-md:   1.25rem;  /* 20px */
  --spacings-lg:   1.5rem;   /* 24px */
  --spacings-xl:   2rem;     /* 32px */
  --spacings-xxl:  2.5rem;   /* 40px */

  /* === BORDER RADIUS === */
  --borders-radius-xs:  4px;
  --borders-radius-sm:  8px;
  --borders-radius-md:  12px;
  --borders-radius-lg:  16px;
  --borders-radius-xl:  20px;
  --borders-radius-xxl: 999px;

  /* === SOMBRAS === */
  --shadow-card:     0 2px 8px rgba(0,0,0,.06);
  --shadow-elevated: 0 8px 32px rgba(0,0,0,.12);
  --shadow-dropdown: 0 4px 20px 0 hsla(0,0%,54%,.16), 0 4px 20px 0 rgba(0,0,0,.1);

  /* === Z-INDEX === */
  --z-index-dropdown: 100;
  --z-index-modal: 1000;
}
```

---

## Notas de Acessibilidade

- **Botão primário:** texto escuro sobre amarelo. Branco sobre amarelo **não passa** em WCAG AA.
- **Links em texto pequeno:** use `--colors-primary-g600` (`#d99e00`) em vez do `g500` para garantir legibilidade.
- **Focus rings:** todos os campos interativos têm glow visível (`box-shadow` 3px de espessura) para usuários de teclado.
- **Estados de erro:** combinam cor + ícone, nunca cor sozinha.
