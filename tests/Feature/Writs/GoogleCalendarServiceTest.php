<?php

namespace Tests\Feature\Writs;

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
        config([
            'google-calendar.timezone' => 'America/Sao_Paulo',
            'google-calendar.default_duration_minutes' => 30,
            'google-calendar.fixed_attendees' => [],
            'google-calendar.create_meet' => false,
        ]);

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
}
