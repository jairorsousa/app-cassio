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
use Illuminate\Support\Facades\Log;
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
            $writ->forceFill([
                'google_calendar_sync_error' => 'Google Calendar esta desativado no ambiente.',
            ])->save();

            return null;
        }

        if (! $writ->cession_at) {
            return null;
        }

        return $this->syncCalendarEvent(
            writ: $writ,
            event: $this->buildCessionEvent($writ),
            existingEventId: $writ->google_calendar_event_id,
            columns: [
                'event_id' => 'google_calendar_event_id',
                'event_link' => 'google_calendar_event_link',
                'synced_at' => 'google_calendar_synced_at',
                'sync_error' => 'google_calendar_sync_error',
            ],
        );
    }

    public function syncWritPetition(Writ $writ): ?Event
    {
        if (! config('google-calendar.enabled')) {
            $writ->forceFill([
                'google_calendar_petition_sync_error' => 'Google Calendar esta desativado no ambiente.',
            ])->save();

            return null;
        }

        if (! $writ->petitioned_at) {
            return null;
        }

        return $this->syncCalendarEvent(
            writ: $writ,
            event: $this->buildPetitionEvent($writ),
            existingEventId: $writ->google_calendar_petition_event_id,
            columns: [
                'event_id' => 'google_calendar_petition_event_id',
                'event_link' => 'google_calendar_petition_event_link',
                'synced_at' => 'google_calendar_petition_synced_at',
                'sync_error' => 'google_calendar_petition_sync_error',
            ],
        );
    }

    public function syncWritAwaitingReceipt(Writ $writ): ?Event
    {
        if (! config('google-calendar.enabled')) {
            $writ->forceFill([
                'google_calendar_awaiting_receipt_sync_error' => 'Google Calendar esta desativado no ambiente.',
            ])->save();

            return null;
        }

        if (! $writ->awaiting_receipt_at) {
            return null;
        }

        return $this->syncCalendarEvent(
            writ: $writ,
            event: $this->buildAwaitingReceiptEvent($writ),
            existingEventId: $writ->google_calendar_awaiting_receipt_event_id,
            columns: [
                'event_id' => 'google_calendar_awaiting_receipt_event_id',
                'event_link' => 'google_calendar_awaiting_receipt_event_link',
                'synced_at' => 'google_calendar_awaiting_receipt_synced_at',
                'sync_error' => 'google_calendar_awaiting_receipt_sync_error',
            ],
        );
    }

    /**
     * @param  array{event_id: string, event_link: string, synced_at: string, sync_error: string}  $columns
     */
    private function syncCalendarEvent(Writ $writ, Event $event, ?string $existingEventId, array $columns): ?Event
    {
        try {
            $token = GoogleCalendarToken::central();

            if (! $token) {
                $writ->forceFill([
                    $columns['sync_error'] => 'Google Calendar ainda nao foi conectado.',
                ])->save();

                return null;
            }

            $calendar = new GoogleCalendar($this->authorizedClient($token));
            $calendarId = $token->calendar_id ?: config('google-calendar.calendar_id', 'primary');
            $params = $this->eventRequestParams(forUpdate: $existingEventId !== null);

            if ($existingEventId) {
                try {
                    $syncedEvent = $calendar->events->update(
                        $calendarId,
                        $existingEventId,
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
                $columns['event_id'] => $syncedEvent->getId(),
                $columns['event_link'] => $syncedEvent->getHtmlLink(),
                $columns['synced_at'] => now(),
                $columns['sync_error'] => null,
            ])->save();

            return $syncedEvent;
        } catch (Throwable $exception) {
            $writ->forceFill([
                $columns['sync_error'] => Str::limit($exception->getMessage(), 2000, ''),
            ])->save();

            Log::error('Google Calendar sync failed for writ #'.$writ->id, [
                'event_id_column' => $columns['event_id'],
                'existing_event_id' => $existingEventId,
                'exception' => $exception,
            ]);

            return null;
        }
    }

    private function tokenNeedsRefresh(GoogleCalendarToken $token): bool
    {
        if (! $token->expires_at) {
            return true;
        }

        return $token->expires_at->lte(now()->addMinute());
    }

    private function authorizedClient(GoogleCalendarToken $token): Client
    {
        $client = $this->makeOAuthClient();

        if (! $this->tokenNeedsRefresh($token)) {
            $client->setAccessToken([
                'access_token' => $token->access_token,
                'refresh_token' => $token->refresh_token,
                'expires_in' => (int) max(1, now()->diffInSeconds($token->expires_at)),
                'created' => now()->timestamp,
            ]);

            return $client;
        }

        if (! $token->refresh_token) {
            throw new RuntimeException('Refresh token do Google Calendar ausente. Reconecte a integracao em /google/calendar/connect.');
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
            'expires_in' => (int) max(1, now()->diffInSeconds($token->expires_at)),
            'created' => now()->timestamp,
        ]);

        return $client;
    }

    private function buildCessionEvent(Writ $writ): Event
    {
        $timezone = config('google-calendar.timezone', 'America/Sao_Paulo');
        $start = $this->localWritDateTime($writ->cession_at, $timezone);
        $end = $start->copy()->addMinutes(max(15, (int) config('google-calendar.default_duration_minutes', 30)));

        $event = new Event([
            'summary' => $this->eventSummary($writ, 'Cessao'),
            'description' => $this->eventDescription($writ, Writ::STAGE_LABELS['pending']),
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

    private function buildPetitionEvent(Writ $writ): Event
    {
        $timezone = config('google-calendar.timezone', 'America/Sao_Paulo');
        $start = $this->localWritDateTime($writ->petitioned_at, $timezone);
        $end = $start->copy()->addMinutes(max(15, (int) config('google-calendar.default_duration_minutes', 30)));

        $event = new Event([
            'summary' => $this->eventSummary($writ, 'Peticionar'),
            'description' => $this->eventDescription($writ, Writ::STAGE_LABELS['petitioning']),
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
                    'requestId' => 'writ-'.$writ->id.'-petition',
                ],
            ]);
        }

        return $event;
    }

    private function buildAwaitingReceiptEvent(Writ $writ): Event
    {
        $timezone = config('google-calendar.timezone', 'America/Sao_Paulo');
        $start = $this->localWritDateTime($writ->awaiting_receipt_at, $timezone);
        $end = $start->copy()->addMinutes(max(15, (int) config('google-calendar.default_duration_minutes', 30)));

        $event = new Event([
            'summary' => $this->eventSummary($writ, 'Aguardando Recebimento'),
            'description' => $this->eventDescription($writ, Writ::STAGE_LABELS['awaiting_receipt']),
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
                    'requestId' => 'writ-'.$writ->id.'-awaiting-receipt',
                ],
            ]);
        }

        return $event;
    }

    private function localWritDateTime(CarbonInterface $dateTime, string $timezone): CarbonInterface
    {
        return Carbon::parse($dateTime->format('Y-m-d H:i:s'), $timezone);
    }

    private function eventDateTime(CarbonInterface $dateTime, string $timezone): EventDateTime
    {
        return new EventDateTime([
            'dateTime' => $dateTime->toRfc3339String(),
            'timeZone' => $timezone,
        ]);
    }

    private function eventSummary(Writ $writ, string $prefix): string
    {
        return $prefix.' - '.$this->assignorName($writ);
    }

    private function eventDescription(Writ $writ, string $stageLabel): string
    {
        $lines = [
            'Requisitorio: '.($writ->process_number ?: '#'.$writ->id),
            'Etapa: '.$stageLabel,
            'Ente devedor: '.($writ->debtor_entity ?: '-'),
            'Cedente: '.$this->assignorName($writ),
            'Valor negociado: R$ '.number_format($this->negotiatedAmount($writ), 2, ',', '.'),
        ];

        if ($writ->notes) {
            $lines[] = '';
            $lines[] = 'Observacoes: '.$writ->notes;
        }

        $lines[] = '';
        $lines[] = 'Link no sistema: '.URL::route('writs.show', $writ);

        return implode("\n", $lines);
    }

    private function assignorName(Writ $writ): string
    {
        $legacyAssignorName = trim((string) $writ->assignor_name);

        if ($legacyAssignorName !== '') {
            return $legacyAssignorName;
        }

        $assignor = $writ->relationLoaded('assignors')
            ? $writ->assignors->first()
            : $writ->assignors()->first();

        $contactName = trim((string) $assignor?->contact?->name);

        if ($contactName !== '') {
            return $contactName;
        }

        return $writ->process_number ?: '#'.$writ->id;
    }

    private function negotiatedAmount(Writ $writ): float
    {
        $negotiatedAmount = (float) $writ->negotiated_amount;

        if ($negotiatedAmount > 0) {
            return $negotiatedAmount;
        }

        return (float) $writ->proposed_amount;
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
    private function eventRequestParams(bool $forUpdate = false): array
    {
        $params = [
            'sendUpdates' => $forUpdate ? 'none' : config('google-calendar.send_updates', 'all'),
        ];

        if (config('google-calendar.create_meet') && ! $forUpdate) {
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
