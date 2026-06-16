<?php

$fixedAttendees = array_filter(array_map(
    static fn (string $email): string => trim($email),
    explode(',', (string) env('GOOGLE_CALENDAR_FIXED_ATTENDEES', ''))
));

return [
    'enabled' => env('GOOGLE_CALENDAR_ENABLED', false),

    'client_id' => env('GOOGLE_CALENDAR_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CALENDAR_CLIENT_SECRET'),
    'redirect_uri' => env('GOOGLE_CALENDAR_REDIRECT_URI', rtrim((string) env('APP_URL'), '/').'/google/calendar/callback'),

    'calendar_id' => env('GOOGLE_CALENDAR_ID', 'primary'),
    'timezone' => env('GOOGLE_CALENDAR_TIMEZONE', env('APP_TIMEZONE', 'America/Sao_Paulo')),
    'default_duration_minutes' => (int) env('GOOGLE_CALENDAR_DEFAULT_DURATION_MINUTES', 60),
    'send_updates' => env('GOOGLE_CALENDAR_SEND_UPDATES', 'all'),
    'create_meet' => env('GOOGLE_CALENDAR_CREATE_MEET', false),
    'fixed_attendees' => array_values($fixedAttendees),

    'scopes' => [
        'https://www.googleapis.com/auth/calendar.events',
    ],
];
