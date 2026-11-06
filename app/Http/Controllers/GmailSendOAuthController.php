<?php

namespace App\Http\Controllers;

use App\Support\GmailSend\GmailSendOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GmailSendOAuthController extends Controller
{
    public function connect(Request $request, GmailSendOAuthService $oauth): RedirectResponse
    {
        abort_unless($request->user() && (int) $request->user()->id === 1 && $request->user()->isAdmin(), 403);
        try {
            $started = $oauth->beginConnect($request->user());
        } catch (ValidationException $e) {
            return redirect()->route('settings.users')->withErrors($e->errors());
        }

        return redirect()->away($started['url']);
    }

    public function callback(Request $request, GmailSendOAuthService $oauth): RedirectResponse
    {
        abort_unless($request->user() && (int) $request->user()->id === 1 && $request->user()->isAdmin(), 403);
        if ($request->filled('error')) {
            return redirect()->route('settings.users')
                ->withErrors(['gmailOAuth' => 'Google recusou a autorização. Tente de novo.']);
        }
        try {
            $oauth->completeCallback(
                $request->user(),
                (string) $request->query('state', ''),
                (string) $request->query('code', ''),
            );
        } catch (ValidationException $e) {
            return redirect()->route('settings.users')->withErrors($e->errors());
        }

        return redirect()->route('settings.users')
            ->with('status', 'Gmail conectado só para envio de convites. Nenhum e-mail foi enviado ainda — use o teste explícito quando quiser.');
    }

    public function disconnect(Request $request, GmailSendOAuthService $oauth): RedirectResponse
    {
        abort_unless($request->user() && (int) $request->user()->id === 1 && $request->user()->isAdmin(), 403);
        $oauth->disconnect($request->user());

        return redirect()->route('settings.users')
            ->with('status', 'Conexão Gmail de convites removida deste sistema (tokens locais apagados).');
    }
}
