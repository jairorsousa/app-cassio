<?php

// Login com Google (somente usuários já cadastrados). Reaproveita o cliente OAuth
// da Agenda quando GOOGLE_AUTH_* não estiver definido.
return [
    'enabled' => env('GOOGLE_AUTH_ENABLED', true),
    'client_id' => env('GOOGLE_AUTH_CLIENT_ID') ?: env('GOOGLE_CALENDAR_CLIENT_ID'),
    'client_secret' => env('GOOGLE_AUTH_CLIENT_SECRET') ?: env('GOOGLE_CALENDAR_CLIENT_SECRET'),
    'redirect_uri' => env('GOOGLE_AUTH_REDIRECT_URI') ?: rtrim((string) env('APP_URL'), '/').'/login/google/callback',
];
