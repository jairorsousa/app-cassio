<?php

namespace App\Domains\Integrations\Services;

use App\Domains\Integrations\Models\GoogleCalendarToken;
use App\Domains\Writs\Models\Writ;
use Carbon\CarbonInterface;
use Google\Client;
use Google\Service\Calendar as GoogleCalendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventAttendee;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GoogleCalendarService
{
    public function makeOAuthClient(): Client
    {
        $clientId = config('google-calendar.client_id');
        $clientSecret = config('google-calendar.client_secret');
        $redirectUri = config('google-calendar.redirect_uri');

        if (! $clientId || ! $clientSecret || ! $redirectUri) {
            throw new RuntimeException('Credenciais do Google Calendar incompletas.');
        }

        $client = new Client;
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($redirectUri);
        $client->setScopes(config('google-calendar.scopes', []));
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);

        return $client;
    }

    public function authorizationUrl(): string
    {
        return $this->makeOAuthClient()->createAuthUrl();
    }

    public function storeTokenFromAuthCode(string $authCode, ?int $connectedByUserId = null): GoogleCalendarToken
    {
        $client = $this->makeOAuthClient();
        $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);

        if (isset($accessToken['error'])) {
            $message = $accessToken['error_description'] ?? $accessToken['error'];
            throw new RuntimeException('Falha ao autorizar Google Calendar: '.$message);
        }

        $existing = GoogleCalendarToken::central();
        $refreshToken = $accessToken['refresh_token'] ?? $existing?->refresh_token;

        if (! $refreshToken) {
            throw new RuntimeException('O Google nao retornou refresh_token. Revogue o acesso do app na conta Google e conecte novamente.');
        }

        return GoogleCalendarToken::query()->updateOrCreate(
            ['provider' => 'google_calendar'],
            [
                'calendar_id' => config('google-calendar.calendar_id', 'primary'),
                'access_token' => $accessToken['access_token'] ?? $existing?->access_token,
                'refresh_token' => $refreshToken,
                'expires_at' => $this->expiresAt($accessToken),
                'scopes' => $this->scopesFromToken($accessToken),
                'connected_by_user_id' => $connectedByUserId,
                'connected_at' => now(),
            ],
        );
    }

    public function syncWritCession(Writ $writ): ?Event
    {
        if (! config('google-calendar.enabled')) {
            return null;
        }

        if ($writ->stage !== 'pending' || ! $writ->cession_at) {
            return null;
        }

        try {
            $token = GoogleCalendarToken::central();

            if (! $token) {
                $writ->forceFill([
                    'google_calendar_sync_error' => 'Google Calendar ainda nao foi conectado.',
                ])->save();

                return null;
            }

            $calendar = new GoogleCalendar($this->authorizedClient($token));
            $calendarId = $token->calendar_id ?: config('google-calendar.calendar_id', 'primary');
            $event = $this->buildCessionEvent($writ);
            $params = $this->eventRequestParams();

            if ($writ->google_calendar_event_id) {
                try {
                    $syncedEvent = $calendar->events->update(
                        $calendarId,
                        $writ->google_calendar_event_id,
                        $event,
                        $params,
                    );
                } catch (GoogleServiceException $exception) {
                    if ($exception->getCode() !== 404) {
                        throw $exception;
                    }

                    $syncedEvent = $calendar->events->insert($calendarId, $event, $params);
                }
            } else {
                $syncedEvent = $calendar->events->insert($calendarId, $event, $params);
            }

            $writ->forceFill([
                'google_calendar_event_id' => $syncedEvent->getId(),
                'google_calendar_event_link' => $syncedEvent->getHtmlLink(),
                'google_calendar_synced_at' => now(),
                'google_calendar_sync_error' => null,
            ])->save();

            return $syncedEvent;
        } catch (Throwable $exception) {
            $writ->forceFill([
                'google_calendar_sync_error' => Str::limit($exception->getMessage(), 2000, ''),
            ])->save();

            throw $exception;
        }
    }

    private function authorizedClient(GoogleCalendarToken $token): Client
    {
        $client = $this->makeOAuthClient();
        $client->setAccessToken([
            'access_token' => $token->access_token,
            'refresh_token' => $token->refresh_token,
            'expires_in' => $token->expires_at ? (int) max(60, now()->diffInSeconds($token->expires_at, false)) : 60,
            'created' => now()->timestamp,
        ]);

        if (! $client->isAccessTokenExpired()) {
            return $client;
        }

        $refreshedToken = $client->fetchAccessTokenWithRefreshToken($token->refresh_token);

        if (isset($refreshedToken['error'])) {
            $message = $refreshedToken['error_description'] ?? $refreshedToken['error'];
            throw new RuntimeException('Falha ao renovar token do Google Calendar: '.$message);
        }

        $token->forceFill([
            'access_token' => $refreshedToken['access_token'] ?? $token->access_token,
            'refresh_token' => $refreshedToken['refresh_token'] ?? $token->refresh_token,
            'expires_at' => $this->expiresAt($refreshedToken),
            'scopes' => $this->scopesFromToken($refreshedToken, $token->scopes ?? []),
        ])->save();

        $client->setAccessToken([
            'access_token' => $token->access_token,
            'refresh_token' => $token->refresh_token,
            'expires_in' => (int) max(60, now()->diffInSeconds($token->expires_at, false)),
            'created' => now()->timestamp,
        ]);

        return $client;
    }

    private function buildCessionEvent(Writ $writ): Event
    {
        $timezone = config('google-calendar.timezone', 'America/Sao_Paulo');
        $start = $writ->cession_at->copy()->timezone($timezone);
        $end = $start->copy()->addMinutes(max(15, (int) config('google-calendar.default_duration_minutes', 60)));

        $event = new Event([
            'summary' => $this->eventSummary($writ),
            'description' => $this->eventDescription($writ),
            'start' => $this->eventDateTime($start, $timezone),
            'end' => $this->eventDateTime($end, $timezone),
            'attendees' => $this->eventAttendees(),
            'reminders' => [
                'useDefault' => false,
                'overrides' => [
                    ['method' => 'popup', 'minutes' => 30],
                    ['method' => 'email', 'minutes' => 1440],
                ],
            ],
        ]);

        if (config('google-calendar.create_meet')) {
            $event->setConferenceData([
                'createRequest' => [
                    'requestId' => 'writ-'.$writ->id.'-cession',
                ],
            ]);
        }

        return $event;
    }

    private function eventDateTime(CarbonInterface $dateTime, string $timezone): EventDateTime
    {
        return new EventDateTime([
            'dateTime' => $dateTime->toRfc3339String(),
            'timeZone' => $timezone,
        ]);
    }

    private function eventSummary(Writ $writ): string
    {
        $identifier = $writ->process_number ?: '#'.$writ->id;

        return 'Cessao - Requisitorio '.$identifier;
    }

    private function eventDescription(Writ $writ): string
    {
        $lines = [
            'Requisitorio: '.($writ->process_number ?: '#'.$writ->id),
            'Etapa: '.$writ->stageLabel(),
            'Ente devedor: '.($writ->debtor_entity ?: '-'),
            'Cedente: '.($writ->assignor_name ?: '-'),
            'Valor do requisitorio: R$ '.number_format((float) $writ->face_value, 2, ',', '.'),
        ];

        if ($writ->notes) {
            $lines[] = '';
            $lines[] = 'Observacoes: '.$writ->notes;
        }

        $lines[] = '';
        $lines[] = 'Link no sistema: '.URL::route('writs.show', $writ);

        return implode("\n", $lines);
    }

    /**
     * @return array<int, EventAttendee>
     */
    private function eventAttendees(): array
    {
        return collect(config('google-calendar.fixed_attendees', []))
            ->filter()
            ->unique()
            ->map(fn (string $email): EventAttendee => new EventAttendee(['email' => $email]))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function eventRequestParams(): array
    {
        $params = [
            'sendUpdates' => config('google-calendar.send_updates', 'all'),
        ];

        if (config('google-calendar.create_meet')) {
            $params['conferenceDataVersion'] = 1;
        }

        return $params;
    }

    /**
     * @param  array<string, mixed>  $token
     */
    private function expiresAt(array $token): ?Carbon
    {
        if (! isset($token['expires_in'])) {
            return null;
        }

        return now()->addSeconds(max(60, ((int) $token['expires_in']) - 60));
    }

    /**
     * @param  array<string, mixed>  $token
     * @param  array<int, string>  $fallback
     * @return array<int, string>
     */
    private function scopesFromToken(array $token, array $fallback = []): array
    {
        if (isset($token['scope'])) {
            return array_values(array_filter(explode(' ', (string) $token['scope'])));
        }

        return $fallback ?: config('google-calendar.scopes', []);
    }
}
