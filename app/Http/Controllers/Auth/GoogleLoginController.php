<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\GoogleAuth\IdentityProvider;
use App\Support\UserPreferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoogleLoginController extends Controller
{
    private const FAIL = 'Não foi possível entrar com o Google. Use uma conta Google com o e-mail já cadastrado no sistema ou entre com e-mail e senha.';

    public function redirect(Request $request, IdentityProvider $google): RedirectResponse
    {
        if (! $google->configured()) {
            return redirect()->route('login');
        }
        $state = Str::random(64);
        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $request->session()->put('google_login', ['state' => $state, 'verifier' => $verifier, 'at' => now()->timestamp]);

        return redirect()->away($google->authorizationUrl($state, $challenge));
    }

    public function callback(Request $request, IdentityProvider $google): RedirectResponse
    {
        $saved = $request->session()->pull('google_login');
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        if (! $google->configured() || ! is_array($saved) || $request->filled('error') || $code === ''
            || ! is_string($saved['state'] ?? null) || ! hash_equals($saved['state'], $state)
            || (now()->timestamp - (int) ($saved['at'] ?? 0)) > 600) {
            return $this->fail();
        }

        $id = $google->verifiedIdentity($code, (string) $saved['verifier']);
        if (! $id || $id['sub'] === '' || $id['email'] === '' || ($id['email_verified'] ?? false) !== true) {
            return $this->fail();
        }

        $email = mb_strtolower(trim($id['email']));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if (! $user || ! $user->isActive()) {
            return $this->fail();
        }
        // Identidade Google já vinculada a outro usuário, ou usuário vinculado a outra identidade.
        $other = User::query()->where('google_sub', $id['sub'])->where('id', '!=', $user->id)->exists();
        if ($other || ($user->google_sub !== null && ! hash_equals((string) $user->google_sub, $id['sub']))) {
            Log::warning('google_login: conflito de identidade', ['user_id' => $user->id]);
            return $this->fail();
        }
        if ($user->google_sub === null) {
            $user->forceFill(['google_sub' => $id['sub']])->save();
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->intended(UserPreferences::homeUrl($user));
    }

    private function fail(): RedirectResponse
    {
        return redirect()->route('login')->with('status', self::FAIL);
    }
}
