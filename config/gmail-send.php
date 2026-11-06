<?php

/**
 * OAuth Gmail send — só envio de convites (admin id 1).
 * Reaproveita o client_id/secret da Agenda quando GMAIL_SEND_* não existir.
 * Escopos de Calendar e Login NÃO são alterados; include_granted_scopes=false.
 */
return [
    'enabled' => env('GMAIL_SEND_ENABLED', true),

    'client_id' => env('GMAIL_SEND_CLIENT_ID') ?: env('GOOGLE_CALENDAR_CLIENT_ID'),
    'client_secret' => env('GMAIL_SEND_CLIENT_SECRET') ?: env('GOOGLE_CALENDAR_CLIENT_SECRET'),
    'redirect_uri' => env('GMAIL_SEND_REDIRECT_URI')
        ?: rtrim((string) env('APP_URL'), '/').'/configuracoes/email/google/callback',

    'allowed_account' => 'cassio@cassiomota.com',

    // openid+email só para verificar a conta; gmail.send é o único escopo de correio.
    'scopes' => [
        'openid',
        'email',
        'https://www.googleapis.com/auth/gmail.send',
    ],

    'required_mail_scope' => 'https://www.googleapis.com/auth/gmail.send',

    'forbidden_mail_scopes' => [
        'https://mail.google.com/',
        'https://www.googleapis.com/auth/gmail.modify',
        'https://www.googleapis.com/auth/gmail.compose',
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/gmail.metadata',
        'https://www.googleapis.com/auth/gmail.insert',
        'https://www.googleapis.com/auth/gmail.settings.basic',
        'https://www.googleapis.com/auth/gmail.settings.sharing',
    ],
];
