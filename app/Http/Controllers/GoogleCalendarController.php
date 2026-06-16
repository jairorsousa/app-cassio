<?php

namespace App\Http\Controllers;

use App\Domains\Integrations\Models\GoogleCalendarToken;
use App\Domains\Integrations\Services\GoogleCalendarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GoogleCalendarController extends Controller
{
    public function connect(GoogleCalendarService $googleCalendar): RedirectResponse
    {
        try {
            $client = $googleCalendar->makeOAuthClient();
            $state = Str::random(40);

            session(['google_calendar_oauth_state' => $state]);
            $client->setState($state);

            return redirect()->away($client->createAuthUrl());
        } catch (Throwable $exception) {
            Log::error('Falha ao iniciar OAuth do Google Calendar.', [
                'exception' => $exception,
            ]);

            return redirect()
                ->route('dashboard')
                ->with('error', 'Falha ao iniciar conexao com Google Calendar. Verifique as credenciais, o cache de configuracao e as dependencias do Composer.');
        }
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
        } catch (Throwable $exception) {
            Log::error('Falha ao concluir OAuth do Google Calendar.', [
                'exception' => $exception,
            ]);

            return redirect()
                ->route('dashboard')
                ->with('error', 'Falha ao concluir conexao com Google Calendar. Verifique os logs do Laravel.');
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
