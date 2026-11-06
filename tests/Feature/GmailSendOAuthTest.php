<?php

namespace Tests\Feature;

use App\Models\GmailSendCredential;
use App\Models\User;
use App\Models\UserInvitation;
use App\Support\GmailSend\GmailSendClient;
use App\Support\GmailSend\GmailSendOAuthService;
use App\Support\InvitationMailService;
use App\Support\UserAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class GmailSendOAuthTest extends TestCase
{
    use RefreshDatabase;

    private FakeGmailSendClient $fake;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'gmail-send.enabled' => true,
            'gmail-send.client_id' => 'test-client-id',
            'gmail-send.client_secret' => 'test-client-secret',
            'gmail-send.redirect_uri' => 'https://app.cassiomota.com/configuracoes/email/google/callback',
            'gmail-send.allowed_account' => 'cassio@cassiomota.com',
        ]);
        $this->fake = new FakeGmailSendClient;
        $this->app->instance(GmailSendClient::class, $this->fake);
    }

    private function owner(): User
    {
        $u = User::factory()->create(['email' => 'cassio@cassiomota.com']);
        if ((int) $u->id !== 1) {
            User::query()->whereKey($u->id)->update(['id' => 1]);
            $u = User::findOrFail(1);
        }
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'email' => 'cassio@cassiomota.com'])->save();

        return $u->fresh();
    }

    private function connectViaHttp(User $admin): void
    {
        $this->actingAs($admin);
        $r = $this->get(route('settings.email.google.connect'));
        $r->assertRedirect();
        $url = $r->headers->get('Location');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('false', $q['include_granted_scopes']);
        $this->assertSame('consent', $q['prompt']);
        $this->assertSame('offline', $q['access_type']);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertStringContainsString('gmail.send', $q['scope']);
        $this->assertStringNotContainsString('calendar', $q['scope']);
        $state = $q['state'];
        $this->get(route('settings.email.google.callback', ['state' => $state, 'code' => 'auth-code']))
            ->assertRedirect(route('settings.users'));
    }

    public function test_connect_callback_stores_encrypted_tokens_for_allowed_account(): void
    {
        $admin = $this->owner();
        $this->connectViaHttp($admin);
        $cred = GmailSendCredential::current();
        $this->assertNotNull($cred);
        $this->assertSame('cassio@cassiomota.com', $cred->account_email);
        $this->assertNotSame('access-test-token', $cred->getRawOriginal('access_token'));
        $this->assertNotSame('refresh-test-token', $cred->getRawOriginal('refresh_token'));
        $this->assertSame('access-test-token', $cred->access_token);
        $this->assertSame('refresh-test-token', $cred->refresh_token);
        $this->assertTrue(InvitationMailService::isReadyToSend());
        $this->assertSame('google-oauth', InvitationMailService::settingOrDefaults()['transport']);
        $this->assertNotNull($this->fake->lastVerifier);
        $html = $this->get(route('settings.users'))->assertOk()->getContent();
        $this->assertStringNotContainsString('access-test-token', $html);
        $this->assertStringNotContainsString('refresh-test-token', $html);
        $this->assertStringContainsString('data-gmail-connected', $html);
    }

    public function test_wrong_account_rejected_and_no_credential_stored(): void
    {
        $this->fake->email = 'outro@example.com';
        $admin = $this->owner();
        $this->actingAs($admin);
        $this->get(route('settings.email.google.connect'));
        $state = session('gmail_send_oauth.state');
        $this->get(route('settings.email.google.callback', ['state' => $state, 'code' => 'x']))
            ->assertRedirect(route('settings.users'));
        $this->assertNull(GmailSendCredential::current());
        $this->assertFalse(InvitationMailService::isReadyToSend());
    }

    public function test_wrong_scope_rejected(): void
    {
        $this->fake->tokenResponse['scope'] = 'openid email https://www.googleapis.com/auth/gmail.readonly';
        $admin = $this->owner();
        $this->actingAs($admin);
        $this->get(route('settings.email.google.connect'));
        $state = session('gmail_send_oauth.state');
        $this->get(route('settings.email.google.callback', ['state' => $state, 'code' => 'x']))
            ->assertRedirect(route('settings.users'));
        $this->assertNull(GmailSendCredential::current());
    }

    public function test_broad_gmail_scope_forbidden(): void
    {
        $this->fake->tokenResponse['scope'] = 'openid email https://www.googleapis.com/auth/gmail.send https://www.googleapis.com/auth/gmail.modify';
        $admin = $this->owner();
        $this->actingAs($admin);
        $this->get(route('settings.email.google.connect'));
        $state = session('gmail_send_oauth.state');
        $this->get(route('settings.email.google.callback', ['state' => $state, 'code' => 'x']))
            ->assertRedirect(route('settings.users'));
        $this->assertNull(GmailSendCredential::current());
    }

    public function test_csrf_state_mismatch_rejected(): void
    {
        $admin = $this->owner();
        $this->actingAs($admin);
        $this->get(route('settings.email.google.connect'));
        $this->get(route('settings.email.google.callback', ['state' => 'wrong', 'code' => 'x']))
            ->assertRedirect(route('settings.users'));
        $this->assertNull(GmailSendCredential::current());
    }

    public function test_non_id1_admin_forbidden(): void
    {
        $this->owner();
        $other = User::factory()->create();
        $other->forceFill(['is_admin' => true])->save();
        $this->actingAs($other);
        $this->get(route('settings.email.google.connect'))->assertForbidden();
    }

    public function test_provider_down_friendly(): void
    {
        $this->fake->exchangeFails = true;
        $admin = $this->owner();
        $this->actingAs($admin);
        $this->get(route('settings.email.google.connect'));
        $state = session('gmail_send_oauth.state');
        $this->get(route('settings.email.google.callback', ['state' => $state, 'code' => 'x']))
            ->assertRedirect(route('settings.users'));
        $this->assertNull(GmailSendCredential::current());
    }

    public function test_disconnect_erases_local_tokens(): void
    {
        $admin = $this->owner();
        $this->connectViaHttp($admin);
        $this->assertNotNull(GmailSendCredential::current());
        $this->post(route('settings.email.google.disconnect'))->assertRedirect(route('settings.users'));
        $this->assertNull(GmailSendCredential::current());
        $this->assertFalse(InvitationMailService::isReadyToSend());
    }

    public function test_self_test_and_invite_send_use_gmail_api_not_smtp(): void
    {
        Log::spy();
        $admin = $this->owner();
        $this->connectViaHttp($admin);
        InvitationMailService::sendSelfTest($admin);
        $this->assertSame('ok', GmailSendCredential::current()->fresh()->last_self_test_status);
        $this->assertCount(1, $this->fake->sent);
        $this->assertStringContainsString('From: cassio@cassiomota.com', $this->fake->sent[0]['raw']);
        $this->assertStringContainsString('To: cassio@cassiomota.com', $this->fake->sent[0]['raw']);

        [$inv, $token] = UserAccessService::invite($admin, 'Ana', 'ana@example.com', ['contatos']);
        InvitationMailService::queueInvitationEmail($admin, $inv->id, $token);
        $this->assertSame('sent', $inv->fresh()->email_delivery_status);
        $this->assertNull($inv->fresh()->accepted_at);
        $this->assertCount(2, $this->fake->sent);
        $raw = $this->fake->sent[1]['raw'];
        $this->assertStringContainsString('To: ana@example.com', $raw);
        $this->assertStringContainsString('/convite/', $raw);
        $this->assertMatchesRegularExpression('#https://#', $raw);
        Log::shouldNotHaveReceived('warning', fn (...$args) => str_contains(json_encode($args), 'refresh-test-token'));
    }

    public function test_auth_failure_on_send_no_retry_and_safe_message(): void
    {
        $admin = $this->owner();
        $this->connectViaHttp($admin);
        $this->fake->sendFail = 'auth';
        [$inv, $token] = UserAccessService::invite($admin, 'Bia', 'bia@example.com', ['contatos']);
        InvitationMailService::queueInvitationEmail($admin, $inv->id, $token);
        $this->assertSame('failed', $inv->fresh()->email_delivery_status);
        $this->assertSame(GmailSendOAuthService::MSG_AUTH, $inv->fresh()->email_last_error);
        $this->assertNull($inv->fresh()->accepted_at);
    }

    public function test_rate_limit_marks_failed_and_rethrows(): void
    {
        $admin = $this->owner();
        $this->connectViaHttp($admin);
        $this->fake->sendFail = 'rate';
        [$inv, $token] = UserAccessService::invite($admin, 'Cia', 'cia@example.com', ['contatos']);
        $dispatch = 'dispatch-rate-cccccccccccccccccccccccc';
        $inv->forceFill([
            'email_delivery_status' => 'queued',
            'email_dispatch_token' => $dispatch,
            'email_attempts' => 1,
        ])->save();
        try {
            InvitationMailService::deliver($inv->id, $token, $dispatch);
            $this->fail('should rethrow');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('gmail_api_rate', $e->getMessage());
        }
        $this->assertSame('failed', $inv->fresh()->email_delivery_status);
        $this->assertSame(GmailSendOAuthService::MSG_RETRY, $inv->fresh()->email_last_error);
    }

    public function test_no_silent_smtp_fallback_when_oauth_missing(): void
    {
        $admin = $this->owner();
        InvitationMailService::saveSettings($admin, 'cassio@cassiomota.com', 'smtp.gmail.com', 587, 'abcdefghijklmnop');
        [$inv] = UserAccessService::invite($admin, 'Dan', 'dan@example.com', ['contatos']);
        try {
            InvitationMailService::queueInvitationEmail($admin, $inv->id);
            $this->fail('smtp fallback');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inviteMail', $e->errors());
        }
        $this->assertNull($inv->fresh()->email_delivery_status);
        $this->assertCount(0, $this->fake->sent);
    }

    public function test_volt_shows_connect_and_self_test_validation(): void
    {
        $admin = $this->owner();
        $this->actingAs($admin);
        Volt::test('settings.users')
            ->assertSee('Conectar Gmail somente para envio de convites')
            ->assertSee('data-gmail-disconnected', false);
        $this->connectViaHttp($admin);
        $this->fake->sendFail = 'auth';
        Volt::test('settings.users')
            ->call('sendSmtpSelfTest')
            ->assertHasErrors('smtpTest');
        $this->assertSame('failed', GmailSendCredential::current()->fresh()->last_self_test_status);
    }

    public function test_callback_uri_documented_in_config(): void
    {
        $this->assertSame(
            'https://app.cassiomota.com/configuracoes/email/google/callback',
            config('gmail-send.redirect_uri')
        );
    }
}
