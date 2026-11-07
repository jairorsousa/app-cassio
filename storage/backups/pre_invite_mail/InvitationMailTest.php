<?php

namespace Tests\Feature;

use App\Jobs\SendUserInvitationEmail;
use App\Mail\MailSenderSelfTestMail;
use App\Mail\UserInvitationMail;
use App\Models\MailSenderSetting;
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

class InvitationMailTest extends TestCase
{
    use RefreshDatabase;

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

    private function withPassword(User $admin, string $password = 'app-password-secret-value'): MailSenderSetting
    {
        return InvitationMailService::saveSettings(
            $admin,
            MailSenderSetting::ALLOWED_USERNAME,
            MailSenderSetting::DEFAULT_HOST,
            MailSenderSetting::DEFAULT_PORT,
            $password,
        );
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

        $this->withPassword($admin, 'super-secret-app-pass');
        $s = MailSenderSetting::current()->fresh();
        $this->assertTrue($s->hasPassword());
        $raw = $s->getRawOriginal('password');
        $this->assertNotSame('super-secret-app-pass', $raw);
        $this->assertSame('super-secret-app-pass', $s->password);

        InvitationMailService::saveSettings($admin, 'cassio@cassiomota.com', 'smtp.gmail.com', 587, null);
        $s2 = MailSenderSetting::current()->fresh();
        $this->assertTrue($s2->hasPassword());
        $this->assertSame('super-secret-app-pass', $s2->password);

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
        Mail::assertNothingSent();

        UserAccessService::regenerate($admin, $inv->id);
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        $this->assertNull($inv->fresh()->email_delivery_status);
        $this->assertNull($inv->fresh()->accepted_at);
    }

    public function test_explicit_send_queues_uses_fixed_from_and_marks_sent_without_accepting(): void
    {
        Mail::fake();
        $admin = $this->ownerAdmin();
        $this->withPassword($admin);
        $this->actingAs($admin);
        [$inv, $token] = UserAccessService::invite($admin, 'Bia', 'bia@example.com', ['financeiro']);

        [$queued, $sendToken] = InvitationMailService::queueInvitationEmail($admin, $inv->id, $token);
        // QUEUE_CONNECTION=sync em phpunit: o job roda dentro do dispatch e já marca sent.
        $fresh = $inv->fresh();
        $this->assertSame('sent', $fresh->email_delivery_status);
        $this->assertContains($queued->email_delivery_status, ['queued', 'sent']);
        $this->assertNotNull($fresh->email_sent_at);
        $this->assertNull($fresh->accepted_at);
        $this->assertSame('pending', $fresh->status());

        Mail::assertSent(UserInvitationMail::class, function (UserInvitationMail $mail) use ($sendToken) {
            return $mail->hasTo('bia@example.com')
                && $mail->fromAddress === 'cassio@cassiomota.com'
                && str_contains($mail->acceptUrl, $sendToken)
                && str_starts_with($mail->acceptUrl, 'http');
        });

        $this->assertSame('smtp.gmail.com', Config::get('mail.mailers.invitation_smtp.host'));
        $this->assertSame(587, Config::get('mail.mailers.invitation_smtp.port'));
        $this->assertSame(15, Config::get('mail.mailers.invitation_smtp.timeout'));
        $this->assertNotSame('invitation_smtp', Config::get('mail.default'));
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
        Mail::fake();
        $admin = $this->ownerAdmin();
        $this->withPassword($admin);
        [$inv, $token] = UserAccessService::invite($admin, 'Dan', 'dan@example.com', ['contatos']);

        InvitationMailService::markFailed($inv->id, 'tok', 'x'); // token errado: no-op
        $dispatch = 'dispatch-token-for-fail-test-aaaaaaaa';
        $inv->forceFill([
            'email_delivery_status' => 'queued',
            'email_dispatch_token' => $dispatch,
            'email_attempts' => 1,
        ])->save();
        InvitationMailService::markFailed($inv->id, $dispatch, 'Falha ao enviar e-mail.');
        $this->assertSame('failed', $inv->fresh()->email_delivery_status);
        $this->assertNull($inv->fresh()->accepted_at);

        // Retry após falha
        InvitationMailService::queueInvitationEmail($admin, $inv->id, null);
        $this->assertSame('sent', $inv->fresh()->email_delivery_status);
        $this->assertSame(2, (int) $inv->fresh()->email_attempts);
        Mail::assertSent(UserInvitationMail::class, 1);
    }

    public function test_send_blocked_without_password_and_non_admin(): void
    {
        $admin = $this->ownerAdmin();
        InvitationMailService::saveSettings($admin, 'cassio@cassiomota.com', 'smtp.gmail.com', 587, null);
        [$inv] = UserAccessService::invite($admin, 'Eva', 'eva@example.com', ['contatos']);
        try {
            InvitationMailService::queueInvitationEmail($admin, $inv->id);
            $this->fail('enviou sem senha');
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
        Mail::fake();
        $admin = $this->ownerAdmin();
        $this->withPassword($admin);
        InvitationMailService::sendSelfTest($admin);
        Mail::assertSent(MailSenderSelfTestMail::class, fn ($m) => $m->hasTo('cassio@cassiomota.com'));
        Mail::assertNotSent(UserInvitationMail::class);
    }

    public function test_volt_ui_hides_password_and_supports_send_button(): void
    {
        Mail::fake();
        $admin = $this->ownerAdmin();
        $this->actingAs($admin);

        Volt::test('settings.users')
            ->assertSee('Remetente de e-mail')
            ->set('smtpUsername', 'cassio@cassiomota.com')
            ->set('smtpHost', 'smtp.gmail.com')
            ->set('smtpPort', 587)
            ->set('smtpPassword', 'ui-secret-password-xyz')
            ->call('saveSmtp')
            ->assertHasNoErrors()
            ->assertSet('smtpPassword', '');

        $html = $this->get(route('settings.users'))->assertOk()->getContent();
        $this->assertStringNotContainsString('ui-secret-password-xyz', $html);
        $this->assertStringContainsString('Senha já gravada', $html);

        $c = Volt::test('settings.users')
            ->set('inviteName', 'Fê')
            ->set('inviteEmail', 'fe@example.com')
            ->set('inviteModules', ['contatos'])
            ->call('invite')
            ->assertHasNoErrors();
        $id = $c->get('generatedInvitationId');
        $this->assertNotNull($id);
        // Criar não envia
        Mail::assertNothingSent();

        $c->call('sendInvitationEmail', $id)->assertHasNoErrors();
        Mail::assertSent(UserInvitationMail::class, 1);
        $this->assertSame('sent', UserInvitation::find($id)->email_delivery_status);

        // Snapshot Livewire não deve reidratar senha do banco
        $c2 = Volt::test('settings.users');
        $this->assertSame('', $c2->get('smtpPassword'));
        $payload = json_encode($c2->effects);
        $this->assertStringNotContainsString('ui-secret-password-xyz', (string) $payload);
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

        $mailer = \Mockery::mock();
        $mailer->shouldReceive('to')->once()->andReturnSelf();
        $mailer->shouldReceive('send')->once()->andThrow(new TransportException('535-5.7.8 BadCredentials Username and Password not accepted'));
        Mail::shouldReceive('mailer')->once()->with('invitation_smtp')->andReturn($mailer);

        try {
            InvitationMailService::sendSelfTest($admin);
            $this->fail('deveria falhar com ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('smtpTest', $e->errors());
            $this->assertSame(InvitationMailService::MSG_AUTH, $e->errors()['smtpTest'][0]);
            $this->assertStringNotContainsString('BadCredentials', $e->errors()['smtpTest'][0]);
        }

        $s = MailSenderSetting::current()->fresh();
        $this->assertSame('failed', $s->last_self_test_status);
        $this->assertNotNull($s->last_self_test_at);
        $this->assertSame(InvitationMailService::MSG_AUTH, $s->last_self_test_error);
        $this->assertTrue($s->isReadyToSend(), 'senha continua; teste falho ≠ apagar config');
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx) => $msg === 'invitation_smtp_failure'
            && ($ctx['kind'] ?? null) === 'auth'
            && ! str_contains(json_encode($ctx), 'cassio@cassiomota.com')
            && ! str_contains(json_encode($ctx), 'BadCredentials'));

        Volt::test('settings.users')
            ->assertSee('data-smtp-last-test="failed"', false)
            ->assertSee('O Google recusou a autenticação SMTP')
            ->assertDontSee('BadCredentials');
    }

    public function test_deliver_auth_failure_marks_failed_without_rethrow(): void
    {
        Log::spy();
        $admin = $this->ownerAdmin();
        $this->withPassword($admin);
        [$inv, $token] = UserAccessService::invite($admin, 'Hal', 'hal@example.com', ['contatos']);
        $dispatch = 'dispatch-auth-fail-token-bbbbbbbbbbbbbbbb';
        $inv->forceFill([
            'email_delivery_status' => 'queued',
            'email_dispatch_token' => $dispatch,
            'email_attempts' => 1,
        ])->save();

        $mailer = \Mockery::mock();
        $mailer->shouldReceive('to')->once()->andReturnSelf();
        $mailer->shouldReceive('send')->once()->andThrow(new TransportException('535 BadCredentials'));
        Mail::shouldReceive('mailer')->once()->with('invitation_smtp')->andReturn($mailer);

        InvitationMailService::deliver($inv->id, $token, $dispatch); // não deve lançar

        $fresh = $inv->fresh();
        $this->assertSame('failed', $fresh->email_delivery_status);
        $this->assertSame(InvitationMailService::MSG_AUTH, $fresh->email_last_error);
        $this->assertNull($fresh->accepted_at);
        $this->assertSame('pending', $fresh->status());
    }

    public function test_deliver_transient_transport_marks_failed_and_rethrows_for_retry(): void
    {
        $admin = $this->ownerAdmin();
        $this->withPassword($admin);
        [$inv, $token] = UserAccessService::invite($admin, 'Ivo', 'ivo@example.com', ['contatos']);
        $dispatch = 'dispatch-transient-token-cccccccccccccccc';
        $inv->forceFill([
            'email_delivery_status' => 'queued',
            'email_dispatch_token' => $dispatch,
            'email_attempts' => 1,
        ])->save();

        $mailer = \Mockery::mock();
        $mailer->shouldReceive('to')->once()->andReturnSelf();
        $mailer->shouldReceive('send')->once()->andThrow(new TransportException('Connection timed out talking to smtp'));
        Mail::shouldReceive('mailer')->once()->with('invitation_smtp')->andReturn($mailer);

        try {
            InvitationMailService::deliver($inv->id, $token, $dispatch);
            $this->fail('deveria relançar para retry');
        } catch (TransportException $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
        }
        $this->assertSame('failed', $inv->fresh()->email_delivery_status);
        $this->assertSame(InvitationMailService::MSG_TRANSPORT, $inv->fresh()->email_last_error);
    }

    public function test_self_test_success_records_ok_without_pretending_invite_sent(): void
    {
        Mail::fake();
        $admin = $this->ownerAdmin();
        $this->withPassword($admin);
        InvitationMailService::sendSelfTest($admin);
        $s = MailSenderSetting::current()->fresh();
        $this->assertSame('ok', $s->last_self_test_status);
        $this->assertNull($s->last_self_test_error);
        Mail::assertSent(MailSenderSelfTestMail::class, 1);
        $this->assertSame(0, UserInvitation::count());
    }

    public function test_volt_self_test_surfaces_validation_not_server_error(): void
    {
        $admin = $this->ownerAdmin();
        $this->withPassword($admin);
        $this->actingAs($admin);

        $mailer = \Mockery::mock();
        $mailer->shouldReceive('to')->andReturnSelf();
        $mailer->shouldReceive('send')->andThrow(new TransportException('535-5.7.8 Username and Password not accepted'));
        Mail::shouldReceive('mailer')->with('invitation_smtp')->andReturn($mailer);

        Volt::test('settings.users')
            ->call('sendSmtpSelfTest')
            ->assertHasErrors('smtpTest')
            ->assertSee('O Google recusou a autenticação SMTP');
    }

}
