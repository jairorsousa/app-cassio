# Integração com Google Agenda para cessão de requisitórios

Este documento descreve o processo para integrar o sistema Cassio Finance com o Google Agenda, criando um evento quando um requisitório entra na etapa `Cessão Pendente` e o usuário informa a data e hora da cessão.

## Objetivo

Quando o requisitório estiver na etapa `Cessão Pendente` (`stage = pending`) e o campo `cession_at` for preenchido, o sistema deve criar um evento no Google Agenda com os dados da cessão.

Fluxo esperado:

1. Usuário move o requisitório para `Cessão Pendente`.
2. Sistema solicita data e hora da cessão.
3. Usuário confirma a data.
4. Sistema salva `cession_at`.
5. Sistema cria ou atualiza o evento no Google Agenda.
6. Sistema salva o ID/link do evento para evitar duplicidade.

## Decisão principal: qual agenda será usada?

Antes de implementar, escolha um modelo.

### Opção recomendada: agenda central da empresa/escritório

Use uma agenda Google específica, por exemplo `cessoes@app...` ou uma agenda compartilhada chamada `Cessões de Requisitórios`.

Vantagens:

- O evento fica em uma agenda única, controlada pelo escritório.
- O sistema precisa ser autorizado uma vez por um administrador.
- Não depende de cada usuário conectar a própria conta Google.
- É mais simples para o fluxo atual do Cassio Finance.

### Opção alternativa: agenda pessoal de cada usuário

Cada usuário conecta sua conta Google e o evento é criado na agenda dele.

Use essa opção apenas se o evento precisar aparecer na agenda pessoal do usuário que opera o sistema.

Desvantagens:

- Cada usuário precisa passar pelo OAuth.
- O sistema precisa armazenar tokens por usuário.
- O suporte e a manutenção ficam mais chatos.

### Opção com service account

Use apenas se houver Google Workspace com administração do domínio, ou se for uma agenda compartilhada explicitamente com a conta de serviço.

Para Gmail pessoal comum, OAuth 2.0 com uma conta Google autorizando o app costuma ser o caminho mais previsível.

## Dados que precisamos definir antes

### Dados do Google

- Conta Google que será dona/autorizadora da agenda.
- Nome da agenda onde os eventos serão criados.
- `calendar_id` da agenda. Pode ser `primary` ou o ID/e-mail da agenda compartilhada.
- Tipo de conta: Gmail comum ou Google Workspace.
- Se o app ficará em modo `Testing` ou `Production` no Google Cloud.
- E-mails dos usuários de teste, caso o app OAuth fique em `Testing`.

### Dados técnicos

- URL local do sistema, por exemplo `http://localhost:8080`.
- URL de produção, por exemplo `https://app.seudominio.com.br`.
- URI de callback OAuth em produção, por exemplo:

```text
https://app.seudominio.com.br/google/calendar/callback
```

- URI de callback OAuth local, por exemplo:

```text
http://localhost:8080/google/calendar/callback
```

- Se o token será por sistema ou por usuário.
- Onde os tokens serão armazenados com segurança.

### Dados do evento

Defina estes campos de negócio:

- Título do evento.
  Exemplo: `Cessão - Requisitório {process_number}`.
- Duração padrão.
  Sugestão inicial: 60 minutos.
- Descrição do evento.
  Pode incluir número do processo, cedente, ente devedor, valores e link para o requisitório.
- Local da cessão.
  Pode ficar vazio, endereço físico ou link externo.
- Convidados.
  Exemplo: e-mail do responsável, cliente, cedente ou advogado.
- Se deve enviar e-mail para convidados.
  Sugestão: enviar somente quando houver convidados externos e isso fizer sentido operacional.
- Se deve criar Google Meet automaticamente.
- Lembretes.
  Exemplo: popup 30 minutos antes e e-mail 24 horas antes.

## Passo a passo no Google Cloud

### 1. Criar ou escolher um projeto

1. Acesse o Google Cloud Console.
2. Crie um projeto ou use um projeto existente.
3. Dê um nome claro, por exemplo `Cassio Finance`.

### 2. Ativar a Google Calendar API

1. No Google Cloud Console, abra `APIs & Services`.
2. Vá em `Library`.
3. Procure por `Google Calendar API`.
4. Clique em `Enable`.

### 3. Configurar a tela de consentimento OAuth

1. Acesse `APIs & Services > OAuth consent screen`.
2. Escolha o tipo adequado:
   - `Internal`, se for Google Workspace e somente usuários do domínio usarão.
   - `External`, se for Gmail comum ou usuários fora do domínio.
3. Preencha nome do app, e-mail de suporte e dados obrigatórios.
4. Adicione os escopos necessários.

Escopo recomendado para criar eventos:

```text
https://www.googleapis.com/auth/calendar.events
```

Esse escopo permite visualizar e editar eventos nas agendas acessíveis ao usuário autorizado. Se a agenda for sempre propriedade da conta autorizada, também é possível avaliar:

```text
https://www.googleapis.com/auth/calendar.events.owned
```

Evite usar `https://www.googleapis.com/auth/calendar` se não for necessário, porque ele dá permissão mais ampla.

### 4. Criar credenciais OAuth

1. Acesse `APIs & Services > Credentials`.
2. Clique em `Create credentials`.
3. Escolha `OAuth client ID`.
4. Tipo da aplicação: `Web application`.
5. Adicione as URIs de redirect autorizadas.

Exemplo local:

```text
http://localhost:8080/google/calendar/callback
```

Exemplo produção:

```text
https://app.seudominio.com.br/google/calendar/callback
```

6. Copie o `Client ID`.
7. Copie o `Client Secret`.

Esses dados entram no `.env`, nunca direto no código.

## Variáveis de ambiente sugeridas

Adicionar no `.env`:

```ini
GOOGLE_CALENDAR_ENABLED=true
GOOGLE_CALENDAR_CLIENT_ID=
GOOGLE_CALENDAR_CLIENT_SECRET=
GOOGLE_CALENDAR_REDIRECT_URI="${APP_URL}/google/calendar/callback"
GOOGLE_CALENDAR_ID=primary
GOOGLE_CALENDAR_TIMEZONE=America/Sao_Paulo
GOOGLE_CALENDAR_DEFAULT_DURATION_MINUTES=60
GOOGLE_CALENDAR_SEND_UPDATES=all
GOOGLE_CALENDAR_CREATE_MEET=false
```

Observação: em produção, prefira informar `GOOGLE_CALENDAR_REDIRECT_URI` explicitamente para evitar erro por diferença entre domínio, protocolo ou porta.

## Dependência PHP recomendada

O projeto é Laravel/PHP. A biblioteca oficial comum para consumir APIs Google em PHP é:

```bash
composer require google/apiclient
```

## Estrutura sugerida no Laravel

### Configuração

Criar `config/google-calendar.php` lendo as variáveis do `.env`.

### Rotas

Criar rotas protegidas por autenticação/admin:

```php
Route::get('/google/calendar/connect', [GoogleCalendarController::class, 'connect'])
    ->name('google.calendar.connect');

Route::get('/google/calendar/callback', [GoogleCalendarController::class, 'callback'])
    ->name('google.calendar.callback');

Route::post('/google/calendar/disconnect', [GoogleCalendarController::class, 'disconnect'])
    ->name('google.calendar.disconnect');
```

### Armazenamento dos tokens

Criar uma tabela para guardar o token da integração.

Campos sugeridos:

- `id`
- `provider`, valor `google_calendar`
- `user_id`, se o token for por usuário
- `access_token`, criptografado
- `refresh_token`, criptografado
- `expires_at`
- `scopes`
- `calendar_id`
- `created_at`
- `updated_at`

Importante: o refresh token precisa ser guardado de forma segura. Em Laravel, use criptografia/casts criptografados ou um modelo próprio que criptografe antes de salvar.

### Campos no requisitório

Hoje o modelo `Writ` já tem `cession_at`. Para sincronizar com a agenda sem criar eventos duplicados, adicionar:

- `google_calendar_event_id`
- `google_calendar_event_link`
- `google_calendar_synced_at`
- `google_calendar_sync_error`, opcional

## Regra de negócio

Criar evento quando:

- `stage = pending`
- `cession_at` estiver preenchido
- integração Google estiver ativa

Atualizar evento quando:

- `cession_at` mudar
- número do processo, cedente, ente devedor ou dados usados na descrição mudarem
- o evento já tiver `google_calendar_event_id`

Não criar outro evento se:

- o requisitório já tiver `google_calendar_event_id`

Cancelar ou excluir evento quando:

- a cessão for cancelada
- o requisitório sair de `Cessão Pendente` e a regra de negócio exigir remoção da agenda

Essa decisão precisa ser confirmada: em alguns fluxos é melhor manter o evento como histórico e apenas atualizar o título/status.

## Payload do evento

Exemplo de evento a ser enviado para a Google Calendar API:

```json
{
  "summary": "Cessão - Requisitório 0000000-00.0000.0.00.0000",
  "location": "",
  "description": "Requisitório: 0000000-00.0000.0.00.0000\nEtapa: Cessão Pendente\nEnte devedor: ...\nCedente: ...",
  "start": {
    "dateTime": "2026-06-16T14:00:00-03:00",
    "timeZone": "America/Sao_Paulo"
  },
  "end": {
    "dateTime": "2026-06-16T15:00:00-03:00",
    "timeZone": "America/Sao_Paulo"
  },
  "attendees": [
    {
      "email": "responsavel@example.com"
    }
  ],
  "reminders": {
    "useDefault": false,
    "overrides": [
      {
        "method": "popup",
        "minutes": 30
      },
      {
        "method": "email",
        "minutes": 1440
      }
    ]
  }
}
```

Para criar Google Meet, incluir `conferenceData` no evento e enviar a requisição com `conferenceDataVersion=1`.

## Ponto de integração no fluxo atual

No projeto, a etapa `Cessão Pendente` corresponde ao valor:

```text
pending
```

E a data/hora da cessão é salva em:

```text
cession_at
```

O ponto mais direto é após confirmar a data da cessão no fluxo do Kanban, onde hoje o sistema salva `cession_at` e move o card para `pending`.

Recomendação técnica:

1. Manter a action Livewire focada em salvar o requisitório.
2. Disparar um job assíncrono, por exemplo `SyncWritCessionToGoogleCalendar`.
3. O job cria/atualiza o evento.
4. O job grava `google_calendar_event_id`, `google_calendar_event_link` e erros, se houver.

Isso evita deixar a tela lenta caso o Google demore ou retorne erro temporário.

## Cuidados importantes

- Usar OAuth com `access_type=offline` para obter `refresh_token`.
- Guardar o `refresh_token` de forma criptografada.
- Não commitar `Client ID`, `Client Secret`, tokens ou arquivos JSON de credenciais.
- Salvar o ID do evento no requisitório para evitar duplicidade.
- Usar timezone `America/Sao_Paulo`.
- Tratar erro de token revogado pedindo reconexão com Google.
- Tratar falha temporária com retry via queue.
- Registrar logs sem expor tokens.
- Ter uma tela simples de status da integração: conectado, agenda usada, último erro e botão reconectar.

## Checklist de implementação

1. Confirmar qual agenda será usada: central ou por usuário.
2. Criar projeto no Google Cloud.
3. Ativar Google Calendar API.
4. Configurar OAuth consent screen.
5. Criar OAuth Client ID do tipo Web Application.
6. Cadastrar redirect URIs local e produção.
7. Adicionar variáveis no `.env`.
8. Instalar `google/apiclient`.
9. Criar `config/google-calendar.php`.
10. Criar tabela para tokens Google.
11. Criar controller de conexão OAuth.
12. Criar tela/botão administrativo para conectar a agenda.
13. Adicionar campos Google no model/tabela `writs`.
14. Criar serviço `GoogleCalendarService`.
15. Criar job `SyncWritCessionToGoogleCalendar`.
16. Disparar o job após salvar `cession_at`.
17. Testar criação do evento em ambiente local.
18. Testar atualização de data/hora sem duplicar evento.
19. Testar token expirado/revogado.
20. Publicar em produção e reconectar usando a URL final.

## Perguntas que preciso que você responda

Para implementar com segurança, preciso destas decisões:

1. O evento vai para uma agenda central ou para a agenda do usuário logado?
2. Qual conta Google será usada para autorizar a integração?
3. Qual é o `calendar_id` da agenda?
4. Qual deve ser a duração padrão da cessão?
5. Deve criar link do Google Meet automaticamente?
6. Deve convidar alguém? Se sim, quais e-mails vêm do cadastro e quais são fixos?
7. Deve enviar notificação por e-mail aos convidados?
8. Se a data da cessão mudar, o evento deve ser atualizado?
9. Se o requisitório sair de `Cessão Pendente`, o evento deve ser mantido, cancelado ou excluído?
10. O título e a descrição do evento devem conter quais dados do requisitório?

## Fontes oficiais consultadas

- Google Calendar API: criar eventos: https://developers.google.com/workspace/calendar/api/guides/create-events
- Google Calendar API: `events.insert`: https://developers.google.com/workspace/calendar/api/v3/reference/events/insert
- Google Calendar API: escopos OAuth: https://developers.google.com/workspace/calendar/api/auth
- Google OAuth 2.0 para aplicações web server: https://developers.google.com/identity/protocols/oauth2/web-server
- Configuração da tela de consentimento OAuth: https://developers.google.com/workspace/guides/configure-oauth-consent
