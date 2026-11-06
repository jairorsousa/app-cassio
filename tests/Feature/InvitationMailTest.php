<?php

namespace Tests\Feature;

use App\Jobs\SendUserInvitationEmail;
use App\Mail\MailSenderSelfTestMail;
use App\Mail\UserInvitationMail;
use App\Models\GmailSendCredential;
use App\Models\MailSenderSetting;
use App\Support\GmailSend\GmailSendClient;
use App\Models\User;
use App\Models\UserInvitation;
use App\Support\InvitationMailService;
use App\Support\UserAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Exception\TransportException;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Feature\FakeGmailSendClient;

class InvitationMailTest extends TestCase
{
    use RefreshDatabase;

    private FakeGmailSendClient $gmailFake;

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
        $this->gmailFake = new FakeGmailSendClient;
        $this->app->instance(GmailSendClient::class, $this->gmailFake);
    }

    private function connectOAuth(User $admin): void
    {
        GmailSendCredential::query()->updateOrCreate(
            ['provider' => GmailSendCredential::PROVIDER],
            [
                'account_email' => 'cassio@cassiomota.com',
                'access_token' => 'access-test-token',
                'refresh_token' => 'refresh-test-token',
                'expires_at' => now()->addHour(),
                'scopes' => ['openid', 'email', 'https://www.googleapis.com/auth/gmail.send'],
                'connected_by_user_id' => $admin->id,
                'connected_at' => now(),
            ]
        );
    }

    private function ownerAdmin(): User
    {
        $u = User::factory()->create();
        // Garante id 1 para a regra de configuração SMTP.
        if ((int) $u->id !== 1) {
            User::query()->whereKey($u->id)->update(['id' => 1]);
            $u = User::findOrFail(1);
        }
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'email' => 'cassio@cassiomota.com'])->save();

        return $u->fresh();
    }

    private function otherAdmin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_admin' => true, 'status' => 'active'])->save();

        return $u->fresh();
    }

    private function withPassword(User $admin, string $password = 'abcd efgh ijkl mnop'): MailSenderSetting
    {
        $s = InvitationMailService::saveSettings(
            $admin,
            MailSenderSetting::ALLOWED_USERNAME,
            MailSenderSetting::DEFAULT_HOST,
            MailSenderSetting::DEFAULT_PORT,
            $password,
        );
        // Envio agora exige OAuth; SMTP legado sozinho não habilita transporte.
        $this->connectOAuth($admin);

        return $s;
    }

    public function test_id1_can_save_sender_without_password_and_blank_save_keeps_secret(): void
    {
        $admin = $this->ownerAdmin();
        $this->actingAs($admin);

        InvitationMailService::saveSettings($admin, 'cassio@cassiomota.com', 'smtp.gmail.com', 587, null);
        $s = MailSenderSetting::current();
        $this->assertNotNull($s);
        $this->assertFalse($s->hasPassword());
        $this->assertFalse($s->isReadyToSend());

        $this->withPassword($admin, 'abcdefghijklmnop');
        $s = MailSenderSetting::current()->fresh();
        $this->assertTrue($s->hasPassword());
        $raw = $s->getRawOriginal('password');
        $this->assertNotSame('abcdefghijklmnop', $raw);
        $this->assertSame('abcdefghijklmnop', $s->password);

        InvitationMailService::saveSettings($admin, 'cassio@cassiomota.com', 'smtp.gmail.com', 587, null);
        $s2 = MailSenderSetting::current()->fresh();
        $this->assertTrue($s2->hasPassword());
        $this->assertSame('abcdefghijklmnop', $s2->password);

        InvitationMailService::clearPassword($admin);
        $this->assertFalse(MailSenderSetting::current()->fresh()->hasPassword());
    }

    public function test_rejects_non_allowed_sender_and_non_id1_admin(): void
    {
        $admin = $this->ownerAdmin();
        try {
            InvitationMailService::saveSettings($admin, 'outro@cassiomota.com', 'smtp.gmail.com', 587, 'x');
            $this->fail('aceitou remetente inválido');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('smtpUsername', $e->errors());
        }

        $other = $this->otherAdmin();
        try {
            InvitationMailService::saveSettings($other, 'cassio@cassiomota.com', 'smtp.gmail.com', 587, 'x');
            $this->fail('outro admin configurou SMTP');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_invite_and_regenerate_do_not_auto_send_email(): void
    {
        Mail::fake();
        Queue::fake();
        $admin = $this->ownerAdmin();
        $this->withPassword($admin);
        $this->actingAs($admin);

        [$inv] = UserAccessService::invite($admin, 'Ana', 'ana@example.com', ['contatos']);
        $this->assertNull($inv->email_delivery_status);
        Queue::assertNothingPushed();
        $this->assertCount(0, $this->gmailFake->sent);

        UserAccessService::regenerate($admin, $inv->id);
        Queue::assertNothingPushed();
        $this->assertCount(0, $this->gmailFake->sent);
        $this->assertNull($inv->fresh()->email_delivery_status);
        $this->assertNull($inv->fresh()->accepted_at);
    }

    public function test_explicit_send_queues_uses_fixed_from_and_marks_sent_without_accepting(): void
    {
        $admin = $this->ownerAdmin();
        $this->withPassword($admin);
        $this->actingAs($admin);
        [$inv, $token] = UserAccessService::invite($admin, 'Bia', 'bia@example.com', ['financeiro']);

        [$queued, $sendToken] = InvitationMailService::queueInvitationEmail($admin, $inv->id, $token);
        $fresh = $inv->fresh();
        $this->assertSame('sent', $fresh->email_delivery_status);
        $this->assertContains($queued->email_delivery_status, ['queued', 'sent']);
        $this->assertNotNull($fresh->email_sent_at);
        $this->assertNull($fresh->accepted_at);
        $this->assertSame('pending', $fresh->status());
        $this->assertNotEmpty($this->gmailFake->sent);
        $raw = $this->gmailFake->sent[0]['raw'];
        $this->assertStringContainsString('From: cassio@cassiomota.com', $raw);
        $this->assertStringContainsString('To: bia@example.com', $raw);
        $this->assertStringContainsString($sendToken, $raw);
        $this->assertSame('array', Config::get('mail.default'));
    }

    public function test_parallel_send_is_idempotent_while_queued(): void
    {
        Queue::fake();
        $admin = $this->ownerAdmin();
        $this->withPassword($admin);
        [$inv, $token] = UserAccessService::invite($admin, 'Cia', 'cia@example.com', ['contatos']);

        InvitationMailService::queueInvitationEmail($admin, $inv->id, $token);
        Queue::assertPushed(SendUserInvitationEmail::class, 1);

        try {
            InvitationMailService::queueInvitationEmail($admin, $inv->id, $token);
            $this->fail('duplicou envio');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inviteMail', $e->errors());
        }
        Queue::assertPushed(SendUserInvitationEmail::class, 1);
        $this->assertSame('queued', $inv->fresh()->email_delivery_status);
        $this->assertNull($inv->fresh()->accepted_at);
    }

    public function test_failed_delivery_can_retry_and_does_not_accept(): void
    {
        $admin = $this->ownerAdmin();
        $this->withPassword($admin);
        [$inv, $token] = UserAccessService::invite($admin, 'Dan', 'dan@example.com', ['contatos']);

        InvitationMailService::markFailed($inv->id, 'tok', 'x');
        $dispatch = 'dispatch-token-for-fail-test-aaaaaaaa';
        $inv->forceFill([
            'email_delivery_status' => 'queued',
            'email_dispatch_token' => $dispatch,
            'email_attempts' => 1,
        ])->save();
        InvitationMailService::markFailed($inv->id, $dispatch, 'Falha ao enviar e-mail.');
        $this->assertSame('failed', $inv->fresh()->email_delivery_status);
        $this->assertNull($inv->fresh()->accepted_at);

        InvitationMailService::queueInvitationEmail($admin, $inv->id, null);
        $this->assertSame('sent', $inv->fresh()->email_delivery_status);
        $this->assertSame(2, (int) $inv->fresh()->email_attempts);
        $this->assertNotEmpty($this->gmailFake->sent);
    }

    public function test_send_blocked_without_password_and_non_admin(): void
    {
        $admin = $this->ownerAdmin();
        InvitationMailService::saveSettings($admin, 'cassio@cassiomota.com', 'smtp.gmail.com', 587, null);
        // sem connectOAuth
        [$inv] = UserAccessService::invite($admin, 'Eva', 'eva@example.com', ['contatos']);
        try {
            InvitationMailService::queueInvitationEmail($admin, $inv->id);
            $this->fail('enviou sem oauth');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inviteMail', $e->errors());
        }

        $limited = User::factory()->create();
        $limited->forceFill(['modules' => ['financeiro']])->save();
        try {
            InvitationMailService::queueInvitationEmail($limited, $inv->id);
            $this->fail('não-admin enviou');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_self_test_only_to_configured_sender(): void
    {
        $admin = $this->ownerAdmin();
        $this->withPassword($admin);
        InvitationMailService::sendSelfTest($admin);
        $this->assertCount(1, $this->gmailFake->sent);
        $this->assertStringContainsString('To: cassio@cassiomota.com', $this->gmailFake->sent[0]['raw']);
        $this->assertSame(0, UserInvitation::count());
    }

    public function test_volt_ui_hides_password_and_supports_send_button(): void
    {
        Mail::fake();
        $admin = $this->ownerAdmin();
        $this->actingAs($admin);

        Volt::test('settings.users')
            ->assertSee('Remetente de e-mail (Gmail OAuth)')
            ->set('smtpUsername', 'cassio@cassiomota.com')
            ->set('smtpHost', 'smtp.gmail.com')
            ->set('smtpPort', 587)
            ->set('smtpPassword', 'abcdefghijklmnop')
            ->call('saveSmtp')
            ->assertHasNoErrors()
            ->assertSet('smtpPassword', '');

        $html = $this->get(route('settings.users'))->assertOk()->getContent();
        $this->assertStringNotContainsString('abcdefghijklmnop', $html);
        $this->assertStringContainsString('Há senha de aplicativo ainda gravada', $html);

        $this->connectOAuth($admin);

        $c = Volt::test('settings.users')
            ->set('inviteName', 'Fê')
            ->set('inviteEmail', 'fe@example.com')
            ->set('inviteModules', ['contatos'])
            ->call('invite')
            ->assertHasNoErrors();
        $id = $c->get('generatedInvitationId');
        $this->assertNotNull($id);
        // Criar não envia
        $this->assertCount(0, $this->gmailFake->sent);

        $c->call('sendInvitationEmail', $id)->assertHasNoErrors();
        $this->assertNotEmpty($this->gmailFake->sent);
        $this->assertSame('sent', UserInvitation::find($id)->email_delivery_status);

        // Snapshot Livewire não deve reidratar senha do banco
        $c2 = Volt::test('settings.users');
        $this->assertSame('', $c2->get('smtpPassword'));
        $payload = json_encode($c2->effects);
        $this->assertStringNotContainsString('abcdefghijklmnop', (string) $payload);
    }

    public function test_global_mail_default_untouched_and_spoof_from_blocked_by_contract(): void
    {
        $before = Config::get('mail.default');
        $admin = $this->ownerAdmin();
        $this->withPassword($admin);
        Mail::fake();
        [$inv, $token] = UserAccessService::invite($admin, 'Gus', 'gus@example.com', ['contatos']);
        InvitationMailService::queueInvitationEmail($admin, $inv->id, $token);
        $this->assertSame($before, Config::get('mail.default'));
        $this->assertSame('array', Config::get('mail.default')); // phpunit
    }

    public function test_classify_auth_failure_is_not_retryable_and_safe(): void
    {
        $e = new TransportException('Expected response code "235" but got code "535", with message "535-5.7.8 Username and Password not accepted for cassio@example.invalid".');
        $info = InvitationMailService::classifyTransportFailure($e);
        $this->assertSame('auth', $info['kind']);
        $this->assertFalse($info['retryable']);
        $this->assertSame(InvitationMailService::MSG_AUTH, $info['message']);
        $this->assertStringNotContainsString('cassio@', $info['message']);
        $this->assertStringNotContainsString('535-5.7.8 Username', $info['message']);
    }

    public function test_self_test_auth_failure_is_friendly_records_status_no_500(): void
    {
        Log::spy();
        $admin = $this->ownerAdmin();
        $this->withPassword($admin);
        $this->actingAs($admin);

        $this->gmailFake->sendFail = 'auth';

        try {
            InvitationMailService::sendSelfTest($admin);
            $this->fail('deveria falhar com ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('smtpTest', $e->errors());
            $this->assertSame(\App\Support\GmailSend\GmailSendOAuthService::MSG_AUTH, $e->errors()['smtpTest'][0]);
        }

        $cred = GmailSendCredential::current()->fresh();
        $this->assertSame('failed', $cred->last_self_test_status);
        $this->assertNotNull($cred->last_self_test_at);
        $this->assertTrue(InvitationMailService::isReadyToSend());
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx) => $msg === 'invitation_mail_failure'
            && ($ctx['kind'] ?? null) === 'auth'
            && ! str_contains(json_encode($ctx), 'refresh-test-token'));

        Volt::test('settings.users')
            ->assertSee('data-smtp-last-test="failed"', false)
            ->assertDontSee('refresh-test-token');
    }

    public function test_deliver_auth_failure_marks_failed_without_rethrow(): void
    {
        $admin = $this->ownerAdmin();
        $this->withPassword($admin);
        $this->gmailFake->sendFail = 'auth';
        [$inv, $token] = UserAccessService::invite($admin, 'Hal', 'hal@example.com', ['contatos']);
        $dispatch = 'dispatch-auth-fail-token-bbbbbbbbbbbbbbbb';
        $inv->forceFill([
            'email_delivery_status' => 'queued',
            'email_dispatch_token' => $dispatch,
            'email_attempts' => 1,
        ])->save();

        InvitationMailService::deliver($inv->id, $token, $dispatch);

        $fresh = $inv->fresh();
        $this->assertSame('failed', $fresh->email_delivery_status);
        $this->assertSame(\App\Support\GmailSend\GmailSendOAuthService::MSG_AUTH, $fresh->email_last_error);
        $this->assertNull($fresh->accepted_at);
        $this->assertSame('pending', $fresh->status());
    }

    public function test_deliver_transient_transport_marks_failed_and_rethrows_for_retry(): void
    {
        $admin = $this->ownerAdmin();
        $this->withPassword($admin);
        $this->gmailFake->sendFail = 'rate';
        [$inv, $token] = UserAccessService::invite($admin, 'Ivo', 'ivo@example.com', ['contatos']);
        $dispatch = 'dispatch-transient-token-cccccccccccccccc';
        $inv->forceFill([
            'email_delivery_status' => 'queued',
            'email_dispatch_token' => $dispatch,
            'email_attempts' => 1,
        ])->save();

        try {
            InvitationMailService::deliver($inv->id, $token, $dispatch);
            $this->fail('deveria relançar para retry');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('gmail_api_rate', $e->getMessage());
        }
        $this->assertSame('failed', $inv->fresh()->email_delivery_status);
        $this->assertSame(\App\Support\GmailSend\GmailSendOAuthService::MSG_RETRY, $inv->fresh()->email_last_error);
    }

    public function test_self_test_success_records_ok_without_pretending_invite_sent(): void
    {
        $admin = $this->ownerAdmin();
        $this->withPassword($admin);
        InvitationMailService::sendSelfTest($admin);
        $cred = GmailSendCredential::current()->fresh();
        $this->assertSame('ok', $cred->last_self_test_status);
        $this->assertNull($cred->last_self_test_error);
        $this->assertSame(0, UserInvitation::count());
    }

    public function test_volt_self_test_surfaces_validation_not_server_error(): void
    {
        $admin = $this->ownerAdmin();
        $this->withPassword($admin);
        $this->actingAs($admin);

        $this->gmailFake->sendFail = 'auth';

        Volt::test('settings.users')
            ->call('sendSmtpSelfTest')
            ->assertHasErrors('smtpTest');
    }


    public function test_app_password_spaces_normalized_and_invalid_rejected(): void
    {
        $admin = $this->ownerAdmin();
        $grouped = 'abcd efgh ijkl mnop';
        $s = InvitationMailService::saveSettings($admin, 'cassio@cassiomota.com', 'smtp.gmail.com', 587, $grouped);
        $this->assertTrue($s->passwordFormatOk());
        $this->assertSame('abcdefghijklmnop', $s->password);
        $this->assertStringNotContainsString(' ', $s->password);

        try {
            InvitationMailService::saveSettings($admin, 'cassio@cassiomota.com', 'smtp.gmail.com', 587, 'short');
            $this->fail('aceitou senha curta');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('smtpPassword', $e->errors());
        }

        try {
            InvitationMailService::saveSettings($admin, 'cassio@cassiomota.com', 'smtp.gmail.com', 587, 'AbcdEfghIjklMn12');
            $this->fail('aceitou formato não Google');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('smtpPassword', $e->errors());
        }

        // Mantém a senha boa anterior (blank save)
        InvitationMailService::saveSettings($admin, 'cassio@cassiomota.com', 'smtp.gmail.com', 587, null);
        $this->assertSame('abcdefghijklmnop', MailSenderSetting::current()->fresh()->password);
        $meta = InvitationMailService::settingOrDefaults();
        $this->assertTrue($meta['password_format_ok']);
        $this->assertArrayNotHasKey('password', $meta);
    }

    public function test_setting_meta_flags_bad_stored_format_without_exposing_secret(): void
    {
        $admin = $this->ownerAdmin();
        $s = InvitationMailService::saveSettings($admin, 'cassio@cassiomota.com', 'smtp.gmail.com', 587, 'abcdefghijklmnop');
        // Simula valor legado inválido já no banco (bypass validação de save).
        $s->password = 'NotGoogle!13';
        $s->password_set_at = now();
        $s->save();
        $fresh = MailSenderSetting::current()->fresh();
        $this->assertTrue($fresh->hasPassword());
        $this->assertFalse($fresh->passwordFormatOk());
        $meta = InvitationMailService::settingOrDefaults();
        $this->assertTrue($meta['has_password']);
        $this->assertFalse($meta['password_format_ok']);
        $this->actingAs($admin);
        $this->get(route('settings.users'))->assertOk()
            ->assertSee('data-smtp-format-bad', false)
            ->assertDontSee('NotGoogle');
    }

}
