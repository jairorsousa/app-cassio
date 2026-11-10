<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Máscara única de dinheiro (x-money, resources/js/money-mask.js): estilo caixa eletrônico "R$ 1.234,56".
 * A lógica de digitação é testada em tests/js/money-mask.test.mjs (rodado aqui pelo node, quando existir).
 */
class MoneyMaskUnifiedTest extends TestCase
{
    public function test_js_mask_rules_pass(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node indisponível');
        }
        exec(escapeshellarg($node).' '.escapeshellarg(base_path('tests/js/money-mask.test.mjs')).' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('JS money-mask OK', implode("\n", $out));
    }

    public function test_mask_is_atm_style_and_single_directive(): void
    {
        $js = file_get_contents(resource_path('js/money-mask.js'));
        $this->assertStringContainsString('export function applyTyped', $js);
        $this->assertStringContainsString("const PREFIX = 'R$ '", $js);
        $app = file_get_contents(resource_path('js/app.js'));
        $this->assertSame(1, substr_count($app, "Alpine.directive('money'"));
    }

    public function test_money_fields_that_were_unmasked_now_use_x_money(): void
    {
        $writs = file_get_contents(resource_path('views/livewire/writs/form.blade.php'));
        $this->assertStringContainsString('x-money wire:model="default_brokers.{{ $i }}.rate_or_amount"', $writs);
        $this->assertStringContainsString('wire:model.live="default_brokers.{{ $i }}.mode"', $writs);

        $positions = file_get_contents(resource_path('views/livewire/investments/positions.blade.php'));
        $this->assertStringContainsString('label="Saldo atual (R$) *" type="text" x-money', $positions);
    }

    public function test_no_money_field_is_left_without_mask(): void
    {
        // Campos de valor em reais com wire:model devem ter x-money (preço/cotas/percentuais usam x-decimal).
        $allowedDecimal = '/(percentage|percent|rate|fees|office_amount|quantity|unit_price|unit_amount|quotePrice|interest|penalty|participation|applied_percentage|launch_amount|splitRows|brokers\.\{\{ \$index \}\}\.value)/';
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            foreach (file($file->getPathname()) as $n => $line) {
                if (preg_match('/<(input|x-jr\.input|x-fx\.input)\b[^>]*wire:model[.a-z0-9]*="([^"]*(amount|Amount|valor|Valor|balance|Balance|price|Price|limit|Limit|face_value|total|Total)[^"]*)"/', $line, $m)
                    && ! str_contains($line, 'x-money') && ! str_contains($line, 'x-decimal') && ! preg_match('/type="(date|checkbox|radio|hidden)"/', $line)
                    && ! preg_match($allowedDecimal, $m[2])) {
                    $offenders[] = str_replace(resource_path('views').'/', '', $file->getPathname()).':'.($n + 1).' '.$m[2];
                }
            }
        }
        $this->assertSame([], $offenders);
    }
}
