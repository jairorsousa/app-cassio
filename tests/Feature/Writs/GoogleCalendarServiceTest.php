<?php

namespace Tests\Feature\Writs;

use App\Domains\Integrations\Models\GoogleCalendarToken;
use App\Domains\Integrations\Services\GoogleCalendarService;
use App\Domains\Writs\Models\Writ;
use Google\Service\Calendar\Event;
use Illuminate\Support\Carbon;
use ReflectionMethod;
use Tests\TestCase;

class GoogleCalendarServiceTest extends TestCase
{
    public function test_cession_event_keeps_local_wall_clock_time(): void
    {
        $this->configureGoogleCalendar();

        $writ = new Writ([
            'type' => 'rpv',
            'stage' => 'pending',
            'process_number' => '0001234-56.2026.8.13.0001',
            'assignor_name' => 'Maria Clara Santos',
            'debtor_entity' => 'INSS',
            'face_value' => 35000,
            'negotiated_amount' => 20000,
            'proposed_amount' => 10000,
        ]);
        $writ->id = 19;
        $writ->cession_at = Carbon::parse('2026-06-19 14:00:00', 'UTC');

        $method = new ReflectionMethod(GoogleCalendarService::class, 'buildCessionEvent');
        $method->setAccessible(true);

        /** @var Event $event */
        $event = $method->invoke(app(GoogleCalendarService::class), $writ);

        $this->assertSame('Cessao - Maria Clara Santos', $event->getSummary());
        $this->assertSame('2026-06-19T14:00:00-03:00', $event->getStart()->getDateTime());
        $this->assertSame('2026-06-19T14:30:00-03:00', $event->getEnd()->getDateTime());
        $this->assertStringContainsString('Valor negociado: R$ 20.000,00', $event->getDescription());
        $this->assertStringNotContainsString('Valor do requisitorio: R$ 35.000,00', $event->getDescription());
    }

    public function test_petition_event_uses_petition_title_and_local_time(): void
    {
        $this->configureGoogleCalendar();

        $writ = new Writ([
            'type' => 'rpv',
            'stage' => 'petitioning',
            'process_number' => '0001234-56.2026.8.13.0001',
            'assignor_name' => 'Maria Clara Santos',
            'debtor_entity' => 'INSS',
            'face_value' => 35000,
            'negotiated_amount' => 20000,
            'proposed_amount' => 10000,
        ]);
        $writ->id = 21;
        $writ->petitioned_at = Carbon::parse('2026-06-22 15:00:00', 'UTC');

        $method = new ReflectionMethod(GoogleCalendarService::class, 'buildPetitionEvent');
        $method->setAccessible(true);

        /** @var Event $event */
        $event = $method->invoke(app(GoogleCalendarService::class), $writ);

        $this->assertSame('Peticionar - Maria Clara Santos', $event->getSummary());
        $this->assertSame('2026-06-22T15:00:00-03:00', $event->getStart()->getDateTime());
        $this->assertSame('2026-06-22T15:30:00-03:00', $event->getEnd()->getDateTime());
        $this->assertStringContainsString('Etapa: Peticionar', $event->getDescription());
        $this->assertStringContainsString('Valor negociado: R$ 20.000,00', $event->getDescription());
    }

    public function test_token_needs_refresh_when_expires_at_is_missing_or_past(): void
    {
        $method = new ReflectionMethod(GoogleCalendarService::class, 'tokenNeedsRefresh');
        $method->setAccessible(true);

        $service = app(GoogleCalendarService::class);

        Carbon::setTestNow('2026-06-16 10:00:00');

        $missingExpiry = new GoogleCalendarToken(['expires_at' => null]);
        $expired = new GoogleCalendarToken(['expires_at' => Carbon::parse('2026-06-16 08:00:00')]);
        $expiringSoon = new GoogleCalendarToken(['expires_at' => now()->addSeconds(30)]);
        $valid = new GoogleCalendarToken(['expires_at' => now()->addHour()]);

        $this->assertTrue($method->invoke($service, $missingExpiry));
        $this->assertTrue($method->invoke($service, $expired));
        $this->assertTrue($method->invoke($service, $expiringSoon));
        $this->assertFalse($method->invoke($service, $valid));

        Carbon::setTestNow();
    }

    public function test_awaiting_receipt_event_uses_stage_title_and_local_time(): void
    {
        $this->configureGoogleCalendar();

        $writ = new Writ([
            'type' => 'rpv',
            'stage' => 'awaiting_receipt',
            'process_number' => '0001234-56.2026.8.13.0001',
            'assignor_name' => 'Maria Clara Santos',
            'debtor_entity' => 'INSS',
            'face_value' => 35000,
            'negotiated_amount' => 20000,
            'proposed_amount' => 10000,
        ]);
        $writ->id = 22;
        $writ->awaiting_receipt_at = Carbon::parse('2026-06-23 16:00:00', 'UTC');

        $method = new ReflectionMethod(GoogleCalendarService::class, 'buildAwaitingReceiptEvent');
        $method->setAccessible(true);

        /** @var Event $event */
        $event = $method->invoke(app(GoogleCalendarService::class), $writ);

        $this->assertSame('Aguardando Recebimento - Maria Clara Santos', $event->getSummary());
        $this->assertSame('2026-06-23T16:00:00-03:00', $event->getStart()->getDateTime());
        $this->assertSame('2026-06-23T16:30:00-03:00', $event->getEnd()->getDateTime());
        $this->assertStringContainsString('Etapa: Aguardando Recebimento', $event->getDescription());
        $this->assertStringContainsString('Valor negociado: R$ 20.000,00', $event->getDescription());
    }

    private function configureGoogleCalendar(): void
    {
        config([
            'google-calendar.timezone' => 'America/Sao_Paulo',
            'google-calendar.default_duration_minutes' => 30,
            'google-calendar.fixed_attendees' => [],
            'google-calendar.create_meet' => false,
        ]);
    }
}
