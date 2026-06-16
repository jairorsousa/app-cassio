<?php

namespace App\Http\Controllers;

use App\Domains\Integrations\Models\GoogleCalendarToken;
use App\Domains\Integrations\Services\GoogleCalendarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

class GoogleCalendarController extends Controller
{
    public function connect(GoogleCalendarService $googleCalendar): RedirectResponse
    {
        $client = $googleCalendar->makeOAuthClient();
        $state = Str::random(40);

        session(['google_calendar_oauth_state' => $state]);
        $client->setState($state);

        return redirect()->away($client->createAuthUrl());
    }

    public function callback(Request $request, GoogleCalendarService $googleCalendar): RedirectResponse
    {
        if ($request->filled('error')) {
            return redirect()
                ->route('dashboard')
                ->with('error', 'Google Calendar recusou a autorizacao: '.$request->string('error'));
        }

        $expectedState = session()->pull('google_calendar_oauth_state');

        if (! $expectedState || ! hash_equals($expectedState, (string) $request->query('state'))) {
            return redirect()
                ->route('dashboard')
                ->with('error', 'Estado OAuth invalido. Tente conectar o Google Calendar novamente.');
        }

        if (! $request->filled('code')) {
            return redirect()
                ->route('dashboard')
                ->with('error', 'Callback do Google Calendar sem codigo de autorizacao.');
        }

        try {
            $googleCalendar->storeTokenFromAuthCode(
                (string) $request->query('code'),
                $request->user()?->id,
            );
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('dashboard')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('dashboard')
            ->with('status', 'Google Calendar conectado com sucesso.');
    }

    public function disconnect(): RedirectResponse
    {
        GoogleCalendarToken::query()
            ->where('provider', 'google_calendar')
            ->delete();

        return redirect()
            ->route('dashboard')
            ->with('status', 'Google Calendar desconectado.');
    }
}
