<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'chatwoot' => [
        'base_url' => env('CHATWOOT_BASE_URL', 'https://msa.vozconecta.com.br'),
        'account_id' => env('CHATWOOT_ACCOUNT_ID', 1),
        'api_access_token' => env('CHATWOOT_API_ACCESS_TOKEN'),
        'inbox_id' => env('CHATWOOT_INBOX_ID', 15),
        'assignee_id' => env('CHATWOOT_ASSIGNEE_ID', 4),
        // Token legado/local (header ou ?token=). Opcional se webhook_secret estiver preenchido.
        'webhook_token' => env('CHATWOOT_WEBHOOK_TOKEN'),
        // Secret do webhook "Transmissão - SAIR" (UI Chatwoot → editar webhook). Assinatura X-Chatwoot-Signature.
        // Vazio = modo assinatura desligado. Nunca logar. Não usar token do webhook "labels".
        'webhook_secret' => env('CHATWOOT_WEBHOOK_SECRET'),
        // Tolerância do X-Chatwoot-Timestamp em segundos (0 = não checa idade). Padrão 5 min.
        'webhook_signature_tolerance' => (int) env('CHATWOOT_WEBHOOK_SIGNATURE_TOLERANCE', 300),
        'webhook_optout_per_minute' => (int) env('CHATWOOT_WEBHOOK_OPTOUT_PER_MINUTE', 30),
        // Inbox permitida para SAIR (fail-closed se vazio ou divergente). CMS-6000 conta 1 = 15.
        'opt_out_inbox_id' => (int) env('CHATWOOT_OPT_OUT_INBOX_ID', 0),
    ],

    // IA opcional para SUGERIR mapeamento de extratos (envia cabeçalho + 5 linhas). Vazio = desligado, nada é enviado.
    'statement_ai' => [
        'provider' => env('STATEMENT_AI_PROVIDER'),
        'url' => env('STATEMENT_AI_URL'),
        'key' => env('STATEMENT_AI_KEY'),
        'model' => env('STATEMENT_AI_MODEL'),
    ],

    'brapi' => [
        'token' => env('BRAPI_TOKEN'),
        'base_url' => env('BRAPI_BASE_URL', 'https://brapi.dev'),
        'timeout' => (int) env('BRAPI_TIMEOUT', 8),
    ],

];
